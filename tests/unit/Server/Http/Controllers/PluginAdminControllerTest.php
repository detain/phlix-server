<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Server\Http\Controllers;

use DateTimeImmutable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Phlex\Common\Logger\AuditLogger;
use Phlex\Plugins\Exception\PluginEnableException;
use Phlex\Plugins\Exception\PluginInstallException;
use Phlex\Plugins\Exception\PluginNotFoundException;
use Phlex\Plugins\InstalledPlugin;
use Phlex\Plugins\Manifest;
use Phlex\Plugins\ManifestValidationError;
use Phlex\Plugins\PluginLoader;
use Phlex\Server\Http\Controllers\PluginAdminController;
use Phlex\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see PluginAdminController} (Step A.5).
 *
 * {@see PluginLoader} is `final` so PHPUnit can't double it; tests use
 * Mockery (already a project dev-dep) to mock it.
 *
 * @covers \Phlex\Server\Http\Controllers\PluginAdminController
 */
final class PluginAdminControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var PluginLoader&MockInterface */
    private PluginLoader&MockInterface $loader;
    /** @var AuditLogger&MockInterface */
    private AuditLogger&MockInterface $audit;
    private PluginAdminController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = Mockery::mock(PluginLoader::class);
        $this->audit  = Mockery::mock(AuditLogger::class)->shouldIgnoreMissing();
        $this->controller = new PluginAdminController($this->loader, $this->audit);
    }

    public function test_index_returns_plugin_list_as_json(): void
    {
        $this->loader->shouldReceive('listInstalled')
            ->once()
            ->andReturn([$this->fixturePlugin('phlex-plugin-demo', enabled: true)]);

        $response = $this->controller->index($this->makeRequest('admin-1'), []);

        $this->assertSame(200, $response->statusCode);
        $body = $this->decode($response->body);
        $this->assertArrayHasKey('plugins', $body);
        $this->assertCount(1, $body['plugins']);
        $this->assertSame('phlex-plugin-demo', $body['plugins'][0]['name']);
        $this->assertTrue($body['plugins'][0]['enabled']);
        $this->assertArrayHasKey('settings', $body['plugins'][0]);
    }

    public function test_install_returns_201_with_manifest_on_success(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlex-plugin-demo',
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'metadata-provider',
            'entry' => 'Demo\\Plugin',
        ]);

        $this->loader->shouldReceive('install')
            ->once()
            ->with('https://example.com/plugin.json')
            ->andReturn($manifest);

        $this->audit->shouldReceive('logDataExport')
            ->once()
            ->with('admin-1', 'plugin.install.ui', 1);

        $response = $this->controller->install(
            $this->makeRequest('admin-1', ['url' => 'https://example.com/plugin.json']),
            [],
        );

        $this->assertSame(201, $response->statusCode);
        $body = $this->decode($response->body);
        $this->assertSame('phlex-plugin-demo', $body['plugin']['name']);
        $this->assertSame('1.0.0', $body['plugin']['version']);
    }

    public function test_install_returns_400_on_missing_url(): void
    {
        $this->loader->shouldNotReceive('install');
        $this->audit->shouldNotReceive('logDataExport');

        $response = $this->controller->install($this->makeRequest('admin-1', []), []);

        $this->assertSame(400, $response->statusCode);
        $body = $this->decode($response->body);
        $this->assertSame('plugin.url.required', $body['code']);
    }

    public function test_install_returns_400_on_non_https_scheme(): void
    {
        $this->loader->shouldNotReceive('install');

        $response = $this->controller->install(
            $this->makeRequest('admin-1', ['url' => 'http://example.com/plugin.json']),
            [],
        );

        $this->assertSame(400, $response->statusCode);
        $body = $this->decode($response->body);
        $this->assertSame('plugin.url.invalid_scheme', $body['code']);
    }

    public function test_install_returns_422_on_invalid_manifest_with_field_errors(): void
    {
        $this->loader->shouldReceive('install')->andThrow(new PluginInstallException(
            'Manifest is missing required fields.',
            [
                new ManifestValidationError(field: 'name', code: 'required', message: 'name is required'),
                new ManifestValidationError(field: 'version', code: 'required', message: 'version is required'),
            ],
        ));

        $response = $this->controller->install(
            $this->makeRequest('admin-1', ['url' => 'https://example.com/plugin.json']),
            [],
        );

        $this->assertSame(422, $response->statusCode);
        $body = $this->decode($response->body);
        $this->assertSame('plugin.install.failed', $body['code']);
        $this->assertCount(2, $body['fields']);
        $this->assertSame('name', $body['fields'][0]['field']);
    }

    public function test_enable_returns_200_and_calls_loader(): void
    {
        $this->loader->shouldReceive('enable')->once()->with('phlex-plugin-demo');
        $this->audit->shouldReceive('logDataExport')
            ->once()
            ->with('admin-1', 'plugin.enable.ui', 1);

        $response = $this->controller->enable($this->makeRequest('admin-1'), ['name' => 'phlex-plugin-demo']);

        $this->assertSame(200, $response->statusCode);
        $body = $this->decode($response->body);
        $this->assertSame('phlex-plugin-demo', $body['plugin']['name']);
        $this->assertTrue($body['plugin']['enabled']);
    }

    public function test_enable_returns_404_when_plugin_not_found(): void
    {
        $this->loader->shouldReceive('enable')->andThrow(
            new PluginNotFoundException('No installed plugin named "missing".'),
        );

        $response = $this->controller->enable($this->makeRequest('admin-1'), ['name' => 'missing']);

        $this->assertSame(404, $response->statusCode);
        $body = $this->decode($response->body);
        $this->assertSame('plugin.not_found', $body['code']);
    }

    public function test_enable_returns_422_when_enable_fails(): void
    {
        $this->loader->shouldReceive('enable')->andThrow(new PluginEnableException('entry class missing'));

        $response = $this->controller->enable($this->makeRequest('admin-1'), ['name' => 'broken']);

        $this->assertSame(422, $response->statusCode);
    }

    public function test_disable_returns_200(): void
    {
        $this->loader->shouldReceive('disable')->once()->with('phlex-plugin-demo');
        $this->audit->shouldReceive('logDataExport')
            ->once()
            ->with('admin-1', 'plugin.disable.ui', 1);

        $response = $this->controller->disable($this->makeRequest('admin-1'), ['name' => 'phlex-plugin-demo']);

        $this->assertSame(200, $response->statusCode);
        $body = $this->decode($response->body);
        $this->assertFalse($body['plugin']['enabled']);
    }

    public function test_disable_returns_404_when_plugin_not_found(): void
    {
        $this->loader->shouldReceive('disable')->andThrow(
            new PluginNotFoundException('No installed plugin named "missing".'),
        );

        $response = $this->controller->disable($this->makeRequest('admin-1'), ['name' => 'missing']);

        $this->assertSame(404, $response->statusCode);
    }

    public function test_uninstall_returns_204(): void
    {
        $this->loader->shouldReceive('uninstall')->once()->with('phlex-plugin-demo');
        $this->audit->shouldReceive('logDataExport')
            ->once()
            ->with('admin-1', 'plugin.uninstall.ui', 1);

        $response = $this->controller->uninstall($this->makeRequest('admin-1'), ['name' => 'phlex-plugin-demo']);

        $this->assertSame(204, $response->statusCode);
    }

    public function test_uninstall_returns_404_when_plugin_not_found(): void
    {
        $this->loader->shouldReceive('uninstall')->andThrow(
            new PluginNotFoundException('No installed plugin named "missing".'),
        );

        $response = $this->controller->uninstall($this->makeRequest('admin-1'), ['name' => 'missing']);

        $this->assertSame(404, $response->statusCode);
    }

    public function test_every_action_logs_to_audit_logger(): void
    {
        $this->loader->shouldReceive('install')->andReturn(Manifest::fromArray([
            'name' => 'phlex-plugin-demo',
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'metadata-provider',
            'entry' => 'Demo\\Plugin',
        ]));
        $this->loader->shouldReceive('enable')->andReturnNull();
        $this->loader->shouldReceive('disable')->andReturnNull();
        $this->loader->shouldReceive('uninstall')->andReturnNull();

        $this->audit->shouldReceive('logDataExport')->times(4);

        $this->controller->install(
            $this->makeRequest('admin-1', ['url' => 'https://example.com/plugin.json']),
            [],
        );
        $this->controller->enable($this->makeRequest('admin-1'), ['name' => 'phlex-plugin-demo']);
        $this->controller->disable($this->makeRequest('admin-1'), ['name' => 'phlex-plugin-demo']);
        $this->controller->uninstall($this->makeRequest('admin-1'), ['name' => 'phlex-plugin-demo']);
    }

    public function test_action_routes_reject_empty_name(): void
    {
        $this->loader->shouldNotReceive('enable');
        $this->loader->shouldNotReceive('disable');
        $this->loader->shouldNotReceive('uninstall');

        foreach (['enable', 'disable', 'uninstall'] as $action) {
            /** @var \Phlex\Server\Http\Response $resp */
            $resp = $this->controller->{$action}($this->makeRequest('admin-1'), ['name' => '   ']);
            $this->assertSame(400, $resp->statusCode, "action $action should reject blank name");
        }
    }

    public function test_index_masks_secret_settings(): void
    {
        $this->loader->shouldReceive('listInstalled')->andReturn([
            $this->fixturePlugin(
                'phlex-plugin-secret',
                enabled: false,
                settings: ['api_key' => 'topsecret', 'verbose' => true],
                manifestSettings: [
                    'api_key' => ['type' => 'string', 'secret' => true],
                    'verbose' => ['type' => 'bool'],
                ],
            ),
        ]);

        $response = $this->controller->index($this->makeRequest('admin-1'), []);

        $body = $this->decode($response->body);
        $this->assertSame('***', $body['plugins'][0]['settings']['api_key']);
        $this->assertTrue($body['plugins'][0]['settings']['verbose']);
    }

    /**
     * @param array<string, array{type: string, required?: bool, secret?: bool, default?: mixed}> $manifestSettings
     * @param array<string, mixed> $settings
     */
    private function fixturePlugin(
        string $name,
        bool $enabled,
        array $settings = [],
        array $manifestSettings = [],
    ): InstalledPlugin {
        return new InstalledPlugin(
            id: 'id-' . $name,
            manifest: Manifest::fromArray([
                'name' => $name,
                'version' => '1.0.0',
                'phlex_min_server_version' => '0.10.0',
                'type' => 'metadata-provider',
                'entry' => 'Demo\\Plugin',
                'settings' => $manifestSettings,
            ]),
            enabled: $enabled,
            installedAt: new DateTimeImmutable('2024-01-01 00:00:00'),
            settings: $settings,
            directory: '/tmp/' . $name,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function makeRequest(?string $userId, array $body = []): Request
    {
        $request           = new Request();
        $request->method   = 'GET';
        $request->path     = '/api/v1/admin/plugins';
        $request->headers  = [];
        $request->query    = [];
        $request->body     = $body;
        $request->files    = [];
        $request->remoteIp = '127.0.0.1';
        $request->remotePort = 0;
        $request->protocol = 'HTTP/1.1';
        $request->queryString = '';
        $request->userId   = $userId;
        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        return $decoded;
    }
}
