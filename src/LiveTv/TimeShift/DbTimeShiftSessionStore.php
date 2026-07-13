<?php

/**
 * Phlix media server component: TimeShift.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\LiveTv\TimeShift;

use Phlix\Common\Util\RowMap;
use Workerman\MySQL\Connection;

/**
 * Database-backed store for Live TV time-shift sessions.
 *
 * Persists {@see TimeShiftSession} rows to the `livetv_timeshift_sessions` table
 * (migration 078) so any Workerman worker can resolve any session — replacing
 * Recorder::$activeTimeShifts, which was per-worker in-memory and therefore
 * invisible to a stream request routed to a different worker (S-F6 / S-I1).
 *
 * Follows the project's DB-backed state-store idiom (cf. BookProgressStore,
 * DbLoginRateLimitStore, DbTraktOAuthStateStore): a single injected
 * Workerman\MySQL\Connection, positional `?` binds, and CRUD only — no business
 * logic (the buffer writer f-b and stream controller f-c own that).
 *
 * @since 0.45.0
 * @see TimeShiftSession For the value object
 */
final class DbTimeShiftSessionStore
{
    /** @var Connection Database connection */
    private Connection $db;

    /**
     * @param Connection $db Workerman MySQL connection instance
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Insert a session, or update it in place if the id already exists.
     *
     * `updated_at` is refreshed automatically by the column's
     * `ON UPDATE CURRENT_TIMESTAMP` default; `created_at` is preserved.
     *
     * @param TimeShiftSession $session The session to persist
     * @return void
     */
    public function save(TimeShiftSession $session): void
    {
        $this->db->query(
            "INSERT INTO livetv_timeshift_sessions
                (id, session_id, channel_id, buffer_dir, pid,
                 buffer_start_at, buffer_end_at, window_seconds,
                 cursor_position, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                session_id = VALUES(session_id),
                channel_id = VALUES(channel_id),
                buffer_dir = VALUES(buffer_dir),
                pid = VALUES(pid),
                buffer_start_at = VALUES(buffer_start_at),
                buffer_end_at = VALUES(buffer_end_at),
                window_seconds = VALUES(window_seconds),
                cursor_position = VALUES(cursor_position),
                status = VALUES(status)",
            [
                $session->id,
                $session->session_id,
                $session->channel_id,
                $session->buffer_dir,
                $session->pid,
                $session->buffer_start_at,
                $session->buffer_end_at,
                $session->window_seconds,
                $session->cursor_position,
                $session->status,
            ]
        );
    }

    /**
     * Find a session by its primary id.
     *
     * @param string $id The time-shift session id
     * @return TimeShiftSession|null The session, or null if not found
     */
    public function findById(string $id): ?TimeShiftSession
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_timeshift_sessions WHERE id = ?",
            [$id]
        );

        return $this->hydrateFirst($result);
    }

    /**
     * Find the most recent session for a playback session id (the URL route
     * key). Ordered newest-first so a re-started session wins over any stale row.
     *
     * @param string $sessionId The owning playback session id
     * @return TimeShiftSession|null The session, or null if none exists
     */
    public function findBySessionId(string $sessionId): ?TimeShiftSession
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_timeshift_sessions
             WHERE session_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT 1",
            [$sessionId]
        );

        return $this->hydrateFirst($result);
    }

    /**
     * Update the playback cursor position for a session.
     *
     * @param string $id             The time-shift session id
     * @param int    $cursorPosition Playback cursor offset within the buffer (seconds)
     * @return void
     */
    public function updateCursor(string $id, int $cursorPosition): void
    {
        $this->db->query(
            "UPDATE livetv_timeshift_sessions SET cursor_position = ? WHERE id = ?",
            [$cursorPosition, $id]
        );
    }

    /**
     * Update the rolling buffer window bounds as capture advances / trims.
     *
     * @param string $id            The time-shift session id
     * @param int    $bufferStartAt Epoch of the oldest buffered second
     * @param int    $bufferEndAt   Epoch of the newest buffered second (live edge)
     * @return void
     */
    public function updateBufferWindow(string $id, int $bufferStartAt, int $bufferEndAt): void
    {
        $this->db->query(
            "UPDATE livetv_timeshift_sessions
             SET buffer_start_at = ?, buffer_end_at = ?
             WHERE id = ?",
            [$bufferStartAt, $bufferEndAt, $id]
        );
    }

    /**
     * Record (or clear) the detached ffmpeg capture PID for a session.
     *
     * @param string   $id  The time-shift session id
     * @param int|null $pid The ffmpeg child PID, or null to clear it
     * @return void
     */
    public function updatePid(string $id, ?int $pid): void
    {
        $this->db->query(
            "UPDATE livetv_timeshift_sessions SET pid = ? WHERE id = ?",
            [$pid, $id]
        );
    }

    /**
     * Update the lifecycle status of a session.
     *
     * @param string $id     The time-shift session id
     * @param string $status One of the TimeShiftSession::STATUS_* constants
     * @return void
     */
    public function updateStatus(string $id, string $status): void
    {
        $this->db->query(
            "UPDATE livetv_timeshift_sessions SET status = ? WHERE id = ?",
            [$status, $id]
        );
    }

    /**
     * Delete a session row.
     *
     * @param string $id The time-shift session id
     * @return void
     */
    public function delete(string $id): void
    {
        $this->db->query(
            "DELETE FROM livetv_timeshift_sessions WHERE id = ?",
            [$id]
        );
    }

    /**
     * List all currently active sessions (oldest first).
     *
     * @return list<TimeShiftSession>
     */
    public function listActive(): array
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_timeshift_sessions
             WHERE status = ?
             ORDER BY created_at ASC",
            [TimeShiftSession::STATUS_ACTIVE]
        );

        if (!is_array($result)) {
            return [];
        }

        $sessions = [];
        foreach (RowMap::listFromMixed($result) as $row) {
            $sessions[] = TimeShiftSession::fromRow($row);
        }

        return $sessions;
    }

    /**
     * Hydrate the first row of a query result into a session, or null.
     *
     * @param mixed $result Raw return of $db->query()
     * @return TimeShiftSession|null
     */
    private function hydrateFirst(mixed $result): ?TimeShiftSession
    {
        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            return null;
        }

        return TimeShiftSession::fromRow(RowMap::fromMixed($result[0]));
    }
}
