<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Admin;

use DateTime;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Streaming\StreamManager;
use Phlix\Media\Streaming\StreamState;
use Phlix\Session\SessionManager;
use Phlix\Stats\StatsCollector;
use Workerman\MySQL\Connection;

/**
 * Dashboard service providing aggregated data for the admin dashboard.
 *
 * This service aggregates data from StatsCollector, SessionManager, and StreamManager
 * to provide:
 * - Currently active playback sessions (now playing)
 * - Top users leaderboard by watch time
 * - Top media items by play count
 * - Storage usage summary by media type
 * - Recent activity feed (playback, library, auth events)
 *
 * @author Phlix Team
 * @version 1.0.0
 * @description Admin dashboard data aggregation service
 *
 * @see StatsCollector For stats aggregation
 * @see SessionManager For session management
 * @see StreamManager For active stream tracking
 */
class DashboardService
{
    /** @var StatsCollector Stats aggregation service */
    private StatsCollector $stats;

    /** @var SessionManager Session management service */
    private SessionManager $sessions;

    /** @var StreamManager Active stream management */
    private StreamManager $streams;

    /** @var ItemRepository Media item data access */
    private ItemRepository $items;

    /** @var Connection Database connection */
    private Connection $db;

    /**
     * Creates a new DashboardService instance.
     *
     * @param StatsCollector $stats Stats aggregation service
     * @param SessionManager $sessions Session management service
     * @param StreamManager $streams Active stream management
     * @param ItemRepository $items Media item data access
     * @param Connection $db Database connection
     */
    public function __construct(
        StatsCollector $stats,
        SessionManager $sessions,
        StreamManager $streams,
        ItemRepository $items,
        Connection $db
    ) {
        $this->stats = $stats;
        $this->sessions = $sessions;
        $this->streams = $streams;
        $this->items = $items;
        $this->db = $db;
    }

    /**
     * Get all currently active playback sessions.
     *
     * Returns data for all active streams including user info, media info,
     * playback progress, and device information.
     *
     * @return array<int, array{
     *     stream_id: string,
     *     user_id: string,
     *     username: string|null,
     *     media_item_id: string,
     *     media_title: string|null,
     *     media_type: string|null,
     *     poster_url: string|null,
     *     position_ticks: int,
     *     duration_ticks: int,
     *     progress_percent: float,
     *     status: string,
     *     device_name: string|null,
     *     device_type: string|null
     * }> Active playback sessions
     */
    public function getNowPlaying(): array
    {
        $activeStreams = $this->streams->getActiveStreams();
        if ($activeStreams === []) {
            return [];
        }

        $result = [];
        foreach ($activeStreams as $stream) {
            if (!$stream->isActive()) {
                continue;
            }

            $mediaItem = $this->items->findById($stream->mediaItemId);
            $session = $this->sessions->getSession($stream->sessionId);

            $mediaTitle = is_array($mediaItem) ? $this->toString($mediaItem['name'] ?? null) : null;
            $mediaType = is_array($mediaItem) ? $this->toString($mediaItem['type'] ?? null) : null;
            $deviceName = is_array($session) ? $this->toString($session['device_name'] ?? null) : null;
            $deviceType = is_array($session) ? $this->toString($session['device_type'] ?? null) : null;

            $result[] = [
                'stream_id' => $stream->id,
                'user_id' => $stream->userId,
                'username' => $this->getUsernameById($stream->userId),
                'media_item_id' => $stream->mediaItemId,
                'media_title' => $mediaTitle,
                'media_type' => $mediaType,
                'poster_url' => $this->getPosterUrl($mediaItem),
                'position_ticks' => $stream->positionTicks,
                'duration_ticks' => $stream->durationTicks,
                'progress_percent' => $stream->getProgressPercent(),
                'status' => $stream->status,
                'device_name' => $deviceName,
                'device_type' => $deviceType,
            ];
        }

        return $result;
    }

