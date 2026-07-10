-- Migration 068: Fix metadata_ratings.source ENUM and sync media_items rating columns
--
-- ENUM was ('tmdb','imdb','user') but D9 spec requires ('imdb','tmdb','rt','aggregate').
-- The 'user' source no longer exists; aggregate ratings use the new 'aggregate' source.
-- This migration:
--   1. Updates the existing aggregate row (source='user', type='average') to source='aggregate'
--   2. Changes the ENUM column to the correct D9 values
--   3. Clears any stale 'user' source rows (should not exist in clean data)

-- First, migrate any existing aggregate row from 'user' to 'aggregate' source
UPDATE metadata_ratings
SET source = 'aggregate'
WHERE source = 'user' AND rating_type = 'average';

-- Drop the old unique constraint (cannot alter enum in-place with active constraint)
ALTER TABLE metadata_ratings
    DROP INDEX uniq_media_source_type;

-- Change the ENUM column (MySQL allows reordering/adding/removing enum values)
ALTER TABLE metadata_ratings
    MODIFY source ENUM('imdb','tmdb','rt','aggregate') NOT NULL DEFAULT 'aggregate';

-- Re-add the unique constraint with new values
ALTER TABLE metadata_ratings
    ADD UNIQUE KEY uniq_media_source_type (media_item_id, source, rating_type);

-- Backfill media_items.rating_score and rating_votes from metadata_ratings aggregate rows
-- This mirrors what RatingService::aggregate() writes; ensures pre-existing items are correct
UPDATE media_items m
JOIN (
    SELECT
        media_item_id,
        ROUND(score, 1) AS weighted_score,
        votes
    FROM metadata_ratings
    WHERE source = 'aggregate' AND rating_type = 'average'
) AS agg ON agg.media_item_id = m.id
SET
    m.rating_score = agg.weighted_score,
    m.rating_votes = COALESCE(agg.votes, 0);
