<?php

declare(strict_types=1);

namespace Phlex\Session;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Common\Util\RowMap;
use Workerman\MySQL\Connection;

/**
 * Session manager for device session lifecycle management.
 *
 * This class manages user device sessions including creation, retrieval,
 * activity tracking, and cleanup of stale sessions. Sessions track which
 * devices a user has authenticated from and their last activity time.
 *
 * @author Phlex Team
 * @version 1.0.0
 * @description Manages device sessions for multi-device support including
 *              session creation, tracking, and cleanup of stale sessions.
 * @see PlaybackController For playback session management
 *
 * @property Connection $db Database connection instance
 * @property array<string, array<string, mixed>> $activeSessions In-memory active session cache
 * @property StructuredLogger $logger Application logger
 */
class SessionManager
{
    /** @var Connection Database connection for MySQL queries */
    private Connection $db;

    /** @var array<string, array<string, mixed>> In-memory cache of active sessions indexed by session ID */
    private array $activeSessions = [];

    /** @var StructuredLogger Application logger for session events */
    private StructuredLogger $logger;

    /**
     * Create a new SessionManager instance.
     *
     * @param Connection $db Workerman MySQL connection instance
     * @param StructuredLogger|null $logger Optional application logger
     *
     * @example
     * ```php
     * $sessionManager = new SessionManager($dbConnection);
     * ```
     */
    public function __construct(Connection $db, ?StructuredLogger $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger ?? $this->createDefaultLogger();
    }

    /**
     * Create a default logger for session events.
     *
     * @return StructuredLogger Configured logger instance
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlex_session_' . uniqid();
        mkdir($tempDir, 0755, true);

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/session.log',
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
     * Create a new session for a device.
     *
     * If a session already exists for the device, returns the existing
     * session ID and updates its activity timestamp.
     *
     * @param string $userId User UUID who owns this session
     * @param string $deviceId Unique device identifier (e.g., device UUID)
     * @param string $deviceName Human-readable device name
     * @param string $deviceType Device type (e.g., 'mobile', 'desktop', 'tv')
     *
     * @return string Generated or existing session UUID
     *
     * @example
     * ```php
     * $sessionId = $sessionManager->createSession(
     *     'user-uuid-123',
     *     'device-uuid-456',
     *     'iPhone 15 Pro',
     *     'mobile'
     * );
     * ```
     */
    public function createSession(string $userId, string $deviceId, string $deviceName, string $deviceType): string
    {
        // Check if session already exists for this device
        $existing = $this->findByDeviceId($deviceId);
        if ($existing !== null) {
            $existingId = $existing['id'] ?? null;
            if (is_string($existingId) && $existingId !== '') {
                $this->updateActivity($existingId);
                return $existingId;
            }
        }

        $sessionId = $this->generateUuid();

        $this->db->query(
            "INSERT INTO sessions (id, user_id, device_id, device_name, device_type) VALUES (?, ?, ?, ?, ?)",
            [$sessionId, $userId, $deviceId, $deviceName, $deviceType]
        );

        $this->activeSessions[$sessionId] = [
            'id' => $sessionId,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'last_activity' => time(),
        ];

        $this->logger->info('Session created', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'device_id' => $deviceId,
        ]);

