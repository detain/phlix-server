<?php

declare(strict_types=1);

namespace Phlex\Collections;

use Workerman\MySQL\Connection;

/**
 * CRUD operations for collection items (membership).
 *
 * Provides data access for the collection_items table using
 * Workerman\MySQL\Connection with parameterized queries.
 *
 * @since 0.14.0
 */
class CollectionItemRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * Insert a media item into a collection.
     *
     * @param string $collectionId Collection UUID
     * @param string $mediaItemId Media item UUID
     * @param int $sortOrder Display order within collection
     * @return void
     *
     * @since 0.14.0
     */
    public function insert(string $collectionId, string $mediaItemId, int $sortOrder = 0): void
    {
        $this->db->query(
            "INSERT INTO collection_items (collection_id, media_item_id, sort_order, added_at)
             VALUES (?, ?, ?, ?)",
            [
                $collectionId,
                $mediaItemId,
                $sortOrder,
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Delete a specific item from a collection.
     *
     * @param string $collectionId Collection UUID
     * @param string $mediaItemId Media item UUID
     * @return void
     *
     * @since 0.14.0
     */
    public function delete(string $collectionId, string $mediaItemId): void
    {
        $this->db->query(
            "DELETE FROM collection_items WHERE collection_id = ? AND media_item_id = ?",
            [$collectionId, $mediaItemId]
        );
    }

    /**
     * Delete all items from a collection.
     *
     * @param string $collectionId Collection UUID
     * @return void
     *
     * @since 0.14.0
     */
    public function deleteAllForCollection(string $collectionId): void
    {
        $this->db->query(
            "DELETE FROM collection_items WHERE collection_id = ?",
            [$collectionId]
        );
    }

    /**
     * Get all media item IDs for a collection.
     *
     * @param string $collectionId Collection UUID
     * @return array<int, string> Array of media item UUIDs in sort order
     *
     * @since 0.14.0
     */
    public function findMediaItemIdsForCollection(string $collectionId): array
    {
        $results = $this->db->query(
            "SELECT media_item_id FROM collection_items
             WHERE collection_id = ? ORDER BY sort_order, added_at",
            [$collectionId]
        );

        if (!is_array($results)) {
            return [];
        }

        $ids = [];
        foreach ($results as $row) {
            if (is_array($row) && array_key_exists('media_item_id', $row)) {
                $ids[] = (string)$row['media_item_id'];
            }
        }

        return $ids;
    }

    /**
     * Count the number of items in a collection.
     *
     * @param string $collectionId Collection UUID
     * @return int Item count
     *
     * @since 0.14.0
     */
    public function countForCollection(string $collectionId): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as cnt FROM collection_items WHERE collection_id = ?",
            [$collectionId]
        );

        if (!is_array($result) || count($result) === 0) {
            return 0;
        }

        $firstRow = $result[0];
        if (!is_array($firstRow)) {
            return 0;
        }

        $count = $firstRow['cnt'] ?? 0;
        return is_numeric($count) ? (int)$count : 0;
    }

    /**
     * Check if a media item exists in a collection.
     *
     * @param string $collectionId Collection UUID
     * @param string $mediaItemId Media item UUID
     * @return bool True if item is in collection
     *
     * @since 0.14.0
     */
    public function existsInCollection(string $collectionId, string $mediaItemId): bool
    {
        $result = $this->db->query(
            "SELECT 1 FROM collection_items WHERE collection_id = ? AND media_item_id = ? LIMIT 1",
            [$collectionId, $mediaItemId]
        );

        return is_array($result) && count($result) > 0;
    }

    /**
     * Get the maximum sort order for a collection.
     *
     * @param string $collectionId Collection UUID
     * @return int Maximum sort order, or 0 if empty
     *
     * @since 0.14.0
     */
    public function getMaxSortOrder(string $collectionId): int
    {
        $result = $this->db->query(
            "SELECT MAX(sort_order) as max_order FROM collection_items WHERE collection_id = ?",
            [$collectionId]
        );

        if (!is_array($result) || count($result) === 0) {
            return 0;
        }

        $firstRow = $result[0];
        if (!is_array($firstRow)) {
            return 0;
        }

        $maxOrder = $firstRow['max_order'] ?? 0;
        return is_numeric($maxOrder) ? (int)$maxOrder : 0;
    }
}
