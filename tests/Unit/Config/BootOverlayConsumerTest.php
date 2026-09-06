<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Config;

use DI\ContainerBuilder;
use Phlix\Common\Container\Providers\MediaServicesProvider;
use Phlix\Common\Container\Providers\TranscodeServicesProvider;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Config\EffectiveConfig;
use Phlix\Config\HwAccelConfig;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\HwaccelRegistry;
use Phlix\Media\Transcoding\TranscodeManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Workerman\MySQL\Connection;

/**
 * End-to-end proof that a persisted `server_settings` override actually
 * changes what a DI-RESOLVED CONSUMER observes after the boot path runs.
 *
 * These are the tests that make the schema's `"restart": true` flag honest.
 * Each one:
 *
 *   1. asserts the shipped config-file default (so the assertion cannot pass
 *      by coincidence),
 *   2. persists an override and runs the real boot sequence — the same
 *      `EffectiveConfig::bootstrapAndOverlay($config)` → `provider->register()`
 *      → `$container->get()` order `start.php`'s `onWorkerStart` and
 *      `public/index.php` both use,
 *   3. asserts the resolved service holds the OVERRIDDEN value.
 *
 * Disabling the overlay (deleting the `bootstrapAndOverlay()` call in
 * {@see self::boot()}) turns every one of them red.
 */
