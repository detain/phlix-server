-- Phlex Media Server — Admin role flag on users (Step A.5)
-- Adds a minimal `users.is_admin` column so the plugin admin UI
-- (Phase A.5) and any subsequent privileged endpoints have a single
-- gate to check. This is intentionally minimum-viable: a full RBAC
-- model with roles + permissions arrives in Phase D.
--
-- On install:
--   * brand-new installations: no rows in users yet, nothing to promote.
--     The next user created via AuthManager::register() is promoted
--     automatically because they are the "first user".
--   * pre-existing single-user installs (the dev / home case): we keep
--     the existing user able to manage the box by promoting the
--     oldest row by created_at.

ALTER TABLE users
    ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER display_name;

-- Promote the earliest-created user so existing installs keep working.
-- The inner SELECT layer wraps the subquery so MySQL doesn't choke on
-- "you can't specify target table for update in FROM clause" when the
-- subquery references the same table being updated.
--
-- The migrations runner tracks applied migrations and will not re-run
-- this file in normal operation, but the secondary `EXISTS` guard makes
-- the statement defensively idempotent: once any user has `is_admin =
-- 1` the UPDATE becomes a no-op even when replayed against an existing
-- database during development or recovery.
UPDATE users SET is_admin = 1
 WHERE id = (
     SELECT id FROM (
         SELECT id FROM users ORDER BY created_at ASC, id ASC LIMIT 1
     ) AS t
 )
 AND NOT EXISTS (
     SELECT 1 FROM (
         SELECT id FROM users WHERE is_admin = 1 LIMIT 1
     ) AS already_admin
 );

CREATE INDEX idx_users_is_admin ON users (is_admin);
