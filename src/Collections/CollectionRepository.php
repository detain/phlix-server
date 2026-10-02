<?php

/**
 * Phlix media server component: Collections.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Collections;

use Phlix\Common\Util\RowMap;
use Workerman\MySQL\Connection;

/**
 * CRUD operations for collections.
 *
 * Provides data access for the collections table using
 * Workerman\MySQL\Connection with parameterized queries.
 *
 * @since 0.14.0
 */
class CollectionRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * Insert a new collection.
     *
     * @param Collection $collection Collection to insert
     * @return void
     *
     * @since 0.14.0
     */
    public function insert(Collection $collection): void
    {
        // created_by (migration 112) is part of the identity of the row — the
        // controller stamps the actor on create; it is immutable afterwards
        // (update() deliberately never touches it).
        $this->db->query(
            "INSERT INTO collections
             (id, name, library_id, smart_playlist_id, parent_id, sort_order, created_at, updated_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $collection->id,
                $collection->name,
                $collection->libraryId,
                $collection->smartPlaylistId,
                $collection->parentId,
                $collection->sortOrder,
                $collection->createdAt->format('Y-m-d H:i:s'),
                $collection->updatedAt->format('Y-m-d H:i:s'),
                $collection->createdBy,
            ]
        );
    }

    /**
     * Update an existing collection.
     *
     * @param Collection $collection Collection to update
     * @return void
     *
     * @since 0.14.0
     */
    public function update(Collection $collection): void
    {
        $this->db->query(
            "UPDATE collections
             SET name = ?, library_id = ?, smart_playlist_id = ?, parent_id = ?, sort_order = ?, updated_at = ?
             WHERE id = ?",
            [
                $collection->name,
                $collection->libraryId,
                $collection->smartPlaylistId,
                $collection->parentId,
                $collection->sortOrder,
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                $collection->id,
            ]
        );
    }

    /**
     * Delete a collection by ID.
     *
     * @param string $id Collection ID to delete
     * @return void
     *
     * @since 0.14.0
     */
    public function delete(string $id): void
    {
        $this->db->query("DELETE FROM collections WHERE id = ?", [$id]);
    }

    /**
     * Find a collection by ID — deliberately UNSCOPED (no owner clause).
     *
     * Ownership authz is the HTTP layer's job (CollectionController resolves
     * the row, then compares `created_by` against the actor and refuses
     * foreign rows with the byte-identical not-found shape). Scoping here
     * would also drag identity into the smart-playlist refresh consumer paths
     * that have none. tests/Unit/Collections/CollectionRepositoryTest.php
     * pins the owner-clause ABSENCE so a future sweep can't rot this.
     *
     * @param string $id Collection ID
     * @return Collection|null Found collection or null
     *
     * @since 0.14.0
     */
    public function findById(string $id): ?Collection
    {
        $result = $this->db->query(
            "SELECT * FROM collections WHERE id = ?",
            [$id]
        );

        if (!is_array($result) || count($result) === 0) {
            return null;
        }

        $firstRow = $result[0];
        if (!is_array($firstRow)) {
            return null;
        }

        return Collection::fromRow(RowMap::fromMixed($firstRow));
    }

    /**
     * Find all collections for a library.
     *
     * @param string $libraryId Library UUID
     * @return array<int, Collection> Array of matching collections
     *
     * @since 0.14.0
     */
    public function findByLibraryId(string $libraryId): array
    {
        $results = $this->db->query(
            "SELECT * FROM collections WHERE library_id = ? ORDER BY sort_order, name",
            [$libraryId]
        );

        if (!is_array($results)) {
            return [];
        }

        $collections = [];
        foreach ($results as $row) {
            if (is_array($row)) {
                $collections[] = Collection::fromRow(RowMap::fromMixed($row));
            }
        }

        return $collections;
    }

    /**
     * Get all collections with pagination.
     *
     * @param int $limit Maximum number of collections to return (default: 1000)
     * @param int $offset Number of collections to skip (default: 0)
     *
     * @return array<int, Collection> Array of all collections
     *
     * @since 0.14.0
     */
    public function findAll(int $limit = 1000, int $offset = 0): array
    {
        $results = $this->db->query(
            "SELECT * FROM collections ORDER BY sort_order, name LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        if (!is_array($results)) {
            return [];
        }

        $collections = [];
        foreach ($results as $row) {
            if (is_array($row)) {
                $collections[] = Collection::fromRow(RowMap::fromMixed($row));
            }
        }

        return $collections;
    }

    /**
     * Owner-scoped variant of findByLibraryId(): the caller's own rows plus
     * the NULL-owner legacy rows (migration 112 policy — legacy lists stay
     * visible to everyone). Active admins do NOT use this method; they get
     * the raw findByLibraryId() set.
     *
     * @param string $libraryId Library UUID
     * @param string $userId Actor user id (non-empty — the controller guards)
     * @return array<int, Collection> Array of visible collections
     *
     * @since 0.15.0 (collections ownership, Option A)
     */
    public function findVisibleByLibraryId(string $libraryId, string $userId): array
    {
        $results = $this->db->query(
            "SELECT * FROM collections
             WHERE library_id = ? AND (created_by = ? OR created_by IS NULL)
             ORDER BY sort_order, name",
            [$libraryId, $userId]
        );

        if (!is_array($results)) {
            return [];
        }

        $collections = [];
        foreach ($results as $row) {
            if (is_array($row)) {
                $collections[] = Collection::fromRow(RowMap::fromMixed($row));
            }
        }

        return $collections;
    }

    /**
     * Owner-scoped variant of findAll(): the caller's own rows plus the
     * NULL-owner legacy rows (mirrors findVisibleByLibraryId's predicate).
     * Active admins do NOT use this method; they get the raw findAll() set.
     *
     * @param string $userId Actor user id (non-empty — the controller guards)
     * @param int $limit Maximum number of collections to return (default: 1000)
     * @param int $offset Number of collections to skip (default: 0)
     * @return array<int, Collection> Array of visible collections
     *
     * @since 0.15.0 (collections ownership, Option A)
     */
    public function findAllVisibleTo(string $userId, int $limit = 1000, int $offset = 0): array
    {
        $results = $this->db->query(
            "SELECT * FROM collections
             WHERE created_by = ? OR created_by IS NULL
             ORDER BY sort_order, name LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );

        if (!is_array($results)) {
            return [];
        }

        $collections = [];
        foreach ($results as $row) {
            if (is_array($row)) {
                $collections[] = Collection::fromRow(RowMap::fromMixed($row));
            }
        }

        return $collections;
    }

    /**
     * Find all collections by parent ID.
     *
     * @param string|null $parentId Parent collection UUID (null = top-level)
     * @return array<int, Collection> Array of matching collections
     *
     * @since 0.14.0
     */
    public function findByParentId(?string $parentId): array
    {
        if ($parentId === null) {
            $results = $this->db->query(
                "SELECT * FROM collections WHERE parent_id IS NULL ORDER BY sort_order, name"
            );
        } else {
            $results = $this->db->query(
                "SELECT * FROM collections WHERE parent_id = ? ORDER BY sort_order, name",
                [$parentId]
            );
        }

        if (!is_array($results)) {
            return [];
        }

        $collections = [];
        foreach ($results as $row) {
            if (is_array($row)) {
                $collections[] = Collection::fromRow(RowMap::fromMixed($row));
            }
        }

        return $collections;
    }

    /**
     * Find collections that reference a smart playlist.
     *
     * @param string $smartPlaylistId Smart playlist UUID
     * @return array<int, Collection> Array of matching collections
     *
     * @since 0.14.0
     */
    public function findBySmartPlaylistId(string $smartPlaylistId): array
    {
        $results = $this->db->query(
            "SELECT * FROM collections WHERE smart_playlist_id = ? ORDER BY sort_order, name",
            [$smartPlaylistId]
        );

        if (!is_array($results)) {
            return [];
        }

        $collections = [];
        foreach ($results as $row) {
            if (is_array($row)) {
                $collections[] = Collection::fromRow(RowMap::fromMixed($row));
            }
        }

        return $collections;
    }
}
