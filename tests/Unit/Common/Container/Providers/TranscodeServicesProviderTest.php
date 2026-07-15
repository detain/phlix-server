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
 *
 * @covers \Phlix\Common\Container\Providers\TranscodeServicesProvider
 */
final class TranscodeServicesProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HwaccelRegistry::reset();
        HwAccelConfig::reset();
        FfmpegRunner::resetHwaccelProbed();
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
}
