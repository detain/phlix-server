-- Migration: User Recommendations Cache (P4-S2)
-- Creates table for "because you watched" recommendations per user.
-- Stores pre-computed top-K recommendations with dismissal support.

CREATE TABLE user_recommendations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  media_item_id CHAR(36) NOT NULL,
  reason VARCHAR(100) NOT NULL COMMENT 'because_you_watched',
  score DECIMAL(4,3) NOT NULL,
  computed_at DATETIME NOT NULL,
  shown_at DATETIME NULL COMMENT 'when user was shown this rec',
  dismissed_at DATETIME NULL COMMENT 'when user explicitly dismissed',
  UNIQUE KEY uk_user_item (user_id, media_item_id),
  INDEX idx_user_shown (user_id, shown_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
