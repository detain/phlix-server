<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\DbLoginRateLimitStore;
use Phlix\Auth\RateLimitException;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Behaviour tests for {@see DbLoginRateLimitStore}.
 *
 * This is the SV-1.10 central, bounded, cross-worker login rate-limit store
 * that replaces the unbounded per-worker static array. Backed by the
 * `login_rate_limit` table (migration 074). The DB is mocked so the tests run
 * without a MySQL server.
 *
 * @covers \Phlix\Auth\DbLoginRateLimitStore
 */
final class DbLoginRateLimitStoreTest extends TestCase
{
    /**
     * check() allows an IP with no existing record.
     */
    public function test_check_allows_when_no_record(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $store = new DbLoginRateLimitStore($db);
        $this->assertTrue($store->check('1.2.3.4', 5));
    }

    /**
     * check() throws RateLimitException once the attempt count reaches the max
     * within an unexpired window.
     */
    public function test_check_throws_when_over_limit(): void
    {
        $resetAt = time() + 600;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['attempts' => 5, 'reset_at' => $resetAt],
        ]);

        $store = new DbLoginRateLimitStore($db);

        try {
            $store->check('1.2.3.4', 5);
            $this->fail('Expected RateLimitException.');
        } catch (RateLimitException $e) {
            $this->assertSame($resetAt, $e->resetAt);
            $this->assertSame(0, $e->remaining);
        }
    }

    /**
     * check() deletes an expired window and allows the attempt.
     */
    public function test_check_deletes_expired_window_and_allows(): void
    {
        $expired = time() - 10;
        $queries = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($expired, &$queries): array {
                $queries[] = $sql;
                if (str_starts_with($sql, 'SELECT')) {
                    return [['attempts' => 3, 'reset_at' => $expired]];
                }
                return [];
            }
        );

        $store = new DbLoginRateLimitStore($db);
        $this->assertTrue($store->check('1.2.3.4', 5));

        $this->assertTrue(
            (bool) array_filter($queries, static fn (string $q): bool => str_contains($q, 'DELETE')),
            'An expired window should be swept via a DELETE.'
        );
    }

    /**
     * recordFailedAttempt() upserts the counter AND runs the bounded batch
     * sweep (DELETE ... LIMIT) that keeps the table from growing unbounded —
     * this is the core "bounded/sweep" guarantee the static array lacked.
     */
    public function test_record_failed_attempt_upserts_and_sweeps_bounded(): void
    {
        $sqls = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$sqls): array {
                $sqls[] = $sql;
                return [];
            }
        );

        $store = new DbLoginRateLimitStore($db);
        $store->recordFailedAttempt('1.2.3.4');

        $this->assertTrue(
            (bool) array_filter($sqls, static fn (string $q): bool => str_contains($q, 'INSERT')
                && str_contains($q, 'ON DUPLICATE KEY UPDATE')),
            'recordFailedAttempt should upsert the attempt counter.'
        );

        $sweep = array_values(array_filter(
            $sqls,
            static fn (string $q): bool => str_contains($q, 'DELETE') && str_contains($q, 'LIMIT')
        ));
        $this->assertNotEmpty(
            $sweep,
            'recordFailedAttempt should perform a bounded LIMITed sweep of expired rows.'
        );
    }

    /**
     * The bounded sweep (`DELETE ... WHERE reset_at <= ? LIMIT ?`) MUST bind its
     * numeric params as INTEGERS, not strings.
     *
     * Regression guard for the 1064 bug: the project DB layer
     * (PhlixMySQLConnection/PooledMySQLConnection) uses emulated prepares with
     * type-aware binding — a PHP string maps to PDO::PARAM_STR, which PDO QUOTES,
     * so a stringified LIMIT renders `LIMIT '100'` → MySQL error 1064 on EVERY
     * failed login. This mock can't exercise the real prepare, so we assert the
     * argument TYPES the store passes for the sweep are int. A future re-introduction
     * of a `(string)` cast on the LIMIT (or the timestamp) turns this red.
     */
    public function test_cleanup_sweep_binds_numeric_params_as_int(): void
    {
        $sweepParams = null;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$sweepParams): array {
                if (str_contains($sql, 'DELETE') && str_contains($sql, 'LIMIT')) {
                    $sweepParams = $params;
                }
                return [];
            }
        );

        $store = new DbLoginRateLimitStore($db);
        $store->recordFailedAttempt('1.2.3.4');

        $this->assertNotNull($sweepParams, 'Expected a bounded DELETE ... LIMIT sweep.');
        $this->assertCount(2, $sweepParams, 'Sweep should bind [reset_at threshold, LIMIT].');

        // The LIMIT param (positional index 1) MUST be an int — a string here
        // produces `LIMIT '100'` under emulated prepares → MySQL 1064.
        $this->assertIsInt(
            $sweepParams[1],
            'The LIMIT param must be bound as int, not string (PARAM_STR would quote it → 1064).'
        );

        // The reset_at threshold (index 0) is compared against an INT UNSIGNED
        // column and should likewise be an int.
        $this->assertIsInt(
            $sweepParams[0],
            'The reset_at threshold must be bound as int (reset_at is INT UNSIGNED).'
        );
    }

    /**
     * clear() removes the IP's row after a successful auth.
     */
    public function test_clear_deletes_row(): void
    {
        $captured = null;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$captured): array {
                $captured = ['sql' => $sql, 'params' => $params];
                return [];
            }
        );

        $store = new DbLoginRateLimitStore($db);
        $store->clear('9.9.9.9');

        $this->assertNotNull($captured);
        $this->assertStringContainsString('DELETE', $captured['sql']);
        $this->assertSame(['9.9.9.9'], $captured['params']);
    }
}
