-- Phlix media server migration 064: TMDB box-set auto-collections
--
-- Adds tables for syncing TMDB movie collections (box-sets) into local database
-- so the UI can display "Part of a Collection" metadata and allow browsing all
-- items in a collection.
--
-- @copyright 2026 Joe Huss <detain@interserver.net>
-- @license   MIT
-- @author    Phlix Development Team
-- @version   0.36.0
-- @since     0.36.0

-- -----------------------------------------------------------------------------
-- media_collections: Stores TMDB collection metadata (name, overview, posters)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS media_collections (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tmdb_collection_id       INT UNSIGNED NOT NULL UNIQUE COMMENT 'TMDB collection ID for deduplication',
    name                     VARCHAR(255) NOT NULL COMMENT 'Collection display name',
    overview                 TEXT NULL COMMENT 'Collection synopsis',
    poster_url               VARCHAR(500) NULL COMMENT 'Collection poster image URL',
    backdrop_url             VARCHAR(500) NULL COMMENT 'Collection backdrop image URL',
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Index for fast lookups by TMDB collection ID
    INDEX idx_tmdb_collection_id (tmdb_collection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- media_collection_members: Links media_items to their collection with TMDB part order
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS media_collection_members (
    collection_id    INT UNSIGNED NOT NULL COMMENT 'FK to media_collections.id',
    media_item_id    INT UNSIGNED NOT NULL COMMENT 'FK to media_items.id',
    tmdb_part_order  INT UNSIGNED NOT NULL COMMENT 'Part number from TMDB collection',
    added_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (collection_id, media_item_id),
    INDEX idx_media_item (media_item_id),

    CONSTRAINT fk_collection_members_collection
        FOREIGN KEY (collection_id) REFERENCES media_collections(id) ON DELETE CASCADE,
    CONSTRAINT fk_collection_members_item
        FOREIGN KEY (media_item_id) REFERENCES media_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
