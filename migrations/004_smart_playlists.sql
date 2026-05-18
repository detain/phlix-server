-- Smart Playlists Schema
--
-- Smart playlists auto-populate based on JSON DSL rules evaluated
-- against the media library at scan time and on folder-watch events.

CREATE TABLE IF NOT EXISTS smart_playlists (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    library_id CHAR(36) NOT NULL,
    rules_json JSON NOT NULL,
    `limit` INT NOT NULL DEFAULT 0,
    sort_by VARCHAR(32) NOT NULL DEFAULT 'addedAt',
    sort_desc TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_smart_pl_library (library_id),
    INDEX idx_smart_pl_name (name),
    FOREIGN KEY (library_id) REFERENCES libraries(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