    /**
     * Get top users leaderboard by watch time.
     *
     * @param int $limit Maximum number of users to return (default: 10)
     * @param int|null $days Number of days to look back (default: 30, null for all time)
     *
     * Rows for since-deleted accounts are excluded, so every returned entry
     * carries a resolved username — no blank rows.
     *
     * @return array<int, array{
     *     user_id: string,
     *     username: string,
     *     total_watch_time: int,
     *     play_count: int,
     *     avatar_url: string|null
     * }> Top users sorted by total watch time
     */
    public function getTopUsers(int $limit = 10, ?int $days = 30): array
    {
        $since = $days !== null ? new DateTime("-{$days} days") : null;
        $statsData = $this->stats->getTopUsers($limit, $since);

        $result = [];
        foreach ($statsData as $row) {
            $username = $this->getUsernameById($row['user_id']);

            // S14 orphan guard (defense-in-depth). StatsCollector::getTopUsers()
            // already INNER JOINs users so deleted-account events are dropped at
            // the query level; a null username here means the account was removed
            // in the TOCTOU window, so skip it rather than surface a blank
            // leaderboard row.
            if ($username === null) {
                continue;
            }

            $avatarUrl = $this->getUserAvatarUrl($row['user_id']);

            $result[] = [
                'user_id' => $row['user_id'],
                'username' => $username,
                'total_watch_time' => $row['total_watch_time'],
                'play_count' => $row['play_count'],
                'avatar_url' => $avatarUrl,
            ];
        }

        return $result;
    }

    /**
     * Get top media items by play count.
     *
     * @param int $limit Maximum number of items to return (default: 10)
     * @param int|null $days Number of days to look back (default: 30, null for all time)
     *
     * Orphaned rows (plays whose media item was deleted) are excluded, so the
     * returned entries always carry a resolved title/type — no blank rows.
     *
     * @return array<int, array{
     *     media_item_id: string,
     *     title: string,
     *     type: string,
     *     poster_url: string|null,
     *     play_count: int,
     *     total_duration: int
     * }> Top media items sorted by play count
     */
    public function getTopMedia(int $limit = 10, ?int $days = 30): array
    {
        $since = $days !== null ? new DateTime("-{$days} days") : null;
        $statsData = $this->stats->getTopMedia($limit, $since);

        $result = [];
        foreach ($statsData as $row) {
            $mediaItem = $this->items->findById($row['media_item_id']);

            // S14 orphan guard (defense-in-depth). StatsCollector::getTopMedia()
            // already INNER JOINs media_items so deleted-item plays are dropped
            // at the query level; this also covers the TOCTOU race where an item
            // is removed between that aggregate query and this hydrate. Either
            // way a blank (null-title / no-poster) row can never reach the
            // dashboard — orphaned rows are hidden, not shown as a placeholder.
            if (!is_array($mediaItem)) {
                continue;
            }

            $playCount = is_int($row['play_count']) ? $row['play_count'] : 0;
            $totalDuration = is_int($row['total_duration']) ? $row['total_duration'] : 0;

            $result[] = [
                'media_item_id' => $this->toString($row['media_item_id']),
                'title' => $this->toString($mediaItem['name'] ?? null),
                'type' => $this->toString($mediaItem['type'] ?? null),
                'poster_url' => $this->getPosterUrl($mediaItem),
                'play_count' => $playCount,
                'total_duration' => $totalDuration,
            ];
        }

        return $result;
    }

