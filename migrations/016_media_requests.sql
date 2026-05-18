-- Migration: 016_media_requests.sql
-- Step K.3: Jellyseerr-class request UI
-- Creates table for managing user media requests (movies/series)

CREATE TABLE IF NOT EXISTS requests (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    type ENUM('movie','series') NOT NULL,
    tmdb_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    poster_url VARCHAR(500),
    season INT,
    episode INT,
    status ENUM('pending','approved','available','rejected') DEFAULT 'pending',
    rejection_reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_status (user_id, status),
    INDEX idx_status (status),
    INDEX idx_tmdb_id (tmdb_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
