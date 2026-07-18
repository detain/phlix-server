<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\RateLimit;

use Phlix\Common\RateLimit\DbRateLimiter;
use Phlix\Common\RateLimit\RateLimitState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Behaviour tests for {@see DbRateLimiter} — the shared, DB-backed per-surface
 * limiter (SV-4.15 sub-step e, table `rate_limit_buckets`, migration 085).
 *
 * The DB is driven by a behavioural fake ({@see makeFakeDb()}) that models the
 * `rate_limit_buckets` table semantics from the SQL keywords, so the tests
 * prove the FULL hit/peek/reset/window-expiry behaviour matches the in-memory
 * {@see \Phlix\Common\RateLimit\RateLimiter} — not just query-string shape.
 * Unlike the hub donor (named `:param`), the server binds POSITIONAL `?`, so
 * the fake interprets params by POSITION and a dedicated test asserts no named
 * placeholders leak in. Time is driven by an injected clock so windows advance
 * with no real sleeps. The DB is mocked so the tests run without a MySQL server
 * (matching the repo's {@see \Phlix\Auth\DbLoginRateLimitStore} test idiom).
 */
#[CoversClass(DbRateLimiter::class)]
#[CoversClass(RateLimitState::class)]
final class DbRateLimiterTest extends TestCase
{
    /**
     * @var list<array{sql: string, params: array<int, mixed>}> Captured queries.
     */
    private array $log = [];

    /**
     * @var array<string, array{attempts: int, reset_at: int}> In-memory table.
     */
    private array $table = [];

    protected function setUp(): void
    {
        $this->log = [];
        $this->table = [];
    }

    public function testHitInsertsFreshWindowAtOne(): void
    {
        $now = 1_000;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 900, 5, static fn (): int => $now);

        $state = $limiter->hit('register:1.2.3.4');

        self::assertSame(1, $state->count);
        self::assertSame(4, $state->remaining);
        self::assertFalse($state->limited);
        self::assertSame(5, $state->limit);
        self::assertSame($now + 900, $state->resetAt);
    }

    public function testHitIncrementsAndTripsLimitWithinWindow(): void
    {
        $now = 2_000;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 900, 3, static fn (): int => $now);

        $first = $limiter->hit('k');
        self::assertSame(1, $first->count);
        self::assertFalse($first->limited);

        $limiter->hit('k');
        $third = $limiter->hit('k');
        self::assertSame(3, $third->count);
        self::assertSame(0, $third->remaining);
        self::assertTrue($third->limited, 'Reaching maxAttempts must trip the limit.');

        // reset_at is fixed at the FIRST hit's window (does not slide on increment).
        self::assertSame($now + 900, $third->resetAt);

        $fourth = $limiter->hit('k');
        self::assertSame(4, $fourth->count);
        self::assertSame(0, $fourth->remaining, 'remaining clamps at 0.');
        self::assertTrue($fourth->limited);
    }

    public function testDistinctKeysHaveIndependentBuckets(): void
    {
        $now = 500;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 100, 2, static fn (): int => $now);

        $limiter->hit('webauthn_start:alice');
        $limiter->hit('webauthn_start:alice');
        self::assertTrue($limiter->peek('webauthn_start:alice')->limited);
        self::assertFalse(
            $limiter->peek('webauthn_start:bob')->limited,
            'A separate opaque key is unaffected.'
        );
    }

    public function testHitRestartsWindowAfterExpiry(): void
    {
        $now = 1_000;
        $clock = $now;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 100, 5, static function () use (&$clock): int {
            return $clock;
        });

        $limiter->hit('k');
        $limiter->hit('k');
        self::assertSame(2, $limiter->peek('k')->count);

        // Advance past the window: the next hit restarts the counter at 1.
        $clock = $now + 101;
        $restarted = $limiter->hit('k');
        self::assertSame(1, $restarted->count);
        self::assertSame($clock + 100, $restarted->resetAt);
    }

    public function testPeekReportsEmptyWhenNoRecord(): void
    {
        $limiter = new DbRateLimiter($this->makeFakeDb(), 900, 5, static fn (): int => 10);

        $state = $limiter->peek('missing');
        self::assertSame(0, $state->count);
        self::assertSame(5, $state->remaining);
        self::assertSame(0, $state->resetAt);
        self::assertFalse($state->limited);
        self::assertSame(5, $state->limit);
    }

    public function testPeekReportsEmptyForExpiredWindowWithoutWriting(): void
    {
        $now = 1_000;
        $clock = $now;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 100, 5, static function () use (&$clock): int {
            return $clock;
        });

        $limiter->hit('k');
        $clock = $now + 200; // past expiry
        $before = count($this->log); // isolate the peek's queries

        $state = $limiter->peek('k');
        self::assertSame(0, $state->count, 'Expired window reports empty.');
        self::assertFalse($state->limited);

        // peek is read-only: it must NOT write on the hot path.
        foreach (array_slice($this->log, $before) as $entry) {
            self::assertStringStartsWith(
                'SELECT',
                ltrim($entry['sql']),
                'peek() must issue only reads, never a write.'
            );
        }
    }

    public function testResetClearsBucket(): void
    {
        $now = 1_000;
        $captured = null;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 900, 5, static fn (): int => $now);

        $limiter->hit('k');
        $limiter->hit('k');
        self::assertSame(2, $limiter->peek('k')->count);

        $before = count($this->log);
        $limiter->reset('k');

        // reset() issues exactly a DELETE keyed on rate_key with the bucket key.
        foreach (array_slice($this->log, $before) as $entry) {
            self::assertStringContainsString('DELETE', $entry['sql']);
            self::assertStringContainsString('rate_key', $entry['sql']);
            self::assertSame(['k'], array_values($entry['params']));
            $captured = $entry;
        }
        self::assertNotNull($captured, 'reset() must issue a DELETE.');

        self::assertSame(0, $limiter->peek('k')->count, 'reset() empties the bucket.');
        self::assertFalse($limiter->peek('k')->limited);
    }

    public function testHitUpsertsAndRunsBoundedSweep(): void
    {
        $now = 1_000;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 900, 5, static fn (): int => $now);
        $limiter->hit('k');

        $upsert = array_filter(
            $this->log,
            static fn (array $e): bool => str_contains($e['sql'], 'INSERT')
                && str_contains($e['sql'], 'ON DUPLICATE KEY UPDATE')
        );
        self::assertNotEmpty($upsert, 'hit() must upsert the counter atomically.');

        $sweep = array_filter(
            $this->log,
            static fn (array $e): bool => str_contains($e['sql'], 'DELETE')
                && str_contains($e['sql'], 'reset_at')
                && str_contains($e['sql'], 'LIMIT')
        );
        self::assertNotEmpty($sweep, 'hit() must run a bounded LIMITed sweep of expired rows.');
    }

    /**
     * Regression guard for the 1064 bug: under the project DB layer's emulated
     * prepares a string-bound LIMIT renders `LIMIT '100'` → MySQL 1064. The
     * sweep MUST bind both numeric params as ints. A future `(string)` cast
     * turns this red. (Mirrors DbLoginRateLimitStoreTest's guard.)
     */
    public function testSweepBindsNumericParamsAsInt(): void
    {
        $now = 1_000;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 900, 5, static fn (): int => $now);
        $limiter->hit('k');

        $sweep = null;
        foreach ($this->log as $entry) {
            if (str_contains($entry['sql'], 'DELETE') && str_contains($entry['sql'], 'LIMIT')) {
                $sweep = $entry['params'];
            }
        }

        self::assertNotNull($sweep, 'Expected a bounded DELETE ... LIMIT sweep.');
        self::assertCount(2, $sweep, 'Sweep binds [reset_at threshold, LIMIT].');

        // The LIMIT param (positional index 1) MUST be an int — a string here
        // produces `LIMIT '100'` under emulated prepares → MySQL 1064.
        self::assertIsInt(
            $sweep[1],
            'The LIMIT param must be bound as int, not string (PARAM_STR would quote it → 1064).'
        );

        // The reset_at threshold (index 0) is compared against an INT UNSIGNED
        // column and should likewise be an int.
        self::assertIsInt(
            $sweep[0],
            'The reset_at threshold must be bound as int (reset_at is INT UNSIGNED).'
        );
    }

    /**
     * Server DB rule (differs from the hub donor): POSITIONAL `?` placeholders
     * only — never named `:param`. Every bound param array must be a plain
     * int-keyed list.
     */
    public function testEveryQueryUsesPositionalPlaceholders(): void
    {
        $now = 1_000;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 900, 5, static fn (): int => $now);

        $limiter->hit('k');
        $limiter->peek('k');
        $limiter->reset('k');

        self::assertNotEmpty($this->log);
        foreach ($this->log as $entry) {
            self::assertDoesNotMatchRegularExpression(
                '/:[a-zA-Z_]/',
                $entry['sql'],
                'No named placeholders — server uses positional ?.'
            );
            self::assertTrue(
                array_is_list($entry['params']),
                'Positional binds must be a plain int-keyed list.'
            );
        }
    }

    /**
     * Non-positive window/max clamp to 1 (never a never-limited bucket),
     * mirroring the in-memory {@see \Phlix\Common\RateLimit\RateLimiter}.
     */
    public function testNonPositiveThresholdsClampToOne(): void
    {
        $now = 1_000;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 0, 0, static fn (): int => $now);

        $state = $limiter->hit('k');
        self::assertSame(1, $state->limit, 'maxAttempts clamps to 1.');
        self::assertTrue($state->limited, 'A single hit trips a max-of-1 bucket.');
        self::assertSame($now + 1, $state->resetAt, 'window clamps to 1 second.');
    }

    /**
     * A mock {@see Connection} whose `query()` models the `rate_limit_buckets`
     * table well enough to exercise the real hit/peek/reset/expiry semantics
     * under POSITIONAL `?` binding (params interpreted by index).
     */
    private function makeFakeDb(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /**
             * @param array<int, mixed>|null $params
             * @return list<array<string, int>>|bool
             */
            function (string $sql, ?array $params = null): array|bool {
                $params ??= [];
                /** @var array<int, mixed> $params */
                $this->log[] = ['sql' => $sql, 'params' => $params];

                if (str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
                    return $this->applyUpsert($params);
                }
                if (str_starts_with(ltrim($sql), 'SELECT')) {
                    return $this->applySelect($params);
                }
                if (str_contains($sql, 'DELETE') && str_contains($sql, 'LIMIT')) {
                    return $this->applySweep($params);
                }
                if (str_contains($sql, 'DELETE') && str_contains($sql, 'rate_key')) {
                    unset($this->table[self::paramStr($params, 0)]);
                    return true;
                }

                return true;
            }
        );

        return $db;
    }

    /**
     * Model `INSERT … VALUES (?, 1, ?, ?) ON DUPLICATE KEY UPDATE …`.
     * Positional params: [0]=rate_key, [1]=fresh reset_at, [2]=created_at,
     * [3]=now (attempts IF), [4]=now (reset_at IF), [5]=renew reset_at.
     *
     * @param array<int, mixed> $params
     */
    private function applyUpsert(array $params): bool
    {
        $key = self::paramStr($params, 0);
        $fresh = self::paramInt($params, 1);
        $now = self::paramInt($params, 3);

        $existing = $this->table[$key] ?? null;
        if ($existing === null || $existing['reset_at'] <= $now) {
            $this->table[$key] = ['attempts' => 1, 'reset_at' => $fresh];
        } else {
            $this->table[$key]['attempts']++;
        }

        return true;
    }

    /**
     * Model `SELECT attempts, reset_at FROM rate_limit_buckets WHERE rate_key = ?`.
     *
     * @param array<int, mixed> $params
     * @return list<array{attempts: int, reset_at: int}>
     */
    private function applySelect(array $params): array
    {
        $key = self::paramStr($params, 0);
        $row = $this->table[$key] ?? null;

        return $row === null ? [] : [$row];
    }

    /**
     * Model `DELETE … WHERE reset_at <= ? LIMIT ?`.
     * Positional params: [0]=threshold, [1]=batch.
     *
     * @param array<int, mixed> $params
     */
    private function applySweep(array $params): bool
    {
        $threshold = self::paramInt($params, 0);
        $batch = self::paramInt($params, 1);

        $removed = 0;
        foreach ($this->table as $key => $row) {
            if ($removed >= $batch) {
                break;
            }
            if ($row['reset_at'] <= $threshold) {
                unset($this->table[$key]);
                $removed++;
            }
        }

        return true;
    }

    /**
     * Coerce a positional bound param (typed `mixed`) to a string.
     *
     * @param array<int, mixed> $params
     */
    private static function paramStr(array $params, int $index): string
    {
        $value = $params[$index] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Coerce a positional bound param (typed `mixed`) to an int.
     *
     * @param array<int, mixed> $params
     */
    private static function paramInt(array $params, int $index): int
    {
        $value = $params[$index] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
