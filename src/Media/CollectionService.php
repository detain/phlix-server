<?php

/**
 * Phlix media server component: Media.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\TmdbProvider;
use Workerman\MySQL\Connection;

/**
 * Manages TMDB box-set/collection grouping for media items.
 *
 * Box-sets (e.g., "The Lord of the Rings Collection", "Harry Potter Complete 8-Film Collection")
 * are groups of movies that TMDB tracks as a collection. This service syncs those groupings
 * into local database tables so the UI can display "Part of a Collection" metadata and
 * allow browsing all items in a collection.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Syncs TMDB box-set collections to local database
 * @see TmdbProvider For TMDB API access
 */
final class CollectionService
{
    /** @var Connection Database connection */
    private Connection $db;

    /** @var ItemRepository Media item repository */
    private ItemRepository $itemRepository;

    /** @var TmdbProvider TMDB metadata provider */
    private TmdbProvider $tmdbProvider;

    public function __construct(
        Connection $db,
        ItemRepository $itemRepository,
        TmdbProvider $tmdbProvider
    ) {
        $this->db = $db;
        $this->itemRepository = $itemRepository;
        $this->tmdbProvider = $tmdbProvider;
    }

    /**
     * Fetches a TMDB collection and creates or updates the local record.
     *
     * @param int $collectionId TMDB collection ID
     * @param string $tmdbApiKey TMDB API key for the request
     * @return array{id: int, tmdb_collection_id: int, name: string, overview: string|null,
     *     poster_url: string|null, backdrop_url: string|null}|null Collection info or null on failure
     *
     * @since 0.36.0
     */
    public function getOrCreateCollection(int $collectionId, string $tmdbApiKey): ?array
    {
        // Fetch collection metadata from TMDB
        $tmdbCollection = $this->tmdbProvider->getCollection($collectionId);
        if ($tmdbCollection === null) {
            return null;
        }

        // Build poster/backdrop URLs
        $posterUrl = $tmdbCollection['poster_path'] !== null
            ? 'https://image.tmdb.org/t/p/w500' . $tmdbCollection['poster_path']
            : null;
        $backdropUrl = $tmdbCollection['backdrop_path'] !== null
            ? 'https://image.tmdb.org/t/p/w1280' . $tmdbCollection['backdrop_path']
            : null;

        // Upsert the collection record
        $this->db->query(
            'INSERT INTO media_collections (tmdb_collection_id, name, overview, poster_url, backdrop_url)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 name = VALUES(name),
                 overview = VALUES(overview),
                 poster_url = VALUES(poster_url),
                 backdrop_url = VALUES(backdrop_url)',
            [
                $collectionId,
                $tmdbCollection['name'],
                $tmdbCollection['overview'],
                $posterUrl,
                $backdropUrl,
            ]
        );

        // Fetch the persisted record
        $rows = $this->db->query(
            'SELECT id, tmdb_collection_id, name, overview, poster_url, backdrop_url
             FROM media_collections WHERE tmdb_collection_id = ? LIMIT 1',
            [$collectionId]
        );

        if (!is_array($rows) || count($rows) === 0) {
            return null;
        }

        $row = $rows[0];
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => is_int($row['id'] ?? null) ? $row['id'] : 0,
            'tmdb_collection_id' => is_int($row['tmdb_collection_id'] ?? null) ? $row['tmdb_collection_id'] : 0,
            'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
            'overview' => is_string($row['overview'] ?? null) ? $row['overview'] : null,
            'poster_url' => is_string($row['poster_url'] ?? null) ? $row['poster_url'] : null,
            'backdrop_url' => is_string($row['backdrop_url'] ?? null) ? $row['backdrop_url'] : null,
        ];
    }

    /**
     * Synchronizes collection membership for a movie item.
     *
     * If the movie has a TMDB collection, fetches the collection from TMDB (creating
     * or updating the local record), then links the movie to the collection with the
     * correct part order from TMDB. If the movie has no collection, any existing
     * collection membership is removed.
     *
     * @param string $movieItemId The local media_item UUID of the movie
     * @param string $tmdbApiKey TMDB API key for the request
     * @return bool True if sync succeeded (even if movie has no collection), false on failure
     *
     * @since 0.36.0
     */
    public function syncCollectionForMovie(string $movieItemId, string $tmdbApiKey): bool
    {
        // Find the movie item and extract its TMDB ID from metadata_json
        $movie = $this->itemRepository->findById($movieItemId);
        if ($movie === null) {
            return false;
        }

        $metadata = $movie['metadata_json'] ?? null;
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $tmdbId = isset($metadata['tmdb_id']) && is_numeric($metadata['tmdb_id'])
            ? (int) $metadata['tmdb_id']
            : null;

        if ($tmdbId === null) {
            // No TMDB ID - remove any existing membership and return success
            $this->removeCollectionMembership($movieItemId);
            return true;
        }

        // Query TMDB for the movie's collection ID
        $collectionId = $this->tmdbProvider->getCollectionIdForMovie($tmdbId);

        if ($collectionId === null) {
            // Movie is not part of any collection - remove any existing membership
            $this->removeCollectionMembership($movieItemId);
            return true;
        }

        // Fetch/create the collection
        $collection = $this->getOrCreateCollection($collectionId, $tmdbApiKey);
        if ($collection === null) {
            return false;
        }

        // Find the part order from TMDB's collection data
        $tmdbCollection = $this->tmdbProvider->getCollection($collectionId);
        $partOrder = 0;
        if ($tmdbCollection !== null && is_array($tmdbCollection['parts'])) {
            foreach ($tmdbCollection['parts'] as $index => $part) {
                if (isset($part['id']) && (int) $part['id'] === $tmdbId) {
                    $partOrder = $index + 1;
                    break;
                }
            }
        }

        // Upsert the membership record
        $this->db->query(
            'INSERT INTO media_collection_members (collection_id, media_item_id, tmdb_part_order)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE tmdb_part_order = VALUES(tmdb_part_order)',
            [
                $collection['id'],
                $movieItemId,
                $partOrder,
            ]
        );

        return true;
    }

    /**
     * Removes a movie's collection membership if any exists.
     *
     * @param string $movieItemId The local media_item UUID
     * @return void
     */
    private function removeCollectionMembership(string $movieItemId): void
    {
        $this->db->query(
            'DELETE FROM media_collection_members WHERE media_item_id = ?',
            [$movieItemId]
        );
    }

    /**
     * Returns all items that belong to a collection.
     *
     * @param int $collectionId The local media_collections.id value
     * @return array<int, array<string, mixed>> Collection member media items, ordered by tmdb_part_order
     *
     * @since 0.36.0
     */
    public function getCollectionMembers(int $collectionId): array
    {
        $rows = $this->db->query(
            'SELECT m.id, m.name, m.type, m.metadata_json, m.poster_url, m.backdrop_url,
                    mcm.tmdb_part_order
             FROM media_collection_members mcm
             JOIN media_items m ON m.id = mcm.media_item_id
             WHERE mcm.collection_id = ?
             ORDER BY mcm.tmdb_part_order ASC',
            [$collectionId]
        );

        if (!is_array($rows)) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $members */
        $members = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $members[] = [
                'id' => is_string($row['id'] ?? null) ? $row['id'] : '',
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'type' => is_string($row['type'] ?? null) ? $row['type'] : '',
                'metadata_json' => $row['metadata_json'] ?? null,
                'poster_url' => is_string($row['poster_url'] ?? null) ? $row['poster_url'] : null,
                'backdrop_url' => is_string($row['backdrop_url'] ?? null) ? $row['backdrop_url'] : null,
                'tmdb_part_order' => is_int($row['tmdb_part_order'] ?? null) ? $row['tmdb_part_order'] : 0,
            ];
        }

        return $members;
    }

    /**
     * Returns the collection that a media item belongs to, if any.
     *
     * @param string $mediaItemId The local media_item UUID
     * @return array{id: int, tmdb_collection_id: int, name: string, overview: string|null,
     *     poster_url: string|null, backdrop_url: string|null}|null Collection info or null
     *
     * @since 0.36.0
     */
    public function getCollectionForItem(string $mediaItemId): ?array
    {
        $rows = $this->db->query(
            'SELECT mc.id, mc.tmdb_collection_id, mc.name, mc.overview,
                    mc.poster_url, mc.backdrop_url
             FROM media_collections mc
             JOIN media_collection_members mcm ON mcm.collection_id = mc.id
             WHERE mcm.media_item_id = ?
             LIMIT 1',
            [$mediaItemId]
        );

        if (!is_array($rows) || count($rows) === 0) {
            return null;
        }

        $row = $rows[0];
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => is_int($row['id'] ?? null) ? $row['id'] : 0,
            'tmdb_collection_id' => is_int($row['tmdb_collection_id'] ?? null) ? $row['tmdb_collection_id'] : 0,
            'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
            'overview' => is_string($row['overview'] ?? null) ? $row['overview'] : null,
            'poster_url' => is_string($row['poster_url'] ?? null) ? $row['poster_url'] : null,
            'backdrop_url' => is_string($row['backdrop_url'] ?? null) ? $row['backdrop_url'] : null,
        ];
    }

    /**
     * Returns a collection by its local ID.
     *
     * @param int $collectionId The local media_collections.id value
     * @return array{id: int, tmdb_collection_id: int, name: string, overview: string|null,
     *     poster_url: string|null, backdrop_url: string|null}|null Collection info or null
     *
     * @since 0.36.0
     */
    public function getCollectionById(int $collectionId): ?array
    {
        $rows = $this->db->query(
            'SELECT id, tmdb_collection_id, name, overview, poster_url, backdrop_url
             FROM media_collections WHERE id = ? LIMIT 1',
            [$collectionId]
        );

        if (!is_array($rows) || count($rows) === 0) {
            return null;
        }

        $row = $rows[0];
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => is_int($row['id'] ?? null) ? $row['id'] : 0,
            'tmdb_collection_id' => is_int($row['tmdb_collection_id'] ?? null) ? $row['tmdb_collection_id'] : 0,
            'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
            'overview' => is_string($row['overview'] ?? null) ? $row['overview'] : null,
            'poster_url' => is_string($row['poster_url'] ?? null) ? $row['poster_url'] : null,
            'backdrop_url' => is_string($row['backdrop_url'] ?? null) ? $row['backdrop_url'] : null,
        ];
    }
}
