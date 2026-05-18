-- Migration: 019_stats_schema.sql
-- Step L.3: Stats schema + collectors
-- Creates tables for stats collection: playback events, library changes, user activity, storage

CREATE TABLE IF NOT EXISTS stats_playback_events (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    media_item_id CHAR(36) NOT NULL,
    media_type ENUM('movie','series','music','photo') NOT NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME,
    duration_seconds INT DEFAULT 0,
    device_id VARCHAR(255),
    client_ip VARCHAR(45),
    completed BOOLEAN DEFAULT FALSE,
    INDEX idx_user_started (user_id, started_at),
    INDEX idx_media_started (media_item_id, started_at),
    INDEX idx_started_at (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stats_library_changes (
    id CHAR(36) PRIMARY KEY,
    change_type ENUM('item_added','item_removed','metadata_updated') NOT NULL,
    media_item_id CHAR(36),
    library_id CHAR(36),
    user_id CHAR(36),
    changed_at DATETIME NOT NULL,
    details_json TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stats_user_activity (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    activity_type ENUM('login','logout','search','profile_change') NOT NULL,
    occurred_at DATETIME NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    details_json TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stats_storage (
    id CHAR(36) PRIMARY KEY,
    recorded_at DATETIME NOT NULL,
    library_id CHAR(36),
    media_type ENUM('movie','series','music','photo') NOT NULL,
    item_count INT DEFAULT 0,
    total_bytes BIGINT DEFAULT 0,
    transcode_cache_bytes BIGINT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
