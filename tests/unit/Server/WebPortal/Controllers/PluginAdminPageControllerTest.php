<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal\Controllers;

use DateTimeImmutable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Phlix\Plugins\InstalledPlugin;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\PluginLoader;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\Controllers\PluginAdminPageController;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see PluginAdminPageController}.
 *
 * Smarty is not declared as a Composer dependency, so the
 * full-rendering tests are skipped when the runtime Smarty class is
 * unavailable. The remaining tests still exercise the loader-querying
 * logic and the "plugin not found" branch.
 *
 * @covers \Phlix\Server\WebPortal\Controllers\PluginAdminPageController
 */
final class PluginAdminPageControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_detail_returns_404_when_plugin_is_unknown(): void
    {
        $loader = Mockery::mock(PluginLoader::class);
        $loader->shouldReceive('listInstalled')->andReturn([]);

        $controller = new PluginAdminPageController(
            $loader,
            $this->makeTemplateDir(),
        );

        $response = $controller->detail($this->makeRequest(), ['name' => 'missing']);

        $this->assertSame(404, $response->statusCode);
        $this->assertStringContainsString('plugin not found', $response->body);
    }

    public function test_detail_returns_400_when_name_param_is_blank(): void
    {
        $loader = Mockery::mock(PluginLoader::class);
        $loader->shouldNotReceive('listInstalled');

        $controller = new PluginAdminPageController(
            $loader,
            $this->makeTemplateDir(),
        );

        $response = $controller->detail($this->makeRequest(), ['name' => '']);

        $this->assertSame(400, $response->statusCode);
    }

    public function test_index_renders_template_when_smarty_available(): void
    {
        $this->skipWithoutSmarty();

        $loader = Mockery::mock(PluginLoader::class);
        $loader->shouldReceive('listInstalled')->andReturn([$this->fixturePlugin()]);

        $controller = new PluginAdminPageController(
            $loader,
            $this->realTemplateDir(),
        );

        $response = $controller->index($this->makeRequest(), []);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('phlix-plugin-demo', $response->body);
        // Ensure HTML escaping was applied (no raw template syntax leaked).
        $this->assertStringNotContainsString('{$plugin', $response->body);
    }

    public function test_install_renders_form_when_smarty_available(): void
    {
        $this->skipWithoutSmarty();

        $loader = Mockery::mock(PluginLoader::class);
        $controller = new PluginAdminPageController($loader, $this->realTemplateDir());

        $response = $controller->install($this->makeRequest(), []);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('/api/v1/admin/plugins/install', $response->body);
    }

    public function test_detail_renders_template_when_smarty_available(): void
    {
        $this->skipWithoutSmarty();

        $loader = Mockery::mock(PluginLoader::class);
        $loader->shouldReceive('listInstalled')->andReturn([$this->fixturePlugin()]);

        $controller = new PluginAdminPageController($loader, $this->realTemplateDir());

        $response = $controller->detail($this->makeRequest(), ['name' => 'phlix-plugin-demo']);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('phlix-plugin-demo', $response->body);
    }

    private function fixturePlugin(): InstalledPlugin
    {
        return new InstalledPlugin(
            id: 'id-1',
            manifest: Manifest::fromArray([
                'name' => 'phlix-plugin-demo',
                'version' => '1.0.0',
                'phlix_min_server_version' => '0.10.0',
                'type' => 'metadata-provider',
                'entry' => 'Demo\\Plugin',
                'settings' => [
                    'api_key' => ['type' => 'string', 'secret' => true],
                    'verbose' => ['type' => 'bool', 'default' => false],
                ],
            ]),
            enabled: true,
            installedAt: new DateTimeImmutable('2024-01-01 00:00:00'),
            settings: ['api_key' => 'topsecret', 'verbose' => false],
            directory: '/tmp/phlix-plugin-demo',
        );
    }

    private function makeRequest(): Request
    {
        $request           = new Request();
        $request->method   = 'GET';
        $request->path     = '/admin/plugins';
        $request->headers  = [];
        $request->query    = [];
        $request->body     = [];
        $request->files    = [];
        $request->remoteIp = '127.0.0.1';
        $request->remotePort = 0;
        $request->protocol = 'HTTP/1.1';
        $request->queryString = '';
        $request->userId   = 'admin-1';
        return $request;
    }

    /**
     * Templates dir for the "no Smarty needed" branch — points at a
     * tmpdir so any accidental fetch() raises rather than silently
     * loading the real templates.
     */
    private function makeTemplateDir(): string
    {
        return sys_get_temp_dir() . '/phlix_admin_no_smarty_' . uniqid('', true);
    }

    /**
     * Real template dir under public/templates. Used by the
     * Smarty-required tests.
     */
    private function realTemplateDir(): string
    {
        return dirname(__DIR__, 5) . '/public/templates';
    }

    private function skipWithoutSmarty(): void
    {
        if (!class_exists('Smarty')) {
            $this->markTestSkipped('Smarty runtime class not available; skipping render test.');
        }
    }
}
