<?php

namespace Phlex\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Transcoding\FfmpegRunner;

class FfmpegRunnerTest extends TestCase
{
    public function testCanCreateFfmpegRunner(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');

        $this->assertInstanceOf(FfmpegRunner::class, $runner);
    }

    public function testBuildTranscodeCommand(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');

        $params = [
            'video_codec' => 'libx264',
            'preset' => 'medium',
            'crf' => 23,
            'width' => 1920,
            'height' => 1080,
            'audio_codec' => 'aac',
            'audio_bitrate' => '192k',
            'container' => 'mp4',
        ];

        $cmd = $runner->buildTranscodeCommand('/input.mkv', '/output.mp4', $params);

        $this->assertStringContainsString('libx264', $cmd);
        $this->assertStringContainsString('aac', $cmd);
        $this->assertStringContainsString('/input.mkv', $cmd);
        $this->assertStringContainsString('/output.mp4', $cmd);
    }

    public function testIsAvailableReturnsFalseForNonexistentBinary(): void
    {
        $runner = new FfmpegRunner('/nonexistent/ffmpeg', '/nonexistent/ffprobe', '/tmp');

        $this->assertFalse($runner->isAvailable());
    }

    public function testGetTranscodeDirReturnsConfiguredPath(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/var/transcodes');

        $this->assertSame('/var/transcodes', $runner->getTranscodeDir());
    }

    public function testBuildTranscodeCommandIgnoresNonScalarParams(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');

        // Mixed/garbage values (objects, arrays, true) should not corrupt the command line.
        $params = [
            'video_codec' => 'libx264',
            'preset' => new \stdClass(),
            'crf' => ['bogus'],
            'width' => true,
            'height' => null,
            'audio_codec' => 'aac',
            'audio_bitrate' => 192,           // numeric int — should serialize
            'audio_sample_rate' => '44100',   // numeric string — should coerce
        ];

        $cmd = $runner->buildTranscodeCommand('/input.mkv', '/output.mp4', $params);

        $this->assertStringContainsString('-c:v libx264', $cmd);
        $this->assertStringContainsString('-preset medium', $cmd);   // fallback default
        $this->assertStringContainsString('-crf 23', $cmd);          // fallback default
        $this->assertStringContainsString('-b:a 192', $cmd);
        $this->assertStringContainsString('-ar 44100', $cmd);
        $this->assertStringNotContainsString('-vf', $cmd);            // width/height not valid ints
    }

    public function testBuildTranscodeCommandHonoursValidWidthHeight(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');

        $cmd = $runner->buildTranscodeCommand('/input.mkv', '/output.mp4', [
            'video_codec' => 'libx265',
            'width' => 1280,
            'height' => 720,
        ]);

        $this->assertStringContainsString('libx265', $cmd);
        $this->assertStringContainsString('scale=1280:720', $cmd);
    }

    public function testGetVersionReturnsNullWhenBinaryMissing(): void
    {
        $runner = new FfmpegRunner('/nonexistent/ffmpeg', '/nonexistent/ffprobe', '/tmp');

        $this->assertNull($runner->getVersion());
    }
}
