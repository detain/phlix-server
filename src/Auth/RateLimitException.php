<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Auth;

use Phlix\Common\RateLimit\RateLimitState;
use RuntimeException;

use function time;

/**
 * Thrown when a client exceeds the rate limit for authentication attempts.
 *
 * @package Phlix\Auth
 * @author Phlix Team
 * @version 1.0.0
 * @description Exception thrown when rate limit is exceeded on auth endpoints.
 */
final class RateLimitException extends RuntimeException
{
    /** @var int Unix timestamp when the rate limit resets */
    public int $resetAt;

    /** @var int Number of attempts remaining after this rejection */
    public int $remaining;

    public function __construct(int $resetAt, int $remaining = 0)
    {
        $this->resetAt = $resetAt;
        $this->remaining = $remaining;
        parent::__construct('Too many authentication attempts. Please try again later.');
    }

    /**
     * Seconds a client should wait before retrying, relative to `$now`
     * (defaults to the current time). Never negative — suitable for a
     * `Retry-After` header. Delegates the `max(0, resetAt - now)` math to
     * {@see RateLimitState::retryAfter()} so the formula stays single-sourced
     * with the ported per-surface rate-limiter core (SV-4.15).
     *
     * @param int|null $now current unix timestamp (defaults to `time()`)
     *
     * @return int<0, max>
     */
    public function retryAfterSeconds(?int $now = null): int
    {
        $now ??= time();

        return (new RateLimitState(
            count: 0,
            remaining: $this->remaining,
            resetAt: $this->resetAt,
            limited: true,
            limit: 0,
        ))->retryAfter($now);
    }
}
