<?php

declare(strict_types=1);

namespace Phlix\Admin;

use Workerman\MySQL\Connection;

/**
 * Watch-history service backing the admin cross-user watch-history view.
 *
 * Returns recent watch-history rows across ALL users (this is an admin-only
 * concern — the route is gated by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}),
 * joined with user, profile, and media metadata so the admin UI can render
 * "who watched what, when" with a clickable media link.
 *
 * @author Phlix Team
 * @version 1.0.0
 * @description Admin cross-user watch-history data service
 */
class WatchHistoryService
{
    /** @var Connection Database connection */
    private Connection $db;

    /**
     * Creates a new WatchHistoryService instance.
     *
     * @param Connection $db Database connection
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Get recent watch-history rows across all users.
     *
     * Rows are ordered most-recently-watched first. Every returned column is a
     * plain scalar (no NULLs): NULL string columns collapse to `''` and
     * `progress_percent` is coerced to a float, keeping the shape stable for
     * both the frontend and phpstan level 9.
     *
     * @param int         $limit     Max rows (already clamped by the controller).
     * @param string|null $userId    Optional users.id filter.
     * @param string|null $libraryId Optional media_items.library_id filter.
     *
     * @return array<int, array{
     *     id: string,
     *     media_item_id: string,
     *     media_name: string,
     *     media_type: string,
     *     library_id: string,
     *     user_id: string,
     *     username: string,
     *     display_name: string,
     *     profile_name: string,
     *     last_watched_at: string,
     *     completed_at: string,
     *     progress_percent: float,
     *     playback_status: string
     * }> Recent watch-history rows
     */
    public function getRecentWatchHistory(int $limit, ?string $userId = null, ?string $libraryId = null): array
    {
        // NOTE: the SQL string MUST START WITH the literal `SELECT`. Workerman's
        // `query()` returns NULL when the statement begins with `WITH`, so no
        // CTEs are used here.
        $sql = 'SELECT
    wh.id                AS id,
    wh.media_item_id     AS media_item_id,
    mi.name              AS media_name,
    mi.type              AS media_type,
    mi.library_id        AS library_id,
    u.id                 AS user_id,
    u.username           AS username,
    u.display_name       AS display_name,
    up.name              AS profile_name,
    wh.last_watched_at   AS last_watched_at,
    wh.completed_at      AS completed_at,
    wh.progress_percent  AS progress_percent,
    wh.playback_status   AS playback_status
FROM watch_history wh
JOIN user_profiles up ON wh.profile_id = up.id
JOIN users u ON up.user_id = u.id
JOIN media_items mi ON wh.media_item_id = mi.id';

        $conditions = [];
        /** @var array<int, string|int> $params */
        $params = [];

        if ($userId !== null) {
            $conditions[] = 'u.id = ?';
            $params[] = $userId;
        }

        if ($libraryId !== null) {
            $conditions[] = 'mi.library_id = ?';
            $params[] = $libraryId;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY wh.last_watched_at DESC LIMIT ?';
        $params[] = $limit;

        $rows = $this->db->query($sql, $params);
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => $this->toString($row['id'] ?? null),
                'media_item_id' => $this->toString($row['media_item_id'] ?? null),
                'media_name' => $this->toString($row['media_name'] ?? null),
                'media_type' => $this->toString($row['media_type'] ?? null),
                'library_id' => $this->toString($row['library_id'] ?? null),
                'user_id' => $this->toString($row['user_id'] ?? null),
                'username' => $this->toString($row['username'] ?? null),
                'display_name' => $this->toString($row['display_name'] ?? null),
                'profile_name' => $this->toString($row['profile_name'] ?? null),
                'last_watched_at' => $this->toString($row['last_watched_at'] ?? null),
                'completed_at' => $this->toString($row['completed_at'] ?? null),
                'progress_percent' => $this->toFloat($row['progress_percent'] ?? null),
                'playback_status' => $this->toString($row['playback_status'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * Convert a mixed value to string.
     *
     * @param mixed $value The value to convert
     * @return string The string value ('' for NULL/non-scalar)
     */
    private function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        return '';
    }

    /**
     * Convert a mixed value to a float.
     *
     * @param mixed $value The value to convert
     * @return float The float value (0.0 for non-numeric)
     */
    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
