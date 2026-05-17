-- Step F.3: Marker storage columns for chapters, intro, and outro markers
-- Adds formal marker columns to media_items table for skip-intro, skip-outro, and scene chapters.

ALTER TABLE media_items
  ADD COLUMN intro_start_seconds INT UNSIGNED NULL,
  ADD COLUMN intro_end_seconds   INT UNSIGNED NULL,
  ADD COLUMN outro_start_seconds INT UNSIGNED NULL,
  ADD COLUMN outro_end_seconds   INT UNSIGNED NULL,
  ADD COLUMN chapters_json       JSON NULL;  -- array of { start, end, title? }

-- Index for fast marker lookups by item
CREATE INDEX idx_media_items_intro ON media_items (intro_start_seconds) USING BTREE;
CREATE INDEX idx_media_items_outro ON media_items (outro_start_seconds) USING BTREE;
