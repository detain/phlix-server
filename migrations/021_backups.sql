-- Migration: 021_backups.sql
-- Description: Create backups table for server backup and restore system

CREATE TABLE IF NOT EXISTS `backups` (
    `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID identifier',
    `label` VARCHAR(255) NULL COMMENT 'Human-readable label for this backup',
    `file_path` VARCHAR(2048) NOT NULL COMMENT 'Absolute path to backup archive',
    `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Size of backup file in bytes',
    `checksum_sha256` VARCHAR(64) NOT NULL COMMENT 'SHA-256 checksum of backup file',
    `is_s3` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether backup is stored in S3',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Backup creation timestamp',
    `expires_at` TIMESTAMP NULL COMMENT 'Backup expiration timestamp (NULL = never)',
    INDEX `idx_backups_created` (`created_at`),
    INDEX `idx_backups_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
