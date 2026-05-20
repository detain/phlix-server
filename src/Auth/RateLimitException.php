<?php

declare(strict_types=1);

namespace Phlex\Auth;

use RuntimeException;

/**
 * Thrown when a client exceeds the rate limit for authentication attempts.
 *
 * @package Phlex\Auth
 * @author Phlex Team
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
}
