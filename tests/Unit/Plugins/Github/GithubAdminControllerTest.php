<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Github;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Plugins\Github\Controller\GithubAdminController;
use Phlix\Plugins\Github\GithubOAuthProvider;
use Phlix\Plugins\Github\Plugin as GithubPlugin;
use Phlix\Plugins\Ldap\Plugin as LdapPlugin;
use Phlix\Plugins\Oidc\Plugin as OidcPlugin;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * S48 review r1 Findings 12 (`configured` must mean the same thing as the
 * bootstrapper's buildability test), 3 (a save refreshes the live provider) and 1
 * (an operator-configured redirect_uri must be absolute).
 */
final class GithubAdminControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function plugin(array $settings = []): GithubPlugin
    {
        $store = new InMemoryPluginSettingsRepository();
        if ($settings !== []) {
            $store->save(GithubPlugin::PLUGIN_NAME, $settings);
        }

        return new GithubPlugin($store);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->body = $body;

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($response->body, true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    private function bootstrapper(GithubPlugin $plugin, AuthProviderRegistry $registry): AuthProviderBootstrapper
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getOverride')->willReturnCallback(
            static fn (string $key): ?array => $key === AuthProviderBootstrapper::flagKey('github')
                ? ['value' => true, 'value_type' => 'bool']
                : null
        );

        return new AuthProviderBootstrapper(
            $settings,
            $registry,
            new OidcPlugin(),
            new LdapPlugin(),
            $plugin,
        );
    }

    // -----------------------------------------------------------------------
    // Finding 12 — `configured` must agree with buildGithubProvider().
    // -----------------------------------------------------------------------

    public function test_configured_is_false_without_a_client_secret(): void
    {
        $controller = new GithubAdminController($this->plugin(['client_id' => 'cid']));

        $body = $this->decode($controller->getSettings(new Request(), []));

        $this->assertSame('cid', $body['client_id'] ?? null);
        // A GitHub OAuth App is a confidential client: buildGithubProvider()
        // refuses without a secret, so the UI must not claim "configured".
        $this->assertFalse($body['configured'] ?? null);
    }

    public function test_configured_is_true_with_id_and_secret(): void
    {
        $controller = new GithubAdminController(
            $this->plugin(['client_id' => 'cid', 'client_secret' => 'sec']),
        );

        $body = $this->decode($controller->getSettings(new Request(), []));

        $this->assertTrue($body['configured'] ?? null);
        // The secret itself is never echoed back.
        $this->assertArrayNotHasKey('client_secret', $body);
    }

    public function test_save_response_reports_configured_false_when_only_an_id_is_stored(): void
    {
        $plugin = $this->plugin();
        $controller = new GithubAdminController($plugin);

        $body = $this->decode($controller->saveSettings($this->post(['client_id' => 'cid']), []));

        $this->assertFalse($body['configured'] ?? null);
        // …and the follow-up GET must agree.
        $this->assertFalse($this->decode($controller->getSettings(new Request(), []))['configured'] ?? null);
    }

    // -----------------------------------------------------------------------
    // Finding 1 — an operator-configured redirect_uri must be ABSOLUTE.
    // -----------------------------------------------------------------------

    public function test_save_rejects_a_relative_redirect_uri(): void
    {
        $controller = new GithubAdminController($this->plugin());

        $response = $controller->saveSettings($this->post([
            'client_id' => 'cid',
            'client_secret' => 'sec',
            'redirect_uri' => '/auth/github/callback',
        ]), []);

        $this->assertSame(400, $response->statusCode);
        $this->assertSame('invalid_redirect_uri', $this->decode($response)['error'] ?? null);
    }

    public function test_save_stores_and_returns_an_absolute_redirect_uri(): void
    {
        $plugin = $this->plugin();
        $controller = new GithubAdminController($plugin);

        $response = $controller->saveSettings($this->post([
            'client_id' => 'cid',
            'client_secret' => 'sec',
            'redirect_uri' => 'https://phlix.example/auth/github/callback',
        ]), []);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(
            'https://phlix.example/auth/github/callback',
            $this->decode($controller->getSettings(new Request(), []))['redirect_uri'] ?? null,
        );
    }

    /**
     * A save that omits `redirect_uri` entirely must PRESERVE the stored value —
     * `saveSettings()` is a wholesale replace, so an older client would otherwise
     * silently wipe the operator's configured callback URL (the same
     * "merge, never replace" trap that once wiped Trakt's enable flag).
     */
    public function test_save_without_the_redirect_uri_key_preserves_the_stored_value(): void
    {
        $plugin = $this->plugin([
            'client_id' => 'cid',
            'client_secret' => 'sec',
            'redirect_uri' => 'https://phlix.example/auth/github/callback',
        ]);
        $controller = new GithubAdminController($plugin);

        $controller->saveSettings($this->post(['client_id' => 'cid', 'scopes' => 'read:user']), []);

        $this->assertSame(
            'https://phlix.example/auth/github/callback',
            $plugin->getSettings()['redirect_uri'] ?? null,
        );
    }

    public function test_explicitly_empty_redirect_uri_clears_it(): void
    {
        $plugin = $this->plugin([
            'client_id' => 'cid',
            'client_secret' => 'sec',
            'redirect_uri' => 'https://phlix.example/auth/github/callback',
        ]);
        $controller = new GithubAdminController($plugin);

        $controller->saveSettings($this->post(['client_id' => 'cid', 'redirect_uri' => '']), []);

        $this->assertSame('', $plugin->getSettings()['redirect_uri'] ?? null);
    }

    // -----------------------------------------------------------------------
    // Review r2 NEW-3 — the wholesale-replace trap applies to `scopes` too.
    // -----------------------------------------------------------------------

    /**
     * An older/partial client that posts no `scopes` must not silently RESET an
     * operator's custom scopes to DEFAULT_SCOPES (the same trap that once wiped
     * Trakt's stored OAuth tokens in production).
     */
    public function test_save_without_the_scopes_key_preserves_custom_scopes(): void
    {
        $plugin = $this->plugin([
            'client_id' => 'cid',
            'client_secret' => 'sec',
            'scopes' => 'read:user user:email repo',
        ]);
        $controller = new GithubAdminController($plugin);

        $controller->saveSettings($this->post(['client_id' => 'cid']), []);

        $this->assertSame('read:user user:email repo', $plugin->getSettings()['scopes'] ?? null);
    }

    public function test_explicitly_empty_scopes_resets_to_the_default(): void
    {
        $plugin = $this->plugin([
            'client_id' => 'cid',
            'client_secret' => 'sec',
            'scopes' => 'read:user user:email repo',
        ]);
        $controller = new GithubAdminController($plugin);

        $controller->saveSettings($this->post(['client_id' => 'cid', 'scopes' => '  ']), []);

        $this->assertSame(GithubOAuthProvider::DEFAULT_SCOPES, $plugin->getSettings()['scopes'] ?? null);
    }

    public function test_save_with_scopes_stores_them_trimmed(): void
    {
        $plugin = $this->plugin(['client_id' => 'cid', 'client_secret' => 'sec']);
        $controller = new GithubAdminController($plugin);

        $controller->saveSettings($this->post(['client_id' => 'cid', 'scopes' => ' read:user ']), []);

        $this->assertSame('read:user', $plugin->getSettings()['scopes'] ?? null);
    }

    public function test_schema_declares_the_secret_write_only_and_requires_it(): void
    {
        $controller = new GithubAdminController($this->plugin());

        $body = $this->decode($controller->getSchema(new Request(), []));
        /** @var array<string, mixed> $schema */
        $schema = is_array($body['schema'] ?? null) ? $body['schema'] : [];
        /** @var array<string, mixed> $properties */
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        $this->assertArrayHasKey('redirect_uri', $properties);
        $this->assertSame(['client_id', 'client_secret'], $schema['required'] ?? null);
        $this->assertSame(GithubOAuthProvider::DEFAULT_SCOPES, $properties['scopes']['default'] ?? null);
    }

    // -----------------------------------------------------------------------
    // Finding 3 — a successful save refreshes the live provider in this worker.
    // -----------------------------------------------------------------------

    public function test_save_refreshes_the_live_provider_in_this_worker(): void
    {
        $plugin = $this->plugin(['client_id' => 'cid', 'client_secret' => 'old-secret']);
        $registry = new AuthProviderRegistry();
        $bootstrapper = $this->bootstrapper($plugin, $registry);

        $bootstrapper->registerEnabledProviders();
        $stale = $registry->getProvider('github');

        $controller = new GithubAdminController($plugin, $bootstrapper);
        $controller->saveSettings($this->post([
            'client_id' => 'cid',
            'client_secret' => 'new-secret',
        ]), []);

        $this->assertNotSame(
            $stale,
            $registry->getProvider('github'),
            'the settings save must rebuild the registered provider, not leave the stale one',
        );
    }

    /**
     * A blank secret keeps the stored one (so an operator who edits only the
     * scopes does not wipe their credentials).
     */
    public function test_blank_secret_preserves_the_stored_one(): void
    {
        $plugin = $this->plugin(['client_id' => 'cid', 'client_secret' => 'kept']);
        $controller = new GithubAdminController($plugin);

        $controller->saveSettings($this->post(['client_id' => 'cid', 'scopes' => 'read:user']), []);

        $this->assertSame('kept', $plugin->getSettings()['client_secret'] ?? null);
        $this->assertTrue($this->decode($controller->getSettings(new Request(), []))['configured'] ?? null);
    }

    /**
     * S48 TestEngineer — review r1 Finding 3's failure branch: the settings are
     * ALREADY persisted by the time the live-provider refresh runs, so a refresh
     * that throws must be swallowed and logged, never turned into a 500 that tells
     * the operator their save failed when it did not.
     */
    public function test_a_failing_provider_refresh_does_not_fail_the_already_persisted_save(): void
    {
        $plugin = $this->plugin(['client_id' => 'old', 'client_secret' => 'sec']);
        $bootstrapper = $this->createMock(AuthProviderBootstrapper::class);
        $bootstrapper->method('refresh')->willThrowException(new \RuntimeException('registry exploded'));
        $controller = new GithubAdminController($plugin, $bootstrapper);

        $response = $controller->saveSettings($this->post(['client_id' => 'new', 'scopes' => 'read:user']), []);

        $this->assertSame(200, $response->statusCode, (string) $response->body);
        $this->assertSame('Settings saved successfully', $this->decode($response)['message'] ?? null);
        $this->assertSame('new', $plugin->getSettings()['client_id'] ?? null, 'the save must have persisted');
    }
}
