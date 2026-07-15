-- Migration: 079_activate_first_profiles
--
-- Problem: UserProfileManager::create() was inserting profiles with is_active=FALSE
-- and nothing was ever auto-activating the first profile (despite the docblock
-- contract). getActiveProfile() queries WHERE is_active=TRUE, so ALL existing
-- users with profiles have getActiveProfile() returning null — breaking the
-- WebPortalRouter stream_url gating fix (which requires an active profile).
--
-- Fix: For every user who has at least one profile but NONE active, activate
-- their first profile (by created_at, then id — deterministic tiebreak). This
-- is a one-time data correction that makes the docblock contract ("first profile
-- becomes active") true for existing users, matching the behavior now in create().
--
-- No schema changes — purely data.

-- Activate the first profile (by created_at, tiebreak by id) for each user with
-- profiles but no active profile. Uses a double-MIN pattern to ensure exactly
-- one row is selected even when multiple profiles share the same created_at.
UPDATE user_profiles AS p
INNER JOIN (
    SELECT p2.id
    FROM user_profiles AS p2
    INNER JOIN (
        SELECT user_id, MIN(created_at) AS earliest_created
        FROM user_profiles
        GROUP BY user_id
        HAVING user_id NOT IN (
            SELECT user_id FROM user_profiles WHERE is_active = TRUE
        )
    ) AS earliest ON p2.user_id = earliest.user_id AND p2.created_at = earliest.earliest_created
    INNER JOIN (
        SELECT user_id, created_at, MIN(id) AS earliest_id
        FROM user_profiles
        GROUP BY user_id, created_at
    ) AS earliest_id ON p2.user_id = earliest_id.user_id
        AND p2.created_at = earliest_id.created_at
        AND p2.id = earliest_id.earliest_id
) AS target ON p.id = target.id
SET p.is_active = TRUE;
