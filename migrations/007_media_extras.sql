-- Migration: 007_media_extras.sql
-- Step H.5: Trailers + extras with local Trailers/ folder support
-- Creates media_extras table for storing cached trailers and extras

CREATE TABLE IF NOT EXISTS media_extras (
    id CHAR(36) NOT NULL PRIMARY KEY,
    media_item_id CHAR(36) NOT NULL,
    title VARCHAR(256) NOT NULL,
    extra_type VARCHAR(32) NOT NULL COMMENT 'trailer|featurette|behind_the_scenes|interview|clip|deleted_scene',
    source VARCHAR(16) NOT NULL COMMENT 'local|tmdb',
    url VARCHAR(1024) NOT NULL,
    file_path VARCHAR(1024) NULL COMMENT 'Only for source=local',
    duration INT NOT NULL DEFAULT 0 COMMENT 'Duration in seconds',
    quality INT NOT NULL DEFAULT 0 COMMENT 'Video quality (480/720/1080/2160)',
    cached_at DATETIME NOT NULL,
    INDEX idx_me_media (media_item_id),
    INDEX idx_me_type (extra_type),
    INDEX idx_me_source (source),
    CONSTRAINT fk_me_media_item FOREIGN KEY (media_item_id)
        REFERENCES media_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