        return $sessionId;
    }

    /**
     * Get a session by ID.
     *
     * Checks in-memory cache first, then falls back to database.
     *
     * @param string $sessionId Session UUID to look up
     *
     * @return array<string, mixed>|null Session record or null if not found
     *
     * @example
     * ```php
     * $session = $sessionManager->getSession('session-uuid-123');
     * ```
     */
    public function getSession(string $sessionId): ?array
    {
        if (isset($this->activeSessions[$sessionId])) {
            return $this->activeSessions[$sessionId];
        }

        $result = $this->db->query(
            "SELECT * FROM sessions WHERE id = ?",
            [$sessionId]
        );

        $rows = RowMap::listFromMixed($result);
        return $rows[0] ?? null;
    }

    /**
     * Find a session by device ID.
     *
     * Returns the most recently active session for the device.
     *
     * @param string $deviceId Device UUID to look up
     *
     * @return array<string, mixed>|null Most recent session for device or null
     *
     * @example
     * ```php
     * $session = $sessionManager->findByDeviceId('device-uuid-456');
     * ```
     */
    public function findByDeviceId(string $deviceId): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM sessions WHERE device_id = ? ORDER BY last_activity DESC LIMIT 1",
            [$deviceId]
        );

        $rows = RowMap::listFromMixed($result);
        return $rows[0] ?? null;
    }

    /**
     * Get all sessions for a user.
     *
     * @param string $userId User UUID to get sessions for
     *
     * @return array<int, array<string, mixed>> Array of session records ordered by last activity
     *
     * @example
     * ```php
     * $sessions = $sessionManager->getUserSessions('user-uuid-123');
     * foreach ($sessions as $session) {
     *     echo $session['device_name'] . ' - last active: ' . $session['last_activity'];
     * }
     * ```
     */
    public function getUserSessions(string $userId): array
    {
        $result = $this->db->query(
            "SELECT * FROM sessions WHERE user_id = ? ORDER BY last_activity DESC",
            [$userId]
        );

        return RowMap::listFromMixed($result);
    }

    /**
     * Update session's last activity timestamp.
     *
     * @param string $sessionId Session UUID to update
     *
     * @return void
     *
     * @example
     * ```php
     * $sessionManager->updateActivity('session-uuid-123');
     * ```
     */
    public function updateActivity(string $sessionId): void
    {
        $this->db->query(
            "UPDATE sessions SET last_activity = NOW() WHERE id = ?",
            [$sessionId]
        );

        if (isset($this->activeSessions[$sessionId])) {
            $this->activeSessions[$sessionId]['last_activity'] = time();
        }
    }

    /**
     * End and delete a session.
     *
     * @param string $sessionId Session UUID to end
     *
     * @return void
     *
     * @example
     * ```php
     * $sessionManager->endSession('session-uuid-123');
     * ```
     */
    public function endSession(string $sessionId): void
    {
        $session = $this->getSession($sessionId);
        if ($session) {
            $this->db->query("DELETE FROM sessions WHERE id = ?", [$sessionId]);
            unset($this->activeSessions[$sessionId]);

            $this->logger->info('Session ended', ['session_id' => $sessionId]);
        }
    }

    /**
     * End all sessions for a user, optionally except one.
     *
     * Useful for "logout everywhere" functionality.
     *
     * @param string $userId User UUID to end sessions for
     * @param string|null $exceptSessionId Optional session ID to keep
     *
     * @return void
     *
     * @example
     * ```php
     * // End all sessions except current
     * $sessionManager->endAllUserSessions('user-uuid-123', $currentSessionId);
     *
     * // End ALL sessions
     * $sessionManager->endAllUserSessions('user-uuid-123');
     * ```
     */
    public function endAllUserSessions(string $userId, ?string $exceptSessionId = null): void
    {
        $sql = "DELETE FROM sessions WHERE user_id = ?";
        $params = [$userId];

        if ($exceptSessionId) {
            $sql .= " AND id != ?";
            $params[] = $exceptSessionId;
        }

        $this->db->query($sql, $params);

        $this->logger->info('All user sessions ended', [
            'user_id' => $userId,
            'except_session' => $exceptSessionId,
        ]);
    }

    /**
     * Clean up stale sessions older than max idle time.
     *
     * @param int $maxIdleSeconds Maximum idle time in seconds (default: 86400 = 24 hours)
     *
     * @return int Number of sessions cleaned up
     *
     * @example
     * ```php
     * $cleaned = $sessionManager->cleanupStaleSessions(86400);
     * echo "Cleaned up $cleaned stale sessions";
     * ```
     */
    public function cleanupStaleSessions(int $maxIdleSeconds = 86400): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - $maxIdleSeconds);

        $result = $this->db->query(
            "SELECT id FROM sessions WHERE last_activity < ?",
            [$cutoff]
        );

        $rows = RowMap::listFromMixed($result);
        $count = count($rows);

        if ($count > 0) {
            $this->db->query(
                "DELETE FROM sessions WHERE last_activity < ?",
                [$cutoff]
            );

            $this->logger->info('Cleaned up stale sessions', ['count' => $count]);
        }

        return $count;
    }

    /**
     * Get count of active in-memory sessions.
     *
     * Note: This only counts sessions in the in-memory cache, not total DB sessions.
     *
     * @return int Number of active cached sessions
     *
     * @example
     * ```php
     * $activeCount = $sessionManager->getActiveSessionCount();
     * ```
     */
    public function getActiveSessionCount(): int
    {
        return count($this->activeSessions);
    }

    /**
     * Get list of online user IDs.
     *
     * Users with activity within the last 5 minutes are considered online.
     *
     * @return array<int, string> Array of online user IDs
     *
     * @example
     * ```php
     * $onlineUsers = $sessionManager->getOnlineUsers();
     * echo "Users online: " . count($onlineUsers);
     * ```
     */
    public function getOnlineUsers(): array
    {
        $cutoff = date('Y-m-d H:i:s', time() - 300); // 5 minutes
        $result = $this->db->query(
            "SELECT DISTINCT user_id FROM sessions WHERE last_activity > ?",
            [$cutoff]
        );

        $rows = RowMap::listFromMixed($result);
        $userIds = [];
        foreach ($rows as $row) {
            $userId = $row['user_id'] ?? null;
            if (is_string($userId)) {
                $userIds[] = $userId;
            }
        }
        return $userIds;
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
}
