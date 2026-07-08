<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use DateTimeImmutable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\Expectation;
use Mockery\MockInterface;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Plugins\Catalog\PluginCatalogService;
use Phlix\Plugins\Catalog\PluginUpdateService;
use Phlix\Plugins\InstalledPlugin;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\PluginLoader;
use Phlix\Server\Http\Controllers\PluginCatalogController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see PluginCatalogController}.
 *
 * Uses a real {@see PluginCatalogService} (it is `final`) wired to an
 * in-memory settings double + an injected offline fetcher, so the controller
 * is exercised through the true aggregate path. {@see PluginLoader} and
 * {@see AuditLogger} are `final` → mocked with Mockery.
 *
 * @covers \Phlix\Server\Http\Controllers\PluginCatalogController
 */
final class PluginCatalogControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const DEFAULT_SOURCE = 'https://github.com/detain/phlix-plugins';
    // SV-S2b: the official catalog resolves to a PINNED ref, not HEAD.
    private const DEFAULT_RAW = 'https://raw.githubusercontent.com/detain/phlix-plugins/'
        . \Phlix\Plugins\Catalog\CatalogSourceResolver::OFFICIAL_PINNED_REF . '/plugins.json';

    /** @var PluginLoader&MockInterface */
    private PluginLoader&MockInterface $loader;
    /** @var AuditLogger&MockInterface */
    private AuditLogger&MockInterface $audit;
    /** @var array<string, mixed> */
    private array $store;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var PluginLoader&MockInterface $loader */
        $loader = Mockery::mock(PluginLoader::class);
        $this->loader = $loader;
        /** @var AuditLogger&MockInterface $audit */
        $audit = Mockery::mock(AuditLogger::class)->shouldIgnoreMissing();
        $this->audit  = $audit;
        $this->store  = [PluginCatalogService::KEY_DEFAULT_SOURCE => self::DEFAULT_SOURCE];
        // Catalog fetch/add now SSRF-guards URLs. Inject a deterministic public
        // resolver so the suite never performs a real DNS lookup.
        SsrfGuard::setResolver(static fn (string $host): array => ['93.184.216.34']);
    }

    protected function tearDown(): void
    {
        SsrfGuard::reset();
        parent::tearDown();
    }

    public function test_index_annotates_install_state(): void
    {
        $body = json_encode([
            'name' => 'Phlix Official Plugins',
            'plugins' => [
                ['name' => 'phlix-plugin-anidb', 'title' => 'AniDB', 'repo' => 'https://github.com/detain/phlix-plugin-anidb'],
                ['name' => 'phlix-plugin-myanimelist', 'title' => 'MyAnimeList', 'repo' => 'https://github.com/detain/phlix-plugin-myanimelist'],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expect($this->loader, 'listInstalled')
            ->once()
            ->andReturn([$this->fixturePlugin('phlix-plugin-anidb', enabled: true)]);

        $controller = $this->controller([self::DEFAULT_RAW => $body]);
        $response = $controller->index($this->makeRequest('admin-1'), []);

        $this->assertSame(200, $response->statusCode);
        $payload = $this->decode($response->body);

        $this->assertSame(self::DEFAULT_SOURCE, $payload['default_source']);
        $this->assertSame([self::DEFAULT_SOURCE], $payload['sources']);
        /** @var list<array<string, mixed>> $catalogs */
        $catalogs = $payload['catalogs'];
        $this->assertCount(1, $catalogs);

        /** @var list<array<string, mixed>> $plugins */
        $plugins = $catalogs[0]['plugins'];
        $this->assertSame('phlix-plugin-anidb', $plugins[0]['name']);
        $this->assertTrue($plugins[0]['installed']);
        $this->assertTrue($plugins[0]['enabled']);
        $this->assertSame('phlix-plugin-myanimelist', $plugins[1]['name']);
        $this->assertFalse($plugins[1]['installed']);
        $this->assertFalse($plugins[1]['enabled']);
    }

    public function test_index_reports_unreachable_source_as_error(): void
    {
        $this->store[PluginCatalogService::KEY_SOURCES] = ['https://example.com/down.json'];
        $this->expect($this->loader, 'listInstalled')->once()->andReturn([]);

        // No body for either source → both error out, page still 200s.
        $controller = $this->controller([]);
        $response = $controller->index($this->makeRequest('admin-1'), []);

        $this->assertSame(200, $response->statusCode);
        $payload = $this->decode($response->body);
        $this->assertSame([], $payload['catalogs']);
        /** @var list<mixed> $errors */
        $errors = $payload['errors'];
        $this->assertCount(2, $errors);
    }

    public function test_add_source_persists_and_audits(): void
    {
        $this->expect($this->audit, 'logPluginAction')
            ->once()
            ->with('admin-1', 'catalog.add_source', 'https://example.com/extra.json', Mockery::type('array'));

        $controller = $this->controller([]);
        $response = $controller->addSource(
            $this->makeRequest('admin-1', ['url' => 'https://example.com/extra.json']),
            [],
        );

        $this->assertSame(200, $response->statusCode);
        $payload = $this->decode($response->body);
        /** @var list<mixed> $sources */
        $sources = $payload['sources'];
        $this->assertContains('https://example.com/extra.json', $sources);
        $this->assertSame(['https://example.com/extra.json'], $this->store[PluginCatalogService::KEY_SOURCES]);
    }

    public function test_add_source_rejects_missing_url(): void
    {
        $controller = $this->controller([]);
        $response = $controller->addSource($this->makeRequest('admin-1', []), []);

        $this->assertSame(400, $response->statusCode);
        $this->assertSame('plugin.catalog.url.required', $this->decode($response->body)['code']);
    }

    public function test_add_source_rejects_invalid_scheme(): void
    {
        $controller = $this->controller([]);
        $response = $controller->addSource(
            $this->makeRequest('admin-1', ['url' => 'file:///etc/passwd']),
            [],
        );

        $this->assertSame(400, $response->statusCode);
        $this->assertSame('plugin.catalog.url.invalid', $this->decode($response->body)['code']);
    }

    public function test_remove_source_drops_extra(): void
    {
        $this->store[PluginCatalogService::KEY_SOURCES] = ['https://example.com/extra.json'];
        $this->expect($this->audit, 'logPluginAction')->once();

        $controller = $this->controller([]);
        $response = $controller->removeSource(
            $this->makeRequest('admin-1', ['url' => 'https://example.com/extra.json']),
            [],
        );

        $this->assertSame(200, $response->statusCode);
        $payload = $this->decode($response->body);
        $this->assertSame([self::DEFAULT_SOURCE], $payload['sources']);
        $this->assertSame([], $this->store[PluginCatalogService::KEY_SOURCES]);
    }

    public function test_remove_source_reads_url_from_query_param(): void
    {
        // The browser's DELETE has no body — the URL arrives on the query string.
        $this->store[PluginCatalogService::KEY_SOURCES] = ['https://example.com/extra.json'];
        $this->expect($this->audit, 'logPluginAction')->once();

        $request = $this->makeRequest('admin-1', []);
        $request->query = ['url' => 'https://example.com/extra.json'];

        $controller = $this->controller([]);
        $response = $controller->removeSource($request, []);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame([self::DEFAULT_SOURCE], $this->decode($response->body)['sources']);
        $this->assertSame([], $this->store[PluginCatalogService::KEY_SOURCES]);
    }

    /**
     * @param array<string, string> $catalogBodies
     */
    private function controller(array $catalogBodies): PluginCatalogController
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturnCallback(
            fn (string $key): mixed => $this->store[$key] ?? null,
        );
        $settings->method('set')->willReturnCallback(
            function (string $key, mixed $value, string $type): void {
                $this->store[$key] = $value;
            },
        );

        $service = new PluginCatalogService(
            $settings,
            static function (string $url, int $timeout) use ($catalogBodies): string {
                if (!array_key_exists($url, $catalogBodies)) {
                    throw new \RuntimeException('HTTP 404');
                }
                return $catalogBodies[$url];
            },
        );

        $updates = new PluginUpdateService(
            $this->loader,
            $service,
            static fn (string $url, int $timeout): string => throw new \RuntimeException('update fetch disabled'),
        );

        return new PluginCatalogController($service, $this->loader, $this->audit, $updates);
    }

    private function fixturePlugin(string $name, bool $enabled): InstalledPlugin
    {
        return new InstalledPlugin(
            id: 'id-' . $name,
            manifest: Manifest::fromArray([
                'name' => $name,
                'version' => '1.0.0',
                'phlix_min_server_version' => '0.10.0',
                'type' => 'metadata-provider',
                'entry' => 'Demo\\Plugin',
            ]),
            enabled: $enabled,
            installedAt: new DateTimeImmutable('2024-01-01 00:00:00'),
            settings: [],
            directory: '/tmp/' . $name,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function makeRequest(?string $userId, array $body = []): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/admin/plugins/catalog';
        $request->headers = [];
        $request->query = [];
        $request->body = $body;
        $request->files = [];
        $request->remoteIp = '127.0.0.1';
        $request->remotePort = 0;
        $request->protocol = 'HTTP/1.1';
        $request->queryString = '';
        $request->userId = $userId;
        return $request;
    }

    /**
     * Narrow Mockery's loose {@see \Mockery\LegacyMockInterface::shouldReceive()}
     * union return (Expectation|ExpectationInterface|HigherOrderMessage) down to
     * the {@see Expectation} API a single-method expectation exposes, so fluent
     * ->once()/->with() calls type-check under PHPStan. The runtime value is a
     * Mockery\CompositeExpectation that proxies those calls via __call(), hence
     * no native return type (which would reject the concrete runtime class).
     *
     * @return Expectation
     */
    private function expect(MockInterface $mock, string $method)
    {
        /** @var Expectation $expectation */
        $expectation = $mock->shouldReceive($method);

        return $expectation;
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
