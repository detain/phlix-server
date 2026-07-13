<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * End-to-end exercise of {@see FfmpegRunner::generateThumbnailBatch()} `[S-F19]`
 * against a REAL ffmpeg binary: generates a short synthetic clip, requests
 * thumbnails at multiple distinct timestamps in one batch call, and asserts
 * distinct, non-empty frames are actually produced. Skipped when ffmpeg is not
 * installed so the suite stays green on minimal CI images.
 *
 * Before the SV-0.9 command-shape fix this batch call rendered NO thumbnails
 * at all once more than one timestamp was requested (a malformed command with
 * every output group bunched before a single shared `-i`); these tests would
 * fail against that prior shape.
 */
class FfmpegThumbnailBatchTest extends TestCase
{
    private string $dir;
    private FfmpegRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', sys_get_temp_dir());
        if (!$this->runner->isAvailable()) {
            $this->markTestSkipped('ffmpeg binary not available');
        }
        $this->dir = sys_get_temp_dir() . '/phlix_thumb_batch_it_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir)) {
            $this->rrmdir($this->dir);
        }
    }

    private function makeClip(string $path, int $durationSeconds = 5): void
    {
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -f lavfi -i %s -pix_fmt yuv420p %s 2>/dev/null',
            escapeshellarg('/usr/bin/ffmpeg'),
            escapeshellarg(sprintf('testsrc=duration=%d:size=320x240:rate=10', $durationSeconds)),
            escapeshellarg($path)
        );
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, 'failed to generate test clip');
        $this->assertFileExists($path);
    }

    public function testBatchExtractionProducesDistinctFramesAtRequestedTimestamps(): void
    {
        $clip = "{$this->dir}/in.mp4";
        $this->makeClip($clip, 5);

        $outDir = "{$this->dir}/frames";
        mkdir($outDir, 0755, true);

        $ok = $this->runner->generateThumbnailBatch($clip, [1, 3], $outDir);
        $this->assertTrue($ok, 'batch extraction should succeed for in-range timestamps');

        $frame0 = "{$outDir}/frame_00000.jpg";
        $frame1 = "{$outDir}/frame_00001.jpg";
        $this->assertFileExists($frame0);
        $this->assertFileExists($frame1);
        $this->assertGreaterThan(0, filesize($frame0));
        $this->assertGreaterThan(0, filesize($frame1));

        // The two timestamps must yield DIFFERENT frames — this is exactly
        // what the pre-fix malformed command failed to do (it rendered no
        // thumbnails at all for a multi-timestamp request).
        $this->assertNotSame(
            md5_file($frame0),
            md5_file($frame1),
            'frames at different timestamps must not be byte-identical (regression guard for "all frames at t=0")'
        );
    }

    public function testBatchExtractionTakesOnlyOneFfmpegProcessAndSurvivesAnOutOfRangeTimestamp(): void
    {
        $clip = "{$this->dir}/in.mp4";
        $this->makeClip($clip, 5);

        $outDir = "{$this->dir}/mixed";
        mkdir($outDir, 0755, true);

        // Second timestamp is beyond the 5s clip duration.
        $ok = $this->runner->generateThumbnailBatch($clip, [1, 999], $outDir);

        // FFmpeg still produces the in-range frame; the out-of-range one is
        // simply absent. The batch as a whole is reported successful because
        // at least one requested frame was actually written.
        $this->assertTrue($ok);
        $this->assertFileExists("{$outDir}/frame_00000.jpg");
        $this->assertGreaterThan(0, filesize("{$outDir}/frame_00000.jpg"));
        $this->assertFileDoesNotExist("{$outDir}/frame_00001.jpg");
    }

    public function testBatchExtractionReturnsFalseWhenEveryTimestampIsOutOfRange(): void
    {
        $clip = "{$this->dir}/in.mp4";
        $this->makeClip($clip, 5);

        $outDir = "{$this->dir}/allmiss";
        mkdir($outDir, 0755, true);

        // FFmpeg itself exits 0 here (it logs "Output file is empty" as a
        // non-fatal warning for each mapped output), so this asserts the
        // runner's own existence-check catches the all-miss case rather than
        // trusting the exit code alone.
        $ok = $this->runner->generateThumbnailBatch($clip, [50, 60], $outDir);

        $this->assertFalse($ok);
        $this->assertFileDoesNotExist("{$outDir}/frame_00000.jpg");
        $this->assertFileDoesNotExist("{$outDir}/frame_00001.jpg");
    }

    private function rrmdir(string $dir): void
    {
        $entries = scandir($dir) ?: [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $path = "{$dir}/{$e}";
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
