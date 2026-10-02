-- Migration: 112_collections_created_by.sql
-- Description: per-user collection ownership — add `collections.created_by`
-- and backfill legacy rows (Option A, supersedes the interim admin gate of
-- c53b1490; owner decision #6 resolved 2026-10-02).
--
-- WHY THIS COLUMN EXISTS:
-- `migrations/005_collections.sql` has no owner column, so every collection
-- row is server-global. The interim ruling (c53b1490) closed the hole by
-- admin-gating ALL eight write registrations, which shipped documented member
-- regressions (the SPA/mobile/console self-service paths 403). This column is
-- the real authz: members act on rows they own, ACTIVE admins (the
-- `UserRepository::findAdminById` predicate — is_admin = 1 AND status =
-- 'active', never the soft flag) act on everything, and a foreign row is
-- refused with the byte-identical "Collection not found" 404 so an ownership
-- miss is indistinguishable from absence (no 403 oracle).
--
-- SEMANTICS (enforced in CollectionController/CollectionRepository):
--   * created_by = <user id>  → the owner reads/writes it; active admins see
--                               and manage everything; other users get the
--                               not-found shape.
--   * created_by IS NULL      → legacy/unowned: visible to EVERY authenticated
--                               user (the degenerate-case mirror of the old
--                               shared-collection read), writable ONLY by
--                               active admins. The backfill below should
--                               remove all NULL rows from upgraded installs
--                               that have users; NULL survives only when the
--                               users table was empty at migration time.
--
-- THE BACKFILL ELECTION (pure DML, non-destructive — owner-chosen tradeoff):
-- legacy rows are STAMPED, never deleted, so the shared set keeps existing.
-- The anchor mirrors the first-admin election precedent of
-- 109_first_admin_election_backfill.sql — "the OLDEST established admin wins"
-- (`ORDER BY created_at ASC, id ASC`), hardened to ACTIVE admins (status =
-- 'active', the same gate UserRepository::findAdminById applies — a
-- deactivated admin must not become the owner of record):
--   1. any active admin          → earliest (created_at, id) active admin;
--   2. else any user             → earliest-created user of ANY status
--      (someone must own the box's existing lists);
--   3. else (users empty)        → NO update fires and rows stay NULL.
-- Accepted consequence, stated plainly: legacy global lists become the
-- anchor's OWNED collections, so members LOSE the pre-gate visibility of
-- rows stamped away from NULL — and GAIN self-service CRUD on their own
-- collections, which is what closes the interim gate's documented 403s.
-- A fresh install with zero users and pre-seeded collections (an ordering
-- only a hand-loaded database can produce) keeps its NULLs and the
-- all-visible/admin-writable policy.
--
-- IDEMPOTENCE (why both guard styles appear in ONE file — this file is both
-- shapes at once, so it borrows each precedent's proven mechanism):
--   * the ALTER statements need the INFORMATION_SCHEMA + PREPARE guard of
--     migration 107 (ADD COLUMN / ADD INDEX have no IF-[NOT-]EXISTS form);
--   * the backfill UPDATE is naturally replay-safe pure DML in the shape of
--     migration 109: it touches ONLY unstamped rows
--     (`WHERE created_by IS NULL`), never overwrites an existing owner, and
--     no-ops on an empty `users` table (`AND EXISTS (SELECT 1 FROM users)`
--     is the gate — a missing `users`/`collections` table errors 1146 LOUD
--     and exits 1 by design; silently skipping the backfill would ship the
--     very unowned-row state this file exists to heal, S159 doctrine).
-- The production runner additionally tolerates MySQL 1060/1061 mid-file, so
-- even a hand-patched schema whose guards were removed replays without a
-- false failure.
--
-- The KEY `idx_col_owner` serves the member-scoped list predicate
-- (`WHERE created_by = ? OR created_by IS NULL ORDER BY sort_order, name`).
-- No foreign key: `collections` predates the FK convention (005 declares
-- none), rows must survive user deletion the way they survive every other
-- referent change today, and the authz layer only ever compares id strings.

SET @dbname = DATABASE();

-- (1) Owner column, guarded (107 idiom).
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'collections' AND COLUMN_NAME = 'created_by') = 0,
    'ALTER TABLE collections ADD COLUMN created_by CHAR(36) NULL DEFAULT NULL',
    'SELECT 1'
));
PREPARE addColumnIfNotExists FROM @preparedStatement;
EXECUTE addColumnIfNotExists;
DEALLOCATE PREPARE addColumnIfNotExists;

-- (2) Owner index, guarded (same idiom against INFORMATION_SCHEMA.STATISTICS
-- so a replay never eats error 1061 mid-file).
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'collections' AND INDEX_NAME = 'idx_col_owner') = 0,
    'ALTER TABLE collections ADD INDEX idx_col_owner (created_by)',
    'SELECT 1'
));
PREPARE addIndexIfNotExists FROM @preparedStatement;
EXECUTE addIndexIfNotExists;
DEALLOCATE PREPARE addIndexIfNotExists;

-- (3) Backfill legacy rows — tier 1: earliest ACTIVE admin; tier 2: earliest
-- user of ANY status (an older DISABLED account legitimately beats a younger
-- active one — the fallback is "earliest established user", deliberately not
-- "earliest non-admin"); no users: the EXISTS gate keeps every row NULL.
-- Flat COALESCE of two LIMIT-1 scalar subqueries, mirroring 109's structure
-- exactly. Proven arm-by-arm against real MySQL in
-- CollectionsOwnershipMigration112RealDbTest.
UPDATE collections
SET created_by = COALESCE(
    (SELECT u.id FROM users u WHERE u.is_admin = 1 AND u.status = 'active' ORDER BY u.created_at ASC, u.id ASC LIMIT 1),
    (SELECT u.id FROM users u ORDER BY u.created_at ASC, u.id ASC LIMIT 1)
)
WHERE created_by IS NULL
  AND EXISTS (SELECT 1 FROM users);
