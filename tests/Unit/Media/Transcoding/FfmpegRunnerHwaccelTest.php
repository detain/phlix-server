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

    private function vaapiCapability(string $device = '/dev/dri/renderD128'): HwaccelCapability
    {
        return new HwaccelCapability(
            vendor: 'vaapi',
            encoder: 'h264_vaapi',
            decoder: 'h264_vaapi',
            supports_hdr_tone_mapping: false,
            supported_codecs: ['h264', 'hevc'],
            supported_profiles: ['main', 'high'],
            max_resolution_w: 3840,
            max_resolution_h: 2160,
            max_bitrate: 100000000,
            extra_args: ['device' => $device],
        );
    }

    private function qsvCapability(string $device = '/dev/dri/renderD128'): HwaccelCapability
    {
        return new HwaccelCapability(
            vendor: 'qsv',
            encoder: 'h264_qsv',
            decoder: 'h264_qsv',
            supports_hdr_tone_mapping: false,
            supported_codecs: ['h264', 'hevc'],
            supported_profiles: ['main', 'high'],
            max_resolution_w: 3840,
            max_resolution_h: 2160,
            max_bitrate: 100000000,
            extra_args: ['device' => $device],
        );
    }

    /**
     * The VAAPI input flags come from the profile ({@see VaapiProfile}), which
     * emits `-vaapi_device` and deliberately NO `-hwaccel_output_format` — so
     * decoded frames land in system memory where the software scale/tonemap
     * filters are valid. (Regression guard for the old collision where the
     * segment path baked in `-hwaccel_output_format vaapi`.)
     */
    public function test_hwaccel_input_flags_are_vendor_specific_for_vaapi(): void
    {
        $registry = $this->seedRegistry(['vaapi' => $this->vaapiCapability()]);

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
        // Delegated to VaapiProfile::getInputDeviceArgs — no hardware-surface output format.
        $this->assertStringContainsString('-vaapi_device', $cmd);
        $this->assertStringContainsString('/dev/dri/renderD128', $cmd);
        $this->assertStringContainsString('-c:v h264_vaapi', $cmd);
        $this->assertStringNotContainsString('-hwaccel_output_format', $cmd);
    }

    /**
     * Finding #1 regression: a DOWNSCALED NVENC segment (width/height supplied,
     * so a software `scale=` is emitted) must NOT also force decoded frames onto
     * a hardware surface via `-hwaccel_output_format`, or ffmpeg aborts with
     * "Impossible to convert between the formats". NVENC accepts system-memory
     * frames, so no upload filter is needed either.
     */
    public function test_downscaled_nvenc_segment_has_no_hw_surface_software_filter_collision(): void
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
            ['video_codec' => 'libx264', 'width' => 1280, 'height' => 720],
        );

        $this->assertNotNull($cmd);
        $this->assertStringContainsString('-c:v h264_nvenc', $cmd);
        // Software scale is present …
        $this->assertStringContainsString('scale=1280:720', $cmd);
        // … so there must be NO hardware-surface output format to collide with it.
        $this->assertStringNotContainsString('-hwaccel_output_format', $cmd);
    }

    /**
     * Finding #1 regression for VAAPI: a downscaled segment emits a software
     * `scale=` (valid because frames are in system memory) and, because the
     * VAAPI encoder needs frames on a HW surface, a trailing `hwupload` — but
     * still NO `-hwaccel_output_format` before `-i`. The upload must come AFTER
     * the software scale, never before it.
     */
    public function test_downscaled_vaapi_segment_uploads_after_software_scale(): void
    {
        $registry = $this->seedRegistry(['vaapi' => $this->vaapiCapability()]);

        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
        $runner->setConfig(['enabled' => true, 'prefer_hardware' => true]);
        $runner->probeHardwareAcceleration($registry);

        $cmd = $runner->buildHwaccelSegmentCommand(
            '/input.mkv',
            '/tmp/out.ts',
            0.0,
            6.0,
            ['video_codec' => 'libx264', 'width' => 1280, 'height' => 720],
        );

        $this->assertNotNull($cmd);
        $this->assertStringContainsString('-c:v h264_vaapi', $cmd);
        $this->assertStringContainsString('scale=1280:720', $cmd);
        $this->assertStringContainsString('hwupload', $cmd);
        $this->assertStringNotContainsString('-hwaccel_output_format', $cmd);
        // Upload happens after the software scale, not before it.
        $this->assertLessThan(
            strpos($cmd, 'hwupload'),
            strpos($cmd, 'scale=1280:720'),
            'hwupload must come after the software scale filter',
        );
    }

    /**
     * Finding #1 regression: an HDR tone-mapped NVENC segment appends a software
     * tonemap graph, which is only valid if frames are in system memory — i.e.
     * there must be no `-hwaccel_output_format` forcing a hardware surface.
     */
    public function test_hdr_tonemap_nvenc_segment_has_no_hw_surface_collision(): void
    {
        // require_hdr_tone_map=true only selects an encoder advertising HDR
        // tone-map support, so seed an NVENC capability with it enabled.
        $hdrNvenc = new HwaccelCapability(
            vendor: 'nvenc',
            encoder: 'h264_nvenc',
            decoder: 'h264_cuvid',
            supports_hdr_tone_mapping: true,
            supported_codecs: ['h264', 'hevc'],
            supported_profiles: ['baseline', 'main', 'high'],
            max_resolution_w: 3840,
            max_resolution_h: 2160,
            max_bitrate: 100000000,
        );
        $registry = $this->seedRegistry(['nvenc' => $hdrNvenc]);

        // Force the HDR branch to emit a real software tonemap graph without a
        // real HDR file (needsToneMapping/probe would otherwise short-circuit on
        // a non-existent input) by overriding getToneMappingProfile().
        $runner = new class ('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp') extends FfmpegRunner {
            public function getToneMappingProfile(string $inputPath, string $outputPath, string $codec): ?string
            {
                return 'zscale=t=linear:npl=100,tonemap=hable,zscale=t=bt709,format=yuv420p';
            }
        };
        $runner->setConfig(['enabled' => true, 'prefer_hardware' => true]);
        $runner->probeHardwareAcceleration($registry);

        $cmd = $runner->buildHwaccelSegmentCommand(
            '/input.mkv',
            '/tmp/out.ts',
            0.0,
            6.0,
            ['video_codec' => 'libx264', 'require_hdr_tone_map' => true],
        );

        $this->assertNotNull($cmd);
        $this->assertStringContainsString('-c:v h264_nvenc', $cmd);
        // A software tonemap filter graph is present (tonemap/zscale) …
        $this->assertMatchesRegularExpression('/tonemap|zscale/', $cmd);
        // … and it is NOT preceded by a hardware-surface output format.
        $this->assertStringNotContainsString('-hwaccel_output_format', $cmd);
    }

    /**
     * QSV device from the probe (`extra_args['device']`, per the QsvProbe key
     * fix) actually reaches the built command via QsvProfile::getInputDeviceArgs
     * — a non-default device is preserved rather than silently replaced by the
     * `/dev/dri/renderD128` fallback. Also coherent: no HW-surface collision.
     */
    public function test_qsv_segment_uses_probe_device(): void
    {
        $registry = $this->seedRegistry(['qsv' => $this->qsvCapability('/dev/dri/renderD129')]);

        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
        $runner->setConfig(['enabled' => true, 'prefer_hardware' => true]);
        $runner->probeHardwareAcceleration($registry);

        $cmd = $runner->buildHwaccelSegmentCommand(
            '/input.mkv',
            '/tmp/out.ts',
            0.0,
            6.0,
            ['video_codec' => 'libx264', 'width' => 1280, 'height' => 720],
        );

        $this->assertNotNull($cmd);
        $this->assertStringContainsString('-c:v h264_qsv', $cmd);
        $this->assertStringContainsString('-qsv_device /dev/dri/renderD129', $cmd);
        // hwupload present for the QSV encoder; no HW-surface output format collision.
        $this->assertStringContainsString('hwupload', $cmd);
        $this->assertStringNotContainsString('-hwaccel_output_format', $cmd);
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
