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

    /**
     * S131 — the `null` arm, driven through production.
     *
     * `recordFailedAttempt()` guards its upsert so a brute-force attempt is
     * still charged if the upsert wrote nothing. The guard used to read
     * `$result === false` — a value `Workerman\MySQL\Connection::query()`
     * cannot produce (it THROWS on error) — so it could never fire. `null` is
     * the falsy value it DOES return for a zero-row INSERT.
     *
     * Deleting `|| $result === null` from
     * {@see \Phlix\Common\Database\WriteResult::wroteNothing()} turns this
     * RED: the fallback UPDATE stops being issued and the attempt goes
     * uncharged, i.e. a free retry.
     *
     * @return void
     */
    public function test_an_upsert_that_wrote_nothing_still_charges_the_attempt_via_the_fallback_update(): void
    {
        $sqls = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$sqls) {
                $sqls[] = $sql;
                if (str_contains($sql, 'INSERT') && str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
                    // What the client really returns for a zero-row INSERT.
                    return null;
                }
                return [];
            }
        );

        $store = new DbLoginRateLimitStore($db);
        $store->recordFailedAttempt('1.2.3.4');

        $fallback = array_values(array_filter(
            $sqls,
            static fn (string $q): bool => str_starts_with(ltrim($q), 'UPDATE login_rate_limit')
        ));
        $this->assertCount(
            1,
            $fallback,
            'an upsert that wrote nothing must fall back to the UPDATE, or the failed '
            . 'login is never counted against the brute-force budget'
        );
    }

    /**
     * S131 — the `false` arm, driven through production.
     *
     * ⚠ Honest framing: `false` is a value THIS client never returns; the arm
     * exists for defensive breadth (a driver swap, or a `lastInsertId()` that
     * honours its declared `string|false`). This test reaches it through a
     * double, which is the only way it CAN be reached — see
     * {@see \Phlix\Common\Database\WriteResult}. It is here so that deleting
     * the arm is a decision someone has to make deliberately, not a silent
     * simplification.
     *
     * @return void
     */
    public function test_a_false_upsert_result_also_charges_the_attempt(): void
    {
        $sqls = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$sqls) {
                $sqls[] = $sql;
                if (str_contains($sql, 'INSERT') && str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
                    return false;
                }
                return [];
            }
        );

        $store = new DbLoginRateLimitStore($db);
        $store->recordFailedAttempt('5.6.7.8');

        $fallback = array_values(array_filter(
            $sqls,
            static fn (string $q): bool => str_starts_with(ltrim($q), 'UPDATE login_rate_limit')
        ));
        $this->assertCount(1, $fallback, 'the false arm must still charge the attempt');
    }

    /**
     * 🔴 S131 — `'0'` is a SUCCESSFUL upsert and must NOT trigger the fallback.
     *
     * `login_rate_limit`'s primary key is `ip VARCHAR(45)`, so there is no
     * `AUTO_INCREMENT` and `lastInsertId()` answers the string `'0'` — which
     * is FALSY in PHP. If this guard were ever "simplified" to `if (!$result)`
     * every successful upsert would ALSO run the fallback UPDATE and each
     * failed login would be counted TWICE, locking users out at half the
     * configured budget.
     *
     * @return void
     */
    public function test_the_falsy_string_zero_is_a_successful_upsert_and_does_not_double_charge(): void
    {
        $sqls = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$sqls) {
                $sqls[] = $sql;
                if (str_contains($sql, 'INSERT') && str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
                    // lastInsertId() on a table with no AUTO_INCREMENT column.
                    return '0';
                }
                return [];
            }
        );

        $store = new DbLoginRateLimitStore($db);
        $store->recordFailedAttempt('1.2.3.4');

        $fallback = array_values(array_filter(
            $sqls,
            static fn (string $q): bool => str_starts_with(ltrim($q), 'UPDATE login_rate_limit')
        ));
        $this->assertSame(
            [],
            $fallback,
            "'0' is what a successful INSERT returns on a table with no AUTO_INCREMENT; "
            . 'treating it as a failure double-charges every failed login'
        );
    }
}
