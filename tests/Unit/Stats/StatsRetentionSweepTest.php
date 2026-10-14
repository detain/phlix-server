<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Stats;

use PDOException;
use Phlix\Config\EffectiveConfig;
use Phlix\Plugins\Util\RecursiveDelete;
use Phlix\Stats\StatsCollector;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit pins for the R19-10 bounded retention sweeper
 * ({@see StatsCollector::sweepRetention()}).
 *
 * The sweeper is the fix for the two stats tables that previously grew without
 * any bound (stats_library_changes, stats_user_activity). What matters and is
 * therefore pinned here — with a mocked Connection, no database needed:
 *
 *  - retentionDays() fail-safe coercion: missing/junk/non-positive config
 *    values fall back to DEFAULT_RETENTION_DAYS (90), never to "delete
 *    everything" and never to "never delete"; absurd highs clamp to 36500.
 *  - The two-phase batch shape: a non-locking `SELECT id ... ORDER BY id
 *    LIMIT 1000` harvest followed by exactly one `DELETE ... WHERE id IN (...)`
 *    per harvested batch (the hub ServerReaper::sweepHeartbeats idiom).
 *  - DB-clock cutoffs: `DATE_SUB(NOW(), INTERVAL ? DAY)` on both tables, so a
 *    host/DB timezone split cannot skew the window (R19-03 law).
 *  - stats_playback_events is never touched (documented carve-out).
 *  - Deadlock retry x3 of the WHOLE batch (re-harvest then re-delete); any
 *    non-deadlock PDO failure propagates on the first attempt; the third
 *    deadlock propagates.
 */
final class StatsRetentionSweepTest extends TestCase
{
    /** @var list<string> minted config roots removed in tearDown (S439 zero-residue). */
    private array $mintedConfigRoots = [];

    protected function setUp(): void
    {
        parent::setUp();
        EffectiveConfig::reset();
    }

    protected function tearDown(): void
    {
        EffectiveConfig::reset();
        foreach ($this->mintedConfigRoots as $root) {
            RecursiveDelete::remove($root);
        }
        $this->mintedConfigRoots = [];
        parent::tearDown();
    }

    /**
     * Point EffectiveConfig at a throwaway config dir holding only stats.php
     * (absent when $stats is null), with zero server_settings overrides.
     *
     * @param array<string, mixed>|null $stats contents of stats.php, or null to omit the file
     */
    private function bootstrapStatsConfig(?array $stats): void
    {
        $dir = sys_get_temp_dir() . '/phlix_stats_sweep_' . uniqid('', true) . '/config';
        mkdir($dir, 0o777, true);
        $this->mintedConfigRoots[] = dirname($dir);
        if ($stats !== null) {
            file_put_contents($dir . '/stats.php', '<?php return ' . var_export($stats, true) . ";\n");
        }

        $settingsDb = $this->createMock(Connection::class);
        $settingsDb->method('query')->willReturnCallback(
            static fn(): array => []
        );

        EffectiveConfig::bootstrap($settingsDb, null, $dir);
    }

