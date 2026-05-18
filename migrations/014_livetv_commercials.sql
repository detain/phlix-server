-- Step I.6: LiveTV Commercial Skip (Comskip Integration)
-- Adds columns for commercial processing status to livetv_recordings

ALTER TABLE livetv_recordings
    ADD COLUMN commercial_processed_at DATETIME NULL,
    ADD COLUMN commercial_edl_path VARCHAR(512) NULL,
    ADD COLUMN commercial_frame_count INT NULL,
    ADD COLUMN commercial_duration_seconds INT NULL,
    ADD INDEX idx_commercial_processed (commercial_processed_at);
