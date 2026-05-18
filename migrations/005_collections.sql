-- Collections: named groups of media items (manual + rule-based)
-- Migration 005

CREATE TABLE IF NOT EXISTS collections (
    id CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID primary key',
    name VARCHAR(128) NOT NULL COMMENT 'Collection display name',
    library_id CHAR(36) NOT NULL COMMENT 'Parent library UUID',
    smart_playlist_id CHAR(36) NULL COMMENT 'Link to smart_playlist for auto-populated collections',
    parent_id CHAR(36) NULL COMMENT 'Parent collection UUID for nesting, NULL = top-level',
    sort_order INT NOT NULL DEFAULT 0 COMMENT 'Display order within parent or library',
    created_at DATETIME NOT NULL COMMENT 'Creation timestamp',
    updated_at DATETIME NOT NULL COMMENT 'Last update timestamp',
    INDEX idx_col_library (library_id),
    INDEX idx_col_smart_pl (smart_playlist_id),
    INDEX idx_col_parent (parent_id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collection_items (
    collection_id CHAR(36) NOT NULL COMMENT 'Collection UUID (FK to collections.id)',
    media_item_id CHAR(36) NOT NULL COMMENT 'Media item UUID (FK to media_items.id)',
    sort_order INT NOT NULL DEFAULT 0 COMMENT 'Display order within collection',
    added_at DATETIME NOT NULL COMMENT 'When item was added to collection',
    PRIMARY KEY (collection_id, media_item_id),
    INDEX idx_ci_media (media_item_id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
