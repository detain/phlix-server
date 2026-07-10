<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

use Workerman\MySQL\Connection;

/**
 * PathDeduper identifies and resolves duplicate media items that share the same
 * filesystem path within a library.
 *
 * Scoring algorithm for selecting the keeper in each duplicate group:
 *   score = watch_history_count * 1
 *         + (playback_state exists ? 5 : 0)
 *         + (user_item_data exists ? 5 : 0)
 *         + (media_markers count > 0 ? 3 : 0)
 *         + (rating_votes > 0 ? 2 : 0)
 *         + rating_score (0-10)
 *
 * Tiebreak: lowest id wins.
 */
class PathDeduper
{
    /** @var Connection Database connection */
    private Connection $db;

    /**
     * Tables that reference media_items.id via media_item_id column.
     * (user_item_data uses item_id column instead)
     *
     * @var list<string>
     */
    private const REFERENCING_TABLES = [
        'collection_items',
        'item_similar',
        'manual_match_overrides',
        'media_collection_members',
        'media_extras',
        'media_item_genres',
        'media_markers',
        'media_streams',
        'metadata_ratings',
        'metrics_connections',
        'music_albums',
        'music_artists',
        'music_tracks',
        'playback_state',
        'stats_library_changes',
        'stats_playback_events',
        'transcode_jobs',
        'user_recommendations',
        'watch_history',
    ];

    /**
     * @param Connection $db Database connection
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Begin a database transaction.
     */
    public function beginTrans(): void
    {
        $this->db->query('START TRANSACTION');
    }

    /**
     * Commit the current transaction.
     */
    public function commit(): void
    {
        $this->db->query('COMMIT');
    }

    /**
     * Rollback the current transaction.
     */
    public function rollback(): void
    {
        $this->db->query('ROLLBACK');
    }

