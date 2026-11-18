<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\EncodeSlotLedger;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\SegmentProcessRegistry;
use Phlix\Media\Transcoding\TranscodeManager;
use Psr\Log\NullLogger;
use Workerman\MySQL\Connection;

/**
 * Round-2 transcode-manager performance behaviour:
 *  A. adaptive per-launch encode-thread budget (`encode_threads` on segment params)
 *  B. one shared, time-bounded in-flight scan per window (no per-launch glob storm)
 *  C. early exit from the segment wait loop when the encode died without output
 *  D. throttled, detached segment-cache sweep (plus the CLI's coalescing lock)
 */
final class TranscodeManagerRound2Test extends TestCase
{
    private const SECOND_NS = 1_000_000_000;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phlix_r2_' . uniqid();
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    /**
     * @return TranscodeManager
     */
    private function manager(Connection $db, FfmpegRunner $ff, int $waitMs = 200, ?string $dir = null): TranscodeManager
    {
        return new TranscodeManager(
            $db,
            $ff,
            $dir ?? $this->root,
            null,
            6,
            null,
            null,
            null,
            null,
            null,
            null,
            $waitMs,
        );
    }

    /**
     * A Connection mock answering the job lookup and the empty side queries an ensureSegment
     * launch makes (mirrors the mockDb routing in TranscodeManagerTest).
     *
     * @param array<string, mixed> $jobRow
     */
    private function jobDb(array $jobRow): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql) use ($jobRow): array {
                if (str_contains($sql, 'COUNT(*)')) {
                    return [['c' => 0]];
                }
                if (str_contains($sql, 'transcode_jobs WHERE id = ?')) {
                    return [$jobRow];
                }

                return [];
            }
        );

        return $db;
    }

    /**
     * @return array<string, mixed>
     */
    private function onDemandJobRow(string $dir, string $inputPath): array
    {
        return [
            'id' => 'seg-job',
            'hls_dir' => $dir,
            'input_path' => $inputPath,
            'status' => 'completed',
            'duration_seconds' => 60,
            'segment_seconds' => 6,
            'segment_params' => json_encode(['video_codec' => 'libx264', 'audio_codec' => 'aac']),
        ];
    }

    /**
     * Makes a job dir with a source file, returning [jobDir, inputPath].
     *
     * @return array{0: string, 1: string}
     */
    private function jobDir(string $name): array
    {
        $dir = $this->root . '/' . $name;
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');

        return [$dir, $input];
    }

    /**
     * Invokes a private TranscodeManager method. Used only for the pure bookkeeping helpers
     * under test, so the production call graph is not reshaped for the tests.
     */
    private function callPrivate(TranscodeManager $m, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod(TranscodeManager::class, $method);

        return $ref->invoke($m, ...$args);
    }

    /**
     * Live (non-blank) lines in the cache root's slot ledger: the admission count.
     */
    private function ledgerLines(string $root): int
    {
        $path = $root . '/' . EncodeSlotLedger::FILE_NAME;
        if (!is_file($path)) {
            return 0;
        }
        $lines = array_filter(explode("\n", (string) file_get_contents($path)), static fn (string $l): bool => $l !== '');

        return count($lines);
    }

    /**
     * Token column (first field) of every live ledger line, so a test can tell WHICH launch still holds a slot.
     *
     * @return list<string>
     */
    private function ledgerTokens(string $root): array
    {
        $path = $root . '/' . EncodeSlotLedger::FILE_NAME;
        if (!is_file($path)) {
            return [];
        }
        $tokens = [];
        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            if ($line !== '') {
                $tokens[] = explode(' ', $line)[0];
            }
        }

        return $tokens;
    }

    /**
     * Writes $count fresh ledger lines as if other workers had admitted encodes.
     */
    private function seedLedger(string $root, int $count, string $prefix = 'd'): void
    {
        $text = '';
        for ($i = 0; $i < $count; $i++) {
            $text .= $prefix . bin2hex(random_bytes(8)) . ' ' . time() . " 0\n";
        }
        file_put_contents($root . '/' . EncodeSlotLedger::FILE_NAME, $text, FILE_APPEND);
    }

    /**
     * Temps in the shared scan snapshot, both families. This is what the reaper and prune read.
     * It is no longer the admission count.
     */
    private function liveTempCount(TranscodeManager $m): int
    {
        $families = $this->callPrivate($m, 'inFlightSnapshot');

        return count($families['base']) + count($families['prewarm']);
    }

    private function prewarmTempCount(TranscodeManager $m): int
    {
        $families = $this->callPrivate($m, 'inFlightSnapshot');

        return count($families['prewarm']);
    }

    // ---------------------------------------------------------------- A: thread budget

    public function testSingleEncodeOnTwelveCoresGetsTheCeiling(): void
    {
        $m = $this->manager($this->createMock(Connection::class), $this->createMock(FfmpegRunner::class));
        $m->setEncodeThreadBudget(8, static fn(): int => 12);

        $this->assertSame(8, $this->callPrivate($m, 'segmentEncodeThreadsFor', 1));
    }

    public function testFourConcurrentEncodesOnTwelveCoresGetThree(): void
    {
        $m = $this->manager($this->createMock(Connection::class), $this->createMock(FfmpegRunner::class));
        $m->setEncodeThreadBudget(8, static fn(): int => 12);

        $this->assertSame(3, $this->callPrivate($m, 'segmentEncodeThreadsFor', 4));
    }

    public function testEightConcurrentEncodesFloorAtTwo(): void
    {
        $m = $this->manager($this->createMock(Connection::class), $this->createMock(FfmpegRunner::class));
        $m->setEncodeThreadBudget(8, static fn(): int => 12);

        $this->assertSame(2, $this->callPrivate($m, 'segmentEncodeThreadsFor', 8));
    }

    public function testZeroCeilingLeavesTheKeyUnset(): void
    {
        $m = $this->manager($this->createMock(Connection::class), $this->createMock(FfmpegRunner::class));
        $m->setEncodeThreadBudget(0, static fn(): int => 12);

        $this->assertNull($this->callPrivate($m, 'segmentEncodeThreadsFor', 1));
        $this->assertSame(
            ['video_codec' => 'libx264'],
            $this->callPrivate($m, 'withEncodeThreads', ['video_codec' => 'libx264'], 1),
        );
    }

    public function testLaunchPassesEncodeThreadsForASingleEncode(): void
    {
        [$dir, $input] = $this->jobDir('seg-threads-one');
        $seen = null;
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out, float $start, float $duration, array $params) use (&$seen): int {
                $seen = $params;

                return 1;
            }
        );
        $m = $this->manager($this->jobDb($this->onDemandJobRow($dir, $input)), $ff);
        $m->setEncodeThreadBudget(8, static fn(): int => 12);

        $m->ensureSegment('seg-job', null, 1);

        $this->assertIsArray($seen);
        $this->assertSame(8, $seen['encode_threads'] ?? null);
    }

    public function testLaunchCountsSiblingEncodesWhenSizingTheBudget(): void
    {
        [$dir, $input] = $this->jobDir('seg-threads-four');
        // Three other encodes are already admitted through the ledger by sibling workers
        // (N becomes 4 with this one).
        $this->seedLedger($this->root, 3);
        $seen = null;
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out, float $start, float $duration, array $params) use (&$seen): int {
                $seen = $params;

                return 1;
            }
        );
        $m = $this->manager($this->jobDb($this->onDemandJobRow($dir, $input)), $ff);
        $m->setEncodeThreadBudget(8, static fn(): int => 12);

        $m->ensureSegment('seg-job', null, 1);

        $this->assertIsArray($seen);
        $this->assertSame(3, $seen['encode_threads'] ?? null);
    }

    // ---------------------------------------------------------------- B: shared scan snapshot

    public function testCountsWithinOneSecondShareOneScan(): void
    {
        $ns = 10 * self::SECOND_NS;
        $m = $this->manager($this->createMock(Connection::class), $this->createMock(FfmpegRunner::class));
        $m->setMonotonicNsSource(static function () use (&$ns): int {
            return $ns;
        });
        $jobA = $this->root . '/job-a';
        mkdir($jobA, 0755, true);
        file_put_contents("{$jobA}/seg-00000.ts.part-bbbbbbb1", 'p');

        $liveBefore = $this->liveTempCount($m);
        $this->assertSame(1, $liveBefore);

        // A temp appears inside the same window: the shared snapshot has not been refreshed.
        $jobB = $this->root . '/job-b';
        mkdir($jobB, 0755, true);
        file_put_contents("{$jobB}/seg-00000.ts.part-bbbbbbb2", 'p');
        $ns += (int) (0.5 * self::SECOND_NS);

        $liveAfter = $this->liveTempCount($m);
        $this->assertSame(1, $liveAfter);
        $this->assertSame(0, $this->prewarmTempCount($m));

        // Past the one-second window the next read rescans and sees both temps.
        $ns += (int) (1.5 * self::SECOND_NS);

        $this->assertSame(2, $this->liveTempCount($m));
    }

    public function testInFlightCountSeesATempWhenTheSegmentDirHasABracket(): void
    {
        // An unescaped glob("{$segmentDir}/*") or glob("{$dir}/{...}") reads '[1]' as a character
        // class, matches nothing, and the in-flight count reads zero, so the cap is bypassed.
        $segs = $this->root . '/seg[1]';
        mkdir($segs . '/job-a', 0755, true);
        file_put_contents($segs . '/job-a/seg-00000.ts.part-bbbbbbb1', 'p');

        $ns = 10 * self::SECOND_NS;
        $m = $this->manager(
            $this->createMock(Connection::class),
            $this->createMock(FfmpegRunner::class),
            200,
            $segs,
        );
        $m->setMonotonicNsSource(static function () use (&$ns): int {
            return $ns;
        });

        $this->assertSame(1, $this->liveTempCount($m));
    }

    public function testInFlightCountSeesATempWhenAJobDirNameHasABraceGroup(): void
    {
        // GLOB_BRACE expands '{a,b}' in the directory part of the pattern, so the job dir 'job{a,b}'
        // is never matched and both families read zero. The count must see both temps.
        $segs = $this->root . '/seg-brace-count';
        mkdir($segs . '/job{a,b}', 0755, true);
        file_put_contents($segs . '/job{a,b}/seg-00000.ts.part-bbbbbbb1', 'p');
        file_put_contents($segs . '/job{a,b}/seg-00001.ts.part-pbbbbbbb2', 'p');

        $ns = 10 * self::SECOND_NS;
        $m = $this->manager(
            $this->createMock(Connection::class),
            $this->createMock(FfmpegRunner::class),
            200,
            $segs,
        );
        $m->setMonotonicNsSource(static function () use (&$ns): int {
            return $ns;
        });

        // The total counts both families (base + prewarm); the prewarm budget counts only the prewarm one.
        $this->assertSame(2, $this->liveTempCount($m));
        $this->assertSame(1, $this->prewarmTempCount($m));
    }

    /**
     * Guards the containment fix. A symlink inside the cache root points at a directory OUTSIDE it,
     * and that directory holds a seg-*.part-* temp (with a sidecar) older than the purge threshold.
     * Following the link, the scan would list the external temp and the purge would unlink it. The
     * in-flight scan must skip the link, and the count (which runs the scan and the purge) must leave
     * the external temp and its sidecar on disk.
     */
    public function testPurgeNeverDeletesATempReachedThroughASymlinkedJobDirectory(): void
    {
        $outside = $this->root . '/outside-temps';
        mkdir($outside, 0755, true);
        $external = $outside . '/seg-00000.ts.part-bbbbbbb1';
        file_put_contents($external, 'p');
        file_put_contents($external . '.i', 'i');
        touch($external, time() - 100000); // far past the 60 s timeout + 300 s margin death bound
        $segs = $this->root . '/segs-purge-link';
        mkdir($segs, 0755, true);
        symlink($outside, $segs . '/linked-job');

        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('getTranscodeTimeout')->willReturn(60);
        $ns = 10 * self::SECOND_NS;
        $m = $this->manager($this->createMock(Connection::class), $ff, 200, $segs);
        $m->setMonotonicNsSource(static function () use (&$ns): int {
            return $ns;
        });

        $this->assertSame(0, $this->liveTempCount($m));
        $this->assertFileExists($external, 'a temp outside the cache root must survive the purge');
        $this->assertFileExists($external . '.i', 'a sidecar outside the cache root must survive the purge');
    }

    /**
     * Second layer of the same guard: if a path outside the cache root is handed straight to
     * purgeDeadInFlightTemps() as dead, the purge must refuse to unlink it (or its sidecar).
     */
    public function testPurgeRefusesToUnlinkADeadTempOutsideTheCacheRoot(): void
    {
        $outside = $this->root . '/outside-direct';
        mkdir($outside, 0755, true);
        $external = $outside . '/seg-00000.ts.part-bbbbbbb1';
        file_put_contents($external, 'p');
        file_put_contents($external . '.i', 'i');
        touch($external, time() - 100000);
        $segs = $this->root . '/segs-purge-direct';
        mkdir($segs, 0755, true);

        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('getTranscodeTimeout')->willReturn(60);
        $m = $this->manager($this->createMock(Connection::class), $ff, 200, $segs);

        $this->assertSame([], $this->callPrivate($m, 'purgeDeadInFlightTemps', [$external]));
        $this->assertFileExists($external);
        $this->assertFileExists($external . '.i');
    }

    /**
     * Reaches isInsideSegmentRoot() with the cache root itself a symlink to a real directory. The
     * containment check resolves the root, so a dead temp in a job directory reached through the
     * link is inside the root: the scan finds it, the purge unlinks it and its sidecar. The scan
     * skips linked temps, so a second dead temp, a file symlink inside the root whose target lies
     * OUTSIDE it, is handed straight to purgeDeadInFlightTemps(), the same seam the direct purge test
     * uses. Its directory passes the containment check, so the link is unlinked as a link, and the
     * outside target must survive. Gap: the scan never hands this link to the purge, so the link is
     * reached only through the direct call. A mutation that makes isInsideSegmentRoot() always return
     * true is caught by the earlier direct purge test (testPurgeRefusesToUnlinkADeadTempOutsideTheCacheRoot),
     * not by this test: the final assertFileExists($outsideTarget) survives that mutation because
     * unlink() never follows a link.
     * This test guards the realpath-of-root and no-follow behaviour.
     */
    public function testPurgeUnlinksAnInRootTempThroughASymlinkedSegmentRootAndNeverFollowsAFileLink(): void
    {
        $real = $this->root . '/segs-purge-real-root';
        mkdir($real . '/job-a', 0755, true);
        $inRoot = $real . '/job-a/seg-00000.ts.part-bbbbbbb1';
        file_put_contents($inRoot, 'p');
        file_put_contents($inRoot . '.i', 'i');
        touch($inRoot, time() - 100000); // far past the 60 s timeout + 300 s margin death bound
        $outsideTarget = $this->root . '/outside-file-target.ts';
        file_put_contents($outsideTarget, 'o');
        touch($outsideTarget, time() - 100000);
        $linkedTemp = $real . '/job-a/seg-00001.ts.part-bbbbbbb2';
        symlink($outsideTarget, $linkedTemp);
        $link = $this->root . '/segs-purge-root-link';
        symlink($real, $link);

        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('getTranscodeTimeout')->willReturn(60);
        $ns = 10 * self::SECOND_NS;
        $m = $this->manager($this->createMock(Connection::class), $ff, 200, $link);
        $m->setMonotonicNsSource(static function () use (&$ns): int {
            return $ns;
        });

        $this->assertSame(0, $this->liveTempCount($m));
        $this->assertFileDoesNotExist($inRoot, 'a dead temp inside the root must be unlinked through a symlinked root');
        $this->assertFileDoesNotExist($inRoot . '.i', 'a sidecar inside the root must be unlinked through a symlinked root');
        $this->assertTrue(is_link($linkedTemp), 'the scan must skip a linked temp, so it is still on disk');

        $this->assertSame([], $this->callPrivate($m, 'purgeDeadInFlightTemps', [$linkedTemp]));
        $this->assertFalse(is_link($linkedTemp), 'an in-root file link must be unlinked as a link');
        $this->assertFileExists($outsideTarget, 'the target of a file link outside the root must survive');
    }

    // The admission count is the slot ledger now. A launch is counted from the moment it is
    // admitted, with no scan window in between, and it stops counting when its encode publishes,
    // is reaped, or ages out. The expiry clock is covered by EncodeSlotLedgerTest.

    public function testAdmittedLaunchesCountAgainstTheCapBeforeAnyScan(): void
    {
        $m = $this->manager($this->createMock(Connection::class), $this->createMock(FfmpegRunner::class));
        $cap = (int) (new \ReflectionClassConstant(TranscodeManager::class, 'SEGMENT_MAX_INFLIGHT_GLOBAL'))->getValue();

        $admitted = 0;
        while ($this->callPrivate($m, 'admitSegmentLaunch', false) !== null) {
            $admitted++;
        }

        $this->assertSame($cap, $admitted, 'the ledger admits exactly the cap, with no scan in between');
        $this->assertSame($cap, $this->ledgerLines($this->root));
    }

    public function testPrewarmLaunchesStopAtTheBudgetAndTheReaderKeepsTheHeadroom(): void
    {
        $m = $this->manager($this->createMock(Connection::class), $this->createMock(FfmpegRunner::class));
        $cap = (int) (new \ReflectionClassConstant(TranscodeManager::class, 'SEGMENT_MAX_INFLIGHT_GLOBAL'))->getValue();

        // cap 8: prewarm may hold floor(8 / 2) = 4 slots, and it stops at cap - headroom(2) = 6 in total.
        $prewarm = 0;
        while ($this->callPrivate($m, 'admitSegmentLaunch', true) !== null) {
            $prewarm++;
        }
        $this->assertSame(intdiv($cap, 2), $prewarm, 'prewarm stops at the prewarm budget');

        // The reader keeps the full cap, so the headroom is still there for a seek.
        $reader = 0;
        while ($this->callPrivate($m, 'admitSegmentLaunch', false) !== null) {
            $reader++;
        }
        $this->assertSame($cap - $prewarm, $reader, 'the reader fills the slots prewarm left');
        $this->assertSame($cap, $this->ledgerLines($this->root));
    }

    public function testAPublishedLaunchStopsCountingOnTheNextReconcile(): void
    {
        $m = $this->manager($this->createMock(Connection::class), $this->createMock(FfmpegRunner::class));
        $grant = $this->callPrivate($m, 'admitSegmentLaunch', false);
        $this->assertIsArray($grant);
        $burst = $this->root . '/burst';
        mkdir($burst, 0755, true);
        $final = $burst . '/seg-00000.ts';
        $this->assertTrue($this->callPrivate($m, 'settleLaunch', $final, $grant['token'], 4242));
        $this->assertSame(1, $this->ledgerLines($this->root));

        // Not published yet: the reconcile keeps the slot while the encode is still running.
        $this->callPrivate($m, 'reconcileInFlightSegments');
        $this->assertSame(1, $this->ledgerLines($this->root), 'an unpublished encode keeps its slot');

        // Once the final is published, the next reconcile releases the slot.
        file_put_contents($final, 'segment');
        $this->setPropertyNull($m, 'lastInFlightReconcileMs');
        $this->callPrivate($m, 'reconcileInFlightSegments');
        $this->assertSame(0, $this->ledgerLines($this->root), 'a published encode frees its slot');
    }

    public function testAReapedLaunchKeepsItsSlotUntilItsOwnSettleAndAConfirmedSettleFreesOnlyItsOwnReap(): void
    {
        $m = $this->manager($this->createMock(Connection::class), $this->createMock(FfmpegRunner::class));
        $final = $this->root . '/burst/seg-00001.ts';

        // First reap: one launch of the key, then a kill reaps the key. The reap sends only a TERM,
        // so the slot stays held until the registry settles that reap.
        $first = $this->callPrivate($m, 'admitSegmentLaunch', false);
        $this->assertIsArray($first);
        $this->callPrivate($m, 'settleLaunch', $final, $first['token'], 4242);
        $m->invalidateReservation($final);
        $this->assertSame(
            [$first['token']],
            $this->ledgerTokens($this->root),
            'a reaped encode keeps its slot until the registry settles it'
        );

        // (a) An unconfirmed settle of that reap (the hold bound passed with a launch still running)
        // keeps its slot held.
        $m->onSegmentKeySettled($final, false);
        $this->assertSame(
            [$first['token']],
            $this->ledgerTokens($this->root),
            'an unconfirmed settle keeps the first reap\'s slot held'
        );

        // Second reap of the SAME key: a second real launch, admitted and then reaped by a second
        // invalidateReservation. Its settle is the only one that may release slots this time.
        $second = $this->callPrivate($m, 'admitSegmentLaunch', false);
        $this->assertIsArray($second);
        $this->callPrivate($m, 'settleLaunch', $final, $second['token'], 4243);
        $m->invalidateReservation($final);
        $this->assertEqualsCanonicalizing(
            [$first['token'], $second['token']],
            $this->ledgerTokens($this->root),
            'the second reap holds its own slot alongside the unconfirmed first reap'
        );

        // (b) A confirmed settle frees only the second reap's token. The first reap, still unconfirmed,
        // keeps its token held: a regression that frees every reap of the key fails here.
        $m->onSegmentKeySettled($final, true);
        $this->assertSame(
            [$first['token']],
            $this->ledgerTokens($this->root),
            'a confirmed settle frees only its own reap; the earlier unconfirmed reap stays held'
        );

        // The freed token released again (the launcher's finally runs late) is a no-op, and the
        // first reap's slot is still held.
        $this->callPrivate($m, 'releaseSlot', $second['token']);
        $this->assertSame([$first['token']], $this->ledgerTokens($this->root));
    }

    private function setPropertyNull(TranscodeManager $m, string $property): void
    {
        $prop = new \ReflectionProperty(TranscodeManager::class, $property);
        $prop->setValue($m, null);
    }

    public function testAFailedLaunchIsNotCountedAsInFlight(): void
    {
        [$dir, $input] = $this->jobDir('seg-failed-launch');
        $ff = $this->createMock(FfmpegRunner::class);
        // startSegmentEncode returns 0 when the encode did not start.
        $ff->expects($this->once())->method('startSegmentEncode')->willReturn(0);
        $m = $this->manager($this->jobDb($this->onDemandJobRow($dir, $input)), $ff, 200);

        $this->assertNull($m->ensureSegment('seg-job', null, 1));
        $this->assertSame(
            0,
            $this->ledgerLines($this->root),
            'a launch that never started must release its ledger slot at once'
        );
    }

    // ---------------------------------------------------------------- C: early exit

    public function testWaitLoopStopsEarlyWhenTheEncodeIsDeadWithoutOutput(): void
    {
        [$dir, $input] = $this->jobDir('seg-dead');
        $ns = 10 * self::SECOND_NS;
        // Each clock read advances one second, so the 2 s grace passes within a few polls.
        $ticker = static function () use (&$ns): int {
            $ns += self::SECOND_NS;

            return $ns;
        };
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->once())->method('startSegmentEncode')->willReturn(1);
        $m = $this->manager($this->jobDb($this->onDemandJobRow($dir, $input)), $ff, 5000);
        $m->setMonotonicNsSource($ticker);
        $probes = 0;
        $m->setEncodeLivenessProbe(static function (string $final) use (&$probes): bool {
            $probes++;

            return false; // the encode is dead, and it left no temp and no segment
        });

        $started = microtime(true);
        $this->assertNull($m->ensureSegment('seg-job', null, 1));
        $elapsed = microtime(true) - $started;

        // Without the early exit the loop would poll the full 5 s budget (50 sleeps).
        $this->assertLessThan(10, $probes, 'dead encode must trigger the early exit');
        $this->assertLessThan(3.0, $elapsed);
    }

    public function testWaitLoopKeepsPollingWhileTheEncodeIsAlive(): void
    {
        [$dir, $input] = $this->jobDir('seg-alive');
        $ns = 10 * self::SECOND_NS;
        $ticker = static function () use (&$ns): int {
            $ns += self::SECOND_NS;

            return $ns;
        };
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->once())->method('startSegmentEncode')->willReturn(1);
        $m = $this->manager($this->jobDb($this->onDemandJobRow($dir, $input)), $ff, 1000);
        $m->setMonotonicNsSource($ticker);
        $probes = 0;
        $m->setEncodeLivenessProbe(static function (string $final) use (&$probes): bool {
            $probes++;

            return true; // still encoding: the loop must run to its normal timeout
        });

        $this->assertNull($m->ensureSegment('seg-job', null, 1));
        $this->assertGreaterThanOrEqual(5, $probes, 'a live encode must keep the wait going');
    }

    public function testWaitLoopKeepsPollingWhileAPartMarkerRemains(): void
    {
        [$dir, $input] = $this->jobDir('seg-marker');
        $ns = 10 * self::SECOND_NS;
        $ticker = static function () use (&$ns): int {
            $ns += self::SECOND_NS;

            return $ns;
        };
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out): int {
                // The encode is reported dead, but its marker is still on disk: keep waiting.
                file_put_contents($out . '.part-cccccccc', 'p');

                return 1;
            }
        );
        $m = $this->manager($this->jobDb($this->onDemandJobRow($dir, $input)), $ff, 1000);
        $m->setMonotonicNsSource($ticker);
        $probes = 0;
        $m->setEncodeLivenessProbe(static function () use (&$probes): bool {
            $probes++;

            return false;
        });

        $this->assertNull($m->ensureSegment('seg-job', null, 1));
        $this->assertGreaterThanOrEqual(5, $probes);
    }

    public function testNonOwnerWaiterIsNotAbandonedEarly(): void
    {
        // This worker did not launch $final (a piggyback on a sibling worker). The registry is
        // wired but tracks no PID for the key, so its verdict says "dead", which must not count.
        $final = $this->root . '/seg-job/seg-00001.ts';
        $ns = 10 * self::SECOND_NS;
        $m = $this->manager($this->createMock(Connection::class), $this->createMock(FfmpegRunner::class));
        $m->setMonotonicNsSource(static function () use (&$ns): int {
            return $ns;
        });
        $m->setSegmentProcessRegistry(new SegmentProcessRegistry(null, null, static fn(int $pid): bool => false));
        $waitStartNs = $ns;
        $ns += 3 * self::SECOND_NS; // well past the 2 s grace, with no output and no marker

        $this->assertFalse(
            $this->callPrivate($m, 'encodeAbandoned', $final, $waitStartNs),
            'a key this worker does not own must keep the wait until the normal timeout'
        );
    }

    public function testOwnedDeadEncodeIsAbandonedEarly(): void
    {
        // Control for the test above: the same dead verdict on a key this worker launched.
        $final = $this->root . '/seg-job/seg-00002.ts';
        $ns = 10 * self::SECOND_NS;
        $m = $this->manager($this->createMock(Connection::class), $this->createMock(FfmpegRunner::class));
        $m->setMonotonicNsSource(static function () use (&$ns): int {
            return $ns;
        });
        $registry = new SegmentProcessRegistry(null, null, static fn(int $pid): bool => false);
        $registry->register($final, 2147483647); // a PID that cannot exist
        $m->setSegmentProcessRegistry($registry);
        $waitStartNs = $ns;
        $ns += 3 * self::SECOND_NS;

        $this->assertTrue(
            $this->callPrivate($m, 'encodeAbandoned', $final, $waitStartNs),
            'an owned, dead encode with no output may stop the wait early'
        );
    }

    // ---------------------------------------------------------------- D: throttled detached sweep

    public function testSegmentCacheSweepIsThrottledPerWorker(): void
    {
        $ns = 100 * self::SECOND_NS;
        $launches = [];
        $m = new TranscodeManager(
            $this->createMock(Connection::class),
            $this->createMock(FfmpegRunner::class),
            $this->root,
            null,
            6,
            null,
            null,
            null,
            null,
            1024,
            86400,
            200,
        );
        $m->setMonotonicNsSource(static function () use (&$ns): int {
            return $ns;
        });
        $m->setSweepLauncher(static function (array $argv) use (&$launches): bool {
            $launches[] = $argv;

            return true;
        });

        $firstSweep = $m->sweepSegmentCache();
        $this->assertSame(0, $firstSweep);
        $ns += 5 * self::SECOND_NS;
        $secondSweep = $m->sweepSegmentCache();
        $this->assertSame(0, $secondSweep);
        $this->assertCount(1, $launches, 'two 503s inside 10 s launch one sweep');

        $ns += 6 * self::SECOND_NS; // 11 s after the first launch
        $m->sweepSegmentCache();
        $this->assertCount(2, $launches);

        $this->assertStringEndsWith('scripts/sweep-segment-cache.php', $launches[0][0]);
        $this->assertSame($this->root, $launches[0][1]);
        $this->assertSame('1024', $launches[0][2]);
        $this->assertSame('86400', $launches[0][3]);
    }

    public function testFailedDetachedLaunchFallsBackToTheInProcessSweep(): void
    {
        $stale = $this->root . '/stale-job';
        mkdir($stale, 0755, true);
        touch($stale, time() - 200000);
        $m = new TranscodeManager(
            $this->createMock(Connection::class),
            $this->createMock(FfmpegRunner::class),
            $this->root,
            null,
            6,
            null,
            null,
            null,
            null,
            1024,
            86400,
            200,
        );
        $m->setSweepLauncher(static fn(array $argv): bool => false);

        $this->assertSame(1, $m->sweepSegmentCache());
        $this->assertDirectoryDoesNotExist($stale);
    }

    public function testFailedLaunchOfTheNiceBinaryFallsBackToTheInProcessSweep(): void
    {
        $stale = $this->root . '/stale-no-nice';
        mkdir($stale, 0755, true);
        touch($stale, time() - 200000);
        $m = new TranscodeManager(
            $this->createMock(Connection::class),
            $this->createMock(FfmpegRunner::class),
            $this->root,
            null,
            6,
            null,
            PHP_BINARY,
            null,
            null,
            1024,
            86400,
            200,
        );
        $m->setNiceBinary($this->root . '/no-such-nice');

        // exec() would report 0 for a backgrounded shell whose binary is missing, so the
        // pre-check must catch it and the walk must run in-process.
        $this->assertSame(1, $m->sweepSegmentCache());
        $this->assertDirectoryDoesNotExist($stale);
    }

    public function testFailedLaunchOfThePhpBinaryFallsBackToTheInProcessSweep(): void
    {
        $stale = $this->root . '/stale-no-php';
        mkdir($stale, 0755, true);
        touch($stale, time() - 200000);
        $m = new TranscodeManager(
            $this->createMock(Connection::class),
            $this->createMock(FfmpegRunner::class),
            $this->root,
            null,
            6,
            null,
            $this->root . '/no-such-php',
            null,
            null,
            1024,
            86400,
            200,
        );

        $this->assertSame(1, $m->sweepSegmentCache());
        $this->assertDirectoryDoesNotExist($stale);
    }

    public function testDetachedLaunchReclaimsInTheBackground(): void
    {
        // ONLY real spawn in this Unit file. The argv shape is pinned without spawning by
        // testDetachedLaunchCommandHasTheExpectedArgvShape; this test proves the detached process
        // actually runs. It is timing-tolerant: the poll exits as soon as the directory is
        // reclaimed. The 60 s bound matters only on a loaded host, where a detached `nice -n 19`
        // run can stall for several seconds (the CLI itself takes about 0.2 s). The lock file marks
        // that the CLI started at all; its output goes to /dev/null.
        $stale = $this->root . '/stale-detached';
        mkdir($stale, 0755, true);
        touch($stale, time() - 200000);
        $m = new TranscodeManager(
            $this->createMock(Connection::class),
            $this->createMock(FfmpegRunner::class),
            $this->root,
            null,
            6,
            null,
            null,
            null,
            null,
            1024,
            86400,
            200,
        );

        $this->assertSame(0, $m->sweepSegmentCache(), 'a detached launch returns 0 at once');
        for ($i = 0; $i < 600 && is_dir($stale); $i++) {
            usleep(100_000);
        }
        $started = is_file($this->root . '/' . TranscodeManager::SEGMENT_SWEEP_LOCK_FILE);
        $this->assertDirectoryDoesNotExist(
            $stale,
            'detached sweep did not reclaim within 60 s; CLI started: ' . ($started ? 'yes' : 'no')
        );
    }

    public function testSweepCliCoalescesWhenTheLockIsHeld(): void
    {
        $stale = $this->root . '/stale-held';
        mkdir($stale, 0755, true);
        touch($stale, time() - 200000);
        $lock = fopen($this->root . '/' . TranscodeManager::SEGMENT_SWEEP_LOCK_FILE, 'c');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX));

        // Held lock: the CLI must exit 0 at once and leave the cache alone.
        [$code, $out] = $this->runSweepCli();
        $this->assertSame(0, $code, 'held-lock CLI exit; output: ' . $out);
        $this->assertDirectoryExists($stale, 'held-lock CLI must not sweep; output: ' . $out);

        // Released lock: the same invocation walks the cache and reclaims the stale dir.
        flock($lock, LOCK_UN);
        fclose($lock);
        [$status, $out] = $this->runSweepCli();
        $this->assertSame(0, $status, 'released-lock CLI exit; output: ' . $out);
        $this->assertDirectoryDoesNotExist(
            $stale,
            'released-lock CLI did not reclaim; exit ' . $status . '; output: ' . $out
        );
    }

    public function testSweepCliTreatsANonPositiveBudgetAsTheDefault(): void
    {
        // Same rule as the in-process constructor: 0 means "use the default", not a failure.
        // The stale directory is older than the default 3 h TTL, so it is reclaimed.
        $stale = $this->root . '/stale-zero-budget';
        mkdir($stale, 0755, true);
        touch($stale, time() - 200000);

        [$status, $out] = $this->runSweepCli('0', '0');
        $this->assertSame(0, $status, 'non-positive budget CLI exit; output: ' . $out);
        $this->assertDirectoryDoesNotExist(
            $stale,
            'non-positive budget must fall back to the default TTL; output: ' . $out
        );
    }

    public function testSweepSkipsASymlinkedJobDirectoryThatPointsOutsideTheCache(): void
    {
        $outside = $this->root . '/outside-dir';
        mkdir($outside, 0755, true);
        file_put_contents($outside . '/secret.txt', 'keep');
        touch($outside, time() - 200000);
        $segs = $this->root . '/segs';
        mkdir($segs, 0755, true);
        symlink($outside, $segs . '/evil');

        $reclaimed = TranscodeManager::sweepCacheTree($segs, 1024, 86400, new NullLogger());

        $this->assertSame(0, $reclaimed, 'a directory resolving outside the cache root is not reclaimed');
        $this->assertFileExists($outside . '/secret.txt', 'nothing outside the cache root may be unlinked');
    }

    public function testSweepDoesNotFollowASymlinkInsideAJobDirectory(): void
    {
        $outside = $this->root . '/outside-file-dir';
        mkdir($outside, 0755, true);
        file_put_contents($outside . '/secret.txt', 'keep');
        $segs = $this->root . '/segs-link';
        mkdir($segs, 0755, true);
        $job = $segs . '/old-job';
        mkdir($job, 0755, true);
        file_put_contents($job . '/seg-00000.ts', 'x');
        symlink($outside . '/secret.txt', $job . '/escape.ts');
        touch($job, time() - 200000);

        $reclaimed = TranscodeManager::sweepCacheTree($segs, 1024, 86400, new NullLogger());

        // The link is removed as a link only (never followed), so the job is then empty and is
        // reclaimed. The outside target survives: nothing outside the cache root is unlinked.
        $this->assertSame(1, $reclaimed);
        $this->assertDirectoryDoesNotExist($job);
        $this->assertFileExists(
            $outside . '/secret.txt',
            'a symlink inside a job dir must not reach outside the cache root'
        );
    }

    public function testSweepRemovesADanglingSymlinkInsideAJobDirectory(): void
    {
        $segs = $this->root . '/segs-dangling';
        mkdir($segs, 0755, true);
        $job = $segs . '/dangling-job';
        mkdir($job, 0755, true);
        symlink($segs . '/no-such-target.ts', $job . '/seg-00000.ts');
        touch($job, time() - 200000);

        // First sweep: the dangling link is removed and the now-empty job directory is reclaimed.
        $this->assertSame(1, TranscodeManager::sweepCacheTree($segs, 1024, 86400, new NullLogger()));
        $this->assertFalse(is_link($job . '/seg-00000.ts'), 'the dangling link must be removed');
        $this->assertDirectoryDoesNotExist($job);

        // Later sweeps have nothing left to do, so the warning is not repeated.
        $this->assertSame(0, TranscodeManager::sweepCacheTree($segs, 1024, 86400, new NullLogger()));
    }

    public function testSweepTreeMatchesASegmentDirWhoseNameHasABracket(): void
    {
        // An unescaped glob("{$dir}/*") matches nothing under a '[' in the path, so the cache
        // would silently never be swept. The escaped pattern must still find the job directory.
        $segs = $this->root . '/seg[1]';
        mkdir($segs, 0755, true);
        $stale = $segs . '/stale-bracket';
        mkdir($stale, 0755, true);
        touch($stale, time() - 200000);

        $this->assertSame(1, TranscodeManager::sweepCacheTree($segs, 1024, 86400, new NullLogger()));
        $this->assertDirectoryDoesNotExist($stale);
    }

    public function testSizeBudgetCountsAJobDirectoryWhoseNameHasABracket(): void
    {
        // dirSize() must see the bytes under a '[' path too, or the size budget never evicts it.
        $segs = $this->root . '/seg*[2]?';
        mkdir($segs, 0755, true);
        $big = $segs . '/big-job';
        mkdir($big, 0755, true);
        file_put_contents($big . '/seg-00000.ts', str_repeat('x', 2000));
        touch($big, time() - 5000); // past the 30 min active window, inside the 3 h TTL

        $this->assertSame(1, TranscodeManager::sweepCacheTree($segs, 1024, 10800, new NullLogger()));
        $this->assertDirectoryDoesNotExist($big);
    }

    /**
     * Regression guard: it fails if GLOB_BRACE is ever added to the glob in removeJobDirUnder(). Other
     * sweep-path globs (dirSize, the cache-root glob) are not covered by this test. The stale
     * job directory is named 'stale{a,b}', a literal name on disk. Under GLOB_BRACE, a glob built
     * from that name would expand to 'stalea' and 'staleb', match nothing under the real directory,
     * leave its file behind and fail to reclaim it. The sibling 'stalea' is named like one expansion
     * and is fresh, so the sweep must not remove it. The survivor set is asserted exactly. This test
     * passes on the current code. It makes no claim about which sweep-path glob is currently
     * brace-sensitive.
     */
    public function testSweepReclaimsAJobDirectoryWhoseNameHasABraceGroup(): void
    {
        $segs = $this->root . '/seg-brace-sweep';
        mkdir($segs, 0755, true);
        $stale = $segs . '/stale{a,b}';
        mkdir($stale, 0755, true);
        file_put_contents($stale . '/seg-00000.ts', 'x');
        touch($stale, time() - 200000);
        $sibling = $segs . '/stalea';
        mkdir($sibling, 0755, true);
        file_put_contents($sibling . '/seg-00000.ts', 'y');

        $this->assertDirectoryExists($stale, 'the brace-named stale job must exist before the sweep');
        $this->assertDirectoryExists($sibling, 'the sibling named like an expansion must exist before the sweep');

        $this->assertSame(1, TranscodeManager::sweepCacheTree($segs, 1024, 86400, new NullLogger()));

        $this->assertSame(
            ['stalea'],
            array_values(array_diff(scandir($segs) ?: [], ['.', '..'])),
            'only the fresh sibling may survive; the brace-named stale job must be reclaimed'
        );
        $this->assertFileExists($sibling . '/seg-00000.ts', 'the sibling job must keep its file');
    }

    public function testSweepUnlinksASymlinkToAJobDirectoryAndLeavesTheTargetIntact(): void
    {
        // The link sits in the cache root beside a live job it points at. Following it would empty
        // and rmdir the target. The link must be unlinked as a link; the target and its file stay.
        $segs = $this->root . '/segs-link-to-job';
        mkdir($segs, 0755, true);
        $target = $segs . '/live-job';
        mkdir($target, 0755, true);
        file_put_contents($target . '/seg-00000.ts', 'x');
        symlink($target, $segs . '/link-to-live');

        $reclaimed = TranscodeManager::sweepCacheTree($segs, 1024, 86400, new NullLogger());

        $this->assertSame(0, $reclaimed, 'the link is not a reclaimed job directory');
        $this->assertFalse(is_link($segs . '/link-to-live'), 'the symlinked job entry must be removed');
        $this->assertDirectoryExists($target, 'the link target must not be removed');
        $this->assertFileExists($target . '/seg-00000.ts', 'the link target must not be emptied');
    }

    public function testSweepReclaimsJobDirectoriesWhenTheSegmentDirItselfIsASymlink(): void
    {
        // The cache root is a symlink to a real directory. Containment is checked against the real
        // root, so the job directory under it must still be reclaimed.
        $real = $this->root . '/segs-real-root';
        mkdir($real, 0755, true);
        $stale = $real . '/stale-job';
        mkdir($stale, 0755, true);
        file_put_contents($stale . '/seg-00000.ts', 'x');
        touch($stale, time() - 200000);
        $link = $this->root . '/segs-root-link';
        symlink($real, $link);

        $this->assertSame(1, TranscodeManager::sweepCacheTree($link, 1024, 86400, new NullLogger()));
        $this->assertDirectoryDoesNotExist($stale);
        $this->assertDirectoryExists($real, 'the real cache root must survive the sweep');
    }

    public function testMissingSweepScriptFallsBackToTheInProcessWalkWithoutSpawning(): void
    {
        $stale = $this->root . '/stale-no-script';
        mkdir($stale, 0755, true);
        touch($stale, time() - 200000);
        $m = $this->sweepManager();
        $m->setSweepScriptPath($this->root . '/no-such-sweep-segment-cache.php');
        $calls = 0;
        $m->setExecSeam(static function (string $command) use (&$calls): array {
            $calls++;

            return [0, ['4242']];
        });

        // php would exit 1 after the PID is printed, so the script is checked before any exec.
        $this->assertSame(1, $m->sweepSegmentCache());
        $this->assertSame(0, $calls, 'a missing script must never reach exec');
        $this->assertDirectoryDoesNotExist($stale);
    }

    public function testMissingAutoloaderFallsBackToTheInProcessWalkWithoutSpawning(): void
    {
        // The script exists, but the vendor/autoload.php it requires does not sit beside it.
        $script = $this->root . '/noauto/scripts/sweep-segment-cache.php';
        mkdir(dirname($script), 0755, true);
        file_put_contents($script, "<?php\n");
        $stale = $this->root . '/stale-no-autoload';
        mkdir($stale, 0755, true);
        touch($stale, time() - 200000);
        $m = $this->sweepManager();
        $m->setSweepScriptPath($script);
        $calls = 0;
        $m->setExecSeam(static function (string $command) use (&$calls): array {
            $calls++;

            return [0, ['4242']];
        });

        $this->assertSame(1, $m->sweepSegmentCache());
        $this->assertSame(0, $calls, 'a missing autoloader must never reach exec');
        $this->assertDirectoryDoesNotExist($stale);
    }

    public function testDetachedLaunchCommandHasTheExpectedArgvShape(): void
    {
        // Asserts the command line through the exec seam, so nothing is spawned. The segment dir
        // has a space in it, so the escaping of every argument is exercised.
        $segs = $this->root . '/seg dir';
        mkdir($segs, 0755, true);
        $stale = $segs . '/stale-shape';
        mkdir($stale, 0755, true);
        touch($stale, time() - 200000);
        $fakeNice = $this->root . '/bin/nice';
        mkdir(dirname($fakeNice), 0755, true);
        file_put_contents($fakeNice, "#!/bin/sh\n");
        chmod($fakeNice, 0755);

        $m = new TranscodeManager(
            $this->createMock(Connection::class),
            $this->createMock(FfmpegRunner::class),
            $segs,
            null,
            6,
            null,
            PHP_BINARY,
            null,
            null,
            1024,
            86400,
            200,
        );
        $m->setNiceBinary($fakeNice);
        $commands = [];
        $m->setExecSeam(static function (string $command) use (&$commands): array {
            $commands[] = $command;

            return [0, ['4242']];
        });

        $this->assertSame(0, $m->sweepSegmentCache(), 'an accepted launch returns 0 at once');
        $script = dirname(__DIR__, 4) . '/scripts/sweep-segment-cache.php';
        $expected = implode(' ', array_map('escapeshellarg', [
            $fakeNice,
            '-n',
            '19',
            PHP_BINARY,
            $script,
            $segs,
            '1024',
            '86400',
        ])) . ' >/dev/null 2>&1 & echo $!';
        $this->assertSame([$expected], $commands);
        $this->assertDirectoryExists($stale, 'an accepted launch must not also sweep in-process');
    }

    /**
     * A manager over $this->root with the 1 KB / 1 day budgets the sweep tests use. The default
     * `nice` lookup is kept, so only a seam that is set in the test changes the launch path.
     */
    private function sweepManager(): TranscodeManager
    {
        return new TranscodeManager(
            $this->createMock(Connection::class),
            $this->createMock(FfmpegRunner::class),
            $this->root,
            null,
            6,
            null,
            PHP_BINARY,
            null,
            null,
            1024,
            86400,
            200,
        );
    }

    /** @return array{0: int, 1: string} Exit status and captured CLI output. */
    private function runSweepCli(string $maxBytes = '1024', string $maxAge = '86400'): array
    {
        $script = dirname(__DIR__, 4) . '/scripts/sweep-segment-cache.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' '
            . escapeshellarg($this->root) . ' ' . escapeshellarg($maxBytes) . ' ' . escapeshellarg($maxAge)
            . ' < /dev/null > ' . escapeshellarg($this->cliLog()) . ' 2>&1';
        $output = [];
        $status = 1;
        exec($command, $output, $status);

        return [$status, $this->cliOutput()];
    }

    private function cliLog(): string
    {
        return $this->root . '/cli.out';
    }

    private function cliOutput(): string
    {
        $text = is_file($this->cliLog()) ? (string) file_get_contents($this->cliLog()) : '(no output)';

        return $text === '' ? '(empty)' : $text;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir) ?: [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $path = "{$dir}/{$e}";
            // A symlink is removed as a link and never recursed into (it may point outside the root).
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
