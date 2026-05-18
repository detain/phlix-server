-- Step I.5: LiveTV DVR Scheduled & Series Recordings
-- Adds series recording rules and deduplication support

-- Add new columns to livetv_recordings for series rules and padding
ALTER TABLE livetv_recordings
    ADD COLUMN series_rule_id CHAR(36) NULL AFTER status,
    ADD COLUMN duplicate_group CHAR(36) NULL,
    ADD COLUMN pre_padding_seconds INT NOT NULL DEFAULT 60,
    ADD COLUMN post_padding_seconds INT NOT NULL DEFAULT 60,
    ADD COLUMN scheduled_by_rule CHAR(36) NULL,
    ADD INDEX idx_series_rule_id (series_rule_id),
    ADD INDEX idx_duplicate_group (duplicate_group),
    ADD INDEX idx_scheduled_by_rule (scheduled_by_rule),
    ADD INDEX idx_status_start_time (status, start_time);

-- Create table for series recording rules
CREATE TABLE livetv_series_rules (
    rule_id CHAR(36) PRIMARY KEY,
    series_id VARCHAR(255) NOT NULL,
    channel_id CHAR(36) NULL,
    title VARCHAR(255) NOT NULL,
    priority INT NOT NULL DEFAULT 5,
    pre_padding_seconds INT NOT NULL DEFAULT 60,
    post_padding_seconds INT NOT NULL DEFAULT 60,
    max_recordings INT NULL,  -- NULL = unlimited
    days_ahead INT NOT NULL DEFAULT 14,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_series_id (series_id),
    INDEX idx_is_active (is_active),
    INDEX idx_channel_id (channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
