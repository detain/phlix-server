-- Book progress tracking table
-- Stores per-user per-book progress including page-level tracking
-- Used for resume-reading functionality

CREATE TABLE IF NOT EXISTS book_progress (
    user_id       CHAR(36) NOT NULL,
    book_id       CHAR(36) NOT NULL,
    position_ms   INT UNSIGNED NOT NULL DEFAULT 0,
    current_page  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    total_pages   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    percent_complete DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    last_read_at  INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, book_id),
    INDEX (book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
