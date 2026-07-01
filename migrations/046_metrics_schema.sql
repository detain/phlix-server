-- Migration: 046_metrics_schema.sql
-- Step S1: Metrics / live-traffic core
-- Creates the three tables backing the in-process telemetry subsystem
-- (Phlix\Stats\Metrics): per-worker time-bucket rollups, per-route rollups, and
-- a live snapshot of active connections.
--
-- The 14-worker model: each HTTP worker accumulates counters in RAM and flushes
-- its OWN rows keyed by worker_id (INSERT ... ON DUPLICATE KEY UPDATE). No
-- cross-worker locking is required because a worker never touches another
-- worker's rows. The admin read side aggregates across workers with SUM/GROUP BY.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS so the runner can re-apply every boot.
-- InnoDB + utf8mb4_unicode_ci to match the rest of the schema.

CREATE TABLE IF NOT EXISTS metrics_rollup (
    bucket_started_at DATETIME NOT NULL,
    worker_id SMALLINT NOT NULL,
    request_count INT NOT NULL DEFAULT 0,
    error_count INT NOT NULL DEFAULT 0,
    duration_ms_sum BIGINT NOT NULL DEFAULT 0,
    duration_ms_max INT NOT NULL DEFAULT 0,
    bytes_in BIGINT NOT NULL DEFAULT 0,
    bytes_out BIGINT NOT NULL DEFAULT 0,
    h_le_10 INT NOT NULL DEFAULT 0,
    h_le_50 INT NOT NULL DEFAULT 0,
    h_le_100 INT NOT NULL DEFAULT 0,
    h_le_250 INT NOT NULL DEFAULT 0,
    h_le_500 INT NOT NULL DEFAULT 0,
    h_le_1000 INT NOT NULL DEFAULT 0,
    h_le_2500 INT NOT NULL DEFAULT 0,
    h_le_5000 INT NOT NULL DEFAULT 0,
    h_gt_5000 INT NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket_started_at, worker_id),
    INDEX idx_metrics_rollup_bucket (bucket_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS metrics_route_rollup (
    bucket_started_at DATETIME NOT NULL,
    worker_id SMALLINT NOT NULL,
    method VARCHAR(8) NOT NULL,
    route VARCHAR(191) NOT NULL,
    request_count INT NOT NULL DEFAULT 0,
    error_count INT NOT NULL DEFAULT 0,
    duration_ms_sum BIGINT NOT NULL DEFAULT 0,
    duration_ms_max INT NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket_started_at, worker_id, method, route),
    INDEX idx_metrics_route_rollup_bucket (bucket_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS metrics_connections (
    connection_id VARCHAR(64) NOT NULL,
    worker_id SMALLINT NOT NULL,
    kind ENUM('http','websocket','stream') NOT NULL DEFAULT 'http',
    user_id CHAR(36) NULL,
    remote_ip VARCHAR(45) NULL,
    session_id CHAR(36) NULL,
    media_item_id CHAR(36) NULL,
    bytes_in BIGINT NOT NULL DEFAULT 0,
    bytes_out BIGINT NOT NULL DEFAULT 0,
    bytes_in_rate BIGINT NOT NULL DEFAULT 0,
    bytes_out_rate BIGINT NOT NULL DEFAULT 0,
    opened_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    PRIMARY KEY (connection_id),
    INDEX idx_metrics_connections_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
