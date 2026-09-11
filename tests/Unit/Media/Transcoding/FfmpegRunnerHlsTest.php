<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;
use Throwable;

/**
 * Covers the HLS-muxing additions to {@see FfmpegRunner}: the native-HLS command
 * builder and the detached-launch / process-probe helpers.
 *
 * S460 — detached-launch cleanup is race-free. These tests launch real `nohup`
 * wrappers that write `.complete` / `.failed` markers (and, for the trailing-
 * command cases, post-marker files) into a scratch dir under sys temp. The old
 * shape polled nothing before its final assertion and cleaned up inline, so a
 * mid-test failure skipped the cleanup entirely and the S439 zero-residue census
 * failed the whole SUITE for reasons the failing test already named — the W59
 * master-CI crash. Now: every expected post-launch file is awaited through a
 * bounded poll that fails loudly and by name ({@see waitForDetachedFile()}),
 * every scratch dir is registered in {@see $scratchDirs}, and removal happens
 * in tearDown — which runs on every exit path (pass, failure, exception) — only
 * after the wrapper process itself is gone.
 */
class FfmpegRunnerHlsTest extends TestCase
{
    /**
     * Bounded wait for a detached wrapper to write an expected file. The
     * commands under test complete in ≤0.2 s; 15 s is scheduler headroom for
     * 8-way paraunit load, not a licence to hang.
     */
    private const DETACHED_FILE_TIMEOUT_SECONDS = 15.0;

    /** Bounded wait in tearDown for the wrapper process to exit before removal. */
    private const DETACHED_EXIT_TIMEOUT_SECONDS = 10.0;

    /** Poll interval shared by both bounded waits, in microseconds. */
    private const DETACHED_POLL_INTERVAL_US = 20000;

    /** Attempts removeDir() makes before declaring a scratch dir unremovable. */
    private const REMOVE_DIR_ATTEMPTS = 3;

    /**
     * Lane sentinel (P-1, same shape as ZeroResidueCensusTest::LANE_SENTINEL):
     * lives only in executable code and in failure messages built from it, never
     * in any *.md file. It is what the merge ritual's tokenized-source proof greps.
     */
    private const S460_LANE_SENTINEL = 'S460DETACHEDPOLLX9K1';

    /**
     * Scratch dirs minted by the current test → pid of the detached wrapper
     * launched into it (null until a launch is tracked). tearDown drains it.
     *
     * @var array<string, int|null>
     */
    private array $scratchDirs = [];

    /**
     * Drains every scratch dir registered by the test that just ran — on the pass
     * path AND on every failure/exception path (PHPUnit always calls tearDown).
     * Each dir is emptied only after its tracked wrapper process is gone, so no
     * late marker/post-marker write can land in a dir we are removing; anything
     * that survives the bounded retries fails loudly here, naming the lane, and
     * the suite-level census remains the outer backstop.
     */
    protected function tearDown(): void
    {
        $registered = $this->scratchDirs;
        $this->scratchDirs = [];

        $survivors = [];
        foreach ($registered as $dir => $pid) {
            $note = '';
            try {
                if (is_int($pid) && $pid > 0) {
                    if ($this->awaitDetachedProcessExit($pid)) {
                        $note = sprintf(
                            ' [a process with the tracked wrapper pid %d was still running (live, not zombie) at the exit deadline]',
                            $pid,
                        );
                    }
                } else {
                    $note = ' [no positive wrapper pid was tracked, so no exit wait was possible for this dir]';
                }
                $this->removeDir($dir);
                if (is_dir($dir)) {
                    $survivors[] = $dir . $note;
                }
            } catch (Throwable $t) {
                // Per-dir isolation (S460 review, F5): one poisoned dir must never
                // strand the rest of the registry — and a raw throw escaping here
                // would be swallowed silently by PHPUnit whenever the test body
                // already failed (TestCase.php:798 only records it if !$e), so the
                // leak would surface only as an anonymous census failure later.
                // Reporting it through $survivors keeps it loud and attributed.
                $survivors[] = $dir . ' [drain threw: ' . $t->getMessage() . ']';
            }
        }

        parent::tearDown();

        if ($survivors !== []) {
            // TRUE for every input reaching it: each listed path was observed to
            // still be a directory immediately before this line executed, and each
            // per-dir note states exactly what remediation that dir actually got.
            $this->fail(sprintf(
                '%s CLEANUP FAILED: scratch dirs were not removed by the bounded teardown: %s',
                self::S460_LANE_SENTINEL,
                implode(', ', $survivors),
            ));
        }
    }

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
        $dir = $this->makeScratchDir('phlix_detached_');

