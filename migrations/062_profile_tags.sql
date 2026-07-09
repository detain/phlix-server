-- Migration: Profile Tags (P5-S2)
-- Creates table for per-profile tag blocking/allowing.
-- Blocked tags exclude items from browse results.
-- Allowed tags (when set) restrict browse to only items with those tags.

CREATE TABLE profile_tags (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profile_id INT UNSIGNED NOT NULL,
  tag VARCHAR(100) NOT NULL,
  tag_type ENUM('blocked','allowed') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_profile_tag (profile_id, tag, tag_type),
  INDEX idx_profile_type (profile_id, tag_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;