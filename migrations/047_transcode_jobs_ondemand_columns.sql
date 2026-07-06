-- Migration: 047_transcode_jobs_ondemand_columns.sql
-- Description: On-demand (seek-aware) HLS bookkeeping columns for transcode_jobs.
--
-- The HLS transcode pipeline (src/Media/Transcoding/TranscodeManager::ensureHlsJob())
-- no longer runs a single linear CMAF encode that writes a live, ever-growing
-- playlist. Instead a job now publishes a COMPLETE VOD playlist up front (the full
-- title duration, all segment entries, EXT-X-ENDLIST) and transcodes each MPEG-TS
-- segment ON DEMAND when the player requests it (an -ss fast-seek encode). This
-- makes the player report the true total length immediately and lets the user seek
-- anywhere — including past what has been produced so far — instead of the stream
-- behaving like an open-ended live feed.
--
-- To build the playlist and encode any segment on demand without re-probing the
-- source on every request, the job persists:
--   * duration_seconds  -- probed source length; drives the VOD playlist length.
--   * segment_seconds    -- target HLS segment (EXTINF) length.
--   * segment_params     -- JSON of the per-segment encode decision (video/audio
--                           codec, crf/preset, pix_fmt/profile/level, optional
--                           downscale w/h, audio bitrate/channels). Stored as TEXT
--                           (matches subtitle_tracks) so the workerman/mysql driver
--                           handles it as a plain string.
--
-- Only ADDs nullable columns; no existing column/constraint is altered. Idempotent:
-- re-running an ADD COLUMN that already exists raises a duplicate-column error which
-- the migration runner downgrades to a note (see src/Common/Database/MigrationRunner.php).

ALTER TABLE transcode_jobs ADD COLUMN duration_seconds INT NULL AFTER subtitle_tracks;
ALTER TABLE transcode_jobs ADD COLUMN segment_seconds INT NULL AFTER duration_seconds;
ALTER TABLE transcode_jobs ADD COLUMN segment_params TEXT NULL AFTER segment_seconds;
