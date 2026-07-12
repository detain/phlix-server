<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Common\Container\Providers\TranscodeServicesProvider;
use Phlix\Config\HwAccelConfig;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\HwaccelRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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
}
