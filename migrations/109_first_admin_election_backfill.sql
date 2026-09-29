-- Migration: 109_first_admin_election_backfill.sql
-- Description: stamp the first-admin sentinel on upgraded installs (H-1 rework, security review 2026-09-29).
--
-- WHY THIS MIGRATION EXISTS:
-- 108_first_admin_election.sql is a PURE CREATE TABLE — an upgraded install
-- gets a VIRGIN (empty) sentinel while its `users` table is already populated.
-- Every pre-existing account arrived outside AuthManager::register() —
-- `bin/phlix user:create` (UserCreateCommand), `user:promote`
-- (UserPromoteCommand), the admin user UI (AdminUserController) — and none of
-- those paths stamps the sentinel, because none of them predates the election.
-- AuthManager::claimFirstAdminElection() used to honour the NULL sentinel as
-- "you are the first user" WITHOUT checking the users table, so on every
-- upgraded install the next unauthenticated POST /api/v1/auth/register
-- self-elected ACTIVE ADMIN — and the first-user branch skips the
-- `auth.signup_mode` gate with it. That is the takeover vector.
--
-- THE FIX HAS TWO LAYERS (this file is layer b):
--   (a) claimFirstAdminElection() now re-verifies emptiness of `users` under
--       its FOR UPDATE serialisation before honouring an unstamped claim —
--       that belt holds even if this migration has not run yet;
--   (b) THIS backfill restores the sentinel INVARIANT ("a NULL sentinel can
--       only be a live, uncommitted claim") on already-installed databases,
--       so the register-path election and the docblock agree again, and an
--       operator reading the table sees who actually owns the box.
--
-- STAMP CHOICE, by ascending truthfulness (mirrors 004's precedent of picking
-- the OLDEST established admin — `ORDER BY created_at ASC, id ASC`):
--   * users has at least one admin  → the earliest-created existing admin is
--     recorded as the established winner.
--   * users non-empty, NO admin     → the nil UUID marker. The election is
--     CLOSED without impersonating anyone: a non-admin CLI user must not be
--     recorded as the first-admin winner, and the runtime check (layer a)
--     would reject a NULL here anyway. Recovery is an operator running
--     `bin/phlix user:promote`.
--   * users EMPTY (true fresh install, or 108+109 applied up-front) → NO row
--     is inserted and the NULL sentinel is NOT touched: the election must
--     stay open so the first real registration legitimately wins it.
--
-- IDEMPOTENCE (why no INFORMATION_SCHEMA/PREPARE guard, unlike 107):
-- 107 needs the guard because `ALTER TABLE ... ADD COLUMN` has no IF-NOT-
-- EXISTS; THIS file is pure DML that is naturally replay-safe:
--   * the INSERT IGNORE no-ops when the row exists (PK id=1) and produces
--     zero rows when `users` is empty (its FROM clause IS the existence gate);
--   * the UPDATE touches ONLY a NULL sentinel (`WHERE user_id IS NULL`),
--     never overwrites a stamped winner, and no-ops on an empty `users`
--     (`AND EXISTS (SELECT 1 FROM users)`);
--   * a missing `first_admin_election` table (108 failed) errors 1146 LOUD and
--     exits 1 by design — silently skipping the backfill would ship the very
--     fail-open state this file exists to heal (S159 doctrine).
-- `first_admin_election.user_id` carries NO foreign key in 108's schema, so
-- the nil-UUID marker value is storable by design.
--
-- NOTE on `claimed_at`: backfilled rows stamp the migration instant, not the
-- historical bootstrap time (which was never recorded). The column's only
-- consumers are operators, and the value is documented here.

-- (1) Sentinel row absent (the plain upgraded shape): create it stamped.
INSERT IGNORE INTO first_admin_election (id, user_id)
SELECT 1, COALESCE(
    (SELECT u.id FROM users u WHERE u.is_admin = 1 ORDER BY u.created_at ASC, u.id ASC LIMIT 1),
    '00000000-0000-0000-0000-000000000000'
)
FROM users
LIMIT 1;

-- (2) Sentinel row present but UNSTAMPED: either pre-fix residue (an
-- election a losing register left committed as NULL) or a hand-created row.
-- Same stamp choice; winner-stamped rows are never touched.
UPDATE first_admin_election
SET user_id = COALESCE(
    (SELECT u.id FROM users u WHERE u.is_admin = 1 ORDER BY u.created_at ASC, u.id ASC LIMIT 1),
    '00000000-0000-0000-0000-000000000000'
)
WHERE id = 1
  AND user_id IS NULL
  AND EXISTS (SELECT 1 FROM users);
