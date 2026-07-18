<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\RateLimitException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see RateLimitException::retryAfterSeconds()} (SV-4.15(b)).
 *
 * Pins the `Retry-After` math the central 429 mapping renders: seconds until
 * the window resets, floored at 0, defaulting to `time()` when no clock is
 * supplied. The formula is single-sourced through
 * {@see \Phlix\Common\RateLimit\RateLimitState::retryAfter()}.
 *
 * @covers \Phlix\Auth\RateLimitException
 */
#[CoversClass(RateLimitException::class)]
final class RateLimitExceptionTest extends TestCase
{
    public function testRetryAfterSecondsReturnsExactSecondsWhenNowSupplied(): void
    {
        $e = new RateLimitException(resetAt: 1_160, remaining: 0);

        self::assertSame(60, $e->retryAfterSeconds(1_100));
    }

    public function testRetryAfterSecondsIsZeroAtTheResetInstant(): void
    {
        $e = new RateLimitException(resetAt: 1_160, remaining: 0);

        self::assertSame(0, $e->retryAfterSeconds(1_160));
    }

    public function testRetryAfterSecondsFloorsAtZeroWhenResetIsInThePast(): void
    {
        // resetAt already elapsed relative to the supplied clock → never negative.
        $e = new RateLimitException(resetAt: 1_000, remaining: 0);

        self::assertSame(
            0,
            $e->retryAfterSeconds(5_000),
            'retryAfterSeconds must never return a negative Retry-After.',
        );
    }

    public function testRetryAfterSecondsDefaultsToCurrentTimeWhenNowOmitted(): void
    {
        // With no explicit clock the method uses time(); a window 50s out must
        // report ~50s. Allow a 1s slack for a clock tick between the two reads.
        $e = new RateLimitException(resetAt: time() + 50, remaining: 0);

        $seconds = $e->retryAfterSeconds();

        self::assertGreaterThanOrEqual(49, $seconds);
        self::assertLessThanOrEqual(50, $seconds);
    }

    public function testRetryAfterSecondsDefaultingToNowFloorsAtZeroForPastWindow(): void
    {
        // A window already in the past, using the default time() clock, floors at 0.
        $e = new RateLimitException(resetAt: time() - 120, remaining: 0);

        self::assertSame(0, $e->retryAfterSeconds());
    }

    public function testResetAtAndRemainingArePreserved(): void
    {
        $e = new RateLimitException(resetAt: 2_000, remaining: 3);

        self::assertSame(2_000, $e->resetAt);
        self::assertSame(3, $e->remaining);
        self::assertStringContainsString('Too many', $e->getMessage());
    }
}
