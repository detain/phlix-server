<?php

declare(strict_types=1);

namespace Phlix\Collections;

/**
 * Readonly entity representing a media collection.
 *
 * Collections are named groups of media items that a curator manually
 * assembles (bulk-add from search) or that derive from a saved smart
 * playlist rule. Collections appear alongside libraries in the UI.
 *
 * @since 0.14.0
 */
final class Collection
{
    /**
     * @param string $id UUID identifier
     * @param string $name Display name
     * @param string $libraryId Associated library UUID
     * @param string|null $smartPlaylistId Linked smart playlist UUID (null = manual collection)
     * @param string|null $parentId Parent collection UUID for nesting (null = top-level)
     * @param int $sortOrder Display order within parent or library
     * @param \DateTimeImmutable $createdAt Creation timestamp
     * @param \DateTimeImmutable $updatedAt Last update timestamp
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $libraryId,
        public readonly ?string $smartPlaylistId,
        public readonly ?string $parentId,
        public readonly int $sortOrder = 0,
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        public readonly \DateTimeImmutable $updatedAt = new \DateTimeImmutable(),
    ) {
    }

    /**
     * Check if this is a smart (rule-based) collection.
     *
     * @return bool True if collection auto-populates from smart playlist rules
     *
     * @since 0.14.0
     */
    public function isSmart(): bool
    {
        return $this->smartPlaylistId !== null;
    }

    /**
     * Create a Collection from a database row.
     *
     * @param array<string, mixed> $row Database row
     * @return self New instance
     *
     * @since 0.14.0
     */
    public static function fromRow(array $row): self
    {
        $id = is_string($row['id'] ?? null) ? $row['id'] : '';
        $name = is_string($row['name'] ?? null) ? $row['name'] : '';
        $libraryId = is_string($row['library_id'] ?? null) ? $row['library_id'] : '';
        $smartPlaylistId = is_string($row['smart_playlist_id'] ?? null) ? $row['smart_playlist_id'] : null;
        $parentId = is_string($row['parent_id'] ?? null) ? $row['parent_id'] : null;
        $sortOrderRaw = $row['sort_order'] ?? null;
        $sortOrder = is_int($sortOrderRaw)
            ? $sortOrderRaw
            : (is_numeric($sortOrderRaw) ? (int)$sortOrderRaw : 0);
        $createdAt = is_string($row['created_at'] ?? null) ? $row['created_at'] : 'now';
        $updatedAt = is_string($row['updated_at'] ?? null) ? $row['updated_at'] : 'now';

        return new self(
            id: $id,
            name: $name,
            libraryId: $libraryId,
            smartPlaylistId: $smartPlaylistId,
            parentId: $parentId,
            sortOrder: $sortOrder,
            createdAt: new \DateTimeImmutable($createdAt),
            updatedAt: new \DateTimeImmutable($updatedAt),
        );
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
            'id' => $this->id,
            'name' => $this->name,
            'library_id' => $this->libraryId,
            'smart_playlist_id' => $this->smartPlaylistId,
            'parent_id' => $this->parentId,
            'sort_order' => $this->sortOrder,
            'is_smart' => $this->isSmart(),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
