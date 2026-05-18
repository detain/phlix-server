-- Migration: 008_theme_media.sql
-- Step H.6: Theme music + theme video auto-play on browse
-- Creates theme_media table for caching discovered theme media

CREATE TABLE IF NOT EXISTS theme_media (
    library_id CHAR(36) NOT NULL PRIMARY KEY,
    audio_path VARCHAR(1024) NULL,
    audio_url VARCHAR(512) NULL,
    audio_duration INT NULL COMMENT 'Duration in seconds',
    audio_format VARCHAR(8) NULL COMMENT 'mp3|ogg|aac',
    video_path VARCHAR(1024) NULL,
    video_url VARCHAR(512) NULL,
    video_duration INT NULL COMMENT 'Duration in seconds',
    video_width INT NULL,
    video_height INT NULL,
    video_format VARCHAR(8) NULL COMMENT 'mp4|webm',
    scanned_at DATETIME NOT NULL,
    INDEX idx_tm_library (library_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
