CREATE TABLE IF NOT EXISTS metadata_ratings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    media_item_id CHAR(36) NOT NULL,
    source ENUM('tmdb','imdb','user') NOT NULL DEFAULT 'user',
    rating_type ENUM('average','user','critic','meta') NOT NULL DEFAULT 'user',
    score DECIMAL(3,1) NOT NULL,
    votes INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_media_source_type (media_item_id, source, rating_type),
    INDEX idx_media_item_id (media_item_id),
    INDEX idx_source (source),
    INDEX idx_score (score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
