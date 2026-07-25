<?php

/**
 * Phlix media server component: Stats.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Stats;

use DateTimeInterface;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Uuid;
use Phlix\Media\MediaItemType;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Stats collector for aggregating playback, library, user activity, and storage data.
 *
 * This service records events into the stats_* tables and provides aggregation
 * queries for the admin dashboard (top users, top media, playback time series).
 *
 * @author Phlix Team
 * @version 1.0.0
 * @description Collects and aggregates statistics for the Phlix Media Server.
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
    /**
     * Are usage statistics being recorded?
     *
     * Backs the `stats.enabled` setting. Read at call time through
     * {@see \Phlix\Config\EffectiveConfig::file()} (which memoises per
     * bootstrap generation), so an admin override applies on reload without a
     * full restart.
     *
     * Guarding HERE rather than at the ~52 call sites is deliberate: a switch
     * that each caller had to remember to consult would be honoured
     * inconsistently, which is the "half-effective setting" failure this
     * settings program keeps running into.
     *
     * Defaults to TRUE when the key is absent so existing installs keep
     * recording exactly as before.
     *
     * @return bool
     *
     * @since 1.3.0
     */
    public function isEnabled(): bool
    {
        return (\Phlix\Config\EffectiveConfig::file('stats')['enabled'] ?? true) !== false;
    }

    private function generateUuid(): string
    {
        return Uuid::v4();
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
     * Run one telemetry WRITE, containing any failure inside the stats subsystem.
     *
     * ## Why this boundary exists (S102)
     *
     * Statistics are telemetry: recording them is strictly less important than
     * the user action that triggered them. Before this method every `record*()`
     * call let the driver's exception escape straight to the caller — and
     * `PlaybackController::dispatchPlaybackStarted()` has no try/catch, so a
     * single bad column value took playback-start down with it. In production
     * that is exactly what happened: `media_type = 'episode'` against migration
     * 019's four-member ENUM raised
     * `SQLSTATE[01000]: Warning: 1265 Data truncated for column 'media_type'`,
     * which surfaced as an unhandled `PDOException` in the HTTP worker on EVERY
     * episode play. Widening the ENUM fixes that one value; this boundary makes
     * the whole class of failure (a stats table missing, a column drifted, the
     * connection dropped) non-fatal to playback.
     *
     * ## Deliberately narrow
     *
     * Only WRITES are wrapped. The read/aggregation methods
     * ({@see getPlaybackStats()}, {@see getTopUsers()}, {@see getTopMedia()})
     * are NOT: they serve the admin dashboard, where a broken query must surface
     * as a visible error rather than as a silently empty chart, and none of them
     * sits on a playback path.
     *
     * Failures are logged at **error** level, so they land in `.logs/error.log`
     * (the error handler is untagged and therefore attaches to every channel)
     * and are visible to anyone watching the error log — swallowed, but never
     * silent. The SQL is included because these statements are static literals
     * with bound parameters; the parameters themselves are NOT logged, since
     * they carry user ids and client IPs.
     *
     * This mirrors the reasoning behind {@see isEnabled()}: guarding HERE rather
     * than at the ~52 call sites means the guarantee cannot be forgotten by a
     * future caller.
     *
     * @param string             $operation Symbolic name of the write, for the log.
     * @param string             $sql       Static SQL statement.
     * @param array<int, mixed>  $params    Bound parameters.
     *
     * @return bool True when the write landed, false when it was contained.
     *
     * @since 1.9
     */
    private function write(string $operation, string $sql, array $params): bool
    {
        try {
            $this->db->query($sql, $params);
            return true;
        } catch (Throwable $e) {
            LoggerFactory::get(LogChannels::APPLICATION)->error(
                'Stats write failed and was contained; the triggering action is unaffected',
                [
                    'operation' => $operation,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                ]
            );
            return false;
        }
    }

    /**
     * Record a playback start event.
     *
     * `$mediaType` is stored VERBATIM: `stats_playback_events.media_type` carries
     * the full 13-member `media_items.type` vocabulary (migration 094), so an
     * `episode` play is recorded as `episode` rather than folded into `series`.
     * A value outside {@see MediaItemType::ALL} would be rejected by the column
     * (MySQL error 1265 under `STRICT_TRANS_TABLES`), so it is coerced to
     * {@see MediaItemType::FALLBACK} and logged as a warning — a future ENUM
     * member shows up in the log instead of losing the whole event.
     *
     * @param string $userId User UUID starting playback
     * @param string $mediaItemId Media item UUID being played
     * @param string $mediaType Raw `media_items.type` value; see MediaItemType::ALL
     * @param string|null $deviceId Optional device identifier
     *
     * @return string Event ID for later completion via recordPlaybackEnd
     *
     * @example
     * ```php
     * $eventId = $collector->recordPlaybackStart('user-123', 'media-456', 'episode', 'device-789');
     * ```
     */
    public function recordPlaybackStart(
        string $userId,
        string $mediaItemId,
        string $mediaType,
        ?string $deviceId = null
    ): string {
        $eventId = $this->generateUuid();

        // Still returns a well-formed id when disabled: callers hand it to
        // recordPlaybackEnd(), which is guarded too, so the contract holds and
        // no caller needs to know statistics are off.
        if (!$this->isEnabled()) {
            return $eventId;
        }
        $clientIp = null;
        $storedType = MediaItemType::normalize($mediaType);
        if ($storedType !== $mediaType) {
            LoggerFactory::get(LogChannels::APPLICATION)->warning(
                'Playback stats received a media type outside the media_items.type ENUM; '
                . 'recording it under the fallback type instead',
                [
                    'received' => $mediaType,
                    'recorded_as' => $storedType,
                    'media_item_id' => $mediaItemId,
                ]
            );
        }

        $this->write(
            'playback_start',
            "INSERT INTO stats_playback_events
             (id, user_id, media_item_id, media_type, started_at, device_id, client_ip)
             VALUES (?, ?, ?, ?, NOW(), ?, ?)",
            [$eventId, $userId, $mediaItemId, $storedType, $deviceId, $clientIp]
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
        if (!$this->isEnabled()) {
            return;
        }

        $this->write(
            'playback_end',
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
     * $collector->recordLibraryChange('item_added', 'media-456', null, null, ['path' => 'movie.mkv']);
     * ```
     */
    public function recordLibraryChange(
        string $changeType,
        ?string $mediaItemId = null,
        ?string $libraryId = null,
        ?string $userId = null,
        array $details = []
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $id = $this->generateUuid();
        $detailsJson = $details !== [] ? json_encode($details) : null;

        $this->write(
            'library_change',
            "INSERT INTO stats_library_changes
             (id, change_type, media_item_id, library_id, user_id, changed_at, details_json)
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
    public function recordUserActivity(
        string $userId,
        string $activityType,
        ?string $ipAddress = null,
        array $details = []
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $id = $this->generateUuid();
        $userAgent = null;
        $detailsJson = $details !== [] ? json_encode($details) : null;

        $this->write(
            'user_activity',
            "INSERT INTO stats_user_activity
             (id, user_id, activity_type, occurred_at, ip_address, user_agent, details_json)
             VALUES (?, ?, ?, NOW(), ?, ?, ?)",
            [$id, $userId, $activityType, $ipAddress, $userAgent, $detailsJson]
        );
    }

    /**
     * Record a storage snapshot.
     *
     * ## Coarse buckets, not raw types (S102)
     *
     * Unlike {@see recordPlaybackStart()}, `stats_storage.media_type` is
     * deliberately a COARSE column: five buckets
     * ({@see StorageSnapshotHelper::BUCKETS}), because it has a real reader —
     * `DashboardService::getStorageSummary()` groups by it and matches the result
     * into a fixed `{movie,series,music,photo,book}_bytes` shape, so a raw
     * 13-member type would land in that `match`'s `default => null` arm and its
     * bytes would vanish from the dashboard totals. Widening this column would
     * therefore BREAK a consumer; widening `stats_playback_events.media_type`
     * (which has no readers) does not.
     *
     * So instead of widening, the fold is enforced here, at the only writer:
     * `$mediaType` is normalised through
     * {@see StorageSnapshotHelper::TYPE_TO_BUCKET}, which is exhaustive over
     * {@see \Phlix\Media\MediaItemType::ALL}. The fold is idempotent — every
     * bucket maps to itself — so the two existing callers
     * ({@see StorageSnapshotHelper::bootstrapSnapshot()} and
     * `Application::recordStorageSnapshots()`), which already pass bucket names,
     * are unaffected, while a future caller handing over a raw `episode` gets a
     * correct `series` row rather than MySQL error 1265.
     *
     * @param string $mediaType Bucket name, or any `media_items.type` value to fold
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
    public function recordStorageSnapshot(
        string $mediaType,
        int $itemCount,
        int $totalBytes,
        int $transcodeCacheBytes = 0,
        ?string $libraryId = null
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $id = $this->generateUuid();
        $bucket = StorageSnapshotHelper::TYPE_TO_BUCKET[$mediaType]
            ?? StorageSnapshotHelper::TYPE_TO_BUCKET[MediaItemType::FALLBACK];
        if ($bucket !== $mediaType) {
            LoggerFactory::get(LogChannels::APPLICATION)->warning(
                'Storage snapshot media type folded to a stats_storage bucket',
                ['received' => $mediaType, 'recorded_as' => $bucket]
            );
        }

        $this->write(
            'storage_snapshot',
            "INSERT INTO stats_storage
             (id, recorded_at, library_id, media_type, item_count, total_bytes, transcode_cache_bytes)
             VALUES (?, NOW(), ?, ?, ?, ?, ?)",
            [$id, $libraryId, $bucket, $itemCount, $totalBytes, $transcodeCacheBytes]
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
        // INNER JOIN users so playback events belonging to a since-deleted
        // account are excluded at the query level (S14 orphan guard) — this
        // keeps blank / no-name rows out of the admin "Top Users" leaderboard.
        // users.id is the PK, so the join is 1:1 and does not fan out the
        // COUNT/SUM aggregates.
        $sql = "SELECT
                    e.user_id,
                    COALESCE(SUM(e.duration_seconds), 0) AS total_watch_time,
                    COUNT(*) AS play_count
                FROM stats_playback_events e
                INNER JOIN users u ON e.user_id = u.id";

        $params = [];

        if ($since !== null) {
            $sql .= " WHERE e.started_at >= ?";
            $params[] = $since->format('Y-m-d H:i:s');
        }

        $sql .= " GROUP BY e.user_id ORDER BY total_watch_time DESC LIMIT ?";
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
        // INNER JOIN media_items so plays of a since-deleted item are excluded
        // at the query level (S14 orphan guard) — this keeps blank / no-title
        // rows out of the admin "Top Media" list. media_items.id is the PK, so
        // the join is 1:1 and does not fan out the COUNT/SUM aggregates.
        $sql = "SELECT
                    e.media_item_id,
                    COUNT(*) AS play_count,
                    COALESCE(SUM(e.duration_seconds), 0) AS total_duration
                FROM stats_playback_events e
                INNER JOIN media_items mi ON e.media_item_id = mi.id";

        $params = [];

        if ($since !== null) {
            $sql .= " WHERE e.started_at >= ?";
            $params[] = $since->format('Y-m-d H:i:s');
        }

        $sql .= " GROUP BY e.media_item_id ORDER BY play_count DESC LIMIT ?";
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
