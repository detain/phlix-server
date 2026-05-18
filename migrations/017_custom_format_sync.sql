-- Migration: 017_custom_format_sync.sql
-- Step K.4: TRaSH-Guides custom format sync
-- Creates tables for tracking TRaSH-Guides sync state and history

CREATE TABLE IF NOT EXISTS custom_format_sync (
    id CHAR(36) PRIMARY KEY,
    sync_type VARCHAR(50) NOT NULL COMMENT 'custom_format or quality_profile',
    remote_id INT NOT NULL COMMENT 'CRC32 of name for idempotent matching',
    remote_name VARCHAR(255) NOT NULL,
    trash_version VARCHAR(40) NOT NULL COMMENT 'Git commit SHA of synced version',
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_type_remote (sync_type, remote_id),
    INDEX idx_sync_type (sync_type),
    INDEX idx_trash_version (trash_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trash_guides_sync_log (
    id CHAR(36) PRIMARY KEY,
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    custom_formats_added INT DEFAULT 0,
    custom_formats_updated INT DEFAULT 0,
    quality_profiles_added INT DEFAULT 0,
    quality_profiles_updated INT DEFAULT 0,
    version VARCHAR(40) NOT NULL COMMENT 'Git commit SHA',
    error_message TEXT,
    INDEX idx_synced_at (synced_at),
    INDEX idx_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
