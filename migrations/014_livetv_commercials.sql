-- Step I.6: LiveTV Commercial Skip (Comskip Integration)
-- Adds columns for commercial processing status to livetv_recordings

-- One ALTER per clause so re-runs (where 012a already added these)
-- fail independently per duplicate rather than aborting the whole batch.
ALTER TABLE livetv_recordings ADD COLUMN commercial_processed_at DATETIME NULL;
ALTER TABLE livetv_recordings ADD COLUMN commercial_edl_path VARCHAR(512) NULL;
ALTER TABLE livetv_recordings ADD COLUMN commercial_frame_count INT NULL;
ALTER TABLE livetv_recordings ADD COLUMN commercial_duration_seconds INT NULL;
ALTER TABLE livetv_recordings ADD INDEX idx_commercial_processed (commercial_processed_at);
