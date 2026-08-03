<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Common\Container\Providers\TranscodeServicesProvider;
use Phlix\Config\HwAccelConfig;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
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
}
