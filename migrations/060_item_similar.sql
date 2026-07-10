-- P4-S1: Similar-items engine — stores pre-computed similarity scores.
--
-- Computed by SimilarityService::computeSimilarForItem() using
-- vector-based cosine similarity over a unified feature space containing:
--   - Genres: multi-hot binary vector with W_GENRE=0.35 weight per genre
--   - Actors: multi-hot binary vector with W_ACTOR=0.25 weight per actor
--   - Directors: multi-hot binary vector with W_DIRECTOR=0.15 weight per director
--   - Rating: normalized to [0,1] with W_RATING=0.15
--   - Year: normalized to [0,1] over 1900-2100 range with W_YEAR=0.10
--
-- Cosine = dot(A_weighted, B_weighted) / (||A|| * ||B||)
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
