<?php

/**
 * Phlix media server component: Access.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Access;

use Phlix\Admin\SettingsRepository;
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
     * Interval, in seconds, after which a pending heartbeat timer fires and
     * refreshes the session's last_heartbeat_at timestamp.
     */
    private const HEARTBEAT_INTERVAL_SECONDS = 30;

    /**
     * Pending heartbeat timer ids keyed by stream session id.
     *
     * At most ONE entry exists per active session: registration is deduped so a
     * burst of stream requests for the same session (e.g. every HLS segment)
     * does not create a timer per request. Each timer is one-shot — when it
     * fires it clears its own slot so the next request re-arms it (refresh). This
     * bounds the live timer count to the number of sessions with activity in the
     * last {@see HEARTBEAT_INTERVAL_SECONDS} seconds rather than growing per
     * request.
     *
     * Teardown in the resident HTTP path is TIMEOUT-DRIVEN, not event-driven: the
     * streaming path has no stream-end signal (the client just stops requesting),
     * so the last one-shot timer self-clears ~{@see HEARTBEAT_INTERVAL_SECONDS}
     * after the final request and the 60s {@see cleanupStaleStreams()} sweep
     * removes the DB row. {@see releaseStream()} additionally cancels a pending
     * timer for the (currently test-only) callers that DO have an explicit
     * stream-end signal.
     *
     * @var array<string, int>
     */
    private array $heartbeatTimerIds = [];

    /**
     * Create a new StreamSessionService instance.
     *
     * @param Connection $db Database connection for accessing stream session data.
     */
    /**
     * The dotted settings key backing {@see self::defaultConcurrentStreams()}.
     */
    public const DEFAULT_STREAMS_SETTING_KEY = 'access.default_concurrent_streams';

    /**
     * Shipped per-profile concurrent-stream allowance.
     *
     * Nothing writes a `profile_stream_limits` row at profile creation — the
     * only writer is {@see self::updateStreamLimit()}, reached solely from the
     * admin API — so this fallback is what EVERY profile an admin has not
     * explicitly configured actually runs on. That makes
     * `access.default_concurrent_streams` a live, server-wide control rather
     * than a creation-time seed.
     */
    public const DEFAULT_CONCURRENT_STREAMS = 1;

    /**
     * Hard floor. A configured 0 would deny playback to every profile without
     * an explicit override, i.e. almost all of them.
     */
    public const MIN_CONCURRENT_STREAMS = 1;

    /**
     * Hard ceiling. Concurrent streams are the transcode/bandwidth budget;
     * this bounds what a single mis-typed value can commit the server to.
     */
    public const MAX_CONCURRENT_STREAMS = 100;

    /**
     * @param Connection              $db       Database connection.
     * @param SettingsRepository|null $settings Effective-settings store. NULL
     *        degrades to {@see self::DEFAULT_CONCURRENT_STREAMS}.
     *
     *        NOTE for DI: PHP-DI skips optional constructor parameters during
     *        autowiring. This class has TWO construction paths — the container
     *        and a direct `new` in `Application::getStreamLimitController()`'s
     *        no-container fallback — and both pass this explicitly. Wiring only
     *        one would make the setting apply on some requests and not others.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly ?SettingsRepository $settings = null,
    ) {
    }

    /**
     * The effective default concurrent-stream allowance for a profile with no
     * explicit `profile_stream_limits` row.
     *
     * Read-path class (a) LIVE: resolved per playback, no restart.
     *
     * @return int Between {@see self::MIN_CONCURRENT_STREAMS} and
     *         {@see self::MAX_CONCURRENT_STREAMS} inclusive.
     *
     * @since 1.3.0
     */
    public function defaultConcurrentStreams(): int
    {
        if ($this->settings === null) {
            return self::DEFAULT_CONCURRENT_STREAMS;
        }

        try {
            /** @var mixed $configured */
            $configured = $this->settings->getEffective(self::DEFAULT_STREAMS_SETTING_KEY);
        } catch (\Throwable) {
            // A settings-store failure must not silently lift the concurrency
            // cap, and must not drop it to zero and block all playback.
            return self::DEFAULT_CONCURRENT_STREAMS;
        }

        $value = match (true) {
            is_int($configured) => $configured,
            is_string($configured) && is_numeric($configured) => (int) $configured,
            default => self::DEFAULT_CONCURRENT_STREAMS,
        };

        return max(self::MIN_CONCURRENT_STREAMS, min(self::MAX_CONCURRENT_STREAMS, $value));
    }

    /**
     * Register a new stream session for a profile.
     *
     * Checks if the profile's concurrent stream limit would be exceeded.
     * If a stream record already exists for the same session_id, it returns true (idempotent).
     *
     * @param string $profileId  The profile ID (UUID) to register the stream for.
     * @param string $deviceId   The device identifier.
     * @param string $sessionId The streaming session identifier.
     *
     * @return bool True if registration succeeded (stream started or already exists),
     *             false if the concurrent stream limit has been reached.
     */
    public function registerStream(string $profileId, string $deviceId, string $sessionId): bool
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
     * Also tears down any pending heartbeat timer for the session so no timer
     * outlives the stream it serves. This is the EXPLICIT teardown path, invoked
     * by callers that have a genuine stream-end signal; the resident HTTP
     * streaming path has none, so there it relies on the one-shot timer's
     * self-clear plus the 60s {@see cleanupStaleStreams()} sweep instead.
     *
     * @param string $sessionId The streaming session identifier.
     *
     * @return void
     */
    public function releaseStream(string $sessionId): void
    {
        $this->cancelHeartbeatTimer($sessionId);

        $this->db->query(
            'DELETE FROM active_streams WHERE session_id = ?',
            [$sessionId],
        );
    }

    /**
     * Register (or refresh) the one-shot heartbeat timer for a stream session.
     *
     * Callers invoke this on every stream request. Registration is deduped by
     * session id: if a heartbeat timer is already pending for the session this
     * is a no-op, so a burst of requests (e.g. HLS segment fetches) yields at
     * most ONE timer per session instead of one per request. The timer is
     * one-shot; when it fires it clears its own slot so the next request re-arms
     * it. In the resident HTTP path there is no stream-end signal, so a session's
     * last timer self-clears ~{@see HEARTBEAT_INTERVAL_SECONDS} after its final
     * request (teardown is timeout-driven); {@see releaseStream()} cancels a
     * pending timer for callers that have an explicit stream-end signal.
     *
     * No-op outside a Workerman runtime (the Timer class is unavailable).
     *
     * @param string $sessionId The streaming session identifier.
     *
     * @return void
     */
    public function registerHeartbeatTimer(string $sessionId): void
    {
        if (!class_exists(\Workerman\Timer::class)) {
            return;
        }

        // Dedup: at most one pending heartbeat timer per active session.
        if (isset($this->heartbeatTimerIds[$sessionId])) {
            return;
        }

        $timerId = \Workerman\Timer::add(
            self::HEARTBEAT_INTERVAL_SECONDS,
            function () use ($sessionId): void {
                $this->onHeartbeatTimerFired($sessionId);
            },
            [],
            false, // one-shot
        );

        if ($timerId > 0) {
            $this->heartbeatTimerIds[$sessionId] = $timerId;
        }
    }

    /**
     * Handle a one-shot heartbeat timer firing for a session.
     *
     * Clears the session's timer slot (so the next stream request re-arms a
     * fresh one-shot timer) and refreshes the session heartbeat. Exposed for the
     * timer callback and for deterministic testing of the one-shot self-clear.
     *
     * @param string $sessionId The streaming session identifier.
     *
     * @return void
     */
    public function onHeartbeatTimerFired(string $sessionId): void
    {
        unset($this->heartbeatTimerIds[$sessionId]);
        $this->heartbeat($sessionId);
    }

    /**
     * Cancel and remove any pending heartbeat timer for a session.
     *
     * @param string $sessionId The streaming session identifier.
     *
     * @return void
     */
    public function cancelHeartbeatTimer(string $sessionId): void
    {
        $timerId = $this->heartbeatTimerIds[$sessionId] ?? null;
        if ($timerId === null) {
            return;
        }

        unset($this->heartbeatTimerIds[$sessionId]);

        if (class_exists(\Workerman\Timer::class)) {
            \Workerman\Timer::del($timerId);
        }
    }

    /**
     * Number of pending (registered, not-yet-fired, not-cancelled) heartbeat
     * timers. Used to assert the per-session timer accounting stays bounded.
     *
     * @return int Count of active heartbeat timers.
     */
    public function activeHeartbeatTimerCount(): int
    {
        return count($this->heartbeatTimerIds);
    }

    /**
     * Get the number of currently active streams for a profile.
     *
     * @param string $profileId The profile ID (UUID) to check.
     *
     * @return int The count of active streams.
     */
    public function getActiveStreamCount(string $profileId): int
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
     * ⚠ **S131 — the documented return was a lie, and it is a DELETE, not an
     * insert.** This read `return $result !== false ? 1 : 0;`, which reported
     * `1` for every sweep including one that removed nothing, and could never
     * report `0` at all — the client has no `return false`. It is also not an
     * insert result, so the {@see \Phlix\Common\Database\WriteResult} helper
     * does not apply: for a `delete` the client returns `rowCount()`, an
     * **int**, and `0` there means "ran, matched nothing", which is a
     * different question from "wrote nothing because the write did not happen".
     * The `is_int()` narrowing keeps the `null` shape
     * ({@see \Phlix\Common\Database\WriteResult} trap 3 — a reformat that hides
     * the leading `DELETE` keyword) reported as `0` rather than crashing.
     *
     * @return int The number of stale streams removed.
     */
    public function cleanupStaleStreams(): int
    {
        $result = $this->db->query(
            'DELETE FROM active_streams WHERE last_heartbeat_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)',
        );

        return is_int($result) ? $result : 0;
    }

    /**
     * Get the stream limit for a profile.
     *
     * If no limit is configured, returns a default limit of 1 concurrent stream.
     *
     * @param string $profileId The profile ID (UUID) to get the limit for.
     *
     * @return ProfileStreamLimit The stream limit configuration.
     */
    public function getStreamLimit(string $profileId): ProfileStreamLimit
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM profile_stream_limits WHERE profile_id = ?',
            [$profileId],
        );

        if (!is_array($rows) || $rows === [] || !is_array($rows[0])) {
            // No explicit row — the common case, since nothing seeds one at
            // profile creation. Falls back to the effective
            // `access.default_concurrent_streams`.
            return new ProfileStreamLimit(
                profileId: $profileId,
                maxConcurrentStreams: $this->defaultConcurrentStreams(),
            );
        }

        /** @var array<string, mixed> $firstRow */
        $firstRow = $rows[0];
        return ProfileStreamLimit::fromRow($firstRow);
    }

    /**
     * Update the stream limit for a profile.
     *
     * ## S131 — this is the one insert-result site where the strict helper is the WRONG predicate
     *
     * This method used to end `return $result !== false;`. That was dead code:
     * `Workerman\MySQL\Connection::query()` has no `return false` at all and
     * signals a failed write by THROWING, so the expression was always `true`.
     * Returning `true` unconditionally is therefore byte-for-byte the same
     * behaviour, with the contract stated instead of implied.
     *
     * ⚠ **Do NOT "finish the job" by writing
     * `return !WriteResult::wroteNothing($result);`.** It looks like the
     * obvious completion of S131 and it is a REGRESSION. Both falsy shapes
     * {@see \Phlix\Common\Database\WriteResult::wroteNothing()} tests for are
     * SUCCESS here:
     *
     *  - **`null`** — an `INSERT … ON DUPLICATE KEY UPDATE` whose values are
     *    already current affects **0 rows**, and the client answers `null`
     *    (measured against real MySQL 8, S96 review r3). The row already says
     *    exactly what the caller asked for; reporting that as a failed update
     *    is wrong.
     *  - **`'0'`** — on a row that WAS written, `profile_stream_limits` has a
     *    `CHAR(36)` PRIMARY KEY and no `AUTO_INCREMENT`
     *    (`migrations/063_device_stream_limits.sql:6-11`), so
     *    `lastInsertId()` returns the string `'0'`, which is FALSY in PHP. A
     *    truthiness test here reads a successful write as a failure.
     *
     * Both shapes are pinned in `tests/Unit/Access/StreamSessionServiceTest.php`.
     *
     * @param string   $profileId            The profile ID (UUID) to update.
     * @param int      $maxConcurrentStreams Maximum concurrent streams.
     * @param int|null $maxTotalBandwidthKbps Maximum total bandwidth in kbps, or null for unlimited.
     *
     * @return bool Always true. The write either succeeded or threw; there is
     *              no failure value for this client to return.
     */
    public function updateStreamLimit(string $profileId, int $maxConcurrentStreams, ?int $maxTotalBandwidthKbps): bool
    {
        $this->db->query(
            'INSERT INTO profile_stream_limits (profile_id, max_concurrent_streams, max_total_bandwidth_kbps)'
            . ' VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE max_concurrent_streams = VALUES(max_concurrent_streams),'
            . ' max_total_bandwidth_kbps = VALUES(max_total_bandwidth_kbps)',
            [$profileId, $maxConcurrentStreams, $maxTotalBandwidthKbps],
        );

        return true;
    }

    /**
     * Get all active streams for a profile.
     *
     * @param string $profileId The profile ID (UUID) to get streams for.
     *
     * @return list<array{
     *     id: int,
     *     profile_id: string,
     *     device_id: string,
     *     session_id: string,
     *     started_at: string,
     *     last_heartbeat_at: string,
     *     stream_type: string
     * }>
     */
    public function getActiveStreamsForProfile(string $profileId): array
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
                    'profile_id' => isset($row['profile_id']) && is_string($row['profile_id']) ? $row['profile_id'] :
                        '',
                    'device_id' => isset($row['device_id']) && is_string($row['device_id']) ? $row['device_id'] : '',
                    'session_id' => isset($row['session_id']) && is_string($row['session_id']) ? $row['session_id'] :
                        '',
                    'started_at' => isset($row['started_at']) && is_string($row['started_at']) ? $row['started_at'] :
                        '',
                    'last_heartbeat_at' => isset($row['last_heartbeat_at']) && is_string($row['last_heartbeat_at']) ?
                        $row['last_heartbeat_at'] : '',
                    'stream_type' => isset($row['stream_type']) && is_string($row['stream_type']) ?
                        $row['stream_type'] : 'direct',
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
