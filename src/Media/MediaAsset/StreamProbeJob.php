<?php

/**
 * Phlix media server component: Media Asset.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\MediaAsset;

/**
 * Value object for one background full-track-set probe of a legacy media item.
 *
 * A legacy item (streams_probed_at NULL, no subtitle row, fewer than two audio
 * rows) is repaired by an ffprobe that runs in the background, never inside a
 * playback-info request. The job carries only the item id: the worker re-reads
 * the item's current path and marker at run time, so a moved or already-probed
 * item is handled by the state it is in then, not by a stale snapshot.
 *
 * `attempts` counts the failed runs so far. A job is dropped once it reaches
 * {@see self::MAX_ATTEMPTS}, so a permanently broken file cannot re-queue forever.
 *
 * @since 0.74.0
 */
final class StreamProbeJob
{
    /**
     * Total runs (the first one plus retries) before the job is dropped.
     *
     * Three runs bounds a transient failure (a share that was briefly unmounted)
     * while still giving up on a deterministic one.
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * @param string $itemId   Media item UUID
     * @param int    $attempts Failed runs so far (0 for a fresh enqueue)
     */
    public function __construct(
        public readonly string $itemId,
        public readonly int $attempts = 0,
    ) {
    }

    /**
     * Create from a plain array (e.g. deserialized from a queue file).
     *
     * @param array<string, mixed> $data
     *
     * @throws \InvalidArgumentException If item_id is missing or attempts is negative
     */
    public static function fromArray(array $data): self
    {
        $itemId = $data['item_id'] ?? null;
        $attempts = $data['attempts'] ?? 0;

        if (!is_string($itemId) || $itemId === '') {
            throw new \InvalidArgumentException('StreamProbeJob: item_id must be a non-empty string');
        }
        if (!is_int($attempts) || $attempts < 0) {
            throw new \InvalidArgumentException('StreamProbeJob: attempts must be a non-negative int');
        }

        return new self($itemId, $attempts);
    }

    /**
     * Serialize to a plain array for queue storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'attempts' => $this->attempts,
        ];
    }

    /**
     * The job to re-queue after one more failed run.
     */
    public function nextAttempt(): self
    {
        return new self($this->itemId, $this->attempts + 1);
    }

    /**
     * Whether the failed-run count has reached the bound, i.e. the job is dropped.
     */
    public function isExhausted(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }
}
