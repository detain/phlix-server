<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Github;

use Phlix\Auth\AuthProviderRegistry;
use Phlix\Plugins\Github\GithubOAuthProvider;
use Phlix\Plugins\Github\Plugin;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Exercises the S48 DB-backed plugin settings path ({@see \Phlix\Plugins\PluginDbSettings})
 * through the GitHub plugin: the DB round-trip, the one-time settings.json → DB
 * import (backward-compat), and the no-DB file fallback.
 *
 */
final class GithubPluginDbSettingsTest extends TestCase
{
    private string $pluginDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pluginDir = sys_get_temp_dir() . '/phlix_github_plugin_' . uniqid();
        mkdir($this->pluginDir, 0755, true);
        Plugin::setPluginDirectory($this->pluginDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob($this->pluginDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->pluginDir);
        Plugin::setPluginDirectory(\dirname(__DIR__, 3) . '/src/Plugins/Github');
    }

    public function test_db_store_round_trips_settings(): void
    {
        $store = new InMemoryPluginSettingsRepository();
        $plugin = new Plugin($store);

        $plugin->saveSettings([
            'client_id' => 'gh-client',
            'client_secret' => 'gh-secret',
            'scopes' => 'read:user user:email',
        ]);

        $loaded = $plugin->getSettings();
        $this->assertSame('gh-client', $loaded['client_id']);
        $this->assertSame('gh-secret', $loaded['client_secret']);
        $this->assertSame('read:user user:email', $loaded['scopes']);

        // It genuinely persisted to the DB store (not a file).
        $this->assertArrayHasKey('github', $store->rows);
        $this->assertFalse(is_file($this->pluginDir . '/settings.json'));
    }

    public function test_legacy_settings_json_is_imported_once_into_db(): void
    {
        // Pre-existing on-disk config from a pre-S48 deployment.
        file_put_contents(
            $this->pluginDir . '/settings.json',
            (string) json_encode(['client_id' => 'legacy-id', 'client_secret' => 'legacy-secret']),
        );

        $store = new InMemoryPluginSettingsRepository();
        $plugin = new Plugin($store);

        // First read imports the file into the DB and returns it.
        $first = $plugin->getSettings();
        $this->assertSame('legacy-id', $first['client_id']);
        $this->assertArrayHasKey('github', $store->rows);
        $this->assertSame('legacy-secret', $store->rows['github']['client_secret'] ?? null);

        // Remove the file — a second read must now come from the DB store.
        unlink($this->pluginDir . '/settings.json');
        $second = $plugin->getSettings();
        $this->assertSame('legacy-id', $second['client_id']);
    }

    public function test_no_db_store_falls_back_to_file(): void
    {
        $plugin = new Plugin(); // no store injected

        $plugin->saveSettings(['client_id' => 'file-only']);

        $this->assertTrue(is_file($this->pluginDir . '/settings.json'));
        $this->assertSame('file-only', $plugin->getSettings()['client_id']);
    }

    public function test_mask_secrets_strips_client_secret(): void
    {
        $plugin = new Plugin(new InMemoryPluginSettingsRepository());

        $masked = $plugin->maskSecrets(['client_id' => 'x', 'client_secret' => 'shh']);

        $this->assertArrayHasKey('client_id', $masked);
        $this->assertArrayNotHasKey('client_secret', $masked);
    }

    // -----------------------------------------------------------------------
    // S48 TestEngineer — the remaining uncovered branches of the new plugin
    // entry point and of the trait's one-time-import latch.
    // -----------------------------------------------------------------------

    /**
     * Review r2 NEW-6 — the legacy-import attempt is latched PER INSTANCE, so a
     * worker whose store is empty does not re-stat `settings.json` on every
     * authorize/callback request (a blocking syscall on the event-loop path). The
     * second read must therefore NOT touch the filesystem again: proven by dropping
     * a `settings.json` in place only AFTER the first read and showing it is
     * ignored until a fresh instance is built.
     */
    public function test_the_legacy_import_is_attempted_only_once_per_instance(): void
    {
        $store = new InMemoryPluginSettingsRepository();
        $plugin = new Plugin($store);

        $this->assertSame([], $plugin->getSettings(), 'nothing stored and no legacy file yet');
        $this->assertArrayNotHasKey('github', $store->rows, 'an empty legacy file must not be imported');

        // The file appears at runtime, AFTER the latch was set.
        file_put_contents(
            $this->pluginDir . '/settings.json',
            (string) json_encode(['client_id' => 'appeared-later']),
        );

        $this->assertSame([], $plugin->getSettings(), 'the latch must prevent a second file read');
        $this->assertArrayNotHasKey('github', $store->rows);

        // A NEW instance (i.e. the next worker start) does pick it up — the
        // deliberately accepted consequence documented on the latch.
        $this->assertSame('appeared-later', (new Plugin($store))->getSettings()['client_id'] ?? null);
    }