    /**
     * Find all duplicate path groups within media items.
     *
     * Only considers items of types that have real filesystem paths:
     * episode, movie, audio, book. Containers (series, season) and other types
     * with synthetic/not-real paths are excluded.
     *
     * @return array<int, array{path: string, library_id: string, library_name: string, items: list<array{id: string, name: string, type: string, created_at: string}>}>
     */
    public function findDuplicateGroups(): array
    {
        // First find all paths that appear more than once (with their library and all item ids)
        $result = $this->db->query(
            "SELECT mi.path, mi.library_id, l.name AS library_name,
                    mi.id, mi.name, mi.type, mi.created_at
             FROM media_items mi
             JOIN libraries l ON l.id = mi.library_id
             WHERE mi.type IN ('episode', 'movie', 'audio', 'book')
               AND mi.path IS NOT NULL
               AND mi.path != ''
             ORDER BY mi.library_id, mi.path, mi.id ASC"
        );

        if (!is_array($result)) {
            return [];
        }

        // Index by path+library, collecting all items in each group
        $groups = [];
        foreach ($result as $row) {
            if (!is_array($row)) {
                continue;
            }
            $path = self::asString($row['path'] ?? '');
            $libraryId = self::asString($row['library_id'] ?? '');
            $key = $path . '::' . $libraryId;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'path' => $path,
                    'library_id' => $libraryId,
                    'library_name' => self::asString($row['library_name'] ?? ''),
                    'items' => [],
                ];
            }
            $groups[$key]['items'][] = [
                'id' => self::asString($row['id'] ?? ''),
                'name' => self::asString($row['name'] ?? ''),
                'type' => self::asString($row['type'] ?? ''),
                'created_at' => self::asString($row['created_at'] ?? ''),
            ];
        }

        // Filter to only groups with 2+ items (actual duplicates)
        $groups = array_values(array_filter($groups, static fn(array $g): bool => count($g['items']) > 1));

        return $groups;
    }

    /**
     * Score a media item to determine which one to keep in a duplicate group.
     *
     * Higher score = more "valuable" item to preserve.
     *
     * @param string $id Media item ID to score
     * @return int Score value
     */
    public function scoreItem(string $id): int
    {
        $score = 0;

        // Watch history count * 1
        $watchResult = $this->db->query(
            "SELECT COUNT(*) as cnt FROM watch_history WHERE media_item_id = ?",
            [$id]
        );
        $watchCount = $this->extractCount($watchResult);
        $score += $watchCount * 1;

        // Playback state exists * 5
        $pbResult = $this->db->query(
            "SELECT 1 FROM playback_state WHERE media_item_id = ? LIMIT 1",
            [$id]
        );
        if (is_array($pbResult) && count($pbResult) > 0) {
            $score += 5;
        }

        // User item data exists * 5 (uses item_id column)
        $uidResult = $this->db->query(
            "SELECT 1 FROM user_item_data WHERE item_id = ? LIMIT 1",
            [$id]
        );
        if (is_array($uidResult) && count($uidResult) > 0) {
            $score += 5;
        }

        // Media markers count > 0 * 3
        $markerResult = $this->db->query(
            "SELECT COUNT(*) as cnt FROM media_markers WHERE media_item_id = ?",
            [$id]
        );
        $markerCount = $this->extractCount($markerResult);
        if ($markerCount > 0) {
            $score += 3;
        }

        // Rating votes > 0 * 2
        $ratingResult = $this->db->query(
            "SELECT COALESCE(SUM(votes), 0) as votes FROM metadata_ratings WHERE media_item_id = ?",
            [$id]
        );
        if (self::asInt(self::cell($ratingResult, 'votes')) > 0) {
            $score += 2;
        }

        // Rating score (0-10)
        $scoreResult = $this->db->query(
            "SELECT rating FROM metadata_ratings WHERE media_item_id = ? ORDER BY updated_at DESC LIMIT 1",
            [$id]
        );
        $score += (int) self::asFloat(self::cell($scoreResult, 'rating'));

        return $score;
    }

    /**
     * Repoint every table that references a media item from the loser
     * (`$fromId`) to the keeper (`$toId`), collision-safe.
     *
     * Several of these tables carry a composite unique/primary key that includes
     * the media-item column — e.g. `media_item_genres(media_item_id, genre)`,
     * `collection_items(collection_id, media_item_id)`,
     * `user_item_data(user_id, item_id)`,
     * `metadata_ratings(media_item_id, source, rating_type)`. Because duplicates
     * are the *same file scanned twice*, the keeper and the loser almost always
     * hold rows with identical key tuples, so a plain
     * `UPDATE … SET media_item_id = keeper` would raise MySQL 1062 and abort the
     * whole merge. We therefore:
     *
     *   1. `UPDATE IGNORE` — moves every non-colliding row onto the keeper and
     *      silently leaves the loser's colliding rows in place (the keeper
     *      already has the equivalent row, so nothing is lost).
     *   2. `DELETE … WHERE <col> = loser` — removes whatever the UPDATE IGNORE
     *      could not move, so deleting the loser's `media_items` row never
     *      orphans a child row (tables without an `ON DELETE CASCADE` FK, such
     *      as `item_similar`, would otherwise keep dangling references).
     *
     * `item_similar` is handled on *both* of its columns (`media_item_id` and
     * `similar_item_id`); `user_item_data` uses `item_id` instead of
     * `media_item_id`. Any self-referential `item_similar` row created by the
     * repoint (a row now pointing at the keeper on both sides) is dropped.
     *
     * @param string $fromId Original media_item_id to repoint FROM (the loser)
     * @param string $toId   Target media_item_id to repoint TO (the keeper)
     * @return int Total rows moved onto the keeper across all tables
     */
    public function repointReferencingTables(string $fromId, string $toId): int
    {
        $totalUpdated = 0;

        foreach (self::REFERENCING_TABLES as $table) {
            $totalUpdated += $this->repointColumn($table, 'media_item_id', $fromId, $toId);
        }

        // item_similar references media_items twice; the loop above handled the
        // media_item_id side, so repoint the similar_item_id side here too.
        $totalUpdated += $this->repointColumn('item_similar', 'similar_item_id', $fromId, $toId);

        // Drop any now self-referential similar rows (keeper listed as similar to
        // itself), which the repoint above can create.
        $this->db->query('DELETE FROM item_similar WHERE media_item_id = similar_item_id');

        // user_item_data uses item_id instead of media_item_id.
        $totalUpdated += $this->repointColumn('user_item_data', 'item_id', $fromId, $toId);

        return $totalUpdated;
    }

    /**
     * Move rows of a single referencing column from the loser id to the keeper
     * id, dropping the loser's leftovers that collide with the keeper.
     *
     * @param string $table  Referencing table name (trusted constant, never user input).
     * @param string $column Foreign-key column referencing media_items.id.
     * @param string $fromId Loser media-item id.
     * @param string $toId   Keeper media-item id.
     * @return int Rows moved onto the keeper.
     */
    private function repointColumn(string $table, string $column, string $fromId, string $toId): int
    {
        $moved = $this->db->query(
            "UPDATE IGNORE {$table} SET {$column} = ? WHERE {$column} = ?",
            [$toId, $fromId]
        );

        // Remove any rows that could not be moved (collided with an existing
        // keeper row) so the loser's media_items delete leaves no orphans.
        $this->db->query(
            "DELETE FROM {$table} WHERE {$column} = ?",
            [$fromId]
        );

        return self::asInt($moved);
    }

    /**
     * Delete a media item after its references have been repointed.
     *
     * @param string $id Media item ID to delete
     * @return bool True if deleted, false if failed
     */
    public function deleteItem(string $id): bool
    {
        $result = $this->db->query("DELETE FROM media_items WHERE id = ?", [$id]);
        return $result !== false;
    }

    /**
     * Extract count from a COUNT(*) query result.
     *
     * @param mixed $result Query result
     * @return int Count value
     */
    private function extractCount(mixed $result): int
    {
        $cnt = self::cell($result, 'cnt');
        if ($cnt === null) {
            $cnt = self::cell($result, 'count');
        }
        return self::asInt($cnt);
    }

    /**
     * Read `$result[0][$col]` from a `query()` result, or null if absent.
     *
     * `Connection::query()` is typed `mixed`; this narrows the common
     * single-row/single-column shape without scattering guards at call sites.
     *
     * @param mixed $result Raw query() result.
     */
    private static function cell(mixed $result, string $col): mixed
    {
        if (is_array($result) && isset($result[0]) && is_array($result[0]) && array_key_exists($col, $result[0])) {
            return $result[0][$col];
        }
        return null;
    }

    /**
     * Coerce a mixed DB cell to a string (empty string for non-scalars).
     */
    private static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Coerce a mixed DB cell to an int (0 for non-numeric values).
     */
    private static function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Coerce a mixed DB cell to a float (0.0 for non-numeric values).
     */
    private static function asFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
