<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Catalog;

use DateTimeImmutable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Plugins\Catalog\PluginCatalogService;
use Phlix\Plugins\Catalog\PluginUpdateService;
use Phlix\Plugins\InstalledPlugin;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\PluginLoader;
use Phlix\Tests\Unit\Plugins\MockeryExpectationTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Plugins\Catalog\PluginUpdateService
 */
final class PluginUpdateServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use MockeryExpectationTrait;

    protected function setUp(): void
    {
        parent::setUp();
        // Catalog fetch now SSRF-guards the resolved URL. Inject a deterministic
        // public resolver so the suite never performs a real DNS lookup.
        SsrfGuard::setResolver(static fn (string $host): array => ['93.184.216.34']);
    }

    protected function tearDown(): void
    {
        SsrfGuard::reset();
        parent::tearDown();
    }

    private const DEFAULT_SOURCE = 'https://github.com/detain/phlix-plugins';
    // SV-S2b: the official catalog now resolves to a PINNED ref, not HEAD.
    private const CATALOG_RAW = 'https://raw.githubusercontent.com/detain/phlix-plugins/'
        . \Phlix\Plugins\Catalog\CatalogSourceResolver::OFFICIAL_PINNED_REF . '/plugins.json';
    private const ANIDB_REPO = 'https://github.com/detain/phlix-plugin-anidb';
    private const ANIDB_MANIFEST_RAW = 'https://raw.githubusercontent.com/detain/phlix-plugin-anidb/HEAD/plugin.json';

    /** A catalog listing one plugin (anidb). */
    private function catalogBody(): string
    {
        return json_encode([
            'name' => 'Official',
            'plugins' => [
                ['name' => 'phlix-plugin-anidb', 'repo' => self::ANIDB_REPO],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function catalogService(): PluginCatalogService
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturnCallback(
            fn (string $key): mixed => $key === PluginCatalogService::KEY_DEFAULT_SOURCE ? self::DEFAULT_SOURCE : null,
        );
        return new PluginCatalogService(
            $settings,
            fn (string $url, int $t): string => $url === self::CATALOG_RAW
                ? $this->catalogBody()
                : throw new \RuntimeException("unexpected catalog fetch: $url"),
        );
    }

    /**
     * @param array<string,string> $manifests Map of manifest-raw-url → body.
     */
    private function service(PluginLoader $loader, array $manifests): PluginUpdateService
    {
        return new PluginUpdateService(
            $loader,
            $this->catalogService(),
            function (string $url, int $t) use ($manifests): string {
                if (!array_key_exists($url, $manifests)) {
                    throw new \RuntimeException("HTTP 404: $url");
                }
                return $manifests[$url];
            },
        );
    }

    private function installed(string $name, string $version): InstalledPlugin
    {
        return new InstalledPlugin(
            id: 'id-' . $name,
            manifest: Manifest::fromArray([
                'name' => $name,
                'version' => $version,
                'phlix_min_server_version' => '0.10.0',
                'type' => 'metadata-provider',
                'entry' => 'Demo\\Plugin',
            ]),
            enabled: true,
            installedAt: new DateTimeImmutable('2024-01-01 00:00:00'),
            settings: [],
            directory: '/tmp/' . $name,
        );
    }

    public function test_reports_an_available_update(): void
    {
        /** @var PluginLoader&MockInterface $loader */
        $loader = Mockery::mock(PluginLoader::class);
        $this->expect($loader, 'listInstalled')->andReturn([$this->installed('phlix-plugin-anidb', '0.1.0')]);

        $svc = $this->service($loader, [
            self::ANIDB_MANIFEST_RAW => json_encode(['version' => '0.2.0'], JSON_THROW_ON_ERROR),
        ]);

        $result = $svc->checkUpdates();
        self::assertSame(1, $result['available']);
        $row = $result['updates'][0];
        self::assertSame('phlix-plugin-anidb', $row['name']);
        self::assertSame('0.1.0', $row['installed_version']);
        self::assertSame('0.2.0', $row['latest_version']);
        self::assertTrue($row['update_available']);
        self::assertTrue($row['checkable']);
        self::assertSame(self::ANIDB_REPO, $row['repo']);
    }

    public function test_reports_up_to_date(): void
    {
        /** @var PluginLoader&MockInterface $loader */
        $loader = Mockery::mock(PluginLoader::class);
        $this->expect($loader, 'listInstalled')->andReturn([$this->installed('phlix-plugin-anidb', '0.2.0')]);

        $svc = $this->service($loader, [
            self::ANIDB_MANIFEST_RAW => json_encode(['version' => '0.2.0'], JSON_THROW_ON_ERROR),
        ]);

        $result = $svc->checkUpdates();
        self::assertSame(0, $result['available']);
        self::assertFalse($result['updates'][0]['update_available']);
    }

    public function test_marks_not_in_catalog_as_uncheckable(): void
    {
        /** @var PluginLoader&MockInterface $loader */
        $loader = Mockery::mock(PluginLoader::class);
        $this->expect($loader, 'listInstalled')->andReturn([$this->installed('phlix-plugin-orphan', '1.0.0')]);

        $result = $this->service($loader, [])->checkUpdates();
        $row = $result['updates'][0];
        self::assertFalse($row['checkable']);
        self::assertFalse($row['update_available']);
        self::assertNotNull($row['error']);
    }

    public function test_manifest_fetch_failure_is_isolated(): void
    {
        /** @var PluginLoader&MockInterface $loader */
        $loader = Mockery::mock(PluginLoader::class);
        $this->expect($loader, 'listInstalled')->andReturn([$this->installed('phlix-plugin-anidb', '0.1.0')]);

        // No manifest body for the repo → the fetch throws, captured per-row.
        $result = $this->service($loader, [])->checkUpdates();
        $row = $result['updates'][0];
        self::assertTrue($row['checkable']);
        self::assertNull($row['latest_version']);
        self::assertFalse($row['update_available']);
        self::assertNotNull($row['error']);
    }

    public function test_update_reinstalls_from_the_catalog_repo(): void
    {
        /** @var PluginLoader&MockInterface $loader */
        $loader = Mockery::mock(PluginLoader::class);
        $this->expect($loader, 'listInstalled')->andReturn([$this->installed('phlix-plugin-anidb', '0.1.0')]);
        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-anidb',
            'version' => '0.2.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'metadata-provider',
            'entry' => 'Demo\\Plugin',
        ]);
        // SV-B2: the un-pinned catalog entry resolves to [null, null], so the
        // reinstall threads a null pin (stays on the SV-S2b default-deny path).
        $this->expect($loader, 'install')->once()->with(self::ANIDB_REPO, null, null)->andReturn($manifest);

        $result = $this->service($loader, [])->update('phlix-plugin-anidb');
        self::assertSame('0.2.0', $result->version);
    }

    public function test_update_threads_the_catalog_pin_for_a_pinned_entry(): void
    {
        // SV-B2: when the catalog entry is pinned (schemaVersion 2), update()
        // must forward [artifactSha256, ref] into PluginLoader::install so the
        // reinstall clears the SV-S2b default-deny.
        $sha = str_repeat('a', 64);
        $ref = str_repeat('b', 40);

        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturnCallback(
            fn (string $key): mixed => $key === PluginCatalogService::KEY_DEFAULT_SOURCE
                ? self::DEFAULT_SOURCE
                : null,
        );
        $catalogBody = json_encode([
            'name' => 'Official',
            'plugins' => [
                [
                    'name' => 'phlix-plugin-anidb',
                    'repo' => self::ANIDB_REPO,
                    'ref' => $ref,
                    'artifactSha256' => $sha,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $catalog = new PluginCatalogService(
            $settings,
            fn (string $url, int $t): string => $url === self::CATALOG_RAW
                ? $catalogBody
                : throw new \RuntimeException("unexpected catalog fetch: $url"),
        );

        /** @var PluginLoader&MockInterface $loader */
        $loader = Mockery::mock(PluginLoader::class);
        $this->expect($loader, 'listInstalled')->andReturn([$this->installed('phlix-plugin-anidb', '0.1.0')]);
        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-anidb',
            'version' => '0.2.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'metadata-provider',
            'entry' => 'Demo\\Plugin',
        ]);
        $this->expect($loader, 'install')->once()->with(self::ANIDB_REPO, $sha, $ref)->andReturn($manifest);

        $svc = new PluginUpdateService($loader, $catalog, fn (string $u, int $t): string => throw new \RuntimeException('no manifest fetch'));
        $result = $svc->update('phlix-plugin-anidb');
        self::assertSame('0.2.0', $result->version);
    }

    public function test_update_throws_when_not_in_catalog(): void
    {
        /** @var PluginLoader&MockInterface $loader */
        $loader = Mockery::mock(PluginLoader::class);
        $this->expect($loader, 'listInstalled')->andReturn([]);
        $loader->shouldNotReceive('install');

        $this->expectException(\RuntimeException::class);
        $this->service($loader, [])->update('phlix-plugin-orphan');
    }

    public function test_update_all_updates_only_outdated_plugins(): void
    {
        /** @var PluginLoader&MockInterface $loader */
        $loader = Mockery::mock(PluginLoader::class);
        $this->expect($loader, 'listInstalled')->andReturn([$this->installed('phlix-plugin-anidb', '0.1.0')]);
        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-anidb',
            'version' => '0.2.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'metadata-provider',
            'entry' => 'Demo\\Plugin',
        ]);
        // SV-B2: the un-pinned catalog entry resolves to [null, null], so the
        // reinstall threads a null pin (stays on the SV-S2b default-deny path).
        $this->expect($loader, 'install')->once()->with(self::ANIDB_REPO, null, null)->andReturn($manifest);

        $svc = $this->service($loader, [
            self::ANIDB_MANIFEST_RAW => json_encode(['version' => '0.2.0'], JSON_THROW_ON_ERROR),
        ]);
        $result = $svc->updateAll();
        self::assertCount(1, $result['updated']);
        self::assertSame('phlix-plugin-anidb', $result['updated'][0]['name']);
        self::assertSame([], $result['failed']);
    }
}
