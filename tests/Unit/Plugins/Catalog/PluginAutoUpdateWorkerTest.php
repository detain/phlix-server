<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Catalog;

use DateTimeImmutable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Plugins\Catalog\PluginAutoUpdateWorker;
use Phlix\Plugins\Catalog\PluginCatalogService;
use Phlix\Plugins\Catalog\PluginUpdateService;
use Phlix\Plugins\InstalledPlugin;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\PluginLoader;
use Phlix\Tests\Unit\Plugins\MockeryExpectationTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Plugins\Catalog\PluginAutoUpdateWorker
 */
final class PluginAutoUpdateWorkerTest extends TestCase
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

    /**
     * @param array<string,string> $catalogBodies
     */
    private function catalog(bool $autoUpdate, array $catalogBodies): PluginCatalogService
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturnCallback(
            fn (string $key): mixed => match ($key) {
                PluginCatalogService::KEY_AUTO_UPDATE => $autoUpdate,
                PluginCatalogService::KEY_DEFAULT_SOURCE => self::DEFAULT_SOURCE,
                default => null,
            },
        );
        return new PluginCatalogService(
            $settings,
            static function (string $url, int $t) use ($catalogBodies): string {
                if (!array_key_exists($url, $catalogBodies)) {
                    throw new \RuntimeException("HTTP 404: $url");
                }
                return $catalogBodies[$url];
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

    public function test_does_nothing_when_auto_update_is_off(): void
    {
        $catalog = $this->catalog(false, []);
        /** @var PluginLoader&MockInterface $loader */
        $loader = Mockery::mock(PluginLoader::class);
        // updateAll() is never reached, so the installed list is never read.
        $loader->shouldNotReceive('listInstalled');
        $loader->shouldNotReceive('install');

        $updates = new PluginUpdateService(
            $loader,
            $catalog,
            static fn (string $u, int $t): string => throw new \RuntimeException('should not fetch'),
        );
        $worker = new PluginAutoUpdateWorker($catalog, $updates, $this->createMock(StructuredLogger::class));

        $this->assertFalse($worker->runOnce());
    }

    public function test_applies_updates_when_enabled(): void
    {
        $catalogBody = json_encode([
            'name' => 'Official',
            'plugins' => [['name' => 'phlix-plugin-anidb', 'repo' => self::ANIDB_REPO]],
        ], JSON_THROW_ON_ERROR);

        $catalog = $this->catalog(true, [self::CATALOG_RAW => $catalogBody]);

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
        // SV-B2: un-pinned catalog entry → update threads a null pin.
        $this->expect($loader, 'install')->once()->with(self::ANIDB_REPO, null, null)->andReturn($manifest);

        $updates = new PluginUpdateService(
            $loader,
            $catalog,
            static fn (string $u, int $t): string => $u === self::ANIDB_MANIFEST_RAW
                ? json_encode(['version' => '0.2.0'], JSON_THROW_ON_ERROR)
                : throw new \RuntimeException("unexpected: $u"),
        );
        $worker = new PluginAutoUpdateWorker($catalog, $updates, $this->createMock(StructuredLogger::class));

        $this->assertTrue($worker->runOnce());
    }

    public function test_returns_false_when_enabled_but_all_up_to_date(): void
    {
        $catalogBody = json_encode([
            'name' => 'Official',
            'plugins' => [['name' => 'phlix-plugin-anidb', 'repo' => self::ANIDB_REPO]],
        ], JSON_THROW_ON_ERROR);

        $catalog = $this->catalog(true, [self::CATALOG_RAW => $catalogBody]);

        /** @var PluginLoader&MockInterface $loader */
        $loader = Mockery::mock(PluginLoader::class);
        $this->expect($loader, 'listInstalled')->andReturn([$this->installed('phlix-plugin-anidb', '0.2.0')]);
        $loader->shouldNotReceive('install');

        $updates = new PluginUpdateService(
            $loader,
            $catalog,
            static fn (string $u, int $t): string => json_encode(['version' => '0.2.0'], JSON_THROW_ON_ERROR),
        );
        $worker = new PluginAutoUpdateWorker($catalog, $updates, $this->createMock(StructuredLogger::class));

        $this->assertFalse($worker->runOnce());
    }
}
