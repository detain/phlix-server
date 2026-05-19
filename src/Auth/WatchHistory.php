<?php

declare(strict_types=1);

namespace Phlex\Auth;

use Phlex\Auth\Dto\UserRow;
use Phlex\Auth\Dto\WatchHistoryRow;
use Phlex\Common\Util\RowMap;
use Workerman\MySQL\Connection;

/**
 * Manages watch history and progress tracking per profile.
 *
 * This class provides comprehensive watch history management including:
 * - Tracking playback progress per profile per media item
 * - Managing "continue watching" and "recently completed" queues
 * - Calculating watch time statistics (total, daily, by period)
 * - Resume position tracking for seamless playback continuation
 * - Automatic completion detection based on progress threshold
 *
 * Tick Format:
 * All position and duration values are in "ticks" - 100-nanosecond intervals.
 * This matches standard media formats (e.g., Matroska/EBML use 100ns ticks).
 * Conversion: ticks / 10000000 = seconds, ticks / 10000 = milliseconds
 *
 * Watch History Structure:
 * Each entry tracks a single media item's watch progress for a profile:
 * - position_ticks: Current playback position
 * - duration_ticks: Total media duration
 * - progress_percent: Calculated completion percentage (0-100)
 * - playback_status: Current state (playing, paused, stopped, completed)
 * - completed_at: Timestamp when progress reached completion threshold
 *
 * @package Phlex\Auth
 * @author Phlex Development Team
 * @license Proprietary
 *
 * @see UserProfileManager For profile-based access control and restrictions
 * @see PlaybackController For session-based playback state management
 */
class WatchHistory
{
    /**
     * Database connection instance.
     *
     * @var Connection
     */
    private Connection $db;

    /**
     * Playback status: Media is actively playing.
     *
     * @var string
     */
    public const STATUS_PLAYING = 'playing';

    /**
     * Playback status: Media is paused.
     *
     * @var string
     */
    public const STATUS_PAUSED = 'paused';

    /**
     * Playback status: Playback has been stopped.
     *
     * @var string
     */
    public const STATUS_STOPPED = 'stopped';

    /**
     * Playback status: Media has been completed.
     *
     * A media item is considered completed when progress_percent >= COMPLETED_THRESHOLD.
     *
     * @var string
     */
    public const STATUS_COMPLETED = 'completed';

    /**
     * Progress percentage threshold for marking as completed.
     *
     * When a media item's progress reaches or exceeds this percentage,
     * it is automatically marked as completed and the completed_at
     * timestamp is set.
     *
     * @var float
     */
    public const COMPLETED_THRESHOLD = 90.0;

    /**
     * Tick conversion factor: ticks to milliseconds.
     *
     * In this implementation, 1 tick = 0.1 milliseconds (100 microseconds).
     * Therefore 10 ticks = 1 millisecond, 10000 ticks = 1 second.
     *
     * @var int
     */
    public const TICKS_PER_MILLISECOND = 10;

    /**
     * Tick conversion factor: ticks to seconds.
     *
     * In this implementation, 1 tick = 0.1 milliseconds.
     * Therefore 10000 ticks = 1 second.
     *
     * @var int
     */
    public const TICKS_PER_SECOND = 10000;

    /**
     * Default pagination limit for history queries.
     *
     * @var int
     */
    public const DEFAULT_LIMIT = 50;

    /**
     * Default limit for continue watching queue.
     *
     * @var int
     */
    public const CONTINUE_WATCHING_LIMIT = 10;

    /**
     * Default limit for recently completed queue.
     *
     * @var int
     */
    public const RECENTLY_COMPLETED_LIMIT = 20;

    /**
     * Constructs a new WatchHistory instance.
     *
     * @param Connection $db Database connection for watch history persistence
     *
     * @throws void
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Get watch history entries for a profile.
     *
     * Returns paginated watch history including media metadata.
     * Results are ordered by most recently watched first.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param int $limit Maximum number of entries to return (default: 50)
     * @param int $offset Number of entries to skip for pagination (default: 0)
     *
     * @return list<array<string, mixed>> Array of watch history entries with media info.
     *         Each entry contains: id, profile_id, media_item_id, position_ticks,
     *         duration_ticks, playback_status, progress_percent, last_watched_at,
     *         created_at, completed_at, and optionally media_name, media_type,
     *         metadata, poster_url, thumbnail_url when JOINed.
     *
     * @throws void
     *
     * @see getContinueWatching() For in-progress items only
     * @see getRecentlyCompleted() For finished items only
     */
    public function getHistory(string $profileId, int $limit = 50, int $offset = 0): array
    {
        $results = $this->db->query(
            "SELECT wh.*, mi.name as media_name, mi.type as media_type, mi.metadata_json
             FROM watch_history wh
             JOIN media_items mi ON wh.media_item_id = mi.id
             WHERE wh.profile_id = ?
             ORDER BY wh.last_watched_at DESC
             LIMIT ? OFFSET ?",
            [$profileId, $limit, $offset]
        );

        $rows = RowMap::listFromMixed($results);
        return array_map(fn(array $r): array => $this->hydrateEntry($r), $rows);
    }

