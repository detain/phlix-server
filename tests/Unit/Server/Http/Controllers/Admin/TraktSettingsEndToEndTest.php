<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Admin\SettingsRepository;
use Phlix\Plugins\SettingsMasker;
use Phlix\Server\Http\Controllers\Admin\AdminSettingsController;
use Phlix\Server\Http\Controllers\TraktOAuthController;
use Phlix\Server\Http\Request;
use Phlix\Plugins\Scrobbler\Trakt\TraktOAuthStateStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workerman\MySQL\Connection;

/**
 * End-to-end regression cover for the three Trakt operator-credential settings.
 *
 * phlix-shared v0.24.0 deleted `trakt.client_id` / `trakt.client_secret` /
 * `trakt.redirect_uri` from the server-settings schema because
 * {@see SettingsRepository::getDefault()} resolved only a FLAT
 * `config/<file>.php` while the real config lives at
 * `config/scrobblers/trakt.php`. The READ path never broke — but the admin PUT
 * allow-list is derived from that schema, so PUT began rejecting all three as
 * "Unknown setting key" and Trakt credentials became unsettable from the admin
 * Settings page. v0.25.0 restores the keys and `config/trakt.php` re-exports
 * `config/scrobblers/trakt.php` so they resolve again under their ORIGINAL
 * names (a rename to `scrobblers.trakt.*` would have orphaned overrides already
 * persisted on live installs).
 *
 * These tests assert the CONSEQUENCE, not the shape: not "the key is in a list"
 * but "a saved client_id actually changes what TraktOAuthController does". Every
 * one of them runs against the REAL `config/` directory, so the shim is under
 * test rather than mocked away.
 *
 * @covers \Phlix\Server\Http\Controllers\Admin\AdminSettingsController
 * @covers \Phlix\Admin\SettingsRepository
 */
final class TraktSettingsEndToEndTest extends TestCase
{
    /** @var list<string> The three keys under test. */
    private const TRAKT_KEYS = [
        'trakt.client_id',
        'trakt.client_secret',
        'trakt.redirect_uri',
    ];

    /** @var string|null Temp config file handed to TraktOAuthController. */
    private ?string $traktConfigFile = null;

    protected function tearDown(): void
    {
        if ($this->traktConfigFile !== null && is_file($this->traktConfigFile)) {
            unlink($this->traktConfigFile);
        }
        $this->traktConfigFile = null;

        parent::tearDown();
    }

    /**
     * A SettingsRepository whose OVERRIDES live in memory but whose DEFAULTS
     * come from the repository's real `config/` tree — so `config/trakt.php`
     * (the shim) is genuinely exercised, not stubbed.
     *
     * @param array<string, mixed> $seed Pre-existing overrides.
     */
    private function repository(array $seed = []): SettingsRepository
    {
        /** @var Connection $db getDefault() never touches the connection. */
        $db = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();

        return new class ($db, dirname(__DIR__, 6) . '/config', $seed) extends SettingsRepository {
            /** @var array<string, mixed> */
            public array $stored;

            /** @var array<string, string> */
            public array $types = [];

            /**
             * @param array<string, mixed> $seed
             */
            public function __construct(Connection $db, string $configDir, array $seed)
            {
                parent::__construct($db, $configDir);
                $this->stored = $seed;
            }

            public function set(string $key, mixed $value, string $valueType): void
            {
                $this->stored[$key] = $value;
                $this->types[$key] = $valueType;
            }

            public function getOverride(string $key): ?array
            {
                if (!array_key_exists($key, $this->stored)) {
                    return null;
                }

                return [
                    'value'      => $this->stored[$key],
                    'value_type' => $this->types[$key] ?? 'string',
                ];
            }

            /**
             * @return array<string, mixed>
             */
            public function getAllOverrides(): array
            {
                return $this->stored;
            }
        };
    }