final class BootOverlayConsumerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EffectiveConfig::reset();
        HwAccelConfig::reset();
        HwaccelRegistry::reset();
    }

    protected function tearDown(): void
    {
        // S439: the container graph this test resolves constructs MediaAssetJobStore
        // and SimilarityJobStore through MediaServicesProvider's factories at the
        // production default queue paths, and their constructors mint the shared
        // /tmp directories. Sweep them so the suite leaves zero residue.
        foreach (['phlix_media_asset_jobs', 'phlix_similarity_jobs'] as $sharedQueue) {
            $sharedDir = sys_get_temp_dir() . '/' . $sharedQueue;
            if (is_dir($sharedDir)) {
                foreach (glob($sharedDir . '/*') ?: [] as $queued) {
                    @unlink($queued);
                }
                @rmdir($sharedDir);
            }
        }
        EffectiveConfig::reset();
        HwAccelConfig::reset();
        HwaccelRegistry::reset();
        parent::tearDown();
    }

    /**
     * Stand-in `server_settings` table answering both of
     * {@see \Phlix\Admin\SettingsRepository}'s queries.
     *
     * @param array<string, array{0: string, 1: string}> $rows key → [stored text, value_type]
     */
    private function settingsDb(array $rows): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /**
             * @param mixed $query
             * @param mixed $params
             *
             * @return list<array<string, string>>
             */
            static function ($query = '', $params = null) use ($rows): array {
                $sql = is_string($query) ? $query : '';

                if (str_contains($sql, 'SELECT setting_key,')) {
                    $out = [];
                    foreach ($rows as $key => [$value, $type]) {
                        $out[] = [
                            'setting_key'   => $key,
                            'setting_value' => $value,
                            'value_type'    => $type,
                        ];
                    }
                    return $out;
                }

                if (str_contains($sql, 'SELECT setting_value,')) {
                    $key = is_array($params) && isset($params[0]) && is_string($params[0]) ? $params[0] : '';
                    if (isset($rows[$key])) {
                        return [['setting_value' => $rows[$key][0], 'value_type' => $rows[$key][1]]];
                    }
                }

                return [];
            }
        );

        return $db;
    }

    /**
     * Run the real boot sequence with the given persisted overrides and return
     * the resulting container.
     *
     * @param array<string, array{0: string, 1: string}> $overrides
     */
    private function boot(array $overrides): \Psr\Container\ContainerInterface
    {
        $db = $this->settingsDb($overrides);

        /** @var array<string, mixed> $config */
        $config = include \dirname(__DIR__, 3) . '/config/server.php';

        // ---- THE MECHANISM UNDER TEST -----------------------------------
        // Exactly what start.php's onWorkerStart and public/index.php do,
        // immediately before ContainerFactory::create($config).
        EffectiveConfig::bootstrap($db);
        $config = EffectiveConfig::overlayAppConfig($config);
        // -----------------------------------------------------------------

        $this->seedHwaccelRegistry();

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new TranscodeServicesProvider())->register($builder, $config);
        (new MediaServicesProvider())->register($builder, $config);
        $builder->addDefinitions([
            // CoreServicesProvider publishes the (already overlaid) boot config
            // under this key in production; several MediaServicesProvider
            // factories read it.
            'app.config' => $config,
            // A real StructuredLogger (writing nowhere): MediaScanner type-hints
            // the concrete class, while the transcode factories only need a PSR
            // LoggerInterface — StructuredLogger satisfies both.
            'logger.media' => new StructuredLogger('media', ['handlers' => []]),
            'logger.metadata' => new NullLogger(),
            Connection::class => $db,
        ]);

        return $builder->build();
    }

    /**
     * Seed the singleton hwaccel registry so the provider's boot probe is
     * deterministic and never touches real hardware.
     */
    private function seedHwaccelRegistry(): void
    {
        HwaccelRegistry::reset();
        $registry = HwaccelRegistry::getInstance();

        $capability = new HwaccelCapability(
            vendor: 'nvenc',
            encoder: 'h264_nvenc',
            decoder: 'h264_cuvid',
            supports_hdr_tone_mapping: false,
            supported_codecs: ['h264'],
            supported_profiles: ['high'],
            max_resolution_w: 3840,
            max_resolution_h: 2160,
            max_bitrate: 100000000,
        );

        $ref = new \ReflectionObject($registry);
        $capProp = $ref->getProperty('capabilities');
        $capProp->setAccessible(true);
        $capProp->setValue($registry, ['nvenc' => $capability]);
        $initProp = $ref->getProperty('initialized');
        $initProp->setAccessible(true);
        $initProp->setValue($registry, true);
    }

    private function privateInt(object $target, string $property): int
    {
        $ref = new \ReflectionObject($target);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        /** @var mixed $value */
        $value = $prop->getValue($target);
        $this->assertIsInt($value);

        return $value;
    }

    // -----------------------------------------------------------------------
    // Shape 1 — flat `hwaccel.*` → FfmpegRunner's merged config
    // -----------------------------------------------------------------------

    public function test_hwaccel_enabled_override_reaches_the_resolved_ffmpeg_runner(): void
    {
        /** @var FfmpegRunner $baseline */
        $baseline = $this->boot([])->get(FfmpegRunner::class);
        $this->assertTrue($baseline->getConfig()['enabled'], 'shipped default');

        // FfmpegRunner's factory memoises in a per-worker static, so a second
        // boot in this process would hand back the first runner. Assert the
        // merged config the factory feeds it instead — the exact value
        // setConfig() receives.
        $this->boot(['hwaccel.enabled' => ['0', 'bool']]);

        $this->assertFalse(
            HwAccelConfig::get()['enabled'],
            'the config TranscodeServicesProvider passes to FfmpegRunner::setConfig()'
        );
    }

    public function test_preferred_accelerator_override_reaches_the_merged_runner_config(): void
    {
        $this->boot([]);
        $this->assertSame('', HwAccelConfig::get()['preferred_accelerator'], 'shipped default (auto-detect)');

        $this->boot(['transcoding.preferred_accelerator' => ['qsv', 'string']]);
        $this->assertSame('qsv', HwAccelConfig::get()['preferred_accelerator']);
    }

    // -----------------------------------------------------------------------
    // Shape 2 — nested `server.hls.*` → TranscodeManager
    // -----------------------------------------------------------------------

    public function test_nested_hls_overrides_reach_the_resolved_transcode_manager(): void
    {
        /** @var TranscodeManager $baseline */
        $baseline = $this->boot([])->get(TranscodeManager::class);
        $this->assertSame(6, $this->privateInt($baseline, 'segmentSeconds'), 'shipped default');

        /** @var TranscodeManager $manager */
        $manager = $this->boot([
            'server.hls.segment_seconds'         => ['10', 'int'],
            'server.hls.max_concurrent_segments' => ['3', 'int'],
            'server.hls.cache_max_age'           => ['60', 'int'],
            'server.hls.cache_max_bytes'         => ['4096', 'int'],
        ])->get(TranscodeManager::class);

        $this->assertSame(10, $this->privateInt($manager, 'segmentSeconds'));
        $this->assertSame(3, $this->privateInt($manager, 'maxConcurrentSegments'));
        $this->assertSame(60, $this->privateInt($manager, 'cacheMaxAgeSeconds'));
        $this->assertSame(4096, $this->privateInt($manager, 'cacheMaxBytes'));
    }

    // -----------------------------------------------------------------------
    // Shape 3 — hyphenated `process.<worker>.enabled` → start.php's gate
    // -----------------------------------------------------------------------

    public function test_process_worker_toggle_override_reaches_the_managed_worker_gate(): void
    {
        $this->boot([]);
        $shipped = EffectiveConfig::file('process');
        $this->assertIsArray($shipped['media-asset']);
        $this->assertTrue($shipped['media-asset']['enabled'], 'shipped default');

        $this->boot(['process.media-asset.enabled' => ['0', 'bool']]);

        // The literal expression start.php's managed-worker onWorkerStart
        // evaluates before arming the poll timer.
        $effective = EffectiveConfig::file('process');
        $this->assertIsArray($effective['media-asset']);
        $this->assertFalse($effective['media-asset']['enabled']);
    }

    // -----------------------------------------------------------------------
    // Shape 4 — `ffmpeg.*` → TranscodeManager / MediaScanner / FfmpegRunner
    // -----------------------------------------------------------------------

    public function test_max_concurrent_transcodes_override_reaches_the_resolved_transcode_manager(): void
    {
        /** @var TranscodeManager $baseline */
        $baseline = $this->boot([])->get(TranscodeManager::class);
        $this->assertSame(4, $this->privateInt($baseline, 'maxConcurrentTranscodes'), 'shipped default');

        /** @var TranscodeManager $manager */
        $manager = $this->boot(['ffmpeg.max_concurrent_transcodes' => ['9', 'int']])
            ->get(TranscodeManager::class);

        $this->assertSame(9, $this->privateInt($manager, 'maxConcurrentTranscodes'));
    }

    public function test_max_concurrent_scan_probes_override_reaches_the_resolved_media_scanner(): void
    {
        /** @var MediaScanner $baseline */
        $baseline = $this->boot([])->get(MediaScanner::class);
        $this->assertSame(4, $this->privateInt($baseline, 'maxConcurrentScanProbes'), 'shipped default');

        /** @var MediaScanner $scanner */
        $scanner = $this->boot(['ffmpeg.max_concurrent_scan_probes' => ['7', 'int']])
            ->get(MediaScanner::class);

        $this->assertSame(7, $this->privateInt($scanner, 'maxConcurrentScanProbes'));
    }

    public function test_transcode_timeout_override_reaches_the_ffmpeg_runners_timeout_wrapper(): void
    {
        $this->boot([]);
        $this->assertSame(7200, $this->resolveTranscodeTimeout(), 'shipped default');

        $this->boot(['ffmpeg.transcode_timeout' => ['111', 'int']]);
        $this->assertSame(
            111,
            $this->resolveTranscodeTimeout(),
            'the value FfmpegRunner wraps every detached encode in `timeout N ...` with'
        );
    }

    /**
     * Invoke {@see FfmpegRunner::getTranscodeTimeout()} — the private method
     * whose result becomes the `timeout N` wrapper on every detached encode.
     */
    private function resolveTranscodeTimeout(): int
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp/phlix_transcodes');
        $ref = new \ReflectionObject($runner);
        $method = $ref->getMethod('getTranscodeTimeout');
        $method->setAccessible(true);
        /** @var mixed $value */
        $value = $method->invoke($runner);
        $this->assertIsInt($value);

        return $value;
    }
}
