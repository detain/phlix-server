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
 * A TTL-windowed attempt counter / rate limiter keyed by an arbitrary
 * string (e.g. a client IP, a user id, or a composite `ip:action` key).
 *
 * The contract is deliberately backend-agnostic: the default binding is the
 * worker-local in-memory {@see RateLimiter}, but a cluster-safe
 * implementation (a shared DB table or shared memory) can be swapped in
 * later without touching call sites — callers depend only on this interface
 * and the {@see RateLimitState} value object.
 *
 * @package Phlix\Common\RateLimit
 */
interface RateLimiterInterface
{
    /**
     * Record one attempt against `$key` and return the resulting state.
     *
     * Starting (or restarting, once a window has expired) a window resets
     * the counter to 1. The returned {@see RateLimitState::$limited} flag
     * reflects whether the count has reached/exceeded the configured
     * maximum within the active window.
     *
     * @param string $key opaque bucket key
     */
    public function hit(string $key): RateLimitState;

    /**
     * Clear any recorded attempts for `$key` (e.g. after a successful
     * login). A subsequent {@see peek()} reports an empty bucket.
     *
     * @param string $key opaque bucket key
     */
    public function reset(string $key): void;

    /**
     * Inspect the current state for `$key` WITHOUT recording an attempt.
     *
     * Returns an empty (unlimited, full-remaining) state when no window is
     * active for the key.
     *
     * @param string $key opaque bucket key
     */
    public function peek(string $key): RateLimitState;
}