        // A trivial backgrounded command: returns a real pid, writes .complete.
        $pid = $this->runner()->startDetached('sleep 0.2', $dir);
        $this->trackDetachedPid($dir, $pid);

        $this->assertGreaterThan(0, $pid);

        // Bounded wait for the completion marker the wrapper writes on success.
        $this->waitForDetachedFile($dir, '.complete');
        $this->assertFileDoesNotExist("{$dir}/.failed");

        // Removal lives in tearDown (S460): it runs on every exit path, after the
        // wrapper is gone — no inline removeDir racing a still-live writer.
    }

    public function testStartDetachedWritesFailedMarkerOnNonZeroExit(): void
    {
        $dir = $this->makeScratchDir('phlix_detached_fail_');

        $pid = $this->runner()->startDetached('false', $dir);
        $this->trackDetachedPid($dir, $pid);

        $this->waitForDetachedFile($dir, '.failed');
    }

    public function testStartDetachedWritesFailedMarkerWithTrailingCmds(): void
    {
        // Regression for the subtitle-chain precedence bug: a FAILED primary
        // command must write .failed even when trailing (subtitle) commands are
        // present and succeed. The old `cmd && extract || true && touch .complete`
        // chain wrote .complete here; the if/then/else form must not.
        $dir = $this->makeScratchDir('phlix_detached_subfail_');

        // Primary fails; trailing extract group is the always-succeeding form.
        $pid = $this->runner()->startDetached('false', $dir, ['( true ) || true']);
        $this->trackDetachedPid($dir, $pid);

        $this->waitForDetachedFile($dir, '.failed');
        // The if/then/else is branch-exclusive: once `.failed` exists the wrapper
        // has taken the else-arm and no later write can create `.complete`.
        $this->assertFileDoesNotExist("{$dir}/.complete");
    }

    public function testStartDetachedRunsTrailingCmdsOnlyOnSuccessAndKeepsComplete(): void
    {
        // A SUCCESSFUL primary command writes .complete, then runs the trailing
        // commands; a FAILING trailing command must NOT flip the job to .failed.
        $dir = $this->makeScratchDir('phlix_detached_subok_');
        $marker = $dir . '/trailing-ran';

        $pid = $this->runner()->startDetached(
            'true',
            $dir,
            ['( false ) || true', 'touch ' . escapeshellarg($marker)]
        );
        $this->trackDetachedPid($dir, $pid);

        $this->waitForDetachedFile($dir, '.complete');
        // The trailing `touch` lands AFTER `.complete` — the old bare
        // assertFileExists($marker) right after the marker poll raced it and the
        // resulting mid-test failure leaked the dir into the S439 census (W59).
        // Await the post-marker file through the same bounded poll instead.
        $this->waitForDetachedFile($dir, 'trailing-ran');
        $this->assertFileDoesNotExist("{$dir}/.failed");
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
        $dir = $this->makeScratchDir('phlix_cmaf_subfail_');

        // Build the full chain exactly as production does, then swap the real
        // encode command for `false` to simulate a failure deterministically.
        $runner = $this->runner();
        $extract = '( true ) || true';
        $full = $runner->buildDetachedCommand('false', $dir, [$extract]);

        // The launch string ends in `& echo $!`, so shell_exec returns the
        // wrapper's pid — track it so tearDown waits for the writer to exit.
        $launchOut = shell_exec($full);
        $this->trackDetachedPid($dir, is_string($launchOut) ? (int) trim($launchOut) : 0);

        $this->waitForDetachedFile($dir, '.failed');
        // Branch-exclusive chain: `.failed` present ⇒ `.complete` can never follow.
        $this->assertFileDoesNotExist("{$dir}/.complete");
    }

    public function testIsProcessRunningForSelfAndBogusPid(): void
    {
        $runner = $this->runner();
        $this->assertTrue($runner->isProcessRunning(getmypid() ?: 1));
        $this->assertFalse($runner->isProcessRunning(0));
        $this->assertFalse($runner->isProcessRunning(-5));
    }

    /**
     * Mints a `phlix_*` scratch dir under sys temp and registers it so tearDown
     * removes it on EVERY exit path (S460) — a mid-test failure can no longer
     * strand residue for the S439 census to attribute to the whole suite.
     */
    private function makeScratchDir(string $prefix): string
    {
        // Random suffix, not uniqid(): two paraunit workers minting in the same
        // microsecond on one host would otherwise collide on one dir, and the
        // loser's tearDown would rmdir the winner's live scratch.
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8));

        // Register before mkdir: a partially-created path is still residue and
        // must still be drained by tearDown.
        $this->scratchDirs[$dir] = null;

        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $this->fail(sprintf(
                '%s SETUP FAILED: could not create scratch dir %s',
                self::S460_LANE_SENTINEL,
                $dir,
            ));
        }

        return $dir;
    }

    /**
     * Attaches the wrapper pid reported by the launch to its registered dir, so
     * tearDown can wait for the writer to exit before removing it. A pid ≤ 0
     * means the launch produced nothing usable: it is stored, never waited on,
     * and the timeout message reports it as untracked (never "probed and dead").
     */
    private function trackDetachedPid(string $dir, int $pid): void
    {
        if (!array_key_exists($dir, $this->scratchDirs)) {
            $this->fail(sprintf(
                '%s BOOKKEEPING ERROR: pid tracked for unregistered dir %s',
                self::S460_LANE_SENTINEL,
                $dir,
            ));
        }

        $this->scratchDirs[$dir] = $pid;
    }

    /**
     * Bounded poll for one file the detached wrapper is expected to write.
     *
     * Loud, named, and TRUE for every input that reaches the timeout: each
     * interpolated clause is re-observed at failure time, and none of them
     * claims a cause the observation cannot support.
     */
    private function waitForDetachedFile(string $dir, string $name): void
    {
        $path = $dir . '/' . $name;
        $deadline = microtime(true) + self::DETACHED_FILE_TIMEOUT_SECONDS;

        while (!file_exists($path)) {
            if (microtime(true) >= $deadline) {
                break;
            }
            usleep(self::DETACHED_POLL_INTERVAL_US);
        }

        if (!file_exists($path)) {
            // Timeout arm: every clause below is re-observed right here, so the
            // message is true for ALL inputs reaching it — it never blames a cause
            // the observations cannot support (S345 rule 1).
            // startDetached()'s contract: 0 means the launch produced no usable
            // pid, so pid<=0 is reported as "not tracked" — the alive probe is
            // only ever claimed to have RUN when a positive pid exists (S345 r1).
            $pid = $this->scratchDirs[$dir] ?? null;
            $tracked = is_int($pid) && $pid > 0 ? (string) $pid : 'not tracked';
            $alive = is_int($pid) && $pid > 0 && $this->wrapperMayStillWrite($pid);
            $probe = $tracked === 'not tracked'
                ? 'not run (no positive pid was tracked for this dir, so the wrapper fate is unknown)'
                : ($alive
                    ? 'yes — a live (non-zombie) process still holds that pid and may produce the file'
                    : 'no — no live, non-zombie process holds that pid');

            // Awaited name is excluded from the listing: between the absence check
            // above and this glob a genuinely-slow writer could create it, and the
            // message must never contradict its own "was not present" claim (F3).
            $entries = array_values(array_filter(
                array_map('basename', glob("{$dir}/*") ?: []),
                static fn (string $entry): bool => $entry !== $name,
            ));
            foreach (['.complete', '.failed'] as $marker) {
                if ($marker !== $name && is_file("{$dir}/{$marker}")) {
                    $entries[] = $marker;
                }
            }
            $listing = $entries === [] ? 'none' : implode(', ', $entries);

            $this->fail(sprintf(
                '%s DETACHED-MARKER TIMEOUT: "%s" was not present in %s after %.1f s. '
                . 'Observed at timeout — dir: %s; wrapper pid: %s; process-alive probe: %s; other entries: %s.',
                self::S460_LANE_SENTINEL,
                $name,
                $dir,
                self::DETACHED_FILE_TIMEOUT_SECONDS,
                is_dir($dir) ? 'exists' : 'does NOT exist',
                $tracked,
                $probe,
                $listing,
            ));
        }

        // Success arm: registered as an assertion so every caller performs at
        // least one PHPUnit-visible assertion (failOnRisky would flag otherwise).
        $this->assertFileExists($path);
    }

    /**
     * WRITER-liveness, not bare pid-liveness: the tracked wrapper is orphaned the
     * moment the launching shell exits, and on a non-reaping container init (no
     * --init/sandboxee — a common way to run this suite) its corpse lingers as a
     * ZOMBIE that both posix_kill($pid, 0) and /proc/{pid} report as "alive" even
     * though every write it will ever do has happened. Trusting the raw probe
     * there would burn DETACHED_EXIT_TIMEOUT_SECONDS per dir on every HEALTHY run
     * and let the stuck-writer note assert something false (S460 review, F1).
     * A zombie is therefore treated as exited; where procfs does not exist at all
     * we fall back to the production probe rather than guess.
     */
    private function wrapperMayStillWrite(int $pid): bool
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");

        if (!is_string($stat)) {
            return $this->runner()->isProcessRunning($pid);
        }

        // Field 3 of /proc/pid/stat is the state char, after the LAST ')' — the
        // comm field (2) may itself contain spaces and parentheses.
        if (preg_match('/.*\) (\S)/', $stat, $m) !== 1) {
            return $this->runner()->isProcessRunning($pid);
        }

        return $m[1] !== 'Z';
    }

    /**
     * Bounded wait in tearDown for the wrapper process to exit. Every file the
     * chain can write lands before the tracked pid exits (and a zombie has by
     * definition written everything), so once it is gone the dir is final and
     * removeDir() cannot race a late write. Returns true only when a live,
     * non-zombie process still held the pid at the deadline — the caller proceeds
     * with removal and reports it loudly if removal then leaves a survivor; the
     * S439 census stays the outer backstop either way.
     */
    private function awaitDetachedProcessExit(int $pid): bool
    {
        $deadline = microtime(true) + self::DETACHED_EXIT_TIMEOUT_SECONDS;

        while ($this->wrapperMayStillWrite($pid)) {
            if (microtime(true) >= $deadline) {
                return true;
            }
            usleep(self::DETACHED_POLL_INTERVAL_US);
        }

        return false;
    }

    /**
     * Empties and removes one scratch dir, retrying a bounded number of times.
     * Called only from tearDown, only after the tracked writer is gone (or its
     * exit wait timed out — the census then catches anything the writer recreates).
     */
    private function removeDir(string $dir): void
    {
        for ($attempt = 1; $attempt <= self::REMOVE_DIR_ATTEMPTS; $attempt++) {
            if (!is_dir($dir)) {
                return;
            }

            $files = glob("{$dir}/*") ?: [];
            foreach ($files as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            // Hidden markers — glob skips dotfiles by design, name them explicitly.
            foreach (['.complete', '.failed'] as $marker) {
                if (is_file("{$dir}/{$marker}")) {
                    @unlink("{$dir}/{$marker}");
                }
            }

            if (@rmdir($dir)) {
                return;
            }

            usleep(100000);
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
