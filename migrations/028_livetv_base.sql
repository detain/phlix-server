-- Step 2.4: Live TV / DVR Base Schema
-- Creates the 6 tables that currently have no migration.
-- Uses CREATE TABLE IF NOT EXISTS so it is safe to re-run.

-- Tuners: HDHomeRun, IPTV, DVB-T devices discovered on the network.
CREATE TABLE IF NOT EXISTS livetv_tuners (
    id              CHAR(36)     PRIMARY KEY,
    tuner_id        VARCHAR(64)  NOT NULL UNIQUE,
    type            ENUM('hdhomerun','iptv','dvbt') NOT NULL DEFAULT 'hdhomerun',
    name            VARCHAR(255) NOT NULL,
    host            VARCHAR(255) NULL,
    port            INT          NULL,
    device_id       VARCHAR(128) NULL,
    enabled         TINYINT(1)   NOT NULL DEFAULT 1,
    last_seen       DATETIME     NULL,
    status          VARCHAR(32)  NOT NULL DEFAULT 'idle',
    capabilities    JSON         NULL,
    discovered_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tuner_id    (tuner_id),
    INDEX idx_type        (type),
    INDEX idx_enabled    (enabled),
    INDEX idx_status      (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Channels: TV channels discovered from tuner scans or IPTV playlists.
CREATE TABLE IF NOT EXISTS livetv_channels (
    id              CHAR(36)     PRIMARY KEY,
    channel_id     CHAR(36)     NOT NULL UNIQUE,
    tuner_id        CHAR(36)     NULL,
    name            VARCHAR(255) NOT NULL,
    number          INT          NOT NULL DEFAULT 0,
    callsign        VARCHAR(64)  NULL,
    transport       VARCHAR(64)  NULL,
    frequency       INT UNSIGNED NOT NULL DEFAULT 0,
    modulation      VARCHAR(32)  NULL,
    type            VARCHAR(32)  NOT NULL DEFAULT 'tv',
    service_id      VARCHAR(64)  NULL,
    visual_id       VARCHAR(64)  NULL,
    description     TEXT         NULL,
    icon_url        VARCHAR(512) NULL,
    visibility      VARCHAR(32)  NOT NULL DEFAULT 'visible',
    enabled         TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_channel_id (channel_id),
    INDEX idx_tuner_id   (tuner_id),
    INDEX idx_number     (number),
    INDEX idx_visibility (visibility),
    INDEX idx_enabled   (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Programs: EPG entries for channels (from Schedules Direct or tuner scan).
CREATE TABLE IF NOT EXISTS livetv_programs (
    id              CHAR(36)     PRIMARY KEY,
    program_id     CHAR(36)     NOT NULL UNIQUE,
    channel_id      CHAR(36)     NOT NULL,
    title           VARCHAR(512) NOT NULL,
    description     TEXT         NULL,
    start_time      INT UNSIGNED NOT NULL,
    end_time        INT UNSIGNED NOT NULL,
    season          INT          NULL,
    episode         INT          NULL,
    year            INT          NULL,
    rating          VARCHAR(16)  NULL,
    poster          VARCHAR(512) NULL,
    category        VARCHAR(64)  NULL,
    series_id       VARCHAR(255) NULL,
    episode_number  INT          NULL,
    episode_title   VARCHAR(255) NULL,
    rating_system   VARCHAR(16)  NULL,
    series_episode  VARCHAR(32)  NULL,
    is_repeat       TINYINT(1)   NOT NULL DEFAULT 0,
    is_film         TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_program_id  (program_id),
    INDEX idx_channel_id (channel_id),
    INDEX idx_start_time (start_time),
    INDEX idx_end_time   (end_time),
    INDEX idx_series_id  (series_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Favorites: per-user favorite channels.
CREATE TABLE IF NOT EXISTS livetv_favorites (
    id              CHAR(36)     PRIMARY KEY,
    channel_id      CHAR(36)     NOT NULL,
    user_id         CHAR(36)     NOT NULL,
    added_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_favorite (channel_id, user_id),
    INDEX idx_user_id    (user_id),
    INDEX idx_channel_id (channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lineups: named channel lineups (e.g., "My TV", "Sports Package").
CREATE TABLE IF NOT EXISTS livetv_lineups (
    id              CHAR(36)     PRIMARY KEY,
    lineup_id      CHAR(36)     NOT NULL UNIQUE,
    name            VARCHAR(255) NOT NULL,
    type            VARCHAR(64)  NOT NULL DEFAULT 'custom',
    source          VARCHAR(64)  NULL,
    user_id         CHAR(36)     NULL,
    enabled         TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_lineup_id (lineup_id),
    INDEX idx_user_id   (user_id),
    INDEX idx_enabled   (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lineup channels: junction table linking lineups to channels with guide numbers.
CREATE TABLE IF NOT EXISTS livetv_lineup_channels (
    id              CHAR(36)     PRIMARY KEY,
    lineup_id       CHAR(36)     NOT NULL,
    channel_id      CHAR(36)     NOT NULL,
    guide_number    VARCHAR(16)  NULL,
    position        INT          NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_lineup_channel (lineup_id, channel_id),
    INDEX idx_lineup_id  (lineup_id),
    INDEX idx_channel_id (channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