    /**
     * A TraktOAuthController reading the SAME settings store, over a config file
     * whose credentials are empty — exactly what a fresh install ships, so the
     * "before" state is deterministic regardless of the developer's TRAKT_*
     * environment variables.
     */
    private function traktController(SettingsRepository $settings): TraktOAuthController
    {
        $this->traktConfigFile = (string) tempnam(sys_get_temp_dir(), 'phlix_trakt_e2e_');
        file_put_contents(
            $this->traktConfigFile,
            "<?php return " . var_export([
                'client_id'     => '',
                'client_secret' => '',
                'redirect_uri'  => 'https://your-server.com/api/v1/oauth/trakt/callback',
            ], true) . ";\n",
        );

        return new TraktOAuthController(
            logger: null,
            stateStore: new class implements TraktOAuthStateStore {
                public function put(string $state, string $codeVerifier): void
                {
                }

                public function consume(string $state): ?string
                {
                    return null;
                }
            },
            configFile: $this->traktConfigFile,
            settings: $settings,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function makeRequest(array $body = []): Request
    {
        $request = new Request();
        $request->body = $body;

        return $request;
    }

    /**
     * @return array{configured: bool}
     */
    private function traktStatus(TraktOAuthController $controller): array
    {
        /** @var array{configured: bool} $body */
        $body = json_decode((string) $controller->status(new Request(), [])->body, true);

        return $body;
    }

    /**
     * THE REGRESSION. Before the fix this PUT returned 400 with
     * `{"errors":{"trakt.client_id":"Unknown setting key."}}` for all three.
     */
    public function testPutAcceptsAllThreeTraktKeysAndPersistsThem(): void
    {
        $repo = $this->repository();
        $controller = new AdminSettingsController($repo);

        $response = $controller->update($this->makeRequest(['settings' => [
            'trakt.client_id'     => 'operator-client-id',
            'trakt.client_secret' => 'operator-client-secret',
            'trakt.redirect_uri'  => 'https://phlix.example/api/v1/oauth/trakt/callback',
        ]]), []);

        $this->assertSame(200, $response->statusCode, 'PUT of the trakt.* keys must be accepted: ' . $response->body);

        /** @var array{success: bool, errors?: array<string, string>} $body */
        $body = json_decode($response->body, true);
        $this->assertTrue($body['success']);
        $this->assertArrayNotHasKey('errors', $body);

        $this->assertSame('operator-client-id', $repo->stored['trakt.client_id']);
        $this->assertSame('operator-client-secret', $repo->stored['trakt.client_secret']);
        $this->assertSame(
            'https://phlix.example/api/v1/oauth/trakt/callback',
            $repo->stored['trakt.redirect_uri'],
        );
    }

    /**
     * The consequence that actually matters: a saved client_id/secret must
     * change what the Trakt OAuth flow DOES. A key that is merely "settable"
     * but inert is the exact defect this remediation exists to eliminate.
     */
    public function testSavedCredentialsMakeTheTraktOAuthFlowLive(): void
    {
        $repo = $this->repository();
        $trakt = $this->traktController($repo);

        // BEFORE: no override, empty config → Trakt is not configured and
        // authorize() dead-ends on the "register an app" page.
        $this->assertFalse($this->traktStatus($trakt)['configured']);
        $this->assertSame(503, $trakt->authorize(new Request(), [])->statusCode);

        // Save credentials through the ADMIN SETTINGS ENDPOINT.
        $response = (new AdminSettingsController($repo))->update($this->makeRequest(['settings' => [
            'trakt.client_id'     => 'live-client-id',
            'trakt.client_secret' => 'live-client-secret',
            'trakt.redirect_uri'  => 'https://phlix.example/api/v1/oauth/trakt/callback',
        ]]), []);
        $this->assertSame(200, $response->statusCode);

        // AFTER: same controller instance, no restart, no reload — the override
        // is read per request, so the flow is immediately live. This is what
        // makes `"restart": false` on these three keys honest.
        $this->assertTrue(
            $this->traktStatus($trakt)['configured'],
            'A client_id/secret saved via the settings PUT must make Trakt report itself configured',
        );

        $redirect = $trakt->authorize(new Request(), []);
        $this->assertSame(302, $redirect->statusCode);
        $location = $redirect->headers['Location'] ?? '';
        $this->assertStringContainsString('trakt.tv', $location);
        $this->assertStringContainsString(
            'live-client-id',
            $location,
            'The saved client_id must be the one sent to Trakt',
        );
        $this->assertStringContainsString(
            rawurlencode('https://phlix.example/api/v1/oauth/trakt/callback'),
            $location,
            'The saved redirect_uri must be the one sent to Trakt',
        );
    }

    /**
     * The secret half must never travel back to the browser in plaintext.
     */
    public function testTraktClientSecretIsMaskedInGetAndPutResponses(): void
    {
        $repo = $this->repository([
            'trakt.client_id'     => 'greppable-client-id',
            'trakt.client_secret' => 'greppable-client-secret',
        ]);
        $controller = new AdminSettingsController($repo);

        $responses = [
            'GET' => $controller->index($this->makeRequest(), []),
            'PUT' => $controller->update($this->makeRequest(['settings' => [
                'trakt.redirect_uri' => 'https://phlix.example/api/v1/oauth/trakt/callback',
            ]]), []),
        ];

        foreach ($responses as $verb => $response) {
            $this->assertSame(200, $response->statusCode);

            $this->assertStringNotContainsString(
                'greppable-client-secret',
                $response->body,
                sprintf('trakt.client_secret leaked into the %s response body', $verb),
            );
            $this->assertStringNotContainsString(
                'greppable-client-id',
                $response->body,
                sprintf('trakt.client_id leaked into the %s response body', $verb),
            );

            /** @var array{data: array{settings: array<string, mixed>}} $body */
            $body = json_decode($response->body, true);
            $this->assertSame(SettingsMasker::MASK, $body['data']['settings']['trakt.client_secret']);
            $this->assertSame(SettingsMasker::MASK, $body['data']['settings']['trakt.client_id']);
        }

        // The public redirect URI is NOT a secret and must render normally.
        $body = json_decode($controller->index($this->makeRequest(), [])->body, true);
        $this->assertNotSame(SettingsMasker::MASK, $body['data']['settings']['trakt.redirect_uri']);
    }

    /**
     * Re-submitting the mask sentinel must leave the STORED secret intact —
     * asserted on the store and on the Trakt flow, not merely on "set() was
     * never called". Without the guard the first Save on the settings page
     * overwrites the operator's Trakt secret with the literal mask string and
     * silently breaks the OAuth token exchange.
     */
    public function testResubmittingTheMaskSentinelDoesNotOverwriteTheStoredTraktSecret(): void
    {
        $repo = $this->repository([
            'trakt.client_id'     => 'original-client-id',
            'trakt.client_secret' => 'original-client-secret',
        ]);
        $trakt = $this->traktController($repo);
        $this->assertTrue($this->traktStatus($trakt)['configured']);

        // Exactly what the SPA sends back after a GET: masked secrets, plus one
        // genuinely edited non-secret field.
        $response = (new AdminSettingsController($repo))->update($this->makeRequest(['settings' => [
            'trakt.client_id'     => SettingsMasker::MASK,
            'trakt.client_secret' => SettingsMasker::MASK,
            'trakt.redirect_uri'  => 'https://phlix.example/api/v1/oauth/trakt/callback',
        ]]), []);

        $this->assertSame(200, $response->statusCode);

        $this->assertSame(
            'original-client-secret',
            $repo->stored['trakt.client_secret'],
            'The stored Trakt secret must survive a Save that echoed the mask back',
        );
        $this->assertSame('original-client-id', $repo->stored['trakt.client_id']);
        // The genuinely edited non-secret sibling still landed.
        $this->assertSame(
            'https://phlix.example/api/v1/oauth/trakt/callback',
            $repo->stored['trakt.redirect_uri'],
        );

        // And the consequence: Trakt is still configured, with the ORIGINAL
        // credentials, after the round-trip.
        $this->assertTrue($this->traktStatus($trakt)['configured']);
        $this->assertStringContainsString(
            'original-client-id',
            $trakt->authorize(new Request(), [])->headers['Location'] ?? '',
        );
    }

    /**
     * With no override at all, the three keys must still report the shipped
     * config defaults — i.e. `config/trakt.php` really does re-export
     * `config/scrobblers/trakt.php`. Delete the shim and this fails.
     */
    public function testDefaultsResolveThroughTheFlatConfigShim(): void
    {
        $repo = $this->repository();

        foreach (self::TRAKT_KEYS as $key) {
            $this->assertTrue(
                $repo->hasDefault($key),
                sprintf('%s must resolve to a real config default via config/trakt.php', $key),
            );
        }

        // The shim must expose the SAME array as the canonical nested file, so
        // the flat and nested spellings can never drift apart.
        $this->assertSame(
            $repo->getDefault('scrobblers.trakt.redirect_uri'),
            $repo->getDefault('trakt.redirect_uri'),
        );
        $this->assertSame(
            $repo->getDefault('scrobblers.trakt.client_id'),
            $repo->getDefault('trakt.client_id'),
        );
    }
}
