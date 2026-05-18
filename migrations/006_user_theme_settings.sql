-- Migration: Add active_theme_id to user_profiles
-- Step H.3: Custom CSS / themes with ui-theme plugin type
-- Depends on: 002_user_profiles_and_parental_controls.sql

-- Add active_theme_id column to store per-profile theme preference
ALTER TABLE user_profiles
    ADD COLUMN active_theme_id VARCHAR(64) NULL AFTER max_profiles;
