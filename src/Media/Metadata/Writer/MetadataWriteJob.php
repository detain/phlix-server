<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata\Writer;

use InvalidArgumentException;

/**
 * A queued "write this item's canonical metadata back to disk" request (S87).
 *
 * Deliberately carries ONLY the identity of the item, never the metadata
 * itself: the scanner must enqueue in O(1) without serialising a metadata blob
 * into the queue file, and by the time the worker drains the job the freshest
 * canonical metadata is the persisted row, which the worker re-reads. Mirrors
 * {@see \Phlix\Media\SimilarityJob}.
 *
 * @since S87
 */
final class MetadataWriteJob
{
    public function __construct(
        public readonly string $itemId,
        public readonly string $libraryId,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $itemId = $data['item_id'] ?? null;
        $libraryId = $data['library_id'] ?? null;

        if (!is_string($itemId) || $itemId === '') {
            throw new InvalidArgumentException('MetadataWriteJob requires a non-empty item_id');
        }
        if (!is_string($libraryId)) {
            throw new InvalidArgumentException('MetadataWriteJob requires a string library_id');
        }

        return new self($itemId, $libraryId);
    }

    /**
     * @return array{item_id: string, library_id: string}
     */
    public function toArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'library_id' => $this->libraryId,
        ];
    }
}
