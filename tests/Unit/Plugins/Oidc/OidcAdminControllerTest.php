<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Oidc;

use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Oidc\Controller\OidcAdminController;
use Phlix\Plugins\Oidc\Plugin;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

final class OidcAdminControllerTest extends TestCase
{
    private string $pluginDir;
    private Plugin $plugin;
    private OidcAdminController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pluginDir = sys_get_temp_dir() . '/phlix_oidc_admin_test_' . uniqid();
        mkdir($this->pluginDir, 0755, true);

        Plugin::setPluginDirectory($this->pluginDir);
        $this->plugin = new Plugin();
        $this->controller = new OidcAdminController($this->plugin);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->pluginDir)) {
            $files = glob($this->pluginDir . '/*') ?: [];
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
        /** @var array<string, mixed> $body */
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

        /** @var array<string, mixed> $body */
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
        /** @var array<string, mixed> $body */
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
        /** @var array<string, mixed> $body */
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
        /** @var array<string, mixed> $body */
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
        /** @var array<string, mixed> $body */
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
        /** @var array<string, mixed> $body */
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

    // -----------------------------------------------------------------------
    // Review r2 NEW-2 — the OIDC half of the r1-Finding-1 redirect_uri save
    // semantics had ZERO tests (the GitHub twin had three). Mirrors
    // GithubAdminControllerTest::test_save_*redirect_uri*.
    // -----------------------------------------------------------------------

    public function test_save_rejects_a_relative_redirect_uri(): void
    {
        $request = new Request();
        $request->body = [
            'provider_url' => 'https://idp.example.com',
            'client_id' => 'cid',
            'redirect_uri' => '/auth/oidc/callback',
        ];

        $response = $this->controller->saveSettings($request, []);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('invalid_redirect_uri', $body['error']);
        // Nothing may be persisted by a rejected save.
        $this->assertSame([], $this->plugin->getSettings());
    }

    public function test_save_stores_and_returns_an_absolute_redirect_uri(): void
    {
        $request = new Request();
        $request->body = [
            'provider_url' => 'https://idp.example.com',
            'client_id' => 'cid',
            'redirect_uri' => 'https://phlix.example/auth/oidc/callback',
        ];

        $this->assertSame(200, $this->controller->saveSettings($request, [])->statusCode);

        /** @var array<string, mixed> $body */
        $body = json_decode($this->controller->getSettings(new Request(), [])->body, true);
        $this->assertSame('https://phlix.example/auth/oidc/callback', $body['redirect_uri']);
    }

    /**
     * A save that omits `redirect_uri` entirely must PRESERVE the stored value —
     * `saveSettings()` is a wholesale replace, so an older client would otherwise
     * silently wipe the operator's configured callback URL.
     */
    public function test_save_without_the_redirect_uri_key_preserves_the_stored_value(): void
    {
        $this->plugin->saveSettings([
            'provider_url' => 'https://idp.example.com',
            'client_id' => 'cid',
            'redirect_uri' => 'https://phlix.example/auth/oidc/callback',
        ]);

        $request = new Request();
        $request->body = ['provider_url' => 'https://idp.example.com', 'client_id' => 'cid'];
        $this->controller->saveSettings($request, []);

        $this->assertSame(
            'https://phlix.example/auth/oidc/callback',
            $this->plugin->getSettings()['redirect_uri'] ?? null,
        );
    }

    public function test_explicitly_empty_redirect_uri_clears_it(): void
    {
        $this->plugin->saveSettings([
            'provider_url' => 'https://idp.example.com',
            'client_id' => 'cid',
            'redirect_uri' => 'https://phlix.example/auth/oidc/callback',
        ]);

        $request = new Request();
        $request->body = [
            'provider_url' => 'https://idp.example.com',
            'client_id' => 'cid',
            'redirect_uri' => '',
        ];
        $this->controller->saveSettings($request, []);

        $this->assertSame('', $this->plugin->getSettings()['redirect_uri'] ?? null);
    }

    // -----------------------------------------------------------------------
    // Review r2 NEW-3 — the same wholesale-replace trap for `scopes`.
    // -----------------------------------------------------------------------

    public function test_save_without_the_scopes_key_preserves_custom_scopes(): void
    {
        $this->plugin->saveSettings([
            'provider_url' => 'https://idp.example.com',
            'client_id' => 'cid',
            'scopes' => 'openid profile email groups',
        ]);

        $request = new Request();
        $request->body = ['provider_url' => 'https://idp.example.com', 'client_id' => 'cid'];
        $this->controller->saveSettings($request, []);

        $this->assertSame('openid profile email groups', $this->plugin->getSettings()['scopes'] ?? null);
    }

    public function test_explicitly_empty_scopes_resets_to_the_default(): void
    {
        $this->plugin->saveSettings([
            'provider_url' => 'https://idp.example.com',
            'client_id' => 'cid',
            'scopes' => 'openid profile email groups',
        ]);

        $request = new Request();
        $request->body = [
            'provider_url' => 'https://idp.example.com',
            'client_id' => 'cid',
            'scopes' => '',
        ];
        $this->controller->saveSettings($request, []);

        $this->assertSame('openid profile email', $this->plugin->getSettings()['scopes'] ?? null);
    }

    public function test_get_schema_returns_valid_json_schema(): void
    {
        $request = new Request();
        $response = $this->controller->getSchema($request, []);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);

        $this->assertArrayHasKey('schema', $body);
        /** @var array<string, mixed> $schema */
        $schema = $body['schema'];
        $this->assertSame('OIDC Provider Configuration', $schema['title']);
        $this->assertArrayHasKey('properties', $schema);
        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];
        $this->assertArrayHasKey('provider_url', $properties);
        $this->assertArrayHasKey('client_id', $properties);
        $this->assertArrayHasKey('client_secret', $properties);
        $this->assertArrayHasKey('scopes', $properties);
    }
}
