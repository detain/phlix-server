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
 * Bounded FIFO of media-item IDs awaiting background plugin-source enrichment,
 * with a hard minimum spacing between dispatches.
 *
 * ## Why this exists (F2b)
 *
 * F2a wired plugin metadata SOURCES (omdb / anidb / myanimelist) into the
 * resolver behind an opt-in `includePluginSources` flag, but NO production
 * caller passes it — so those sources never actually run. The keystone safety
 * property of F2a is that the bulk library scan makes ZERO plugin-source
 * network calls (omdb 1000/day, anidb ban risk). This queue is the automatic,
 * THROTTLED, BACKGROUND trigger that finally drives those sources — WITHOUT
 * ever doing HTTP inline in the scan path.
 *
 * The scan/job worker fires `MediaItemAdded` once per newly-persisted file. The
 * event handler only ever *enqueues* here (no I/O); a re-arming background
 * `Workerman\Timer` pulls at most one item per interval and enriches it off the
 * scan path. This mirrors the phlix-plugin-musicbrainz `EnrichmentQueue`
 * (1-request-per-second etiquette) — belt-and-suspenders with the plugins'
 * own self-throttling.
 *
 * ## Resident-memory lifecycle (no leak)
 *
 * The queue is deliberately INSTANCE-scoped (NOT static): it lives for the
 * lifetime of the subscriber in a resident worker and is bounded by
 * {@see self::$maxSize} so a runaway scan can never grow it without limit. It
 * is drained as items are processed, and de-duplicates against items already
 * pending so a re-enqueue (deferred/throttled retry) never doubles an entry.
 *
 * @package Phlix\Media\Metadata\Enrichment
 * @since   0.15.0
 */
final class PluginEnrichmentQueue
{
    /**
     * Absolute floor on the drain spacing. Even a misconfigured (too-small)
     * interval cannot make this queue release items faster than once per
     * second — the same 1/s courtesy floor the source plugins observe.
     */
    private const MIN_INTERVAL_FLOOR_SECONDS = 1.0;

    /** @var list<string> FIFO of pending media-item IDs. */
    private array $queue = [];

    /** @var array<string, true> Membership set for O(1) de-duplication. */
    private array $pending = [];

    /** Monotonic timestamp (seconds) of the last dispatch; null if none yet. */
    private ?float $lastDispatchedAt = null;

    /** Minimum seconds that must elapse between two dispatches (post-clamp). */
    private float $minIntervalSeconds;

    /** Hard cap on queued items to bound memory in a long-lived worker. */
    private int $maxSize;

    /** @var callable(): float Monotonic clock returning seconds. */
    private $clock;

    /**
     * @param float                    $minIntervalSeconds Requested spacing; clamped up to the 1s floor.
     * @param int                      $maxSize            Maximum queued items (memory bound).
     * @param (callable(): float)|null $clock              Injectable monotonic clock (seconds) for tests.
     */
    public function __construct(
        float $minIntervalSeconds = 1.0,
        int $maxSize = 10000,
        ?callable $clock = null,
    ) {
        $this->minIntervalSeconds = max(self::MIN_INTERVAL_FLOOR_SECONDS, $minIntervalSeconds);
        $this->maxSize = max(1, $maxSize);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000.0;
    }

    /**
     * Add a media-item ID to the queue.
     *
     * De-duplicates against items already pending and refuses to grow past
     * {@see self::$maxSize}.
     *
     * @param string $itemId Media item UUID.
     * @return bool True if accepted; false if dropped (empty, duplicate, or full).
     */
    public function enqueue(string $itemId): bool
    {
        if ($itemId === '' || isset($this->pending[$itemId])) {
            return false;
        }
        if (count($this->queue) >= $this->maxSize) {
            return false;
        }
        $this->queue[] = $itemId;
        $this->pending[$itemId] = true;
        return true;
    }

    /**
     * Pop the next item ONLY if the minimum interval since the last dispatch
     * has elapsed; otherwise return null (throttled).
     *
     * This is the queue-level throttle: even if the caller ticks faster than
     * the interval, at most one item is released per {@see self::$minIntervalSeconds}.
     *
     * @return string|null Next media-item UUID, or null when the queue is empty
     *     or the caller is still inside the cool-down.
     */
    public function dequeueDue(): ?string
    {
        if ($this->queue === []) {
            return null;
        }
        $now = ($this->clock)();
        if ($this->lastDispatchedAt !== null && ($now - $this->lastDispatchedAt) < $this->minIntervalSeconds) {
            return null;
        }
        $itemId = array_shift($this->queue);
        unset($this->pending[$itemId]);
        $this->lastDispatchedAt = $now;
        return $itemId;
    }

    /** Number of items currently queued. */
    public function size(): int
    {
        return count($this->queue);
    }

    /** Whether the queue is empty. */
    public function isEmpty(): bool
    {
        return $this->queue === [];
    }

    /** Effective minimum spacing between dispatches, in seconds (post-clamp). */
    public function minIntervalSeconds(): float
    {
        return $this->minIntervalSeconds;
    }
}