    /**
     * Get storage usage summary grouped by media type.
     *
     * Returns the most recent storage snapshot for each media type,
     * including item count, total bytes, and transcode cache usage.
     *
     * ## Why the query AGGREGATES (S102 review r1, MED-2)
     *
     * The latest-snapshot join can legitimately return SEVERAL rows for the same
     * `media_type` — one per `library_id` when a snapshot is recorded per library,
     * and historically one per raw `media_items.type` folded into the same coarse
     * bucket. The `match` below used to ASSIGN (`=`) while the query ordered by
     * `total_bytes DESC`, so the SMALLEST colliding row won and the rest vanished
     * from the headline totals: measured on real MySQL, 13 folded types worth
     * 91,000 bytes produced five totals summing to 31,000. Both halves are now
     * additive — `SUM(…) GROUP BY media_type` in SQL, and `+=` in PHP — so a
     * bucket's bytes cannot be dropped by a duplicate row no matter which side
     * produced it. `ORDER BY SUM(ss.total_bytes)` is spelled out rather than using
     * the select alias, because an alias that shadows a real column name is
     * exactly the kind of `GROUP BY`/`ORDER BY` ambiguity `ONLY_FULL_GROUP_BY`
     * has bitten this repo with before.
     *
     * ## The two halves are pinned SEPARATELY, on purpose (S102 review r2, MED-1)
     *
     * The halves hide each other: the SQL `GROUP BY` collapses the result set to one
     * row per `media_type`, so the `+=` never sees a second row — and with `+=` in
     * place the `SUM` is equally invisible. Every test therefore used to pass with
     * EITHER half reverted, and only the simultaneous revert was caught. That is a
     * live trap, because the deferred per-library snapshot writer will start
     * emitting one row per `library_id` per bucket, and "the SQL already groups" is
     * a plausible reason to simplify these arms back to `=` — three 1 TB libraries
     * would then render as 1 TB with a green suite. So each half now has its own
     * regression:
     *
     * - the PHP `+=` arms →
     *   {@see \Phlix\Tests\Unit\Admin\DashboardServiceTest::test_get_storage_summary_sums_two_rows_for_one_bucket}
     *   (mocked, no database: two `movie` rows, 1,000 + 2,000, must be 3,000)
     * - the SQL `SUM`/`GROUP BY` →
     *   {@see \Phlix\Tests\Integration\Stats\PlaybackEventMediaTypeEnumTest::testTheQueryItselfCollapsesDuplicateRowsIntoOneItemPerBucket}
     *   (real MySQL: two rows per bucket in one second must be FIVE `items` rows)
     *
     * Do not "simplify" either half without watching one of those go red.
     *
     * @return array{
     *     movie_bytes: int,
     *     series_bytes: int,
     *     music_bytes: int,
     *     photo_bytes: int,
     *     book_bytes: int,
     *     transcode_cache_bytes: int,
     *     items: array<int, array{
     *         media_type: string,
     *         item_count: int,
     *         total_bytes: int,
     *         transcode_cache_bytes: int,
     *         formatted_total: string,
     *         formatted_cache: string
     *     }>,
     *     formatted_transcode_cache: string
     * } Storage summary
     */
    public function getStorageSummary(): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT
                ss.media_type,
                SUM(ss.item_count) AS item_count,
                SUM(ss.total_bytes) AS total_bytes,
                SUM(ss.transcode_cache_bytes) AS transcode_cache_bytes,
                MAX(ss.recorded_at) AS recorded_at
             FROM stats_storage ss
             INNER JOIN (
                 SELECT
                     media_type,
                     MAX(recorded_at) AS max_recorded_at
                 FROM stats_storage
                 GROUP BY media_type
             ) latest ON ss.media_type = latest.media_type
                 AND ss.recorded_at = latest.max_recorded_at
             GROUP BY ss.media_type
             ORDER BY SUM(ss.total_bytes) DESC"
        );

        /** @var array{movie_bytes: int, series_bytes: int, music_bytes: int, photo_bytes: int, book_bytes: int, transcode_cache_bytes: int, items: array<int, array{media_type: string, item_count: int, total_bytes: int, transcode_cache_bytes: int, formatted_total: string, formatted_cache: string}>, formatted_transcode_cache: string} $result */
        $result = [
            'movie_bytes' => 0,
            'series_bytes' => 0,
            'music_bytes' => 0,
            'photo_bytes' => 0,
            'book_bytes' => 0,
            'transcode_cache_bytes' => 0,
            'items' => [],
        ];

        foreach ($rows as $row) {
            $totalBytes = isset($row['total_bytes'])
                && is_numeric($row['total_bytes']) ? (int)$row['total_bytes'] : 0;
            $cacheBytes = isset($row['transcode_cache_bytes'])
                && is_numeric($row['transcode_cache_bytes']) ? (int)$row['transcode_cache_bytes'] : 0;
            $itemCount = isset($row['item_count'])
                && is_numeric($row['item_count']) ? (int)$row['item_count'] : 0;
            $mediaType = $this->toString($row['media_type']);

            // ACCUMULATE, never assign: see the method docblock. A second row for
            // the same bucket must add to the total, not replace it.
            match ($mediaType) {
                'movie' => $result['movie_bytes'] += $totalBytes,
                'series' => $result['series_bytes'] += $totalBytes,
                'music' => $result['music_bytes'] += $totalBytes,
                'photo' => $result['photo_bytes'] += $totalBytes,
                'book' => $result['book_bytes'] += $totalBytes,
                default => null,
            };

            $result['transcode_cache_bytes'] += $cacheBytes;

            /** @var array{media_type: string, item_count: int, total_bytes: int, transcode_cache_bytes: int, formatted_total: string, formatted_cache: string} $item */
            $item = [
                'media_type' => $mediaType,
                'item_count' => $itemCount,
                'total_bytes' => $totalBytes,
                'transcode_cache_bytes' => $cacheBytes,
                'formatted_total' => $this->formatBytes($totalBytes),
                'formatted_cache' => $this->formatBytes($cacheBytes),
            ];
            $result['items'][] = $item;
        }

        $result['formatted_transcode_cache'] = $this->formatBytes($result['transcode_cache_bytes']);

        return $result;
    }

    /**
     * Get recent activity feed combining playback, library, and auth events.
     *
     * Returns the most recent events from all stats tables, sorted by
     * timestamp in descending order (most recent first).
     *
     * @param int $limit Maximum number of events to return (default: 20)
     *
     * @return array<int, array{
     *     id: string,
     *     event_type: string,
     *     category: string,
     *     user_id: string|null,
     *     username: string|null,
     *     details: array<string, mixed>,
     *     occurred_at: string
     * }> Recent activity events
     */
    public function getRecentActivity(int $limit = 20): array
    {
        $playbackEvents = $this->getRecentPlaybackEvents($limit);
        $libraryEvents = $this->getRecentLibraryEvents($limit);
        $authEvents = $this->getRecentAuthEvents($limit);

        $allEvents = array_merge($playbackEvents, $libraryEvents, $authEvents);

        usort($allEvents, function (array $a, array $b): int {
            return strcmp($b['occurred_at'], $a['occurred_at']);
        });

        return array_slice($allEvents, 0, $limit);
    }

    /**
     * Get recent playback events.
     *
     * @param int $limit Maximum number of events
     *
     * @return array<int, array{
     *     id: string,
     *     event_type: string,
     *     category: string,
     *     user_id: string,
     *     username: string,
     *     details: array<string, mixed>,
     *     occurred_at: string
     * }> Playback events — S220: rows whose user or media item no longer resolves
     * are hidden (never a null username / null media_title), mirroring the S14
     * orphan guard on the Top Users / Top Media cards.
     */
    private function getRecentPlaybackEvents(int $limit): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT
                spe.id,
                'playback_completed' AS event_type,
                'playback' AS category,
                spe.user_id,
                spe.media_item_id,
                spe.duration_seconds,
                spe.completed,
                spe.started_at,
                spe.ended_at
             FROM stats_playback_events spe
             WHERE spe.ended_at IS NOT NULL
             ORDER BY spe.ended_at DESC
             LIMIT ?",
            [$limit]
        );

        $result = [];
        foreach ($rows as $row) {
            $mediaItem = $this->items->findById($this->toString($row['media_item_id']));
            $mediaTitle = is_array($mediaItem) ? $this->toString($mediaItem['name'] ?? null) : null;
            $username = $this->getUsernameById($this->toString($row['user_id']));

            // S220 — the S14 HIDE decision, finished on this missed surface.
            // A playback event whose media item or user account has since been
            // deleted must not surface as a blank-identity activity row: skip it
            // rather than emit a null username or a null media_title, exactly as
            // getTopUsers()/getTopMedia() skip their orphaned rows (S14).
            if ($username === null || $mediaTitle === null) {
                continue;
            }

            $durationSecs = isset($row['duration_seconds'])
                && is_numeric($row['duration_seconds']) ? (int)$row['duration_seconds'] : 0;
            $completed = isset($row['completed']) && $row['completed'];

            $result[] = [
                'id' => $this->toString($row['id']),
                'event_type' => 'playback_completed',
                'category' => 'playback',
                'user_id' => $this->toString($row['user_id']),
                'username' => $username,
                'details' => [
                    'media_title' => $mediaTitle,
                    'duration_seconds' => $durationSecs,
                    'completed' => $completed,
                ],
                'occurred_at' => $this->toString($row['ended_at'] ?? ($row['started_at'] ?? '')),
            ];
        }

        return $result;
    }

    /**
     * Get recent library change events.
     *
     * @param int $limit Maximum number of events
     *
     * @return array<int, array{
     *     id: string,
     *     event_type: string,
     *     category: string,
     *     user_id: string,
     *     username: string|null,
     *     details: array<string, mixed>,
     *     occurred_at: string
     * }> Library events
     */
    private function getRecentLibraryEvents(int $limit): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT
                id,
                change_type,
                media_item_id,
                library_id,
                user_id,
                changed_at,
                details_json
             FROM stats_library_changes
             ORDER BY changed_at DESC
             LIMIT ?",
            [$limit]
        );

        $result = [];
        foreach ($rows as $row) {
            $details = [];
            if (!empty($row['details_json']) && is_string($row['details_json'])) {
                $decoded = json_decode($row['details_json'], true);
                if (is_array($decoded)) {
                    $details = $decoded;
                }
            }

            $result[] = [
                'id' => $this->toString($row['id']),
                'event_type' => $this->toString($row['change_type']),
                'category' => 'library',
                'user_id' => $this->toString($row['user_id']),
                'username' => $this->getUsernameById($this->toString($row['user_id'])),
                'details' => $details,
                'occurred_at' => $this->toString($row['changed_at']),
            ];
        }

        return $result;
    }

    /**
     * Get recent user authentication events.
     *
     * @param int $limit Maximum number of events
     *
     * @return array<int, array{
     *     id: string,
     *     event_type: string,
     *     category: string,
     *     user_id: string,
     *     username: string|null,
     *     details: array<string, mixed>,
     *     occurred_at: string
     * }> Auth events
     */
    private function getRecentAuthEvents(int $limit): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT
                id,
                user_id,
                activity_type,
                occurred_at,
                ip_address,
                details_json
             FROM stats_user_activity
             WHERE activity_type IN ('login', 'logout')
             ORDER BY occurred_at DESC
             LIMIT ?",
            [$limit]
        );

        $result = [];
        foreach ($rows as $row) {
            $details = [];
            if (!empty($row['details_json']) && is_string($row['details_json'])) {
                $decoded = json_decode($row['details_json'], true);
                if (is_array($decoded)) {
                    $details = $decoded;
                }
            }
            if (!empty($row['ip_address'])) {
                $details['ip_address'] = $this->toString($row['ip_address']);
            }

            $result[] = [
                'id' => $this->toString($row['id']),
                'event_type' => $this->toString($row['activity_type']),
                'category' => 'auth',
                'user_id' => $this->toString($row['user_id']),
                'username' => $this->getUsernameById($this->toString($row['user_id'])),
                'details' => $details,
                'occurred_at' => $this->toString($row['occurred_at']),
            ];
        }

        return $result;
    }

    /**
     * Get username by user ID.
     *
     * @param string $userId User UUID
     * @return string|null Username or null if not found
     */
    private function getUsernameById(string $userId): ?string
    {
        if ($userId === '') {
            return null;
        }

        /** @var array<array<string, mixed>> $result */
        $result = $this->db->query(
            "SELECT username FROM users WHERE id = ?",
            [$userId]
        );

        if (count($result) === 0) {
            return null;
        }

        $row = $result[0];
        if (!is_array($row)) {
            return null;
        }

        return $this->toString($row['username'] ?? null);
    }

    /**
     * Get user avatar URL.
     *
     * @param string $userId User UUID
     * @return string|null Avatar URL or null
     */
    private function getUserAvatarUrl(string $userId): ?string
    {
        if ($userId === '') {
            return null;
        }

        /** @var array<array<string, mixed>> $result */
        $result = $this->db->query(
            "SELECT avatar_url FROM users WHERE id = ?",
            [$userId]
        );

        if (count($result) === 0) {
            return null;
        }

        $row = $result[0];
        if (!is_array($row)) {
            return null;
        }

        return $this->toString($row['avatar_url'] ?? null);
    }

    /**
     * Get poster URL from media item.
     *
     * @param array<string, mixed>|null $mediaItem Media item array
     * @return string|null Poster URL or null
     */
    private function getPosterUrl(?array $mediaItem): ?string
    {
        if ($mediaItem === null) {
            return null;
        }

        $metadata = $mediaItem['metadata'] ?? null;
        if (!is_array($metadata)) {
            return null;
        }

        return $this->toString($metadata['poster_url'] ?? null);
    }

    /**
     * Convert a mixed value to string.
     *
     * @param mixed $value The value to convert
     * @return string The string value
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
     * Format bytes into human-readable string.
     *
     * @param int $bytes Number of bytes
     * @return string Formatted string (e.g., "1.5 GB")
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $bytes;

        // S146: consume the unit list instead of indexing it. Behaviour is
        // identical to the previous `while ($size >= 1024 && $unitIndex <
        // count($units) - 1)` + `$units[$unitIndex]` (including the 1024 TB
        // saturation at the top of the scale), but the label is now a value that
        // provably exists rather than an offset the analysers have to bound:
        // Psalm widened the index to int<0, max> and reported InvalidArrayOffset,
        // while the `?? 'TB'` fallback that silences Psalm makes PHPStan report
        // nullCoalesce.offset. The two gates contradict each other on the indexed
        // form; neither has anything to say about this one.
        $unit = array_shift($units);
        while ($size >= 1024 && $units !== []) {
            $size /= 1024;
            $unit = array_shift($units);
        }

        return round($size, 2) . ' ' . $unit;
    }
}
