<?php

/**
 * Phlix media server component: Session.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Session;

use Phlix\Common\Uuid;
use Phlix\Common\Util\RowMap;
use Phlix\Media\Library\MediaItemShaper;
use Phlix\Stats\StatsCollector;
use Phlix\Shared\Events\Playback\PlaybackPaused;
use Phlix\Shared\Events\Playback\PlaybackResumed;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Psr\EventDispatcher\EventDispatcherInterface;
use Workerman\MySQL\Connection;

/**
 * Playback controller for managing playback state and progress.
 *
 * This class tracks playback progress for media items across sessions,
 * providing functionality to report progress, retrieve playback state,
 * and manage "continue watching" and "recently watched" lists.
 *
 * @author Phlix Team
 * @version 1.0.0
 * @description Manages playback state persistence and progress tracking
 *              for session-based media playback in the Phlix Media Server.
 * @see SessionManager For session lifecycle management
 *
 * @property Connection $db Database connection instance
 * @property SessionManager $sessionManager Session manager reference
 * @property StructuredLogger $logger Application logger
 */
class PlaybackController
{
    /** @var Connection Database connection for MySQL queries */
    private Connection $db;

    /** @var SessionManager Session manager for activity updates */
    private SessionManager $sessionManager;

    /** @var StructuredLogger Application logger for playback events */
    private StructuredLogger $logger;

    /** @var EventDispatcherInterface|null PSR-14 dispatcher for playback lifecycle events. */
    private ?EventDispatcherInterface $eventDispatcher;

    /** @var StatsCollector|null Stats collector for recording playback events */
    private ?StatsCollector $statsCollector;

    /** @var \Phlix\Dlna\PlayToManager|null DLNA play-to manager for renderer sessions */
    private ?\Phlix\Dlna\PlayToManager $playToManager;

    /** @var array<string, string> Map of sessionId:mediaItemId -> eventId for playback tracking */
    private array $playbackEventIds = [];

    /**
     * Create a new PlaybackController instance.
     *
     * @param Connection $db Workerman MySQL connection instance
     * @param SessionManager $sessionManager Session manager for activity tracking
     * @param StructuredLogger|null $logger Optional application logger
     * @param EventDispatcherInterface|null $eventDispatcher Optional PSR-14 dispatcher;
     *                                       when supplied, lifecycle events
     *                                       (started/paused/resumed/stopped)
     *                                       are dispatched as they occur. Defaults
     *                                       to null so unit tests not exercising
     *                                       events do not need to wire one up.
     * @param StatsCollector|null $statsCollector Optional stats collector for
     *                                       recording playback metrics. Defaults
     *                                       to null so unit tests not exercising
     *                                       stats do not need to wire one up.
     *
     * @example
     * ```php
     * $controller = new PlaybackController($db, $sessionManager);
     * ```
     */
    public function __construct(
        Connection $db,
        SessionManager $sessionManager,
        ?StructuredLogger $logger = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?StatsCollector $statsCollector = null,
        ?\Phlix\Dlna\PlayToManager $playToManager = null
    ) {
        $this->db = $db;
        $this->sessionManager = $sessionManager;
        $this->logger = $logger ?? $this->createDefaultLogger();
        $this->eventDispatcher = $eventDispatcher;
        $this->statsCollector = $statsCollector;
        $this->playToManager = $playToManager;
    }