    /**
     * Get continue watching items for a profile.
     *
     * Returns items that are in progress but not yet completed.
     * Useful for displaying "Resume Watching" or "Continue Watching" sections.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param int $limit Maximum number of items to return (default: 10)
     *
     * @return list<array<string, mixed>> Array of in-progress watch entries with media info.
     *                          See getHistory() return format for structure.
     *
     * @throws void
     *
     * @see getRecentlyCompleted() For finished items
     * @see getHistory() For all history entries
     */
    public function getContinueWatching(string $profileId, int $limit = 10): array
    {
        $results = $this->db->query(
            "SELECT wh.*, mi.name as media_name, mi.type as media_type, mi.metadata_json
             FROM watch_history wh
             JOIN media_items mi ON wh.media_item_id = mi.id
             WHERE wh.profile_id = ?
               AND wh.playback_status != 'completed'
               AND wh.progress_percent > 0
               AND wh.progress_percent < ?
             ORDER BY wh.last_watched_at DESC
             LIMIT ?",
            [$profileId, self::COMPLETED_THRESHOLD, $limit]
        );

        $rows = RowMap::listFromMixed($results);
        return array_map(fn(array $r): array => $this->hydrateEntry($r), $rows);
    }

    /**
     * Get recently completed items for a profile.
     *
     * Returns items that have been watched to completion, ordered by
     * most recently completed first.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param int $limit Maximum number of items to return (default: 20)
     *
     * @return list<array<string, mixed>> Array of completed watch entries with media info.
     *                          See getHistory() return format for structure.
     *
     * @throws void
     *
     * @see getContinueWatching() For in-progress items
     * @see hasWatched() To check completion status only
     */
    public function getRecentlyCompleted(string $profileId, int $limit = 20): array
    {
        $results = $this->db->query(
            "SELECT wh.*, mi.name as media_name, mi.type as media_type, mi.metadata_json
             FROM watch_history wh
             JOIN media_items mi ON wh.media_item_id = mi.id
             WHERE wh.profile_id = ?
               AND wh.playback_status = 'completed'
             ORDER BY wh.completed_at DESC
             LIMIT ?",
            [$profileId, $limit]
        );

        $rows = RowMap::listFromMixed($results);
        return array_map(fn(array $r): array => $this->hydrateEntry($r), $rows);
    }

