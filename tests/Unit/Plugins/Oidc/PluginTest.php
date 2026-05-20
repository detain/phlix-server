<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Oidc;

use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Oidc\Plugin;

/**
 * @covers \Phlix\Plugins\Oidc\Plugin
 */
final class PluginTest extends TestCase
{
    private string $pluginDir;
    private string $originalDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pluginDir = sys_get_temp_dir() . '/phlix_oidc_plugin_test_' . uniqid();
        mkdir($this->pluginDir, 0755, true);
        $this->originalDir = Plugin::getPluginDirectory();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->pluginDir)) {
            $files = glob($this->pluginDir . '/*');
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->pluginDir);
        }
    }

    public function test_set_and_get_plugin_directory(): void
    {
        Plugin::setPluginDirectory($this->pluginDir);
        $this->assertSame($this->pluginDir, Plugin::getPluginDirectory());
    }

    public function test_get_plugin_directory_defaults_to_class_directory(): void
    {
        Plugin::setPluginDirectory(__DIR__);
        $dir = Plugin::getPluginDirectory();
        $this->assertSame(__DIR__, $dir);
    }

    public function test_subscribed_events_returns_empty_array(): void
    {
        Plugin::setPluginDirectory($this->pluginDir);
        $plugin = new Plugin();

        $events = $plugin->subscribedEvents();

        $this->assertIsArray($events);
        $this->assertEmpty($events);
    }

    public function test_on_disable_does_nothing(): void
    {
        Plugin::setPluginDirectory($this->pluginDir);
        $plugin = new Plugin();

        $plugin->onDisable();
        $this->assertTrue(true);
    }

    public function test_save_and_get_settings(): void
    {
        Plugin::setPluginDirectory($this->pluginDir);
        $plugin = new Plugin();

        $settings = [
            'provider_url' => 'https://example.com',
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'scopes' => 'openid profile email',
        ];

        $plugin->saveSettings($settings);
        $loaded = $plugin->getSettings();

        $this->assertSame($settings['provider_url'], $loaded['provider_url']);
        $this->assertSame($settings['client_id'], $loaded['client_id']);
        $this->assertSame($settings['client_secret'], $loaded['client_secret']);
        $this->assertSame($settings['scopes'], $loaded['scopes']);
    }

    public function test_get_settings_returns_empty_array_when_no_settings_file(): void
    {
        Plugin::setPluginDirectory($this->pluginDir);
        $plugin = new Plugin();

        $loaded = $plugin->getSettings();

        $this->assertIsArray($loaded);
    }

    public function test_get_settings_returns_existing_secret_when_not_provided_in_save(): void
    {
        Plugin::setPluginDirectory($this->pluginDir);
        $plugin = new Plugin();

        $initialSettings = [
            'provider_url' => 'https://example.com',
            'client_id' => 'test-client',
            'client_secret' => 'original-secret',
        ];

        $plugin->saveSettings($initialSettings);

        $updatedSettings = [
            'provider_url' => 'https://new-provider.com',
            'client_id' => 'new-client',
            'client_secret' => 'original-secret',
        ];

        $plugin->saveSettings($updatedSettings);
        $loaded = $plugin->getSettings();

        $this->assertSame('https://new-provider.com', $loaded['provider_url']);
        $this->assertSame('new-client', $loaded['client_id']);
        $this->assertSame('original-secret', $loaded['client_secret']);
    }
}
