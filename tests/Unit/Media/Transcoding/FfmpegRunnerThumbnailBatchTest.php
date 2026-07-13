<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * Covers the command SHAPE built by {@see FfmpegRunner::buildThumbnailBatchCommand()}
 * / {@see FfmpegRunner::generateThumbnailBatch()} `[S-F19]`.
 *
 * Before SV-0.9's command-shape fix, every per-timestamp `-ss`/`-vframes`/output
 * group was concatenated BEFORE the single shared `-i <input>` — i.e.
 * `ffmpeg -ss T1 -vframes 1 out1 -ss T2 -vframes 1 out2 -i input`, which is
 * malformed (output groups appearing before any `-i` has been declared) and
 * renders no thumbnails at all whenever more than one timestamp is requested.
 * These tests assert the fixed shape: one `-ss <timestamp> -i <input>` pair per
 * timestamp (fast input-side seeking), all declared before any output group,
 * each output pinned back to its own input via an explicit `-map <index>:v:0`.
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

        // Regression guard for the exact S-F19 defect: an output group
        // (`-map`/`-vframes`/frame path) must never appear before the LAST
        // `-i` in the command — that is precisely the malformed shape that
        // rendered zero thumbnails. Assert every `-i` occurs before every
        // `-map` occurrence.
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
