-- Migration: Add active_theme_id to user_profiles
-- Step H.3: Custom CSS / themes with ui-theme plugin type
-- Depends on: 002_user_profiles_and_parental_controls.sql

-- Add active_theme_id column to store per-profile theme preference.
-- `AFTER` is intentionally omitted: an earlier revision said
-- `AFTER max_profiles`, but that column lives in `user_settings`, not
-- `user_profiles` — the ALTER then failed with "Unknown column
-- 'max_profiles' in 'user_profiles'". Column ordering is cosmetic.
ALTER TABLE user_profiles
    ADD COLUMN active_theme_id VARCHAR(64) NULL;
