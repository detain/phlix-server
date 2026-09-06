<?php

/**
 * Phlix media server component: Media.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media;

/**
 * Value object representing a single TMDB box-set collection sync job.
 *
 * Carries the minimum data needed to sync one movie's collection membership
 * AFTER the scan path has indexed it. S215: the scanner enqueues one of these
 * per newly indexed, already-tmdb-matched item instead of calling
 * {@see CollectionService::syncCollectionForMovie()} inline — the sync makes
 * blocking HTTPS calls to TMDB, which must never stall the scan loop.
 *
 * Mirrors {@see SimilarityJob}.
 *
 * @since 0.38.0
 */
final class CollectionJob
{
    /**
     * @param string $itemId Media item UUID whose collection membership to sync
     */
    public function __construct(
        public readonly string $itemId,
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

        if (!is_string($itemId) || $itemId === '') {
            throw new \InvalidArgumentException('CollectionJob: item_id must be a non-empty string');
        }

        return new self($itemId);
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
        ];
    }
}
