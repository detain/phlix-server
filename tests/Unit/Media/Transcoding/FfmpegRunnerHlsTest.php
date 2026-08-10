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

    // S59 REMOVED five `buildCmafCommand()` command-string tests here. That
    // builder — and `startCmafTranscode()` / `startCmafTranscodeWithSubtitles()`
    // with it — was the orphaned linear-CMAF path: zero callers in `src/`, and a
    // `chunk-$RepresentationID$-…m4s` naming scheme no producer or serve path in
    // this codebase has ever used. The live CMAF coverage is
    // `Fmp4SegmentProductionTest` (S56, real ffmpeg + parsed boxes) and
    // `VodMpdSegmentResolutionTest` (S58, real segments + `ffmpeg -i manifest.mpd`).

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

    public function testStartDetachedWritesFailedMarkerWithTrailingCmds(): void
    {
        // Regression for the subtitle-chain precedence bug: a FAILED primary
        // command must write .failed even when trailing (subtitle) commands are
        // present and succeed. The old `cmd && extract || true && touch .complete`
        // chain wrote .complete here; the if/then/else form must not.
        $dir = sys_get_temp_dir() . '/phlix_detached_subfail_' . uniqid();
        mkdir($dir, 0755, true);

        // Primary fails; trailing extract group is the always-succeeding form.
        $this->runner()->startDetached('false', $dir, ['( true ) || true']);

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline && !file_exists("{$dir}/.failed")) {
            usleep(50000);
        }
        $this->assertFileExists("{$dir}/.failed");
        $this->assertFileDoesNotExist("{$dir}/.complete");

        $this->removeDir($dir);
    }

    public function testStartDetachedRunsTrailingCmdsOnlyOnSuccessAndKeepsComplete(): void
    {
        // A SUCCESSFUL primary command writes .complete, then runs the trailing
        // commands; a FAILING trailing command must NOT flip the job to .failed.
        $dir = sys_get_temp_dir() . '/phlix_detached_subok_' . uniqid();
        mkdir($dir, 0755, true);
        $marker = $dir . '/trailing-ran';

        $this->runner()->startDetached(
            'true',
            $dir,
            ['( false ) || true', 'touch ' . escapeshellarg($marker)]
        );

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline && !file_exists("{$dir}/.complete")) {
            usleep(50000);
        }
        $this->assertFileExists("{$dir}/.complete");
        $this->assertFileDoesNotExist("{$dir}/.failed");
        // Trailing command ran (after .complete) despite an earlier failing group.
        $this->assertFileExists($marker);

        $this->removeDir($dir);
    }

    public function testBuildDetachedCommandGuardsCompleteWithIfThenElse(): void
    {
        $cmd = $this->runner()->buildDetachedCommand('SOME_ENCODE', '/out', ['( EXTRACT0 ) || true']);

        // The marker decision is an unambiguous if/then/else keyed on the encode,
        // NOT a `... || true && touch .complete` chain that the extract `|| true`
        // could bridge through on a failed encode.
        $this->assertStringContainsString('if SOME_ENCODE; then touch ', $cmd);
        $this->assertStringContainsString('/out/.complete', $cmd);
        $this->assertStringContainsString('else touch ', $cmd);
        $this->assertStringContainsString('/out/.failed', $cmd);
        $this->assertStringContainsString('fi', $cmd);
        // The extract group lives inside the `then` branch, after `.complete`.
        $this->assertMatchesRegularExpression(
            '/then touch .*\.complete.*; \( EXTRACT0 \) \|\| true.*; else touch .*\.failed/s',
            $cmd
        );
        // The old bridging pattern must be gone.
        $this->assertStringNotContainsString('|| true && touch', $cmd);
    }

    public function testDetachedChainGuardsCompleteOnEncodeFailureWithTrailingExtracts(): void
    {
        // End-to-end through the real detached chain using sh stand-ins: a
        // failing encode must produce .failed, never .complete, even with a
        // (succeeding) subtitle-extract trailing command present.
        // (Named for startCmafTranscodeWithSubtitles() until S59 deleted that
        // orphan; the behaviour under test is buildDetachedCommand()'s, and it
        // is the chain TranscodeManager::ensureHlsJob() still launches.)
        $dir = sys_get_temp_dir() . '/phlix_cmaf_subfail_' . uniqid();
        mkdir($dir, 0755, true);

        // Build the full chain exactly as production does, then swap the real
        // encode command for `false` to simulate a failure deterministically.
        $runner = $this->runner();
        $extract = '( true ) || true';
        $full = $runner->buildDetachedCommand('false', $dir, [$extract]);
        shell_exec($full);

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline && !file_exists("{$dir}/.failed")) {
            usleep(50000);
        }
        $this->assertFileExists("{$dir}/.failed");
        $this->assertFileDoesNotExist("{$dir}/.complete");

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

    public function testBuildSegmentCommandFastSeeksAndAnchorsTimeline(): void
    {
        $cmd = $this->runner()->buildSegmentCommand('/in.mkv', '/out/seg-00130.ts', 780.0, 6.0, [
            'video_codec' => 'libx264',
            'preset' => 'veryfast',
            'crf' => 23,
            'pix_fmt' => 'yuv420p',
            'profile' => 'high',
            'level' => '4.1',
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
            'audio_channels' => 2,
        ]);

        // Accurate fast INPUT seek (before -i) to the segment start.
        $this->assertMatchesRegularExpression('/-ss 780 -i /', $cmd);
        $this->assertStringContainsString('-t 6', $cmd);
        // First output frame is an IDR → independently decodable segment.
        $this->assertStringContainsString("-force_key_frames 'expr:eq(n,0)'", $cmd);
        // ...and ONLY the first: a time predicate like `gte(t,0)` matches every frame
        // and silently forces an all-intra encode (gross artefacts under the VBV cap).
        $this->assertStringNotContainsString('gte(t,0)', $cmd);
        // PTS anchored to the absolute timeline position so segments stitch + a seek lands right.
        $this->assertStringContainsString('-output_ts_offset 780', $cmd);
        // Browser-decodable encode + MPEG-TS output.
        $this->assertStringContainsString('-c:v libx264', $cmd);
        $this->assertStringContainsString('-pix_fmt yuv420p', $cmd);
        $this->assertStringContainsString('-c:a aac', $cmd);
        $this->assertStringContainsString('-f mpegts', $cmd);
        $this->assertStringContainsString("'/out/seg-00130.ts'", $cmd);
    }

    public function testBuildSegmentCommandAppliesPerRungCappedCrf(): void
    {
        // A 1080p ABR rung: capped-CRF (quality-driven encode with a hard VBV ceiling
        // from Rendition::maxrate()/bufsize()), the rung downscale, and the rung level.
        $cmd = $this->runner()->buildSegmentCommand('/in.mkv', '/out/seg-00130.ts', 780.0, 6.0, [
            'video_codec' => 'libx264',
            'preset' => 'veryfast',
            'crf' => 23,
            'video_bitrate' => 5000000,
            'maxrate' => 5350000,
            'bufsize' => 10700000,
            'width' => 1920,
            'height' => 1080,
            'level' => '4.1',
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
            'audio_channels' => 2,
        ]);

        $this->assertStringContainsString('-crf 23', $cmd);
        $this->assertStringContainsString('-preset veryfast', $cmd);
        // Hard VBV ceiling from the rung — the cap is maxrate/bufsize, never a bare -b:v.
        $this->assertStringContainsString('-maxrate 5350000', $cmd);
        $this->assertStringContainsString('-bufsize 10700000', $cmd);
        $this->assertStringNotContainsString('-b:v', $cmd);
        $this->assertStringContainsString('scale=1920:1080:force_original_aspect_ratio=decrease', $cmd);
        $this->assertStringContainsString('-level 4.1', $cmd);
    }

    public function testBuildSegmentCommandHonorsPerRungLevelAndScaleForLowRung(): void
    {
        // A 240p rung: its own scale/cap/level flow through independently of any other rung.
        $cmd = $this->runner()->buildSegmentCommand('/in.mkv', '/out/seg-00130.ts', 780.0, 6.0, [
            'video_codec' => 'libx264',
            'maxrate' => 428000,
            'bufsize' => 856000,
            'width' => 426,
            'height' => 240,
            'level' => '3.0',
        ]);

        $this->assertStringContainsString('-maxrate 428000', $cmd);
        $this->assertStringContainsString('-bufsize 856000', $cmd);
        $this->assertStringContainsString('scale=426:240:force_original_aspect_ratio=decrease', $cmd);
        $this->assertStringContainsString('-level 3.0', $cmd);
        $this->assertStringContainsString('-crf 23', $cmd);
    }

    public function testBuildSegmentCommandCappedFlagsAbsentWhenNotRequested(): void
    {
        // Backward-compat: no maxrate/bufsize params → CRF-only, exactly like before.
        $cmd = $this->runner()->buildSegmentCommand('/in.mkv', '/out/seg-00000.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
            'crf' => 23,
        ]);

        $this->assertStringContainsString('-crf 23', $cmd);
        $this->assertStringNotContainsString('-maxrate', $cmd);
        $this->assertStringNotContainsString('-bufsize', $cmd);
        $this->assertStringNotContainsString('-b:v', $cmd);
    }

    public function testBuildSegmentCommandStreamCopiesVideoForOriginal(): void
    {
        // Genuine "Original" passthrough: -c:v copy, NO encoder/scale/keyframe flags.
        $cmd = $this->runner()->buildSegmentCommand('/in.mkv', '/out/seg-00000.ts', 0.0, 6.0, [
            'video_codec' => 'copy',
            'audio_codec' => 'copy',
        ]);

        $this->assertStringContainsString('-c:v copy', $cmd);
        // A stream copy cannot synthesise a keyframe mid-GOP, so no force_key_frames.
        $this->assertStringNotContainsString('-force_key_frames', $cmd);
        // No encoder-only / scale / cap flags leak into a pure copy.
        $this->assertStringNotContainsString('-crf', $cmd);
        $this->assertStringNotContainsString('-preset', $cmd);
        $this->assertStringNotContainsString('-maxrate', $cmd);
        $this->assertStringNotContainsString('-bufsize', $cmd);
        $this->assertStringNotContainsString('scale=', $cmd);
        $this->assertStringNotContainsString('libx264', $cmd);
        // PTS anchoring still applies to a copy segment.
        $this->assertStringContainsString('-output_ts_offset 0', $cmd);
        $this->assertStringContainsString('-muxdelay 0 -muxpreload 0', $cmd);
        $this->assertStringContainsString('-f mpegts', $cmd);
    }

    public function testBuildSegmentCommandStreamCopiesAudio(): void
    {
        // Genuine audio passthrough: -c:a copy, no bitrate/sample-rate/channel flags.
        $cmd = $this->runner()->buildSegmentCommand('/in.mkv', '/out/seg-00000.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
            'audio_codec' => 'copy',
        ]);

        $this->assertStringContainsString('-c:a copy', $cmd);
        $this->assertStringNotContainsString('-b:a', $cmd);
        $this->assertStringNotContainsString('-ar', $cmd);
        $this->assertStringNotContainsString('-ac', $cmd);
    }

    public function testBuildSegmentCommandForcesStereoOnAacReencodeWithoutChannels(): void
    {
        // Browser-safe audio: a 6-channel (5.1(side)) AC-3 source re-encoded to AAC
        // with NO audio_channels pinned must be forced to stereo (-ac 2), otherwise
        // the native aac encoder emits channel_configuration=0 (PCE) that hls.js
        // cannot parse — breaking the audio SourceBuffer and the whole player load.
        $cmd = $this->runner()->buildSegmentCommand('/in.mkv', '/out/seg-00000.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
            'crf' => 23,
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
            // deliberately NO audio_channels — mirrors segmentParamsForRendition() legacy
        ]);

        $this->assertStringContainsString('-c:a aac', $cmd);
        $this->assertStringContainsString('-ac 2', $cmd);
    }

    public function testBuildSegmentCommandClampsSurroundChannelsToStereoOnReencode(): void
    {
        // Even when a producer explicitly requests a surround layout (6ch), the
        // re-encode is clamped to browser-safe stereo.
        $cmd = $this->runner()->buildSegmentCommand('/in.mkv', '/out/seg-00000.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
            'audio_channels' => 6,
        ]);

        $this->assertStringContainsString('-ac 2', $cmd);
        $this->assertStringNotContainsString('-ac 6', $cmd);
    }

    public function testBuildSegmentCommandKeepsPinnedStereoOnReencode(): void
    {
        // An already-stereo pin is preserved unchanged.
        $cmd = $this->runner()->buildSegmentCommand('/in.mkv', '/out/seg-00000.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
            'audio_codec' => 'aac',
            'audio_channels' => 2,
        ]);

        $this->assertStringContainsString('-ac 2', $cmd);
    }

    public function testBuildSegmentCommandNeverEmitsAcOnAudioCopy(): void
    {
        // The copy (direct-play) path must never carry -ac — the source layout is
        // passed through untouched.
        $cmd = $this->runner()->buildSegmentCommand('/in.mkv', '/out/seg-00000.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
            'audio_codec' => 'copy',
            'audio_channels' => 6,
        ]);

        $this->assertStringContainsString('-c:a copy', $cmd);
        $this->assertStringNotContainsString('-ac', $cmd);
    }

    public function testBuildAudioSegmentCommandForcesStereoOnReencodeWithoutChannels(): void
    {
        // The audio-only rendition builder shares the same browser-safe default.
        $cmd = $this->runner()->buildAudioSegmentCommand('/in.mkv', '/out/seg-a0-00000.ts', 0.0, 6.0, [
            'audio_stream_index' => 0,
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
        ]);

        $this->assertStringContainsString('-c:a aac', $cmd);
        $this->assertStringContainsString('-ac 2', $cmd);
    }

    public function testBuildSegmentCommandMixedVideoReencodeAudioCopy(): void
    {
        // The caller pins video re-encode + audio copy; each stream's codec decision
        // is independent, so an AAC-safe source can keep its audio while video encodes.
        $cmd = $this->runner()->buildSegmentCommand('/in.mkv', '/out/seg-00000.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
            'crf' => 23,
            'audio_codec' => 'copy',
        ]);

        $this->assertStringContainsString('-c:v libx264', $cmd);
        $this->assertStringContainsString('-force_key_frames', $cmd);
        $this->assertStringContainsString('-c:a copy', $cmd);
        $this->assertStringNotContainsString('-c:v copy', $cmd);
        $this->assertStringNotContainsString('-c:a aac', $cmd);
    }

    public function testBuildSegmentCommandMixedVideoCopyAudioReencode(): void
    {
        // H.264 source with non-AAC audio → copy the compatible video, re-encode audio.
        $cmd = $this->runner()->buildSegmentCommand('/in.mkv', '/out/seg-00000.ts', 0.0, 6.0, [
            'video_codec' => 'copy',
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
        ]);

        $this->assertStringContainsString('-c:v copy', $cmd);
        $this->assertStringNotContainsString('-force_key_frames', $cmd);
        $this->assertStringContainsString('-c:a aac', $cmd);
        $this->assertStringContainsString('-b:a 128k', $cmd);
        $this->assertStringNotContainsString('-c:a copy', $cmd);
    }

    public function testBuildAudioSegmentCommandIsGenuinelyAudioOnly(): void
    {
        // P3B multi-audio: an audio rendition segment is -vn (NO video decode/encode
        // of any kind), maps the AUDIO-RELATIVE stream index, encodes AAC, and keeps
        // the exact -ss/-t/-output_ts_offset framing of the video segments.
        $cmd = $this->runner()->buildAudioSegmentCommand('/in.mkv', '/out/seg-a1-00130.ts', 780.0, 6.0, [
            'audio_stream_index' => 1,
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
        ]);

        $this->assertStringContainsString(' -vn', $cmd);
        $this->assertStringContainsString('-map 0:a:1', $cmd);
        $this->assertStringContainsString('-c:a aac', $cmd);
        $this->assertStringContainsString('-b:a 128k', $cmd);
        // NO video codec / encoder / mapping flags at all.
        $this->assertStringNotContainsString('-c:v', $cmd);
        $this->assertStringNotContainsString('libx264', $cmd);
        $this->assertStringNotContainsString('-map 0:v', $cmd);
        $this->assertStringNotContainsString('-force_key_frames', $cmd);
        $this->assertStringNotContainsString('scale=', $cmd);
        // Same segment framing as the video segments (shared VOD timeline).
        $this->assertStringContainsString('-ss 780 -i ', $cmd);
        $this->assertStringContainsString('-t 6', $cmd);
        $this->assertStringContainsString('-output_ts_offset 780', $cmd);
        $this->assertStringContainsString('-muxdelay 0 -muxpreload 0', $cmd);
        $this->assertStringContainsString('-f mpegts', $cmd);
    }

    public function testBuildAudioSegmentCommandDefaultsAndRefusesCopy(): void
    {
        // Defaults: first audio track, AAC 128k. A 'copy' request is upgraded to AAC
        // so the rendition always matches the advertised mp4a.40.2.
        $cmd = $this->runner()->buildAudioSegmentCommand('/in.mkv', '/out/seg-a0-00000.ts', 0.0, 6.0, [
            'audio_codec' => 'copy',
        ]);

        $this->assertStringContainsString('-map 0:a:0', $cmd);
        $this->assertStringContainsString('-c:a aac', $cmd);
        $this->assertStringContainsString('-b:a 128k', $cmd);
        $this->assertStringNotContainsString('-c:a copy', $cmd);
    }

    public function testBuildSegmentCommandVideoOnlyDropsAudio(): void
    {
        // With a shared audio group in the master, video variant segments carry NO
        // audio (-an, no audio map/codec flags) — sound plays from the audio renditions.
        $cmd = $this->runner()->buildSegmentCommand('/in.mkv', '/out/seg-v480p-00000.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
            'crf' => 23,
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
            'video_only' => true,
        ]);

        $this->assertStringContainsString(' -an', $cmd);
        $this->assertStringContainsString('-c:v libx264', $cmd);
        $this->assertStringNotContainsString('-map 0:a', $cmd);
        $this->assertStringNotContainsString('-c:a', $cmd);
        $this->assertStringNotContainsString('-b:a', $cmd);
    }

    public function testBuildSegmentCommandVideoOnlyAppliesToCopyToo(): void
    {
        // A stream-copy "Original" under an audio group is also video-only.
        $cmd = $this->runner()->buildSegmentCommand('/in.mkv', '/out/seg-voriginal-00000.ts', 0.0, 6.0, [
            'video_codec' => 'copy',
            'audio_codec' => 'copy',
            'video_only' => true,
        ]);

        $this->assertStringContainsString('-c:v copy', $cmd);
        $this->assertStringContainsString(' -an', $cmd);
        $this->assertStringNotContainsString('-map 0:a', $cmd);
        $this->assertStringNotContainsString('-c:a', $cmd);
    }

    public function testBuildSegmentCommandBoundaryFlagsIdenticalAcrossRungs(): void
    {
        // Seamless ABR switching demands that the segment framing (keyframe expr, PTS
        // anchor, mux pre-roll, window length) is byte-identical across every rung;
        // only scale/bitrate/level differ.
        $common = [
            'video_codec' => 'libx264',
            'preset' => 'veryfast',
            'crf' => 23,
        ];
        $rung480 = $this->runner()->buildSegmentCommand('/in.mkv', '/out/seg-00130.ts', 780.0, 6.0, $common + [
            'maxrate' => 1498000,
            'bufsize' => 2996000,
            'width' => 854,
            'height' => 480,
            'level' => '3.1',
        ]);
        $rung1080 = $this->runner()->buildSegmentCommand('/in.mkv', '/out/seg-00130.ts', 780.0, 6.0, $common + [
            'maxrate' => 5350000,
            'bufsize' => 10700000,
            'width' => 1920,
            'height' => 1080,
            'level' => '4.1',
        ]);

        // Boundary / PTS / framing flags are IDENTICAL across the two rungs.
        foreach (
            [
                "-force_key_frames 'expr:eq(n,0)'",
                '-output_ts_offset 780',
                '-muxdelay 0 -muxpreload 0',
                '-ss 780 -i ',
                '-t 6',
            ] as $shared
        ) {
            $this->assertStringContainsString($shared, $rung480);
            $this->assertStringContainsString($shared, $rung1080);
        }

        // ...and ONLY the scale/bitrate/level differ.
        $this->assertStringContainsString('scale=854:480', $rung480);
        $this->assertStringContainsString('scale=1920:1080', $rung1080);
        $this->assertStringContainsString('-maxrate 1498000', $rung480);
        $this->assertStringContainsString('-maxrate 5350000', $rung1080);
        $this->assertStringContainsString('-level 3.1', $rung480);
        $this->assertStringContainsString('-level 4.1', $rung1080);
    }

    public function testBuildSegmentCommandFormatsFractionalTimes(): void
    {
        // The trailing segment is shorter than a full segment; times must be plain
        // decimals (no scientific notation) that ffmpeg accepts.
        $cmd = $this->runner()->buildSegmentCommand('/in.mkv', '/out/seg-00237.ts', 1422.0, 1.4, [
            'video_codec' => 'libx264',
        ]);

        $this->assertStringContainsString('-ss 1422 -i ', $cmd);
        $this->assertStringContainsString('-t 1.4', $cmd);
        $this->assertStringContainsString('-output_ts_offset 1422', $cmd);
    }
}
