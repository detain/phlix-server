<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session;

use Phlix\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * minors M-SERVER-85 (W-min109) — the stale-session sweep used to HARVEST ids
 * under one NOW() evaluation and then DELETE under a SECOND, re-evaluated
 * NOW(): seconds ticking between the two statements silently enlarged the
 * window, so the returned count could (honestly, always in one direction)
 * UNDERSTATE what was actually removed. The cure rides the house two-phase
 * law already documented for the stats retention sweep (StatsCollector
 * R19-10 / hub ServerReaper): a non-locking `SELECT id` harvest followed by
 * `DELETE ... WHERE id IN (...)` — never a DELETE with an unbounded range
 * predicate. The count is now, by construction, exactly the set removed.
 */
final class SessionManagerCleanupCountTest extends TestCase
{
    public function testDeleteRidesTheHarvestedIdsNotASecondNowEvaluation(): void
    {
        $db = $this->createMock(Connection::class);
        $seen = [];
        $db->method('query')->willReturnCallback(
            /** @return mixed */
            function (string $sql, array $params = []) use (&$seen) {
                $seen[] = [$sql, $params];

                return stripos(ltrim($sql), 'SELECT') === 0
                    ? [['id' => 's-1'], ['id' => 's-2']]
                    : 2;
            }
        );

        $manager = new SessionManager($db);
        $count = $manager->cleanupStaleSessions(3600);

        self::assertSame(2, $count, 'the sweep reports exactly what it harvested');
        self::assertCount(2, $seen, 'one harvest + one delete');

        [$deleteSql, $deleteParams] = $seen[1];
        self::assertStringContainsString('DELETE FROM sessions', $deleteSql);
        self::assertStringContainsString('WHERE id IN (?,?)', $deleteSql);
        self::assertSame(['s-1', 's-2'], $deleteParams, 'ids bind through placeholders, never SQL');

        $nowEvaluations = 0;
        foreach ($seen as [$sql, $params]) {
            $nowEvaluations += substr_count($sql, 'NOW()');
        }
        self::assertSame(1, $nowEvaluations, 'R19-03 DB-clock cutoff evaluated ONCE (M-SERVER-85)');
    }

    public function testEmptyHarvestDeletesNothingAndReportsZero(): void
    {
        $db = $this->createMock(Connection::class);
        $seen = [];
        $db->method('query')->willReturnCallback(
            /** @return mixed */
            function (string $sql, array $params = []) use (&$seen) {
                $seen[] = [$sql, $params];

                return [];
            }
        );

        $manager = new SessionManager($db);

        self::assertSame(0, $manager->cleanupStaleSessions(3600));
        self::assertCount(1, $seen, 'no DELETE is issued for an empty harvest');
    }
}
