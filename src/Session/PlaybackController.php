<?php

declare(strict_types=1);

namespace Phlex\Session;

use Phlex\Stats\StatsCollector;
use Phlex\Shared\Events\Playback\PlaybackPaused;
use Phlex\Shared\Events\Playback\PlaybackResumed;
use Phlex\Shared\Events\Playback\PlaybackStarted;
use Phlex\Shared\Events\Playback\PlaybackStopped;
use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\StructuredLogger;
use Psr\EventDispatcher\EventDispatcherInterface;
use Workerman\MySQL\Connection;

/**
 * Playback controller for managing playback state and progress.
 *
 * This class tracks playback progress for media items across sessions,
 * providing functionality to report progress, retrieve playback state,
 * and manage "continue watching" and "recently watched" lists.
 *
 * @author Phlex Team
 * @version 1.0.0
 * @description Manages playback state persistence and progress tracking
 *              for session-based media playback in the Phlex Media Server.
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
        ?StatsCollector $statsCollector = null
    ) {
        $this->db = $db;
        $this->sessionManager = $sessionManager;
        $this->logger = $logger ?? $this->createDefaultLogger();
        $this->eventDispatcher = $eventDispatcher;
        $this->statsCollector = $statsCollector;
    }

    /**
     * Create a default logger for playback events.
     *
     * @return StructuredLogger Configured logger instance
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlex_playback_' . uniqid();
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
    public function reportProgress(string $sessionId, string $mediaItemId, int $positionTicks, int $durationTicks, bool $isPaused): void
    {
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

        return $result[0] ?? null;
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

        return $result[0] ?? null;
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
            "UPDATE playback_state SET playback_status = 'stopped', position_ticks = 0 WHERE session_id = ? AND media_item_id = ?",
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
     * Get items the user has in progress (continue watching).
     *
     * Returns media items that are currently being watched but not yet completed,
     * ordered by most recently watched.
     *
     * @param string $userId User UUID to get continue watching list for
     * @param int $limit Maximum number of items to return (default: 10)
     *
     * @return array<int, array<string, mixed>> Array of playback state records with media info
     *
     * @example
     * ```php
     * $continueWatching = $controller->getContinueWatching('user-uuid-123', 5);
     * foreach ($continueWatching as $item) {
     *     echo $item['name'] . " - " . $item['progress_percent'] . "% complete";
     * }
     * ```
     */
    public function getContinueWatching(string $userId, int $limit = 10): array
    {
        $result = $this->db->query(
            "SELECT ps.*, mi.name, mi.type, mi.metadata_json
             FROM playback_state ps
             INNER JOIN sessions s ON ps.session_id = s.id
             INNER JOIN media_items mi ON ps.media_item_id = mi.id
             WHERE s.user_id = ?
               AND ps.playback_status IN ('playing', 'paused')
               AND ps.position_ticks > 0
               AND ps.position_ticks < (ps.duration_ticks * 0.95)
             ORDER BY ps.updated_at DESC
             LIMIT ?",
            [$userId, $limit]
        );

        return array_map(function ($row) {
            $row['metadata'] = json_decode($row['metadata_json'] ?? '{}', true);
            return $row;
        }, $result);
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

        return array_map(function ($row) {
            $row['metadata'] = json_decode($row['metadata_json'] ?? '{}', true);
            return $row;
        }, $result);
    }

    /**
     * Generate a UUID v4 string.
     *
     * @return string UUID in standard format
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
        if (!is_array($result) || $result === []) {
            return null;
        }
        $first = $result[0] ?? null;
        return is_array($first) ? $first : null;
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
     * @return \Phlex\Dlna\PlayToSession|null New play-to session or null on failure
     *
     * @since 0.12.0
     */
    public function startPlayToSession(
        string $sessionId,
        string $mediaItemId,
        string $rendererId,
        string $streamUrl,
        string $metadata = ''
    ): ?\Phlex\Dlna\PlayToSession {
        try {
            // Get the PlayToManager from container if available
            $container = \Phlex\Common\Container\ContainerFactory::getInstance();
            if ($container === null || !$container->has(\Phlex\Dlna\PlayToManager::class)) {
                $this->logger->warning('PlayToManager not available in container');
                return null;
            }

            /** @var \Phlex\Dlna\PlayToManager */
            $playToManager = $container->get(\Phlex\Dlna\PlayToManager::class);

            $session = $playToManager->startSession($rendererId, $mediaItemId, $streamUrl, $metadata);

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
