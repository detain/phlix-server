<?php

/**
 * Phlix media server component: RateLimit.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\RateLimit;

/**
 * Immutable snapshot of a rate-limit bucket for a single key.
 *
 * Returned by {@see RateLimiterInterface::hit()} and
 * {@see RateLimiterInterface::peek()} so callers can decide whether to
 * proceed, surface a `Retry-After`, or reject. All counts are
 * point-in-time values computed against the configured window/max.
 *
 * @package Phlix\Common\RateLimit
 */
final class RateLimitState
{
    /**
     * @param int $count     attempts recorded in the current window (>= 0)
     * @param int $remaining attempts left before the limit trips (>= 0)
     * @param int $resetAt   unix timestamp at which the window expires and
     *                       the counter resets (0 when no window is active)
     * @param bool $limited  whether the key is currently over the limit and
     *                       further attempts should be rejected
     * @param int $limit     the configured maximum attempts for the window
     */
    public function __construct(
        public readonly int $count,
        public readonly int $remaining,
        public readonly int $resetAt,
        public readonly bool $limited,
        public readonly int $limit,
    ) {
    }

    /**
     * Seconds remaining until the window resets, relative to `$now`
     * (never negative). Suitable for a `Retry-After` header.
     *
     * @param int $now current unix timestamp
     *
     * @return int<0, max>
     */
    public function retryAfter(int $now): int
    {
        $delta = $this->resetAt - $now;

        return $delta > 0 ? $delta : 0;
    }
}
