-- P4-S1: Similar-items engine — stores pre-computed cosine similarity scores.
--
-- Computed by SimilarityService::computeSimilarForItem() using:
--   - Genre overlap (Jaccard)
--   - Actor overlap (Jaccard)
--   - Director overlap (Jaccard)
--   - Rating proximity (1 - |r1-r2|/10)
--   - Year proximity (1 - |y1-y2|/100)
--
-- The `reason` column carries the dominant signal that contributed the most
-- to the final score for display purposes.

CREATE TABLE IF NOT EXISTS item_similar (
    media_item_id   CHAR(36)     NOT NULL,
    similar_item_id CHAR(36)     NOT NULL,
    score           DECIMAL(4,3) NOT NULL,
    reason          VARCHAR(50)  NOT NULL DEFAULT 'genre',
    computed_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (media_item_id, similar_item_id),
    INDEX idx_similar_item_id (similar_item_id),
    INDEX idx_score (score DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
