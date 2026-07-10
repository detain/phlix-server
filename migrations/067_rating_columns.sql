-- Add denormalized rating aggregate columns to media_items for indexed sort/filter.
--
-- The P1-S1 acceptance criteria required indexed rating columns on media_items
-- for efficient ORDER BY / WHERE without a JOIN + AVG() + GROUP BY on every query.
--
-- rating_score: weighted average (0.0-10.0) of all non-user ratings, NULL when
--               no ratings exist. Mirrors AVG(metadata_ratings.score) for the
--               same items, but stored denormalized for index use.
-- rating_votes: total vote count across all sources. NULL for user-only ratings.
--
-- These columns are kept in sync by the rating backfill CLI and any future
-- write path that creates/updates metadata_ratings rows.
--
-- Indexes:
--   idx_media_items_rating_score   for ORDER BY rating_score DESC queries
--   idx_media_items_rating_votes   for filtering by vote count thresholds

ALTER TABLE media_items
    ADD COLUMN rating_score DECIMAL(3,1) UNSIGNED NULL DEFAULT NULL COMMENT 'Denormalized weighted average rating 0.0-10.0' AFTER metadata_json,
    ADD COLUMN rating_votes INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total vote count across all rating sources' AFTER rating_score,
    ADD INDEX idx_media_items_rating_score (rating_score),
    ADD INDEX idx_media_items_rating_votes (rating_votes);
