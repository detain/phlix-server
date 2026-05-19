<?php

declare(strict_types=1);

namespace Phlex\Media\Library;

use Workerman\MySQL\Connection;

/**
 * ItemRepository provides data access for media items in the database.
 *
 * This repository handles all CRUD operations for media_items and media_streams
 * tables, including querying, searching, filtering by content ratings and genres,
 * and stream management.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Data access layer for media items with content filtering support
 * @see LibraryManager For library-level operations
 */
class ItemRepository
{
    /** @var Connection Database connection */
    private Connection $db;

    /**
     * Constructor for ItemRepository.
     *
     * @param Connection $db Database connection for media item persistence
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Finds a media item by its unique identifier.
     *
     * @param string $id The media item's unique identifier
     * @return array<string, mixed>|null The hydrated media item array or null if not found
     */
    public function findById(string $id): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM media_items WHERE id = ?",
            [$id]
        );

        $row = $this->firstRow($result);
        if ($row === null) {
            return null;
        }

        return $this->hydrateItem($row);
    }

    /**
     * Finds a media item by its filesystem path.
     *
     * @param string $path The absolute filesystem path to the media file
     * @return array<string, mixed>|null The hydrated media item array or null if not found
     */
    public function findByPath(string $path): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM media_items WHERE path = ?",
            [$path]
        );

        $row = $this->firstRow($result);
        if ($row === null) {
            return null;
        }

        return $this->hydrateItem($row);
    }

    /**
     * Finds all child items of a parent media item.
     *
     * @param string $parentId The parent media item's unique identifier
     * @return array<int, array<string, mixed>> Array of hydrated child media items ordered by name
     */
    public function findByParent(string $parentId): array
    {
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE parent_id = ? ORDER BY name",
            [$parentId]
        );

        return $this->hydrateRows($results);
    }

    /**
     * Gets media items by type within a library with pagination.
     *
     * @param string $libraryId The library's unique identifier
     * @param string $type The media type filter (e.g., 'movie', 'series', 'audio')
     * @param int $limit Maximum number of items to return
     * @param int $offset Number of items to skip for pagination
     * @return array<int, array<string, mixed>> Array of hydrated media items
     */
    public function getByType(string $libraryId, string $type, int $limit = 100, int $offset = 0): array
    {
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE library_id = ? AND type = ? ORDER BY name LIMIT ? OFFSET ?",
            [$libraryId, $type, $limit, $offset]
        );

        return $this->hydrateRows($results);
    }

    /**
     * Gets all media items of a specific type across all libraries.
     *
     * @param string $type The media type filter (e.g., 'movie', 'audio', 'image')
     * @param int $limit Maximum number of items to return
     * @param int $offset Number of items to skip for pagination
     * @return array<int, array<string, mixed>> Array of hydrated media items
     *
     * @since 0.12.0
     */
    public function getAllByType(string $type, int $limit = 100, int $offset = 0): array
    {
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE type = ? ORDER BY name LIMIT ? OFFSET ?",
            [$type, $limit, $offset]
        );

        return $this->hydrateRows($results);
    }

    /**
     * Counts all media items of a specific type across all libraries.
     *
     * @param string $type The media type to count
     * @return int Number of items of the given type
     *
     * @since 0.12.0
     */
    public function countAllByType(string $type): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM media_items WHERE type = ?",
            [$type]
        );

        return $this->extractCount($result);
    }

    /**
     * Gets all media items within a library with pagination.
     *
     * @param string $libraryId The library's unique identifier
     * @param int $limit Maximum number of items to return
     * @param int $offset Number of items to skip for pagination
     * @return array<int, array<string, mixed>> Array of hydrated media items
     */
    public function getByLibrary(string $libraryId, int $limit = 100, int $offset = 0): array
    {
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE library_id = ? ORDER BY name LIMIT ? OFFSET ?",
            [$libraryId, $limit, $offset]
        );

        return $this->hydrateRows($results);
    }

    /**
     * Performs full-text search on media item names.
     *
     * @param string $query The search query for full-text matching
     * @param int $limit Maximum number of results to return
     * @return array<int, array<string, mixed>> Array of hydrated media items matching the query
     */
    public function search(string $query, int $limit = 50): array
    {
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE MATCH(name) AGAINST(? IN BOOLEAN MODE) LIMIT ?",
            [$query, $limit]
        );

        return $this->hydrateRows($results);
    }

    /**
     * Performs fuzzy/partial string matching on media item names.
     *
     * @param string $query The partial string to search for
     * @param int $limit Maximum number of results to return
     * @return array<int, array<string, mixed>> Array of hydrated media items matching the query
     */
    public function searchFuzzy(string $query, int $limit = 50): array
    {
        $escapedQuery = '%' . addcslashes($query, '%_') . '%';
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE name LIKE ? LIMIT ?",
            [$escapedQuery, $limit]
        );

        return $this->hydrateRows($results);
    }

    /**
     * Creates a new media item in the database.
     *
     * @param array<string, mixed> $data Media item data including library_id, name, type, path, and optionally metadata_json
     * @return string The unique identifier of the created media item
     * @throws \InvalidArgumentException If required fields are missing
     */
    public function create(array $data): string
    {
        $idCandidate = $data['id'] ?? null;
        $id = is_string($idCandidate) ? $idCandidate : $this->generateUuid();
        $metadataJson = isset($data['metadata_json'])
            ? (is_array($data['metadata_json']) ? json_encode($data['metadata_json']) : $data['metadata_json'])
            : '{}';

        $this->db->query(
            "INSERT INTO media_items (id, library_id, parent_id, name, type, path, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $data['library_id'],
                $data['parent_id'] ?? null,
                $data['name'],
                $data['type'],
                $data['path'],
                $metadataJson,
            ]
        );

        return $id;
    }

    /**
     * Updates an existing media item's properties.
     *
     * @param string $id The media item's unique identifier
     * @param array<string, mixed> $data Associative array of fields to update
     * @return void
     */
    public function update(string $id, array $data): void
    {
        $sets = [];
        $values = [];

        foreach ($data as $key => $value) {
            $sets[] = "$key = ?";
            if ($key === 'metadata_json' && is_array($value)) {
                $value = json_encode($value);
            }
            $values[] = $value;
        }

        if (empty($sets)) {
            return;
        }

        $values[] = $id;

        $this->db->query(
            "UPDATE media_items SET " . implode(', ', $sets) . " WHERE id = ?",
            $values
        );
    }

    /**
     * Deletes a media item by its identifier.
     *
     * @param string $id The media item's unique identifier
     * @return void
     */
    public function delete(string $id): void
    {
        $this->db->query("DELETE FROM media_items WHERE id = ?", [$id]);
    }

    /**
     * Deletes all media items belonging to a specific library.
     *
     * @param string $libraryId The library's unique identifier
     * @return void
     */
    public function deleteByLibrary(string $libraryId): void
    {
        $this->db->query("DELETE FROM media_items WHERE library_id = ?", [$libraryId]);
    }

    /**
     * Counts media items of a specific type within a library.
     *
     * @param string $libraryId The library's unique identifier
     * @param string $type The media type to count
     * @return int The number of items matching the criteria
     */
    public function countByType(string $libraryId, string $type): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM media_items WHERE library_id = ? AND type = ?",
            [$libraryId, $type]
        );

        return $this->extractCount($result);
    }

    /**
     * Gets recently added media items from a library.
     *
     * @param string $libraryId The library's unique identifier
     * @param int $limit Maximum number of items to return
     * @return array<int, array<string, mixed>> Array of recently added hydrated media items
     */
    public function getRecentlyAdded(string $libraryId, int $limit = 20): array
    {
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE library_id = ? ORDER BY created_at DESC LIMIT ?",
            [$libraryId, $limit]
        );

        return $this->hydrateRows($results);
    }

    /**
     * Gets all streams associated with a media item.
     *
     * @param string $itemId The media item's unique identifier
     * @return array<int, array<string, mixed>> Array of stream data arrays
     */
    public function getItemStreams(string $itemId): array
    {
        $result = $this->db->query(
            "SELECT * FROM media_streams WHERE media_item_id = ? ORDER BY stream_index",
            [$itemId]
        );

        if (!is_array($result)) {
            return [];
        }

        $rows = [];
        foreach ($result as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Adds a stream to a media item.
     *
     * @param string $itemId The media item's unique identifier
     * @param array<string, mixed> $streamData Stream data including stream_index, stream_type, codec, etc.
     * @return string The unique identifier of the created stream
     */
    public function addStream(string $itemId, array $streamData): string
    {
        $idCandidate = $streamData['id'] ?? null;
        $id = is_string($idCandidate) ? $idCandidate : $this->generateUuid();

        $this->db->query(
            "INSERT INTO media_streams (id, media_item_id, stream_index, stream_type, codec, language, bitrate, width, height)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $itemId,
                $streamData['stream_index'],
                $streamData['stream_type'],
                $streamData['codec'] ?? null,
                $streamData['language'] ?? null,
                $streamData['bitrate'] ?? null,
                $streamData['width'] ?? null,
                $streamData['height'] ?? null,
            ]
        );

        return $id;
    }

    /**
     * Gets the intro marker columns for a media item.
     *
     * @param string $itemId The media item's unique identifier
     * @return array{start_seconds: int|null, end_seconds: int|null, confidence: int|null}|null
     *
     * @since 0.12.0
     */
    public function getIntroMarker(string $itemId): ?array
    {
        $result = $this->db->query(
            "SELECT intro_start_seconds, intro_end_seconds, intro_confidence FROM media_items WHERE id = ?",
            [$itemId]
        );

        if (!is_array($result) || count($result) === 0) {
            return null;
        }

        $firstRow = $result[0];
        if (!is_array($firstRow)) {
            return null;
        }

        $introStart = $firstRow['intro_start_seconds'] ?? null;
        $introEnd = $firstRow['intro_end_seconds'] ?? null;
        $introConf = $firstRow['intro_confidence'] ?? null;

        return [
            'start_seconds' => is_int($introStart) || is_float($introStart) ? (int) $introStart : null,
            'end_seconds' => is_int($introEnd) || is_float($introEnd) ? (int) $introEnd : null,
            'confidence' => is_int($introConf) || is_float($introConf) ? (int) $introConf : null,
        ];
    }

    /**
     * Gets the outro marker columns for a media item.
     *
     * @param string $itemId The media item's unique identifier
     * @return array{start_seconds: int|null, end_seconds: int|null, confidence: int|null}|null
     *
     * @since 0.12.0
     */
    public function getOutroMarker(string $itemId): ?array
    {
        $result = $this->db->query(
            "SELECT outro_start_seconds, outro_end_seconds, outro_confidence FROM media_items WHERE id = ?",
            [$itemId]
        );

        if (!is_array($result) || count($result) === 0) {
            return null;
        }

        $firstRow = $result[0];
        if (!is_array($firstRow)) {
            return null;
        }

        $outroStart = $firstRow['outro_start_seconds'] ?? null;
        $outroEnd = $firstRow['outro_end_seconds'] ?? null;
        $outroConf = $firstRow['outro_confidence'] ?? null;

        return [
            'start_seconds' => is_int($outroStart) || is_float($outroStart) ? (int) $outroStart : null,
            'end_seconds' => is_int($outroEnd) || is_float($outroEnd) ? (int) $outroEnd : null,
            'confidence' => is_int($outroConf) || is_float($outroConf) ? (int) $outroConf : null,
        ];
    }

    /**
     * Gets the chapters JSON for a media item.
     *
     * @param string $itemId The media item's unique identifier
     * @return array<mixed, mixed>|null
     *
     * @since 0.12.0
     */
    public function getChapters(string $itemId): ?array
    {
        $result = $this->db->query(
            "SELECT chapters_json FROM media_items WHERE id = ?",
            [$itemId]
        );

        if (!is_array($result) || count($result) === 0) {
            return null;
        }

        $firstRow = $result[0];
        if (!is_array($firstRow)) {
            return null;
        }

        $chaptersJson = $firstRow['chapters_json'] ?? null;
        if ($chaptersJson === null) {
            return null;
        }

        if (is_string($chaptersJson)) {
            $chapters = json_decode($chaptersJson, true);
        } else {
            $chapters = $chaptersJson;
        }

        return is_array($chapters) ? $chapters : null;
    }

    /**
     * Updates the marker columns for a media item.
     *
     * @param string $itemId The media item's unique identifier
     * @param array<string, mixed> $markerData Marker data with optional keys:
     *   intro_start_seconds, intro_end_seconds, intro_confidence,
     *   outro_start_seconds, outro_end_seconds, outro_confidence,
     *   chapters_json
     *
     * @since 0.12.0
     */
    public function updateMarkers(string $itemId, array $markerData): void
    {
        $sets = [];
        $values = [];

        $markerColumns = [
            'intro_start_seconds',
            'intro_end_seconds',
            'intro_confidence',
            'outro_start_seconds',
            'outro_end_seconds',
            'outro_confidence',
        ];

        foreach ($markerColumns as $col) {
            if (array_key_exists($col, $markerData)) {
                $sets[] = "{$col} = ?";
                $values[] = $markerData[$col];
            }
        }

        if (isset($markerData['chapters_json'])) {
            $sets[] = "chapters_json = ?";
            $chapters = $markerData['chapters_json'];
            if (is_array($chapters)) {
                $chapters = json_encode($chapters);
            }
            $values[] = $chapters;
        }

        if (empty($sets)) {
            return;
        }

        $values[] = $itemId;

        $this->db->query(
            "UPDATE media_items SET " . implode(', ', $sets) . " WHERE id = ?",
            $values
        );
    }

    /**
     * Batch creates multiple media items.
     *
     * @param array<int, array<string, mixed>> $items Array of media item data arrays
     * @return array<string> Array of created media item identifiers
     */
    public function batchCreate(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            $ids[] = $this->create($item);
        }

        return $ids;
    }

    /**
     * Content rating order mapping from least to most restrictive.
     *
     * @var array<string, int> Rating string to numeric order mapping
     */
    public const RATING_ORDER = [
        'G' => 1,
        'PG' => 2,
        'PG-13' => 3,
        'R' => 4,
        'NC-17' => 5,
        'X' => 6,
        'UNRATED' => 7,
    ];

    /**
     * Get items filtered by allowed content ratings.
     *
     * @param string $libraryId Library to filter
     * @param array<string> $allowedRatings Array of allowed rating strings (e.g., ['G', 'PG'])
     * @param int $limit Max items to return
     * @param int $offset Pagination offset
     * @return array<int, array<string, mixed>> Filtered media items ordered by rating restriction level
     */
    public function getByAllowedRatings(string $libraryId, array $allowedRatings, int $limit = 100, int $offset = 0): array
    {
        // Build CASE expression for rating order comparison
        $ratingCases = [];
        foreach (self::RATING_ORDER as $rating => $order) {
            $ratingCases[] = "WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.rating')) = '{$rating}' THEN {$order}";
        }
        $ratingOrderSql = 'CASE ' . implode(' ', $ratingCases) . ' ELSE 999 END';

        // Build rating filter
        $ratingPlaceholders = implode(',', array_fill(0, count($allowedRatings), '?'));

        $results = $this->db->query(
            "SELECT * FROM media_items
             WHERE library_id = ?
               AND (
                   JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.rating')) IN ({$ratingPlaceholders})
                   OR JSON_EXTRACT(metadata_json, '$.rating') IS NULL
               )
             ORDER BY {$ratingOrderSql}, name
             LIMIT ? OFFSET ?",
            array_merge([$libraryId], $allowedRatings, [$limit, $offset])
        );

        return $this->hydrateRows($results);
    }

    /**
     * Get items filtered by a maximum content rating.
     *
     * @param string $libraryId Library to filter
     * @param string $maxRating Maximum allowed rating (e.g., 'R' excludes NC-17 and X)
     * @param int $limit Max items to return
     * @param int $offset Pagination offset
     * @return array<int, array<string, mixed>> Filtered media items
     */
    public function getByMaxRating(string $libraryId, string $maxRating, int $limit = 100, int $offset = 0): array
    {
        $maxOrder = self::RATING_ORDER[$maxRating] ?? 4;

        // Get all ratings up to and including maxRating
        $allowedRatings = [];
        foreach (self::RATING_ORDER as $rating => $order) {
            if ($order <= $maxOrder) {
                $allowedRatings[] = $rating;
            }
        }

        return $this->getByAllowedRatings($libraryId, $allowedRatings, $limit, $offset);
    }

    /**
     * Check if a media item's rating is within allowed ratings.
     *
     * @param string $itemId Media item ID to check
     * @param array<string> $allowedRatings Array of allowed rating strings
     * @return bool True if rating is allowed or item not found (safe default)
     */
    public function isRatingAllowed(string $itemId, array $allowedRatings): bool
    {
        $item = $this->findById($itemId);
        if (!$item) {
            return false;
        }

        $metadata = $item['metadata'] ?? null;
        $rating = is_array($metadata) && isset($metadata['rating'])
            ? $metadata['rating']
            : 'UNRATED';

        if ($rating === 'UNRATED') {
            return in_array('UNRATED', $allowedRatings);
        }

        return in_array($rating, $allowedRatings);
    }

    /**
     * Get items filtered by allowed genres.
     *
     * @param string $libraryId Library to filter
     * @param array<string> $allowedGenres Array of allowed genre strings
     * @param int $limit Max items to return
     * @param int $offset Pagination offset
     * @return array<int, array<string, mixed>> Filtered media items
     */
    public function getByAllowedGenres(string $libraryId, array $allowedGenres, int $limit = 100, int $offset = 0): array
    {
        if (empty($allowedGenres)) {
            return $this->getByLibrary($libraryId, $limit, $offset);
        }

        $genrePlaceholders = implode(',', array_fill(0, count($allowedGenres), '?'));

        // Use JSON_CONTAINS for genre array matching
        $results = $this->db->query(
            "SELECT * FROM media_items
             WHERE library_id = ?
               AND (
                   JSON_CONTAINS(metadata_json, ?) > 0
                   OR JSON_EXTRACT(metadata_json, '$.genres') IS NULL
               )
             ORDER BY name
             LIMIT ? OFFSET ?",
            array_merge([$libraryId], $allowedGenres, [$limit, $offset])
        );

        return $this->hydrateRows($results);
    }

    /**
     * Get items excluding blocked genres.
     *
     * @param string $libraryId Library to filter
     * @param array<string> $blockedGenres Array of blocked genre strings
     * @param int $limit Max items to return
     * @param int $offset Pagination offset
     * @return array<int, array<string, mixed>> Filtered media items
     */
    public function getExcludingGenres(string $libraryId, array $blockedGenres, int $limit = 100, int $offset = 0): array
    {
        if (empty($blockedGenres)) {
            return $this->getByLibrary($libraryId, $limit, $offset);
        }

        $genrePlaceholders = implode(',', array_fill(0, count($blockedGenres), '?'));

        $results = $this->db->query(
            "SELECT * FROM media_items
             WHERE library_id = ?
               AND JSON_CONTAINS(metadata_json, ?) = 0
             ORDER BY name
             LIMIT ? OFFSET ?",
            array_merge([$libraryId], $blockedGenres, [$limit, $offset])
        );

        return $this->hydrateRows($results);
    }

    /**
     * Hydrates a database row with decoded metadata.
     *
     * @param array<string, mixed> $row Database row with metadata_json field
     * @return array<string, mixed> Row with added 'metadata' key containing decoded JSON
     */
    private function hydrateItem(array $row): array
    {
        $row['metadata_json'] = $row['metadata_json'] ?? '{}';
        if (is_string($row['metadata_json'])) {
            $row['metadata'] = json_decode($row['metadata_json'], true) ?? [];
        } else {
            $row['metadata'] = $row['metadata_json'];
        }
        return $row;
    }

    /**
     * Hydrates a list of raw DB rows into media item arrays, filtering out any
     * non-array entries that the database driver might return as `mixed`.
     *
     * @param mixed $results Raw result set from {@see Connection::query()}.
     * @return list<array<string, mixed>> Hydrated rows.
     */
    private function hydrateRows(mixed $results): array
    {
        if (!is_array($results)) {
            return [];
        }
        $out = [];
        foreach ($results as $row) {
            $normalized = $this->normalizeRow($row);
            if ($normalized !== null) {
                $out[] = $this->hydrateItem($normalized);
            }
        }
        return $out;
    }

    /**
     * Returns the first row of a query result if present and array-typed.
     *
     * @param mixed $results Raw result set from {@see Connection::query()}.
     * @return array<string, mixed>|null First row or null.
     */
    private function firstRow(mixed $results): ?array
    {
        if (!is_array($results) || count($results) === 0) {
            return null;
        }
        return $this->normalizeRow($results[0] ?? null);
    }

    /**
     * Coerces a single raw query row into a string-keyed associative array.
     *
     * @param mixed $row Raw row value.
     * @return array<string, mixed>|null
     */
    private function normalizeRow(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $out = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Extracts a `count` aggregate from a `SELECT COUNT(*) as count` result set.
     *
     * @param mixed $results Raw result set from {@see Connection::query()}.
     */
    private function extractCount(mixed $results): int
    {
        $row = $this->firstRow($results);
        if ($row === null) {
            return 0;
        }
        $count = $row['count'] ?? 0;
        if (is_int($count)) {
            return $count;
        }
        if (is_string($count) && is_numeric($count)) {
            return (int) $count;
        }
        if (is_float($count)) {
            return (int) $count;
        }
        return 0;
    }

    /**
     * Generates a v4 UUID for media item and stream identifiers.
     *
     * @return string A formatted UUID string (xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx)
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
