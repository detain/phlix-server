-- Migration: 015_livetv_relay_sessions.sql
-- Step I.7: HLS re-streaming via hub relay for remote live TV
-- Creates table for managing HLS relay sessions

CREATE TABLE IF NOT EXISTS livetv_relay_sessions (
    session_id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    channel_id CHAR(36) NOT NULL,
    tune_request_id CHAR(36) NOT NULL,
    mount_url VARCHAR(512) NOT NULL,
    started_at DATETIME NOT NULL,
    last_activity_at DATETIME NOT NULL,
    bytes_relayed BIGINT NOT NULL DEFAULT 0,
    INDEX idx_user_id (user_id),
    INDEX idx_started_at (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
