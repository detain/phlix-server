<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Relay;

use Psr\Log\LoggerInterface;

/**
 * Bounded LRU segment buffer for HLS relay sessions.
 *
 * Each {@see HlsRelayManager} session owns one {@see SegmentCache}.
 * The cache enforces two caps (count and cumulative duration) and
 * evicts oldest-first, but never evicts a segment whose refcount is
 * greater than zero — those are in-flight to a downstream client.
 *
 * ## Cap semantics
 *
 * The effective cap is the **greater** of the two limits, so neither
 * very-short segments (e.g., 2-second iPhone HLS chunks) nor very-long
 * segments (e.g., 10-second VOD chunks) can starve the other budget.
 *
 * ## Refcount lifecycle
 *
 * Callers track in-flight downloads via {@see acquire()} /
 * {@see release()}. While `refcount > 0` the segment is pinned in the
 * cache. Once `release()` brings the refcount back to zero and the
 * cap is exceeded, the next {@see push()} will evict the segment.
 *
 * @since Wave 2 (post-O.7)
 */
final class SegmentCache
{
    /**
     * @var array<string, array{data: string, duration: float, inserted: int, order: int, refcount: int}>
     *      Cache entries keyed by segment id.
     */
    private array $entries = [];

    /** @var int Maximum number of segments retained. */
    private int $maxSegments;

    /** @var float Maximum cumulative segment duration (seconds). */
    private float $maxBufferSeconds;

    /** @var int Monotonically increasing counter used for LRU ordering. */
    private int $nextOrder = 0;

    /** @var LoggerInterface|null Optional logger. */
    private ?LoggerInterface $logger;

    /**
     * @param int                  $maxSegments      Soft cap on segment count.
     * @param float                $maxBufferSeconds Soft cap on cumulative duration.
     * @param LoggerInterface|null $logger           Optional logger.
     */
    public function __construct(
        int $maxSegments = 6,
        float $maxBufferSeconds = 30.0,
        ?LoggerInterface $logger = null,
    ) {
        $this->maxSegments      = max(1, $maxSegments);
        $this->maxBufferSeconds = max(1.0, $maxBufferSeconds);
        $this->logger           = $logger;
    }

    /**
     * Insert a segment into the cache.
     *
     * Triggers eviction of the oldest releasable entries when the caps
     * are exceeded after insertion. Segments whose refcount is > 0
     * are skipped during eviction.
     *
     * @param string $segmentId        Unique segment identifier (typically the path or URL).
     * @param string $data             Raw segment bytes.
     * @param float  $durationSeconds  Duration of the segment in seconds (from #EXTINF).
     */
    public function push(string $segmentId, string $data, float $durationSeconds): void
    {
        // Overwrite: drop the existing entry so size accounting stays accurate.
        if (isset($this->entries[$segmentId])) {
            unset($this->entries[$segmentId]);
        }

        $this->entries[$segmentId] = [
            'data'     => $data,
            'duration' => max(0.0, $durationSeconds),
            'inserted' => time(),
            'order'    => $this->nextOrder++,
            'refcount' => 0,
        ];

        $this->evictIfOverCap();
    }

    /**
     * Retrieve cached segment bytes, or null on miss.
     *
     * @param string $segmentId Segment identifier.
     * @return string|null Raw bytes or null when the segment is not cached.
     */
    public function get(string $segmentId): ?string
    {
        if (!isset($this->entries[$segmentId])) {
            return null;
        }
        return $this->entries[$segmentId]['data'];
    }

    /**
     * Returns true when the cache currently holds the given segment.
     */
    public function has(string $segmentId): bool
    {
        return isset($this->entries[$segmentId]);
    }

    /**
     * Pin a segment so it cannot be evicted while in flight.
     *
     * Returns true if the segment was pinned (cache hit), false if the
     * caller must fetch it separately (cache miss).
     */
    public function acquire(string $segmentId): bool
    {
        if (!isset($this->entries[$segmentId])) {
            return false;
        }
        $this->entries[$segmentId]['refcount']++;
        return true;
    }

    /**
     * Release a previously-pinned segment.
     *
     * Once the refcount drops to zero the segment becomes eligible for
     * eviction on the next {@see push()}.
     */
    public function release(string $segmentId): void
    {
        if (!isset($this->entries[$segmentId])) {
            return;
        }
        $this->entries[$segmentId]['refcount'] = max(0, $this->entries[$segmentId]['refcount'] - 1);
    }

    /**
     * Current segment count.
     */
    public function size(): int
    {
        return count($this->entries);
    }

    /**
     * Current cumulative buffered duration in seconds.
     */
    public function totalDuration(): float
    {
        $sum = 0.0;
        foreach ($this->entries as $entry) {
            $sum += $entry['duration'];
        }
        return $sum;
    }

    /**
     * Drop every entry, regardless of refcount.
     *
     * Intended for session teardown only — callers MUST guarantee no
     * downstream client is still reading from this session.
     */
    public function clear(): void
    {
        $this->entries = [];
    }

    /**
     * Cap accessors (used by tests and diagnostics).
     *
     * @return array{max_segments: int, max_buffer_seconds: float, segments: int, buffered_seconds: float}
     */
    public function stats(): array
    {
        return [
            'max_segments'       => $this->maxSegments,
            'max_buffer_seconds' => $this->maxBufferSeconds,
            'segments'           => $this->size(),
            'buffered_seconds'   => $this->totalDuration(),
        ];
    }

    /**
     * Evict oldest releasable entries while any cap is exceeded.
     *
     * Pinned entries (refcount > 0) are skipped — in-flight downloads
     * never see their bytes ripped out from underneath them.
     */
    private function evictIfOverCap(): void
    {
        while (
            ($this->size() > $this->maxSegments)
            || ($this->totalDuration() > $this->maxBufferSeconds)
        ) {
            $victim = $this->pickEvictionVictim();
            if ($victim === null) {
                // Every remaining segment is pinned; cannot evict further.
                break;
            }
            $duration = $this->entries[$victim]['duration'];
            unset($this->entries[$victim]);
            $this->logger?->debug('SegmentCache: evicted segment', [
                'segment_id' => $victim,
                'duration'   => $duration,
            ]);
        }
    }

    /**
     * Return the segment id of the oldest releasable entry, or null
     * if every entry is currently pinned.
     */
    private function pickEvictionVictim(): ?string
    {
        $victim     = null;
        $victimSeen = PHP_INT_MAX;

        foreach ($this->entries as $id => $entry) {
            if ($entry['refcount'] > 0) {
                continue;
            }
            if ($entry['order'] < $victimSeen) {
                $victimSeen = $entry['order'];
                $victim     = $id;
            }
        }

        return $victim;
    }
}
