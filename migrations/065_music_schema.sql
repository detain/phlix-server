-- Phlix media server migration 065: Music library schema
--
-- Creates the Artist -> Album -> Track hierarchy for music media:
-- - music_artists: Artist metadata with MusicBrainz ID
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
    name                     VARCHAR(255) NOT NULL,
    sort_name                VARCHAR(255) NULL COMMENT 'Name for alphabetical sorting',
    musicbrainz_artist_id    CHAR(36) NULL COMMENT 'MusicBrainz UUID for artist',
    thumbnail_path           VARCHAR(500) NULL COMMENT 'Path to artist thumbnail image',
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Ensure artist names are unique
    UNIQUE KEY uk_name (name),

    -- Index for MusicBrainz lookups
    INDEX idx_mb_artist (musicbrainz_artist_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- music_albums: Stores album information linked to artists
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS music_albums (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    artist_id                INT UNSIGNED NOT NULL COMMENT 'FK to music_artists.id',
    title                    VARCHAR(255) NOT NULL,
    sort_title               VARCHAR(255) NULL COMMENT 'Title for alphabetical sorting',
    year                     INT UNSIGNED NULL COMMENT 'Release year',
    total_tracks             INT UNSIGNED NOT NULL DEFAULT 0,
    total_discs              INT UNSIGNED NOT NULL DEFAULT 1,
    thumbnail_path           VARCHAR(500) NULL COMMENT 'Path to album cover image',
    musicbrainz_release_id   CHAR(36) NULL COMMENT 'MusicBrainz UUID for release',
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Index for artist lookups
    INDEX idx_artist (artist_id),

    -- Index for MusicBrainz lookups
    INDEX idx_mb_release (musicbrainz_release_id),

    -- Foreign key to artists
    CONSTRAINT fk_albums_artist FOREIGN KEY (artist_id)
        REFERENCES music_artists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- music_tracks: Stores track information linked to albums
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS music_tracks (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    album_id                 INT UNSIGNED NOT NULL COMMENT 'FK to music_albums.id',
    title                    VARCHAR(255) NOT NULL,
    track_number             INT UNSIGNED NOT NULL DEFAULT 1,
    disc_number              INT UNSIGNED NOT NULL DEFAULT 1,
    duration_seconds         INT UNSIGNED NOT NULL DEFAULT 0,
    musicbrainz_recording_id CHAR(36) NULL COMMENT 'MusicBrainz UUID for recording',
    audio_file_path          VARCHAR(500) NOT NULL COMMENT 'Absolute path to audio file',
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Index for album lookups
    INDEX idx_album (album_id),

    -- Index for MusicBrainz lookups
    INDEX idx_mb_recording (musicbrainz_recording_id),

    -- Foreign key to albums
    CONSTRAINT fk_tracks_album FOREIGN KEY (album_id)
        REFERENCES music_albums(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;