    /**
     * Create a default logger for playback events.
     *
     * @return StructuredLogger Configured logger instance
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlix_playback_' . uniqid();
        mkdir($tempDir, 0755, true);

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/playback.log',
                    'level' => 'debug',
                ],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ];

        return new StructuredLogger(LogChannels::SESSION, $config);
    }

    /**
     * Report playback progress for a session.
     *
     * Updates or creates playback state record for the given session
     * and media item. Also updates the parent session's activity timestamp.
     *
     * @param string $sessionId Session UUID for the playback
     * @param string $mediaItemId Media item UUID being played
     * @param int $positionTicks Current playback position in ticks
     * @param int $durationTicks Total media duration in ticks
     * @param bool $isPaused Whether playback is paused
     *
     * @return void
     *
     * @example
     * ```php
     * $controller->reportProgress(
     *     'session-uuid-123',
     *     'media-uuid-456',
     *     12000000000,  // 20 minutes in ticks
     *     36000000000,  // 1 hour in ticks
     *     false         // playing
     * );
     * ```
     */
    public function reportProgress(
        string $sessionId,
        string $mediaItemId,
        int $positionTicks,
        int $durationTicks,
        bool $isPaused
    ): void {
        $previousStatus = $this->lookupPlaybackStatus($sessionId, $mediaItemId);
        $newStatus = $isPaused ? 'paused' : 'playing';

        // Update or create playback state
        $this->db->query(
            "INSERT INTO playback_state (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                position_ticks = VALUES(position_ticks),
                duration_ticks = VALUES(duration_ticks),
                playback_status = VALUES(playback_status),
                updated_at = NOW()",
            [
                $this->generateUuid(),
                $sessionId,
                $mediaItemId,
                $positionTicks,
                $durationTicks,
                $newStatus,
            ]
        );

        // Update session activity
        $this->sessionManager->updateActivity($sessionId);

        // Dispatch lifecycle events for state transitions. The session
        // lookup is best-effort: when the session row is missing we
        // simply fall back to the empty string so listeners can still
        // observe the event (it just won't have user/device context).
        if ($this->eventDispatcher === null) {
            return;
        }
        if ($previousStatus === null) {
            $this->dispatchPlaybackStarted($sessionId, $mediaItemId, $positionTicks);
            return;
        }
        if ($previousStatus !== 'paused' && $newStatus === 'paused') {
            $this->dispatchPlaybackPaused($sessionId, $mediaItemId, $positionTicks);
            return;
        }
        if ($previousStatus === 'paused' && $newStatus === 'playing') {
            $this->dispatchPlaybackResumed($sessionId, $mediaItemId, $positionTicks);
        }
    }

