<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Plugins\Oidc;

use PHPUnit\Framework\TestCase;
use Phlex\Plugins\Oidc\Controller\OidcAdminController;
use Phlex\Plugins\Oidc\Plugin;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;

/**
 * @covers \Phlex\Plugins\Oidc\Controller\OidcAdminController
 */
final class OidcAdminControllerTest extends TestCase
{
    private string $pluginDir;
    private Plugin $plugin;
    private OidcAdminController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pluginDir = sys_get_temp_dir() . '/phlex_oidc_admin_test_' . uniqid();
        mkdir($this->pluginDir, 0755, true);

        Plugin::setPluginDirectory($this->pluginDir);
        $this->plugin = new Plugin();
        $this->controller = new OidcAdminController($this->plugin);
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

    public function test_get_settings_returns_default_when_not_configured(): void
    {
        $request = new Request();
        $response = $this->controller->getSettings($request, []);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertFalse($body['configured']);
        $this->assertSame('', $body['provider_url']);
        $this->assertSame('', $body['client_id']);
        $this->assertSame('openid profile email', $body['scopes']);
    }

    public function test_get_settings_returns_saved_settings_without_secret(): void
    {
        $this->plugin->saveSettings([
            'provider_url' => 'https://test.example.com',
            'client_id' => 'my-client-id',
            'client_secret' => 'secret-value',
            'scopes' => 'openid profile',
        ]);

        $request = new Request();
        $response = $this->controller->getSettings($request, []);

        $body = json_decode($response->body, true);
        $this->assertTrue($body['configured']);
        $this->assertSame('https://test.example.com', $body['provider_url']);
        $this->assertSame('my-client-id', $body['client_id']);
        $this->assertSame('openid profile', $body['scopes']);
    }

    public function test_save_settings_requires_provider_url(): void
    {
        $request = new Request();
        $request->body = [
            'client_id' => 'test-client',
        ];

        $response = $this->controller->saveSettings($request, []);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('missing_provider_url', $body['error']);
    }

    public function test_save_settings_requires_client_id(): void
    {
        $request = new Request();
        $request->body = [
            'provider_url' => 'https://example.com',
        ];

        $response = $this->controller->saveSettings($request, []);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('missing_client_id', $body['error']);
    }

    public function test_save_settings_rejects_non_https_urls(): void
    {
        $request = new Request();
        $request->body = [
            'provider_url' => 'http://example.com',
            'client_id' => 'test-client',
        ];

        $response = $this->controller->saveSettings($request, []);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('invalid_provider_url', $body['error']);
    }

    public function test_save_settings_accepts_localhost_for_development(): void
    {
        $request = new Request();
        $request->body = [
            'provider_url' => 'http://localhost:8080',
            'client_id' => 'test-client',
        ];

        $response = $this->controller->saveSettings($request, []);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Settings saved successfully', $body['message']);
        $this->assertTrue($body['configured']);
    }

    public function test_save_settings_saves_configuration(): void
    {
        $request = new Request();
        $request->body = [
            'provider_url' => 'https://oidc-provider.example.com',
            'client_id' => 'my-client-id',
            'client_secret' => 'my-client-secret',
            'scopes' => 'openid profile email custom',
        ];

        $response = $this->controller->saveSettings($request, []);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Settings saved successfully', $body['message']);

        $savedSettings = $this->plugin->getSettings();
        $this->assertSame('https://oidc-provider.example.com', $savedSettings['provider_url']);
        $this->assertSame('my-client-id', $savedSettings['client_id']);
        $this->assertSame('my-client-secret', $savedSettings['client_secret']);
        $this->assertSame('openid profile email custom', $savedSettings['scopes']);
    }

    public function test_save_settings_does_not_overwrite_secret_if_empty(): void
    {
        $this->plugin->saveSettings([
            'provider_url' => 'https://example.com',
            'client_id' => 'client',
            'client_secret' => 'original-secret',
        ]);

        $request = new Request();
        $request->body = [
            'provider_url' => 'https://new.example.com',
            'client_id' => 'new-client',
        ];

        $response = $this->controller->saveSettings($request, []);

        $this->assertSame(200, $response->statusCode);

        $savedSettings = $this->plugin->getSettings();
        $this->assertSame('original-secret', $savedSettings['client_secret']);
    }

    public function test_get_schema_returns_valid_json_schema(): void
    {
        $request = new Request();
        $response = $this->controller->getSchema($request, []);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);

        $this->assertArrayHasKey('schema', $body);
        $schema = $body['schema'];
        $this->assertSame('OIDC Provider Configuration', $schema['title']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('provider_url', $schema['properties']);
        $this->assertArrayHasKey('client_id', $schema['properties']);
        $this->assertArrayHasKey('client_secret', $schema['properties']);
        $this->assertArrayHasKey('scopes', $schema['properties']);
    }
}
