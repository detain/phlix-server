<?php

declare(strict_types=1);

namespace Phlex\Collections;

/**
 * Hydrated DTO representing a collection with its items.
 *
 * Contains the collection entity plus the full list of media items
 * it contains, suitable for API responses.
 *
 * @since 0.14.0
 */
final class CollectionWithItems
{
    /**
     * @param Collection $collection The collection entity
     * @param array<int, array<string, mixed>> $items Hydrated media items
     * @param int $total Total item count
     */
    public function __construct(
        public readonly Collection $collection,
        public readonly array $items,
        public readonly int $total,
    ) {
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed> Array representation
     *
     * @since 0.14.0
     */
    public function toArray(): array
    {
        return [
            'collection' => $this->collection->toArray(),
            'items' => $this->items,
            'total' => $this->total,
        ];
    }
}
