<?php

/**
 * Phlix media server component: Access.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Access;

use Workerman\MySQL\Connection;

/**
 * Service for managing stream sessions and enforcing per-profile concurrent stream limits.
 *
 * Provides methods to register, heartbeat, and release stream sessions.
 * Automatically cleans up stale streams that have missed heartbeats.
 *
 * @package Phlix\Access
 */
final class StreamSessionService
{
    /**
     * Create a new StreamSessionService instance.
     *
     * @param Connection $db Database connection for accessing stream session data.
     */
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * Register a new stream session for a profile.
     *
     * Checks if the profile's concurrent stream limit would be exceeded.
     * If a stream record already exists for the same session_id, it returns true (idempotent).
     *
     * @param int    $profileId  The profile ID to register the stream for.
     * @param string $deviceId   The device identifier.
     * @param string $sessionId The streaming session identifier.
     *
     * @return bool True if registration succeeded (stream started or already exists),
     *             false if the concurrent stream limit has been reached.
     */
    public function registerStream(int $profileId, string $deviceId, string $sessionId): bool
    {
        // Check if stream already exists for this session (idempotent)
        /** @var array<array<string, mixed>> $existingRows */
        $existingRows = $this->db->query(
            'SELECT 1 FROM active_streams WHERE profile_id = ? AND session_id = ? LIMIT 1',
            [$profileId, $sessionId],
        );

        if (is_array($existingRows) && count($existingRows) > 0) {
            // Stream already registered, refresh heartbeat
            $this->heartbeat($sessionId);
            return true;
        }

        // Get the profile's stream limit
        $limit = $this->getStreamLimit($profileId);
        $currentCount = $this->getActiveStreamCount($profileId);

        if ($currentCount >= $limit->maxConcurrentStreams) {
            return false;
        }

        // Clean up any stale streams first (heartbeat missed)
        $this->cleanupStaleStreams();

        // Re-check count after cleanup
        $currentCount = $this->getActiveStreamCount($profileId);
        if ($currentCount >= $limit->maxConcurrentStreams) {
            return false;
        }

        // Insert new stream record
        $this->db->query(
            'INSERT INTO active_streams (profile_id, device_id, session_id, stream_type)'
            . ' VALUES (?, ?, ?, ?)',
            [$profileId, $deviceId, $sessionId, 'direct'],
        );

        return true;
    }

    /**
     * Update the heartbeat timestamp for a stream session.
     *
     * @param string $sessionId The streaming session identifier.
     *
     * @return void
     */
    public function heartbeat(string $sessionId): void
    {
        $this->db->query(
            'UPDATE active_streams SET last_heartbeat_at = NOW() WHERE session_id = ?',
            [$sessionId],
        );
    }

    /**
     * Release (remove) a stream session.
     *
     * @param string $sessionId The streaming session identifier.
     *
     * @return void
     */
    public function releaseStream(string $sessionId): void
    {
        $this->db->query(
            'DELETE FROM active_streams WHERE session_id = ?',
            [$sessionId],
        );
    }

    /**
     * Get the number of currently active streams for a profile.
     *
     * @param int $profileId The profile ID to check.
     *
     * @return int The count of active streams.
     */
    public function getActiveStreamCount(int $profileId): int
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT COUNT(*) as cnt FROM active_streams WHERE profile_id = ?',
            [$profileId],
        );

        if (!is_array($rows) || $rows === [] || !is_array($rows[0])) {
            return 0;
        }

        /** @var array<string, mixed> $firstRow */
        $firstRow = $rows[0];
        return isset($firstRow['cnt']) && is_numeric($firstRow['cnt']) ? (int) $firstRow['cnt'] : 0;
    }

    /**
     * Clean up streams with stale heartbeats (no heartbeat for > 60 seconds).
     *
     * @return int The number of stale streams removed.
     */
    public function cleanupStaleStreams(): int
    {
        $result = $this->db->query(
            'DELETE FROM active_streams WHERE last_heartbeat_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)',
        );

        return $result !== false ? 1 : 0;
    }

    /**
     * Get the stream limit for a profile.
     *
     * If no limit is configured, returns a default limit of 1 concurrent stream.
     *
     * @param int $profileId The profile ID to get the limit for.
     *
     * @return ProfileStreamLimit The stream limit configuration.
     */
    public function getStreamLimit(int $profileId): ProfileStreamLimit
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM profile_stream_limits WHERE profile_id = ?',
            [$profileId],
        );

        if (!is_array($rows) || $rows === [] || !is_array($rows[0])) {
            // Default limit if none configured
            return new ProfileStreamLimit(profileId: $profileId, maxConcurrentStreams: 1);
        }

        /** @var array<string, mixed> $firstRow */
        $firstRow = $rows[0];
        return ProfileStreamLimit::fromRow($firstRow);
    }

    /**
     * Update the stream limit for a profile.
     *
     * @param int      $profileId            The profile ID to update.
     * @param int      $maxConcurrentStreams Maximum concurrent streams.
     * @param int|null $maxTotalBandwidthKbps Maximum total bandwidth in kbps, or null for unlimited.
     *
     * @return bool True if the limit was updated.
     */
    public function updateStreamLimit(int $profileId, int $maxConcurrentStreams, ?int $maxTotalBandwidthKbps): bool
    {
        $result = $this->db->query(
            'INSERT INTO profile_stream_limits (profile_id, max_concurrent_streams, max_total_bandwidth_kbps)'
            . ' VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE max_concurrent_streams = VALUES(max_concurrent_streams),'
            . ' max_total_bandwidth_kbps = VALUES(max_total_bandwidth_kbps)',
            [$profileId, $maxConcurrentStreams, $maxTotalBandwidthKbps],
        );

        return $result !== false;
    }

    /**
     * Get all active streams for a profile.
     *
     * @param int $profileId The profile ID to get streams for.
     *
     * @return list<array{
     *     id: int,
     *     profile_id: int,
     *     device_id: string,
     *     session_id: string,
     *     started_at: string,
     *     last_heartbeat_at: string,
     *     stream_type: string
     * }>
     */
    public function getActiveStreamsForProfile(int $profileId): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM active_streams WHERE profile_id = ? ORDER BY started_at DESC',
            [$profileId],
        );

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $streams = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $streams[] = [
                    'id' => isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0,
                    'profile_id' => isset($row['profile_id']) && is_numeric($row['profile_id']) ? (int) $row['profile_id'] : 0,
                    'device_id' => isset($row['device_id']) && is_string($row['device_id']) ? $row['device_id'] : '',
                    'session_id' => isset($row['session_id']) && is_string($row['session_id']) ? $row['session_id'] : '',
                    'started_at' => isset($row['started_at']) && is_string($row['started_at']) ? $row['started_at'] : '',
                    'last_heartbeat_at' => isset($row['last_heartbeat_at']) && is_string($row['last_heartbeat_at']) ? $row['last_heartbeat_at'] : '',
                    'stream_type' => isset($row['stream_type']) && is_string($row['stream_type']) ? $row['stream_type'] : 'direct',
                ];
            }
        }

        return $streams;
    }

    /**
     * Check if a session exists for a given session ID.
     *
     * @param string $sessionId The session ID to check.
     *
     * @return bool True if a stream with this session ID exists.
     */
    public function sessionExists(string $sessionId): bool
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT 1 FROM active_streams WHERE session_id = ? LIMIT 1',
            [$sessionId],
        );

        return is_array($rows) && count($rows) > 0;
    }
}