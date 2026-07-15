-- Migration: 079_activate_first_profiles
--
-- Problem: UserProfileManager::create() was inserting profiles with is_active=FALSE
-- and nothing was ever auto-activating the first profile (despite the docblock
-- contract). getActiveProfile() queries WHERE is_active=TRUE, so ALL existing
-- users with profiles have getActiveProfile() returning null — breaking the
-- WebPortalRouter stream_url gating fix (which requires an active profile).
--
-- Fix: For every user who has at least one profile but NONE active, activate
-- their first profile (by created_at order). This is a one-time data correction
-- that makes the docblock contract ("first profile becomes active") true for
-- existing users, matching the behavior now implemented in create().
--
-- No schema changes — purely data.

-- Activate the first profile (by created_at) for each user who has profiles but no active one
UPDATE user_profiles AS p
INNER JOIN (
    SELECT user_id, MIN(created_at) as earliest
    FROM user_profiles
    GROUP BY user_id
    HAVING user_id NOT IN (
        SELECT user_id FROM user_profiles WHERE is_active = TRUE
    )
) AS first_per_user ON p.user_id = first_per_user.user_id AND p.created_at = first_per_user.earliest
SET p.is_active = TRUE;