    /**
     * Get watch history entry for a specific media item on a profile.
     *
     * Returns the profile's watch progress for a single media item,
     * or null if no history exists for that item.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param string $mediaItemId The unique media item identifier (UUID format)
     *
     * @return array<string, mixed>|null Watch history entry array (see getHistory() format)
     *                    or null if no entry exists
     *
     * @throws void
     *
     * @see updateProgress() To create or update an entry
     */
    public function getForMediaItem(string $profileId, string $mediaItemId): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM watch_history WHERE profile_id = ? AND media_item_id = ?",
            [$profileId, $mediaItemId]
        );

        $row = UserRow::firstFromMixed($result);
        if ($row === null) {
            return null;
        }

        return $this->hydrateEntry($row);
    }

    /**
     * Update or create watch progress for a profile and media item.
     *
     * This is the primary method for tracking playback progress.
     * It handles both creating new entries and updating existing ones.
     * Automatic completion detection occurs when progress >= COMPLETED_THRESHOLD.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param string $mediaItemId The unique media item identifier (UUID format)
     * @param int $positionTicks Current playback position in ticks
     *                            (100-nanosecond intervals, see class documentation)
     * @param int|null $durationTicks Total media duration in ticks (optional, uses
     *                                existing value if not provided)
     * @param string $status Playback status constant (STATUS_PLAYING, STATUS_PAUSED, etc.)
     *
     * @return array<string, mixed> Updated watch history entry with media info
     *
     * @throws \RuntimeException If the persisted entry can not be re-read after upsert.
     *
     * @example
     * // Report 30 minutes progress on a 2-hour movie
     * $entry = $watchHistory->updateProgress(
     *     'profile_123',
     *     'media_456',
     *     18000000000,  // 30 min in ticks
     *     72000000000,  // 2 hours in ticks
     *     WatchHistory::STATUS_PLAYING
     * );
     *
     * @see markCompleted() To manually mark as completed
     * @see getResumePosition() To get position for resuming
     */
    public function updateProgress(
        string $profileId,
        string $mediaItemId,
        int $positionTicks,
        ?int $durationTicks = null,
        string $status = self::STATUS_PLAYING
    ): array {
        // Get existing entry to calculate progress
        $existing = $this->getForMediaItem($profileId, $mediaItemId);

        $progressPercent = 0.0;
        if ($durationTicks && $durationTicks > 0) {
            $progressPercent = round(($positionTicks / $durationTicks) * 100, 2);
        }

        $completedAt = null;
        if ($progressPercent >= self::COMPLETED_THRESHOLD) {
            $status = self::STATUS_COMPLETED;
            $completedAt = date('Y-m-d H:i:s');
        }

        $now = date('Y-m-d H:i:s');

        if ($existing) {
            // Update existing entry
            $this->db->query(
                "UPDATE watch_history
                 SET position_ticks = ?,
                     duration_ticks = COALESCE(?, duration_ticks),
                     playback_status = ?,
                     progress_percent = ?,
                     last_watched_at = ?,
                     completed_at = COALESCE(?, completed_at)
                 WHERE profile_id = ? AND media_item_id = ?",
                [
                    $positionTicks,
                    $durationTicks,
                    $status,
                    $progressPercent,
                    $now,
                    $completedAt,
                    $profileId,
                    $mediaItemId,
                ]
            );
        } else {
            // Create new entry
            $id = $this->generateUuid();
            $this->db->query(
                "INSERT INTO watch_history (id, profile_id, media_item_id, position_ticks, duration_ticks, playback_status, progress_percent, last_watched_at, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $id,
                    $profileId,
                    $mediaItemId,
                    $positionTicks,
                    $durationTicks,
                    $status,
                    $progressPercent,
                    $now,
                    $completedAt,
                ]
            );
        }

        $entry = $this->getForMediaItem($profileId, $mediaItemId);
        if ($entry === null) {
            throw new \RuntimeException(
                'Failed to read back watch_history entry after upsert'
            );
        }
        return $entry;
    }

    /**
     * Mark a media item as completed for a profile.
     *
     * Manually sets the playback status to completed, ignoring the
     * automatic completion threshold. Use this when user explicitly
     * marks something as watched or finished.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param string $mediaItemId The unique media item identifier (UUID format)
     *
     * @return array<string, mixed> Updated watch history entry with status=completed
     *
     * @throws \RuntimeException If the persisted entry can not be re-read after upsert.
     *
     * @see updateProgress() For progress-based completion
     */
    public function markCompleted(string $profileId, string $mediaItemId): array
    {
        return $this->updateProgress(
            $profileId,
            $mediaItemId,
            0,
            null,
            self::STATUS_COMPLETED
        );
    }

    /**
     * Remove a media item from watch history.
     *
     * Permanently deletes the watch history entry for the specified
     * media item. This is useful for "Mark as Unwatched" functionality.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param string $mediaItemId The unique media item identifier (UUID format)
     *
     * @return void
     *
     * @throws void
     *
     * @see clearHistory() To remove all history for a profile
     */
    public function removeFromHistory(string $profileId, string $mediaItemId): void
    {
        $this->db->query(
            "DELETE FROM watch_history WHERE profile_id = ? AND media_item_id = ?",
            [$profileId, $mediaItemId]
        );
    }

    /**
     * Clear all watch history for a profile.
     *
     * Permanently deletes all watch history entries for the profile.
     * This action cannot be undone.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     *
     * @return void
     *
     * @throws void
     *
     * @see removeFromHistory() To remove a single entry
     */
    public function clearHistory(string $profileId): void
    {
        $this->db->query(
            "DELETE FROM watch_history WHERE profile_id = ?",
            [$profileId]
        );
    }

    /**
     * Get total watch time for a profile in seconds.
     *
     * Calculates the sum of all completed media durations for the profile.
     * Only includes items with playback_status = 'completed'.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     *
     * @return int Total watch time in seconds (0 if no history)
     *
     * @throws void
     *
     * @see getTodayWatchTime() For today's watch time only
     * @see getWatchTimeByDay() For historical breakdown
     */
    public function getTotalWatchTime(string $profileId): int
    {
        $row = UserRow::firstFromMixed($this->db->query(
            "SELECT SUM(duration_ticks) as total
             FROM watch_history
             WHERE profile_id = ? AND playback_status = 'completed'",
            [$profileId]
        ));

        $totalTicks = UserRow::int($row, 'total', 0);

        // Convert ticks to seconds (ticks / TICKS_PER_SECOND)
        return (int)($totalTicks / self::TICKS_PER_SECOND);
    }

    /**
     * Get watch time for today for a profile.
     *
     * Calculates total watch time for completed items watched today
     * (based on last_watched_at timestamp).
     *
     * @param string $profileId The unique profile identifier (UUID format)
     *
     * @return int Today's watch time in seconds (0 if none)
     *
     * @throws void
     *
     * @see getTotalWatchTime() For all-time total
     * @see getWatchTimeByDay() For multi-day breakdown
     */
    public function getTodayWatchTime(string $profileId): int
    {
        $row = UserRow::firstFromMixed($this->db->query(
            "SELECT SUM(duration_ticks) as total
             FROM watch_history
             WHERE profile_id = ?
               AND playback_status = 'completed'
               AND DATE(last_watched_at) = CURDATE()",
            [$profileId]
        ));

        $totalTicks = UserRow::int($row, 'total', 0);

        return (int)($totalTicks / self::TICKS_PER_SECOND);
    }

    /**
     * Get daily watch times for the past N days.
     *
     * Returns a keyed array mapping dates to total watch seconds,
     * useful for building watch time charts or usage statistics.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param int $days Number of past days to include (default: 7, max: 365)
     *
     * @return array<string, int> Associative array with date strings (Y-m-d) as keys
     *                          and watch time in seconds as values. Dates with no
     *                          watch activity are omitted from the array.
     *
     * @throws void
     *
     * @see getTotalWatchTime() For all-time total
     * @see getTodayWatchTime() For today's total only
     */
    public function getWatchTimeByDay(string $profileId, int $days = 7): array
    {
        $results = $this->db->query(
            "SELECT DATE(last_watched_at) as watch_date,
                    SUM(duration_ticks) as total_ticks
             FROM watch_history
             WHERE profile_id = ?
               AND playback_status = 'completed'
               AND last_watched_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(last_watched_at)
             ORDER BY watch_date ASC",
            [$profileId, $days]
        );

        $data = [];
        foreach (RowMap::listFromMixed($results) as $row) {
            $date = UserRow::string($row, 'watch_date');
            if ($date === null) {
                continue;
            }
            $totalTicks = UserRow::int($row, 'total_ticks', 0);
            $data[$date] = (int)($totalTicks / self::TICKS_PER_SECOND);
        }

        return $data;
    }

    /**
     * Check if a media item has been completed by a profile.
     *
     * Returns true only if the media item has been watched to completion
     * (status = completed), not just started.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param string $mediaItemId The unique media item identifier (UUID format)
     *
     * @return bool True if the media has been completed, false otherwise
     *
     * @throws void
     *
     * @see getForMediaItem() For full watch history entry
     * @see getRecentlyCompleted() For all completed items
     */
    public function hasWatched(string $profileId, string $mediaItemId): bool
    {
        $result = $this->db->query(
            "SELECT 1 FROM watch_history
             WHERE profile_id = ? AND media_item_id = ?
               AND playback_status = 'completed'",
            [$profileId, $mediaItemId]
        );

        return !empty($result);
    }

    /**
     * Get resume position for a media item.
     *
     * Returns the saved playback position where the user left off,
     * suitable for resuming playback. Returns null if the media
     * has been completed or hasn't been started.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param string $mediaItemId The unique media item identifier (UUID format)
     *
     * @return int|null Resume position in ticks, or null if not resumable
     *
     * @throws void
     *
     * @example
     * $position = $watchHistory->getResumePosition('profile_123', 'media_456');
     * if ($position !== null) {
     *     $player->seek($position);
     * }
     *
     * @see updateProgress() To save a new position
     */
    public function getResumePosition(string $profileId, string $mediaItemId): ?int
    {
        $entry = $this->getForMediaItem($profileId, $mediaItemId);

        if ($entry === null || ($entry['playback_status'] ?? null) === self::STATUS_COMPLETED) {
            return null;
        }

        $position = $entry['position_ticks'] ?? 0;
        return is_numeric($position) ? (int) $position : 0;
    }

    /**
     * Get count of items in watch history.
     *
     * Returns the total number of watch history entries for a profile,
     * regardless of completion status.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     *
     * @return int Total number of history entries (0 if none)
     *
     * @throws void
     *
     * @see getHistory() To retrieve the actual entries
     */
    public function getCount(string $profileId): int
    {
        $row = UserRow::firstFromMixed($this->db->query(
            "SELECT COUNT(*) as count FROM watch_history WHERE profile_id = ?",
            [$profileId]
        ));

        return UserRow::int($row, 'count', 0);
    }

    /**
     * Hydrate a database row into a watch history entry array.
     *
     * Transforms raw database records (including JOINed media info) into
     * structured arrays with properly typed values and extracted metadata.
     *
     * @param array<string, mixed> $row Raw database row from watch_history JOIN media_items
     *
     * @return array<string, mixed> Hydrated watch history entry with media metadata when available
     *
     * @internal
     */
    private function hydrateEntry(array $row): array
    {
        return WatchHistoryRow::fromRow($row)->toArray();
    }

    /**
     * Generate a UUID v4 string.
     *
     * Creates a random UUID suitable for use as a unique identifier.
     * Format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx (RFC 4122 compliant)
     *
     * @return string UUID v4 string
     *
     * @internal
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
