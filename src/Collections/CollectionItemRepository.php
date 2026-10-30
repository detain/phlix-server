<?php

/**
 * Phlix media server component: Collections.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Collections;

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
     * Insert a media item into a collection — IDEMPOTENT.
     *
     * `collection_items` is keyed by `PRIMARY KEY (collection_id,
     * media_item_id)` (migrations/005). The plain INSERT this method used to
     * issue made a re-add — double-click, retry after a network blip, two
     * tabs — deterministically raise MySQL 1062, surfacing as an HTTP 500 on
     * POST /api/v1/collections/{id}/items/{mediaItemId} (M-SERVER-07).
     *
     * The cure is the estate's house duplicate-key idiom, the one
     * {@see \Phlix\Auth\WatchHistory::record()} narrates and
     * {@see \Phlix\Session\PlaybackController::reportProgress()} ships:
     * `INSERT ... ON DUPLICATE KEY UPDATE` against the same key. The survivor
     * clause here is deliberately the SELF-ASSIGNMENT NO-OP
     * `media_item_id = VALUES(media_item_id)` — a duplicate add leaves the
     * existing row completely untouched (neither sort_order nor added_at is
     * re-touched), so membership age and curated position never move (law
     * pinned by CollectionItemReAddRealDbTest). No read-probe pre-check is
     * used because the check-then-act shape is exactly the TOCTOU race
     * WatchHistory's docblock records; the write itself is atomic.
     *
     * Nothing is swallowed: the ODKU arm answers only the duplicate case —
     * every other SQL failure (FK/strict-mode/connection) still propagates
     * unchanged, so the route's 500 stays reserved for genuine breakage.
     *
     * @param string $collectionId Collection UUID
     * @param string $mediaItemId Media item UUID
     * @param int $sortOrder Display order within collection (applied only on a fresh add)
     * @return void
     *
     * @since 0.14.0
     */
    public function insert(string $collectionId, string $mediaItemId, int $sortOrder = 0): void
    {
        $this->db->query(
            "INSERT INTO collection_items (collection_id, media_item_id, sort_order, added_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE media_item_id = VALUES(media_item_id)",
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
     * Check which media items exist in a collection (batch check).
     *
     * @param string $collectionId Collection UUID
     * @param array<int, string> $mediaItemIds Array of media item UUIDs to check
     * @return array<string, bool> Map of media_item_id => exists (only includes IDs that exist)
     *
     * @since 0.14.0
     */
    public function findExistingInCollection(string $collectionId, array $mediaItemIds): array
    {
        if ($mediaItemIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($mediaItemIds), '?'));
        $result = $this->db->query(
            "SELECT media_item_id FROM collection_items
             WHERE collection_id = ? AND media_item_id IN ({$placeholders})",
            array_merge([$collectionId], $mediaItemIds)
        );

        $existing = [];
        if (is_array($result)) {
            foreach ($result as $row) {
                if (is_array($row) && array_key_exists('media_item_id', $row)) {
                    $existing[(string)$row['media_item_id']] = true;
                }
            }
        }

        return $existing;
    }

    /**
     * Batch insert multiple media items into a collection — IDEMPOTENT.
     *
     * Same M-SERVER-07 cure as {@see insert()}: without the ODKU no-op arm a
     * SINGLE duplicate tuple failed the entire multi-row statement with 1062,
     * so a bulk add racing a concurrent single add lost even its brand-new
     * items. Fresh rows land; duplicates keep their sealed position/stamp
     * (self-assignment no-op); non-duplicate failures still propagate.
     *
     * @param string $collectionId Collection UUID
     * @param array<int, array{mediaItemId: string, sortOrder: int}> $items Items to insert
     * @return void
     *
     * @since 0.14.0
     */
    public function batchInsert(string $collectionId, array $items): void
    {
        if ($items === []) {
            return;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $placeholders = [];

        foreach ($items as $item) {
            $placeholders[] = '(?, ?, ?, ?)';
        }

        $bindings = [];
        foreach ($items as $item) {
            $bindings[] = $collectionId;
            $bindings[] = $item['mediaItemId'];
            $bindings[] = $item['sortOrder'];
            $bindings[] = $now;
        }

        $this->db->query(
            "INSERT INTO collection_items (collection_id, media_item_id, sort_order, added_at)
             VALUES " . implode(', ', $placeholders) . "
             ON DUPLICATE KEY UPDATE media_item_id = VALUES(media_item_id)",
            $bindings
        );
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

    /**
     * Apply a membership diff atomically (L5): every add and every remove of
     * one smart-collection refresh commits or rolls back as a unit, so a
     * mid-loop DB failure can never leave the collection half-rebuilt —
     * previously the insert loop and the delete loop ran untransacted, and a
     * throw between them persisted an inconsistent membership set.
     *
     * Transaction idiom mirrors the house pattern in
     * {@see \Phlix\Server\Integrations\Trakt\DbTraktOAuthStateStore::fetchAndDelete()}
     * (begin → work → commit, rollback on every early exit or Throwable). The
     * failure path RETHROWS: a refresh caller must hear about a rolled-back
     * diff rather than receive a silent no-op. The per-row {@see insert()} is
     * itself duplicate-idempotent (M-SERVER-07 ODKU no-op), so a member the
     * diff-compute raced against lands as a position-preserving no-op instead
     * of a 1062 that would have rolled the whole diff back.
     *
     * @param string $collectionId   Collection UUID
     * @param list<string> $toAdd     Media item UUIDs to append, in order
     * @param list<string> $toRemove  Media item UUIDs to delete
     * @param int $startSortOrder     sort_order for the first $toAdd entry
     *
     * @throws \Throwable any statement failure, after rollback
     *
     * @since 0.14.0
     */
    public function applyMemberDiff(string $collectionId, array $toAdd, array $toRemove, int $startSortOrder): void
    {
        if ($toAdd === [] && $toRemove === []) {
            return;
        }

        $this->db->beginTrans();
        try {
            $sortOrder = $startSortOrder;
            foreach ($toAdd as $mediaItemId) {
                $this->insert($collectionId, $mediaItemId, $sortOrder);
                $sortOrder++;
            }

            foreach ($toRemove as $mediaItemId) {
                $this->delete($collectionId, $mediaItemId);
            }

            $this->db->commitTrans();
        } catch (\Throwable $e) {
            $this->db->rollBackTrans();
            throw $e;
        }
    }
}
