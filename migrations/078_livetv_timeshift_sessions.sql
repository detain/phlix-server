-- Migration: 078_livetv_timeshift_sessions.sql
-- Description: DB-backed store for Live TV time-shift (pause/rewind live) sessions.
--
-- Problem (SV-3.1 f, findings S-F6 / S-I1): time-shift state lived ONLY in
-- Recorder::$activeTimeShifts — a per-worker in-memory array. Under Workerman's
-- resident-memory multi-worker model a session started on worker N is invisible
-- to a `/livetv/timeshift/{session}/stream` request routed to worker M, so the
-- stream 404s. This table gives every worker a shared, authoritative view of
-- each rolling on-disk time-shift buffer (mirrors the project's existing
-- DB-backed state-store pattern used for oauth_state_store / login_rate_limit).
--
-- The buffer-writer sub-step (f-b) fills `buffer_dir` with the rolling capture
-- and records the detached ffmpeg `pid`; the stream controller sub-step (f-c)
-- resolves a session by id / session_id and serves from `buffer_dir`.
--
-- Conventions mirror `livetv_recordings` (012a): CHAR(36) UUID key, epoch
-- INT UNSIGNED timestamps to match Recorder.php's time()-based bookkeeping
-- (buffer_start / buffer_end in $activeTimeShifts), VARCHAR(512) paths, a
-- VARCHAR(32) status, DATETIME created/updated with ON UPDATE, InnoDB utf8mb4.
--
-- Idempotent via `CREATE TABLE IF NOT EXISTS` (valid on both MySQL 8 and
-- MariaDB, unlike `IF NOT EXISTS` on ADD COLUMN / ADD INDEX); the SV-4.9
-- migration ledger additionally skips this file once its checksum is recorded.

CREATE TABLE IF NOT EXISTS livetv_timeshift_sessions (
    id                CHAR(36)     NOT NULL,
    session_id        VARCHAR(64)  NOT NULL,
    channel_id        CHAR(36)     NOT NULL,
    buffer_dir        VARCHAR(512) NOT NULL,
    pid               INT          NULL,
    buffer_start_at   INT UNSIGNED NOT NULL,
    buffer_end_at     INT UNSIGNED NOT NULL,
    window_seconds    INT UNSIGNED NOT NULL DEFAULT 7200,
    cursor_position   INT          NOT NULL DEFAULT 0,
    status            VARCHAR(32)  NOT NULL DEFAULT 'active',
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    -- session_id is the URL route key and must be a genuine upsert key: a
    -- re-started time-shift for the same playback session overwrites the prior
    -- row (ON DUPLICATE KEY UPDATE on this UNIQUE constraint) instead of leaving
    -- a second row that only dedupes on the random PK `id`. Also serves as the
    -- lookup index for findBySessionId / reapBySessionId.
    UNIQUE KEY uq_session_id (session_id),
    INDEX idx_channel_id (channel_id),
    INDEX idx_status     (status),
    INDEX idx_pid        (pid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
