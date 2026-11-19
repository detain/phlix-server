<?php

/**
 * Phlix media server component: Collections.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Collections;

use Phlix\Common\Util\RowMap;

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
     * @param string|null $createdBy Owning user id (migration 112); null = legacy/unowned —
     *                               visible to every authenticated user, writable only by active admins
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
        public readonly ?string $createdBy = null,
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
        // E-09: every column's NULL-handling is an EXPLICIT argument here
        // (the legacy ternaries' fallbacks, transcribed — never silently
        // harmonized). The strict/numeric families match the legacy
        // narrowing per column: sort_order was numeric-coercive, the
        // string columns strict; the byte-identity pin lives in
        // tests/Unit/Collections/CollectionFromRowByteIdentityTest.php.
        $id = RowMap::str($row, 'id', '');
        $name = RowMap::str($row, 'name', '');
        $libraryId = RowMap::str($row, 'library_id', '');
        $smartPlaylistId = RowMap::nullableStr($row, 'smart_playlist_id');
        $parentId = RowMap::nullableStr($row, 'parent_id');
        $sortOrder = RowMap::intFromNumeric($row, 'sort_order', 0);
        $createdAt = RowMap::str($row, 'created_at', 'now');
        $updatedAt = RowMap::str($row, 'updated_at', 'now');
        // Missing key (pre-112 fixtures) and SQL NULL both hydrate to null —
        // the legacy/unowned policy value, not a parse failure.
        $createdBy = RowMap::nullableStr($row, 'created_by');

        return new self(
            id: $id,
            name: $name,
            libraryId: $libraryId,
            smartPlaylistId: $smartPlaylistId,
            parentId: $parentId,
            sortOrder: $sortOrder,
            createdAt: new \DateTimeImmutable($createdAt),
            updatedAt: new \DateTimeImmutable($updatedAt),
            createdBy: $createdBy,
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
            'created_by' => $this->createdBy,
        ];
    }
}
