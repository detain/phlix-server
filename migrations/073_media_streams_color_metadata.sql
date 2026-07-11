-- Migration 073: HDR color metadata on media_streams (SV-1.1)
--
-- Persists color transfer/primaries/space at scan time so tone-mapping
-- need is known without re-probing on every segment encode.
--
-- A 2-hour playback was triggering ~3 ffprobe calls per segment (one in
-- needsToneMapping, one in getToneMappingProfile) — this kills the per-segment
-- ffprobe storm by resolving the tone-map decision once at scan time and
-- passing it through computeSegmentParams to every segment build.
--
-- Columns are only meaningful for VIDEO streams (NULL for audio/subtitle rows).
--
-- Backwards compatibility: legacy rows keep NULL values and the existing
-- needsToneMapping() / getToneMappingProfile() fallbacks handle them by
-- re-probing (so pre-073 items still tone-map correctly, just less efficiently
-- until they are re-scanned).
--
-- Each ALTER is a separate statement so the MigrationRunner's idempotent
-- duplicate-column downgrade (error 1060) applies per column on replay.

ALTER TABLE media_streams
    ADD COLUMN color_space VARCHAR(50) NULL COMMENT 'ffprobe color_space (e.g. bt2020nc, smpte2084)' AFTER is_default;

ALTER TABLE media_streams
    ADD COLUMN color_transfer VARCHAR(50) NULL COMMENT 'ffprobe color_transfer (e.g. smpte2084, arib-std-b67, bt709)' AFTER color_space;

ALTER TABLE media_streams
    ADD COLUMN color_primaries VARCHAR(50) NULL COMMENT 'ffprobe color_primaries (e.g. bt2020, bt709)' AFTER color_transfer;

ALTER TABLE media_streams
    ADD COLUMN max_luminance DECIMAL(10,2) NULL COMMENT 'Mastering display max luminance (e.g. 1000.00)' AFTER color_primaries;

ALTER TABLE media_streams
    ADD COLUMN avg_luminance DECIMAL(10,2) NULL COMMENT 'Mastering display avg luminance (e.g. 200.00)' AFTER max_luminance;
