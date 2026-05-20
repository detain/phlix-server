<?php

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
     * @return array<int, array{
     *     user_id: string,
     *     username: string|null,
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
     * @return array<int, array{
     *     media_item_id: string,
     *     title: string|null,
     *     type: string|null,
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

            $title = is_array($mediaItem) ? $this->toString($mediaItem['name'] ?? null) : null;
            $type = is_array($mediaItem) ? $this->toString($mediaItem['type'] ?? null) : null;
            $playCount = is_int($row['play_count']) ? $row['play_count'] : 0;
            $totalDuration = is_int($row['total_duration']) ? $row['total_duration'] : 0;

            $result[] = [
                'media_item_id' => $this->toString($row['media_item_id']),
                'title' => $title,
                'type' => $type,
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
     * @return array{
     *     movie_bytes: int,
     *     series_bytes: int,
     *     music_bytes: int,
     *     photo_bytes: int,
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
                ss.item_count,
                ss.total_bytes,
                ss.transcode_cache_bytes,
                ss.recorded_at
             FROM stats_storage ss
             INNER JOIN (
                 SELECT
                     media_type,
                     MAX(recorded_at) AS max_recorded_at
                 FROM stats_storage
                 GROUP BY media_type
             ) latest ON ss.media_type = latest.media_type
                 AND ss.recorded_at = latest.max_recorded_at
              ORDER BY ss.total_bytes DESC"
        );

        /** @var array{movie_bytes: int, series_bytes: int, music_bytes: int, photo_bytes: int, transcode_cache_bytes: int, items: array<int, array{media_type: string, item_count: int, total_bytes: int, transcode_cache_bytes: int, formatted_total: string, formatted_cache: string}>, formatted_transcode_cache: string} $result */
        $result = [
            'movie_bytes' => 0,
            'series_bytes' => 0,
            'music_bytes' => 0,
            'photo_bytes' => 0,
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

            match ($mediaType) {
                'movie' => $result['movie_bytes'] = $totalBytes,
                'series' => $result['series_bytes'] = $totalBytes,
                'music' => $result['music_bytes'] = $totalBytes,
                'photo' => $result['photo_bytes'] = $totalBytes,
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
     *     username: string|null,
     *     details: array<string, mixed>,
     *     occurred_at: string
     * }> Playback events
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
            $durationSecs = isset($row['duration_seconds'])
                && is_numeric($row['duration_seconds']) ? (int)$row['duration_seconds'] : 0;
            $completed = isset($row['completed']) && $row['completed'];

            $result[] = [
                'id' => $this->toString($row['id']),
                'event_type' => 'playback_completed',
                'category' => 'playback',
                'user_id' => $this->toString($row['user_id']),
                'username' => $this->getUsernameById($this->toString($row['user_id'])),
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
        $unitIndex = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }
}
