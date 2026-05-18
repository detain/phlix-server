-- Audiobook progress tracking table
-- Stores per-user per-audiobook progress including chapter-level tracking
-- Used for resume-in-chapter functionality

CREATE TABLE IF NOT EXISTS audiobook_progress (
    user_id       CHAR(36) NOT NULL,
    audiobook_id  CHAR(36) NOT NULL,
    position_ms   INT UNSIGNED NOT NULL DEFAULT 0,
    current_chapter_index SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    completed_chapters JSON NOT NULL DEFAULT ('[]'),
    percent_complete DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    last_played_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, audiobook_id),
    INDEX (audiobook_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
