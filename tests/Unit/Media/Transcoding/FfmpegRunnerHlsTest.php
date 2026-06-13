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
        // Defaults to a closed VOD playlist, never an open 'event' (live) one
        // that would make the player report an ever-growing duration.
        $this->assertStringContainsString("-hls_playlist_type 'vod'", $cmd);
        $this->assertStringNotContainsString('event', $cmd);
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

    public function testBuildCmafCommandEmitsDashWithHlsPlaylist(): void
    {
        $cmd = $this->runner()->buildCmafCommand('/in.mkv', '/out', [
            'video_codec' => 'libx264',
            'crf' => 23,
            'audio_codec' => 'aac',
            'segment_seconds' => 6,
        ]);

        // DASH muxer + HLS playlist generation from one encode.
        $this->assertStringContainsString('-f dash', $cmd);
        $this->assertStringContainsString('-hls_playlist 1', $cmd);
        $this->assertStringContainsString('-hls_master_name master.m3u8', $cmd);
        $this->assertStringContainsString('-seg_duration 6', $cmd);
        $this->assertStringContainsString('manifest.mpd', $cmd);
        // CMAF fMP4 segment templates.
        $this->assertStringContainsString('init-$RepresentationID$.m4s', $cmd);
        $this->assertStringContainsString('chunk-$RepresentationID$-$Number%05d$.m4s', $cmd);
        // Explicit mapping: video required, audio optional.
        $this->assertStringContainsString('-map 0:v:0', $cmd);
        $this->assertStringContainsString('-map 0:a:0?', $cmd);
        $this->assertStringContainsString('-c:v libx264', $cmd);
    }

    public function testBuildCmafCommandCopiesCompatibleStreams(): void
    {
        $cmd = $this->runner()->buildCmafCommand('/in.mkv', '/out', [
            'video_codec' => 'copy',
            'audio_codec' => 'copy',
        ]);
        $this->assertStringContainsString('-c:v copy', $cmd);
        $this->assertStringContainsString('-c:a copy', $cmd);
        $this->assertStringContainsString('-f dash', $cmd);
        $this->assertStringNotContainsString('libx264', $cmd);
    }

    public function testBuildCmafCommandForcesBrowserDecodableH264Profile(): void
    {
        // A libx264 re-encode must pin an 8-bit 4:2:0 High@4.1 stream so a
        // 10-bit (High 10) source can't yield an undecodable HLS variant.
        $cmd = $this->runner()->buildCmafCommand('/in.mkv', '/out', [
            'video_codec' => 'libx264',
            'crf' => 23,
            'audio_codec' => 'aac',
        ]);
        $this->assertStringContainsString('-pix_fmt yuv420p', $cmd);
        $this->assertStringContainsString('-profile:v high', $cmd);
        $this->assertStringContainsString('-level 4.1', $cmd);
    }

    public function testBuildCmafCommandHonorsExplicitPixFmtAndProfile(): void
    {
        $cmd = $this->runner()->buildCmafCommand('/in.mkv', '/out', [
            'video_codec' => 'libx264',
            'pix_fmt' => 'yuv420p',
            'profile' => 'main',
            'level' => '4.0',
        ]);
        $this->assertStringContainsString('-profile:v main', $cmd);
        $this->assertStringContainsString('-level 4.0', $cmd);
    }

    public function testBuildCmafCommandCopyPathOmitsPixFmtFlags(): void
    {
        // A direct copy must NOT inject encoder-only flags.
        $cmd = $this->runner()->buildCmafCommand('/in.mkv', '/out', [
            'video_codec' => 'copy',
            'audio_codec' => 'copy',
        ]);
        $this->assertStringNotContainsString('-pix_fmt', $cmd);
        $this->assertStringNotContainsString('-profile:v', $cmd);
    }

    public function testBuildHlsCommandForcesBrowserDecodableH264Profile(): void
    {
        $cmd = $this->runner()->buildHlsCommand('/in.mkv', '/out', [
            'video_codec' => 'libx264',
            'audio_codec' => 'aac',
        ]);
        $this->assertStringContainsString('-pix_fmt yuv420p', $cmd);
        $this->assertStringContainsString('-profile:v high', $cmd);
        $this->assertStringContainsString('-level 4.1', $cmd);
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

    public function testStartCmafTranscodeWithSubtitlesGuardsCompleteOnEncodeFailure(): void
    {
        // End-to-end via the real with-subtitles path using sh stand-ins: a
        // failing CMAF encode must produce .failed, never .complete, even with a
        // (succeeding) subtitle-extract trailing command present.
        $dir = sys_get_temp_dir() . '/phlix_cmaf_subfail_' . uniqid();
        mkdir($dir, 0755, true);

        // Build the full chain exactly as production does, then swap the real
        // CMAF command for `false` to simulate an encode failure deterministically.
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
}
