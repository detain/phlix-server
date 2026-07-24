<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Github;

use Phlix\Plugins\Github\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the S48 DB-backed plugin settings path ({@see \Phlix\Plugins\PluginDbSettings})
 * through the GitHub plugin: the DB round-trip, the one-time settings.json → DB
 * import (backward-compat), and the no-DB file fallback.
 *
 * @covers \Phlix\Plugins\PluginDbSettings
 * @covers \Phlix\Plugins\Github\Plugin
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
}