    /**
     * Get current playback state for a session.
     *
     * @param string $sessionId Session UUID to get state for
     *
     * @return array<string, mixed>|null Playback state record or null if not found
     *
     * @example
     * ```php
     * $state = $controller->getPlaybackState('session-uuid-123');
     * if ($state) {
     *     echo "Playing: " . $state['media_item_id'];
     * }
     * ```
     */
    public function getPlaybackState(string $sessionId): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM playback_state WHERE session_id = ? ORDER BY updated_at DESC LIMIT 1",
            [$sessionId]
        );

        $rows = RowMap::listFromMixed($result);
        return $rows[0] ?? null;
    }

    /**
     * Get playback progress for a user and media item.
     *
     * Returns the most recent playback state across all of the user's sessions.
     *
     * @param string $userId User UUID to get progress for
     * @param string $mediaItemId Media item UUID to get progress for
     *
     * @return array<string, mixed>|null Playback state record or null if not found
     *
     * @example
     * ```php
     * $progress = $controller->getUserProgress('user-uuid-123', 'media-uuid-456');
     * if ($progress) {
     *     $resumePosition = $progress['position_ticks'];
     * }
     * ```
     */
    public function getUserProgress(string $userId, string $mediaItemId): ?array
    {
        $result = $this->db->query(
            "SELECT ps.* FROM playback_state ps
             INNER JOIN sessions s ON ps.session_id = s.id
             WHERE s.user_id = ? AND ps.media_item_id = ?
             ORDER BY ps.updated_at DESC LIMIT 1",
            [$userId, $mediaItemId]
        );

        $rows = RowMap::listFromMixed($result);
        return $rows[0] ?? null;
    }

    /**
     * Mark a media item as watched for a session.
     *
     * Sets playback status to 'stopped' and resets position to 0.
     *
     * @param string $sessionId Session UUID to mark watched for
     * @param string $mediaItemId Media item UUID to mark as watched
     *
     * @return void
     *
     * @example
     * ```php
     * $controller->markAsWatched('session-uuid-123', 'media-uuid-456');
     * ```
     */
    public function markAsWatched(string $sessionId, string $mediaItemId): void
    {
        $previous = $this->lookupPlaybackRow($sessionId, $mediaItemId);

        $this->db->query(
            "UPDATE playback_state SET playback_status = 'stopped', position_ticks = 0
             WHERE session_id = ? AND media_item_id = ?",
            [$sessionId, $mediaItemId]
        );

        if ($this->eventDispatcher === null) {
            return;
        }
        $finalPosition = self::positionFromRow($previous);
        $this->dispatchPlaybackStopped($sessionId, $mediaItemId, $finalPosition, reachedEnd: true);
    }

    /**
     * Clear playback progress for a session and media item.
     *
     * @param string $sessionId Session UUID to clear progress for
     * @param string $mediaItemId Media item UUID to clear progress for
     *
     * @return void
     *
     * @example
     * ```php
     * $controller->clearProgress('session-uuid-123', 'media-uuid-456');
     * ```
     */
    public function clearProgress(string $sessionId, string $mediaItemId): void
    {
        $previous = $this->lookupPlaybackRow($sessionId, $mediaItemId);

        $this->db->query(
            "DELETE FROM playback_state WHERE session_id = ? AND media_item_id = ?",
            [$sessionId, $mediaItemId]
        );

        if ($this->eventDispatcher === null || $previous === null) {
            return;
        }
        $finalPosition = self::positionFromRow($previous);
        $this->dispatchPlaybackStopped($sessionId, $mediaItemId, $finalPosition, reachedEnd: false);
    }

    /**
     * Returns the user's continue-watching list.
     *
     * Each returned row is shaped via MediaItemShaper and augmented with:
     * - top-level `id` = media item id (not playback state id)
     * - top-level `poster_url` = series poster for episodes (resolved before shaping)
     * - top-level `runtime` (minutes, from metadata) — SPA contract
     * - top-level `position_ticks` / `duration_ticks` (raw ticks, for SPA resume sync)
     * - top-level `media_item_id` and `metadata` map (console/gate compatibility)
     * - shaper-produced fields: `poster_srcset`, `year`, `rating`, `genres`, etc.
     *
     * Episodes: the stored poster_url is a TMDB still frame; this method resolves
     * the series poster (or season poster as fallback) before shaping so the CW
     * rail in `/app` shows correct poster images and a progress bar.
     *
     * @param string $userId User UUID to get continue watching list for
     * @param int $limit Maximum number of items to return (default: 10)
     *
     * @return list<array{id: string, metadata: array<string, mixed>, position_ticks: int,
     *                    duration_ticks: int, media_item_id: string, ...}>
     *         Array of shaped media items with playback position.
     *
     * @example
     * ```php
     * $continueWatching = $controller->getContinueWatching('user-uuid-123', 5);
     * foreach ($continueWatching as $item) {
     *     echo $item['name'] . " - " . $item['poster_url'];
     *     // $item['position_ticks'] / $item['duration_ticks'] are raw ticks for useResumeSync()
     * }
     * ```
     */
    public function getContinueWatching(string $userId, int $limit = 10): array
    {
        // A user has one playback_state row per (session, media item), and a
        // single title is watched across many sessions/devices — so the same
        // media_item_id appears in several rows. Collapse to one row per media
        // item (the most recently updated, ties broken by id) BEFORE applying
        // the limit, otherwise "LIMIT 10" can return ten rows that are all the
        // same title. The window function keeps the newest row per partition.
        //
        // Episode rows carry the TMDB still as their stored poster_url, which
        // is not appropriate for the CW card — we need the series poster.
        // We join the parent (season for episodes) and the grandparent series
        // to resolve the correct poster before shaping.
        $result = $this->db->query(
            "SELECT ranked.id, ranked.session_id, ranked.media_item_id,
                    ranked.position_ticks, ranked.duration_ticks,
                    ranked.playback_status, ranked.updated_at,
                    ranked.name, ranked.type, ranked.path, ranked.parent_id, ranked.created_at,
                    ranked.metadata_json, ranked.parent_metadata_json, ranked.series_metadata_json
             FROM (
                 SELECT ps.*,
                        mi.name, mi.type, mi.path, mi.parent_id, mi.created_at, mi.metadata_json,
                        p.metadata_json AS parent_metadata_json,
                        s.metadata_json AS series_metadata_json,
                        ROW_NUMBER() OVER (
                            PARTITION BY ps.media_item_id
                            ORDER BY ps.updated_at DESC, ps.id DESC
                        ) AS rn
                 FROM playback_state ps
                 INNER JOIN sessions s_session ON ps.session_id = s_session.id
                 INNER JOIN media_items mi ON ps.media_item_id = mi.id
                 LEFT JOIN media_items p ON mi.parent_id = p.id
                 LEFT JOIN media_items s ON p.parent_id = s.id
                 WHERE s_session.user_id = ?
                   AND ps.playback_status IN ('playing', 'paused')
                   AND ps.position_ticks > 0
                   AND ps.position_ticks < (ps.duration_ticks * 0.95)
             ) ranked
             WHERE ranked.rn = 1
             ORDER BY ranked.updated_at DESC
             LIMIT ?",
            [$userId, $limit]
        );

        $rows = RowMap::listFromMixed($result);

        return array_map(static function (array $row): array {
            $rawJson = $row['metadata_json'] ?? '{}';
            $metadata = is_string($rawJson) ? json_decode($rawJson, true) : [];
            if (!is_array($metadata)) {
                $metadata = [];
            }

            // For episodes the stored poster_url is a TMDB still frame. Resolve
            // a real poster: series poster → season poster → episode still.
            // Movies/series keep their own poster_url unchanged.
            if (($row['type'] ?? '') === 'episode') {
                $parentMeta = is_string($row['parent_metadata_json'] ?? null)
                    ? json_decode($row['parent_metadata_json'], true)
                    : null;
                $seriesMeta = is_string($row['series_metadata_json'] ?? null)
                    ? json_decode($row['series_metadata_json'], true)
                    : null;

                $seriesPoster = is_array($seriesMeta) ? ($seriesMeta['poster_url'] ?? null) : null;
                $seasonPoster = is_array($parentMeta) ? ($parentMeta['poster_url'] ?? null) : null;

                // The episode's stored poster_url is (almost always) a TMDB still
                // frame — the wrong image for the CW rail. For the TMDB path the
                // still is detectable (poster_url == still_url), but for non-TMDB
                // episodes there is no still_url field to compare against, so the
                // still would slip through the old detection and the rail showed a
                // frame grab instead of the series art. Prefer the resolved
                // series → season poster whenever ONE EXISTS, regardless of whether
                // a still was positively detected; only fall back to the episode's
                // own poster when neither a series nor a season poster is available.
                if ($seriesPoster !== null && $seriesPoster !== '') {
                    $metadata['poster_url'] = $seriesPoster;
                } elseif ($seasonPoster !== null && $seasonPoster !== '') {
                    $metadata['poster_url'] = $seasonPoster;
                }
            }

            // Build the item array for MediaItemShaper::shape(): needs top-level
            // id, name, type, path, parent_id, created_at, updated_at, metadata.
            // Use media_item_id (not playback state id) as the shaped id.
            $item = [
                'id' => $row['media_item_id'],
                'name' => $row['name'],
                'type' => $row['type'],
                'path' => $row['path'] ?? null,
                'parent_id' => $row['parent_id'] ?? null,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
                'metadata' => $metadata,
            ];

            /** @var array{id: string, metadata: array<string, mixed>, position_ticks: int, duration_ticks: int, media_item_id: string, ...} $shaped */
            $shaped = MediaItemShaper::shape($item);

            // Re-attach playback-specific fields the shaper doesn't carry.
            // Use type checks to satisfy PHPStan
            $positionTicks = is_numeric($row['position_ticks'] ?? null) ? intval($row['position_ticks']) : 0;
            $durationTicks = is_numeric($row['duration_ticks'] ?? null) ? intval($row['duration_ticks']) : 0;
            $mediaItemId = is_string($row['media_item_id'] ?? null) ? $row['media_item_id'] : '';
            $shaped['position_ticks'] = $positionTicks;
            $shaped['duration_ticks'] = $durationTicks;
            $shaped['media_item_id'] = $mediaItemId;
            // MediaItemShaper::shape() re-mints any stale/expired signature on the
            // top-level internal-artwork poster_url. Copy that fresh value back into
            // the metadata map so clients that read the NESTED metadata.poster_url
            // (the console TUI's authless <img> loader) also get a valid signature —
            // otherwise they fetch the scan-time-signed (now expired) URL and 401 →
            // blank poster. External (TMDB/AniList) covers pass through unchanged.
            if (is_string($shaped['poster_url'] ?? null) && ($shaped['poster_url'] !== '')) {
                $metadata['poster_url'] = $shaped['poster_url'];
            }
            $shaped['metadata'] = $metadata;

            return $shaped;
        }, $rows);
    }

    /**
     * Get recently watched items for a user.
     *
     * Returns all media items in reverse chronological order by last watch time.
     *
     * @param string $userId User UUID to get recently watched for
     * @param int $limit Maximum number of items to return (default: 20)
     *
     * @return array<int, array<string, mixed>> Array of playback state records with media info
     *
     * @example
     * ```php
     * $recentlyWatched = $controller->getRecentlyWatched('user-uuid-123', 10);
     * ```
     */
    public function getRecentlyWatched(string $userId, int $limit = 20): array
    {
        $result = $this->db->query(
            "SELECT ps.*, mi.name, mi.type, mi.metadata_json
             FROM playback_state ps
             INNER JOIN sessions s ON ps.session_id = s.id
             INNER JOIN media_items mi ON ps.media_item_id = mi.id
             WHERE s.user_id = ?
             ORDER BY ps.updated_at DESC
             LIMIT ?",
            [$userId, $limit]
        );

        return array_map(static function (array $row): array {
            $rawJson = $row['metadata_json'] ?? '{}';
            $json = is_string($rawJson) ? $rawJson : '{}';
            $row['metadata'] = json_decode($json, true);
            return $row;
        }, RowMap::listFromMixed($result));
    }

    /**
     * Generate a UUID v4 string.
     *
     * @return string UUID in standard format
     */
    private function generateUuid(): string
    {
        return Uuid::v4();
    }

    /**
     * Look up the current `playback_status` for a `(session, media)` pair.
     *
     * Used to detect the started/paused/resumed transitions.
     *
     * @param string $sessionId   Session UUID.
     * @param string $mediaItemId Media item UUID.
     *
     * @return string|null Status string from the DB, or null when no row exists.
     */
    private function lookupPlaybackStatus(string $sessionId, string $mediaItemId): ?string
    {
        $row = $this->lookupPlaybackRow($sessionId, $mediaItemId);
        if ($row === null) {
            return null;
        }
        $status = $row['playback_status'] ?? null;
        return is_string($status) ? $status : null;
    }

    /**
     * Look up the latest playback_state row for a `(session, media)` pair.
     *
     * @param string $sessionId   Session UUID.
     * @param string $mediaItemId Media item UUID.
     *
     * @return array<string, mixed>|null Row data when found, null otherwise.
     */
    private function lookupPlaybackRow(string $sessionId, string $mediaItemId): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM playback_state WHERE session_id = ? AND media_item_id = ? ORDER BY updated_at DESC LIMIT 1",
            [$sessionId, $mediaItemId]
        );
        $rows = RowMap::listFromMixed($result);
        return $rows[0] ?? null;
    }

    /**
     * Resolve `(userId, deviceId)` for a session, falling back to empty
     * strings when the session lookup yields nothing usable (e.g. tests
     * that mock the DB).
     *
     * @param string $sessionId Session UUID.
     *
     * @return array{0: string, 1: string} `[userId, deviceId]`.
     */
    private function resolveSessionContext(string $sessionId): array
    {
        try {
            $session = $this->sessionManager->getSession($sessionId);
        } catch (\Throwable) {
            $session = null;
        }
        if (!is_array($session)) {
            return ['', ''];
        }
        $userId = self::stringFromMixed($session['user_id'] ?? null);
        $deviceId = self::stringFromMixed($session['device_id'] ?? null);
        return [$userId, $deviceId];
    }

    /**
     * Coerce a mixed value into a string suitable for an event payload.
     *
     * Returns the empty string for null / non-scalar values so the
     * caller never has to special-case a missing column.
     *
     * @param mixed $value Raw column value from a query result.
     *
     * @return string Coerced string (empty when not coercible).
     */
    private static function stringFromMixed(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string)$value;
        }
        return '';
    }

    /**
     * Pull the `position_ticks` column out of a playback_state row as
     * a non-negative int, defaulting to zero when missing / unparseable.
     *
     * @param array<string, mixed>|null $row Row data, or null when none.
     *
     * @return int Position in ticks; zero when row missing.
     */
    private static function positionFromRow(?array $row): int
    {
        if ($row === null) {
            return 0;
        }
        $raw = $row['position_ticks'] ?? 0;
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && is_numeric($raw)) {
            return (int)$raw;
        }
        if (is_float($raw)) {
            return (int)$raw;
        }
        return 0;
    }

    /**
     * Emit {@see PlaybackStarted}.
     *
     * @param string $sessionId     Session UUID.
     * @param string $mediaItemId   Media item UUID.
     * @param int    $positionTicks Position at the moment of start, in ticks.
     *
     * @return void
     */
    private function dispatchPlaybackStarted(string $sessionId, string $mediaItemId, int $positionTicks): void
    {
        [$userId, $deviceId] = $this->resolveSessionContext($sessionId);

        // Record stats if collector is available
        if ($this->statsCollector !== null && $userId !== '') {
            $eventId = $this->statsCollector->recordPlaybackStart(
                $userId,
                $mediaItemId,
                'movie', // Default type; actual type lookup would require DB query
                $deviceId
            );
            $key = $sessionId . ':' . $mediaItemId;
            $this->playbackEventIds[$key] = $eventId;
        }

        if ($this->eventDispatcher === null) {
            return;
        }

        $this->eventDispatcher->dispatch(new PlaybackStarted(
            sessionId: $sessionId,
            userId: $userId,
            mediaItemId: $mediaItemId,
            deviceId: $deviceId,
            positionTicks: $positionTicks,
        ));
    }

    /**
     * Emit {@see PlaybackPaused}.
     *
     * @param string $sessionId     Session UUID.
     * @param string $mediaItemId   Media item UUID.
     * @param int    $positionTicks Position at the moment of pause, in ticks.
     *
     * @return void
     */
    private function dispatchPlaybackPaused(string $sessionId, string $mediaItemId, int $positionTicks): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }
        [$userId, $deviceId] = $this->resolveSessionContext($sessionId);
        $this->eventDispatcher->dispatch(new PlaybackPaused(
            sessionId: $sessionId,
            userId: $userId,
            mediaItemId: $mediaItemId,
            deviceId: $deviceId,
            positionTicks: $positionTicks,
        ));
    }

    /**
     * Emit {@see PlaybackResumed}.
     *
     * @param string $sessionId     Session UUID.
     * @param string $mediaItemId   Media item UUID.
     * @param int    $positionTicks Position at the moment of resume, in ticks.
     *
     * @return void
     */
    private function dispatchPlaybackResumed(string $sessionId, string $mediaItemId, int $positionTicks): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }
        [$userId, $deviceId] = $this->resolveSessionContext($sessionId);
        $this->eventDispatcher->dispatch(new PlaybackResumed(
            sessionId: $sessionId,
            userId: $userId,
            mediaItemId: $mediaItemId,
            deviceId: $deviceId,
            positionTicks: $positionTicks,
        ));
    }

    /**
     * Emit {@see PlaybackStopped}.
     *
     * @param string $sessionId          Session UUID.
     * @param string $mediaItemId        Media item UUID.
     * @param int    $finalPositionTicks Final position at stop, in ticks.
     * @param bool   $reachedEnd         True when stop should be treated
     *                                   as fully-watched (markAsWatched),
     *                                   false when the user stopped
     *                                   mid-stream (clearProgress).
     *
     * @return void
     */
    private function dispatchPlaybackStopped(
        string $sessionId,
        string $mediaItemId,
        int $finalPositionTicks,
        bool $reachedEnd
    ): void {
        // Record stats if collector is available
        if ($this->statsCollector !== null) {
            $key = $sessionId . ':' . $mediaItemId;
            $eventId = $this->playbackEventIds[$key] ?? null;
            if ($eventId !== null) {
                // Convert ticks to seconds (ticks are in 100-nanosecond intervals)
                $durationSeconds = (int) ($finalPositionTicks / 10_000_000);
                $this->statsCollector->recordPlaybackEnd($eventId, $durationSeconds, $reachedEnd);
                unset($this->playbackEventIds[$key]);
            }
        }

        if ($this->eventDispatcher === null) {
            return;
        }

        [$userId, $deviceId] = $this->resolveSessionContext($sessionId);
        $this->eventDispatcher->dispatch(new PlaybackStopped(
            sessionId: $sessionId,
            userId: $userId,
            mediaItemId: $mediaItemId,
            deviceId: $deviceId,
            finalPositionTicks: $finalPositionTicks,
            reachedEnd: $reachedEnd,
        ));
    }

    /**
     * Start a "play to" DLNA session alongside the local session.
     *
     * Creates a PlayToSession that sends media to a DLNA renderer while
     * also tracking position in the local PlaybackController. Both local
     * and remote positions are kept in sync.
     *
     * @param string $sessionId Local session ID for position tracking
     * @param string $mediaItemId Media item UUID being played
     * @param string $rendererId DLNA renderer UDN
     * @param string $streamUrl HLS stream URL for the renderer
     * @param string $metadata DIDL-Lite metadata (optional)
     *
     * @return \Phlix\Dlna\PlayToSession|null New play-to session or null on failure
     *
     * @since 0.12.0
     */
    public function startPlayToSession(
        string $sessionId,
        string $mediaItemId,
        string $rendererId,
        string $streamUrl,
        string $metadata = ''
    ): ?\Phlix\Dlna\PlayToSession {
        try {
            if ($this->playToManager === null) {
                $this->logger->warning('PlayToManager not available — inject it via constructor');
                return null;
            }

            $session = $this->playToManager->startSession($rendererId, $mediaItemId, $streamUrl, $metadata);

            if ($session === null) {
                $this->logger->error('Failed to start play-to session', [
                    'renderer_id' => $rendererId,
                    'media_item_id' => $mediaItemId,
                ]);
                return null;
            }

            $this->logger->info('Play-to session started', [
                'session_id' => $session->getSessionId(),
                'renderer_id' => $rendererId,
                'media_item_id' => $mediaItemId,
            ]);

            return $session;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to start play-to session', [
                'error' => $e->getMessage(),
                'renderer_id' => $rendererId,
                'media_item_id' => $mediaItemId,
            ]);
            return null;
        }
    }
}
