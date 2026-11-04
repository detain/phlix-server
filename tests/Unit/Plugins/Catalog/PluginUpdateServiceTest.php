<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Catalog;

use DateTimeImmutable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Plugins\Catalog\PluginCatalogService;
use Phlix\Plugins\Catalog\PluginUpdateService;
use Phlix\Plugins\InstalledPlugin;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\PluginLoader;
use Phlix\Tests\Unit\Plugins\MockeryExpectationTrait;
use PHPUnit\Framework\TestCase;

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
        foreach ($this->capturedLoggerDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        $this->capturedLoggerDirs = [];
        LoggerFactory::reset();
        parent::tearDown();
    }

    private const DEFAULT_SOURCE = 'https://github.com/detain/phlix-plugins';
    // SV-S2b: the official catalog now resolves to a PINNED ref, not HEAD.
    private const CATALOG_RAW = 'https://raw.githubusercontent.com/detain/phlix-plugins/'
        . \Phlix\Plugins\Catalog\CatalogSourceResolver::OFFICIAL_PINNED_REF . '/plugins.json';
    private const ANIDB_REPO = 'https://github.com/detain/phlix-plugin-anidb';
    private const ANIDB_MANIFEST_RAW = 'https://raw.githubusercontent.com/detain/phlix-plugin-anidb/HEAD/plugin.json';

    /** @var list<string> Captured-logger scratch dirs (INFO-SERVER-62 log pins). */
    private array $capturedLoggerDirs = [];

    /**
     * Planted transport prose for the INFO-SERVER-62 pins: shaped like the real
     * thing (cURL failure embedding the upstream URL) and carrying one unique
     * marker string — the whole laundering law is that this prose reaches the
     * PLUGINS log and NEVER a client-visible string.
     */
    private const TRANSPORT_PROSE = 'cURL error 7: Failed to connect to the catalog repository '
        . '[S62-LAUNDER-MARKER-9f3c] at ' . self::ANIDB_MANIFEST_RAW;

    /**
     * The byte-identical client-facing string for a transport failure:
     * checkUpdates' own row prefix + the service's fixed curated copy. Any
     * rewording of either half must be a DELIBERATE contract change that
     * rotates this pin — that is the point (the estate's pin-a-cure law).
     */
    private const CURATED_ROW_ERROR = 'Could not read the latest version: '
        . 'The plugin manifest could not be fetched from its catalog repository.';

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

        $svc = new PluginUpdateService(
            $loader,
            $catalog,
            static function (string $u, int $t) {
                throw new \RuntimeException('no manifest fetch');
            },
        );
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

    // ------------------------------------------------------------------
    // INFO-SERVER-62 teeth (review server__secaudit / srvtail-R1, 2026-11-04):
    // fetchLatestVersion()'s transport-failure rethrow used to carry
    // $e->getMessage() — upstream-URL prose — which checkUpdates() echoed into
    // the row a client reads. The cure answers the byte-identical curated copy
    // and routes the REAL prose to the PLUGINS log only. Red at the parent arm
    // BOTH directions (prose in row / no log record); the pre-existing pins
    // (assertNotNull($row['error'])) could not see either direction — which is
    // why the R1 review filed this as a Major.
    // ------------------------------------------------------------------

    /**
     * Service whose manifest fetcher fails with the planted transport prose
     * while the catalog itself resolves (so the row is `checkable`).
     */
    private function launderingService(PluginLoader $loader): PluginUpdateService
    {
        return new PluginUpdateService(
            $loader,
            $this->catalogService(),
            static function (string $url, int $timeout): string {
                throw new \RuntimeException(self::TRANSPORT_PROSE);
            },
        );
    }

    /**
     * Estate captured-logger seam (the aa57aaca/87616b0f idiom): a temp logger
     * config routed to a stream file so the test asserts exactly what the
     * PLUGINS channel received. Scratch dir tracked for tearDown cleanup.
     */
    private function capturePluginsLog(): string
    {
        LoggerFactory::reset();
        $dir = sys_get_temp_dir() . '/phlix_s62_log_' . uniqid('', true);
        mkdir($dir, 0700, true);
        $this->capturedLoggerDirs[] = $dir;
        $logPath = $dir . '/app.log';
        file_put_contents(
            $dir . '/logger.php',
            "<?php\nreturn [\n"
            . "    'default' => 'file',\n"
            . "    'handlers' => [\n"
            . "        'file' => [\n"
            . "            'type' => 'stream',\n"
            . "            'path' => " . var_export($logPath, true) . ",\n"
            . "            'level' => 'debug',\n"
            . "        ],\n"
            . "    ],\n"
            . "];\n"
        );
        LoggerFactory::init($dir . '/logger.php');

        return $logPath;
    }

    public function test_transport_failure_row_carries_the_curated_copy_and_never_the_exception_prose(): void
    {
        /** @var PluginLoader&MockInterface $loader */
        $loader = Mockery::mock(PluginLoader::class);
        $this->expect($loader, 'listInstalled')->andReturn([$this->installed('phlix-plugin-anidb', '0.1.0')]);

        $result = $this->launderingService($loader)->checkUpdates();
        $row = $result['updates'][0];

        self::assertTrue($row['checkable']);
        self::assertNull($row['latest_version']);
        self::assertFalse($row['update_available']);
        // The whole laundering law in one line: the row carries the byte-
        // identical curated copy — NEVER the transport exception's prose.
        self::assertSame(self::CURATED_ROW_ERROR, $row['error']);
        self::assertStringNotContainsString('S62-LAUNDER-MARKER-9f3c', (string) $row['error']);
    }

    public function test_transport_failure_routes_the_real_prose_to_the_plugins_log_only(): void
    {
        $logPath = $this->capturePluginsLog();

        /** @var PluginLoader&MockInterface $loader */
        $loader = Mockery::mock(PluginLoader::class);
        $this->expect($loader, 'listInstalled')->andReturn([$this->installed('phlix-plugin-anidb', '0.1.0')]);

        $result = $this->launderingService($loader)->checkUpdates();
        $row = $result['updates'][0];

        // Log half of the law: the real cause (message + class + repo) is
        // preserved on the PLUGINS channel — a cure that silently deleted the
        // diagnostic would stay invisible to the row-only pin, so both halves
        // need teeth.
        self::assertFileExists($logPath, 'the transport failure logged nothing at all');
        $log = (string) file_get_contents($logPath);
        self::assertStringContainsString('plugins.ERROR: Plugin manifest fetch failed', $log);
        self::assertStringContainsString('S62-LAUNDER-MARKER-9f3c', $log);
        self::assertStringContainsString('phlix-plugin-anidb', $log);
        // And it stays log-ONLY: the client-facing row never carries it.
        self::assertStringNotContainsString('S62-LAUNDER-MARKER-9f3c', (string) $row['error']);
    }
}
