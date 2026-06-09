<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * Covers the HLS-muxing additions to {@see FfmpegRunner}: the native-HLS command
 * builder and the detached-launch / process-probe helpers.
 */
class FfmpegRunnerHlsTest extends TestCase
{
    private function runner(): FfmpegRunner
    {
        return new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
    }

    public function testBuildHlsCommandCopiesCompatibleStreams(): void
    {
        $cmd = $this->runner()->buildHlsCommand('/in.mkv', '/out', [
            'video_codec' => 'copy',
            'audio_codec' => 'copy',
            'segment_seconds' => 6,
        ]);

        $this->assertStringContainsString('-c:v copy', $cmd);
        $this->assertStringContainsString('-c:a copy', $cmd);
        $this->assertStringContainsString('-f hls', $cmd);
        $this->assertStringContainsString('-hls_time 6', $cmd);
        $this->assertStringContainsString('-hls_segment_type mpegts', $cmd);
        $this->assertStringContainsString("segment_0_%03d.ts", $cmd);
        $this->assertStringContainsString("stream_0.m3u8", $cmd);
        // A pure remux must NOT encode.
        $this->assertStringNotContainsString('libx264', $cmd);
    }

    public function testBuildHlsCommandEncodesAndScalesWhenRequested(): void
    {
        $cmd = $this->runner()->buildHlsCommand('/in.mkv', '/out', [
            'video_codec' => 'libx264',
            'crf' => 23,
            'preset' => 'veryfast',
            'width' => 1920,
            'height' => 1080,
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
            'audio_channels' => 2,
        ]);

        $this->assertStringContainsString('-c:v libx264', $cmd);
        $this->assertStringContainsString('-preset veryfast', $cmd);
        $this->assertStringContainsString('-crf 23', $cmd);
        $this->assertStringContainsString('scale=1920:1080:force_original_aspect_ratio=decrease', $cmd);
        $this->assertStringContainsString('-g 48', $cmd);
        $this->assertStringContainsString('-sc_threshold 0', $cmd);
        $this->assertStringContainsString('-c:a aac', $cmd);
        $this->assertStringContainsString('-b:a 128k', $cmd);
        $this->assertStringContainsString('-ac 2', $cmd);
    }

    public function testBuildHlsCommandHonorsVariantIndex(): void
    {
        $cmd = $this->runner()->buildHlsCommand('/in.mkv', '/out', [
            'variant_index' => 2,
            'video_codec' => 'copy',
            'audio_codec' => 'copy',
        ]);

        $this->assertStringContainsString('segment_2_%03d.ts', $cmd);
        $this->assertStringContainsString('stream_2.m3u8', $cmd);
    }

    public function testBuildHlsCommandDefaultsSegmentSecondsWhenInvalid(): void
    {
        $cmd = $this->runner()->buildHlsCommand('/in.mkv', '/out', [
            'video_codec' => 'copy',
            'audio_codec' => 'copy',
            'segment_seconds' => 0,
        ]);

        $this->assertStringContainsString('-hls_time 6', $cmd);
    }

    public function testStartDetachedReturnsPidAndIsNonBlocking(): void
    {
        $dir = sys_get_temp_dir() . '/phlix_detached_' . uniqid();
        mkdir($dir, 0755, true);

        // A trivial backgrounded command: returns a real pid, writes .complete.
        $pid = $this->runner()->startDetached('sleep 0.2', $dir);

        $this->assertGreaterThan(0, $pid);

        // Poll for the completion marker the wrapper writes on success.
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline && !file_exists("{$dir}/.complete")) {
            usleep(50000);
        }
        $this->assertFileExists("{$dir}/.complete");
        $this->assertFileDoesNotExist("{$dir}/.failed");

        $this->removeDir($dir);
    }

    public function testStartDetachedWritesFailedMarkerOnNonZeroExit(): void
    {
        $dir = sys_get_temp_dir() . '/phlix_detached_fail_' . uniqid();
        mkdir($dir, 0755, true);

        $this->runner()->startDetached('false', $dir);

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline && !file_exists("{$dir}/.failed")) {
            usleep(50000);
        }
        $this->assertFileExists("{$dir}/.failed");

        $this->removeDir($dir);
    }

    public function testIsProcessRunningForSelfAndBogusPid(): void
    {
        $runner = $this->runner();
        $this->assertTrue($runner->isProcessRunning(getmypid() ?: 1));
        $this->assertFalse($runner->isProcessRunning(0));
        $this->assertFalse($runner->isProcessRunning(-5));
    }

    private function removeDir(string $dir): void
    {
        $files = glob("{$dir}/*") ?: [];
        foreach ($files as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        // Hidden markers
        foreach (['.complete', '.failed'] as $marker) {
            if (is_file("{$dir}/{$marker}")) {
                unlink("{$dir}/{$marker}");
            }
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }
}
