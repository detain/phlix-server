<?php

/**
 * Phlix media server component: Enrichment.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata\Enrichment;

/**
 * Per-source minimum-spacing enforcer for background plugin-source enrichment.
 *
 * ## Why this exists (quota safety)
 *
 * Each metadata-source plugin has a very different courtesy/quota budget:
 *
 *  - **omdb** — 1000 requests/day. Enriching a large library back-to-back would
 *    burn the whole daily allowance in minutes, so omdb is spaced far apart
 *    (default 90s ≈ ~960/day) — a big library trickles through over hours/days
 *    rather than in one sitting.
 *  - **anidb** — aggressive anti-flood ban. Kept sparse (default 4s).
 *  - **myanimelist** — friendlier; a couple of seconds is plenty.
 *
 * This limiter tracks the last dispatch time PER SOURCE and reports whether a
 * source is {@see due()} again. The background enricher only ever consults the
 * sources that are due, so no single scan can hammer a source past its budget.
 * The source plugins ALSO self-throttle — this is deliberate belt-and-suspenders
 * at the host level (a plugin bug can't burn the quota).
 *
 * ## Resident-memory / concurrency
 *
 * Instance-scoped (NOT static): it lives with the subscriber in a resident
 * worker and only ever holds one timestamp per known source name, so it can
 * never grow unbounded. Never fans out concurrent per-source calls — the
 * enricher consults sources one item at a time off a single re-arming timer.
 *
 * @package Phlix\Media\Metadata\Enrichment
 * @since   0.15.0
 */
final class SourceRateLimiter
{
    /**
     * Absolute floor on any source's spacing. Even a config that asks for 0s
     * cannot make a source fire faster than the 1/s courtesy floor every
     * plugin source observes.
     */
    private const MIN_INTERVAL_FLOOR_SECONDS = 1.0;

    /**
     * Conservative built-in per-source defaults (seconds). Used when a source
     * is not named in the injected config map. Chosen for quota safety, not
     * throughput — a large library should trickle, not flood.
     *
     * @var array<string, float>
     */
    private const BUILTIN_INTERVALS = [
        'omdb' => 90.0,
        'anidb' => 4.0,
        'myanimelist' => 2.0,
    ];

    /** Fallback spacing for any source with neither a config nor a builtin entry. */
    private const DEFAULT_INTERVAL_SECONDS = 2.0;

    /** @var array<string, float> Effective per-source min spacing (post-clamp). */
    private array $intervals;

    /** Effective default spacing for unknown sources (post-clamp). */
    private float $defaultInterval;

    /** @var array<string, float> Monotonic timestamp (seconds) of each source's last dispatch. */
    private array $lastDispatchedAt = [];

    /** @var callable(): float Monotonic clock returning seconds. */
    private $clock;

    /**
     * @param array<string, float|int> $intervals       Per-source spacing overrides (seconds), merged
     *     OVER the built-in defaults. Each value is clamped up to the 1s floor.
     * @param float|null               $defaultInterval Spacing for sources with no explicit entry;
     *     null uses the built-in {@see self::DEFAULT_INTERVAL_SECONDS}. Clamped up to the 1s floor.
     * @param (callable(): float)|null $clock           Injectable monotonic clock (seconds) for tests.
     */
    public function __construct(
        array $intervals = [],
        ?float $defaultInterval = null,
        ?callable $clock = null,
    ) {
        $merged = self::BUILTIN_INTERVALS;
        foreach ($intervals as $name => $seconds) {
            if (is_string($name) && $name !== '') {
                $merged[$name] = max(self::MIN_INTERVAL_FLOOR_SECONDS, (float) $seconds);
            }
        }
        // Re-clamp the builtins too (defensive; they are already ≥ floor).
        foreach ($merged as $name => $seconds) {
            $merged[$name] = max(self::MIN_INTERVAL_FLOOR_SECONDS, (float) $seconds);
        }

        $this->intervals = $merged;
        $this->defaultInterval = max(
            self::MIN_INTERVAL_FLOOR_SECONDS,
            $defaultInterval ?? self::DEFAULT_INTERVAL_SECONDS,
        );
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000.0;
    }

    /**
     * Whether `$source` may be dispatched now (enough time has elapsed since
     * its last dispatch, or it has never been dispatched).
     *
     * @param string $source Canonical source name (e.g. `omdb`).
     */
    public function due(string $source): bool
    {
        $last = $this->lastDispatchedAt[$source] ?? null;
        if ($last === null) {
            return true;
        }
        return (($this->clock)() - $last) >= $this->intervalFor($source);
    }

    /**
     * Record that `$source` was just dispatched, starting its cool-down.
     *
     * Marked on ATTEMPT (whether or not the source returned data), so a source
     * that misses or errors is not immediately retried — protecting the quota.
     *
     * @param string $source Canonical source name.
     */
    public function mark(string $source): void
    {
        $this->lastDispatchedAt[$source] = ($this->clock)();
    }

    /**
     * Effective minimum spacing for `$source`, in seconds (post-clamp).
     *
     * @param string $source Canonical source name.
     */
    public function intervalFor(string $source): float
    {
        return $this->intervals[$source] ?? $this->defaultInterval;
    }
}
