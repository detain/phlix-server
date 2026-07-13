<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * Covers the command SHAPE built by {@see FfmpegRunner::buildThumbnailBatchCommand()}
 * / {@see FfmpegRunner::generateThumbnailBatch()} `[S-F19]`. These string-shape
 * assertions are the tests that genuinely guard the SV-0.9 command-shape change
 * (the real-ffmpeg integration tests are positive correctness checks, not shape
 * guards — see FfmpegThumbnailBatchTest's docblock).
 *
 * Before SV-0.9's command-shape fix, every per-timestamp `-ss`/`-vframes`/output
 * group was concatenated BEFORE the single shared `-i <input>` — i.e.
 * `ffmpeg -ss T1 -vframes 1 out1 -ss T2 -vframes 1 out2 -i input`, declaring
 * output groups before any `-i`. That worked only via ffmpeg's (undocumented)
 * tolerance of an input specified after the outputs, plus a slow output-side
 * seek; on this box's ffmpeg 6.1.1 it still produced correct frames, so the
 * defect was a not-guaranteed-correct, slow arrangement — not "no thumbnails at
 * all." These tests assert the fixed shape: one `-ss <timestamp> -i <input>`
 * pair per timestamp (fast input-side seeking), all declared before any output
 * group, each output pinned back to its own input via an explicit `-map <index>:v:0`.
 */
class FfmpegRunnerThumbnailBatchTest extends TestCase
{
    private function runner(): FfmpegRunner
    {
        return new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
    }

    public function testBuildThumbnailBatchCommandPairsEachSeekWithItsOwnInput(): void
    {
        $cmd = $this->runner()->buildThumbnailBatchCommand('/in.mkv', [30, 90, 150], '/out');

        // Each timestamp gets its own `-ss <seconds> -i <input>` block — no
        // escapeshellarg() on the numeric (would corrupt %d), input-side seek.
        $this->assertStringContainsString("-ss 30 -i '/in.mkv'", $cmd);
        $this->assertStringContainsString("-ss 90 -i '/in.mkv'", $cmd);
        $this->assertStringContainsString("-ss 150 -i '/in.mkv'", $cmd);
        $this->assertStringNotContainsString("-ss '30'", $cmd);
        $this->assertStringNotContainsString("-ss '90'", $cmd);

        // Every output is pinned to its own input occurrence by index so
        // ffmpeg's default per-output auto-stream-selection can't silently
        // grab a different (or duplicate) timestamp's frame.
        $this->assertStringContainsString("-map 0:v:0 -vframes 1 '/out/frame_00000.jpg'", $cmd);
        $this->assertStringContainsString("-map 1:v:0 -vframes 1 '/out/frame_00001.jpg'", $cmd);
        $this->assertStringContainsString("-map 2:v:0 -vframes 1 '/out/frame_00002.jpg'", $cmd);
    }

    public function testBuildThumbnailBatchCommandDeclaresAllInputsBeforeAnyOutput(): void
    {
        $cmd = $this->runner()->buildThumbnailBatchCommand('/in.mkv', [10, 20], '/out');

        // Regression guard for the exact S-F19 command-shape defect: an output
        // group (`-map`/`-vframes`/frame path) must never appear before the LAST
        // `-i` in the command — that outputs-before-input arrangement is the
        // malformed shape the fix removed (it worked only via ffmpeg's lenient
        // argument reordering + a slow output-side seek, not by documented
        // guarantee). Assert every `-i` occurs before every `-map` occurrence.
        $lastInputPos = strrpos($cmd, ' -i ');
        $firstMapPos = strpos($cmd, ' -map ');
        $this->assertNotFalse($lastInputPos);
        $this->assertNotFalse($firstMapPos);
        $this->assertLessThan(
            $firstMapPos,
            $lastInputPos,
            'every -i must be declared before the first output -map group'
        );

        // Exactly 2 inputs, exactly 2 outputs for 2 timestamps.
        $this->assertSame(2, substr_count($cmd, ' -i '));
        $this->assertSame(2, substr_count($cmd, ' -map '));
        $this->assertSame(2, substr_count($cmd, ' -vframes 1 '));
    }

    public function testBuildThumbnailBatchCommandUsesDistinctTimestampsNotAllZero(): void
    {
        $cmd = $this->runner()->buildThumbnailBatchCommand('/in.mkv', [5, 45], '/out');

        // Regression guard for the sibling escaping bug: distinct requested
        // timestamps must survive as distinct values, never all coerced to 0.
        $this->assertStringContainsString('-ss 5 ', $cmd);
        $this->assertStringContainsString('-ss 45 ', $cmd);
        $this->assertStringNotContainsString('-ss 0 ', $cmd);
    }

    public function testBuildThumbnailBatchCommandReindexesNonSequentialKeys(): void
    {
        // Callers may pass a non-sequential/associative array (e.g. a subset
        // filtered by key); output naming must still be a clean 0..N-1 run.
        $cmd = $this->runner()->buildThumbnailBatchCommand('/in.mkv', [7 => 20, 3 => 40], '/out');

        $this->assertStringContainsString("-ss 20 -i '/in.mkv'", $cmd);
        $this->assertStringContainsString("-map 0:v:0 -vframes 1 '/out/frame_00000.jpg'", $cmd);
        $this->assertStringContainsString("-ss 40 -i '/in.mkv'", $cmd);
        $this->assertStringContainsString("-map 1:v:0 -vframes 1 '/out/frame_00001.jpg'", $cmd);
    }

    public function testGenerateThumbnailBatchReturnsTrueImmediatelyForEmptyTimestamps(): void
    {
        $this->assertTrue($this->runner()->generateThumbnailBatch('/in.mkv', [], '/out'));
    }
}
