<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Admin\SettingsRepository;
use Phlix\Server\Integrations\Trakt\TraktOAuthStateStore;
use Phlix\Server\Http\Controllers\TraktOAuthController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Covers the CSRF state validation, the M-4 privileged-callback gate, and the
 * M-4 initiating-identity binding of the Trakt OAuth callback.
 *
 * The controller MUST reject any callback whose `state` parameter does
 * not correspond to a previously-issued state, MUST refuse to honour
 * a replay of an already-consumed state value, MUST run the admin gate
 * BEFORE touching the state store (a denied caller may not even burn a
 * legitimately-issued one-shot state), and MUST refuse a state that was
 * issued to a DIFFERENT (or no) identity than the admin completing it.
 *
 * See post-O.7 wave 1 security audit finding H.4 (state CSRF) and the
 * 2026-09-30 security audit finding M-4 (any authenticated user could
 * bind the server-wide Trakt account).
 */
final class TraktOAuthControllerTest extends TestCase
{
    /**
     * Allow-all gate stub for the state-focused tests: these exercise the
     * state machine, not the privilege gate (pinned separately below).
     */
    private static function permissiveGate(): \Closure
    {
        return static fn (Request $request): ?Response => null;
    }

    /**
     * Build a callback request carrying the query params the handler reads.
     *
     * @param array<string, string> $query
     */
    private static function callbackRequest(array $query, ?string $userId = null): Request
    {
        $request = new Request();
        $request->query = $query;
        $request->userId = $userId;

        return $request;
    }

    public function test_callback_with_wrong_state_returns_error_redirect(): void
    {
        $store = new FakeTraktOAuthStateStore();
        $store->put('expected-state', 'verifier-xyz', 'admin-1');

        $controller = new TraktOAuthController(
            logger: null,
            stateStore: $store,
            adminGate: self::permissiveGate(),
        );

        $response = $controller->callback(
            self::callbackRequest(['code' => 'auth-code-aaa', 'state' => 'spoofed-state'], 'admin-1'),
            [],
        );

        self::assertSame(302, $response->statusCode);
        self::assertStringContainsString('trakt=error', $response->headers['Location'] ?? '');
    }

    public function test_callback_after_state_already_consumed_returns_error_redirect(): void
    {
        $store = new FakeTraktOAuthStateStore();
        $store->put('one-shot-state', 'verifier-xyz', 'admin-1');

        $controller = new TraktOAuthController(
            logger: null,
            stateStore: $store,
            adminGate: self::permissiveGate(),
        );

        // First consume succeeds at the state-check level; we don't care
        // about the downstream token exchange because the second call must
        // be rejected up front.
        $controller->callback(
            self::callbackRequest(['code' => 'auth-code-aaa', 'state' => 'one-shot-state'], 'admin-1'),
            [],
        );

        $replay = $controller->callback(
            self::callbackRequest(['code' => 'auth-code-aaa', 'state' => 'one-shot-state'], 'admin-1'),
            [],
        );

        self::assertSame(302, $replay->statusCode);
        self::assertStringContainsString('trakt=error', $replay->headers['Location'] ?? '');
    }

    public function test_callback_without_state_returns_error_redirect(): void
    {
        $store = new FakeTraktOAuthStateStore();

        $controller = new TraktOAuthController(
            logger: null,
            stateStore: $store,
            adminGate: self::permissiveGate(),
        );

        $response = $controller->callback(
            self::callbackRequest(['code' => 'auth-code-aaa', 'state' => ''], 'admin-1'),
            [],
        );

        self::assertSame(302, $response->statusCode);
        self::assertStringContainsString('trakt=error', $response->headers['Location'] ?? '');
    }

    // -----------------------------------------------------------------
    // M-4: privileged-callback gate (fail-closed, runs before state work)
    // -----------------------------------------------------------------

    /**
     * FAIL-CLOSED: a controller constructed without any gate must DENY the
     * callback with the registered 403 `auth.not_admin` — omission of the
     * gate parameter can never mean "skip the gate".
     */
    public function test_callback_without_admin_gate_is_denied_fail_closed(): void
    {
        $store = new FakeTraktOAuthStateStore();
        $store->put('issued-state', 'verifier-xyz', 'admin-1');

        $controller = new TraktOAuthController(logger: null, stateStore: $store);

        $response = $controller->callback(
            self::callbackRequest(['code' => 'auth-code-aaa', 'state' => 'issued-state'], 'admin-1'),
            [],
        );

        self::assertSame(403, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->body, true);
        self::assertSame('auth.not_admin', $body['code'] ?? null);

        // The state must survive untouched: a denied caller may not burn a
        // legitimately-issued one-shot state.
        self::assertTrue($store->has('issued-state'), 'gate denial must not consume the state');
    }

