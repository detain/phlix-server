<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Plugins\Scrobbler\Trakt\TraktOAuthStateStore;
use Phlix\Server\Http\Controllers\TraktOAuthController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Covers the CSRF state validation behaviour of the Trakt OAuth callback.
 *
 * The controller MUST reject any callback whose `state` parameter does
 * not correspond to a previously-issued state, and MUST refuse to honour
 * a replay of an already-consumed state value.
 *
 * See post-O.7 wave 1 security audit, finding H.4.
 */
final class TraktOAuthControllerTest extends TestCase
{
    public function test_callback_with_wrong_state_returns_403(): void
    {
        $store = new FakeTraktOAuthStateStore();
        $store->put('expected-state', 'verifier-xyz');

        $controller = new TraktOAuthController(logger: null, stateStore: $store);

        $response = $controller->callback(new Request(), [
            'code' => 'auth-code-aaa',
            'state' => 'spoofed-state',
        ]);

        self::assertSame(403, $response->statusCode);
    }

    public function test_callback_after_state_already_consumed_returns_403(): void
    {
        $store = new FakeTraktOAuthStateStore();
        $store->put('one-shot-state', 'verifier-xyz');

        $controller = new TraktOAuthController(logger: null, stateStore: $store);

        // First consume succeeds at the state-check level; we don't care
        // about the downstream token exchange because the second call must
        // be rejected up front.
        $controller->callback(new Request(), [
            'code' => 'auth-code-aaa',
            'state' => 'one-shot-state',
        ]);

        $replay = $controller->callback(new Request(), [
            'code' => 'auth-code-aaa',
            'state' => 'one-shot-state',
        ]);

        self::assertSame(403, $replay->statusCode);
    }

    public function test_callback_without_state_returns_400(): void
    {
        $store = new FakeTraktOAuthStateStore();

        $controller = new TraktOAuthController(logger: null, stateStore: $store);

        $response = $controller->callback(new Request(), [
            'code' => 'auth-code-aaa',
            'state' => '',
        ]);

        self::assertSame(400, $response->statusCode);
    }

    /**
     * Regression: authorize() loads the operator-creds config via an injectable
     * path (previously dirname(__DIR__, 7), which resolved above the project
     * root so the file was never read and every Connect attempt reported
     * "missing client_id"). With a config file that supplies client_id +
     * client_secret it must start the OAuth flow (302 redirect to Trakt).
     */
    public function test_authorize_with_credentials_redirects_to_trakt(): void
    {
        $configFile = $this->writeConfig([
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'redirect_uri' => 'https://phlix.test/api/v1/oauth/trakt/callback',
        ]);

        $controller = new TraktOAuthController(
            logger: null,
            stateStore: new FakeTraktOAuthStateStore(),
            configFile: $configFile,
        );

        $response = $controller->authorize(new Request(), []);

        self::assertSame(302, $response->statusCode);
        self::assertArrayHasKey('Location', $response->headers);
        self::assertStringContainsString('trakt.tv', $response->headers['Location']);
    }

    /**
     * authorize() is a full-page redirect target, so when the operator has not
     * supplied credentials it must render a readable HTML page (503), not a raw
     * JSON 400.
     */
    public function test_authorize_without_credentials_renders_html_not_configured_page(): void
    {
        $configFile = $this->writeConfig([
            'client_id' => '',
            'client_secret' => '',
        ]);

        $controller = new TraktOAuthController(
            logger: null,
            stateStore: new FakeTraktOAuthStateStore(),
            configFile: $configFile,
        );

        $response = $controller->authorize(new Request(), []);

        self::assertSame(503, $response->statusCode);
        self::assertStringContainsString('text/html', $response->headers['Content-Type'] ?? '');
        self::assertStringContainsString('not configured', $response->body);
        self::assertStringContainsString('trakt.tv', $response->body);
    }

    public function test_status_reports_configured_true_when_credentials_present(): void
    {
        $configFile = $this->writeConfig([
            'client_id' => 'cid',
            'client_secret' => 'secret',
        ]);

        $controller = new TraktOAuthController(
            logger: null,
            stateStore: new FakeTraktOAuthStateStore(),
            configFile: $configFile,
        );

        $response = $controller->status(new Request(), []);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->body, true);

        self::assertTrue($body['configured']);
        self::assertFalse($body['connected']);
    }

    public function test_status_reports_configured_false_without_credentials(): void
    {
        $configFile = $this->writeConfig([
            'client_id' => '',
            'client_secret' => '',
        ]);

        $controller = new TraktOAuthController(
            logger: null,
            stateStore: new FakeTraktOAuthStateStore(),
            configFile: $configFile,
        );

        $response = $controller->status(new Request(), []);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->body, true);

        self::assertFalse($body['configured']);
    }

    /**
     * Write a throwaway Trakt config file and register it for cleanup.
     *
     * @param array<string, mixed> $config
     */
    private function writeConfig(array $config): string
    {
        $path = sys_get_temp_dir() . '/phlix-trakt-config-' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export($config, true) . ';');
        $this->tempFiles[] = $path;

        return $path;
    }

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
    }
}

/**
 * Plain in-memory store used by the controller test. Mirrors the
 * one-shot contract of the production implementation.
 *
 * @internal Test fixture only.
 */
final class FakeTraktOAuthStateStore implements TraktOAuthStateStore
{
    /** @var array<string, string> */
    private array $entries = [];

    public function put(string $state, string $codeVerifier): void
    {
        $this->entries[$state] = $codeVerifier;
    }

    public function consume(string $state): ?string
    {
        if (!isset($this->entries[$state])) {
            return null;
        }
        $verifier = $this->entries[$state];
        unset($this->entries[$state]);
        return $verifier;
    }
}
