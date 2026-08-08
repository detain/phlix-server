<?php

/**
 * Phlix media server component: Media Asset.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\MediaAsset;

/**
 * Value object representing a single media asset generation job.
 *
 * Carries the minimum data needed to generate chapter thumbnails
 * and trickplay sprites for a media item after the scan completes.
 *
 * @since 0.36.0
 */
final class MediaAssetJob
{
    /**
     * Container extensions the media-asset pipeline can process.
     *
     * ⚠ **THE ONE DEFINITION.** It was spelled out three times — in
     * {@see \Phlix\Media\Library\MediaScanner::processFile()} (the enqueue gate),
     * in {@see MediaAssetGenerationJob::generateChapterThumbnails()} (the
     * chapter-extraction gate) and, as of S284, in
     * {@see MediaAssetBackfill} (the re-enqueue gate). Three copies of an
     * eligibility list is how a backfill silently enqueues items the processor
     * then refuses, so they all read this.
     *
     * @var list<string>
     */
    public const SUPPORTED_EXTENSIONS = ['mkv', 'mp4', 'webm'];

    /**
     * @param string $itemId   Media item UUID
     * @param string $path     Absolute filesystem path to the source media file
     * @param int    $duration Media item duration in seconds (for trickplay timestamp generation)
     */
    public function __construct(
        public readonly string $itemId,
        public readonly string $path,
        public readonly int $duration,
    ) {
    }

    /**
     * Create from a plain array (e.g. deserialized from queue file).
     *
     * @param array<string, mixed> $data
     *
     * @throws \InvalidArgumentException If required keys are missing
     */
    public static function fromArray(array $data): self
    {
        $itemId = $data['item_id'] ?? null;
        $path = $data['path'] ?? null;
        $duration = $data['duration'] ?? null;

        if (!is_string($itemId) || $itemId === '') {
            throw new \InvalidArgumentException('MediaAssetJob: item_id must be a non-empty string');
        }
        if (!is_string($path) || $path === '') {
            throw new \InvalidArgumentException('MediaAssetJob: path must be a non-empty string');
        }
        if (!is_int($duration) || $duration < 0) {
            throw new \InvalidArgumentException('MediaAssetJob: duration must be a non-negative int');
        }

        return new self($itemId, $path, $duration);
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
            'path' => $this->path,
            'duration' => $this->duration,
        ];
    }
}
