-- Migration: 111_casting_sessions.sql
--
-- Device-M1: DB-shared casting sessions.
--
-- The four casting managers (DLNA PlayTo, Roku, Chromecast, AirPlay) kept their
-- session maps in worker-local process memory. The HTTP pool runs count=14, so
-- a cast START could land on worker A while the follow-up pause/stop/status hit
-- worker B and see "no active session" — a spurious 404 for a session that very
-- much exists. This table is the cross-worker source of truth the managers now
-- consult on a local-map miss: the row carries everything needed to rebuild a
-- control client for the same device (every casting transport in this repo is
-- stateless-per-command: DLNA SOAP POST, Roku ECP HTTP, Cast HTTP JSON, RAOP
-- fresh-socket-per-command), plus the owning user for authorization.
--
-- Schema notes (MySQL 5.7-compatible house style, cf. 106_client_heartbeats.sql):
-- - `state` is JSON WITHOUT a DEFAULT (5.7 forbids JSON defaults); the store
--   always writes it. It holds only what re-attach needs — device endpoint
--   fields and the media URL — never tokens or secrets.
-- - DATETIME columns default to the database clock (`CURRENT_TIMESTAMP`); the
--   store writes `last_seen_at = NOW()` explicitly on every touch, so the
--   default is a safety net, not the mechanism.
-- - `uk_type_device` mirrors the managers' device-keyed maps: at most one live
--   casting session per device per class, which is exactly the start-path
--   REPLACE semantics the local maps already had.
-- - `ix_casting_sessions_last_seen_at` backs the bounded stale-row sweep the
--   store runs lazily on find/insert.

CREATE TABLE IF NOT EXISTS casting_sessions (
    session_id CHAR(36) NOT NULL,
    type VARCHAR(16) NOT NULL,
    device_id VARCHAR(191) NOT NULL,
    user_id CHAR(36) NOT NULL,
    state JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (session_id),
    UNIQUE KEY uk_type_device (type, device_id),
    KEY ix_casting_sessions_last_seen_at (last_seen_at),
    KEY ix_casting_sessions_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
