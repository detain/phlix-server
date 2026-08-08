<?php

/**
 * Phlix media server component: Media.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media;

use Phlix\Auth\SignedUrl;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\BackdropSrcset;
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
 * ## Artwork shaping contract (S104)
 *
 * The four read methods here are response-shaping exits: their return arrays go
 * straight onto the wire via {@see \Phlix\Server\WebPortal\WebPortalRouter}. Rows
 * do NOT pass through {@see \Phlix\Media\Library\MediaItemShaper}, so both of the
 * transforms that shaper applies have to be applied here as well, and for the same
 * reasons:
 *
 *  1. **Re-mint at RESPONSE time** — every emitted `poster_url`/`backdrop_url` runs
 *     through {@see SignedUrl::refreshArtworkUrl()}. Artwork URLs are signed at
 *     SCAN/enrich time with a bounded TTL and stored verbatim, so hours later every
 *     stored signature is expired; a client that fetches artwork WITHOUT a session
 *     authorises by signature alone and gets a 401 and a blank image. That is the
 *     2026-07-19 production incident (expired signed artwork URLs → broken images
 *     plus a re-download flood), whose fix was re-signing on the way OUT. This is
 *     latent until S72 caches artwork locally as `/api/v1/artwork/…`; external
 *     (TMDB) covers and null are returned unchanged, so it is a no-op until then.
 *     Do NOT "optimise" this back to scan time.
 *  2. **TMDB width-swap on backdrops** — the two {@see BackdropSrcset} size budgets
 *     are deliberately different, and both appear here. The three collection-level
 *     methods emit ONE box-set background per response, so they take the HERO budget
 *     ({@see BackdropSrcset::largeUrl()}, `/original`) — a genuine step UP from the
 *     `/w1280` this class stores in {@see self::getOrCreateCollection()}, since TMDB
 *     re-renders from the master asset. {@see self::getCollectionMembers()} returns
 *     up to N rows, so it takes the LIST-ROW budget
 *     ({@see BackdropSrcset::rowUrl()}) instead and never advertises `/original`.
 *     Non-TMDB and locally cached URLs have no width ladder and fall back to the
 *     stored value, which is then re-minted by (1).
 *
 * Posters are deliberately NOT width-swapped: `MediaItemShaper` does not swap the
 * single `poster_url` either (it keeps the stored width and derives a separate
 * `poster_srcset`), and this service emits no srcset field.
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
     * The TMDB API key is NOT a parameter here: it is resolved once by the
     * injected {@see TmdbProvider} (constructed with the configured
     * `metadata.tmdb.api_key`), so every request this method issues is already
     * authenticated by that provider. An earlier signature took a `$tmdbApiKey`
     * argument that the body never used — callers passed an empty string — which
     * this method drops entirely.
     *
     * @param int $collectionId TMDB collection ID
     * @return array{id: int, tmdb_collection_id: int, name: string, overview: string|null,
     *     poster_url: string|null, backdrop_url: string|null}|null Collection info or null on failure
     *
     * @since 0.36.0
     */
    public function getOrCreateCollection(int $collectionId): ?array
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

        $storedPoster = is_string($row['poster_url'] ?? null) ? $row['poster_url'] : null;
        $storedBackdrop = is_string($row['backdrop_url'] ?? null) ? $row['backdrop_url'] : null;

        return [
            'id' => is_int($row['id'] ?? null) ? $row['id'] : 0,
            'tmdb_collection_id' => is_int($row['tmdb_collection_id'] ?? null) ? $row['tmdb_collection_id'] : 0,
            'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
            'overview' => is_string($row['overview'] ?? null) ? $row['overview'] : null,
            'poster_url' => SignedUrl::refreshArtworkUrl($storedPoster),
            'backdrop_url' => SignedUrl::refreshArtworkUrl(
                BackdropSrcset::largeUrl($storedBackdrop) ?? $storedBackdrop
            ),
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
     * The TMDB API key is resolved by the injected {@see TmdbProvider} (from the
     * configured `metadata.tmdb.api_key`), not passed in — an earlier signature
     * took an unused `$tmdbApiKey` argument that callers filled with an empty
     * string. When no key is configured the provider cannot answer, so this
     * method skips cleanly (no HTTP, no DB writes) and reports success rather
     * than churning failing requests during a scan.
     *
     * @param string $movieItemId The local media_item UUID of the movie
     * @return bool True if sync succeeded (even if movie has no collection), false on failure
     *
     * @since 0.36.0
     */
    public function syncCollectionForMovie(string $movieItemId): bool
    {
        // No configured TMDB key → the provider cannot resolve collections. Skip
        // cleanly (a no-op success) instead of issuing requests that would just
        // fail; existing membership is left untouched rather than wrongly wiped.
        if (!$this->tmdbProvider->hasApiKey()) {
            return true;
        }

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
        $collection = $this->getOrCreateCollection($collectionId);
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
            $storedPoster = is_string($row['poster_url'] ?? null) ? $row['poster_url'] : null;
            $storedBackdrop = is_string($row['backdrop_url'] ?? null) ? $row['backdrop_url'] : null;

            $members[] = [
                'id' => is_string($row['id'] ?? null) ? $row['id'] : '',
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'type' => is_string($row['type'] ?? null) ? $row['type'] : '',
                'metadata_json' => $row['metadata_json'] ?? null,
                'poster_url' => SignedUrl::refreshArtworkUrl($storedPoster),
                // LIST-ROW budget, not the hero one: this is up to N member rows
                // per response, so `/original` is never advertised and the stored
                // `/w500` steps UP to BackdropSrcset's row width — byte-identical
                // to what MediaItemShaper::shape() does for a library row.
                'backdrop_url' => SignedUrl::refreshArtworkUrl(
                    BackdropSrcset::rowUrl($storedBackdrop) ?? $storedBackdrop
                ),
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

        $storedPoster = is_string($row['poster_url'] ?? null) ? $row['poster_url'] : null;
        $storedBackdrop = is_string($row['backdrop_url'] ?? null) ? $row['backdrop_url'] : null;

        return [
            'id' => is_int($row['id'] ?? null) ? $row['id'] : 0,
            'tmdb_collection_id' => is_int($row['tmdb_collection_id'] ?? null) ? $row['tmdb_collection_id'] : 0,
            'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
            'overview' => is_string($row['overview'] ?? null) ? $row['overview'] : null,
            'poster_url' => SignedUrl::refreshArtworkUrl($storedPoster),
            'backdrop_url' => SignedUrl::refreshArtworkUrl(
                BackdropSrcset::largeUrl($storedBackdrop) ?? $storedBackdrop
            ),
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

        $storedPoster = is_string($row['poster_url'] ?? null) ? $row['poster_url'] : null;
        $storedBackdrop = is_string($row['backdrop_url'] ?? null) ? $row['backdrop_url'] : null;

        return [
            'id' => is_int($row['id'] ?? null) ? $row['id'] : 0,
            'tmdb_collection_id' => is_int($row['tmdb_collection_id'] ?? null) ? $row['tmdb_collection_id'] : 0,
            'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
            'overview' => is_string($row['overview'] ?? null) ? $row['overview'] : null,
            'poster_url' => SignedUrl::refreshArtworkUrl($storedPoster),
            'backdrop_url' => SignedUrl::refreshArtworkUrl(
                BackdropSrcset::largeUrl($storedBackdrop) ?? $storedBackdrop
            ),
        ];
    }
}
