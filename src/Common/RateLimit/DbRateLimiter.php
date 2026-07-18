<?php

/**
 * Phlix media server component: RateLimit.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\RateLimit;

use Closure;
use Workerman\MySQL\Connection;

/**
 * Shared, DB-backed TTL-windowed rate limiter (SV-4.15 sub-step e).
 *
 * A cluster/cross-worker-safe {@see RateLimiterInterface} implementation backed
 * by the `rate_limit_buckets` table (migration 085). One row per opaque bucket
 * key (`rate_key` natural PK — e.g. `register:<ip>` or `webauthn_start:<user>`)
 * holds the current window's `attempts` counter and its `reset_at` Unix expiry,
 * so EVERY resident worker process shares one counter for a key.
 *
 * ## Why (vs the worker-local {@see RateLimiter})
 *
 * The worker-local {@see RateLimiter} keeps its bucket map on the instance, so
 * each of the server's HTTP workers counts INDEPENDENTLY and the real
 * brute-force budget is roughly `max × workers` (~14× with 14 workers) instead
 * of the intended `max` — a genuine weakening on brute-force surfaces
 * (register / refresh / WebAuthn). Backing the bucket with a single shared row
 * per key unifies the counter across all workers. Surfaces where a soft
 * per-worker budget is acceptable stay on the in-memory {@see RateLimiter};
 * only the genuinely-global profiles are bound here.
 *
 * This mirrors the shape of the server's existing IP-keyed
 * {@see \Phlix\Auth\DbLoginRateLimitStore} (migration 074), generalised to an
 * OPAQUE key and the {@see RateLimiterInterface} contract. The `login` surface
 * keeps using {@see \Phlix\Auth\DbLoginRateLimitStore} / migration 074
 * unchanged — this is a NEW, additive limiter for the other surfaces.
 *
 * ## Semantics (identical to {@see RateLimiter})
 *
 * - {@see hit()} records one attempt: a fresh (or expired) window restarts the
 *   counter at 1 with `reset_at = now + window`; an active window increments
 *   and keeps its `reset_at`. Returns the resulting {@see RateLimitState}
 *   (`limited` once `attempts >= max`). The counter mutation is an atomic
 *   `INSERT … ON DUPLICATE KEY UPDATE` so concurrent workers can't lose an
 *   increment (the shared-store race the in-memory version cannot have). The
 *   subsequent re-SELECT only READS the post-write value; a concurrent worker
 *   racing between the upsert and the read can only make the reported count
 *   HIGHER, never lower, so the limit is never under-counted.
 * - {@see peek()} inspects WITHOUT recording (read-only, no writes — it may run
 *   on a hot path); an absent or expired window reports an empty, unlimited
 *   state.
 * - {@see reset()} clears the bucket row (e.g. after a successful auth).
 *
 * ## DB conventions (server rules — differ from the hub donor)
 *
 * - `Workerman\MySQL\Connection` with POSITIONAL `?` placeholders (the server's
 *   `PhlixMySQLConnection`/`PooledMySQLConnection` support positional `?`),
 *   mirroring {@see \Phlix\Auth\DbLoginRateLimitStore}. The hub's donor
 *   `DbRateLimiter` uses NAMED `:param` placeholders — do not confuse the two.
 * - The bounded sweep binds its `LIMIT` as a native PHP INT. Under the project
 *   DB layer's emulated prepares a PHP string maps to `PDO::PARAM_STR`, which
 *   PDO QUOTES, so a stringified `LIMIT` renders `LIMIT '100'` → MySQL error
 *   1064 (this store's IP-keyed sibling hit exactly this). Passing an int binds
 *   as `PARAM_INT` (unquoted) and defends against a future regression.
 *
 * ## Table bound
 *
 * The table holds one row per distinct key (not per attempt), but expired rows
 * for keys that never return accumulate, so {@see hit()} runs a bounded
 * `DELETE … WHERE reset_at <= ? LIMIT <int>` sweep to keep it from growing
 * without bound — mirroring {@see \Phlix\Auth\DbLoginRateLimitStore}.
 *
 * @package Phlix\Common\RateLimit
 */
final class DbRateLimiter implements RateLimiterInterface
{
    /** Expired rows removed per {@see hit()} sweep batch (bounds the table). */
    private const int CLEANUP_BATCH_SIZE = 100;

    /** Validated window length in seconds (>= 1). */
    private readonly int $windowSeconds;

    /** Validated maximum attempts per window (>= 1). */
    private readonly int $maxAttempts;

    /**
     * Unix-timestamp clock. Injectable so tests advance time deterministically;
     * defaults to {@see time()}.
     *
     * @var Closure(): int
     */
    private readonly Closure $clock;

