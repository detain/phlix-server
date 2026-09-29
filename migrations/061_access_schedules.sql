-- Migration: Access Schedules (P5-S1)
-- Creates tables for time-based access control windows per profile.
-- Enforces 403 during scheduled denials for authenticated requests.
--
-- FIX: profile_id changed from INT UNSIGNED to CHAR(36) to match user_profiles.id (UUID)

CREATE TABLE access_schedules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profile_id CHAR(36) NOT NULL,
  name VARCHAR(100) NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  days_of_week SET('mon','tue','wed','thu','fri','sat','sun') NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_profile (profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Join table for profile-to-schedule many-to-many relationship.
-- A profile can have multiple schedules; a schedule can be assigned to multiple profiles.
--
-- UNUSED (L-7, 2026-09-29 security audit): zero references in src/, tests/ or
-- scripts/ — the shipped model is one-schedule-row-per-profile via
-- access_schedules.profile_id, and nothing ever populated this join table.
-- The table (and this migration) are RETAINED per the never-revert-migrations
-- policy: deployed databases already carry it, editing an applied migration
-- would desynchronise its checksum, and a future many-to-many schedule model
-- would want exactly this table. Do not build new code against `access_schedules.profile_id`
-- AND this table together without deciding which is the single source of truth.
CREATE TABLE profile_access_schedule (
  profile_id CHAR(36) NOT NULL,
  schedule_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (profile_id, schedule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
