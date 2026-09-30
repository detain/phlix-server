-- Migration: 110_oauth_state_store_user_id.sql
-- Description: bind OAuth state rows to the initiating identity (M-4, security audit 2026-09-30).
--
-- WHY THIS COLUMN EXISTS:
-- The Trakt OAuth connect flow issued its CSRF `state` + PKCE verifier without
-- recording WHO initiated it, and the callback accepted whatever browser rode
-- the redirect home. Any authenticated (non-admin) user could therefore bind
-- the SERVER-WIDE Trakt account to their own Trakt profile, or plant their
-- account through a state an admin issued. The fix persists the initiating
-- identity at `authorize()` time and makes the callback require an admin whose
-- id matches the bound one — which needs a column to bind against.
--
-- Semantics:
--   * CHAR(36) NULL — same type/width as `users.id`, but NO foreign key: the
--     table is the unified provider store (migration 048) shared by
--     oidc/lastfm/github/oauth2/quick-connect/webauthn carriers whose rows
--     legitimately have no user context, and a TTL-transient table must not
--     outlive its issuer via a constraint error on user delete.
--   * NULL → unbound (pre-existing rows, and providers that never set it).
--     The Trakt callback REFUSES unbound state — a flow with no recorded
--     initiator can never complete (fail-closed).
--
-- Additive-only: existing rows and every other provider's INSERT are
-- byte-identical in behaviour (the column is unlisted in their statements and
-- defaults to NULL). Never revert an applied migration (house rule).
--
-- Idempotent: ALTER TABLE IF NOT EXISTS is not valid MySQL, so guard with the
-- established INFORMATION_SCHEMA + PREPARE pattern (see migration 107).

SET @dbname = DATABASE();
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'oauth_state_store' AND COLUMN_NAME = 'user_id') = 0,
    'ALTER TABLE oauth_state_store ADD COLUMN user_id CHAR(36) NULL DEFAULT NULL AFTER state_value',
    'SELECT 1'
));
PREPARE addColumnIfNotExists FROM @preparedStatement;
EXECUTE addColumnIfNotExists;
DEALLOCATE PREPARE addColumnIfNotExists;
