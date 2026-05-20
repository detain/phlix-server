-- Step Wave (post-O.7): Confidence scores for intro/outro marker detection
-- Adds confidence columns so the system can track how reliable each
-- intro/outro detection is (0.00-100.00 scale). Higher confidence
-- means the detection algorithm is more certain about the marker's
-- accuracy. This allows filtering out low-confidence detections
-- and improves skip-button UX when users prefer accurate markers.

ALTER TABLE media_items
    ADD COLUMN intro_confidence  DECIMAL(5,2) NULL,
    ADD COLUMN outro_confidence  DECIMAL(5,2) NULL;

-- Indexes for fast filtering by confidence (e.g., find all high-confidence intros)
CREATE INDEX idx_media_items_intro_confidence  ON media_items (intro_confidence);
CREATE INDEX idx_media_items_outro_confidence  ON media_items (outro_confidence);
