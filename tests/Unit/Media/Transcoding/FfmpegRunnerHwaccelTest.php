<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\HwaccelRegistry;
use PHPUnit\Framework\TestCase;

/**
 * SV-0.1 segment-command wiring: {@see FfmpegRunner::buildHwaccelSegmentCommand()}
 * emits a hardware encode command (with `-hwaccel` input flags and the vendor
 * `-c:v` encoder) when the probed registry reports an available HW encoder, and
 * returns null (so the caller falls back to the libx264 software path) when it
 * does not.
 *
 * @covers \Phlix\Media\Transcoding\FfmpegRunner
 */
final class FfmpegRunnerHwaccelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HwaccelRegistry::reset();
    }

    protected function tearDown(): void
    {
        HwaccelRegistry::reset();
        parent::tearDown();
    }

    /**
     * Seeds the HwaccelRegistry singleton with a fixed capability map so the
     * probe is deterministic and never touches real hardware.
     *
     * @param array<string, HwaccelCapability> $capabilities
     */
    private function seedRegistry(array $capabilities): HwaccelRegistry
    {
        HwaccelRegistry::reset();
        $registry = HwaccelRegistry::getInstance();

        $ref = new \ReflectionObject($registry);

        $capProp = $ref->getProperty('capabilities');
        $capProp->setAccessible(true);
        $capProp->setValue($registry, $capabilities);

        $initProp = $ref->getProperty('initialized');
        $initProp->setAccessible(true);
        $initProp->setValue($registry, true);

        return $registry;
    }

    private function nvencCapability(): HwaccelCapability
    {
        return new HwaccelCapability(
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
    }

    public function test_segment_command_uses_hw_encoder_when_available(): void
    {
        $registry = $this->seedRegistry(['nvenc' => $this->nvencCapability()]);

        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
        $runner->setConfig(['enabled' => true, 'prefer_hardware' => true]);
        $runner->probeHardwareAcceleration($registry);

        $cmd = $runner->buildHwaccelSegmentCommand(
            '/input.mkv',
            '/tmp/out.ts',
            0.0,
            6.0,
            ['video_codec' => 'libx264'],
        );

        $this->assertNotNull($cmd);
        $this->assertStringContainsString('-hwaccel', $cmd);
        $this->assertStringContainsString('-c:v h264_nvenc', $cmd);
    }

    public function test_hwaccel_input_flags_are_vendor_specific_for_vaapi(): void
    {
        $vaapi = new HwaccelCapability(
            vendor: 'vaapi',
            encoder: 'h264_vaapi',
            decoder: 'h264_vaapi',
            supports_hdr_tone_mapping: false,
            supported_codecs: ['h264', 'hevc'],
            supported_profiles: ['main', 'high'],
            max_resolution_w: 3840,
            max_resolution_h: 2160,
            max_bitrate: 100000000,
            extra_args: ['device' => '/dev/dri/renderD128'],
        );
        $registry = $this->seedRegistry(['vaapi' => $vaapi]);

        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
        $runner->setConfig(['enabled' => true, 'prefer_hardware' => true]);
        $runner->probeHardwareAcceleration($registry);

        $cmd = $runner->buildHwaccelSegmentCommand(
            '/input.mkv',
            '/tmp/out.ts',
            0.0,
            6.0,
            ['video_codec' => 'libx264'],
        );

        $this->assertNotNull($cmd);
        $this->assertStringContainsString('-hwaccel vaapi', $cmd);
        $this->assertStringContainsString('-hwaccel_output_format vaapi', $cmd);
        $this->assertStringContainsString('/dev/dri/renderD128', $cmd);
        $this->assertStringContainsString('-c:v h264_vaapi', $cmd);
    }

    public function test_segment_command_null_when_no_hw_encoder(): void
    {
        // Empty capability map => getEncoder() returns null => no HW path.
        $registry = $this->seedRegistry([]);

        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
        $runner->setConfig(['enabled' => true, 'prefer_hardware' => true]);
        $runner->probeHardwareAcceleration($registry);

        $cmd = $runner->buildHwaccelSegmentCommand(
            '/input.mkv',
            '/tmp/out.ts',
            0.0,
            6.0,
            ['video_codec' => 'libx264'],
        );

        $this->assertNull($cmd);
    }

    /**
     * The software fallback the caller uses when the HW path returns null still
     * produces a valid libx264 segment command.
     */
    public function test_software_fallback_segment_command_uses_libx264(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');

        $cmd = $runner->buildSegmentCommand(
            '/input.mkv',
            '/tmp/out.ts',
            0.0,
            6.0,
            ['video_codec' => 'libx264'],
        );

        $this->assertStringContainsString('-c:v libx264', $cmd);
        $this->assertStringNotContainsString('-hwaccel', $cmd);
    }

    public function test_hardware_acceleration_summary_reflects_probe(): void
    {
        $registry = $this->seedRegistry(['nvenc' => $this->nvencCapability()]);

        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
        $runner->setConfig(['enabled' => true, 'prefer_hardware' => true]);
        $runner->probeHardwareAcceleration($registry);

        $summary = $runner->getHardwareAccelerationSummary();

        $this->assertTrue($summary['enabled']);
        $this->assertTrue($summary['prefer_hardware']);
        $this->assertContains('nvenc', $summary['available']);
        $this->assertSame('nvenc', $summary['chosen_vendor']);
        $this->assertSame('h264_nvenc', $summary['chosen_encoder']);
    }
}
