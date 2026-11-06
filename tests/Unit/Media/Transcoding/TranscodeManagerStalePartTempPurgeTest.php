<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use ReflectionMethod;
use ReflectionProperty;
use Workerman\MySQL\Connection;

/**
 * Fix A′′′ (playback-lag program, 2026-11-06) — provably-dead orphaned
 * `.part-*` atomic-write temps stop occupying the in-flight cap, the prewarm
 * budget, and the cross-worker dedup snapshot.
 *
 * The live defect (owner reload mid-encode, haproxy-captured): the detached
 * segment encode runs `timeout -k 10 -s TERM N sh -c '… && mv || rm'` (CMAF:
 * the `; rm -f <tmp> <tmp>.i <tmp>.s0 <tmp>.m3u8` publish tail). A group TERM
 * — disconnect kill, launcher teardown — can land between the marker touch
 * and the tail, and if that temp's PROCESS REGISTRY entry is already gone
 * (released by the launcher side, or never registered across a worker
 * boundary) NO cleaner ever runs again. The orphan then feeds the same
 * whole-tree globs as live encodes forever: one permanent slot of
 * SEGMENT_MAX_INFLIGHT_GLOBAL (a cap=4 box is 503-theater after four
 * reloads), a permanent prewarm-budget slot, and — worst — a permanent
 * dedup entry, so every fresh requester skips its own launch, rides the
 * ≤30 s piggyback poll, times out, and 404s the same segment on every
 * retry. The LRU sweep is idle-based: an actively-watched job dir never
 * ages out. This is the stale-lock tax behind the observed 400-class
 * reload pain (the specific captured 400 itself was haproxy-layer —
 * Tr=-1 CD-- — but every later requester of that segment hit the 404).
 *
 * The law under test — death must be PROVABLE, so the bound is the wrapper:
 * a temp older than `ffmpeg.transcode_timeout` (+ the grace margin) than any
 * process can still own it has outlived its `timeout`. Younger temps —
 * including merely SLOW ones mid-encode — keep counting: SV-4.2 finding #2
 * is that slow-but-wanted finishes (unlinking a live MPEG-TS temp kills the
 * publish ffmpeg still writes by fd). Wrapper unset (`0`) → no provable
 * bound → the purge stands down entirely, today's behavior byte-preserved.
 *
 * The S2 doctrine also stays: the cap is a REAL-TIME whole-tree glob, never
 * an in-memory counter — which is why the fixtures plant temps in TWO job
 * subdirectories and assert the sum, and why purging happens AT the
 * accounting step that reads the tree, not in a side janitor.
 */
final class TranscodeManagerStalePartTempPurgeTest extends TestCase
{
    /** The wrapper stubbed into every fixture manager: death bound = 60 + 300. */
    private const WRAPPER_TIMEOUT_SECONDS = 60;

    /** Past 360 s of age in the fixtures; comfortably inside it for "young". */
    private const STALE_AGE_SECONDS = 500;
    private const YOUNG_AGE_SECONDS = 10;

    private string $segmentDir;

    protected function setUp(): void
    {
        $this->segmentDir = sys_get_temp_dir() . '/phlix_deadpart_' . uniqid();
        mkdir($this->segmentDir . '/jobA', 0755, true);
        mkdir($this->segmentDir . '/jobB', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->segmentDir);
    }

    // ─────────────────────────────────────────────────────────────────
    // The global cap count.
    // ─────────────────────────────────────────────────────────────────

