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
 * Value object representing a single similarity computation job.
 *
 * Carries the minimum data needed to compute item similarities
 * after a media item is scanned and indexed.
 *
 * @since 0.38.0
 */
final class SimilarityJob
{
    /**
     * @param string $itemId     Media item UUID to compute similarities for
     * @param string $libraryId  Library UUID to scope candidate search
     */
    public function __construct(
        public readonly string $itemId,
        public readonly string $libraryId,
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
        $libraryId = $data['library_id'] ?? null;

        if (!is_string($itemId) || $itemId === '') {
            throw new \InvalidArgumentException('SimilarityJob: item_id must be a non-empty string');
        }
        if (!is_string($libraryId) || $libraryId === '') {
            throw new \InvalidArgumentException('SimilarityJob: library_id must be a non-empty string');
        }

        return new self($itemId, $libraryId);
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
            'library_id' => $this->libraryId,
        ];
    }
}
