-- Music Library Schema Extension
-- Step G.2: Add track type and music-specific indexes

-- Add 'track' to media_items type enum if not already present
-- Note: This is a MySQL-specific migration for altering ENUM
-- We use a procedure to safely add to the enum

SET @sql = (
    SELECT CASE
        WHEN EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'media_items'
            AND COLUMN_NAME = 'type'
            AND COLUMN_TYPE LIKE '%track%'
        )
        THEN 'SELECT 0'
        ELSE 'ALTER TABLE media_items MODIFY COLUMN type ENUM(''movie'', ''series'', ''season'', ''episode'', ''track'', ''music'', ''album'', ''artist'', ''video'', ''audio'', ''book'', ''photo'') NOT NULL'
    END
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index on library_id + type for efficient music queries
-- This helps when querying for all tracks in a music library
CREATE INDEX idx_media_items_library_type
ON media_items (library_id, type);

-- Add index on metadata_json for artist/album queries
-- Uses JSON functions for partial matching
CREATE INDEX idx_media_items_metadata_artist
ON media_items ((CAST(metadata_json->>'$.artist' AS CHAR(255))));

CREATE INDEX idx_media_items_metadata_album
ON media_items ((CAST(metadata_json->>'$.album' AS CHAR(255))));

-- Add index for genre queries
CREATE INDEX idx_media_items_metadata_genre
ON media_items ((CAST(metadata_json->>'$.genre' AS CHAR(255))));
