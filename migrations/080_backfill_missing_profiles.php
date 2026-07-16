<?php

/**
 * Migration 080: Backfill missing user profiles.
 *
 * Run this ONCE after migration 079 to fix users who have NO profiles at all
 * (user_profiles table is empty).
 *
 * Problem: AuthManager::register() never created a UserProfile, and the
 * original migration 079 only activated existing profiles — it was a no-op
 * when user_profiles was empty. Every user has getActiveProfile() === null,
 * so WebPortalRouter::getMediaItem() never mints a stream_url and no video
 * plays.
 *
 * Fix: For every user who has no profile at all, create a default profile
 * (named after their username) marked as is_active=TRUE, plus default
 * profile_settings.
 *
 * Safe to re-run: the INSERT uses IGNORE so already-present profiles are
 * skipped.
 *
 *     php migrations/080_backfill_missing_profiles.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Uuid;

echo "=== Migration 080: backfill missing user profiles ===\n\n";

ConnectionPool::init(__DIR__ . '/../config/database.php');
$db = ConnectionPool::getConnection('mysql');

// Find all users who have no profile at all
$users = $db->query(
    "SELECT u.id, u.username, u.email
     FROM users u
     WHERE u.id NOT IN (SELECT DISTINCT user_id FROM user_profiles)"
);

if ($users === []) {
    echo "All users already have profiles — nothing to do.\n";
    exit(0);
}

echo sprintf("Found %d user(s) without a profile.\n\n", count($users));

foreach ($users as $user) {
    $userId = $user['id'];
    $username = $user['username'];
    $email = $user['email'];
    $profileId = Uuid::v4();
    $settingsId = Uuid::v4();
    $profileName = $username;
    $contentRating = 'R';

    echo sprintf("Creating profile for user %s (id=%s, email=%s)\n", $username, $userId, $email);
    echo sprintf("  profile_id = %s\n", $profileId);
    echo sprintf("  profile_name = '%s'\n", $profileName);

    try {
        // Insert the profile — IGNORE prevents duplicate-key errors on re-run
        $db->query(
            "INSERT IGNORE INTO user_profiles (id, user_id, name, avatar_url, is_active, is_admin)
             VALUES (?, ?, ?, NULL, TRUE, FALSE)",
            [$profileId, $userId, $profileName]
        );

        // Insert default profile settings
        $db->query(
            "INSERT IGNORE INTO profile_settings
             (id, profile_id, content_rating, pin_hash, pin_required_for_admin,
              max_daily_watch_time, allowed_genres, blocked_genres, allow_unrated)
             VALUES (?, ?, ?, NULL, FALSE, 0, NULL, NULL, TRUE)",
            [$settingsId, $profileId, $contentRating]
        );

        echo "  ✓ Profile created and activated.\n";
    } catch (\Throwable $e) {
        echo sprintf("  ✗ FAILED: %s\n", $e->getMessage());
        exit(1);
    }
}

echo "\n=== Migration 080 complete ===\n";
echo sprintf("Created %d profile(s).\n", count($users));
