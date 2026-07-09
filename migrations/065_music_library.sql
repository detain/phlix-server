-- Phlix media server migration 065: Music Library
--
-- Creates the Artist -> Album -> Track hierarchy for music media:
-- - music_artists: Artist metadata linked to media_items
-- - music_albums: Album metadata linked to artists
-- - music_tracks: Track metadata linked to albums with audio file paths
--
-- @copyright 2026 Joe Huss <detain@interserver.net>
-- @license   MIT
-- @author    Phlix Development Team
-- @version   0.37.0
-- @since     0.37.0

-- -----------------------------------------------------------------------------
-- music_artists: Stores music artist information
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS music_artists (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    media_item_id            INT UNSIGNED NULL UNIQUE COMMENT 'FK to media_items.id for artwork/metadata',
    name                     VARCHAR(255) NOT NULL,
    sort_name                VARCHAR(255) NULL COMMENT 'Name for alphabetical sorting',
    biography                TEXT NULL COMMENT 'Artist biography',
    image_url                VARCHAR(500) NULL COMMENT 'URL to artist image',
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Ensure artist names are unique
    UNIQUE KEY uk_name (name),

    -- Index for name lookups
    INDEX idx_name (name),

    -- Foreign key to media_items for artwork
    CONSTRAINT fk_artists_media_item FOREIGN KEY (media_item_id)
        REFERENCES media_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- music_albums: Stores album information linked to artists
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS music_albums (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    media_item_id            INT UNSIGNED NULL UNIQUE COMMENT 'FK to media_items.id for artwork/metadata',
    artist_id                INT UNSIGNED NOT NULL COMMENT 'FK to music_artists.id',
    title                    VARCHAR(255) NOT NULL,
    sort_title               VARCHAR(255) NULL COMMENT 'Title for alphabetical sorting',
    year                     INT UNSIGNED NULL COMMENT 'Release year',
    total_tracks             INT UNSIGNED NOT NULL DEFAULT 0,
    total_discs              INT UNSIGNED NOT NULL DEFAULT 1,
    album_art_url            VARCHAR(500) NULL COMMENT 'URL to album cover art',
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Index for artist lookups
    INDEX idx_artist (artist_id),

    -- Index for title lookups
    INDEX idx_title (title),

    -- Foreign key to artists
    CONSTRAINT fk_albums_artist FOREIGN KEY (artist_id)
        REFERENCES music_artists(id) ON DELETE CASCADE,

    -- Foreign key to media_items for artwork
    CONSTRAINT fk_albums_media_item FOREIGN KEY (media_item_id)
        REFERENCES media_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- music_tracks: Stores track information linked to albums
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS music_tracks (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    media_item_id            INT UNSIGNED NOT NULL UNIQUE COMMENT 'FK to media_items.id for stream/metadata',
    album_id                 INT UNSIGNED NOT NULL COMMENT 'FK to music_albums.id',
    artist_id                INT UNSIGNED NOT NULL COMMENT 'FK to music_artists.id (denormalized for queries)',
    title                    VARCHAR(255) NOT NULL,
    track_number             INT UNSIGNED NULL COMMENT 'Position within album',
    disc_number              INT UNSIGNED NOT NULL DEFAULT 1,
    duration_secs            INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Duration in seconds',
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Index for album lookups
    INDEX idx_album (album_id),

    -- Index for artist lookups
    INDEX idx_artist (artist_id),

    -- Index for title lookups
    INDEX idx_title (title),

    -- Foreign key to albums
    CONSTRAINT fk_tracks_album FOREIGN KEY (album_id)
        REFERENCES music_albums(id) ON DELETE CASCADE,

    -- Foreign key to artists (denormalized for efficient queries)
    CONSTRAINT fk_tracks_artist FOREIGN KEY (artist_id)
        REFERENCES music_artists(id) ON DELETE CASCADE,

    -- Foreign key to media_items for stream/metadata
    CONSTRAINT fk_tracks_media_item FOREIGN KEY (media_item_id)
        REFERENCES media_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
