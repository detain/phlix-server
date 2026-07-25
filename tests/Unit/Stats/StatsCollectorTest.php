<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Stats;

use DateTime;
use PDOException;
use PHPUnit\Framework\TestCase;
use Phlix\Media\MediaItemType;
use Phlix\Stats\StatsCollector;
use RuntimeException;
use Workerman\MySQL\Connection;

class StatsCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The failure counters are process-wide (see StatsCollector::$writeFailures),
        // so every test starts from a known state regardless of execution order.
        StatsCollector::resetWriteFailureCounters();
    }

    protected function tearDown(): void
    {
        StatsCollector::resetWriteFailureCounters();
        parent::tearDown();
    }

    public function testRecordPlaybackStartCreatesEvent(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO stats_playback_events'),
                $this->callback(function ($params) {
                    return count($params) === 6
                        && $params[0] !== '' // event id
                        && $params[1] === 'user-123'
                        && $params[2] === 'media-456'
                        && $params[3] === 'movie'
                        && $params[4] === 'device-789'
                        && $params[5] === null; // client_ip
                })
            );

        $collector = new StatsCollector($db);
        $eventId = $collector->recordPlaybackStart('user-123', 'media-456', 'movie', 'device-789');

        $this->assertNotEmpty($eventId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{4}[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}[0-9a-f]{4}[0-9a-f]{4}$/',
            $eventId
        );
    }

    /**
     * S102: the raw `media_items.type` value is bound VERBATIM — the column now
     * carries all 13 members (migration 094), so nothing folds `episode` into
     * `series`. This pins the SQL shape for runs with no database; the real
     * INSERT is proven in
     * {@see \Phlix\Tests\Integration\Stats\PlaybackEventMediaTypeEnumTest}.
     */
    public function testRecordPlaybackStartBindsEveryMediaTypeVerbatim(): void
    {
        foreach (MediaItemType::ALL as $type) {
            $db = $this->createMock(Connection::class);
            $db->expects($this->once())
                ->method('query')
                ->with(
                    $this->stringContains('INSERT INTO stats_playback_events'),
                    $this->callback(static fn(array $params): bool => $params[3] === $type)
                );

            (new StatsCollector($db))->recordPlaybackStart('user-1', 'media-1', $type, 'device-1');
        }
    }

    /**
     * S102: a value the column ENUM does not contain would be MySQL error 1265,
     * so it is coerced to the shared fallback instead of losing the event.
     * `image` is the classic wrong value here — a scanner label that has never
     * been a `media_items.type` member.
     */
    public function testRecordPlaybackStartCoercesATypeOutsideTheEnum(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO stats_playback_events'),
                $this->callback(static fn(array $params): bool => $params[3] === MediaItemType::FALLBACK)
            );

        (new StatsCollector($db))->recordPlaybackStart('user-1', 'media-1', 'image', 'device-1');
    }

    /**
     * S102 — THE BOUNDARY. Recording statistics is telemetry: it must never be
     * able to break the user action that triggered it. Before the fix the
     * driver's exception escaped `recordPlaybackStart()` and, since
     * `PlaybackController::dispatchPlaybackStarted()` has no try/catch, escaped
     * the HTTP worker as well — a 500 on every episode play.
     *
     * @return array<string, array{0: \Throwable}>
     */
    public static function writeFailureProvider(): array
    {
        return [
            'error 1265 (the S102 production failure)' => [
                new PDOException("SQLSTATE[01000]: Warning: 1265 Data truncated for column 'media_type' at row 1"),
            ],
            'connection lost' => [new RuntimeException('MySQL server has gone away')],
            'engine-level error' => [new \Error('driver blew up')],
        ];
    }

    /**
     * @dataProvider writeFailureProvider
     */
    public function testAFailingWriteIsContainedAndNeverPropagates(\Throwable $failure): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException($failure);

        $collector = new StatsCollector($db);

        // Every WRITE path has to be contained, not just the one that broke.
        $eventId = $collector->recordPlaybackStart('user-1', 'media-1', 'episode', 'device-1');
        $this->assertNotSame('', $eventId, 'A contained write must still return a usable event id');

        $collector->recordPlaybackEnd($eventId, 60, true);
        $collector->recordLibraryChange('item_added', 'media-1');
        $collector->recordUserActivity('user-1', 'login');
        $collector->recordStorageSnapshot('movie', 1, 1);

        // Reaching here at all IS the assertion: nothing propagated.
        $this->assertTrue(true);
    }

    /**
     * The boundary is deliberately narrow: READ failures still surface, because
     * they serve the admin dashboard, where a broken query must be a visible
     * error rather than a silently empty chart.
     *
     * All THREE reads are pinned (S102 review r1, LOW-4): pinning only
     * `getTopMedia()` left the other two free to be wrapped in containment with
     * no red test — the exact "boundary widened by accident" this test exists to
     * prevent.
     *
     * @return array<string, array{0: callable(StatsCollector): mixed}>
     */
    public static function readMethodProvider(): array
    {
        return [
            'getPlaybackStats' => [
                static fn(StatsCollector $c): mixed => $c->getPlaybackStats(
                    new DateTime('2024-01-01'),
                    new DateTime('2024-01-31')
                ),
            ],
            'getTopUsers' => [static fn(StatsCollector $c): mixed => $c->getTopUsers(10, null)],
            'getTopMedia' => [static fn(StatsCollector $c): mixed => $c->getTopMedia(10, null)],
        ];
    }

    /**
     * @param callable(StatsCollector): mixed $read
     *
     * @dataProvider readMethodProvider
     */
    public function testReadFailuresStillPropagate(callable $read): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(new RuntimeException('read exploded'));

        $collector = new StatsCollector($db);

        $this->expectException(RuntimeException::class);
        $read($collector);
    }

    /**
     * A contained read would also leave no trace in the write counters, so assert
     * the failure never went through the WRITE boundary either.
     */
    public function testAReadFailureIsNotCountedAsAContainedWrite(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(new RuntimeException('read exploded'));

        try {
            (new StatsCollector($db))->getTopUsers(10, null);
            $this->fail('getTopUsers() must propagate');
        } catch (RuntimeException) {
            $this->assertSame([], StatsCollector::writeFailureCounters());
        }
    }

    /**
     * S102: `stats_storage.media_type` stays COARSE (it has a real reader in
     * `DashboardService::getStorageSummary()`), so the writer folds whatever it
     * is handed onto a bucket. The fold is idempotent, so the two existing
     * callers — which already pass bucket names — are unaffected.
     */
    public function testRecordStorageSnapshotFoldsRawTypesToBuckets(): void
    {
        foreach (['episode' => 'series', 'track' => 'music', 'audiobook' => 'book', 'movie' => 'movie'] as $t => $b) {
            $db = $this->createMock(Connection::class);
            $db->expects($this->once())
                ->method('query')
                ->with(
                    $this->stringContains('INSERT INTO stats_storage'),
                    $this->callback(static fn(array $params): bool => $params[2] === $b)
                );

            (new StatsCollector($db))->recordStorageSnapshot($t, 1, 1024);
        }
    }

    /**
     * S102 review r1 MED-2 — the fold must AGGREGATE, not emit a row per call.
     *
     * Several raw types share a bucket, and every row of one snapshot run carries
     * the same `NOW()` second, so `getStorageSummary()`'s `MAX(recorded_at)` join
     * returns all of them for that bucket. One summed row per bucket is what the
     * reader's grouping assumes. Real-DB proof of the resulting totals:
     * {@see \Phlix\Tests\Integration\Stats\PlaybackEventMediaTypeEnumTest::testTheDashboardRollUpsEqualTheRawBytesWritten}.
     */
    public function testRecordStorageSnapshotsSumsEveryTypeThatSharesABucket(): void
    {
        /** @var array<string, array{count: int, bytes: int, cache: int}> $written */
        $written = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use (&$written): array {
                $this->assertStringContainsString('INSERT INTO stats_storage', $sql);
                $written[(string) $params[2]] = [
                    'count' => (int) $params[3],
                    'bytes' => (int) $params[4],
                    'cache' => (int) $params[5],
                ];

                return [];
            }
        );

        (new StatsCollector($db))->recordStorageSnapshots([
            'series' => ['count' => 1, 'bytes' => 2_000, 'cache' => 1],
            'season' => ['count' => 2, 'bytes' => 3_000, 'cache' => 2],
            'episode' => ['count' => 3, 'bytes' => 4_000, 'cache' => 4],
            'photo' => ['count' => 4, 'bytes' => 12_000],
        ]);

        $this->assertSame(['series', 'photo'], array_keys($written), 'One row per BUCKET, not per type');
        $this->assertSame(['count' => 6, 'bytes' => 9_000, 'cache' => 7], $written['series']);
        $this->assertSame(['count' => 4, 'bytes' => 12_000, 'cache' => 0], $written['photo']);
    }

    /**
     * The whole 13-member vocabulary in one run: five rows, and not one byte lost.
     */
    public function testRecordStorageSnapshotsLosesNoBytesAcrossTheWholeVocabulary(): void
    {
        $totals = [];
        $expectedTotal = 0;
        foreach (MediaItemType::ALL as $index => $type) {
            $bytes = 1_000 * ($index + 1);
            $totals[$type] = ['count' => 1, 'bytes' => $bytes];
            $expectedTotal += $bytes;
        }
        $this->assertSame(91_000, $expectedTotal, 'The review r1 fixture: 13 types, 91,000 bytes');

        $writtenBytes = 0;
        $rows = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use (&$writtenBytes, &$rows): array {
                $rows++;
                $writtenBytes += (int) $params[4];

                return [];
            }
        );

        (new StatsCollector($db))->recordStorageSnapshots($totals);

        $this->assertSame(5, $rows, 'One row per stats_storage bucket');
        $this->assertSame(91_000, $writtenBytes, 'Every byte handed in must reach the table');
    }

    /**
     * S102 review r2 MED-2 — one snapshot RUN carries one `recorded_at`.
     *
     * `recorded_at` is a second-precision `DATETIME` and the dashboard joins on
     * `MAX(recorded_at)` per `media_type`, so a run whose rows land on different
     * seconds loses every bucket that received rows in more than one of them
     * (measured on real MySQL: 13 individual calls over three seconds → 44,000 of
     * 91,000 bytes). With `NOW()` per INSERT that was decided by wall-clock luck;
     * the run is now stamped once and bound through `FROM_UNIXTIME(?)` — a bound
     * unix second rather than a PHP-formatted string, so the value is what `NOW()`
     * would have produced in the MySQL session's own time zone.
     *
     * ⚠ Nothing is asserted inside the `query` callback, and that is deliberate:
     * {@see \Phlix\Stats\StatsCollector::write()} wraps every query in
     * `catch (Throwable)`, and PHPUnit's `ExpectationFailedException` IS a `Throwable`,
     * so an assertion that fails in there is CONTAINED by the telemetry boundary and
     * degrades to "the write silently did not happen". (That is why reverting the stamp
     * used to redden this test as *"actual size 0 matches expected size 7"* rather than
     * naming the missing `FROM_UNIXTIME(?)`.) Record in the callback, assert outside it.
     */
    public function testEveryRowOfASnapshotRunCarriesTheSameRecordedAtSecond(): void
    {
        /** @var list<int|null> $stamps */
        $stamps = [];
        /** @var list<string> $statements */
        $statements = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $params) use (&$stamps, &$statements): array {
                // `array_key_exists` rather than a blind `$params[6]`: on a write path
                // that no longer binds the stamp the blind read raises "Undefined array
                // key 6", which phpunit.xml's failOnWarning escalates — reddening this
                // test for something other than the run stamp (S102 review r3).
                $statements[] = $sql;
                $stamps[] = array_key_exists(6, $params) ? (int) $params[6] : null;

                return [];
            }
        );

        $collector = new StatsCollector($db);

        // A batch (5 rows) plus later individual calls: one run either way.
        $collector->recordStorageSnapshots([
            'movie' => ['count' => 1, 'bytes' => 1],
            'series' => ['count' => 1, 'bytes' => 2],
            'music' => ['count' => 1, 'bytes' => 3],
            'photo' => ['count' => 1, 'bytes' => 4],
            'book' => ['count' => 1, 'bytes' => 5],
        ]);
        $collector->recordStorageSnapshot('episode', 1, 6);
        $collector->recordStorageSnapshot('track', 1, 7);

        $this->assertCount(7, $stamps);
        foreach ($statements as $index => $sql) {
            $this->assertStringContainsString(
                'FROM_UNIXTIME(?)',
                $sql,
                'Row ' . $index . ' must carry the run\'s BOUND second, not a fresh NOW()'
            );
            $this->assertStringNotContainsString('NOW()', $sql);
        }
        $this->assertNotContains(
            null,
            $stamps,
            'Every storage INSERT binds the run stamp as its 7th parameter'
        );
        $this->assertCount(
            1,
            array_unique($stamps),
            'One run, one recorded_at: ' . implode(',', array_map(strval(...), $stamps))
        );
        $this->assertLessThanOrEqual(
            2,
            abs(time() - (int) $stamps[0]),
            'The stamp must be the run\'s own wall-clock second, not an arbitrary value'
        );
    }

    /**
     * The gap window this test brackets, in seconds, written down INDEPENDENTLY of
     * `StatsCollector::SNAPSHOT_RUN_MAX_GAP_SECONDS`.
     *
     * Deliberately a literal and not a reflection read of the constant: a test that
     * derives its expectation from the value under test follows every mutation of it
     * and pins nothing. Widening the production constant must break this file.
     */
    private const DOCUMENTED_GAP_WINDOW_NS = 5_000_000_000;

    /**
     * Gaps to the previous storage write, and whether the run CONTINUES across them.
     *
     * The window is `<` ({@see \Phlix\Stats\StatsCollector::snapshotRunSecond()}), so
     * exactly 5.000000000 s already starts a new run. 4.99 s and 4.999 s also continue
     * when measured, but they are not asserted here: the headroom left for the probe
     * itself (10 ms / 1 ms) is smaller than a scheduler hiccup on a loaded box, which
     * would turn a correct implementation red. 4.9 s leaves 100 ms and is checked
     * explicitly against the probe's own measured overhead below.
     *
     * @return array<string, array{0: float, 1: bool}>
     */
    public static function snapshotRunGapProvider(): array
    {
        return [
            'no gap at all — back-to-back writes' => [0.0, true],
            '1 s after the previous write' => [1.0, true],
            '4.5 s — half a second of window left' => [4.5, true],
            '4.9 s — the tightest gap that still continues' => [4.9, true],
            'exactly 5.000000000 s — the boundary itself ENDS the run' => [5.0, false],
            '5.1 s' => [5.1, false],
            '6 s' => [6.0, false],
            '1 minute' => [60.0, false],
            '6 h — the live daemon tick cadence' => [21_600.0, false],
        ];
    }

    /**
     * S102 review r3 MED-1 — a run ends at a STALL, and THIS is the test that proves it.
     *
     * ## Why the previous version of this test proved nothing
     *
     * It rewound `snapshotRunLastWriteNs` by 6 s but not the clock, then asserted
     * `assertGreaterThanOrEqual($stamps[1], $stamps[2])`. A *continuing* run and a
     * *recomputed* one both return the same `time()` second within one test, so that
     * assertion held either way. Measured consequence: the entire gap arm could be
     * deleted (`$continuingARun = $this->snapshotRunSecond !== 0;` — a run that NEVER
     * expires) and the whole suite stayed green at `OK (116 tests, 608 assertions)`;
     * `SNAPSHOT_RUN_MAX_GAP_SECONDS` could be widened 5 → 86400 (a run lasts a DAY) and
     * the suite stayed green at `OK (202 tests, 897 assertions)`. That matters because
     * `Application::startStorageSnapshotTimer()` holds ONE collector for the worker's
     * whole life: with the arm gone, every 6-hourly tick reuses the BOOT second and the
     * reader SUMS every generation together — measured, three runs 1 s apart on one
     * collector made the dashboard report 45,000 for a real 15,000 (3.00×), rising to
     * ~5× after a day of ticks. A mechanism no test can see is a mechanism the next
     * cleanup deletes.
     *
     * ## The discriminator: a sentinel no recomputation can produce
     *
     * `snapshotRunSecond` is set to `time() - 12345` before the gap is rewound. If the
     * next write CONTINUES the run it returns that sentinel; if it RECOMPUTES it returns
     * a fresh `time()`. The two are then distinguishable with no sleeping and no clock
     * manipulation. The gap itself is rewound by reflection rather than slept through:
     * the point is the window arithmetic, and a test that slept 6 s to prove it would be
     * paid for on every run of the suite forever.
     *
     * @dataProvider snapshotRunGapProvider
     */
    public function testAStallLongerThanTheGapWindowStartsANewRun(
        float $gapSeconds,
        bool $expectSameRun
    ): void {
        /** @var list<int|null> $stamps */
        $stamps = [];
        /** @var list<string> $statements */
        $statements = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $params) use (&$stamps, &$statements): array {
                // Recorded, never asserted: StatsCollector::write() catches Throwable, and
                // an ExpectationFailedException IS one, so an assertion in here would be
                // swallowed by the telemetry boundary. `array_key_exists` rather than a
                // blind `$params[6]` because that read raises "Undefined array key 6" on a
                // write path with no bound stamp, and failOnWarning would then redden this
                // test for something other than a stall (S102 review r3, requirement 3).
                $statements[] = $sql;
                $stamps[] = array_key_exists(6, $params) ? (int) $params[6] : null;

                return [];
            }
        );

        $collector = new StatsCollector($db);
        $lastWrite = new \ReflectionProperty(StatsCollector::class, 'snapshotRunLastWriteNs');
        $second = new \ReflectionProperty(StatsCollector::class, 'snapshotRunSecond');

        $collector->recordStorageSnapshot('movie', 1, 1);

        $this->assertCount(1, $statements, 'The first storage write must reach the connection');
        $this->assertStringContainsString(
            'FROM_UNIXTIME(?)',
            $statements[0],
            'The run stamp must be BOUND as a unix second, not re-evaluated as NOW() per row — '
            . 'with NOW() there is no run and therefore nothing that can stall'
        );
        $this->assertNotContains(
            null,
            $stamps,
            'The 7th bound parameter IS the run stamp; without it there is no run to stall'
        );

        $firstRun = (int) $second->getValue($collector);
        $this->assertNotSame(0, $firstRun, 'Precondition: the first storage write opens a run');

        // A second write with no gap at all is the same run — the "continue" arm exists.
        $collector->recordStorageSnapshot('series', 1, 2);
        $this->assertSame($firstRun, (int) $second->getValue($collector));
        $this->assertSame($stamps[0], $stamps[1], 'The first two writes are one run');

        // A value no recomputation can produce, so "reused" and "recomputed" differ.
        $sentinel = time() - 12_345;
        $second->setValue($collector, $sentinel);

        $gapNs = (int) round($gapSeconds * 1_000_000_000);
        $startNs = hrtime(true);
        $lastWrite->setValue($collector, $startNs - $gapNs);
        $collector->recordStorageSnapshot('music', 1, 3);
        $overheadNs = hrtime(true) - $startNs;

        $this->assertCount(3, $stamps, 'Three writes, three stamps');

        if ($expectSameRun) {
            // The collector saw a gap in [$gapNs, $gapNs + $overheadNs]. Assert the case
            // was UNAMBIGUOUSLY inside the window, so a pathologically slow box produces
            // this message instead of a false verdict on the arithmetic.
            $this->assertLessThan(
                self::DOCUMENTED_GAP_WINDOW_NS - $gapNs,
                $overheadNs,
                sprintf(
                    'Probe overhead %.3f ms pushed a %.3f s gap onto the far side of the %.0f s '
                    . 'window; the case is ambiguous, not wrong',
                    $overheadNs / 1_000_000,
                    $gapSeconds,
                    self::DOCUMENTED_GAP_WINDOW_NS / 1_000_000_000
                )
            );
            $this->assertSame(
                $sentinel,
                $stamps[2],
                sprintf(
                    'A gap of %.3f s is inside the %.0f s window, so the write must REUSE the run '
                    . 'stamp; a fresh value here means the run expired early',
                    $gapSeconds,
                    self::DOCUMENTED_GAP_WINDOW_NS / 1_000_000_000
                )
            );
            $this->assertSame($sentinel, (int) $second->getValue($collector), 'The run was not restarted');

            return;
        }

        $this->assertNotSame(
            $sentinel,
            $stamps[2],
            sprintf(
                'A gap of %.3f s is %.0f s or more, so the run has STALLED and the stamp must be '
                . 'RECOMPUTED. Reusing it means a run never expires: one long-lived collector then '
                . 'stamps every tick with its boot second and the dashboard SUMS every generation '
                . '(measured 3.00x for three runs, ~5x after a day of 6-hourly ticks)',
                $gapSeconds,
                self::DOCUMENTED_GAP_WINDOW_NS / 1_000_000_000
            )
        );
        $this->assertLessThanOrEqual(
            2,
            abs(time() - $stamps[2]),
            'A recomputed stamp is the new run\'s own wall-clock second'
        );
        $this->assertSame($stamps[2], (int) $second->getValue($collector), 'The new stamp is memoised');
    }

    /**
     * The run stamp is per INSTANCE, so two collectors are two runs and cannot be
     * merged into one `recorded_at` generation.
     *
     * ⚠ Per-INSTANCE is what this proves, and per-instance is NOT per-coroutine:
     * `AdminServicesProvider` registers `StatsCollector::class => autowire()` and
     * php-di's `autowire()` is a singleton per container, so two coroutines that both
     * resolve the collector from the container hold the SAME instance and DO share a
     * stamp (S102 review r3, LOW-1a — documented on
     * {@see \Phlix\Stats\StatsCollector::snapshotRunSecond()}; the DI registration is
     * out of S102's scope). Nothing here should be read as a coroutine guarantee.
     *
     * The second collector's stamp is checked against a SENTINEL planted on the first,
     * not merely against 0: "the other one is still zero" also holds when the stamp
     * mechanism is absent entirely, whereas inheriting the sentinel is specifically
     * what a shared (static) memo would do.
     */
    public function testTheRunStampDoesNotLeakBetweenCollectorInstances(): void
    {
        $reflection = new \ReflectionProperty(StatsCollector::class, 'snapshotRunSecond');
        $lastWrite = new \ReflectionProperty(StatsCollector::class, 'snapshotRunLastWriteNs');

        /** @var list<int|null> $otherStamps */
        $otherStamps = [];
        $otherDb = $this->createMock(Connection::class);
        $otherDb->method('query')->willReturnCallback(
            static function (string $sql, array $params) use (&$otherStamps): array {
                // Recorded, not asserted — StatsCollector::write() would contain the
                // ExpectationFailedException. See the stall test above.
                $otherStamps[] = array_key_exists(6, $params) ? (int) $params[6] : null;

                return [];
            }
        );

        $first = new StatsCollector($this->createMock(Connection::class));
        $second = new StatsCollector($otherDb);

        $first->recordStorageSnapshot('movie', 1, 1);

        $this->assertNotSame(
            0,
            $reflection->getValue($first),
            'The writing collector has a run (with no run stamp at all there is nothing to leak, so '
            . 'this test would otherwise pass vacuously)'
        );
        $this->assertSame(0, $reflection->getValue($second), 'A second collector must start with none');

        // Give the first collector a live run carrying an impossible stamp; the second
        // collector's own first write must not pick it up.
        $sentinel = time() - 12_345;
        $reflection->setValue($first, $sentinel);
        $lastWrite->setValue($first, hrtime(true));

        $second->recordStorageSnapshot('movie', 1, 1);

        $this->assertSame(
            $sentinel,
            (int) $reflection->getValue($first),
            'The first collector keeps its own run'
        );
        $this->assertNotSame(
            $sentinel,
            $otherStamps[0],
            'A run stamp must never leak from one collector instance to another — that is what '
            . 'keeps two concurrent snapshot writers (two FPM processes, or two coroutines each '
            . 'constructing its own collector) independent generations'
        );
        $this->assertLessThanOrEqual(2, abs(time() - $otherStamps[0]), 'The second collector stamped its own run');
    }

    /**
     * S102 review r1 MED-3 — an unmapped type must NOT be filed under `movie`.
     *
     * `migrations/086_stats_storage_book_bucket.sql:11-14` states the rule: "a
     * wrong number that looks right is worse than a visibly missing one". Before
     * this fix `recordStorageSnapshot('totally-unknown', 3, 999)` wrote
     * `media_type=movie, total_bytes=999` with only an app.log warning.
     */
    public function testAnUnmappedStorageTypeIsDroppedRatherThanFiledUnderMovie(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        (new StatsCollector($db))->recordStorageSnapshot('totally-unknown', 3, 999);
    }

    /**
     * Dropping the unmapped type must not take the mapped ones with it.
     */
    public function testAnUnmappedTypeDoesNotStopTheRestOfTheRun(): void
    {
        /** @var list<string> $buckets */
        $buckets = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use (&$buckets): array {
                $buckets[] = (string) $params[2];

                return [];
            }
        );

        (new StatsCollector($db))->recordStorageSnapshots([
            'movie' => ['count' => 1, 'bytes' => 1_000],
            'podcast' => ['count' => 9, 'bytes' => 999],
            'photo' => ['count' => 2, 'bytes' => 2_000],
        ]);

        $this->assertSame(['movie', 'photo'], $buckets);
    }

    /**
     * S102 review r1 LOW-5 — a persistent write failure must be COUNTED and the
     * log THROTTLED.
     *
     * `ItemRepository::recordChange()` calls `recordLibraryChange()` once per
     * scanned item, so an un-throttled boundary wrote one `error` line per item:
     * ~29,000 lines for the production music library, with nothing countable
     * anywhere. First failure logs immediately; the rest are counted.
     */
    public function testRepeatedWriteFailuresAreCountedAndTheLogIsThrottled(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(new RuntimeException('stats_library_changes is gone'));

        $collector = new StatsCollector($db);
        for ($i = 0; $i < 1_000; $i++) {
            $collector->recordLibraryChange('item_added', 'media-' . $i);
        }

        $counters = StatsCollector::writeFailureCounters();

        $this->assertArrayHasKey('library_change', $counters);
        $this->assertSame(1_000, $counters['library_change']['failures'], 'Every failure must be counted');
        $this->assertSame(
            999,
            $counters['library_change']['suppressed'],
            'Exactly ONE of the 1,000 identical failures may reach the log inside the throttle window'
        );
    }

    /**
     * S102 review r2 LOW-6 — the throttle may hide a REPEAT, never a NEW FAILURE
     * CLASS.
     *
     * Keyed on the operation alone, the second symptom of a broken subsystem was
     * counted but never described: once `library_change` had logged its
     * `RuntimeException`, a `PDOException "MySQL server has gone away"` in the same
     * 60 s window went into `suppressed` and its message was lost (measured: two
     * failures, two classes, ONE log line). The window is now keyed on operation AND
     * exception class, so `suppressed` stays at ZERO for two distinct classes —
     * which is exactly "both were logged", without this test having to read the log
     * file.
     */
    public function testADifferentFailureClassIsNotSuppressedByTheThrottle(): void
    {
        /** @var list<\Throwable> $failures */
        $failures = [
            new RuntimeException('stats_library_changes is gone'),
            new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'),
        ];

        $call = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function () use (&$call, $failures): array {
                throw $failures[$call++] ?? $failures[0];
            }
        );

        $collector = new StatsCollector($db);
        $collector->recordLibraryChange('item_added', 'media-1');
        $collector->recordLibraryChange('item_added', 'media-2');

        $counters = StatsCollector::writeFailureCounters();

        $this->assertSame(2, $counters['library_change']['failures'], 'Both failures must be counted');
        $this->assertSame(
            0,
            $counters['library_change']['suppressed'],
            'A DIFFERENT exception class for an already-failing operation is different news and must '
            . 'reach the log; only a repeat of the same class may be suppressed.'
        );
    }

    /**
     * The other side of LOW-6: the ~29,000 → 1 reduction the throttle exists for
     * must survive the finer key. Same operation, same class, twice ⇒ one line.
     */
    public function testARepeatOfTheSameFailureClassIsStillSuppressed(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(new RuntimeException('stats_library_changes is gone'));

        $collector = new StatsCollector($db);
        $collector->recordLibraryChange('item_added', 'media-1');
        $collector->recordLibraryChange('item_added', 'media-2');

        $counters = StatsCollector::writeFailureCounters();

        $this->assertSame(2, $counters['library_change']['failures']);
        $this->assertSame(1, $counters['library_change']['suppressed']);
    }

    /**
     * The counters are per operation, and bounded by the fixed write vocabulary —
     * nothing a request controls can become a key, so the map cannot grow.
     */
    public function testFailureCountersAreKeyedPerOperationAndBounded(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(new RuntimeException('everything is on fire'));

        $collector = new StatsCollector($db);
        $eventId = $collector->recordPlaybackStart('user-1', 'media-1', 'episode', 'device-1');
        $collector->recordPlaybackEnd($eventId, 60, true);
        $collector->recordLibraryChange('item_added', 'media-1');
        $collector->recordUserActivity('user-1', 'login');
        $collector->recordStorageSnapshot('movie', 1, 1);

        $counters = StatsCollector::writeFailureCounters();

        $this->assertSame(
            ['playback_start', 'playback_end', 'library_change', 'user_activity', 'storage_snapshot'],
            array_keys($counters)
        );
        foreach ($counters as $operation => $state) {
            $this->assertSame(1, $state['failures'], $operation);
            $this->assertSame(0, $state['suppressed'], $operation);
        }
    }

    public function testResetClearsTheFailureCounters(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(new RuntimeException('nope'));

        (new StatsCollector($db))->recordUserActivity('user-1', 'login');
        $this->assertNotSame([], StatsCollector::writeFailureCounters());

        StatsCollector::resetWriteFailureCounters();
        $this->assertSame([], StatsCollector::writeFailureCounters());
    }

    public function testRecordPlaybackEndCalculatesDuration(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE stats_playback_events'),
                $this->callback(function ($params) {
                    return count($params) === 3
                        && $params[0] === 3600 // duration_seconds
                        && $params[1] === true // completed
                        && $params[2] === 'event-123'; // eventId
                })
            );

        $collector = new StatsCollector($db);
        $collector->recordPlaybackEnd('event-123', 3600, true);
    }

    public function testRecordLibraryChangeStoresChange(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO stats_library_changes'),
                $this->callback(function ($params) {
                    return count($params) === 6
                        && $params[0] !== '' // id
                        && $params[1] === 'item_added'
                        && $params[2] === 'media-456'
                        && $params[3] === 'lib-123'
                        && $params[4] === 'user-789'
                        && $params[5] !== null; // details_json
                })
            );

        $collector = new StatsCollector($db);
        $collector->recordLibraryChange('item_added', 'media-456', 'lib-123', 'user-789', ['path' => '/movies/test.mkv']);
    }

    public function testRecordUserActivityStoresActivity(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO stats_user_activity'),
                $this->callback(function ($params) {
                    return count($params) === 6
                        && $params[0] !== '' // id
                        && $params[1] === 'user-123'
                        && $params[2] === 'login'
                        && $params[3] === '192.168.1.1'
                        && $params[4] === null // user_agent
                        && $params[5] !== null; // details_json
                })
            );

        $collector = new StatsCollector($db);
        $collector->recordUserActivity('user-123', 'login', '192.168.1.1', ['device' => 'Chrome']);
    }

    public function testGetTopUsersAggregatesWatchTime(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'user_id' => 'user-123',
                'total_watch_time' => '36000',
                'play_count' => '10',
            ],
            [
                'user_id' => 'user-456',
                'total_watch_time' => '18000',
                'play_count' => '5',
            ],
        ]);

        $collector = new StatsCollector($db);
        $topUsers = $collector->getTopUsers(10, null);

        $this->assertCount(2, $topUsers);
        $this->assertEquals('user-123', $topUsers[0]['user_id']);
        $this->assertEquals(36000, $topUsers[0]['total_watch_time']);
        $this->assertEquals(10, $topUsers[0]['play_count']);
        $this->assertEquals('user-456', $topUsers[1]['user_id']);
        $this->assertEquals(18000, $topUsers[1]['total_watch_time']);
        $this->assertEquals(5, $topUsers[1]['play_count']);
    }

    public function testGetTopMediaAggregatesPlayCount(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'media_item_id' => 'media-001',
                'play_count' => '25',
                'total_duration' => '90000',
            ],
            [
                'media_item_id' => 'media-002',
                'play_count' => '15',
                'total_duration' => '45000',
            ],
        ]);

        $collector = new StatsCollector($db);
        $topMedia = $collector->getTopMedia(10, null);

        $this->assertCount(2, $topMedia);
        $this->assertEquals('media-001', $topMedia[0]['media_item_id']);
        $this->assertEquals(25, $topMedia[0]['play_count']);
        $this->assertEquals(90000, $topMedia[0]['total_duration']);
        $this->assertEquals('media-002', $topMedia[1]['media_item_id']);
        $this->assertEquals(15, $topMedia[1]['play_count']);
        $this->assertEquals(45000, $topMedia[1]['total_duration']);
    }

    public function testGetTopUsersInnerJoinsUsersToExcludeOrphans(): void
    {
        // S14: orphan exclusion is enforced at the query level — getTopUsers()
        // INNER JOINs `users` so playback events belonging to a since-deleted
        // account are dropped before aggregation and can never surface as a
        // blank "Top Users" row. Assert the JOIN is present and the aggregates
        // are unchanged (the PK join is 1:1, so no COUNT/SUM fan-out).
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('INNER JOIN users u ON e.user_id = u.id'),
                    $this->stringContains('COALESCE(SUM(e.duration_seconds), 0) AS total_watch_time'),
                    $this->stringContains('COUNT(*) AS play_count')
                ),
                $this->anything()
            )
            ->willReturn([
                [
                    'user_id' => 'user-live',
                    'total_watch_time' => '36000',
                    'play_count' => '10',
                ],
            ]);

        $collector = new StatsCollector($db);
        $topUsers = $collector->getTopUsers(10, null);

        // Regression: the surviving row's aggregates pass through unchanged by
        // the join (no double-count).
        $this->assertCount(1, $topUsers);
        $this->assertSame('user-live', $topUsers[0]['user_id']);
        $this->assertSame(36000, $topUsers[0]['total_watch_time']);
        $this->assertSame(10, $topUsers[0]['play_count']);
    }

    public function testGetTopMediaInnerJoinsMediaItemsToExcludeOrphans(): void
    {
        // S14: orphan exclusion is enforced at the query level — getTopMedia()
        // INNER JOINs `media_items` so plays of a since-deleted item are dropped
        // before aggregation and can never surface as a blank / no-title row.
        // Assert the JOIN is present and the aggregates are unchanged (the PK
        // join is 1:1, so no COUNT/SUM fan-out).
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('INNER JOIN media_items mi ON e.media_item_id = mi.id'),
                    $this->stringContains('COUNT(*) AS play_count'),
                    $this->stringContains('COALESCE(SUM(e.duration_seconds), 0) AS total_duration')
                ),
                $this->anything()
            )
            ->willReturn([
                [
                    'media_item_id' => 'media-live',
                    'play_count' => '25',
                    'total_duration' => '90000',
                ],
            ]);

        $collector = new StatsCollector($db);
        $topMedia = $collector->getTopMedia(10, null);

        // Regression: the surviving row's aggregates pass through unchanged by
        // the join (no double-count).
        $this->assertCount(1, $topMedia);
        $this->assertSame('media-live', $topMedia[0]['media_item_id']);
        $this->assertSame(25, $topMedia[0]['play_count']);
        $this->assertSame(90000, $topMedia[0]['total_duration']);
    }

    public function testGetPlaybackStatsReturnsTimeSeries(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'date' => '2024-01-01',
                'play_count' => '100',
                'total_duration' => '360000',
                'completed_count' => '50',
            ],
            [
                'date' => '2024-01-02',
                'play_count' => '120',
                'total_duration' => '432000',
                'completed_count' => '60',
            ],
        ]);

        $collector = new StatsCollector($db);
        $from = new DateTime('2024-01-01');
        $to = new DateTime('2024-01-02');
        $stats = $collector->getPlaybackStats($from, $to);

        $this->assertCount(2, $stats);
        $this->assertEquals('2024-01-01', $stats[0]['date']);
        $this->assertEquals(100, $stats[0]['play_count']);
        $this->assertEquals(360000, $stats[0]['total_duration']);
        $this->assertEquals(50, $stats[0]['completed_count']);
        $this->assertEquals('2024-01-02', $stats[1]['date']);
        $this->assertEquals(120, $stats[1]['play_count']);
        $this->assertEquals(432000, $stats[1]['total_duration']);
        $this->assertEquals(60, $stats[1]['completed_count']);
    }
}
