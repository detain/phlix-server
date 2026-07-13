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
     * Insert a session, or update the existing row in place on a key collision.
     *
     * A genuine upsert keyed on BOTH unique keys: the PK `id` and the
     * `UNIQUE (session_id)` constraint (migration 078). Re-saving a fresh row
     * whose `session_id` already exists therefore overwrites that session's row
     * (updating buffer_dir / pid / window / status / times) rather than leaking a
     * second row that only dedupes on the random PK. The PK `id` is deliberately
     * NOT in the update set-list (it identifies the row and must not be rewritten
     * on a session_id collision); `updated_at` is bumped explicitly so it advances
     * even when the column's `ON UPDATE CURRENT_TIMESTAMP` would not fire on an
     * otherwise-identical row; `created_at` is preserved.
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
                channel_id = VALUES(channel_id),
                buffer_dir = VALUES(buffer_dir),
                pid = VALUES(pid),
                buffer_start_at = VALUES(buffer_start_at),
                buffer_end_at = VALUES(buffer_end_at),
                window_seconds = VALUES(window_seconds),
                cursor_position = VALUES(cursor_position),
                status = VALUES(status),
                updated_at = CURRENT_TIMESTAMP",
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
     * Atomically CLAIM a session_id by inserting a fresh null-pid row.
     *
     * Unlike {@see save()} — an upsert that silently overwrites a colliding row —
     * this is a PLAIN `INSERT`, so a second concurrent `startTimeShift()` for the
     * SAME session_id can be DETECTED rather than clobbering the winner's row.
     * Exactly one caller can win the `UNIQUE (session_id)` INSERT (migration 078);
     * every other caller sees the collision and MUST abort without spawning a
     * second capture. This closes the two-live-ffmpeg / orphaned-pid window that a
     * silent upsert (which hands the surviving row a different `id` than the fresh
     * caller then tries to `updatePid()`) would otherwise leave.
     *
     * On a duplicate-key collision the driver raises an exception; we re-resolve by
     * session_id and, if a row now exists, report the loss (`false`) rather than
     * surfacing the error. Any OTHER failure (no row appeared → not a collision) is
     * re-thrown so the caller's failure-safe path can best-effort spawn.
     *
     * @param TimeShiftSession $session The fresh null-pid claim row to insert
     * @return bool True if THIS caller inserted the row (won the claim); false if a
     *              row for the session_id already existed (lost the race)
     * @throws \Throwable On any DB error that is NOT a duplicate-key collision
     */
    public function claim(TimeShiftSession $session): bool
    {
        try {
            $this->db->query(
                "INSERT INTO livetv_timeshift_sessions
                    (id, session_id, channel_id, buffer_dir, pid,
                     buffer_start_at, buffer_end_at, window_seconds,
                     cursor_position, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
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

            return true;
        } catch (\Throwable $e) {
            // Either a UNIQUE(session_id) collision (we lost the race) or a genuine
            // DB error. Re-resolve: if a row now exists for the session_id another
            // caller won the claim first — report the loss. Otherwise re-throw so
            // the caller treats it as a persist failure (best-effort spawn).
            if ($this->findBySessionId($session->session_id) !== null) {
                return false;
            }

            throw $e;
        }
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
     * Find the session for a playback session id (the URL route key).
     *
     * `session_id` is `UNIQUE` (migration 078), so this is a plain unique lookup —
     * no `ORDER BY created_at DESC LIMIT 1` tie-break is needed (that ordering was
     * unreliable anyway: two rows written in the same second could sort the older
     * one first). At most one row can exist per session_id.
     *
     * @param string $sessionId The owning playback session id
     * @return TimeShiftSession|null The session, or null if none exists
     */
    public function findBySessionId(string $sessionId): ?TimeShiftSession
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_timeshift_sessions WHERE session_id = ?",
            [$sessionId]
        );

        return $this->hydrateFirst($result);
    }

    /**
     * Return ALL rows for a playback session id so the caller can fully tear a
     * session down (terminate every capture pid + clean every buffer dir).
     *
     * With `UNIQUE (session_id)` this returns at most one row in normal operation,
     * but it stays list-shaped as defence-in-depth: a legacy/pre-migration row set
     * or a crash mid-write could leave more than one, and stopTimeShift must reap
     * every one rather than only the newest (which could orphan a running ffmpeg).
     *
     * @param string $sessionId The owning playback session id
     * @return list<TimeShiftSession> Every session row for this session_id (may be empty)
     */
    public function reapBySessionId(string $sessionId): array
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_timeshift_sessions WHERE session_id = ?",
            [$sessionId]
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
     * Record (or clear) the capture PID on the row that ACTUALLY survives for a
     * session_id — keyed on the `UNIQUE (session_id)` constraint, NOT a transient
     * row id.
     *
     * The two-phase persist (claim a null-pid row → spawn → record the pid) must
     * land the pid on the row that findBySessionId / getTimeShift will return. That
     * row is keyed on session_id, whose surviving `id` can differ from the fresh id
     * a caller generated (a colliding upsert keeps the pre-existing row's id).
     * Keying the pid write on session_id therefore guarantees it hits the resolved
     * row regardless of which id won, instead of matching zero rows and leaving the
     * real capture pid unpersisted (an unreapable tuner-holding orphan).
     *
     * @param string   $sessionId The owning playback session id (the upsert key)
     * @param int|null $pid       The ffmpeg child PID, or null to clear it
     * @return void
     */
    public function updatePidBySessionId(string $sessionId, ?int $pid): void
    {
        $this->db->query(
            "UPDATE livetv_timeshift_sessions SET pid = ? WHERE session_id = ?",
            [$pid, $sessionId]
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
