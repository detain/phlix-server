-- Phlix Media Server Initial Schema

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id CHAR(36) PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User settings table
CREATE TABLE IF NOT EXISTS user_settings (
    user_id CHAR(36) PRIMARY KEY,
    max_streams INT DEFAULT 3,
    max_bitrate INT DEFAULT 100000000,
    preferred_audio_language VARCHAR(10) DEFAULT 'en',
    preferred_subtitle_language VARCHAR(10) DEFAULT 'en',
    subtitle_mode ENUM('always', 'only_foreign', 'none') DEFAULT 'only_foreign',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Libraries table
CREATE TABLE IF NOT EXISTS libraries (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type ENUM('movie', 'series', 'music', 'photo', 'video') NOT NULL,
    paths JSON NOT NULL,
    options JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Media items table
CREATE TABLE IF NOT EXISTS media_items (
    id CHAR(36) PRIMARY KEY,
    library_id CHAR(36) NOT NULL,
    parent_id CHAR(36),
    name VARCHAR(255) NOT NULL,
    type ENUM('movie', 'series', 'season', 'episode', 'music', 'album', 'artist', 'video', 'audio', 'book', 'photo') NOT NULL,
    path VARCHAR(1000) NOT NULL,
    metadata_json JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_library (library_id),
    INDEX idx_parent (parent_id),
    INDEX idx_type (type),
    FULLTEXT idx_name (name),
    FOREIGN KEY (library_id) REFERENCES libraries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Media streams table
CREATE TABLE IF NOT EXISTS media_streams (
    id CHAR(36) PRIMARY KEY,
    media_item_id CHAR(36) NOT NULL,
    stream_index INT NOT NULL,
    stream_type ENUM('video', 'audio', 'subtitle') NOT NULL,
    codec VARCHAR(50),
    language VARCHAR(10),
    bitrate INT,
    width INT,
    height INT,
    FOREIGN KEY (media_item_id) REFERENCES media_items(id) ON DELETE CASCADE,
    INDEX idx_media_item (media_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions table
CREATE TABLE IF NOT EXISTS sessions (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    device_id VARCHAR(255) NOT NULL,
    device_name VARCHAR(255),
    device_type VARCHAR(50),
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Playback state table
CREATE TABLE IF NOT EXISTS playback_state (
    id CHAR(36) PRIMARY KEY,
    session_id CHAR(36) NOT NULL,
    media_item_id CHAR(36) NOT NULL,
    position_ticks BIGINT DEFAULT 0,
    duration_ticks BIGINT,
    playback_status ENUM('playing', 'paused', 'stopped') DEFAULT 'stopped',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (media_item_id) REFERENCES media_items(id) ON DELETE CASCADE,
    INDEX idx_session (session_id),
    INDEX idx_media_item (media_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API keys table
CREATE TABLE IF NOT EXISTS api_keys (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    key_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_key_hash (key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transcode jobs table
CREATE TABLE IF NOT EXISTS transcode_jobs (
    id CHAR(36) PRIMARY KEY,
    stream_state_id CHAR(36),
    media_item_id CHAR(36) NOT NULL,
    input_path VARCHAR(1000) NOT NULL,
    output_path VARCHAR(1000) NOT NULL,
    status ENUM('queued', 'running', 'completed', 'failed', 'cancelled') DEFAULT 'queued',
    progress DECIMAL(5,2) DEFAULT 0,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (media_item_id) REFERENCES media_items(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_media_item (media_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;