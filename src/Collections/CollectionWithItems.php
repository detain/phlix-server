<?php

/**
 * Phlix media server component: Collections.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Collections;

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
     * @param int $total Total item count (true membership count when truncated)
     * @param bool $truncated True when membership exceeded the hydration ceiling and `items` is capped
     */
    public function __construct(
        public readonly Collection $collection,
        public readonly array $items,
        public readonly int $total,
        public readonly bool $truncated = false,
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
            'truncated' => $this->truncated,
        ];
    }
}
