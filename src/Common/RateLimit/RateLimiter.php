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

/**
 * Worker-local, TTL-windowed rate limiter backed by a BOUNDED array with
 * active eviction.
 *
 * Each key tracks an attempt count and a window-reset timestamp. When the
 * window expires the bucket restarts; over the configured maximum the
 * bucket reports {@see RateLimitState::$limited}. The backing array is
 * capped (`$cap`, default 10k keys): on insert, expired buckets are swept
 * first and, if the map is still at the cap, the OLDEST-resetting bucket
 * is evicted. This deliberately avoids the classic resident-memory leak of
 * an unbounded per-worker map (one entry per distinct IP, never reclaimed),
 * which grows slowly but forever under Workerman/Swoole. There is no
 * `static` state here; the map is an instance field with a hard size
 * ceiling.
 *
 * SCOPE / CAVEAT: state is held in THIS worker process only. Under
 * `workers > 1` (or multiple boxes) each worker keeps an independent
 * count, so the effective limit is per-worker, not global. That is
 * acceptable for surfaces where a soft per-worker budget is sufficient. A
 * cluster-safe backend (shared DB table / shared memory) can be substituted
 * later by binding a different {@see RateLimiterInterface} implementation —
 * call sites are unaffected.
 *
 * NOT thread-safe in the OS-thread sense, but Workerman + Swoole coroutine
 * workers are single-threaded per process and array mutations here contain
 * no coroutine yield points, so concurrent coroutines cannot interleave
 * mid-update.
 *
 * @package Phlix\Common\RateLimit
 */
final class RateLimiter implements RateLimiterInterface
{
    /**
     * Bucket map keyed by the caller's opaque key.
     *
     * @var array<string, array{count: int, reset_at: int}>
     */
    private array $buckets = [];

    /** Validated window length in seconds (>= 1). */
    private readonly int $windowSeconds;

    /** Validated maximum attempts per window (>= 1). */
    private readonly int $maxAttempts;

    /** Validated key-count ceiling (>= 1). */
    private readonly int $cap;

    /**
     * Monotonic-enough clock returning a unix timestamp. Injectable so
     * tests can advance time deterministically; defaults to {@see time()}.
     *
     * @var Closure(): int
     */
    private readonly Closure $clock;

    /**
     * @param int                    $windowSeconds length of the counting window in seconds
     * @param int                    $maxAttempts   attempts allowed within a window before `limited`
     * @param int                    $cap           maximum number of distinct keys retained in memory
     * @param (callable(): int)|null $clock         unix-timestamp source (defaults to {@see time()})
     */
    public function __construct(
        int $windowSeconds = 900,
        int $maxAttempts = 5,
        int $cap = 10000,
        ?callable $clock = null,
    ) {
        $this->windowSeconds = $windowSeconds > 0 ? $windowSeconds : 1;
        $this->maxAttempts = $maxAttempts > 0 ? $maxAttempts : 1;
        $this->cap = $cap > 0 ? $cap : 1;

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

        $bucket = $this->buckets[$key] ?? null;
        if ($bucket === null || $bucket['reset_at'] <= $now) {
            // Fresh window. Make room before inserting a brand-new key.
            if ($bucket === null) {
                $this->evictIfNeeded($now);
            }
            $bucket = ['count' => 1, 'reset_at' => $now + $this->windowSeconds];
        } else {
            $bucket['count']++;
        }

        $this->buckets[$key] = $bucket;

        return $this->stateFor($bucket);
    }

    /**
     * @inheritDoc
     */
    public function reset(string $key): void
    {
        unset($this->buckets[$key]);
    }

    /**
     * @inheritDoc
     */
    public function peek(string $key): RateLimitState
    {
        $now = ($this->clock)();
        $bucket = $this->buckets[$key] ?? null;

        if ($bucket === null || $bucket['reset_at'] <= $now) {
            return new RateLimitState(
                count: 0,
                remaining: $this->maxAttempts,
                resetAt: 0,
                limited: false,
                limit: $this->maxAttempts,
            );
        }

        return $this->stateFor($bucket);
    }

    /**
     * Number of buckets currently retained. Exposed for tests/observability;
     * never exceeds the configured cap.
     */
    public function size(): int
    {
        return count($this->buckets);
    }

    /**
     * Build a state snapshot from a live bucket.
     *
     * @param array{count: int, reset_at: int} $bucket
     */
    private function stateFor(array $bucket): RateLimitState
    {
        $count = $bucket['count'];
        $remaining = $this->maxAttempts - $count;

        return new RateLimitState(
            count: $count,
            remaining: $remaining > 0 ? $remaining : 0,
            resetAt: $bucket['reset_at'],
            limited: $count >= $this->maxAttempts,
            limit: $this->maxAttempts,
        );
    }

    /**
     * Active eviction: drop expired buckets first, then — if still at the
     * cap — drop the bucket whose window resets soonest (the oldest /
     * closest-to-expiry entry). Keeps the map bounded by `$cap`.
     */
    private function evictIfNeeded(int $now): void
    {
        if (count($this->buckets) < $this->cap) {
            return;
        }

        // Sweep expired entries first — cheap reclamation.
        foreach ($this->buckets as $k => $bucket) {
            if ($bucket['reset_at'] <= $now) {
                unset($this->buckets[$k]);
            }
        }

        if (count($this->buckets) < $this->cap) {
            return;
        }

        // Still full of live buckets: evict the soonest-expiring one.
        $oldestKey = null;
        $oldestReset = PHP_INT_MAX;
        foreach ($this->buckets as $k => $bucket) {
            if ($bucket['reset_at'] < $oldestReset) {
                $oldestReset = $bucket['reset_at'];
                $oldestKey = $k;
            }
        }

        if ($oldestKey !== null) {
            unset($this->buckets[$oldestKey]);
        }
    }
}
