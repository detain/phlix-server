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
CREATE TABLE profile_access_schedule (
  profile_id CHAR(36) NOT NULL,
  schedule_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (profile_id, schedule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
