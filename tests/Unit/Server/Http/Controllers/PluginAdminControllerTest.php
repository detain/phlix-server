<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use DateTimeImmutable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Plugins\Catalog\CatalogSourceResolver;
use Phlix\Plugins\Catalog\PluginCatalogService;
use Phlix\Plugins\Exception\PluginEnableException;
use Phlix\Plugins\Exception\PluginInstallException;
use Phlix\Plugins\Exception\PluginNotFoundException;
use Phlix\Plugins\InstalledPlugin;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\PluginFieldHelp;
use Phlix\Shared\Plugin\ManifestValidationError;
use Phlix\Plugins\PluginLoader;
use Phlix\Server\Http\Controllers\PluginAdminController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see PluginAdminController} (Step A.5).
 *
 * {@see PluginLoader} is `final` so PHPUnit can't double it; tests use
 * Mockery (already a project dev-dep) to mock it.
 *
 * @covers \Phlix\Server\Http\Controllers\PluginAdminController
 */
final class PluginAdminControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const DEFAULT_SOURCE = 'https://github.com/detain/phlix-plugins';
    private const CATALOG_RAW = 'https://raw.githubusercontent.com/detain/phlix-plugins/'
        . CatalogSourceResolver::OFFICIAL_PINNED_REF . '/plugins.json';

    /** @var PluginLoader&MockInterface */
    private PluginLoader&MockInterface $loader;
    /** @var AuditLogger&MockInterface */
    private AuditLogger&MockInterface $audit;
    private PluginAdminController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var PluginLoader&MockInterface $loader */
        $loader = Mockery::mock(PluginLoader::class);
        $this->loader = $loader;
        /** @var AuditLogger&MockInterface $audit */
        $audit = Mockery::mock(AuditLogger::class)->shouldIgnoreMissing();
        $this->audit = $audit;
        // Default catalog has no plugins, so pinFor() always returns [null, null]
        // (un-pinned). Tests that assert a pin rebuild the controller with a
        // populated catalog via controllerWithCatalog().
        $this->controller = new PluginAdminController(
            $this->loader,
            $this->audit,
            $this->catalogService([]),
        );

        // install() runs SourceUrlResolver::normalize(), which now applies the
        // SSRF guard to http(s) outputs. Inject a deterministic public resolver.
        SsrfGuard::setResolver(static fn (string $host): array => ['93.184.216.34']);

        // Isolate these unit tests from the production field-help overlay in
        // config/plugin_field_help.php so schema-projection assertions test the
        // manifest only. The dedicated overlay test injects its own map.
        PluginFieldHelp::setMapForTesting([]);
    }

    /**
     * Build a real {@see PluginCatalogService} (it is final, so it cannot be
     * mocked) backed by a stub settings store + offline fetcher that serves a
     * catalog listing the given plugin entries.
     *
     * @param list<array<string, mixed>> $plugins Raw `plugins[]` entries.
     */
    private function catalogService(array $plugins): PluginCatalogService
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturnCallback(
            static fn (string $key): mixed => $key === PluginCatalogService::KEY_DEFAULT_SOURCE
                ? self::DEFAULT_SOURCE
                : null,
        );
        $body = json_encode(['name' => 'Official', 'plugins' => $plugins], JSON_THROW_ON_ERROR);

        return new PluginCatalogService(
            $settings,
            static fn (string $url, int $t): string => $url === self::CATALOG_RAW
                ? $body
                : throw new \RuntimeException("unexpected catalog fetch: $url"),
        );
    }

    /**
     * Swap in a controller whose catalog lists the given plugin entries.
     *
     * @param list<array<string, mixed>> $plugins
     */
    private function useCatalog(array $plugins): void
    {
        $this->controller = new PluginAdminController(
            $this->loader,
            $this->audit,
            $this->catalogService($plugins),
        );
    }

    protected function tearDown(): void
    {
        SsrfGuard::reset();
        PluginFieldHelp::setMapForTesting(null);
        parent::tearDown();
    }

    public function test_index_returns_plugin_list_as_json(): void
    {
        $this->expectCall($this->loader, 'listInstalled')
            ->once()
            ->andReturn([$this->fixturePlugin('phlix-plugin-demo', enabled: true)]);

        $response = $this->controller->index($this->makeRequest('admin-1'), []);

        $this->assertSame(200, $response->statusCode);
        /** @var array{plugins: array<int, array<string, mixed>>} $body */
        $body = $this->decode($response->body);
        $this->assertArrayHasKey('plugins', $body);
        $this->assertCount(1, $body['plugins']);
        $this->assertSame('phlix-plugin-demo', $body['plugins'][0]['name']);
        $this->assertTrue($body['plugins'][0]['enabled']);
        $this->assertArrayHasKey('settings', $body['plugins'][0]);
    }

    public function test_install_returns_201_with_manifest_on_success(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-demo',
            'version' => '1.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'metadata-provider',
            'entry' => 'Demo\\Plugin',
        ]);

        $this->expectCall($this->loader, 'install')
            ->once()
            ->with('https://example.com/plugin.json', null, null)
            ->andReturn($manifest);

        $this->expectCall($this->audit, 'logPluginAction')
            ->once()
            ->with(
                'admin-1',
                'install',
                'phlix-plugin-demo',
                Mockery::on(static function ($ctx): bool {
                    return is_array($ctx)
                        && ($ctx['source'] ?? null) === 'ui'
                        && ($ctx['url'] ?? null) === 'https://example.com/plugin.json';
                })
            );

        $response = $this->controller->install(
            $this->makeRequest('admin-1', ['url' => 'https://example.com/plugin.json']),
            [],
        );

        $this->assertSame(201, $response->statusCode);
        /** @var array{plugin: array<string, mixed>} $body */
        $body = $this->decode($response->body);
        $this->assertSame('phlix-plugin-demo', $body['plugin']['name']);
        $this->assertSame('1.0.0', $body['plugin']['version']);
    }

    public function test_install_accepts_a_scheme_less_github_repository_url(): void
    {
        // Regression: a pasted repo URL like `github.com/owner/repo` used to be
        // 400-rejected by the scheme guard. It must now pass through to the
        // loader (which rewrites it to a tarball) verbatim.
        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-anidb',
            'version' => '0.1.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'metadata-provider',
            'entry' => 'Phlix\\Anidb\\AnidbMetadataProvider',
        ]);

        $this->expectCall($this->loader, 'install')
            ->once()
            ->with('github.com/detain/phlix-plugin-anidb', null, null)
            ->andReturn($manifest);

        $this->expectCall($this->audit, 'logPluginAction')->once();

        $response = $this->controller->install(
            $this->makeRequest('admin-1', ['url' => 'github.com/detain/phlix-plugin-anidb']),
            [],
        );

        $this->assertSame(201, $response->statusCode);
        /** @var array{plugin: array<string, mixed>} $body */
        $body = $this->decode($response->body);
        $this->assertSame('phlix-plugin-anidb', $body['plugin']['name']);
    }

    public function test_install_threads_catalog_pin_for_a_pinned_official_plugin(): void
    {
        // SV-B2: a pinned official catalog entry must forward its
        // artifactSha256 + ref into PluginLoader::install so the SV-S2b
        // default-deny is cleared (no PHLIX_PLUGINS_ALLOW_UNVERIFIED needed).
        $repo = 'https://github.com/detain/phlix-plugin-anidb';
        $sha  = str_repeat('a', 64);
        $ref  = str_repeat('b', 40);

        $this->useCatalog([[
            'name' => 'phlix-plugin-anidb',
            'repo' => $repo,
            'ref' => $ref,
            'artifactSha256' => $sha,
        ]]);

        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-anidb',
            'version' => '2.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'metadata-provider',
            'entry' => 'Phlix\\Anidb\\AnidbMetadataProvider',
        ]);

        $this->expectCall($this->loader, 'install')
            ->once()
            ->with($repo, $sha, $ref)
            ->andReturn($manifest);

        $this->expectCall($this->audit, 'logPluginAction')->once();

        $response = $this->controller->install(
            $this->makeRequest('admin-1', ['url' => $repo]),
            [],
        );

        $this->assertSame(201, $response->statusCode);
        /** @var array{plugin: array<string, mixed>} $body */
        $body = $this->decode($response->body);
        $this->assertSame('phlix-plugin-anidb', $body['plugin']['name']);
    }

    public function test_install_passes_null_pin_for_an_unpinned_operator_url(): void
    {
        // SV-B2: a URL not present in any catalog yields [null, null] from
        // pinFor(), so install is called un-pinned — preserving the SV-S2b
        // default-deny behaviour for operator-added sources. (The default
        // empty catalog from setUp() never matches this URL.)
        $url = 'https://example.com/operator-plugin.tar.gz';

        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-operator',
            'version' => '1.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'metadata-provider',
            'entry' => 'Op\\Plugin',
        ]);

        $this->expectCall($this->loader, 'install')
            ->once()
            ->with($url, null, null)
            ->andReturn($manifest);

        $this->expectCall($this->audit, 'logPluginAction')->once();

        $response = $this->controller->install(
            $this->makeRequest('admin-1', ['url' => $url]),
            [],
        );

        $this->assertSame(201, $response->statusCode);
        /** @var array{plugin: array<string, mixed>} $body */
        $body = $this->decode($response->body);
        $this->assertSame('phlix-plugin-operator', $body['plugin']['name']);
    }

    public function test_install_returns_400_on_missing_url(): void
    {
        $this->loader->shouldNotReceive('install');
        $this->audit->shouldNotReceive('logPluginAction');

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

    public function test_install_blocks_private_host_via_ssrf_guard(): void
    {
        SsrfGuard::setResolver(static fn (string $host): array => ['10.0.0.5']);
        $this->loader->shouldNotReceive('install');

        $response = $this->controller->install(
            $this->makeRequest('admin-1', ['url' => 'https://internal.example/plugin.tar.gz']),
            [],
        );

        $this->assertSame(400, $response->statusCode);
        $body = $this->decode($response->body);
        $this->assertSame('plugin.url.blocked', $body['code']);
    }

    public function test_install_returns_422_on_invalid_manifest_with_field_errors(): void
    {
        $this->expectCall($this->loader, 'install')->andThrow(new PluginInstallException(
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
        /** @var array{code: mixed, fields: array<int, array<string, mixed>>} $body */
        $body = $this->decode($response->body);
        $this->assertSame('plugin.install.failed', $body['code']);
        $this->assertCount(2, $body['fields']);
        $this->assertSame('name', $body['fields'][0]['field']);
    }

    public function test_enable_returns_200_and_calls_loader(): void
    {
        $this->expectCall($this->loader, 'enable')->once()->with('phlix-plugin-demo');
        $this->expectCall($this->audit, 'logPluginAction')
            ->once()
            ->with(
                'admin-1',
                'enable',
                'phlix-plugin-demo',
                Mockery::on(static fn ($ctx) => is_array($ctx) && ($ctx['source'] ?? null) === 'ui')
            );

        $response = $this->controller->enable($this->makeRequest('admin-1'), ['name' => 'phlix-plugin-demo']);

        $this->assertSame(200, $response->statusCode);
        /** @var array{plugin: array<string, mixed>} $body */
        $body = $this->decode($response->body);
        $this->assertSame('phlix-plugin-demo', $body['plugin']['name']);
        $this->assertTrue($body['plugin']['enabled']);
    }

    public function test_enable_returns_404_when_plugin_not_found(): void
    {
        $this->expectCall($this->loader, 'enable')->andThrow(
            new PluginNotFoundException('No installed plugin named "missing".'),
        );

        $response = $this->controller->enable($this->makeRequest('admin-1'), ['name' => 'missing']);

        $this->assertSame(404, $response->statusCode);
        $body = $this->decode($response->body);
        $this->assertSame('plugin.not_found', $body['code']);
    }

    public function test_enable_returns_422_when_enable_fails(): void
    {
        $this->expectCall($this->loader, 'enable')->andThrow(new PluginEnableException('entry class missing'));

        $response = $this->controller->enable($this->makeRequest('admin-1'), ['name' => 'broken']);

        $this->assertSame(422, $response->statusCode);
    }

    public function test_disable_returns_200(): void
    {
        $this->expectCall($this->loader, 'disable')->once()->with('phlix-plugin-demo');
        $this->expectCall($this->audit, 'logPluginAction')
            ->once()
            ->with(
                'admin-1',
                'disable',
                'phlix-plugin-demo',
                Mockery::on(static fn ($ctx) => is_array($ctx) && ($ctx['source'] ?? null) === 'ui')
            );

        $response = $this->controller->disable($this->makeRequest('admin-1'), ['name' => 'phlix-plugin-demo']);

        $this->assertSame(200, $response->statusCode);
        /** @var array{plugin: array<string, mixed>} $body */
        $body = $this->decode($response->body);
        $this->assertFalse($body['plugin']['enabled']);
    }

    public function test_disable_returns_404_when_plugin_not_found(): void
    {
        $this->expectCall($this->loader, 'disable')->andThrow(
            new PluginNotFoundException('No installed plugin named "missing".'),
        );

        $response = $this->controller->disable($this->makeRequest('admin-1'), ['name' => 'missing']);

        $this->assertSame(404, $response->statusCode);
    }

    public function test_uninstall_returns_200_with_body(): void
    {
        $this->expectCall($this->loader, 'uninstall')->once()->with('phlix-plugin-demo');
        $this->expectCall($this->audit, 'logPluginAction')
            ->once()
            ->with(
                'admin-1',
                'uninstall',
                'phlix-plugin-demo',
                Mockery::on(static fn ($ctx) => is_array($ctx) && ($ctx['source'] ?? null) === 'ui')
            );

        $response = $this->controller->uninstall($this->makeRequest('admin-1'), ['name' => 'phlix-plugin-demo']);

        // A 200 with a JSON body (not 204) so the SPA fetch client can parse the
        // response and run its post-uninstall refresh instead of throwing on an
        // empty body and leaving the plugin lingering until a page reload.
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(
            ['uninstalled' => true, 'name' => 'phlix-plugin-demo'],
            $this->decode($response->body),
        );
    }

    public function test_uninstall_returns_404_when_plugin_not_found(): void
    {
        $this->expectCall($this->loader, 'uninstall')->andThrow(
            new PluginNotFoundException('No installed plugin named "missing".'),
        );

        $response = $this->controller->uninstall($this->makeRequest('admin-1'), ['name' => 'missing']);

        $this->assertSame(404, $response->statusCode);
    }

    public function test_every_action_logs_to_audit_logger(): void
    {
        $this->expectCall($this->loader, 'install')->andReturn(Manifest::fromArray([
            'name' => 'phlix-plugin-demo',
            'version' => '1.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'metadata-provider',
            'entry' => 'Demo\\Plugin',
        ]));
        $this->expectCall($this->loader, 'enable')->andReturnNull();
        $this->expectCall($this->loader, 'disable')->andReturnNull();
        $this->expectCall($this->loader, 'uninstall')->andReturnNull();

        $this->expectCall($this->audit, 'logPluginAction')->times(4);

        $this->controller->install(
            $this->makeRequest('admin-1', ['url' => 'https://example.com/plugin.json']),
            [],
        );
        $this->controller->enable($this->makeRequest('admin-1'), ['name' => 'phlix-plugin-demo']);
        $this->controller->disable($this->makeRequest('admin-1'), ['name' => 'phlix-plugin-demo']);
        $this->controller->uninstall($this->makeRequest('admin-1'), ['name' => 'phlix-plugin-demo']);
    }

    public function test_action_routes_reject_empty_name(): void
    {
        $this->loader->shouldNotReceive('enable');
        $this->loader->shouldNotReceive('disable');
        $this->loader->shouldNotReceive('uninstall');

        foreach (['enable', 'disable', 'uninstall'] as $action) {
            /** @var \Phlix\Server\Http\Response $resp */
            $resp = $this->controller->{$action}($this->makeRequest('admin-1'), ['name' => '   ']);
            $this->assertSame(400, $resp->statusCode, "action $action should reject blank name");
        }
    }

    public function test_index_masks_secret_settings(): void
    {
        $this->expectCall($this->loader, 'listInstalled')->andReturn([
            $this->fixturePlugin(
                'phlix-plugin-secret',
                enabled: false,
                settings: ['api_key' => 'topsecret', 'verbose' => true],
                manifestSettings: [
                    'api_key' => ['type' => 'string', 'secret' => true],
                    'verbose' => ['type' => 'bool'],
                ],
            ),
        ]);

        $response = $this->controller->index($this->makeRequest('admin-1'), []);

        /** @var array{plugins: array<int, array{settings: array<string, mixed>}>} $body */
        $body = $this->decode($response->body);
        $this->assertSame('***', $body['plugins'][0]['settings']['api_key']);
        $this->assertTrue($body['plugins'][0]['settings']['verbose']);
    }

    // --- S6: detail + settings configure --------------------------------

    public function test_show_returns_404_when_not_found(): void
    {
        $this->expectCall($this->loader, 'getInstalled')->once()->with('missing')->andThrow(
            new PluginNotFoundException('No installed plugin named "missing".'),
        );

        $response = $this->controller->show($this->makeRequest('admin-1'), ['name' => 'missing']);

        $this->assertSame(404, $response->statusCode);
        $this->assertSame('plugin.not_found', $this->decode($response->body)['code']);
    }

    public function test_show_returns_schema_and_masked_values(): void
    {
        $this->expectCall($this->loader, 'getInstalled')->once()->with('phlix-plugin-anidb')->andReturn(
            $this->fixturePlugin(
                'phlix-plugin-anidb',
                enabled: true,
                settings: ['username' => 'joe', 'api_key' => 'topsecret', 'use_title_dump' => true],
                manifestSettings: [
                    'username' => ['type' => 'string', 'required' => true, 'label' => 'User'],
                    'api_key'  => ['type' => 'string', 'required' => true, 'secret' => true],
                    'use_title_dump' => ['type' => 'boolean', 'default' => true],
                ],
            ),
        );

        $response = $this->controller->show($this->makeRequest('admin-1'), ['name' => 'phlix-plugin-anidb']);

        $this->assertSame(200, $response->statusCode);
        /** @var array{name: mixed, enabled: mixed, settings_schema: array<string, array<string, mixed>>, settings: array<string, mixed>} $plugin */
        $plugin = $this->decode($response->body)['plugin'];
        $this->assertSame('phlix-plugin-anidb', $plugin['name']);
        $this->assertTrue($plugin['enabled']);
        // schema projection
        $this->assertTrue($plugin['settings_schema']['username']['required']);
        $this->assertSame('User', $plugin['settings_schema']['username']['label']);
        $this->assertTrue($plugin['settings_schema']['api_key']['secret']);
        $this->assertSame(true, $plugin['settings_schema']['use_title_dump']['default']);
        $this->assertArrayNotHasKey('default', $plugin['settings_schema']['username']);
        // masked values
        $this->assertSame('joe', $plugin['settings']['username']);
        $this->assertSame('***', $plugin['settings']['api_key']);
        $this->assertTrue($plugin['settings']['use_title_dump']);
        // secret_status distinguishes a set secret from an unset one, carrying
        // the length (not the value) so the UI can render length-appropriate dots.
        /** @var array{secret_status: array<string, array{set: bool, length: int}>} $plugin2 */
        $plugin2 = $this->decode($response->body)['plugin'];
        $this->assertTrue($plugin2['secret_status']['api_key']['set']);
        $this->assertSame(mb_strlen('topsecret'), $plugin2['secret_status']['api_key']['length']);
        $this->assertArrayNotHasKey('username', $plugin2['secret_status'], 'only secret keys appear');
    }

    public function test_show_reports_unset_secret_status(): void
    {
        $this->expectCall($this->loader, 'getInstalled')->once()->with('phlix-plugin-anidb')->andReturn(
            $this->fixturePlugin(
                'phlix-plugin-anidb',
                enabled: false,
                settings: ['api_key' => ''],
                manifestSettings: [
                    'api_key' => ['type' => 'string', 'required' => true, 'secret' => true],
                ],
            ),
        );

        $response = $this->controller->show($this->makeRequest('admin-1'), ['name' => 'phlix-plugin-anidb']);

        /** @var array{secret_status: array<string, array{set: bool, length: int}>} $plugin */
        $plugin = $this->decode($response->body)['plugin'];
        $this->assertFalse($plugin['secret_status']['api_key']['set']);
        $this->assertSame(0, $plugin['secret_status']['api_key']['length']);
    }

    public function test_show_applies_field_help_overlay(): void
    {
        PluginFieldHelp::setMapForTesting([
            'phlix-plugin-anidb' => [
                'api_key' => [
                    'label'       => 'AniDB UDP API Key',
                    'description' => 'Set one in your AniDB profile.',
                    'link'        => 'https://anidb.net/software/api',
                    'link_text'   => 'AniDB API docs',
                ],
            ],
        ]);

        $this->expectCall($this->loader, 'getInstalled')->once()->with('phlix-plugin-anidb')->andReturn(
            $this->fixturePlugin(
                'phlix-plugin-anidb',
                enabled: false,
                settings: ['api_key' => 'x'],
                manifestSettings: [
                    // Terse manifest — the overlay upgrades the human-facing text.
                    'api_key' => ['type' => 'string', 'required' => true, 'secret' => true],
                ],
            ),
        );

        $response = $this->controller->show($this->makeRequest('admin-1'), ['name' => 'phlix-plugin-anidb']);

        /** @var array{settings_schema: array<string, array<string, mixed>>} $plugin */
        $plugin = $this->decode($response->body)['plugin'];
        $field = $plugin['settings_schema']['api_key'];
        $this->assertSame('AniDB UDP API Key', $field['label']);
        $this->assertSame('Set one in your AniDB profile.', $field['description']);
        $this->assertSame('https://anidb.net/software/api', $field['link']);
        $this->assertSame('AniDB API docs', $field['link_text']);
        // Non-overlay keys still come from the manifest.
        $this->assertTrue($field['required']);
        $this->assertTrue($field['secret']);
    }

    public function test_update_settings_returns_404_when_not_found(): void
    {
        $this->expectCall($this->loader, 'getInstalled')->once()->with('missing')->andThrow(
            new PluginNotFoundException('No installed plugin named "missing".'),
        );
        $this->loader->shouldNotReceive('updateSettings');

        $response = $this->controller->updateSettings(
            $this->makeRequest('admin-1', ['settings' => ['x' => 1]]),
            ['name' => 'missing'],
        );

        $this->assertSame(404, $response->statusCode);
    }

    public function test_update_settings_rejects_missing_settings_object(): void
    {
        $this->expectCall($this->loader, 'getInstalled')->once()->andReturn(
            $this->fixturePlugin('phlix-plugin-demo', enabled: false),
        );
        $this->loader->shouldNotReceive('updateSettings');

        $response = $this->controller->updateSettings(
            $this->makeRequest('admin-1', []),
            ['name' => 'phlix-plugin-demo'],
        );

        $this->assertSame(400, $response->statusCode);
        $this->assertSame('plugin.settings.invalid', $this->decode($response->body)['code']);
    }

    public function test_update_settings_rejects_unknown_key(): void
    {
        $this->expectCall($this->loader, 'getInstalled')->once()->andReturn(
            $this->fixturePlugin(
                'phlix-plugin-demo',
                enabled: false,
                manifestSettings: ['username' => ['type' => 'string']],
            ),
        );
        $this->loader->shouldNotReceive('updateSettings');

        $response = $this->controller->updateSettings(
            $this->makeRequest('admin-1', ['settings' => ['username' => 'joe', 'bogus' => 'x']]),
            ['name' => 'phlix-plugin-demo'],
        );

        $this->assertSame(400, $response->statusCode);
        /** @var array{code: mixed, errors: array<string, mixed>} $body */
        $body = $this->decode($response->body);
        $this->assertSame('plugin.settings.validation_failed', $body['code']);
        $this->assertArrayHasKey('bogus', $body['errors']);
    }

    public function test_update_settings_rejects_type_mismatch(): void
    {
        $this->expectCall($this->loader, 'getInstalled')->once()->andReturn(
            $this->fixturePlugin(
                'phlix-plugin-demo',
                enabled: false,
                manifestSettings: ['use_dump' => ['type' => 'boolean']],
            ),
        );
        $this->loader->shouldNotReceive('updateSettings');

        $response = $this->controller->updateSettings(
            $this->makeRequest('admin-1', ['settings' => ['use_dump' => 'banana']]),
            ['name' => 'phlix-plugin-demo'],
        );

        $this->assertSame(400, $response->statusCode);
        /** @var array{errors: array<string, mixed>} $body */
        $body = $this->decode($response->body);
        $this->assertArrayHasKey('use_dump', $body['errors']);
    }

    public function test_update_settings_preserves_secret_when_mask_echoed_back(): void
    {
        $existing = [
            'username' => 'joe',
            'api_key'  => 'realsecret',
            'use_dump' => false,
        ];
        $this->expectCall($this->loader, 'getInstalled')->twice()->andReturn(
            $this->fixturePlugin(
                'phlix-plugin-anidb',
                enabled: true,
                settings: $existing,
                manifestSettings: [
                    'username' => ['type' => 'string'],
                    'api_key'  => ['type' => 'string', 'secret' => true],
                    'use_dump' => ['type' => 'boolean'],
                ],
            ),
        );

        // The UI echoes the masked api_key back unchanged, changes username + use_dump.
        $this->expectCall($this->loader, 'updateSettings')
            ->once()
            ->with('phlix-plugin-anidb', Mockery::on(static function ($settings): bool {
                return is_array($settings)
                    && $settings['api_key'] === 'realsecret'   // secret kept
                    && $settings['username'] === 'jane'        // updated
                    && $settings['use_dump'] === true;         // coerced + updated
            }));

        $this->expectCall($this->audit, 'logPluginAction')
            ->once()
            ->with('admin-1', 'configure', 'phlix-plugin-anidb', Mockery::on(
                static fn ($ctx) => is_array($ctx) && ($ctx['source'] ?? null) === 'ui'
            ));

        $response = $this->controller->updateSettings(
            $this->makeRequest('admin-1', ['settings' => [
                'username' => 'jane',
                'api_key'  => '***',
                'use_dump' => 'true',
            ]]),
            ['name' => 'phlix-plugin-anidb'],
        );

        $this->assertSame(200, $response->statusCode);
        // refreshed detail returned (still the fixture, secret masked)
        /** @var array{plugin: array{settings: array<string, mixed>}} $body */
        $body = $this->decode($response->body);
        $this->assertSame('***', $body['plugin']['settings']['api_key']);
    }

    public function test_update_settings_updates_secret_when_real_value_provided(): void
    {
        $this->expectCall($this->loader, 'getInstalled')->twice()->andReturn(
            $this->fixturePlugin(
                'phlix-plugin-anidb',
                enabled: true,
                settings: ['api_key' => 'oldsecret'],
                manifestSettings: ['api_key' => ['type' => 'string', 'secret' => true]],
            ),
        );

        $this->expectCall($this->loader, 'updateSettings')
            ->once()
            ->with('phlix-plugin-anidb', Mockery::on(static function ($settings): bool {
                return is_array($settings) && $settings['api_key'] === 'newsecret';
            }));
        $this->expectCall($this->audit, 'logPluginAction')->once();

        $response = $this->controller->updateSettings(
            $this->makeRequest('admin-1', ['settings' => ['api_key' => 'newsecret']]),
            ['name' => 'phlix-plugin-anidb'],
        );

        $this->assertSame(200, $response->statusCode);
    }

    public function test_update_settings_merges_over_existing_keys(): void
    {
        $this->expectCall($this->loader, 'getInstalled')->twice()->andReturn(
            $this->fixturePlugin(
                'phlix-plugin-demo',
                enabled: false,
                settings: ['a' => 'keep', 'b' => 'old'],
                manifestSettings: [
                    'a' => ['type' => 'string'],
                    'b' => ['type' => 'string'],
                ],
            ),
        );

        $this->expectCall($this->loader, 'updateSettings')
            ->once()
            ->with('phlix-plugin-demo', Mockery::on(static function ($settings): bool {
                // 'a' not submitted → preserved; 'b' updated.
                return is_array($settings) && $settings['a'] === 'keep' && $settings['b'] === 'new';
            }));
        $this->expectCall($this->audit, 'logPluginAction')->once();

        $response = $this->controller->updateSettings(
            $this->makeRequest('admin-1', ['settings' => ['b' => 'new']]),
            ['name' => 'phlix-plugin-demo'],
        );

        $this->assertSame(200, $response->statusCode);
    }

    public function test_update_settings_handles_typeless_descriptor_without_500(): void
    {
        // A malformed manifest descriptor missing 'type' must be treated as
        // 'mixed' (accept the value) rather than triggering a strict-types 500.
        $this->expectCall($this->loader, 'getInstalled')->twice()->andReturn(
            $this->fixturePlugin(
                'phlix-plugin-demo',
                enabled: false,
                settings: [],
                manifestSettings: [
                    'note' => ['label' => 'Note'], // no 'type' key
                ],
            ),
        );

        $this->expectCall($this->loader, 'updateSettings')
            ->once()
            ->with('phlix-plugin-demo', Mockery::on(static function ($settings): bool {
                return is_array($settings) && ($settings['note'] ?? null) === 'hello';
            }));
        $this->expectCall($this->audit, 'logPluginAction')->once();

        $response = $this->controller->updateSettings(
            $this->makeRequest('admin-1', ['settings' => ['note' => 'hello']]),
            ['name' => 'phlix-plugin-demo'],
        );

        $this->assertSame(200, $response->statusCode);
    }

    /**
     * @param array<string, array{type?: string, required?: bool, secret?: bool, default?: mixed, label?: string}> $manifestSettings
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
                'phlix_min_server_version' => '0.10.0',
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

    /**
     * Set up an expectation, typed as {@see \Mockery\Expectation} so its fluent
     * methods (once/twice/times/with/andReturn/andThrow/...) type-check.
     *
     * Mockery's {@see MockInterface::shouldReceive()} PHPDoc collapses to an
     * interface union that hides those methods; at runtime the returned
     * `CompositeExpectation` forwards the same calls, so the narrowed type is a
     * faithful description of the available fluent surface.
     *
     * @return \Mockery\Expectation
     */
    private function expectCall(MockInterface $mock, string $method)
    {
        /** @var \Mockery\Expectation $expectation */
        $expectation = $mock->shouldReceive($method);

        return $expectation;
    }
}
