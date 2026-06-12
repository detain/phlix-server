-- Migration: 037_users_status.sql
-- Description: Add a `status` column to users for the signup approval gate (S1).
--
-- Until now ANY registered user was immediately usable and (with no per-user
-- filtering) could see all media. The signup approval gate makes new signups
-- require administrator approval: registration creates a PENDING account that
-- cannot log in or see media until an admin approves it. The lifecycle is
-- driven entirely by this column:
--   * 'pending'  -- created via the `approval` signup mode; cannot log in.
--                   Admin moves it to 'active' (approve) or removes it (reject).
--   * 'active'   -- a normal, usable account (today's behaviour). The DEFAULT,
--                   so every pre-existing row is implicitly backfilled to
--                   'active' (those accounts were already usable) and other
--                   INSERT call sites that omit `status` keep working unchanged.
--   * 'disabled' -- an account an admin has suspended; cannot log in.
--
-- Read/write sites added in S1:
--   * AuthManager::register() writes 'pending' (approval mode) or 'active'
--     (open mode / first-user bootstrap) via UserRepository::create(... status).
--   * AuthManager::login() rejects any user whose status !== 'active'.
--   * AdminUserController approve/disable/reject call UserRepository::setStatus()
--     and the admin user list / /auth/me now surface the column.
--
-- The first registered user is ALWAYS created active (+admin) regardless of the
-- signup mode so the install can bootstrap; that policy lives in AuthManager,
-- not in this schema.
--
-- Placed AFTER `is_admin` to keep the account-flag columns grouped. An index on
-- `status` backs the admin "pending queue" listing (WHERE status = 'pending').
--
-- Idempotent: re-running ADD COLUMN raises "Duplicate column name" and ADD INDEX
-- raises a duplicate-key error, both of which the migration runner downgrades to
-- a note (see scripts/run-migrations.php / src/Common/Database/MigrationRunner.php),
-- so the apply-all-every-time contract is preserved. Each statement is split on
-- `;` by the runner, so keep them separate and free of semicolons in literals.

ALTER TABLE users
    ADD COLUMN status ENUM('pending', 'active', 'disabled') NOT NULL DEFAULT 'active' AFTER is_admin;

ALTER TABLE users ADD INDEX idx_users_status (status);
