-- Migration: 040_users_logout_all_devices_at.sql
-- Description: Add `logout_all_devices_at` column for F3 "log out all devices" feature.
--
-- When a user triggers "log out all devices", their `logout_all_devices_at`
-- timestamp is set to the current Unix time. Any access token with an iat
-- (issued at) earlier than this timestamp is rejected in validateAccessToken,
-- effectively invalidating all existing sessions and tokens.
--
-- The column is NULL when no global logout has been performed (the normal case).
-- A NULL value means no epoch invalidation is applied.
--
-- Idempotent: re-running ADD COLUMN raises "Duplicate column name" error,
-- which the migration runner downgrades to a note (see MigrationRunner.php).

ALTER TABLE users
    ADD COLUMN logout_all_devices_at INT UNSIGNED NULL DEFAULT NULL AFTER status;
