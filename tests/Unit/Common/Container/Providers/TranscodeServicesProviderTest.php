<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Common\Container\Providers\TranscodeServicesProvider;
use Phlix\Config\HwAccelConfig;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\ProbeCache;
use Phlix\Media\Transcoding\Hwaccel\HwaccelRegistry;
use Phlix\Media\Transcoding\SegmentProcessRegistry;
use Phlix\Media\Transcoding\TranscodeManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Workerman\MySQL\Connection;

/**
 * SV-0.1 provider boot: resolving {@see FfmpegRunner} through
 * {@see TranscodeServicesProvider} calls both `setConfig()` (with the merged
 * single-source hwaccel config) and `probeHardwareAcceleration()` once at boot.
 */
final class TranscodeServicesProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HwaccelRegistry::reset();
        HwAccelConfig::reset();
    }

    protected function tearDown(): void
    {
        HwaccelRegistry::reset();
        HwAccelConfig::reset();
        parent::tearDown();
    }

    /**
     * Seeds the singleton registry so the boot probe is deterministic and never
     * touches real hardware.
     */
    private function seedRegistry(): HwaccelRegistry
    {
        HwaccelRegistry::reset();
        $registry = HwaccelRegistry::getInstance();

        $capability = new HwaccelCapability(
            vendor: 'nvenc',
            encoder: 'h264_nvenc',
            decoder: 'h264_cuvid',
            supports_hdr_tone_mapping: false,
            supported_codecs: ['h264', 'hevc'],
            supported_profiles: ['baseline', 'main', 'high'],
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

        return $registry;
    }

    private function buildRunner(): FfmpegRunner
    {
        $seeded = $this->seedRegistry();

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new TranscodeServicesProvider())->register($builder, [
            'ffmpeg' => [
                'ffmpeg_path' => '/usr/bin/ffmpeg',
                'ffprobe_path' => '/usr/bin/ffprobe',
                'transcode_dir' => '/tmp/phlix_transcodes',
            ],
            'hls' => [
                'segment_dir' => '/tmp/phlix_hls',
                'segment_seconds' => 6,
            ],
        ]);
        $builder->addDefinitions(['logger.media' => new NullLogger()]);

        $container = $builder->build();

        /** @var FfmpegRunner $runner */
        $runner = $container->get(FfmpegRunner::class);

        // The factory resolves the singleton registry; confirm our seed is used.
        $this->assertSame($seeded, $runner->getHwaccelRegistry());

        return $runner;
    }

    public function test_provider_boot_calls_set_config(): void
    {
        $runner = $this->buildRunner();

        $config = $runner->getConfig();

        // setConfig() ran with the merged single-source config.
        $this->assertNotEmpty($config);
        $this->assertArrayHasKey('enabled', $config);
        $this->assertSame(HwAccelConfig::get()['enabled'], $config['enabled']);
        $this->assertSame(HwAccelConfig::get()['prefer_hardware'], $config['prefer_hardware']);
    }

    public function test_provider_boot_probes_hardware_acceleration(): void
    {
        $runner = $this->buildRunner();

        // probeHardwareAcceleration() ran: the registry is set and the summary
        // reflects the seeded encoder.
        $this->assertNotNull($runner->getHwaccelRegistry());

        $summary = $runner->getHardwareAccelerationSummary();
        $this->assertSame('nvenc', $summary['chosen_vendor']);
        $this->assertSame('h264_nvenc', $summary['chosen_encoder']);
    }

    /**
     * Builds the FULL container and resolves the SAME singleton
     * {@see TranscodeManager} + {@see SegmentProcessRegistry} the runtime uses.
     *
     * @return array{0: TranscodeManager, 1: SegmentProcessRegistry}
     */
    private function buildManagerAndRegistry(): array
    {
        $this->seedRegistry();

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new TranscodeServicesProvider())->register($builder, [
            'ffmpeg' => [
                'ffmpeg_path' => '/usr/bin/ffmpeg',
                'ffprobe_path' => '/usr/bin/ffprobe',
                'transcode_dir' => '/tmp/phlix_transcodes',
            ],
            'hls' => [
                'segment_dir' => '/tmp/phlix_hls',
                'segment_seconds' => 6,
            ],
        ]);
        $builder->addDefinitions([
            'logger.media' => new NullLogger(),
            Connection::class => $this->createMock(Connection::class),
        ]);
        $container = $builder->build();

        /** @var TranscodeManager $manager */
        $manager = $container->get(TranscodeManager::class);
        /** @var SegmentProcessRegistry $registry */
        $registry = $container->get(SegmentProcessRegistry::class);

        // Neutralise real signalling/liveness so kill() sends nothing to a real
        // process group — the DUT here is the provider WIRING (guard + reap
        // callback), not the OS signal path (covered by SegmentProcessRegistryTest).
        $this->setRegistryPrivate($registry, 'signalSender', static function (int $pid, int $signal): void {
        });
        $this->setRegistryPrivate($registry, 'isAlive', static fn (int $pid): bool => false);

        return [$manager, $registry];
    }

    private function setRegistryPrivate(SegmentProcessRegistry $registry, string $prop, mixed $value): void
    {
        $p = new \ReflectionProperty(SegmentProcessRegistry::class, $prop);
        $p->setAccessible(true);
        $p->setValue($registry, $value);
    }

    /**
     * SV-4.2-disconnect F7: the provider must bind the REAL
     * TranscodeManager::hasOtherWaiter guard AND the REAL invalidateReservation
     * reap callback onto the registry singleton. A broken/missing setWaiterGuard
     * or setReapCallback binding in the provider would make this go RED (the
     * unit-level registry tests use FAKE closures and cannot catch that).
     */
    public function test_provider_wires_real_waiter_guard_and_reap_callback(): void
    {
        [$manager, $registry] = $this->buildManagerAndRegistry();

        $final = '/tmp/phlix_hls/job/seg-v720p-00003.ts';

        $waiters = new \ReflectionProperty(TranscodeManager::class, 'segmentWaiters');
        $waiters->setAccessible(true);
        $resv = new \ReflectionProperty(TranscodeManager::class, 'segmentEncodesInFlight');
        $resv->setAccessible(true);

        // (1) REAL waiter guard wired: a genuine piggybacker (count 2) defers a real
        //     kill through the provider-bound guard → hasOtherWaiter($final).
        $waiters->setValue($manager, [$final => 2]); // launcher + piggyback
        $registry->register($final, 4242);
        $this->assertSame(0, $registry->kill($final), 'real DI guard: kill defers while a piggybacker waits');
        $this->assertSame([4242], $registry->pidsFor($final), 'the deferred encode stays tracked');

        // (2) REAL reap callback wired: once the piggybacker leaves, a sole-waiter
        //     kill reaps AND invalidates the manager's reservation through the
        //     provider-bound invalidateReservation callback.
        $waiters->setValue($manager, []);
        $resv->setValue($manager, [$final => ['at' => 1, 'gen' => 1]]);
        $this->assertSame(1, $registry->kill($final), 'real DI: sole-waiter kill reaps');
        $this->assertSame([], $resv->getValue($manager), 'real DI reap callback invalidated the reservation');
    }

    /**
     * SV-1.9: resolves the singleton {@see TranscodeManager} through the provider
     * with an arbitrary `hls` config sub-array so the ENOSPC threshold wiring can
     * be asserted directly off the constructed instance.
     *
     * @param array<string, mixed> $hlsConfig
     */
    private function resolveManagerWithHlsConfig(array $hlsConfig): TranscodeManager
    {
        $this->seedRegistry();

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new TranscodeServicesProvider())->register($builder, [
            'ffmpeg' => [
                'ffmpeg_path' => '/usr/bin/ffmpeg',
                'ffprobe_path' => '/usr/bin/ffprobe',
                'transcode_dir' => '/tmp/phlix_transcodes',
            ],
            'hls' => $hlsConfig,
        ]);
        $builder->addDefinitions([
            'logger.media' => new NullLogger(),
            Connection::class => $this->createMock(Connection::class),
        ]);
        $container = $builder->build();

        /** @var TranscodeManager $manager */
        $manager = $container->get(TranscodeManager::class);

        return $manager;
    }

    private function readMinDiskSpaceBytes(TranscodeManager $manager): int
    {
        $p = new \ReflectionProperty(TranscodeManager::class, 'minDiskSpaceBytes');
        $p->setAccessible(true);
        $value = $p->getValue($manager);
        $this->assertIsInt($value);

        return $value;
    }

    /**
     * SV-1.9 default-threshold guard: with NO `min_disk_space_bytes` config key
     * the provider passes null (position 13) and the TranscodeManager applies its
     * own 500 MiB default. This pins the effective floor so a future drift between
     * the provider wiring and the constructor default is caught here.
     */
    public function test_provider_defaults_enospc_threshold_to_500_mib(): void
    {
        $manager = $this->resolveManagerWithHlsConfig([
            'segment_dir' => '/tmp/phlix_hls',
            'segment_seconds' => 6,
        ]);

        $this->assertSame(500 * 1024 * 1024, $this->readMinDiskSpaceBytes($manager));
    }

    /**
     * SV-1.9 wiring: when `min_disk_space_bytes` IS configured the provider threads
     * it through to the TranscodeManager constructor's ENOSPC-threshold arg
     * (position 13), making the guard operator-tunable.
     */
    public function test_provider_threads_configured_enospc_threshold(): void
    {
        $manager = $this->resolveManagerWithHlsConfig([
            'segment_dir' => '/tmp/phlix_hls',
            'segment_seconds' => 6,
            'min_disk_space_bytes' => 12_345_678,
        ]);

        $this->assertSame(12_345_678, $this->readMinDiskSpaceBytes($manager));
    }

    /**
     * C6 wiring: a configured `prewarm_segments` (from `hls.prewarm_segments`,
     * itself env-driven by PHLIX_SEGMENT_PREWARM_SEGMENTS) is threaded through to
     * the TranscodeManager, so the head-start window is operator-tunable — and
     * `0` disables it — without touching code.
     */
    public function test_provider_threads_configured_prewarm_window(): void
    {
        $manager = $this->resolveManagerWithHlsConfig([
            'segment_dir' => '/tmp/phlix_hls',
            'segment_seconds' => 6,
            'prewarm_segments' => 5,
        ]);

        $this->assertSame(5, $this->readPrewarmSegments($manager));
    }

    /**
     * C6 default-on law: with NO `prewarm_segments` config key the provider passes
     * null and the TranscodeManager applies its own PREWARM_SEGMENT_COUNT (3) —
     * the head start ships enabled, not behind off-by-default theater. The literal
     * is the golden (same pin style as the 500 MiB ENOSPC default above); rotating
     * the const is a deliberate, measured-red act.
     */
    public function test_provider_defaults_prewarm_window_to_three(): void
    {
        $manager = $this->resolveManagerWithHlsConfig([
            'segment_dir' => '/tmp/phlix_hls',
            'segment_seconds' => 6,
        ]);

        $this->assertSame(3, $this->readPrewarmSegments($manager));
    }

    private function readPrewarmSegments(TranscodeManager $manager): int
    {
        $p = new \ReflectionProperty(TranscodeManager::class, 'prewarmSegments');
        $p->setAccessible(true);
        $value = $p->getValue($manager);
        $this->assertIsInt($value);

        return $value;
    }

    /**
     * SV-3.3(1A): resolves the singleton {@see TranscodeManager} through the
     * provider with an arbitrary `ffmpeg` config sub-array (merged over the standard
     * paths) so the loudness-normalization plumbing can be asserted directly off the
     * constructed instance.
     *
     * @param array<string, mixed> $ffmpegConfig
     */
    private function resolveManagerWithFfmpegConfig(array $ffmpegConfig): TranscodeManager
    {
        $this->seedRegistry();

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new TranscodeServicesProvider())->register($builder, [
            'ffmpeg' => array_merge([
                'ffmpeg_path' => '/usr/bin/ffmpeg',
                'ffprobe_path' => '/usr/bin/ffprobe',
                'transcode_dir' => '/tmp/phlix_transcodes',
            ], $ffmpegConfig),
            'hls' => [
                'segment_dir' => '/tmp/phlix_hls',
                'segment_seconds' => 6,
            ],
        ]);
        $builder->addDefinitions([
            'logger.media' => new NullLogger(),
            Connection::class => $this->createMock(Connection::class),
        ]);
        $container = $builder->build();

        /** @var TranscodeManager $manager */
        $manager = $container->get(TranscodeManager::class);

        return $manager;
    }

    /**
     * @return array<string, float>|null
     */
    private function readLoudnormParams(TranscodeManager $manager): ?array
    {
        $p = new \ReflectionProperty(TranscodeManager::class, 'loudnormParams');
        $p->setAccessible(true);
        /** @var array<string, float>|null $value */
        $value = $p->getValue($manager);

        return $value;
    }

    /**
     * SV-3.3(1A) default-inert guard: with NO `ffmpeg.loudness` config at all the
     * provider passes null (position 14) and the TranscodeManager stores null, so
     * loudnorm stays inert. Pins the deployable-by-default behavior.
     */
    public function test_provider_defaults_loudnorm_to_null_when_absent(): void
    {
        $manager = $this->resolveManagerWithFfmpegConfig([]);

        $this->assertNull($this->readLoudnormParams($manager));
        $this->assertNull($manager->getLoudnormParams());
    }

    /**
     * SV-3.3(1A): the shipped `config/ffmpeg.php` shape (`enabled => false` with
     * populated `I/LRA/TP`) must still yield null — an explicit disable overrides
     * present target values, keeping the default deploy inert.
     */
    public function test_provider_ignores_loudnorm_when_disabled(): void
    {
        $manager = $this->resolveManagerWithFfmpegConfig([
            'loudness' => [
                'enabled' => false,
                'I' => -16,
                'LRA' => 11,
                'TP' => -1.5,
            ],
        ]);

        $this->assertNull($this->readLoudnormParams($manager));
        $this->assertNull($manager->getLoudnormParams());
    }

    /**
     * SV-3.3(1A) wiring: when `ffmpeg.loudness` is enabled with a numeric integrated
     * target the provider normalizes it to a float `['I','LRA','TP']` array and
     * threads it through the TranscodeManager constructor's loudnorm arg (position
     * 14) so sub-step 1B has something to read. (Still inert: nothing consumes it
     * yet.)
     */
    public function test_provider_threads_configured_loudnorm_params(): void
    {
        $manager = $this->resolveManagerWithFfmpegConfig([
            'loudness' => [
                'enabled' => true,
                'I' => -16,
                'LRA' => 11,
                'TP' => -1.5,
            ],
        ]);

        $expected = ['I' => -16.0, 'LRA' => 11.0, 'TP' => -1.5];
        $this->assertSame($expected, $this->readLoudnormParams($manager));
        $this->assertSame($expected, $manager->getLoudnormParams());
    }

    /**
     * SV-3.3(1A): when enabled but the required integrated-loudness target is only
     * partially present, the provider still threads the numeric subset (here just
     * `I`) rather than fabricating `LRA`/`TP`.
     */
    public function test_provider_threads_partial_loudnorm_params(): void
    {
        $manager = $this->resolveManagerWithFfmpegConfig([
            'loudness' => [
                'enabled' => true,
                'I' => -23,
            ],
        ]);

        $this->assertSame(['I' => -23.0], $this->readLoudnormParams($manager));
    }

    /**
     * SV-3.3(1A) safe fallback: enabled=true but a missing/non-numeric integrated
     * target (`I`) → null, not a malformed param. Guards against shipping an
     * unusable loudnorm config to sub-step 1B.
     */
    public function test_provider_ignores_loudnorm_with_enabled_but_missing_target(): void
    {
        $missingI = $this->resolveManagerWithFfmpegConfig([
            'loudness' => [
                'enabled' => true,
                'LRA' => 11,
                'TP' => -1.5,
            ],
        ]);
        $this->assertNull($this->readLoudnormParams($missingI));

        $nonNumericI = $this->resolveManagerWithFfmpegConfig([
            'loudness' => [
                'enabled' => true,
                'I' => 'loud',
            ],
        ]);
        $this->assertNull($this->readLoudnormParams($nonNumericI));
    }

    /**
     * @param array<string, mixed> $ffmpeg Extra `ffmpeg` config keys, merged over the base.
     */
    private function buildRunnerWithFfmpeg(array $ffmpeg): FfmpegRunner
    {
        $this->seedRegistry();

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new TranscodeServicesProvider())->register($builder, [
            'ffmpeg' => array_merge([
                'ffmpeg_path' => '/usr/bin/ffmpeg',
                'ffprobe_path' => '/usr/bin/ffprobe',
                'transcode_dir' => '/tmp/phlix_transcodes',
            ], $ffmpeg),
            'hls' => [
                'segment_dir' => '/tmp/phlix_hls',
                'segment_seconds' => 6,
            ],
        ]);
        $builder->addDefinitions(['logger.media' => new NullLogger()]);

        /** @var FfmpegRunner $runner */
        $runner = $builder->build()->get(FfmpegRunner::class);

        return $runner;
    }

    /** The disk cache the runner was wired with, or null when the runner is memo-only. */
    private function probeCacheOf(FfmpegRunner $runner): ?ProbeCache
    {
        $value = (new \ReflectionProperty(FfmpegRunner::class, 'probeCache'))->getValue($runner);

        return $value instanceof ProbeCache ? $value : null;
    }

    /**
     * Item 10: the disk cache is opt-in. With PHLIX_PROBE_CACHE_DIR unset, the real default in
     * config/ffmpeg.php is empty, so the runner is memo-only: no ProbeCache, and the directory the old
     * default pointed at is not created. The default is read from the file, not from a literal.
     */
    public function testProbeCacheIsOffByDefault(): void
    {
        $envBefore = getenv('PHLIX_PROBE_CACHE_DIR');
        putenv('PHLIX_PROBE_CACHE_DIR');
        try {
            /** @var array<string, mixed> $file */
            $file = require dirname(__DIR__, 5) . '/config/ffmpeg.php';
            $this->assertSame('', $file['probe_cache_dir'] ?? null, 'the default is empty, so the cache is opt-in');

            $oldDefault = sys_get_temp_dir() . '/phlix-ffprobe-cache';
            $existed = is_dir($oldDefault);

            $runner = $this->buildRunnerWithFfmpeg(['probe_cache_dir' => $file['probe_cache_dir'] ?? '']);

            $this->assertNull($this->probeCacheOf($runner), 'the runner is memo-only');
            $this->assertSame($existed, is_dir($oldDefault), 'the old default directory is not created');
        } finally {
            if ($envBefore === false) {
                putenv('PHLIX_PROBE_CACHE_DIR');
            } else {
                putenv('PHLIX_PROBE_CACHE_DIR=' . $envBefore);
            }
        }
    }

    /** Item 10: a non-empty absolute directory turns the cache on, and the directory is created private. */
    public function testProbeCacheIsWiredWhenADirectoryIsSet(): void
    {
        $dir = sys_get_temp_dir() . '/phlix-provider-probe-' . bin2hex(random_bytes(6));
        try {
            $runner = $this->buildRunnerWithFfmpeg(['probe_cache_dir' => $dir]);

            $this->assertInstanceOf(ProbeCache::class, $this->probeCacheOf($runner));
            $this->assertDirectoryExists($dir);
            $this->assertSame(0700, fileperms($dir) & 0777);
        } finally {
            @rmdir($dir);
        }
    }

    /** Item 10: a relative value is opt-in but refused, so the runner stays memo-only and nothing is created. */
    public function testARelativeProbeCacheDirectoryLeavesTheRunnerMemoOnly(): void
    {
        $name = 'phlix-provider-relative-' . bin2hex(random_bytes(6));

        $runner = $this->buildRunnerWithFfmpeg(['probe_cache_dir' => $name]);

        $this->assertNull($this->probeCacheOf($runner));
        $this->assertDirectoryDoesNotExist(getcwd() . '/' . $name);
    }
}