    /**
     * A denying gate's own Response (e.g. AdminMiddleware's 401/403) is
     * returned verbatim, and — same law — the one-shot state is NOT consumed.
     */
    public function test_callback_gate_denial_short_circuits_before_state_consume(): void
    {
        $store = new FakeTraktOAuthStateStore();
        $store->put('issued-state', 'verifier-xyz', 'admin-1');

        $controller = new TraktOAuthController(
            logger: null,
            stateStore: $store,
            adminGate: static fn (Request $request): ?Response =>
                $request->userId === 'admin-1' ? null : (new Response())->status(403)->json([
                    'error' => 'Forbidden',
                    'code'  => 'auth.not_admin',
                ]),
        );

        $denied = $controller->callback(
            self::callbackRequest(['code' => 'auth-code-aaa', 'state' => 'issued-state'], 'mere-user'),
            [],
        );

        self::assertSame(403, $denied->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $denied->body, true);
        self::assertSame('auth.not_admin', $body['code'] ?? null);
        self::assertTrue($store->has('issued-state'), 'gate denial must not consume the state');
    }

    // -----------------------------------------------------------------
    // M-4: initiating-identity binding
    // -----------------------------------------------------------------

    /**
     * authorize() must record the initiating identity into the state store so
     * the callback can refuse states issued to somebody else.
     */
    public function test_authorize_binds_initiating_identity_into_state(): void
    {
        $configFile = $this->writeConfig([
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'redirect_uri' => 'https://phlix.test/api/v1/oauth/trakt/callback',
        ]);

        $store = new FakeTraktOAuthStateStore();
        $controller = new TraktOAuthController(
            logger: null,
            stateStore: $store,
            configFile: $configFile,
        );

        $request = new Request();
        $request->userId = 'admin-42';

        self::assertSame(302, $controller->authorize($request, [])->statusCode);
        self::assertSame(['admin-42'], $store->boundUserIds(), 'state must carry the initiating user id');
    }

    /**
     * A state issued to admin A must not complete on admin B's callback —
     * the code_verifier must never reach the credential check / token
     * exchange. The refusal is DISCRIMINATED from a pass-through by its
     * audit warning (reason `state_bound_to_another_user`), which only the
     * mismatch branch emits; the one-shot state is still burned (nobody gets
     * a second try with somebody else's state).
     */
    public function test_callback_refuses_state_bound_to_another_user(): void
    {
        $store = new FakeTraktOAuthStateStore();
        $store->put('shared-state', 'verifier-xyz', 'admin-A');

        // Credentials are intentionally LEFT UNCONFIGURED: if the identity
        // check ever regressed to a pass-through, the flow would log nothing
        // and simply error-redirect — the warning assertion below is what
        // turns that into a hard failure instead of a silent pass.
        $warnings = [];
        $logger = $this->recordingLogger($warnings);

        $controller = new TraktOAuthController(
            logger: $logger,
            stateStore: $store,
            configFile: $this->writeConfig(['client_id' => '', 'client_secret' => '']),
            adminGate: self::permissiveGate(),
        );

        $response = $controller->callback(
            self::callbackRequest(['code' => 'auth-code-aaa', 'state' => 'shared-state'], 'admin-B'),
            [],
        );

        self::assertSame(302, $response->statusCode);
        self::assertStringContainsString('trakt=error', $response->headers['Location'] ?? '');
        self::assertFalse($store->has('shared-state'), 'mismatched redeem still burns the one-shot state');
        self::assertSame(
            [['message' => 'Trakt OAuth callback identity mismatch', 'reason' => 'state_bound_to_another_user']],
            $warnings,
        );
    }

    /**
     * Positive control for the identity check: with a MATCHING identity the
     * flow must get PAST the mismatch branch. Discriminator: NO mismatch
     * warning is logged (the flow then stops at the unconfigured-credentials
     * check, which is silent), while the one-shot state is still consumed.
     */
    public function test_callback_with_matching_identity_passes_the_binding_check(): void
    {
        $store = new FakeTraktOAuthStateStore();
        $store->put('matching-state', 'verifier-xyz', 'admin-1');

        $warnings = [];
        $logger = $this->recordingLogger($warnings);

        $controller = new TraktOAuthController(
            logger: $logger,
            stateStore: $store,
            configFile: $this->writeConfig(['client_id' => '', 'client_secret' => '']),
            adminGate: self::permissiveGate(),
        );

        $response = $controller->callback(
            self::callbackRequest(['code' => 'auth-code-aaa', 'state' => 'matching-state'], 'admin-1'),
            [],
        );

        self::assertSame(302, $response->statusCode);
        self::assertStringContainsString('trakt=error', $response->headers['Location'] ?? '');
        self::assertFalse($store->has('matching-state'), 'one-shot consume still applies');
        self::assertSame([], $warnings, 'a matching identity must NOT be routed through the mismatch branch');
    }