    public function test_a_dead_temp_stops_counting_against_the_global_cap_and_is_unlinked(): void
    {
        $dead = $this->plantTemp('jobA', 'seg-voriginal-00000.m4s', 'abcdef01', self::STALE_AGE_SECONDS);
        $young = $this->plantTemp('jobA', 'seg-voriginal-00001.m4s', '99999999', self::YOUNG_AGE_SECONDS);

        // S2 whole-tree doctrine, live shape: BOTH job dirs feed the ONE sum.
        $deadPrewarm = $this->plantTemp('jobB', 'seg-v720p-00002.ts', 'p12345678', self::STALE_AGE_SECONDS);
        $youngPrewarm = $this->plantTemp('jobB', 'seg-v720p-00003.ts', 'p87654321', self::YOUNG_AGE_SECONDS);

        $manager = $this->manager(self::WRAPPER_TIMEOUT_SECONDS);

        // Four planted, two provably dead → the count is the LIVE two, and
        // the dead files are GONE (this is what frees their slots forever,
        // not merely for this call).
        $this->assertSame(2, $this->invoke($manager, 'countInFlightSegmentEncodes'));
        $this->assertFileDoesNotExist($dead);
        $this->assertFileDoesNotExist($deadPrewarm);
        $this->assertFileExists($young);
        $this->assertFileExists($youngPrewarm);

        // Idempotent: a second accounting sees the same stable world.
        $this->assertSame(2, $this->invoke($manager, 'countInFlightSegmentEncodes'));
    }

    /**
     * The concurrency law: a merely SLOW encode (young temp, no final yet)
     * keeps counting and keeps its file — the purge must never reach into
     * the wrapper's live window, or it would unlink the MPEG-TS temp ffmpeg
     * is still writing by fd and lose a publish the player waits on.
     */
    public function test_a_young_temp_still_counts_and_survives(): void
    {
        $young = $this->plantTemp('jobA', 'seg-voriginal-00004.m4s', '11111111', self::YOUNG_AGE_SECONDS);

        $this->assertSame(
            1,
            $this->invoke($this->manager(self::WRAPPER_TIMEOUT_SECONDS), 'countInFlightSegmentEncodes')
        );
        $this->assertFileExists($young);
    }

    // ─────────────────────────────────────────────────────────────────
    // The prewarm budget (wave A′ floor(cap/2)) — same tax, own glob.
    // ─────────────────────────────────────────────────────────────────

    public function test_a_dead_prewarm_temp_stops_counting_against_the_budget(): void
    {
        $dead = $this->plantTemp('jobB', 'seg-v720p-00005.ts', 'p22222222', self::STALE_AGE_SECONDS);
        $young = $this->plantTemp('jobB', 'seg-v720p-00006.ts', 'p33333333', self::YOUNG_AGE_SECONDS);

        $this->assertSame(
            1,
            $this->invoke($this->manager(self::WRAPPER_TIMEOUT_SECONDS), 'countInFlightPrewarmEncodes')
        );
        $this->assertFileDoesNotExist($dead);
        $this->assertFileExists($young);
    }

    // ─────────────────────────────────────────────────────────────────
    // The dedup snapshot — the arm that turned one orphan into a
    // permanent per-requester 30 s stall + 404.
    // ─────────────────────────────────────────────────────────────────

    public function test_reconcile_drops_a_dead_temp_from_the_dedup_snapshot_and_purges_it(): void
    {
        $deadFinal = "{$this->segmentDir}/jobA/seg-voriginal-00007.m4s";
        $dead = $deadFinal . '.part-44444444';
        $this->put($dead, self::STALE_AGE_SECONDS);
        $youngFinal = "{$this->segmentDir}/jobB/seg-v720p-00008.ts";
        $young = $youngFinal . '.part-p55555555';
        $this->put($young, self::YOUNG_AGE_SECONDS);

        $manager = $this->manager(self::WRAPPER_TIMEOUT_SECONDS);
        $this->invoke($manager, 'reconcileInFlightSegments');

        $prop = new ReflectionProperty(TranscodeManager::class, 'globalInFlightSnapshot');
        $prop->setAccessible(true);
        $snapshot = $prop->getValue($manager);
        $this->assertIsArray($snapshot);
        $this->assertArrayNotHasKey($deadFinal, $snapshot, 'a dead orphan must not key dedup forever');
        $this->assertArrayHasKey($youngFinal, $snapshot, 'a live prewarm encode still piggybacks');
        $this->assertFileDoesNotExist($dead);
        $this->assertFileExists($young);
    }