    /**
     * @param callable(string, mixed): mixed $handler
     */
    private function collector(callable $handler): StatsCollector
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback($handler);
        return new StatsCollector($db);
    }

    public function testRetentionDaysFallsBackToTheDefaultWhenConfigIsAbsent(): void
    {
        $this->bootstrapStatsConfig(null);
        $this->assertSame(StatsCollector::DEFAULT_RETENTION_DAYS, StatsCollector::retentionDays());

        $this->bootstrapStatsConfig([]);
        $this->assertSame(90, StatsCollector::retentionDays());
    }

    public function testRetentionDaysReadsTheConfiguredValue(): void
    {
        $this->bootstrapStatsConfig(['retention_days' => 30]);
        $this->assertSame(30, StatsCollector::retentionDays());

        // Numeric strings arrive verbatim from env-style config; they parse.
        $this->bootstrapStatsConfig(['retention_days' => '45']);
        $this->assertSame(45, StatsCollector::retentionDays());
    }

    public function testRetentionDaysCoercesJunkAndSubOneValuesToTheDefault(): void
    {
        // None of these may mean "delete everything" (0 / negative) or "read
        // a truthy string as days" ('abc').
        foreach ([0, -5, 'abc', '', null, [], new \stdClass()] as $junk) {
            $this->bootstrapStatsConfig(['retention_days' => $junk]);
            $this->assertSame(
                StatsCollector::DEFAULT_RETENTION_DAYS,
                StatsCollector::retentionDays(),
                'junk retention_days must fall back to the default, got ' . var_export($junk, true)
            );
        }
    }

    public function testRetentionDaysClampsAbsurdlyHighValues(): void
    {
        $this->bootstrapStatsConfig(['retention_days' => 999999]);
        $this->assertSame(36500, StatsCollector::retentionDays());
    }

    public function testSweepHarvestsThenDeletesPerTableUsingDbClockCutoffs(): void
    {
        $this->bootstrapStatsConfig(['retention_days' => 30]);

        $statements = [];
        $collector = $this->collector(
            static function ($sql, $params = null) use (&$statements) {
                $statements[] = [(string) $sql, $params];
                if (str_contains((string) $sql, 'DELETE')) {
                    return str_contains((string) $sql, 'stats_library_changes') ? 2 : 1;
                }
                if (str_contains((string) $sql, 'stats_library_changes')) {
                    return [['id' => 1], ['id' => 2]];
                }
                if (str_contains((string) $sql, 'stats_user_activity')) {
                    // Driver may hand back numeric strings — they must survive.
                    return [['id' => '7']];
                }
                return [];
            }
        );

        $this->assertSame(3, $collector->sweepRetention());

        // Exactly four statements: SELECT+DELETE per table, no extras.
        $this->assertCount(4, $statements);

        [$libSelect, $libDelete, $actSelect, $actDelete] = $statements;

        $this->assertStringContainsString(
            'SELECT id FROM stats_library_changes WHERE changed_at < DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY id LIMIT 1000',
            $libSelect[0]
        );
        $this->assertSame([30], $libSelect[1]);
        $this->assertSame('DELETE FROM stats_library_changes WHERE id IN (?,?)', $libDelete[0]);
        $this->assertSame([1, 2], $libDelete[1]);

        $this->assertStringContainsString(
            'SELECT id FROM stats_user_activity WHERE occurred_at < DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY id LIMIT 1000',
            $actSelect[0]
        );
        $this->assertSame([30], $actSelect[1]);
        $this->assertSame('DELETE FROM stats_user_activity WHERE id IN (?)', $actDelete[0]);
        $this->assertSame(['7'], $actDelete[1]);
    }

    /**
     * The PRODUCTION id shape: both stats tables' rows are minted with
     * generateUuid() (recordLibraryChange/recordUserActivity), so the harvest
     * hands back 36-char UUID strings — the original numeric-only acceptance
     * filter silently deleted nothing on a real schema (caught by the
     * real-DB lane). Ids bind through the DELETE verbatim; only genuinely
     * unusable values (null/empty/non-scalar) skip the batch.
     */
    public function testUuidPrimaryKeysSurviveHarvestAndBindVerbatimIntoTheDelete(): void
    {
        $this->bootstrapStatsConfig(['retention_days' => 90]);

        $uuid = '0f3c9a51-7e0b-4c2d-9f6a-1b2c3d4e5f60';
        $statements = [];
        $collector = $this->collector(
            static function ($sql, $params = null) use (&$statements, $uuid) {
                $statements[] = [(string) $sql, $params];
                if (str_contains((string) $sql, 'DELETE')) {
                    return 3;
                }
                if (str_contains((string) $sql, 'stats_library_changes')) {
                    return [['id' => $uuid], ['id' => null], ['id' => '']];
                }
                return [];
            }
        );

        $this->assertSame(3, $collector->sweepRetention());

        $deletes = array_values(array_filter($statements, static fn (array $s): bool => str_contains($s[0], 'DELETE')));
        $this->assertCount(1, $deletes, 'only the library table had a harvest');
        $this->assertSame('DELETE FROM stats_library_changes WHERE id IN (?)', $deletes[0][0]);
        $this->assertSame([$uuid], $deletes[0][1], 'the UUID binds verbatim; null/empty ids are skipped');
    }

    public function testSweepWithoutExpiredRowsNeverEmitsADelete(): void
    {
        $this->bootstrapStatsConfig(null);

        $statements = [];
        $collector = $this->collector(
            static function ($sql, $params = null) use (&$statements) {
                $statements[] = [(string) $sql, $params];
                return [];
            }
        );

        $this->assertSame(0, $collector->sweepRetention());
        $this->assertCount(2, $statements); // two harvests, zero deletes
        foreach ($statements as [$sql]) {
            $this->assertStringStartsWith('SELECT id FROM', $sql);
        }
    }

    public function testAFullBatchKeepsDrainingAndAShortBatchStopsTheLoop(): void
    {
        $this->bootstrapStatsConfig(null);

        $fullBatch = array_map(static fn(int $n): array => ['id' => $n], range(1, 1000));

        $libSelects = 0;
        $libDeletes = 0;
        $collector = $this->collector(
            static function ($sql, $params = null) use (&$libSelects, &$libDeletes, $fullBatch) {
                $sql = (string) $sql;
                if (!str_contains($sql, 'stats_library_changes')) {
                    return []; // stats_user_activity: nothing expired.
                }
                if (str_contains($sql, 'DELETE')) {
                    $libDeletes++;
                    return $libSelects === 1 ? 1000 : 5;
                }
                $libSelects++;
                return $libSelects === 1 ? $fullBatch : [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4], ['id' => 5]];
            }
        );

        // 1000 (full → continue) + 5 (short → stop). One more SELECT than DELETE
        // never happens here: the short batch both deletes and breaks.
        $this->assertSame(1005, $collector->sweepRetention());
        $this->assertSame(2, $libSelects);
        $this->assertSame(2, $libDeletes);
    }

    public function testTheBatchCapBoundsOneTick(): void
    {
        $this->bootstrapStatsConfig(null);

        $fullBatch = array_map(static fn(int $n): array => ['id' => $n], range(1, 1000));
        $selects = 0;
        $collector = $this->collector(
            static function ($sql, $params = null) use (&$selects, $fullBatch) {
                $sql = (string) $sql;
                if (!str_contains($sql, 'stats_library_changes')) {
                    return [];
                }
                if (str_contains($sql, 'DELETE')) {
                    return 1000;
                }
                $selects++;
                return $fullBatch; // table stays saturated — the cap must stop the tick
            }
        );

        $this->assertSame(20000, $collector->sweepRetention());
        $this->assertSame(20, $selects); // RETENTION_SWEEP_MAX_BATCHES_PER_TABLE
    }

    public function testADeadlockRetriesTheWholeBatchAndSucceeds(): void
    {
        $this->bootstrapStatsConfig(null);

        $libSelects = 0;
        $libDeletes = 0;
        $collector = $this->collector(
            static function ($sql, $params = null) use (&$libSelects, &$libDeletes) {
                $sql = (string) $sql;
                if (!str_contains($sql, 'stats_library_changes')) {
                    return []; // user_activity idle
                }
                if (str_contains($sql, 'DELETE')) {
                    $libDeletes++;
                    if ($libDeletes === 1) {
                        throw new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
                    }
                    return 1;
                }
                $libSelects++;
                return [['id' => 11]];
            }
        );

        $this->assertSame(1, $collector->sweepRetention());
        // The retry re-harvests before re-deleting (the batch is retried as a whole).
        $this->assertSame(2, $libSelects);
        $this->assertSame(2, $libDeletes);
    }

    public function testANonDeadlockFailurePropagatesOnFirstAttempt(): void
    {
        $this->bootstrapStatsConfig(null);

        $selects = 0;
        $collector = $this->collector(
            static function ($sql, $params = null) use (&$selects) {
                $sql = (string) $sql;
                if (str_contains($sql, 'DELETE')) {
                    throw new PDOException('SQLSTATE[HY000]: Connection timed out');
                }
                $selects++;
                return str_contains($sql, 'stats_library_changes') ? [['id' => 1]] : [];
            }
        );

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Connection timed out');

        try {
            $collector->sweepRetention();
        } finally {
            // No retry for a non-deadlock failure: exactly one harvest happened.
            $this->assertSame(1, $selects);
        }
    }

    public function testRepeatedDeadlocksPropagateAfterTheThirdAttempt(): void
    {
        $this->bootstrapStatsConfig(null);

        $deletes = 0;
        $collector = $this->collector(
            static function ($sql, $params = null) use (&$deletes) {
                $sql = (string) $sql;
                if (str_contains($sql, 'DELETE')) {
                    $deletes++;
                    throw new PDOException('Deadlock found when trying to get lock');
                }
                return str_contains($sql, 'stats_library_changes') ? [['id' => 1]] : [];
            }
        );

        $this->expectException(PDOException::class);

        try {
            $collector->sweepRetention();
        } finally {
            $this->assertSame(3, $deletes); // RETENTION_SWEEP_MAX_RETRIES
        }
    }

    public function testTheSweeperNeverTouchesStatsPlaybackEvents(): void
    {
        $this->bootstrapStatsConfig(null);

        $statements = [];
        $collector = $this->collector(
            static function ($sql, $params = null) use (&$statements) {
                $statements[] = (string) $sql;
                return [];
            }
        );

        $collector->sweepRetention();

        // stats_playback_events carries the dashboard's historical time series;
        // its lifecycle is an owner decision, not this sweeper's (documented
        // carve-out in sweepRetention()).
        foreach ($statements as $sql) {
            $this->assertStringNotContainsString('stats_playback_events', $sql);
        }
    }
}