    /**
     * @param Connection             $db            Workerman MySQL connection (positional `?`)
     * @param int                    $windowSeconds length of the counting window in seconds
     * @param int                    $maxAttempts   attempts allowed within a window before `limited`
     * @param (callable(): int)|null $clock         unix-timestamp source (defaults to {@see time()})
     */
    public function __construct(
        private readonly Connection $db,
        int $windowSeconds = 900,
        int $maxAttempts = 5,
        ?callable $clock = null,
    ) {
        $this->windowSeconds = $windowSeconds > 0 ? $windowSeconds : 1;
        $this->maxAttempts = $maxAttempts > 0 ? $maxAttempts : 1;

        $this->clock = $clock !== null
            ? Closure::fromCallable($clock)
            : static fn (): int => time();
    }

    /**
     * @inheritDoc
     */
    public function hit(string $key): RateLimitState
    {
        $now = ($this->clock)();
        $reset = $now + $this->windowSeconds;

        // Atomic increment/restart in ONE statement (never split into a
        // read-then-write): a fresh or expired window restarts at 1 with a new
        // reset_at; an active window increments and keeps its reset_at. The
        // whole update is a single INSERT … ON DUPLICATE KEY UPDATE so
        // concurrent workers cannot lose an increment. Positional `?` throughout.
        $this->db->query(
            'INSERT INTO rate_limit_buckets (rate_key, attempts, reset_at, created_at) '
            . 'VALUES (?, 1, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'attempts = IF(reset_at <= ?, 1, attempts + 1), '
            . 'reset_at = IF(reset_at <= ?, ?, reset_at)',
            [$key, $reset, $now, $now, $now, $reset]
        );

        // Re-read the post-write value. A worker racing between the upsert and
        // this read can only push the count HIGHER (never lower), so the limit
        // is never under-counted.
        $row = $this->fetchBucket($key);

        // Bounded reclamation of stale rows (post-write, low-frequency path).
        // Never touches the row we just wrote (its reset_at > now).
        $this->sweepExpired($now);

        if ($row === null) {
            // Raced with a concurrent reset()/sweep — report our own single hit.
            return $this->stateFrom(1, $reset);
        }

        return $this->stateFrom($row['attempts'], $row['reset_at']);
    }

    /**
     * @inheritDoc
     */
    public function reset(string $key): void
    {
        $this->db->query('DELETE FROM rate_limit_buckets WHERE rate_key = ?', [$key]);
    }

    /**
     * @inheritDoc
     */
    public function peek(string $key): RateLimitState
    {
        $now = ($this->clock)();
        $row = $this->fetchBucket($key);

        if ($row === null || $row['reset_at'] <= $now) {
            return $this->emptyState();
        }

        return $this->stateFrom($row['attempts'], $row['reset_at']);
    }

    /**
     * Read the bucket row for `$key`, or null when absent.
     *
     * @return array{attempts: int, reset_at: int}|null
     */
    private function fetchBucket(string $key): ?array
    {
        $result = $this->db->query(
            'SELECT attempts, reset_at FROM rate_limit_buckets WHERE rate_key = ?',
            [$key]
        );

        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            return null;
        }

        /** @var array<string, mixed> $row */
        $row = $result[0];

        return [
            'attempts' => self::toInt($row['attempts'] ?? 0),
            'reset_at' => self::toInt($row['reset_at'] ?? 0),
        ];
    }

    /**
     * Coerce a query-result cell (typed `mixed` under strict analysis) to a
     * non-negative int, defaulting to 0 for non-numeric values.
     */
    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Bounded sweep of expired rows. The `LIMIT` is bound as a native int so
     * the project's emulated prepares don't quote it (see class docblock — a
     * string LIMIT renders `LIMIT '100'` → MySQL 1064).
     */
    private function sweepExpired(int $now): void
    {
        $this->db->query(
            'DELETE FROM rate_limit_buckets WHERE reset_at <= ? LIMIT ?',
            [$now, self::CLEANUP_BATCH_SIZE]
        );
    }

    /**
     * Build a state snapshot from a live bucket's counters.
     */
    private function stateFrom(int $attempts, int $resetAt): RateLimitState
    {
        $remaining = $this->maxAttempts - $attempts;

        return new RateLimitState(
            count: $attempts,
            remaining: $remaining > 0 ? $remaining : 0,
            resetAt: $resetAt,
            limited: $attempts >= $this->maxAttempts,
            limit: $this->maxAttempts,
        );
    }

    /**
     * The empty (unlimited, full-remaining) state for an absent/expired window.
     */
    private function emptyState(): RateLimitState
    {
        return new RateLimitState(
            count: 0,
            remaining: $this->maxAttempts,
            resetAt: 0,
            limited: false,
            limit: $this->maxAttempts,
        );
    }
}
