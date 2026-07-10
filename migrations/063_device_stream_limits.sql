-- Migration: Device Stream Limits (P5-S3)
-- Creates tables for per-profile concurrent-stream caps.
--
-- FIX: profile_id changed from INT UNSIGNED to CHAR(36) to match user_profiles.id (UUID)

CREATE TABLE profile_stream_limits (
  profile_id CHAR(36) PRIMARY KEY,
  max_concurrent_streams INT UNSIGNED NOT NULL DEFAULT 1,
  max_total_bandwidth_kbps INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE active_streams (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profile_id CHAR(36) NOT NULL,
  device_id VARCHAR(100) NOT NULL,
  session_id VARCHAR(100) NOT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_heartbeat_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  stream_type ENUM('direct','transcode','relay') NOT NULL DEFAULT 'direct',
  INDEX idx_profile_session (profile_id, session_id),
  INDEX idx_heartbeat (last_heartbeat_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;