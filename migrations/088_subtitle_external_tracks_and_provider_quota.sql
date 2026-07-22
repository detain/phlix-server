-- Migration 088: External (downloaded) subtitle tracks + provider quota (Wave 3 / F3)
--
-- The on-demand subtitle fetch pipeline downloads a subtitle from a provider
-- plugin (e.g. opensubtitles), persists it to /var/subtitles as a `.vtt` file
-- and attaches it as an external `media_streams` subtitle row the player
-- consumes through the existing subtitle_tracks[] / text/vtt <track> contract.
--
-- media_streams gains three nullable columns that distinguish a downloaded
-- external subtitle from an embedded container stream:
--   - source:           provider name that supplied the subtitle (NULL = embedded
--                       container track from an ffprobe).
--   - storage_path:     absolute path to the stored .vtt under the subtitle root;
--                       StreamTrackShaper treats a non-empty value as external and
--                       emits a signed URL to the external-serving endpoint (this
--                       row is NOT part of ffmpeg's 0:s:N selector space).
--   - hearing_impaired: 1 for an SDH / hearing-impaired subtitle, else 0.
--
-- Backwards compatibility: every legacy/embedded row keeps NULL source +
-- storage_path and hearing_impaired = 0, so the shaper's embedded path is
-- unchanged for them.
--
-- subtitle_provider_quota persists each provider's download-quota state so the
-- fetch service can skip an exhausted provider and the state survives restarts.
-- `provider` is the primary key (one row per provider — no UUID needed).
--
-- Each ALTER is a separate statement so the MigrationRunner's idempotent
-- duplicate-column downgrade (error 1060) applies per column on replay.

ALTER TABLE media_streams
    ADD COLUMN source VARCHAR(64) NULL COMMENT 'Subtitle provider name for a downloaded external track (NULL = embedded container stream)' AFTER avg_luminance;

ALTER TABLE media_streams
    ADD COLUMN storage_path VARCHAR(1024) NULL COMMENT 'Absolute path to a downloaded external subtitle .vtt file (NULL = embedded stream)' AFTER source;

ALTER TABLE media_streams
    ADD COLUMN hearing_impaired TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether this subtitle is a hearing-impaired (SDH) variant' AFTER storage_path;

CREATE TABLE IF NOT EXISTS subtitle_provider_quota (
    provider VARCHAR(64) PRIMARY KEY,
    downloads_remaining INT NULL COMMENT 'Remaining downloads the provider reported (NULL = unknown/cleared)',
    reset_time_utc VARCHAR(40) NULL COMMENT 'ISO-8601 UTC time the quota window resets (NULL = unknown)',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
