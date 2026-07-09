CREATE TABLE IF NOT EXISTS media_markers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    media_item_id CHAR(36) NOT NULL,
    marker_type ENUM('intro','outro','credits','ad') NOT NULL,
    start_time_ms INT UNSIGNED NOT NULL,
    end_time_ms INT UNSIGNED NOT NULL,
    label VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_media_item_id (media_item_id),
    INDEX idx_type (marker_type),
    INDEX idx_start_time (start_time_ms)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