    /**
     * An unauthenticated initiate (no identity to bind) stores a null-bound
     * state, and the callback refuses it with its own reason — a pre-M-4
     * (unbound) row must never act as a wildcard pass for whichever admin
     * happens to complete it inside the TTL window.
     */
    public function test_callback_refuses_state_not_bound_to_any_identity(): void
    {
        $store = new FakeTraktOAuthStateStore();
        $store->put('legacy-state', 'verifier-xyz', null);

        $warnings = [];
        $logger = $this->recordingLogger($warnings);

        $controller = new TraktOAuthController(
            logger: $logger,
            stateStore: $store,
            configFile: $this->writeConfig(['client_id' => '', 'client_secret' => '']),
            adminGate: self::permissiveGate(),
        );

        $response = $controller->callback(
            self::callbackRequest(['code' => 'auth-code-aaa', 'state' => 'legacy-state'], 'admin-1'),
            [],
        );

        self::assertSame(302, $response->statusCode);
        self::assertStringContainsString('trakt=error', $response->headers['Location'] ?? '');
        self::assertSame(
            [['message' => 'Trakt OAuth callback identity mismatch', 'reason' => 'state_not_bound_to_an_identity']],
            $warnings,
        );
    }

    // -----------------------------------------------------------------
    // Pre-existing behaviour regression block
    // -----------------------------------------------------------------

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
     * Credentials saved in the admin Settings page (server_settings) must take
     * precedence over the env/file config: a config file with empty creds plus
     * a DB override yields a configured (and connectable) Trakt integration.
     */
    public function test_db_settings_override_env_and_file_credentials(): void
    {
        $configFile = $this->writeConfig([
            'client_id' => '',
            'client_secret' => '',
        ]);

        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getOverride')->willReturnMap([
            ['trakt.client_id', ['value' => 'db-client-id', 'value_type' => 'string']],
            ['trakt.client_secret', ['value' => 'db-client-secret', 'value_type' => 'string']],
            ['trakt.redirect_uri', null],
        ]);

        $controller = new TraktOAuthController(
            logger: null,
            stateStore: new FakeTraktOAuthStateStore(),
            configFile: $configFile,
            settings: $settings,
        );

        $status = $controller->status(new Request(), []);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $status->body, true);
        self::assertTrue($body['configured'], 'DB-stored credentials should mark Trakt configured');

        // And the OAuth flow now starts instead of dead-ending on the page.
        $authorize = $controller->authorize(new Request(), []);
        self::assertSame(302, $authorize->statusCode);
    }

    /**
     * PSR-3 double that records every warning as a [message, reason] row so
     * the identity-mismatch branches can be told apart from silent passes.
     *
     * @param list<array{message: string, reason: string}> $warnings
     */
    private function recordingLogger(array &$warnings): \Psr\Log\LoggerInterface
    {
        $warnings = [];

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(
            /** @param array<string, mixed> $context */
            static function (string|\Stringable $message, array $context = []) use (&$warnings): void {
                $row = ['message' => (string) $message, 'reason' => (string) ($context['reason'] ?? '')];
                $warnings[] = $row;
            }
        );

        return $logger;
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
 * one-shot contract of the production implementation, INCLUDING the M-4
 * initiating-identity column.
 *
 * @internal Test fixture only.
 */
final class FakeTraktOAuthStateStore implements TraktOAuthStateStore
{
    /** @var array<string, array{verifier: string, user_id: string|null}> */
    private array $entries = [];

    public function put(string $state, string $codeVerifier, ?string $userId = null): void
    {
        $this->entries[$state] = ['verifier' => $codeVerifier, 'user_id' => $userId];
    }

    public function consume(string $state): ?string
    {
        return $this->consumeWithIdentity($state)['code_verifier'] ?? null;
    }

    public function consumeWithIdentity(string $state): ?array
    {
        if (!isset($this->entries[$state])) {
            return null;
        }
        $entry = $this->entries[$state];
        unset($this->entries[$state]);

        return ['code_verifier' => $entry['verifier'], 'user_id' => $entry['user_id']];
    }

    public function has(string $state): bool
    {
        return isset($this->entries[$state]);
    }

    /**
     * The bound identities of all outstanding states, in insertion order.
     *
     * @return list<string|null>
     */
    public function boundUserIds(): array
    {
        return array_values(array_map(
            static fn (array $entry): ?string => $entry['user_id'],
            $this->entries,
        ));
    }
}
