-- Step I.4 (backfilled): canonical schema for the LiveTV recordings table.
--
-- The table was originally created ad-hoc by application code and the
-- subsequent migrations (013_livetv_dvr, 014_livetv_commercials, and
-- 022_recorder_pid) only ALTERed it. When the original CREATE was lost
-- (during the K.3 cleanup that removed the server-side media-request
-- migrations), fresh installs had nothing to ALTER and 013/014/022
-- warned with "Table 'phlix.livetv_recordings' doesn't exist".
--
-- This migration creates the table with the FULL combined schema from
-- 013 / 014 / 022. The earlier migrations stay in place so that an
-- existing DB that already has the base table picks up any column the
-- ad-hoc creation missed; on a fresh DB the ALTERs become harmless
-- "Duplicate column" notes which the runner now downgrades.
--
-- start_time / end_time are stored as INT UNSIGNED unix timestamps to
-- match Recorder.php (which uses time() and strtotime() for both).

CREATE TABLE IF NOT EXISTS livetv_recordings (
    recording_id              CHAR(36)     NOT NULL,
    channel_id                CHAR(36)     NOT NULL,
    user_id                   CHAR(36)     NULL,
    program_id                VARCHAR(64)  NULL,
    title                     VARCHAR(255) NOT NULL,
    description               TEXT         NULL,
    start_time                INT UNSIGNED NOT NULL,
    end_time                  INT UNSIGNED NOT NULL,
    priority                  INT          NOT NULL DEFAULT 0,
    quality                   VARCHAR(32)  NOT NULL DEFAULT 'default',
    storage_path              VARCHAR(512) NULL,
    storage_size              BIGINT UNSIGNED NULL,
    status                    VARCHAR(32)  NOT NULL DEFAULT 'scheduled',
    error_message             TEXT         NULL,

    -- From 022_recorder_pid.sql
    pid                       INT          NULL,

    -- From 013_livetv_dvr.sql
    series_rule_id            CHAR(36)     NULL,
    duplicate_group           CHAR(36)     NULL,
    pre_padding_seconds       INT          NOT NULL DEFAULT 60,
    post_padding_seconds      INT          NOT NULL DEFAULT 60,
    scheduled_by_rule         CHAR(36)     NULL,

    -- From 014_livetv_commercials.sql
    commercial_processed_at   DATETIME     NULL,
    commercial_edl_path       VARCHAR(512) NULL,
    commercial_frame_count    INT          NULL,
    commercial_duration_seconds INT        NULL,

    created_at                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (recording_id),
    INDEX idx_channel_id          (channel_id),
    INDEX idx_user_id             (user_id),
    INDEX idx_status              (status),
    INDEX idx_status_start_time   (status, start_time),
    INDEX idx_start_time          (start_time),
    INDEX idx_pid                 (pid),
    INDEX idx_series_rule_id      (series_rule_id),
    INDEX idx_duplicate_group     (duplicate_group),
    INDEX idx_scheduled_by_rule   (scheduled_by_rule),
    INDEX idx_commercial_processed (commercial_processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
