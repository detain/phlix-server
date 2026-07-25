<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Plugins\Github\Plugin as GithubPlugin;
use Phlix\Plugins\Ldap\Plugin as LdapPlugin;
use Phlix\Plugins\Oidc\Plugin as OidcPlugin;
use Phlix\Tests\Unit\Plugins\Github\InMemoryPluginSettingsRepository;

/**
 * @covers \Phlix\Auth\AuthProviderBootstrapper
 */
final class AuthProviderBootstrapperTest extends TestCase
{
    private string $oidcDir;
    private string $ldapDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oidcDir = sys_get_temp_dir() . '/phlix_oidc_plugin_' . uniqid();
        $this->ldapDir = sys_get_temp_dir() . '/phlix_ldap_plugin_' . uniqid();
        mkdir($this->oidcDir, 0755, true);
        mkdir($this->ldapDir, 0755, true);
        OidcPlugin::setPluginDirectory($this->oidcDir);
        LdapPlugin::setPluginDirectory($this->ldapDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ([$this->oidcDir, $this->ldapDir] as $dir) {
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($dir);
            }
        }
        // Reset the shared static dir so later tests re-read from the default.
        OidcPlugin::setPluginDirectory(\dirname(__DIR__, 3) . '/src/Plugins/Oidc');
        LdapPlugin::setPluginDirectory(\dirname(__DIR__, 3) . '/src/Plugins/Ldap');
    }

    /**
     * @param array<string, mixed> $oidc
     * @param array<string, mixed> $ldap
     */
    private function writeSettings(array $oidc = [], array $ldap = []): void
    {
        if ($oidc !== []) {
            file_put_contents($this->oidcDir . '/settings.json', json_encode($oidc, JSON_THROW_ON_ERROR));
            OidcPlugin::setPluginDirectory($this->oidcDir); // invalidate cache
        }
        if ($ldap !== []) {
            file_put_contents($this->ldapDir . '/settings.json', json_encode($ldap, JSON_THROW_ON_ERROR));
            LdapPlugin::setPluginDirectory($this->ldapDir);
        }
    }

    /**
     * @param array<string, bool> $enabledFlags Map of provider => enabled bool.
     */
    private function makeSettingsRepo(array $enabledFlags = []): SettingsRepository
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getOverride')->willReturnCallback(
            function (string $key) use ($enabledFlags): ?array {
                foreach ($enabledFlags as $provider => $on) {
                    if ($key === AuthProviderBootstrapper::flagKey($provider)) {
                        return ['value' => $on, 'value_type' => 'bool'];
                    }
                }
                return null;
            }
        );
        return $repo;
    }

    /**
     * Build a GitHub plugin backed by an in-memory DB-settings store (S48). This
     * exercises the getSettings()-yields path the S46 reviewer flagged: in
     * production the store is a real DB query.
     *
     * @param array<string, mixed> $settings
     */
    private function makeGithubPlugin(array $settings = []): GithubPlugin
    {
        $store = new InMemoryPluginSettingsRepository();
        if ($settings !== []) {
            $store->save(GithubPlugin::PLUGIN_NAME, $settings);
        }

        return new GithubPlugin($store);
    }

    public function test_flag_key_format(): void
    {
        $this->assertSame('auth.oidc.enabled', AuthProviderBootstrapper::flagKey('oidc'));
        $this->assertSame('auth.ldap.enabled', AuthProviderBootstrapper::flagKey('ldap'));
        $this->assertSame('auth.github.enabled', AuthProviderBootstrapper::flagKey('github'));
    }

    public function test_is_enabled_reads_the_override(): void
    {
        $registry = new AuthProviderRegistry();
        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(['oidc' => true, 'ldap' => false]),
            $registry,
            new OidcPlugin(),
            new LdapPlugin(),
        );

        $this->assertTrue($boot->isEnabled('oidc'));
        $this->assertFalse($boot->isEnabled('ldap'));
    }

    public function test_is_configured_reflects_saved_settings(): void
    {
        $registry = new AuthProviderRegistry();
        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(),
            $registry,
            new OidcPlugin(),
            new LdapPlugin(),
        );

        // No settings.json yet → not configured.
        $this->assertFalse($boot->isConfigured('oidc'));
        $this->assertFalse($boot->isConfigured('ldap'));

        $this->writeSettings(
            oidc: ['provider_url' => 'https://idp.test', 'client_id' => 'cid'],
            ldap: ['host' => 'ldap.test', 'base_dn' => 'dc=test'],
        );

        $this->assertTrue($boot->isConfigured('oidc'));
        $this->assertTrue($boot->isConfigured('ldap'));
    }

    public function test_enable_persists_flag_and_registers_when_configured(): void
    {
        $this->writeSettings(oidc: ['provider_url' => 'https://idp.test', 'client_id' => 'cid']);

        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->once())
            ->method('set')
            ->with('auth.oidc.enabled', true, 'bool');

        $registry = new AuthProviderRegistry();
        $boot = new AuthProviderBootstrapper($repo, $registry, new OidcPlugin(), new LdapPlugin());

        $live = $boot->enable('oidc');

        $this->assertTrue($live);
        $this->assertTrue($registry->hasProvider('oidc'));
    }

    public function test_enable_persists_flag_but_reports_not_live_when_unconfigured(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->once())
            ->method('set')
            ->with('auth.oidc.enabled', true, 'bool');

        $registry = new AuthProviderRegistry();
        $boot = new AuthProviderBootstrapper($repo, $registry, new OidcPlugin(), new LdapPlugin());

        // No settings.json → cannot build the provider.
        $live = $boot->enable('oidc');

        $this->assertFalse($live);
        $this->assertFalse($registry->hasProvider('oidc'));
    }

    public function test_disable_persists_flag_and_unregisters(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->once())
            ->method('set')
            ->with('auth.ldap.enabled', false, 'bool');

        $registry = new AuthProviderRegistry();

        // Arrange: an 'ldap'-named provider is currently live in this worker.
        $provider = $this->createMock(\Phlix\Shared\Auth\ProviderInterface::class);
        $provider->method('name')->willReturn('ldap');
        $registry->registerProvider($provider);
        $this->assertTrue($registry->hasProvider('ldap'));

        $boot = new AuthProviderBootstrapper($repo, $registry, new OidcPlugin(), new LdapPlugin());
        $boot->disable('ldap');

        $this->assertFalse($registry->hasProvider('ldap'));
    }

    public function test_register_enabled_providers_registers_only_enabled_and_configured(): void
    {
        // OIDC enabled + configured; LDAP disabled (even though configured).
        $this->writeSettings(
            oidc: ['provider_url' => 'https://idp.test', 'client_id' => 'cid', 'client_secret' => 'secret'],
            ldap: ['host' => 'ldap.test', 'base_dn' => 'dc=test'],
        );

        $registry = new AuthProviderRegistry();
        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(['oidc' => true, 'ldap' => false]),
            $registry,
            new OidcPlugin(),
            new LdapPlugin(),
        );

        $boot->registerEnabledProviders();

        $this->assertTrue($registry->hasProvider('oidc'));
        $this->assertFalse($registry->hasProvider('ldap'));
    }

    public function test_register_enabled_providers_skips_enabled_but_unconfigured(): void
    {
        // Enabled flags on, but NO settings.json for either → nothing to register.
        $registry = new AuthProviderRegistry();
        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(['oidc' => true, 'ldap' => true]),
            $registry,
            new OidcPlugin(),
            new LdapPlugin(),
        );

        $boot->registerEnabledProviders();

        $this->assertFalse($registry->hasProvider('oidc'));
        $this->assertFalse($registry->hasProvider('ldap'));
    }

    public function test_register_enabled_providers_is_idempotent(): void
    {
        $this->writeSettings(oidc: ['provider_url' => 'https://idp.test', 'client_id' => 'cid']);

        $registry = new AuthProviderRegistry();
        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(['oidc' => true]),
            $registry,
            new OidcPlugin(),
            new LdapPlugin(),
        );

        $boot->registerEnabledProviders();
        // A second pass must NOT throw "already registered".
        $boot->registerEnabledProviders();

        $this->assertTrue($registry->hasProvider('oidc'));
    }

    public function test_is_toggleable(): void
    {
        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(),
            new AuthProviderRegistry(),
            new OidcPlugin(),
            new LdapPlugin(),
        );

        $this->assertTrue($boot->isToggleable('oidc'));
        $this->assertTrue($boot->isToggleable('ldap'));
        $this->assertFalse($boot->isToggleable('saml'));
        $this->assertFalse($boot->isToggleable(''));
    }

    // -----------------------------------------------------------------------
    // S44 Finding 3 — request-path self-heal (ensureProviderRegistered()).
    // -----------------------------------------------------------------------

    public function test_ensure_provider_registered_lazily_registers_when_flag_on(): void
    {
        // Flag ON + configured, but the provider is NOT yet in this worker's
        // registry (it booted before OIDC was enabled).
        $this->writeSettings(oidc: ['provider_url' => 'https://idp.test', 'client_id' => 'cid']);

        $registry = new AuthProviderRegistry();
        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(['oidc' => true]),
            $registry,
            new OidcPlugin(),
            new LdapPlugin(),
        );

        $this->assertFalse($registry->hasProvider('oidc'));

        $result = $boot->ensureProviderRegistered('oidc');

        $this->assertTrue($result);
        $this->assertTrue($registry->hasProvider('oidc'));
    }

    public function test_ensure_provider_registered_drops_stale_registration_when_flag_off(): void
    {
        // Flag OFF, but a stale 'ldap' provider is still live in this worker
        // (it was disabled after this worker booted).
        $registry = new AuthProviderRegistry();
        $provider = $this->createMock(\Phlix\Shared\Auth\ProviderInterface::class);
        $provider->method('name')->willReturn('ldap');
        $registry->registerProvider($provider);
        $this->assertTrue($registry->hasProvider('ldap'));

        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(['ldap' => false]),
            $registry,
            new OidcPlugin(),
            new LdapPlugin(),
        );

        $result = $boot->ensureProviderRegistered('ldap');

        $this->assertFalse($result);
        $this->assertFalse($registry->hasProvider('ldap'));
    }

    public function test_ensure_provider_registered_returns_false_when_enabled_but_unconfigured(): void
    {
        // Flag ON but no settings.json → cannot build → nothing registered.
        $registry = new AuthProviderRegistry();
        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(['oidc' => true]),
            $registry,
            new OidcPlugin(),
            new LdapPlugin(),
        );

        $this->assertFalse($boot->ensureProviderRegistered('oidc'));
        $this->assertFalse($registry->hasProvider('oidc'));
    }

    public function test_ensure_provider_registered_rejects_unknown_provider(): void
    {
        $registry = new AuthProviderRegistry();
        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(['saml' => true]),
            $registry,
            new OidcPlugin(),
            new LdapPlugin(),
        );

        $this->assertFalse($boot->ensureProviderRegistered('saml'));
    }

    // -----------------------------------------------------------------------
    // S44 review r2, Finding A — race-safe registration.
    //
    // Two concurrent coroutines in the same worker can both pass the
    // hasProvider() fast-path (the settings read yields once the store is
    // DB-backed) and both call the registry, which throws \RuntimeException on
    // a duplicate. The loser must NOT surface that throw as a 500.
    // -----------------------------------------------------------------------

    public function test_register_lost_race_is_benign_and_does_not_throw(): void
    {
        // Flag ON + configured, so registerProvider() gets past the fast-path,
        // builds the provider, and reaches the registry.
        $this->writeSettings(oidc: ['provider_url' => 'https://idp.test', 'client_id' => 'cid']);

        // Registry double that models the lost race: the pre-build fast-path
        // check misses, but by the time this coroutine calls registerProvider()
        // the winning coroutine has already committed the same instance key, so
        // the registry rejects the duplicate — yet hasProvider() is true after.
        $racyRegistry = new class extends AuthProviderRegistry {
            private int $hasProviderCalls = 0;

            public function hasProvider(string $name): bool
            {
                // First call = the pre-build fast-path (race not yet lost → a
                // miss); every later call reflects the winner having committed.
                return $this->hasProviderCalls++ > 0;
            }

            public function registerProvider(
                \Phlix\Shared\Auth\ProviderInterface $provider,
                string $instance = self::DEFAULT_INSTANCE
            ): void {
                throw new \RuntimeException(
                    "Auth provider '{$provider->name()}' is already registered."
                );
            }
        };

        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(['oidc' => true]),
            $racyRegistry,
            new OidcPlugin(),
            new LdapPlugin(),
        );

        // No exception must escape, and the provider is reported as live.
        $result = $boot->ensureProviderRegistered('oidc');

        $this->assertTrue($result);
        $this->assertTrue($racyRegistry->hasProvider('oidc'));
    }

    public function test_register_genuine_failure_still_propagates(): void
    {
        $this->writeSettings(oidc: ['provider_url' => 'https://idp.test', 'client_id' => 'cid']);

        // Registry double that models a REAL failure (not a duplicate): the
        // instance is never actually registered, so hasProvider() stays false
        // even after registerProvider() throws — the throw MUST propagate.
        $brokenRegistry = new class extends AuthProviderRegistry {
            public function hasProvider(string $name): bool
            {
                return false;
            }

            public function registerProvider(
                \Phlix\Shared\Auth\ProviderInterface $provider,
                string $instance = self::DEFAULT_INSTANCE
            ): void {
                throw new \RuntimeException('registry backing store unavailable');
            }
        };

        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(['oidc' => true]),
            $brokenRegistry,
            new OidcPlugin(),
            new LdapPlugin(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('registry backing store unavailable');

        $boot->ensureProviderRegistered('oidc');
    }

    // -----------------------------------------------------------------------
    // S48 — GitHub provider governance (DB-backed settings).
    // -----------------------------------------------------------------------

    public function test_github_is_toggleable(): void
    {
        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(),
            new AuthProviderRegistry(),
            new OidcPlugin(),
            new LdapPlugin(),
            $this->makeGithubPlugin(),
        );

        $this->assertTrue($boot->isToggleable('github'));
    }

    public function test_github_registers_when_enabled_and_configured_via_db_store(): void
    {
        $registry = new AuthProviderRegistry();
        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(['github' => true]),
            $registry,
            new OidcPlugin(),
            new LdapPlugin(),
            $this->makeGithubPlugin(['client_id' => 'cid', 'client_secret' => 'sec']),
        );

        $boot->registerEnabledProviders();

        $this->assertTrue($registry->hasProvider('github'));
    }

    public function test_github_not_registered_without_client_secret(): void
    {
        // GitHub OAuth Apps are confidential clients — a client_secret is required.
        $registry = new AuthProviderRegistry();
        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(['github' => true]),
            $registry,
            new OidcPlugin(),
            new LdapPlugin(),
            $this->makeGithubPlugin(['client_id' => 'cid']),
        );

        $this->assertFalse($boot->isConfigured('github'));

        $boot->registerEnabledProviders();

        $this->assertFalse($registry->hasProvider('github'));
    }

    public function test_github_build_returns_null_when_plugin_absent(): void
    {
        // Pre-S48-shaped construction (no GitHub plugin) must not fatal — github
        // simply is not configurable.
        $registry = new AuthProviderRegistry();
        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(['github' => true]),
            $registry,
            new OidcPlugin(),
            new LdapPlugin(),
        );

        $this->assertFalse($boot->isConfigured('github'));
        $boot->registerEnabledProviders();
        $this->assertFalse($registry->hasProvider('github'));
    }

    // -----------------------------------------------------------------------
    // S48 review r1, Finding 3 — a settings save must reach the LIVE provider.
    // -----------------------------------------------------------------------

    /**
     * refresh() drops and rebuilds the registration in THIS worker, so the worker
     * that handled an admin save stops authenticating with the stale credentials
     * immediately (registerProvider()'s hasProvider() fast-path would otherwise
     * keep the old instance until a restart).
     */
    public function test_refresh_rebuilds_the_live_provider_from_current_settings(): void
    {
        $store = new InMemoryPluginSettingsRepository();
        $store->save(GithubPlugin::PLUGIN_NAME, ['client_id' => 'cid', 'client_secret' => 'old-secret']);
        $plugin = new GithubPlugin($store);

        $registry = new AuthProviderRegistry();
        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(['github' => true]),
            $registry,
            new OidcPlugin(),
            new LdapPlugin(),
            $plugin,
        );

        $boot->registerEnabledProviders();
        $first = $registry->getProvider('github');
        $this->assertInstanceOf(\Phlix\Plugins\Github\GithubOAuthProvider::class, $first);

        // The operator fixes a mistyped secret.
        $store->save(GithubPlugin::PLUGIN_NAME, ['client_id' => 'cid', 'client_secret' => 'new-secret']);

        $this->assertTrue($boot->refresh('github'));
        $second = $registry->getProvider('github');
        $this->assertInstanceOf(\Phlix\Plugins\Github\GithubOAuthProvider::class, $second);
        $this->assertNotSame($first, $second, 'refresh() must rebuild, not reuse, the provider instance');
    }

    public function test_refresh_rejects_an_unknown_provider(): void
    {
        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(['saml' => true]),
            new AuthProviderRegistry(),
            new OidcPlugin(),
            new LdapPlugin(),
        );

        $this->assertFalse($boot->refresh('saml'));
    }

    public function test_refresh_drops_the_provider_when_the_flag_is_off(): void
    {
        $store = new InMemoryPluginSettingsRepository();
        $store->save(GithubPlugin::PLUGIN_NAME, ['client_id' => 'cid', 'client_secret' => 'sec']);

        $registry = new AuthProviderRegistry();
        $registry->registerProvider(
            new \Phlix\Plugins\Github\GithubOAuthProvider('cid', 'sec', 'read:user'),
        );

        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(['github' => false]),
            $registry,
            new OidcPlugin(),
            new LdapPlugin(),
            new GithubPlugin($store),
        );

        $this->assertFalse($boot->refresh('github'));
        $this->assertFalse($registry->hasProvider('github'));
    }

    /**
     * THE cross-worker half of Finding 3: a worker that did NOT handle the save
     * still picks the change up, because ensureProviderRegistered() compares the
     * persisted settings fingerprint with the one it built from and rebuilds when
     * it differs. No restart, no admin call on that worker.
     */
    public function test_other_worker_rebuilds_when_persisted_settings_changed(): void
    {
        $store = new InMemoryPluginSettingsRepository();
        $store->save(GithubPlugin::PLUGIN_NAME, ['client_id' => 'cid', 'client_secret' => 'old-secret']);

        $registry = new AuthProviderRegistry();
        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(['github' => true]),
            $registry,
            new OidcPlugin(),
            new LdapPlugin(),
            new GithubPlugin($store),
        );

        // This "worker" registered from the OLD row.
        $this->assertTrue($boot->ensureProviderRegistered('github'));
        $stale = $registry->getProvider('github');

        // A repeat request with unchanged settings must NOT churn the registry.
        $this->assertTrue($boot->ensureProviderRegistered('github'));
        $this->assertSame($stale, $registry->getProvider('github'));

        // Another worker saves a corrected secret straight into the shared store.
        $store->save(GithubPlugin::PLUGIN_NAME, ['client_id' => 'cid', 'client_secret' => 'new-secret']);

        $this->assertTrue($boot->ensureProviderRegistered('github'));
        $this->assertNotSame(
            $stale,
            $registry->getProvider('github'),
            'a changed settings fingerprint must rebuild the provider on every worker',
        );
    }

    /**
     * The S44 race-guard must still hold when the provider settings come from the
     * DB store (the getSettings()-yields path activated by S48): a concurrent
     * coroutine that loses the register race gets a benign no-throw, not a 500.
     */
    public function test_github_db_settings_lost_race_is_benign(): void
    {
        $racyRegistry = new class extends AuthProviderRegistry {
            private int $hasProviderCalls = 0;

            public function hasProvider(string $name): bool
            {
                return $this->hasProviderCalls++ > 0;
            }

            public function registerProvider(
                \Phlix\Shared\Auth\ProviderInterface $provider,
                string $instance = self::DEFAULT_INSTANCE
            ): void {
                throw new \RuntimeException(
                    "Auth provider '{$provider->name()}' is already registered."
                );
            }
        };

        $boot = new AuthProviderBootstrapper(
            $this->makeSettingsRepo(['github' => true]),
            $racyRegistry,
            new OidcPlugin(),
            new LdapPlugin(),
            $this->makeGithubPlugin(['client_id' => 'cid', 'client_secret' => 'sec']),
        );

        $result = $boot->ensureProviderRegistered('github');

        $this->assertTrue($result);
        $this->assertTrue($racyRegistry->hasProvider('github'));
    }
}
