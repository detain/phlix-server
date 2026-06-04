-- Migration: 032_users_avatar_url.sql
-- Description: Add the `avatar_url` column to users.
--
-- UserRepository::updateAvatar()/getAvatar() (src/Auth/UserRepository.php lines
-- 494-522) and Admin\DashboardService (src/Admin/DashboardService.php line 562)
-- read and write a per-user avatar URL on the `users` table, but the original
-- table in 001_initial_schema.sql never defined the column and no later migration
-- added it. Every avatar read therefore failed at runtime with:
--   SQLSTATE[42S22]: Column not found: 1054 Unknown column 'avatar_url' in 'field list'
-- (seen via the admin dashboard user lookup + the profile/avatar code paths).
--
-- This is the account-level user avatar and is DISTINCT from
-- `user_profiles.avatar_url` (migration 002), which is the per-PROFILE picture.
-- Nullable — getAvatar() returns null when unset; VARCHAR(500) mirrors the
-- user_profiles.avatar_url width.
--
-- Idempotent: re-running ADD COLUMN raises "Duplicate column name", which the
-- migration runner downgrades to a note (see src/Common/Database/MigrationRunner.php).

-- NOTE: keep this statement free of semicolons inside string literals. The
-- migration runner strips comments then splits on `;` (see MigrationRunner::
-- splitStatements), so a `;` inside the COMMENT text would shred the ALTER.
ALTER TABLE users
    ADD COLUMN avatar_url VARCHAR(500) NULL DEFAULT NULL AFTER display_name;
