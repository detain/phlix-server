<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\RateLimit;

use Phlix\Common\RateLimit\RateLimitState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the immutable {@see RateLimitState} value object.
 *
 * Pins the readonly constructor fields and the `retryAfter()` floor-at-0
 * contract used to render `Retry-After` headers.
 */
final class RateLimitStateTest extends TestCase
{
    public function testExposesConstructorValuesAsReadonlyFields(): void
    {
        $state = new RateLimitState(
            count: 3,
            remaining: 2,
            resetAt: 1_500,
            limited: false,
            limit: 5,
        );

        self::assertSame(3, $state->count);
        self::assertSame(2, $state->remaining);
        self::assertSame(1_500, $state->resetAt);
        self::assertFalse($state->limited);
        self::assertSame(5, $state->limit);
    }

    public function testRetryAfterReturnsSecondsUntilReset(): void
    {
        $state = new RateLimitState(count: 5, remaining: 0, resetAt: 1_160, limited: true, limit: 5);

        self::assertSame(60, $state->retryAfter(1_100));
    }

    public function testRetryAfterIsZeroAtTheResetInstant(): void
    {
        $state = new RateLimitState(count: 5, remaining: 0, resetAt: 1_160, limited: true, limit: 5);

        self::assertSame(0, $state->retryAfter(1_160));
    }

    public function testRetryAfterFloorsAtZeroPastReset(): void
    {
        $state = new RateLimitState(count: 5, remaining: 0, resetAt: 1_160, limited: true, limit: 5);

        self::assertSame(0, $state->retryAfter(5_000), 'retryAfter must never be negative.');
    }

    public function testRetryAfterOnEmptyStateIsZero(): void
    {
        // resetAt = 0 mirrors the "no active window" state produced by peek().
        $state = new RateLimitState(count: 0, remaining: 5, resetAt: 0, limited: false, limit: 5);

        self::assertSame(0, $state->retryAfter(1_000));
    }
}
