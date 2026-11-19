<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use PDOException;
use Phlix\Common\Database\PruneDeadline;
use Phlix\Common\Database\RetentionPrune;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * D-14 engine pins for {@see RetentionPrune} — the batch-boundary,
 * loop-until-drained, id-filter, deadlock-retry, and soft/hard failure arms
 * generalized from the stats sweeper exemplar. Mocked Connection, no DB.
 */
final class RetentionPruneTest extends TestCase
{
    /** @var list<array{sql: string, params: mixed}> */
    private array $statements = [];

    /** @param callable(string, mixed): mixed $handler */
    private function connection(callable $handler): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function ($sql, $params = null) use ($handler) {
                $this->statements[] = ['sql' => (string) $sql, 'params' => $params];
                return $handler($sql, $params);
            }
        );
        return $db;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStatements();
    }

    /**
     * Reset through a method boundary: an inline `$this->statements = []`
     * narrows the property to the empty-shape for the rest of the function,
     * which is what phpcs/phpstan must not misread as dead offsets below.
     */
    private function resetStatements(): void
    {
        $this->statements = [];
    }

    /**
     * Capture what the run writes to the error channel (estate idiom,
     * {@see EnvConfigTest} — HUB-5.2 swallow must be LOUD on that channel).
     *
     * @param callable(): void $run
     */
    private function captureErrorLog(callable $run): string
    {
        $file = tempnam(sys_get_temp_dir(), 'd14log');
        self::assertIsString($file);
        $previous = (string) ini_get('error_log');
        ini_set('error_log', $file);

        try {
            $run();

            return (string) file_get_contents($file);
        } finally {
            ini_set('error_log', $previous);
            unlink($file);
        }
    }

    // ---------------------------------------------------------------- sweep()

    public function testSweepRendersTheSitesCommittedOnePhaseSqlByteIdentically(): void
    {
        // Dataset = every migrated statement shape, before/after identity law.
        $cases = [
            [
                'table' => 'rate_limit_buckets', 'column' => 'reset_at',
                'deadline' => PruneDeadline::atMostUnixTime(1_700_000_123),
                'extra' => '', 'extraParams' => [],
                'sql' => 'DELETE FROM rate_limit_buckets WHERE reset_at <= ? LIMIT ?',
                'params' => [1_700_000_123, 100],
            ],
            [
                'table' => 'oauth_state_store', 'column' => 'expires_at',
                'deadline' => PruneDeadline::notAfterNow(),
                'extra' => '', 'extraParams' => [],
                'sql' => 'DELETE FROM oauth_state_store WHERE expires_at <= NOW() LIMIT ?',
                'params' => [100],
            ],
            [
                'table' => 'oauth_state_store', 'column' => 'expires_at',
                'deadline' => PruneDeadline::notAfterNow(),
                'extra' => 'provider = ?', 'extraParams' => ['quick_connect'],
                'sql' => 'DELETE FROM oauth_state_store WHERE provider = ? AND expires_at <= NOW() LIMIT ?',
                'params' => ['quick_connect', 100],
            ],
            [
                'table' => 'auth_refresh_grants', 'column' => 'expires_at',
                'deadline' => PruneDeadline::beforeNowMinusSeconds(86_400),
                'extra' => '', 'extraParams' => [],
                'sql' => 'DELETE FROM auth_refresh_grants WHERE expires_at < NOW() - INTERVAL 86400 SECOND LIMIT ?',
                'params' => [1000],
            ],
            [
                'table' => 'auth_refresh_grants', 'column' => 'created_at',
                'deadline' => PruneDeadline::beforeNowMinusSeconds(31_536_000),
                'extra' => 'expires_at IS NULL', 'extraParams' => [],
                'sql' => 'DELETE FROM auth_refresh_grants WHERE expires_at IS NULL AND created_at < NOW() - INTERVAL 31536000 SECOND LIMIT ?',
                'params' => [1000],
            ],
            [
                'table' => 'casting_sessions', 'column' => 'last_seen_at',
                'deadline' => PruneDeadline::beforeDateSubNowSeconds(60),
                'extra' => '', 'extraParams' => [],
                'sql' => 'DELETE FROM casting_sessions WHERE last_seen_at < DATE_SUB(NOW(), INTERVAL 60 SECOND) LIMIT ?',
                'params' => [200],
            ],
        ];

        foreach ($cases as $case) {
            $this->resetStatements();
            $deleted = RetentionPrune::sweep(
                $this->connection(static fn(): int => 7),
                $case['table'],
                $case['column'],
                $case['deadline'],
                (int) end($case['params']),
                $case['extra'],
                $case['extraParams']
            );
            $this->assertSame(7, $deleted);
            $statements = $this->statements;
            $this->assertCount(1, $statements);
            $this->assertSame($case['sql'], $statements[0]['sql']);
            $this->assertSame($case['params'], $statements[0]['params']);
        }
    }

    public function testSubOneBatchRefusesToSweep(): void
    {
        // LIMIT 0 churns and LIMIT -1 is a syntax error; either would mean a
        // mis-set site constant, so the engine refuses without a statement.
        foreach ([0, -1, -100] as $batch) {
            $this->resetStatements();
            $deleted = RetentionPrune::sweep(
                $this->connection(static fn(): int => self::fail('no statement may run')),
                'oauth_state_store',
                'expires_at',
                PruneDeadline::notAfterNow(),
                $batch
            );
            $this->assertSame(0, $deleted, 'batch ' . $batch . ' must refuse');
            $this->assertSame([], $this->statements);
        }
    }

    public function testSweepCoercesTheDriverWriteCount(): void
    {
        $db = $this->connection(static fn(): mixed => '42');
        $this->assertSame(42, RetentionPrune::sweep($db, 't', 'c', PruneDeadline::notAfterNow(), 100));

        $db = $this->connection(static fn(): mixed => null);
        $this->assertSame(0, RetentionPrune::sweep($db, 't', 'c', PruneDeadline::notAfterNow(), 100));
    }

    public function testHardArmPropagatesAndSoftArmSwallowsSweepFailure(): void
    {
        $boom = static fn(): mixed => throw new RuntimeException('db down');

        $this->expectException(RuntimeException::class);
        RetentionPrune::sweep($this->connection($boom), 't', 'c', PruneDeadline::notAfterNow(), 100, failLoud: true);
    }

    public function testSoftArmReturnsZeroOnSweepFailure(): void
    {
        $deleted = null;
        $log = $this->captureErrorLog(function () use (&$deleted): void {
            $deleted = RetentionPrune::sweep(
                $this->connection(static fn(): mixed => throw new RuntimeException('db down')),
                't',
                'c',
                PruneDeadline::notAfterNow(),
                100,
                failLoud: false
            );
        });

        $this->assertSame(0, $deleted);
        $this->assertStringContainsString('RetentionPrune soft-fail: sweep on t.c threw: db down', $log);
    }

    // ----------------------------------------------------------- sweepByIds()

    public function testTwoPhaseHarvestUsesTheExemplarSqlShapes(): void
    {
        $deleted = RetentionPrune::sweepByIds(
            $this->connection(
                static fn(string $sql): mixed => str_contains($sql, 'DELETE') ? 2 : [['id' => 1], ['id' => 'abc']]
            ),
            'stats_library_changes',
            'changed_at',
            PruneDeadline::beforeDateSubNowDays(30),
            1000,
            20,
            3
        );

        $this->assertSame(2, $deleted);
        $this->assertSame('SELECT id FROM stats_library_changes WHERE changed_at < DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY id LIMIT 1000', $this->statements[0]['sql']);
        $this->assertSame([30], $this->statements[0]['params']);
        $this->assertSame('DELETE FROM stats_library_changes WHERE id IN (?,?)', $this->statements[1]['sql']);
        $this->assertSame([1, 'abc'], $this->statements[1]['params']);
    }

    public function testEmptyHarvestEmitsNoDelete(): void
    {
        $deleted = RetentionPrune::sweepByIds(
            $this->connection(static fn(): array => []),
            't',
            'c',
            PruneDeadline::beforeDateSubNowDays(1),
            10,
            20,
            3
        );

        $this->assertSame(0, $deleted);
        $this->assertCount(1, $this->statements);
        $this->assertStringStartsWith('SELECT', $this->statements[0]['sql']);
    }

    public function testIdFilterLawKeepsUsableScalarsAndDropsTheRest(): void
    {
        $uuid = '0f3c9a51-7e0b-4c2d-9f6a-1b2c3d4e5f60';
        RetentionPrune::sweepByIds(
            $this->connection(
                static fn(string $sql): mixed => str_contains($sql, 'DELETE')
                    ? 4
                    : [['id' => 0], ['id' => $uuid], ['id' => '7'], ['id' => -1], ['id' => ''], ['id' => null], ['id' => true], 'not-an-array']
            ),
            't',
            'c',
            PruneDeadline::beforeDateSubNowDays(1),
            10,
            20,
            3
        );

        // int >= 0, or non-empty string — the exemplar law, verbatim: 0 and
        // the UUID and the numeric string ride; negatives/empty/null/bool and
        // non-array rows skip.
        $this->assertSame([0, $uuid, '7'], $this->statements[1]['params']);
    }

    public function testFullBatchKeepsDrainingAndShortBatchStopsTheLoop(): void
    {
        // Batch boundary law: a delete of exactly $batch continues the loop;
        // the short batch both deletes and breaks.
        $rounds = 0;
        $deleted = RetentionPrune::sweepByIds(
            $this->connection(
                static function (string $sql) use (&$rounds): mixed {
                    if (str_contains($sql, 'DELETE')) {
                        return $rounds === 1 ? 2 : 1;
                    }
                    $rounds++;
                    return $rounds === 1 ? [['id' => 1], ['id' => 2]] : [['id' => 3]];
                }
            ),
            't',
            'c',
            PruneDeadline::beforeDateSubNowDays(1),
            2,
            20,
            3
        );

        $this->assertSame(3, $deleted);
        $this->assertSame(2, $rounds);
        $this->assertCount(4, $this->statements); // SELECT+DELETE x2
    }

    public function testMaxBatchesBoundsOneCall(): void
    {
        $selects = 0;
        $deleted = RetentionPrune::sweepByIds(
            $this->connection(
                static function (string $sql) use (&$selects): mixed {
                    if (str_contains($sql, 'DELETE')) {
                        return 2;
                    }
                    $selects++;
                    return [['id' => 1], ['id' => 2]]; // permanently saturated
                }
            ),
            't',
            'c',
            PruneDeadline::beforeDateSubNowDays(1),
            2,
            3,
            3
        );

        $this->assertSame(6, $deleted);
        $this->assertSame(3, $selects);
    }

    public function testDeadlockRetriesTheWholeBatchAndSucceeds(): void
    {
        $selects = 0;
        $deletes = 0;
        $deleted = RetentionPrune::sweepByIds(
            $this->connection(
                static function (string $sql) use (&$selects, &$deletes): mixed {
                    if (str_contains($sql, 'DELETE')) {
                        $deletes++;
                        if ($deletes === 1) {
                            throw new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
                        }
                        return 1;
                    }
                    $selects++;
                    return [['id' => 11]];
                }
            ),
            't',
            'c',
            PruneDeadline::beforeDateSubNowDays(1),
            1000,
            20,
            3
        );

        $this->assertSame(1, $deleted);
        // The retry re-harvests before re-deleting (the batch is retried whole).
        $this->assertSame(2, $selects);
        $this->assertSame(2, $deletes);
    }

    public function testNonDeadlockFailurePropagatesOnFirstAttempt(): void
    {
        $selects = 0;
        $db = $this->connection(
            static function (string $sql) use (&$selects): mixed {
                if (str_contains($sql, 'DELETE')) {
                    throw new PDOException('SQLSTATE[HY000]: Connection timed out');
                }
                $selects++;
                return [['id' => 1]];
            }
        );

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Connection timed out');

        try {
            RetentionPrune::sweepByIds($db, 't', 'c', PruneDeadline::beforeDateSubNowDays(1), 1000, 20, 3);
        } finally {
            $this->assertSame(1, $selects); // no retry ladder for non-deadlock
        }
    }

    public function testRepeatedDeadlocksPropagateAfterTheFinalAttemptUnderTheHardArm(): void
    {
        $deletes = 0;
        $db = $this->connection(
            static function (string $sql) use (&$deletes): mixed {
                if (str_contains($sql, 'DELETE')) {
                    $deletes++;
                    throw new PDOException('Deadlock found when trying to get lock');
                }
                return [['id' => 1]];
            }
        );

        $this->expectException(PDOException::class);

        try {
            RetentionPrune::sweepByIds($db, 't', 'c', PruneDeadline::beforeDateSubNowDays(1), 1000, 20, 3);
        } finally {
            $this->assertSame(3, $deletes);
        }
    }

    public function testSoftTwoPhaseArmReturnsTheRunningTotalAndSwallowsTheFinalDeadlock(): void
    {
        // Maintenance-site analogue: round 1 deletes a full batch, round 2 is
        // a deadlock ladder whose retries exhaust — the SOFT arm must return
        // what was already deleted instead of throwing, LOUD on the error log.
        $rounds = 0;
        $deleted = null;
        $log = $this->captureErrorLog(function () use (&$deleted, &$rounds): void {
            $deleted = RetentionPrune::sweepByIds(
                $this->connection(
                    static function (string $sql) use (&$rounds): mixed {
                        if (str_contains($sql, 'DELETE')) {
                            if ($rounds === 1) {
                                return 2;
                            }
                            throw new PDOException('Deadlock found when trying to get lock');
                        }
                        $rounds++;
                        return $rounds === 1 ? [['id' => 1], ['id' => 2]] : [['id' => 9]];
                    }
                ),
                't',
                'c',
                PruneDeadline::beforeDateSubNowDays(1),
                2,
                20,
                3,
                failLoud: false
            );
        });

        $this->assertSame(2, $deleted);
        $this->assertStringContainsString('RetentionPrune soft-fail: sweepByIds on t.c after 2 deleted', $log);
    }

    public function testSubOneBatchRefusesTheTwoPhaseArm(): void
    {
        $deleted = RetentionPrune::sweepByIds(
            $this->connection(static fn(): mixed => self::fail('no statement may run')),
            't',
            'c',
            PruneDeadline::beforeDateSubNowDays(1),
            0,
            20,
            3
        );

        $this->assertSame(0, $deleted);
        $this->assertSame([], $this->statements);
    }
}
