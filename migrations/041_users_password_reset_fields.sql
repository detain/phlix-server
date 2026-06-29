-- Migration: 041_users_password_reset_fields.sql
-- Description: Add password reset token and must_change_password for S7+F1.
--
-- S7+F1 stops returning plaintext passwords on admin password reset and instead
-- issues a one-time reset token. The token is:
--   - Generated as a secure random string
--   - Stored as a HASH (NOT plaintext) for security
--   - Set to expire after a configurable window (default 15 minutes)
--   - Cleared once used (password is changed)
--
-- The `must_change_password` flag forces the user to set a new password on next
-- login before they can access any content. An admin can also set this flag
-- manually to force a password change.
--
-- Column order: added at the end of the users table.
--
-- Idempotent: re-running ADD COLUMN raises "Duplicate column name" error,
-- which the migration runner downgrades to a note (see MigrationRunner.php).

ALTER TABLE users
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

ALTER TABLE users
    ADD COLUMN password_reset_token VARCHAR(255) NULL DEFAULT NULL AFTER must_change_password;

ALTER TABLE users
    ADD COLUMN password_reset_expires_at INT UNSIGNED NULL DEFAULT NULL AFTER password_reset_token;