    /**
     * A malformed legacy `settings.json` must degrade to "no settings" rather than
     * throwing on the request path — and must not be imported into the DB store.
     *
     * @dataProvider unusableLegacyFiles
     */
    public function test_an_unusable_legacy_settings_file_yields_no_settings(string $contents): void
    {
        file_put_contents($this->pluginDir . '/settings.json', $contents);
        $store = new InMemoryPluginSettingsRepository();

        $this->assertSame([], (new Plugin($store))->getSettings());
        $this->assertArrayNotHasKey('github', $store->rows);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableLegacyFiles(): array
    {
        return [
            'not json' => ['this is not json at all'],
            'json scalar' => ['"just-a-string"'],
            'json null' => ['null'],
            'empty file' => [''],
            'json empty object' => ['{}'],
        ];
    }

    /**
     * `onEnable()` is the parity lifecycle hook (bundled providers are really
     * registered by {@see \Phlix\Auth\AuthProviderBootstrapper}, not the
     * PluginLoader). Invoked directly it must build a provider from the DB-backed
     * settings and register it — including the DEFAULT_SCOPES fallback when the
     * stored scopes are absent or blank.
     */
    public function test_on_enable_registers_a_provider_built_from_the_db_settings(): void
    {
        $store = new InMemoryPluginSettingsRepository();
        $store->save(Plugin::PLUGIN_NAME, [
            'client_id' => 'cid',
            'client_secret' => 'sec',
            'scopes' => 'read:user repo',
        ]);
        $registry = new AuthProviderRegistry();

        (new Plugin($store))->onEnable($this->containerWith($registry));

        $this->assertTrue($registry->hasProvider('github'));
        $provider = $registry->getProvider('github');
        $this->assertInstanceOf(GithubOAuthProvider::class, $provider);
        $this->assertSame('cid', $provider->getClientId());
        $this->assertSame('read:user repo', self::scopeOf($provider));
    }

    public function test_on_enable_falls_back_to_the_default_scopes(): void
    {
        $store = new InMemoryPluginSettingsRepository();
        $store->save(Plugin::PLUGIN_NAME, ['client_id' => 'cid', 'client_secret' => 'sec', 'scopes' => '']);
        $registry = new AuthProviderRegistry();

        (new Plugin($store))->onEnable($this->containerWith($registry));

        $provider = $registry->getProvider('github');
        $this->assertInstanceOf(GithubOAuthProvider::class, $provider);
        $this->assertSame(GithubOAuthProvider::DEFAULT_SCOPES, self::scopeOf($provider));
    }

    /**
     * The two remaining lifecycle members: `onDisable()` is a no-op (the registry
     * unregister is the bootstrapper's job) and the plugin subscribes to no events —
     * asserted so a future change that starts doing work there is a visible
     * decision rather than a silent one.
     */
    public function test_on_disable_is_a_no_op_and_no_events_are_subscribed(): void
    {
        $plugin = new Plugin(new InMemoryPluginSettingsRepository());

        $plugin->onDisable();

        $this->assertSame([], $plugin->subscribedEvents());
    }

    /**
     * The scopes a provider was built with, read back off the authorize URL it
     * produces (there is no getter, and the URL is what actually reaches GitHub).
     */
    private static function scopeOf(GithubOAuthProvider $provider): string
    {
        $params = [];
        parse_str(
            (string) parse_url($provider->buildAuthorizationUrl('https://p.example/cb'), PHP_URL_QUERY),
            $params,
        );

        return is_string($params['scope'] ?? null) ? $params['scope'] : '';
    }

    private function containerWith(AuthProviderRegistry $registry): ContainerInterface
    {
        return new class ($registry) implements ContainerInterface {
            public function __construct(private readonly AuthProviderRegistry $registry)
            {
            }

            public function get(string $id): mixed
            {
                return $this->registry;
            }

            public function has(string $id): bool
            {
                return $id === AuthProviderRegistry::class;
            }
        };
    }
}
