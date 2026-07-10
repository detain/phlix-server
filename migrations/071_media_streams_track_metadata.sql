-- Migration 071: Full audio/subtitle track metadata on media_streams
--
-- P3B track selection: the playback-info endpoints (StreamTrackShaper) emit
-- `audio_tracks[]`/`subtitle_tracks[]` with channels/title/default fields, but
-- the base 001 schema stores none of them — and the scanner previously
-- persisted only one video + one audio row, so the columns had nowhere to go.
--
-- - media_streams.channels:   audio channel count (ffprobe `channels`); NULL
--   for video/subtitle rows and legacy rows.
-- - media_streams.title:      human-readable track title (ffprobe `tags.title`,
--   e.g. "Director's Commentary"); NULL when the container has none.
-- - media_streams.is_default: ffprobe `disposition.default` flag (0/1) so the
--   player can pre-select the container's default audio/subtitle track.
-- - media_items.streams_probed_at: set once the FULL stream set has been
--   persisted (scan, backfill, or lazy playback-info probe). Guards the lazy
--   backfill so an item that genuinely has 1 audio + 0 subtitle streams is
--   probed at most once instead of on every playback-info request.
--
-- Backwards compatibility: legacy rows keep NULL/0 values and legacy items
-- keep streams_probed_at = NULL, which makes them eligible for the one-shot
-- lazy re-probe on their next playback-info request.
--
-- Each ALTER is a separate statement so the MigrationRunner's idempotent
-- duplicate-column downgrade (error 1060) applies per column on replay.

ALTER TABLE media_streams
    ADD COLUMN channels INT NULL COMMENT 'Audio channel count (NULL for video/subtitle rows)' AFTER bitrate;

ALTER TABLE media_streams
    ADD COLUMN title VARCHAR(255) NULL COMMENT 'Track title from container tags (NULL when untitled)' AFTER height;

ALTER TABLE media_streams
    ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'ffprobe disposition.default flag' AFTER title;

ALTER TABLE media_items
    ADD COLUMN streams_probed_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When the full media_streams set was last persisted (NULL = eligible for lazy re-probe)';
