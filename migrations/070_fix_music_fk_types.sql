-- Phlix media server migration 070: Fix FK column types for music library
--
-- media_items.id is CHAR(36) UUID, but media_collection_members.media_item_id,
-- music_artists.media_item_id, music_albums.media_item_id, and music_tracks.media_item_id
-- were incorrectly defined as INT UNSIGNED. This breaks FK constraints.
--
-- Also fixes music_albums.artist_id and music_tracks.album_id/artist_id to use
-- the correct INT UNSIGNED (AUTO_INCREMENT) types to match their referenced tables.
--
-- @copyright 2026 Joe Huss <detain@interserver.net>
-- @license   MIT
-- @author    Phlix Development Team
-- @version   0.37.1
-- @since     0.37.1

-- -----------------------------------------------------------------------------
-- Fix media_collection_members.media_item_id: INT UNSIGNED -> CHAR(36)
-- -----------------------------------------------------------------------------
ALTER TABLE media_collection_members
    MODIFY COLUMN media_item_id CHAR(36) NOT NULL COMMENT 'FK to media_items.id';

-- -----------------------------------------------------------------------------
-- Fix music_artists.media_item_id: INT UNSIGNED -> CHAR(36)
-- Also fix artist_id and album_id columns in music_albums/music_tracks to use
-- the correct INT UNSIGNED type matching their AUTO_INCREMENT source columns
-- -----------------------------------------------------------------------------
ALTER TABLE music_artists
    MODIFY COLUMN media_item_id CHAR(36) NULL UNIQUE COMMENT 'FK to media_items.id for artwork/metadata';

-- -----------------------------------------------------------------------------
-- Fix music_albums.media_item_id: INT UNSIGNED -> CHAR(36)
-- artist_id is already correctly defined as INT UNSIGNED referencing music_artists(id)
-- -----------------------------------------------------------------------------
ALTER TABLE music_albums
    MODIFY COLUMN media_item_id CHAR(36) NULL UNIQUE COMMENT 'FK to media_items.id for artwork/metadata';

-- -----------------------------------------------------------------------------
-- Fix music_tracks.media_item_id: INT UNSIGNED -> CHAR(36)
-- album_id and artist_id are already correctly defined as INT UNSIGNED
-- -----------------------------------------------------------------------------
ALTER TABLE music_tracks
    MODIFY COLUMN media_item_id CHAR(36) NOT NULL UNIQUE COMMENT 'FK to media_items.id for stream/metadata';
