<?php

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Stats\StatsCollector;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * ItemRepository provides data access for media items in the database.
 *
 * This repository handles all CRUD operations for media_items and media_streams
 * tables, including querying, searching, filtering by content ratings and genres,
 * and stream management.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Data access layer for media items with content filtering support
 * @see LibraryManager For library-level operations
 */
class ItemRepository
{
    /** @var Connection Database connection */
    private Connection $db;

    /**
     * Optional stats collector. When wired, item adds/removes are recorded into
     * stats_library_changes, which feeds the admin dashboard activity feed.
     * Null in unit tests / legacy callers (recording no-ops).
     *
     * @var StatsCollector|null
     */
    private ?StatsCollector $statsCollector;

    /**
     * Constructor for ItemRepository.
     *
     * @param Connection $db Database connection for media item persistence
     * @param StatsCollector|null $statsCollector Optional collector; records
     *        item add/remove changes for the admin dashboard when supplied.
     */
    public function __construct(Connection $db, ?StatsCollector $statsCollector = null)
    {
        $this->db = $db;
        $this->statsCollector = $statsCollector;
    }

    /**
     * Record a library-change stat for the admin dashboard.
     *
     * No-ops when no {@see StatsCollector} is wired. Any failure is swallowed
     * so statistics collection can never break a library scan or delete.
     *
     * @param string      $changeType   'item_added', 'item_removed', or 'library_cleared'.
     * @param string|null $mediaItemId  Affected media item UUID, if applicable.
     * @param string|null $libraryId    Owning library UUID, if known.
     */
    private function recordChange(string $changeType, ?string $mediaItemId, ?string $libraryId): void
    {
        if ($this->statsCollector === null) {
            return;
        }
        try {
            $this->statsCollector->recordLibraryChange($changeType, $mediaItemId, $libraryId);
        } catch (Throwable) {
            // Stats recording must never break library operations.
        }
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
        // Children are a season/episode drill-down, not an alphabetical browse
        // listing — they keep raw-name ordering (the UI re-sorts episodes by
        // season/episode number), so the article-stripping rule is intentionally
        // NOT applied here. It is reserved for the top-level listings + A-Z rail.
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
            "SELECT * FROM media_items WHERE library_id = ? AND type = ? ORDER BY " . self::titleOrder() . " LIMIT ? OFFSET ?",
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
            "SELECT * FROM media_items WHERE type = ? ORDER BY " . self::titleOrder() . " LIMIT ? OFFSET ?",
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
            "SELECT * FROM media_items WHERE library_id = ? ORDER BY " . self::titleOrder() . " LIMIT ? OFFSET ?",
            [$libraryId, $limit, $offset]
        );

