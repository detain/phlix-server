-- Migration: 057_network_health_log
-- Created: 2026-07-09
-- Description: Network health monitoring log table for storing relay status and latency metrics
-- Reference: P3B-S7

CREATE TABLE network_health_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  measured_at DATETIME NOT NULL,
  latency_ms INT UNSIGNED NULL,
  relay_status ENUM('connected','disconnected','reconnecting') NOT NULL,
  active_sessions INT UNSIGNED NOT NULL DEFAULT 0,
  INDEX idx_network_health_measured (measured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;