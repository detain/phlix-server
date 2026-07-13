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
