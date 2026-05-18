<?php

declare(strict_types=1);

namespace Phlex\Collections;

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
        $this->db->query(
            "INSERT INTO collections
             (id, name, library_id, smart_playlist_id, parent_id, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $collection->id,
                $collection->name,
                $collection->libraryId,
                $collection->smartPlaylistId,
                $collection->parentId,
                $collection->sortOrder,
                $collection->createdAt->format('Y-m-d H:i:s'),
                $collection->updatedAt->format('Y-m-d H:i:s'),
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
     * Find a collection by ID.
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

        return Collection::fromRow($firstRow);
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
                $collections[] = Collection::fromRow($row);
            }
        }

        return $collections;
    }

    /**
     * Get all collections.
     *
     * @return array<int, Collection> Array of all collections
     *
     * @since 0.14.0
     */
    public function findAll(): array
    {
        $results = $this->db->query("SELECT * FROM collections ORDER BY sort_order, name");

        if (!is_array($results)) {
            return [];
        }

        $collections = [];
        foreach ($results as $row) {
            if (is_array($row)) {
                $collections[] = Collection::fromRow($row);
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
                $collections[] = Collection::fromRow($row);
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
                $collections[] = Collection::fromRow($row);
            }
        }

        return $collections;
    }
}
