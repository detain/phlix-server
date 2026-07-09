CREATE TABLE manual_match_overrides (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider ENUM('tmdb','imdb','anidb') NOT NULL,
    provider_id VARCHAR(50) NOT NULL,
    media_item_id CHAR(36) NOT NULL,
    confidence DECIMAL(3,2) NOT NULL DEFAULT 1.00,
    matched_by ENUM('user','system') NOT NULL DEFAULT 'user',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_provider_item (provider, provider_id),
    INDEX idx_media_item_id (media_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;