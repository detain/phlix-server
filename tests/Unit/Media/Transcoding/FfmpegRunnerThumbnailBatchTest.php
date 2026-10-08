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
 *
 * The `[S-F19-mjpeg]` cases below guard the SECOND S-F19-era contract: every
 * mjpeg/image2 output this class builds carries `-strict unofficial`, because
 * ffmpeg 7.1+ turns the mjpeg encoder's limited-range-YUV complaint into a
 * FATAL encoder-open error — an out-of-range `-ss` (empty output at the EOF
 * flush) then aborts the whole process with exit 234 instead of the documented
 * partial success (exit 0, reachable frames written, unreachable ones absent).
 * The scope law has a pinned NEGATIVE half too: no segment/audio encode command
 * ever carries the flag, so the relaxation cannot leak into streaming encodes.
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

    // ─────────────────────────────────────────────────────────────────────
    // [S-F19-mjpeg] The mjpeg strictness scope law.
    //
    // ffmpeg >= 7.1 aborts (exit 234, "Non full-range YUV is non-standard") at
    // the encoder-open of any image2/mjpeg output that flushes EMPTY — which is
    // exactly what an out-of-range `-ss` produces. Measured 2026-10-08 against
    // BtbN master (N-127252) with these command shapes: pre-fix mixed batch
    // [1,999] exits 234 with frame_00000 aborted mid-run; with `-strict
    // unofficial` per output it exits 0 and the documented partial-success
    // contract holds (frame_00000 written, frame_00001 absent). Pre-7.1 builds
    // (6.1.1 host, 6.1.3 BtbN) exit 0 either way — the flag is inert there.
    // ─────────────────────────────────────────────────────────────────────

    public function testEveryBatchOutputGroupCarriesTheMjpegStrictnessFlag(): void
    {
        $cmd = $this->runner()->buildThumbnailBatchCommand('/in.mkv', [30, 90, 150], '/out');

        // One flag occurrence per mjpeg output — no more, no less.
        $this->assertSame(3, substr_count($cmd, ' -strict unofficial -map '));

        // The flag leads each output group (output options bind to the filename
        // that follows), so every reachable AND every empty output opens its
        // mjpeg encoder in unofficial compliance.
        $this->assertStringContainsString(
            "-strict unofficial -map 0:v:0 -vframes 1 '/out/frame_00000.jpg'",
            $cmd
        );
        $this->assertStringContainsString(
            "-strict unofficial -map 2:v:0 -vframes 1 '/out/frame_00002.jpg'",
            $cmd
        );

        // Output-scoped placement law: the flag sits AFTER every input declaration
        // (so it binds per-output) — never as a global option ahead of the inputs,
        // and never inside an input group.
        $lastInputPos = strrpos($cmd, ' -i ');
        $flagPos = strpos($cmd, ' -strict ');
        $this->assertNotFalse($lastInputPos);
        $this->assertNotFalse($flagPos);
        $this->assertGreaterThan($lastInputPos, $flagPos);
    }

    public function testSingleThumbnailCommandCarriesTheMjpegStrictnessFlag(): void
    {
        $cmd = $this->runner()->buildThumbnailCommand('/in.mkv', '/out/thumb.jpg', 30);

        $this->assertSame(
            "'/usr/bin/ffmpeg' -y -hide_banner -loglevel error -i '/in.mkv'"
            . " -ss 30 -vframes 1 -q:v 2 -strict unofficial -f image2 '/out/thumb.jpg'",
            $cmd
        );
    }

    public function testTrickplaySpriteCommandCarriesTheMjpegStrictnessFlag(): void
    {
        $vf = 'fps=1/0.083,scale=160:90,tile=6x10:margin=2:padding=1';
        $cmd = $this->runner()->buildTrickplaySpriteCommand('/in.mkv', '/out/sprite.jpg', $vf, '0.042');

        // Sheet is an mjpeg output: flag sits between -frames:v 1 and the path.
        $this->assertStringContainsString("-frames:v 1 -strict unofficial '/out/sprite.jpg'", $cmd);
        $this->assertSame(1, substr_count($cmd, '-strict unofficial'));
    }

    public function testBifFramesCommandCarriesTheMjpegStrictnessFlag(): void
    {
        $cmd = $this->runner()->buildBifFramesCommand('/in.mkv', '/out/bif_%05d.jpg', '0.042', '0.083', 320, 60);

        $this->assertStringContainsString("-qscale:v 4 -strict unofficial '/out/bif_%05d.jpg'", $cmd);
        $this->assertSame(1, substr_count($cmd, '-strict unofficial'));
    }

    public function testSegmentAndAudioCommandsNeverCarryTheStrictnessFlag(): void
    {
        // The negative half of the scope law: `-strict` downgrades compliance
        // checks, so it must be structurally absent from every streaming-encode
        // command — a future flag move to a global position reddens these.
        $params = [
            'video_codec' => 'libx264',
            'preset' => 'veryfast',
            'crf' => 23,
            'width' => 1280,
            'height' => 720,
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
        ];
        $video = $this->runner()->buildSegmentCommand('/in.mkv', '/out/seg-00001.ts', 10.0, 6.0, $params);
        $this->assertStringNotContainsString('-strict', $video);
        $this->assertStringNotContainsString('image2', $video);

        $audio = $this->runner()->buildAudioSegmentCommand('/in.mkv', '/out/seg-a0-00001.ts', 10.0, 6.0, [
            'audio_only' => true,
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
        ]);
        $this->assertStringNotContainsString('-strict', $audio);
    }
}
