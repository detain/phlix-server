<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Plugins\Ldap\Plugin as LdapPlugin;
use Phlix\Plugins\Oidc\Plugin as OidcPlugin;

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

    public function test_flag_key_format(): void
    {
        $this->assertSame('auth.oidc.enabled', AuthProviderBootstrapper::flagKey('oidc'));
        $this->assertSame('auth.ldap.enabled', AuthProviderBootstrapper::flagKey('ldap'));
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
}
