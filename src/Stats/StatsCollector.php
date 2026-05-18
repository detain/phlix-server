<?php

declare(strict_types=1);

namespace Phlex\Stats;

use DateTimeInterface;
use Workerman\MySQL\Connection;

/**
 * Stats collector for aggregating playback, library, user activity, and storage data.
 *
 * This service records events into the stats_* tables and provides aggregation
 * queries for the admin dashboard (top users, top media, playback time series).
 *
 * @author Phlex Team
 * @version 1.0.0
 * @description Collects and aggregates statistics for the Phlex Media Server.
 */
class StatsCollector
{
    /** @var Connection Database connection for MySQL queries */
    private Connection $db;

    /**
     * Create a new StatsCollector instance.
     *
     * @param Connection $db Workerman MySQL connection instance
     *
     * @example
     * ```php
     * $collector = new StatsCollector($db);
     * ```
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Generate a UUID v4 string.
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

    /**
     * Convert a mixed value to string.
     *
     * @param mixed $value The value to convert
     *
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
     * Convert a mixed value to int.
     *
     * @param mixed $value The value to convert
     *
     * @return int The integer value
     */
    private function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        return 0;
    }

    /**
     * Record a playback start event.
     *
     * @param string $userId User UUID starting playback
     * @param string $mediaItemId Media item UUID being played
     * @param string $mediaType Media type (movie, series, music, photo)
     * @param string|null $deviceId Optional device identifier
     *
     * @return string Event ID for later completion via recordPlaybackEnd
     *
     * @example
     * ```php
     * $eventId = $collector->recordPlaybackStart('user-123', 'media-456', 'movie', 'device-789');
     * ```
     */
    public function recordPlaybackStart(string $userId, string $mediaItemId, string $mediaType, ?string $deviceId = null): string
    {
        $eventId = $this->generateUuid();
        $clientIp = null;

        $this->db->query(
            "INSERT INTO stats_playback_events (id, user_id, media_item_id, media_type, started_at, device_id, client_ip)
             VALUES (?, ?, ?, ?, NOW(), ?, ?)",
            [$eventId, $userId, $mediaItemId, $mediaType, $deviceId, $clientIp]
        );

        return $eventId;
    }

    /**
     * Record a playback end event.
     *
     * @param string $eventId Event ID from recordPlaybackStart
     * @param int $durationSeconds Duration of playback in seconds
     * @param bool $completed Whether playback was completed
     *
     * @return void
     *
     * @example
     * ```php
     * $collector->recordPlaybackEnd($eventId, 3600, true);
     * ```
     */
    public function recordPlaybackEnd(string $eventId, int $durationSeconds, bool $completed): void
    {
        $this->db->query(
            "UPDATE stats_playback_events
             SET ended_at = NOW(), duration_seconds = ?, completed = ?
             WHERE id = ?",
            [$durationSeconds, $completed, $eventId]
        );
    }

    /**
     * Record a library change event.
     *
     * @param string $changeType Change type (item_added, item_removed, metadata_updated)
     * @param string|null $mediaItemId Media item UUID if applicable
     * @param string|null $libraryId Library UUID if applicable
     * @param string|null $userId User UUID who triggered the change
     * @param array<string, mixed> $details Additional details as key-value pairs
     *
     * @return void
     *
     * @example
     * ```php
     * $collector->recordLibraryChange('item_added', 'media-456', 'lib-123', 'user-789', ['path' => '/movies/test.mkv']);
     * ```
     */
    public function recordLibraryChange(string $changeType, ?string $mediaItemId = null, ?string $libraryId = null, ?string $userId = null, array $details = []): void
    {
        $id = $this->generateUuid();
        $detailsJson = $details !== [] ? json_encode($details) : null;

        $this->db->query(
            "INSERT INTO stats_library_changes (id, change_type, media_item_id, library_id, user_id, changed_at, details_json)
             VALUES (?, ?, ?, ?, ?, NOW(), ?)",
            [$id, $changeType, $mediaItemId, $libraryId, $userId, $detailsJson]
        );
    }

    /**
     * Record a user activity event.
     *
     * @param string $userId User UUID performing the activity
     * @param string $activityType Activity type (login, logout, search, profile_change)
     * @param string|null $ipAddress IP address of the user
     * @param array<string, mixed> $details Additional details as key-value pairs
     *
     * @return void
     *
     * @example
     * ```php
     * $collector->recordUserActivity('user-123', 'login', '192.168.1.1', ['device' => 'Chrome']);
     * ```
     */
    public function recordUserActivity(string $userId, string $activityType, ?string $ipAddress = null, array $details = []): void
    {
        $id = $this->generateUuid();
        $userAgent = null;
        $detailsJson = $details !== [] ? json_encode($details) : null;

        $this->db->query(
            "INSERT INTO stats_user_activity (id, user_id, activity_type, occurred_at, ip_address, user_agent, details_json)
             VALUES (?, ?, ?, NOW(), ?, ?, ?)",
            [$id, $userId, $activityType, $ipAddress, $userAgent, $detailsJson]
        );
    }

    /**
     * Record a storage snapshot.
     *
     * @param string $mediaType Media type (movie, series, music, photo)
     * @param int $itemCount Number of items of this type
     * @param int $totalBytes Total bytes used by this media type
     * @param int $transcodeCacheBytes Transcode cache bytes used
     * @param string|null $libraryId Library UUID if applicable
     *
     * @return void
     *
     * @example
     * ```php
     * $collector->recordStorageSnapshot('movie', 150, 50000000000, 2000000000, 'lib-123');
     * ```
     */
    public function recordStorageSnapshot(string $mediaType, int $itemCount, int $totalBytes, int $transcodeCacheBytes = 0, ?string $libraryId = null): void
    {
        $id = $this->generateUuid();

        $this->db->query(
            "INSERT INTO stats_storage (id, recorded_at, library_id, media_type, item_count, total_bytes, transcode_cache_bytes)
             VALUES (?, NOW(), ?, ?, ?, ?, ?)",
            [$id, $libraryId, $mediaType, $itemCount, $totalBytes, $transcodeCacheBytes]
        );
    }

    /**
     * Get playback stats for a date range.
     *
     * Returns time-series data grouped by day with playback counts and total duration.
     *
     * @param DateTimeInterface $from Start date
     * @param DateTimeInterface $to End date
     *
     * @return array<int, array{date: string, play_count: int, total_duration: int, completed_count: int}>
     *
     * @example
     * ```php
     * $stats = $collector->getPlaybackStats(new \DateTime('-7 days'), new \DateTime());
     * ```
     */
    public function getPlaybackStats(DateTimeInterface $from, DateTimeInterface $to): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT
                 DATE(started_at) AS date,
                 COUNT(*) AS play_count,
                 COALESCE(SUM(duration_seconds), 0) AS total_duration,
                 SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS completed_count
             FROM stats_playback_events
             WHERE started_at >= ? AND started_at <= ?
             GROUP BY DATE(started_at)
             ORDER BY date ASC",
            [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]
        );

        return array_map(function (array $row): array {
            return [
                'date' => $this->toString($row['date']),
                'play_count' => $this->toInt($row['play_count']),
                'total_duration' => $this->toInt($row['total_duration']),
                'completed_count' => $this->toInt($row['completed_count']),
            ];
        }, $rows);
    }

    /**
     * Get top users by watch time.
     *
     * Returns users sorted by total watch duration within the given time window.
     *
     * @param int $limit Maximum number of users to return (default: 10)
     * @param DateTimeInterface|null $since Only count activity since this date (default: all time)
     *
     * @return array<int, array{user_id: string, total_watch_time: int, play_count: int}>
     *
     * @example
     * ```php
     * $topUsers = $collector->getTopUsers(10, new \DateTime('-30 days'));
     * ```
     */
    public function getTopUsers(int $limit = 10, ?DateTimeInterface $since = null): array
    {
        $sql = "SELECT
                    user_id,
                    COALESCE(SUM(duration_seconds), 0) AS total_watch_time,
                    COUNT(*) AS play_count
                FROM stats_playback_events";

        $params = [];

        if ($since !== null) {
            $sql .= " WHERE started_at >= ?";
            $params[] = $since->format('Y-m-d H:i:s');
        }

        $sql .= " GROUP BY user_id ORDER BY total_watch_time DESC LIMIT ?";
        $params[] = $limit;

        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query($sql, $params);

        return array_map(function (array $row): array {
            return [
                'user_id' => $this->toString($row['user_id']),
                'total_watch_time' => $this->toInt($row['total_watch_time']),
                'play_count' => $this->toInt($row['play_count']),
            ];
        }, $rows);
    }

    /**
     * Get top media items by play count.
     *
     * Returns media items sorted by play count within the given time window.
     *
     * @param int $limit Maximum number of items to return (default: 10)
     * @param DateTimeInterface|null $since Only count plays since this date (default: all time)
     *
     * @return array<int, array{media_item_id: string, play_count: int, total_duration: int}>
     *
     * @example
     * ```php
     * $topMedia = $collector->getTopMedia(10, new \DateTime('-30 days'));
     * ```
     */
    public function getTopMedia(int $limit = 10, ?DateTimeInterface $since = null): array
    {
        $sql = "SELECT
                    media_item_id,
                    COUNT(*) AS play_count,
                    COALESCE(SUM(duration_seconds), 0) AS total_duration
                FROM stats_playback_events";

        $params = [];

        if ($since !== null) {
            $sql .= " WHERE started_at >= ?";
            $params[] = $since->format('Y-m-d H:i:s');
        }

        $sql .= " GROUP BY media_item_id ORDER BY play_count DESC LIMIT ?";
        $params[] = $limit;

        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query($sql, $params);

        return array_map(function (array $row): array {
            return [
                'media_item_id' => $this->toString($row['media_item_id']),
                'play_count' => $this->toInt($row['play_count']),
                'total_duration' => $this->toInt($row['total_duration']),
            ];
        }, $rows);
    }
}