        return $this->hydrateRows($results);
    }

    /**
     * Performs full-text search on media item names.
     *
     * Raw user input is passed to a FULLTEXT … AGAINST(… IN BOOLEAN MODE)
     * match. Boolean mode treats `+ - > < ( ) ~ * " @` as operators, so an
     * unbalanced or operator-only query (e.g. an email address or `C++`)
     * makes MySQL raise a "syntax error in fulltext search expression" and
     * the whole request would otherwise blow up. Fall back to a plain LIKE
     * scan so search degrades gracefully instead of erroring out.
     *
     * @param string $query The search query for full-text matching
     * @param int $limit Maximum number of results to return
     * @return array<int, array<string, mixed>> Array of hydrated media items matching the query
     */
    public function search(string $query, int $limit = 50): array
    {
        try {
            $results = $this->db->query(
                "SELECT * FROM media_items WHERE MATCH(name) AGAINST(? IN BOOLEAN MODE) LIMIT ?",
                [$query, $limit]
            );

            return $this->hydrateRows($results);
        } catch (\Throwable) {
            return $this->searchFuzzy($query, $limit);
        }
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
                self::toValidUtf8($data['name'] ?? null),
                $data['type'],
                self::toValidUtf8($data['path'] ?? null),
                $metadataJson,
            ]
        );

        $libraryId = isset($data['library_id']) && is_string($data['library_id'])
            ? $data['library_id']
            : null;
        $this->recordChange('item_added', $id, $libraryId);

        return $id;
    }

    /**
     * Guarantee a value is valid UTF-8 before it reaches a utf8mb4 column.
     *
     * The `media_items.name`/`path` columns are utf8mb4; a value that is not
     * valid UTF-8 (a genuinely non-UTF-8 / Windows-1252 filesystem name, or a
     * multibyte sequence broken by upstream byte-wise string handling) fails
     * to insert with MySQL error 1366 ("Incorrect string value"). Valid UTF-8
     * — the overwhelming majority — is returned untouched; otherwise invalid
     * byte sequences are dropped so the write cannot fail. Non-strings pass
     * through unchanged.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function toValidUtf8(mixed $value): mixed
    {
        if (!is_string($value) || $value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $scrubbed = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        return is_string($scrubbed) ? $scrubbed : $value;
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
            } elseif ($key === 'name' || $key === 'path') {
                $value = self::toValidUtf8($value);
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
        // Capture the owning library before the row is gone so the change can
        // be attributed (cheap single-row lookup, only when a collector is wired).
        $libraryId = null;
        if ($this->statsCollector !== null) {
            $rows = $this->db->query("SELECT library_id FROM media_items WHERE id = ?", [$id]);
            $first = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
            $lib = $first['library_id'] ?? null;
            if (is_string($lib)) {
                $libraryId = $lib;
            }
        }

        $this->db->query("DELETE FROM media_items WHERE id = ?", [$id]);

        $this->recordChange('item_removed', $id, $libraryId);
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

        // One aggregate change row rather than one per deleted item — bulk
        // library clears would otherwise flood stats_library_changes.
        $this->recordChange('library_cleared', null, $libraryId);
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

        // Rating restriction first, then an article-insensitive alphabetical tiebreak.
        $orderBy = $ratingOrderSql . ', ' . self::titleOrder();

        $results = $this->db->query(
            "SELECT * FROM media_items
             WHERE library_id = ?
               AND (
                   JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.rating')) IN ({$ratingPlaceholders})
                   OR JSON_EXTRACT(metadata_json, '$.rating') IS NULL
               )
             ORDER BY {$orderBy}
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

        // One containment test per allowed genre, each scoped to '$.genres' and fed a
        // JSON-encoded candidate (a bare string is not a valid JSON_CONTAINS candidate).
        $genreWheres = implode(
            ' OR ',
            array_fill(0, count($allowedGenres), "JSON_CONTAINS(metadata_json, ?, '\$.genres') > 0")
        );
        $encodedGenres = array_map(static fn ($g) => json_encode($g), $allowedGenres);

        $orderBy = self::titleOrder();

        $results = $this->db->query(
            "SELECT * FROM media_items
             WHERE library_id = ?
               AND (
                   {$genreWheres}
                   OR JSON_EXTRACT(metadata_json, '\$.genres') IS NULL
               )
             ORDER BY {$orderBy}
             LIMIT ? OFFSET ?",
            array_merge([$libraryId], $encodedGenres, [$limit, $offset])
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

        $orderBy = self::titleOrder();

        $results = $this->db->query(
            "SELECT * FROM media_items
             WHERE library_id = ?
               AND JSON_CONTAINS(metadata_json, ?) = 0
             ORDER BY {$orderBy}
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
     * Finds series that have episodes without intro markers.
     *
     * An episode is considered "unfingerprinted" when intro_start_seconds is NULL.
     * Only returns shows that have at least one episode needing fingerprinting.
     *
     * @param int $limit Maximum number of show IDs to return (default 20)
     *
     * @return array<string> Array of show/series media item IDs with unfingerprinted episodes
     *
     * @since 0.12.0
     */
    public function findShowsWithUnfingerprintedEpisodes(int $limit = 20): array
    {
        $result = $this->db->query(
            "SELECT DISTINCT parent_id as show_id
             FROM media_items
             WHERE type IN ('episode', '_episode')
               AND parent_id IS NOT NULL
               AND intro_start_seconds IS NULL
             LIMIT ?",
            [$limit]
        );

        if (!is_array($result)) {
            return [];
        }

        $showIds = [];
        foreach ($result as $row) {
            if (is_array($row) && isset($row['show_id']) && is_string($row['show_id'])) {
                $showIds[] = $row['show_id'];
            }
        }

        return $showIds;
    }

    /**
     * Queries media items with flexible filtering, sorting, and pagination.
     *
     * Honors the library-query schema params over metadata_json, building on the
     * existing getByAllowedGenres() (JSON_CONTAINS) and search() (FULLTEXT/LIKE)
     * patterns. All filter conditions are AND-combined; array-valued filters
     * (genres, ratings, actors) use OR logic within the array.
     *
     * @param array<string, mixed> $params Query parameters:
     *   - search (string|null): Full-text or fuzzy name search
     *   - genres (string[]|null): Filter to items with any of these genres
     *   - yearFrom (int|null): Minimum release year (inclusive)
     *   - yearTo (int|null): Maximum release year (inclusive)
     *   - ratings (string[]|null): Filter to items with any of these ratings
     *   - actors (string[]|null): Filter to items featuring any of these actors
     *   - sort (string): Sort field — name|year|rating|date_added|runtime (default: name)
     *   - order (string): Sort direction — asc|desc (default: asc)
     *   - limit (int): Max items to return 1-100 (default: 50)
     *   - offset (int): Items to skip for pagination (default: 0)
     *   - parentId (string|null): Scope to the direct children of one item (its
     *     seasons/episodes) — drives the series detail drill-down
     *   - topLevel (bool): Return only parent-less items (movies + series),
     *     excluding seasons/episodes — drives Browse rails/library grids. Ignored
     *     when `search` is set (so search still spans the whole library). Mutually
     *     exclusive with `parentId` (parentId wins).
     * @param string|null $libraryId Optional library ID to scope results to one library
     *
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int}
     *
     * @since 0.13.0
     */
    public function query(array $params, ?string $libraryId = null): array
    {
        ['wheres' => $wheres, 'bindings' => $bindings] = $this->buildFilters($params, $libraryId);

        $sortRaw = isset($params['sort']) && is_scalar($params['sort']) ? (string) $params['sort'] : 'name';
        $orderRaw = isset($params['order']) && is_scalar($params['order']) ? (string) $params['order'] : 'asc';
        $sort = $this->normalizeSortField($sortRaw);
        $order = $this->normalizeSortOrder($orderRaw);
        $limit = $this->normalizeLimit($params['limit'] ?? 50);
        $offset = $this->normalizeOffset($params['offset'] ?? 0);

        $orderClause = $this->buildOrderClause($sort, $order);

        $countSql = 'SELECT COUNT(*) as count FROM media_items WHERE ' . implode(' AND ', $wheres);
        $countResult = $this->db->query($countSql, $bindings);
        $total = $this->extractCount($countResult);

        $selectSql = 'SELECT * FROM media_items WHERE ' . implode(' AND ', $wheres) . " ORDER BY {$orderClause} LIMIT ? OFFSET ?";
        $fetchBindings = array_merge($bindings, [$limit, $offset]);
        $results = $this->db->query($selectSql, $fetchBindings);

        return [
            'items' => $this->hydrateRows($results),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Build the WHERE clause + bindings shared by {@see self::query()} and
     * {@see self::letterCounts()} from the public media-query params. Sorting and
     * paging are NOT included (callers add those).
     *
     * @param array<string, mixed> $params
     *
     * @return array{wheres: list<string>, bindings: list<mixed>}
     */
    private function buildFilters(array $params, ?string $libraryId): array
    {
        $wheres = ['1=1'];
        $bindings = [];

        if ($libraryId !== null) {
            $wheres[] = 'library_id = ?';
            $bindings[] = $libraryId;
        }

        $search = isset($params['search']) && is_string($params['search']) ? $params['search'] : null;
        $genres = isset($params['genres']) && is_array($params['genres']) ? $params['genres'] : null;
        $yearFrom = isset($params['yearFrom']) && is_numeric($params['yearFrom']) ? (int) $params['yearFrom'] : null;
        $yearTo = isset($params['yearTo']) && is_numeric($params['yearTo']) ? (int) $params['yearTo'] : null;
        $ratings = isset($params['ratings']) && is_array($params['ratings']) ? $params['ratings'] : null;
        $actors = isset($params['actors']) && is_array($params['actors']) ? $params['actors'] : null;
        $match = isset($params['match']) && is_string($params['match']) ? $params['match'] : null;

        $parentId = isset($params['parentId']) && is_string($params['parentId']) && $params['parentId'] !== ''
            ? $params['parentId']
            : null;
        $topLevel = ($params['topLevel'] ?? false) === true;

        // Hierarchy scope. `parentId` (a series detail drill-down → its
        // seasons/episodes) wins over `topLevel`. `topLevel` restricts to
        // parent-less items (movies + series) so a series library shows shows,
        // not a flat dump of every episode — but it yields to an active search
        // so a title search still spans the whole library, episodes included.
        if ($parentId !== null) {
            $wheres[] = 'parent_id = ?';
            $bindings[] = $parentId;
        } elseif ($topLevel && ($search === null || $search === '')) {
            $wheres[] = 'parent_id IS NULL';
        }

        if ($search !== null && $search !== '') {
            $searchBindings = $this->buildSearchBindings($search);
            $wheres[] = $searchBindings['where'];
            $bindings = array_merge($bindings, $searchBindings['params']);
        }

        if ($genres !== null && count($genres) > 0) {
            $genreWheres = [];
            foreach ($genres as $genre) {
                if (is_string($genre) && $genre !== '') {
                    // Match the genre inside the metadata_json.genres array. Without the
                    // '$.genres' path JSON_CONTAINS tests the whole document and never matches.
                    $genreWheres[] = "JSON_CONTAINS(metadata_json, ?, '\$.genres') > 0";
                    $bindings[] = json_encode($genre);
                }
            }
            if (count($genreWheres) > 0) {
                $wheres[] = '(' . implode(' OR ', $genreWheres) . ')';
            }
        }

        if ($yearFrom !== null) {
            $wheres[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, "$.year")) AS SIGNED) >= ?';
            $bindings[] = $yearFrom;
        }

        if ($yearTo !== null) {
            $wheres[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, "$.year")) AS SIGNED) <= ?';
            $bindings[] = $yearTo;
        }

        if ($ratings !== null && count($ratings) > 0) {
            $ratingPlaceholders = implode(',', array_fill(0, count($ratings), '?'));
            $wheres[] = "JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.rating')) IN ({$ratingPlaceholders})";
            $bindings = array_merge($bindings, $ratings);
        }

        if ($actors !== null && count($actors) > 0) {
            $actorWheres = [];
            foreach ($actors as $actor) {
                if (is_string($actor) && $actor !== '') {
                    $escapedActor = addcslashes($actor, '%_');
                    // Match each actor array element independently (JSON_SEARCH over
                    // '$.actors[*]') so the LIKE can't span the serialized "," boundary
                    // between two names the way a flat JSON_EXTRACT+LIKE could. Cover
                    // BOTH stored shapes: the flat ["Name", …] list ('$.actors[*]')
                    // and the legacy TMDB [{name, …}, …] objects ('$.actors[*].name'),
                    // so the filter works before AND after a metadata re-match.
                    $actorWheres[] = "(JSON_SEARCH(metadata_json, 'one', ?, NULL, '\$.actors[*]') IS NOT NULL"
                        . " OR JSON_SEARCH(metadata_json, 'one', ?, NULL, '\$.actors[*].name') IS NOT NULL)";
                    $bindings[] = '%' . $escapedActor . '%';
                    $bindings[] = '%' . $escapedActor . '%';
                }
            }
            if (count($actorWheres) > 0) {
                $wheres[] = '(' . implode(' OR ', $actorWheres) . ')';
            }
        }

        // Match status. `metadata_refreshed_at` (migration 031) is stamped when
        // LibraryMetadataMatcher last enriched the item; NULL means it has never
        // been matched. Lets the UI surface "unmatched" items that still need a
        // metadata pass (or "matched" ones to review).
        if ($match === 'matched') {
            $wheres[] = 'metadata_refreshed_at IS NOT NULL';
        } elseif ($match === 'unmatched') {
            $wheres[] = 'metadata_refreshed_at IS NULL';
        }

        // array_values keeps `bindings` a positional list after the array_merge
        // calls above (they can widen the inferred key type).
        return ['wheres' => $wheres, 'bindings' => array_values($bindings)];
    }

    /**
     * Per-first-letter counts for the current query — drives the A-Z jump rail.
     * Honors the SAME filters as {@see self::query()} (via {@see self::buildFilters()}),
     * grouping by the uppercased first character of the article-stripped sort key
     * (so "The Plot" counts under P, mirroring the ORDER BY in {@see self::query()}).
     * Letters are returned unordered; the caller assigns cumulative offsets in the
     * list's sort order.
     *
     * @param array<string, mixed> $params Same media-query params as query().
     *
     * @return list<array{letter: string, count: int}>
     */
    public function letterCounts(array $params, ?string $libraryId = null): array
    {
        ['wheres' => $wheres, 'bindings' => $bindings] = $this->buildFilters($params, $libraryId);

        // Bucket by the first letter of the article-stripped sort key (so
        // "The Plot" counts under P), matching the ORDER BY in self::query()
        // so the cumulative letter offsets line up with the grid.
        $letterExpr = SortTitle::letterSqlExpression('name');
        $sql = "SELECT {$letterExpr} AS letter, COUNT(*) AS n FROM media_items WHERE "
            . implode(' AND ', $wheres) . ' GROUP BY letter';
        $rows = $this->db->query($sql, $bindings);

        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rawLetter = $row['letter'] ?? null;
                // An empty sort key (a name that is only an article like "The ",
                // or all whitespace) yields LEFT('',1)='' — bucket it under '#'
                // (where the empty key also sorts, first) instead of dropping it,
                // so the rail's cumulative offsets stay aligned with the grid.
                // The router (getLetterIndex) likewise folds every non-A-Z letter
                // into '#'. NOTE: an accented/non-Latin INITIAL letter (after
                // stripping, e.g. "Élan" or a Cyrillic title) is returned as-is
                // and folded to '#' by the router, yet sorts in its unicode_ci
                // position in the grid — a pre-existing rail/grid skew for
                // multilingual libraries, out of scope for the article rule.
                $letter = is_string($rawLetter) && $rawLetter !== '' ? $rawLetter : '#';
                $count = isset($row['n']) && is_numeric($row['n']) ? (int) $row['n'] : 0;
                $out[] = ['letter' => $letter, 'count' => $count];
            }
        }

        return $out;
    }

    /**
     * Normalizes the sort field to a safe column name.
     *
     * @param string $sort Raw sort field from query param
     * @return string Safe column name (always one of: name, year, rating, date_added, runtime)
     */
    private function normalizeSortField(string $sort): string
    {
        return match ($sort) {
            'year' => 'year_sort',
            'rating' => 'rating_sort',
            'date_added' => 'created_at',
            'runtime' => 'runtime_sort',
            default => 'name',
        };
    }

    /**
     * Normalizes the sort order to 'asc' or 'desc'.
     *
     * @param string $order Raw order param
     * @return string Normalized order
     */
    private function normalizeSortOrder(string $order): string
    {
        return strtolower($order) === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Normalizes the limit to an integer between 1 and 100.
     *
     * @param mixed $limit Raw limit value
     * @return int Normalized limit
     */
    private function normalizeLimit(mixed $limit): int
    {
        $l = is_numeric($limit) ? (int) $limit : 50;
        if ($l < 1) {
            return 1;
        }
        if ($l > 100) {
            return 100;
        }
        return $l;
    }

    /**
     * Normalizes the offset to a non-negative integer.
     *
     * @param mixed $offset Raw offset value
     * @return int Normalized offset
     */
    private function normalizeOffset(mixed $offset): int
    {
        $o = is_numeric($offset) ? (int) $offset : 0;
        return $o < 0 ? 0 : $o;
    }

    /**
     * Builds the WHERE clause and bindings for a search parameter.
     *
     * Uses MySQL FULLTEXT search with boolean mode, falling back to a LIKE-based
     * scan when FULLTEXT raises a syntax error (e.g. operator-only queries).
     *
     * @param string $search Search query
     * @return array{where: string, params: array<string>}
     */
    private function buildSearchBindings(string $search): array
    {
        $escapedSearch = addcslashes($search, '%_');

        return [
            'where' => '(MATCH(name) AGAINST(? IN BOOLEAN MODE) OR name LIKE ?)',
            'params' => [$search, '%' . $escapedSearch . '%'],
        ];
    }

    /**
     * Builds the ORDER BY clause from a normalized sort field and order.
     *
     * Uses CASE expressions to map string ratings to numeric sort order, matching
     * the existing RATING_ORDER mapping used by getByAllowedRatings().
     *
     * @param string $sort Normalized sort field
     * @param string $order Sort direction
     * @return string Safe ORDER BY clause
     */
    private function buildOrderClause(string $sort, string $order): string
    {
        $direction = $order === 'desc' ? 'DESC' : 'ASC';

        // Secondary alphabetical tiebreak ignores a leading article too (so two
        // items with the same year/rating/runtime still file "The Plot" under P).
        $titleTie = self::titleOrder($direction);

        if ($sort === 'year_sort') {
            return "CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.year')) AS SIGNED) {$direction}, {$titleTie}";
        }

        if ($sort === 'rating_sort') {
            $ratingCases = [];
            foreach (self::RATING_ORDER as $rating => $orderVal) {
                $ratingCases[] = "WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.rating')) = '{$rating}' THEN {$orderVal}";
            }
            $ratingOrderSql = 'CASE ' . implode(' ', $ratingCases) . ' ELSE 999 END';
            return "{$ratingOrderSql} {$direction}, {$titleTie}";
        }

        if ($sort === 'runtime_sort') {
            return "CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.runtime')) AS SIGNED) {$direction}, {$titleTie}";
        }

        // Default name sort files "The Plot" under P. `date_added` (→ created_at)
        // and any other safe column keep their natural ordering.
        if ($sort === 'name') {
            return $titleTie;
        }

        return "{$sort} {$direction}";
    }

    /**
     * `ORDER BY` fragment for an article-insensitive alphabetical listing: the
     * article-stripped sort key first (so "The Plot" files under P), then the raw
     * `name` as a stable tiebreaker for distinct titles that share a sort key.
     *
     * @param string $direction 'asc'/'desc' (any case); anything else → ASC.
     * @return string e.g. "TRIM(CASE … END) ASC, name ASC".
     */
    private static function titleOrder(string $direction = 'ASC'): string
    {
        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

        return SortTitle::sqlExpression('name') . " {$dir}, name {$dir}";
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
