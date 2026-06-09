-- Migration: 036_transcode_jobs_hls_columns.sql
-- Description: Add HLS-pipeline bookkeeping columns to transcode_jobs.
--
-- The transcode subsystem (src/Media/Transcoding/TranscodeManager.php) was a
-- skeleton: it wrote one opaque output file and was never wired to a route. The
-- HLS pipeline (TranscodeManager::ensureHlsJob()) now produces real HLS variant
-- segments + playlist on disk and serves them through HlsController, so the job
-- row needs to record:
--   * profile           -- the device profile the variant was encoded for, so a
--                          later request for the SAME item+profile reuses the
--                          existing job instead of spawning a duplicate ffmpeg.
--   * key_hash          -- sha1(media_item_id|profile); indexed reuse lookup key.
--   * hls_dir           -- absolute directory holding stream_0.m3u8 + segments
--                          (the shared HLS segment dir from config['hls']).
--   * variant_width     -- encoded variant pixel width  (for the master playlist
--   * variant_height       RESOLUTION) and height; null when unknown.
--   * variant_bandwidth -- nominal variant bandwidth in bits/sec for the master
--                          playlist EXT-X-STREAM-INF BANDWIDTH attribute.
--   * error             -- last ffmpeg/launch error message when status='failed'.
--
-- The original transcode_jobs table (001_initial_schema.sql) already carries id,
-- stream_state_id, media_item_id, input_path, output_path, status, progress,
-- started_at and completed_at -- those are reused unchanged. This migration only
-- ADDs columns; no existing column or constraint is altered.
--
-- Idempotent: re-running an ADD COLUMN that already exists raises a duplicate-
-- column error which the migration runner downgrades to a note (see
-- scripts/run-migrations.php / src/Common/Database/MigrationRunner.php), so the
-- apply-all-every-time contract is preserved. Each ADD is its own statement
-- because the runner splits on `;`.

ALTER TABLE transcode_jobs ADD COLUMN profile VARCHAR(64) NULL AFTER status;

ALTER TABLE transcode_jobs ADD COLUMN key_hash CHAR(40) NULL AFTER profile;

ALTER TABLE transcode_jobs ADD COLUMN hls_dir VARCHAR(1000) NULL AFTER output_path;

ALTER TABLE transcode_jobs ADD COLUMN variant_width INT NULL AFTER progress;

ALTER TABLE transcode_jobs ADD COLUMN variant_height INT NULL AFTER variant_width;

ALTER TABLE transcode_jobs ADD COLUMN variant_bandwidth INT NULL AFTER variant_height;

ALTER TABLE transcode_jobs ADD COLUMN error TEXT NULL AFTER variant_bandwidth;

ALTER TABLE transcode_jobs ADD INDEX idx_key_hash_status (key_hash, status);
