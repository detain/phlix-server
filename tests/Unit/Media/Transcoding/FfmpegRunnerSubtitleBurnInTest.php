<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\HwaccelRegistry;

/**
 * SV-1.6 gap #3: {@see FfmpegRunner::buildSegmentCommand()} and
 * {@see FfmpegRunner::buildHwaccelSegmentCommand()} — the REAL per-segment
 * builders used by {@see FfmpegRunner::startSegmentEncode()} — wire subtitle
 * burn-in ({@see \Phlix\Media\Transcoding\Subtitles\SubtitleBurner}) into the
 * live per-segment transcode pipeline; previously they never referenced
 * subtitles at all.
 *
 * These tests assert a per-segment command built with subtitle burn-in
 * enabled (via the new `subtitle_burn_in` segment param) actually includes
 * the (colon/backslash-escaping-corrected) subtitle filter in its `-vf`
 * filter chain, for both the software and HW-accelerated segment builders.
 */
final class FfmpegRunnerSubtitleBurnInTest extends TestCase
{
    private string $vttPath;

    protected function setUp(): void
    {
        parent::setUp();
        HwaccelRegistry::reset();
        // S439: tempnam()'s own file is orphaned by the '.vtt' suffix — drop it.
        $rawPath = tempnam(sys_get_temp_dir(), 'phlix_sub_');
        @unlink($rawPath);
        $this->vttPath = $rawPath . '.vtt';
        file_put_contents($this->vttPath, "WEBVTT\n\n00:00:00.000 --> 00:00:02.000\nHello\n");
    }

    protected function tearDown(): void
    {
        HwaccelRegistry::reset();
        if (is_file($this->vttPath)) {
            unlink($this->vttPath);
        }
        parent::tearDown();
    }

    /**
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

    private function vaapiCapability(): HwaccelCapability
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
            extra_args: ['device' => '/dev/dri/renderD128'],
        );
    }

    public function testBuildSegmentCommandOmitsSubtitleFilterWhenNotConfigured(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');

        $cmd = $runner->buildSegmentCommand('/input.mkv', '/tmp/out.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
        ]);

        $this->assertStringNotContainsString('subtitles=', $cmd);
    }

    public function testBuildSegmentCommandIncludesSubtitleFilterWhenEnabled(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');

        $cmd = $runner->buildSegmentCommand('/input.mkv', '/tmp/out.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
            'width' => 1280,
            'height' => 720,
            'subtitle_burn_in' => ['path' => $this->vttPath, 'format' => 'vtt'],
        ]);

        $this->assertStringContainsString('-vf "', $cmd);
        $this->assertStringContainsString('subtitles=' . $this->vttPath, $cmd);
        // Subtitle burn-in (a software filter) must precede the scale filter in
        // the chain, matching the established software-filter-before-scale ordering.
        $subtitlesPos = strpos($cmd, 'subtitles=');
        $scalePos = strpos($cmd, 'scale=1280:720');
        $this->assertNotFalse($subtitlesPos);
        $this->assertNotFalse($scalePos);
        $this->assertLessThan($scalePos, $subtitlesPos);
    }

    public function testBuildSegmentCommandSkipsSubtitleFilterWhenFileMissing(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');

        $cmd = $runner->buildSegmentCommand('/input.mkv', '/tmp/out.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
            // Extraction hasn't produced this sidecar yet (or never will) —
            // must degrade silently rather than emitting a bogus filter arg.
            'subtitle_burn_in' => ['path' => '/tmp/does-not-exist-phlix.vtt', 'format' => 'vtt'],
        ]);

        $this->assertStringNotContainsString('subtitles=', $cmd);
    }

    public function testBuildHwaccelSegmentCommandIncludesSubtitleFilterBeforeHwupload(): void
    {
        $registry = $this->seedRegistry(['vaapi' => $this->vaapiCapability()]);

        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
        $runner->setConfig(['enabled' => true, 'prefer_hardware' => true]);
        $runner->probeHardwareAcceleration($registry);

        $cmd = $runner->buildHwaccelSegmentCommand('/input.mkv', '/tmp/out.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
            'subtitle_burn_in' => ['path' => $this->vttPath, 'format' => 'vtt'],
        ]);

        $this->assertNotNull($cmd);
        $this->assertStringContainsString('-c:v h264_vaapi', $cmd);
        $this->assertStringContainsString('subtitles=' . $this->vttPath, $cmd);
        $this->assertStringContainsString('hwupload', $cmd);

        // A hardware surface cannot be processed by the software subtitles
        // filter, so subtitles= must appear BEFORE hwupload in the chain.
        $subtitlesPos = strpos($cmd, 'subtitles=');
        $hwuploadPos = strpos($cmd, 'hwupload');
        $this->assertNotFalse($subtitlesPos);
        $this->assertNotFalse($hwuploadPos);
        $this->assertLessThan($hwuploadPos, $subtitlesPos);
    }

    public function testBuildHwaccelSegmentCommandOmitsSubtitleFilterWhenNotConfigured(): void
    {
        $registry = $this->seedRegistry(['vaapi' => $this->vaapiCapability()]);

        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
        $runner->setConfig(['enabled' => true, 'prefer_hardware' => true]);
        $runner->probeHardwareAcceleration($registry);

        $cmd = $runner->buildHwaccelSegmentCommand('/input.mkv', '/tmp/out.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
        ]);

        $this->assertNotNull($cmd);
        $this->assertStringNotContainsString('subtitles=', $cmd);
    }
}
