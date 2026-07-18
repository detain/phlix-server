<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\RateLimit;

use Phlix\Common\RateLimit\RateLimiter;
use Phlix\Common\RateLimit\RateLimitState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see RateLimiter}.
 *
 * Exercises the behaviours that matter: TTL window expiry (a stale window
 * restarts rather than accumulating forever), cap-bounded active eviction
 * (the map never grows past the configured ceiling, avoiding the unbounded
 * per-worker map leak), and {@see RateLimiter::reset()}. Time is driven by
 * an injected clock so windows advance deterministically with no real
 * sleeps.
 */
#[CoversClass(RateLimiter::class)]
#[CoversClass(RateLimitState::class)]
final class RateLimiterTest extends TestCase
{
    public function testHitIncrementsAndTripsLimitWithinWindow(): void
    {
        $now = 1_000;
        $limiter = new RateLimiter(windowSeconds: 100, maxAttempts: 3, cap: 10, clock: static fn (): int => $now);

        $first = $limiter->hit('ip-1');
        self::assertSame(1, $first->count);
        self::assertSame(2, $first->remaining);
        self::assertFalse($first->limited);
        self::assertSame(3, $first->limit);
        self::assertSame(1_100, $first->resetAt);

        $limiter->hit('ip-1');
        $third = $limiter->hit('ip-1');
        self::assertSame(3, $third->count);
        self::assertSame(0, $third->remaining);
        self::assertTrue($third->limited, 'Reaching maxAttempts must trip the limit.');

        // Further hits stay limited and remaining clamps at 0.
        $fourth = $limiter->hit('ip-1');
        self::assertSame(4, $fourth->count);
        self::assertSame(0, $fourth->remaining);
        self::assertTrue($fourth->limited);
    }

    public function testDistinctKeysHaveIndependentBuckets(): void
    {
        $now = 500;
        $limiter = new RateLimiter(windowSeconds: 100, maxAttempts: 2, cap: 10, clock: static fn (): int => $now);

        $limiter->hit('ip-a');
        $limiter->hit('ip-a');
        $a = $limiter->peek('ip-a');
        $b = $limiter->peek('ip-b');

        self::assertTrue($a->limited);
        self::assertFalse($b->limited);
        self::assertSame(0, $b->count);
    }

    public function testWindowExpiryRestartsTheCounter(): void
    {
        $now = 0;
        // Clock advances by reference so we can step time forward.
        $clock = static function () use (&$now): int {
            /** @var int $now */
            return $now;
        };
        $limiter = new RateLimiter(windowSeconds: 60, maxAttempts: 2, cap: 10, clock: $clock);

        $now = 100;
        $limiter->hit('ip-1');
        $limiter->hit('ip-1');
        self::assertTrue($limiter->peek('ip-1')->limited, 'Two hits should trip a max of 2.');

        // Advance past the window (reset_at = 100 + 60 = 160).
        $now = 161;
        $afterExpiry = $limiter->peek('ip-1');
        self::assertFalse($afterExpiry->limited, 'Expired window must report unlimited.');
        self::assertSame(0, $afterExpiry->count);

        // A hit after expiry starts a brand-new window at count 1.
        $fresh = $limiter->hit('ip-1');
        self::assertSame(1, $fresh->count);
        self::assertFalse($fresh->limited);
        self::assertSame(161 + 60, $fresh->resetAt);
    }

    public function testCapBoundsTheMapWithActiveEviction(): void
    {
        $now = 0;
        $clock = static function () use (&$now): int {
            /** @var int $now */
            return $now;
        };
        $cap = 5;
        $limiter = new RateLimiter(windowSeconds: 100, maxAttempts: 10, cap: $cap, clock: $clock);

        // Insert well past the cap; the map must never exceed it.
        $now = 1_000;
        for ($i = 0; $i < 50; $i++) {
            $limiter->hit('key-' . $i);
            self::assertLessThanOrEqual($cap, $limiter->size(), 'Map must stay bounded by the cap.');
        }

        self::assertSame($cap, $limiter->size());
    }

    public function testExpiredEntriesAreReclaimedBeforeEvictingLiveOnes(): void
    {
        $now = 0;
        $clock = static function () use (&$now): int {
            /** @var int $now */
            return $now;
        };
        $cap = 3;
        $limiter = new RateLimiter(windowSeconds: 10, maxAttempts: 5, cap: $cap, clock: $clock);

        // Fill the map at t=100 (these windows reset at 110).
        $now = 100;
        $limiter->hit('old-1');
        $limiter->hit('old-2');
        $limiter->hit('old-3');
        self::assertSame($cap, $limiter->size());

        // Advance past their windows so they are all expired, then insert a
        // new key: the sweep should reclaim the expired ones, NOT evict a
        // live entry, and the new key is present.
        $now = 200;
        $limiter->hit('new-1');
        self::assertLessThanOrEqual($cap, $limiter->size());
        self::assertSame(1, $limiter->peek('new-1')->count);
        self::assertFalse($limiter->peek('old-1')->limited);
    }

    public function testResetClearsBucket(): void
    {
        $now = 42;
        $limiter = new RateLimiter(windowSeconds: 100, maxAttempts: 2, cap: 10, clock: static fn (): int => $now);

        $limiter->hit('ip-1');
        $limiter->hit('ip-1');
        self::assertTrue($limiter->peek('ip-1')->limited);

        $limiter->reset('ip-1');

        $state = $limiter->peek('ip-1');
        self::assertSame(0, $state->count);
        self::assertFalse($state->limited);
        self::assertSame(0, $limiter->size());

        // Resetting an unknown key is a no-op.
        $limiter->reset('never-seen');
        self::assertSame(0, $limiter->size());
    }

    public function testPeekDoesNotRecordAnAttempt(): void
    {
        $now = 7;
        $limiter = new RateLimiter(windowSeconds: 100, maxAttempts: 3, cap: 10, clock: static fn (): int => $now);

        $limiter->peek('ip-1');
        $limiter->peek('ip-1');
        self::assertSame(0, $limiter->size(), 'peek() must not create a bucket.');

        $state = $limiter->hit('ip-1');
        self::assertSame(1, $state->count, 'First hit after peeks must be count 1.');
    }

    public function testRetryAfterReportsSecondsUntilReset(): void
    {
        $state = new RateLimitState(count: 5, remaining: 0, resetAt: 1_160, limited: true, limit: 5);

        self::assertSame(60, $state->retryAfter(1_100));
        self::assertSame(0, $state->retryAfter(1_160));
        self::assertSame(0, $state->retryAfter(2_000), 'retryAfter must never be negative.');
    }

    public function testNonPositiveConstructorArgsAreClampedToSaneMinimums(): void
    {
        $now = 0;
        $limiter = new RateLimiter(windowSeconds: 0, maxAttempts: 0, cap: 0, clock: static fn (): int => $now);

        $state = $limiter->hit('ip-1');
        // max clamps to >=1, so the very first hit is already limited.
        self::assertTrue($state->limited);
        self::assertSame(1, $limiter->size());
    }
}
