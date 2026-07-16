-- Migration 080: Backfill missing user profiles.
--
-- Problem: AuthManager::register() never created a UserProfile (only the user
-- row), and migration 079 was a no-op because user_profiles was completely empty.
-- Every user has getActiveProfile() === null, so WebPortalRouter::getMediaItem()
-- never mints a stream_url and no video plays.
--
-- Fix: For every user who has no profile at all, create a default profile
-- named after their username, marked is_active=TRUE, plus default profile_settings.
--
-- Safe to re-run: INSERT IGNORE skips users who already got a profile.

-- Step 1: Insert a default profile for each user who has none.
-- Using INSERT IGNORE so this is idempotent.
INSERT IGNORE INTO user_profiles (id, user_id, name, avatar_url, is_active, is_admin)
SELECT
    UUID() AS id,
    u.id AS user_id,
    u.username AS name,
    NULL AS avatar_url,
    TRUE AS is_active,
    FALSE AS is_admin
FROM users u
WHERE u.id NOT IN (SELECT DISTINCT user_id FROM user_profiles);

-- Step 2: Insert default profile_settings for any newly created profiles.
-- content_rating 'R' is the default per UserProfileManager::DEFAULT_CONTENT_RATING.
INSERT IGNORE INTO profile_settings
  (id, profile_id, content_rating, pin_hash, pin_required_for_admin, max_daily_watch_time, allowed_genres, blocked_genres, allow_unrated)
SELECT
    UUID() AS id,
    up.id AS profile_id,
    'R' AS content_rating,
    NULL AS pin_hash,
    FALSE AS pin_required_for_admin,
    0 AS max_daily_watch_time,
    NULL AS allowed_genres,
    NULL AS blocked_genres,
    TRUE AS allow_unrated
FROM user_profiles up
WHERE up.id NOT IN (SELECT profile_id FROM profile_settings);