    // ─────────────────────────────────────────────────────────────────
    // The sidecar family: per-temp (unique hex), never per-final.
    // ─────────────────────────────────────────────────────────────────

    /**
     * CMAF writes `<tmp>.i`, `<tmp>.s0`, `<tmp>.m3u8` beside the marker. The
     * dead purge sweeps EXACTLY the dead temp's own family: a sibling temp of
     * the SAME final segment that is still young keeps every byte. (A
     * `{final}.part-*` family glob would nuke it — the registry cleaner's
     * per-temp doctrine, enforced here.)
     */
    public function test_the_purge_unlinks_only_the_dead_temps_own_sidecar_family(): void
    {
        $dead = $this->plantTemp('jobA', 'seg-voriginal-00009.m4s', '66666666', self::STALE_AGE_SECONDS);
        foreach (['.i', '.s0', '.m3u8'] as $ext) {
            $this->put($dead . $ext, self::STALE_AGE_SECONDS);
        }
        // Same FINAL segment, different (young) temp — its sidecars are a
        // different temp's business.
        $young = "{$this->segmentDir}/jobA/seg-voriginal-00009.m4s.part-77777777";
        $this->put($young, self::YOUNG_AGE_SECONDS);
        $this->put($young . '.i', self::YOUNG_AGE_SECONDS);

        $total = $this->invoke($this->manager(self::WRAPPER_TIMEOUT_SECONDS), 'countInFlightSegmentEncodes');
        $this->assertSame(1, $total, 'only the young temp is live once the dead one is purged');

        $this->assertFileDoesNotExist($dead);
        $this->assertSame([], glob($dead . '.*') ?: [], 'the dead temp sidecar family must be gone');
        $this->assertFileExists($young);
        $this->assertFileExists($young . '.i', 'a live sibling temp must not be collateral');
    }

    // ─────────────────────────────────────────────────────────────────
    // Wrapper disabled → nothing is provable → the purge stands down.
    // ─────────────────────────────────────────────────────────────────

    public function test_a_disabled_wrapper_timeout_disables_the_purge_entirely(): void
    {
        $dead = $this->plantTemp('jobA', 'seg-voriginal-00010.m4s', '88888888', self::STALE_AGE_SECONDS);

        $this->assertSame(
            1,
            $this->invoke($this->manager(0), 'countInFlightSegmentEncodes'),
            'transcode_timeout=0 has no provable bound — the pre-fix behavior is byte-preserved'
        );
        $this->assertFileExists($dead);
    }

    // ─────────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * @return string The planted temp's absolute path.
     */
    private function plantTemp(string $jobSubdir, string $finalName, string $suffixToken, int $ageSeconds): string
    {
        $path = "{$this->segmentDir}/{$jobSubdir}/{$finalName}.part-{$suffixToken}";
        $this->put($path, $ageSeconds);

        return $path;
    }

    private function put(string $path, int $ageSeconds): void
    {
        file_put_contents($path, 'x');
        $this->touchAged($path, $ageSeconds);
    }

    /**
     * The age fake — an old temp is born by mtime, not content: `touch -t`
     * semantics via the file's future-past.
     */
    private function touchAged(string $path, int $ageSeconds): void
    {
        touch($path, time() - $ageSeconds);
    }

    private function manager(int $wrapperTimeout): TranscodeManager
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('getTranscodeTimeout')->willReturn($wrapperTimeout);

        return new TranscodeManager(
            $this->createMock(Connection::class),
            $ffmpeg,
            $this->segmentDir
        );
    }

    /**
     * @return int|void Accounting methods return counts; reconcile returns void
     *                   (the return is simply unread there).
     */
    private function invoke(TranscodeManager $manager, string $method): mixed
    {
        $m = new ReflectionMethod(TranscodeManager::class, $method);
        $m->setAccessible(true);

        return $m->invoke($manager);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "{$dir}/{$entry}";
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
