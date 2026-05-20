<?php

declare(strict_types=1);

namespace Phlix\Playlists;

use Phlix\Common\Util\RowMap;
use Workerman\MySQL\Connection;

/**
 * CRUD operations for smart playlists.
 *
 * Provides data access for the smart_playlists table using
 * Workerman\MySQL\Connection with parameterized queries.
 *
 * @since 0.14.0
 */
final class SmartPlaylistRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * Insert a new smart playlist.
     *
     * @param SmartPlaylist $playlist Playlist to insert
     * @return void
     *
     * @since 0.14.0
     */
    public function insert(SmartPlaylist $playlist): void
    {
        $this->db->query(
            "INSERT INTO smart_playlists (id, name, library_id, rules_json, `limit`, sort_by, sort_desc, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $playlist->id,
                $playlist->name,
                $playlist->libraryId,
                $playlist->rulesJson,
                $playlist->limit,
                $playlist->sortBy,
                $playlist->sortDesc ? 1 : 0,
                $playlist->createdAt->format('Y-m-d H:i:s'),
                $playlist->updatedAt->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Update an existing smart playlist.
     *
     * @param SmartPlaylist $playlist Playlist to update
     * @return void
     *
     * @since 0.14.0
     */
    public function update(SmartPlaylist $playlist): void
    {
        $this->db->query(
            "UPDATE smart_playlists
             SET name = ?, library_id = ?, rules_json = ?, `limit` = ?, sort_by = ?, sort_desc = ?, updated_at = ?
             WHERE id = ?",
            [
                $playlist->name,
                $playlist->libraryId,
                $playlist->rulesJson,
                $playlist->limit,
                $playlist->sortBy,
                $playlist->sortDesc ? 1 : 0,
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                $playlist->id,
            ]
        );
    }

    /**
     * Delete a smart playlist by ID.
     *
     * @param string $id Playlist ID to delete
     * @return void
     *
     * @since 0.14.0
     */
    public function delete(string $id): void
    {
        $this->db->query("DELETE FROM smart_playlists WHERE id = ?", [$id]);
    }

    /**
     * Find a smart playlist by ID.
     *
     * @param string $id Playlist ID
     * @return SmartPlaylist|null Found playlist or null
     *
     * @since 0.14.0
     */
    public function findById(string $id): ?SmartPlaylist
    {
        $result = $this->db->query(
            "SELECT * FROM smart_playlists WHERE id = ?",
            [$id]
        );

        if (!is_array($result) || count($result) === 0) {
            return null;
        }

        $firstRow = $result[0];
        if (!is_array($firstRow)) {
            return null;
        }

        return SmartPlaylist::fromRow(RowMap::fromMixed($firstRow));
    }

    /**
     * Find all smart playlists for a library.
     *
     * @param string $libraryId Library UUID
     * @return array<int, SmartPlaylist> Array of matching playlists
     *
     * @since 0.14.0
     */
    public function findByLibraryId(string $libraryId): array
    {
        $results = $this->db->query(
            "SELECT * FROM smart_playlists WHERE library_id = ?",
            [$libraryId]
        );

        if (!is_array($results)) {
            return [];
        }

        $playlists = [];
        foreach ($results as $row) {
            if (is_array($row)) {
                $playlists[] = SmartPlaylist::fromRow(RowMap::fromMixed($row));
            }
        }

        return $playlists;
    }

    /**
     * Get all smart playlists.
     *
     * @return array<int, SmartPlaylist> Array of all playlists
     *
     * @since 0.14.0
     */
    public function findAll(): array
    {
        $results = $this->db->query("SELECT * FROM smart_playlists ORDER BY name");

        if (!is_array($results)) {
            return [];
        }

        $playlists = [];
        foreach ($results as $row) {
            if (is_array($row)) {
                $playlists[] = SmartPlaylist::fromRow(RowMap::fromMixed($row));
            }
        }

        return $playlists;
    }
}
