ALTER TABLE media_markers
  ADD COLUMN thumbnail_path VARCHAR(500) NULL AFTER end_time_ms;

CREATE INDEX idx_media_markers_thumb ON media_markers (thumbnail_path);
