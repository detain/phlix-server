-- Migration: 100_user_item_data_profile_id.sql
-- Description: S79 — make favorites/ratings/like-level/watched PROFILE-scoped by
--              adding `user_item_data.profile_id`, backfilling every existing row
--              under exactly one profile, and widening the primary key.
--
-- ## Why 100 and not 099
--
-- `099` is RESERVED for a scanner/matching step that is still in flight. Master's
-- highest applied migration at the time this was written is `098_maintenance_jobs.sql`.
--
-- ## The product decision this migration encodes (S79)
--
-- Until now `user_item_data` was keyed per USER, deliberately (see the header of
-- `039_user_item_data.sql`). Making it per-PROFILE means every existing row has to
-- be assigned to a profile, and that assignment is a product decision, not an
-- engineering default. The decision taken, and the one implemented below, is:
--
--   1. Each existing row is MOVED (never copied, never duplicated) to its owning
--      user's ACTIVE profile; if the user has no active profile, to their FIRST
--      profile ordered by `created_at` then `id` — the same deterministic tiebreak
--      migration 079 uses to pick a user's "first" profile.
--   2. A user with ZERO profiles gets a default profile created for them first,
--      named after their username and marked `is_active = TRUE`, exactly as
--      migration 080 does. This case is LIVE, not hypothetical:
--      `AuthManager::register()` still does not create a profile, so every account
--      created after migration 080 ran has none. Their rows land on that new
--      profile. NOTHING is dropped.
--   3. Rows are moved rather than fanned out to every profile, so the row count of
--      `user_item_data` is INVARIANT across this migration. Copying each user's
--      favorites into all of their profiles was the alternative; it was rejected
--      because it silently gives a child profile the parent's favorites, which is
--      the opposite of what per-profile scoping is for. Moving is also the option
--      the step text names ("copy each user's current favorites into their
--      active/first profile").
--
-- ## Reversibility
--
-- No row is deleted and no column is dropped, so this is reversible with:
--
--     ALTER TABLE user_item_data DROP FOREIGN KEY fk_user_item_data_profile
--     ALTER TABLE user_item_data DROP INDEX idx_profile_item
--     ALTER TABLE user_item_data DROP PRIMARY KEY, ADD PRIMARY KEY (user_id, item_id)
--     ALTER TABLE user_item_data DROP COLUMN profile_id
--
-- That reverse is only safe while at most one row exists per (user_id, item_id) —
-- i.e. before any account has actually diverged its profiles' favorites. Once two
-- profiles of one user disagree, re-adding the old PK would collide (error 1062)
-- and a human has to choose which row survives. The reverse is deliberately NOT
-- automated here for that reason.
--
-- ## Why `profile_id` ends up NOT NULL
--
-- The step text asks for `profile_id CHAR(36) NULL` AND for the primary key to
-- include it. Those two are mutually exclusive in MySQL: a column in a PRIMARY KEY
-- is implicitly NOT NULL, and adding the PK over a nullable column silently
-- rewrites existing NULLs to '' (or errors, depending on sql_mode) — which would
-- produce rows pointing at a profile that does not exist. So the column is added
-- NULLable, backfilled, verified to contain no NULLs, and only then made NOT NULL.
-- The verification step below is what makes that ordering safe.
--
-- ## The no-orphan guard
--
-- Before the column is made NOT NULL, statement group (D) counts rows still lacking
-- a profile and, if there are any, deliberately executes a statement that cannot
-- parse (`SELECT fail_migration_100_...`). MySQL raises error 1054, which is NOT in
-- `MigrationRunner::IDEMPOTENT_ERROR_CODES`, so the runner records a genuine error,
-- leaves this file out of `schema_migrations`, and `scripts/run-migrations.php`
-- exits 1. The primary key is therefore never widened over rows that would lose
-- their identity. Relying on `MODIFY ... NOT NULL` to raise 1138 instead would work
-- only under a strict `sql_mode`; this guard does not depend on server config.
--
-- ## Runner contract
--
-- Statements are split by `MigrationRunner::splitStatements()`, which is quote- and
-- comment-aware: a `;` inside a string literal or inside one of these `--` comments
-- does not split a statement. Group (E) deliberately carries a `;` inside a column
-- COMMENT literal so that behaviour is exercised by a real migration rather than
-- only by the runner's own unit tests. There is no `DELIMITER` support, so the
-- conditional DDL uses the `SET @var` + `PREPARE`/`EXECUTE`/`DEALLOCATE` idiom
-- established by `045_user_item_data_watched.sql`.
--
-- Every group is individually re-runnable, so a partially-applied file (the runner
-- is continue-and-report, not stop-on-first-error) is safe to retry.

-- (A) Add the nullable column, if it is not already there.
SET @dbname = DATABASE();
SET @addProfileColumn = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'user_item_data' AND COLUMN_NAME = 'profile_id') = 0,
    'ALTER TABLE user_item_data ADD COLUMN profile_id CHAR(36) NULL AFTER user_id',
    'SELECT 1'
));
PREPARE addProfileColumn FROM @addProfileColumn;
EXECUTE addProfileColumn;
DEALLOCATE PREPARE addProfileColumn;

-- (B) Guarantee every user owns at least one profile, so step (C) can never leave
-- a row unassigned. Mirrors migration 080 verbatim in intent; INSERT IGNORE makes
-- it a no-op for users who already have one.
INSERT IGNORE INTO user_profiles (id, user_id, name, avatar_url, is_active, is_admin)
SELECT
    UUID() AS id,
    u.id AS user_id,
    u.username AS name,
    NULL AS avatar_url,
    TRUE AS is_active,
    FALSE AS is_admin
FROM users u
WHERE u.id NOT IN (SELECT DISTINCT user_id FROM user_profiles);

-- Default parental-control settings for any profile created above, so a
-- freshly-backfilled profile is not missing its `profile_settings` row.
INSERT IGNORE INTO profile_settings
  (id, profile_id, content_rating, pin_hash, pin_required_for_admin, max_daily_watch_time, allowed_genres, blocked_genres, allow_unrated)
SELECT
    UUID() AS id,
    up.id AS profile_id,
    'R' AS content_rating,
    NULL AS pin_hash,
    FALSE AS pin_required_for_admin,
    0 AS max_daily_watch_time,
    NULL AS allowed_genres,
    NULL AS blocked_genres,
    TRUE AS allow_unrated
FROM user_profiles up
WHERE up.id NOT IN (SELECT profile_id FROM profile_settings);

-- (C) The backfill. Active profile wins, else the earliest-created profile, else
-- the lowest id — the same ordering migration 079 uses. Only touches rows that do
-- not already carry a profile, so a replay cannot re-point a row a human moved.
UPDATE user_item_data uid
SET uid.profile_id = (
    SELECT p.id
    FROM user_profiles p
    WHERE p.user_id = uid.user_id
    ORDER BY p.is_active DESC, p.created_at ASC, p.id ASC
    LIMIT 1
)
WHERE uid.profile_id IS NULL;

-- (D) Refuse to continue if any row is still unassigned. See the header.
SET @orphanRows = (SELECT COUNT(*) FROM user_item_data WHERE profile_id IS NULL);
SET @orphanGuard = (SELECT IF(
    @orphanRows = 0,
    'SELECT 1',
    'SELECT fail_migration_100_user_item_data_rows_remain_without_a_profile'
));
PREPARE orphanGuard FROM @orphanGuard;
EXECUTE orphanGuard;
DEALLOCATE PREPARE orphanGuard;

-- (E) Now that every row is assigned, the column can carry the NOT NULL the
-- primary key requires. The COMMENT literal contains a `;` on purpose — see the
-- runner-contract note in the header.
ALTER TABLE user_item_data
    MODIFY COLUMN profile_id CHAR(36) NOT NULL
    COMMENT 'Owning user_profiles.id; scopes favorite/rating/like_level/watched per profile (S79)';

-- (F) Widen the primary key. `user_id` stays leftmost so the existing
-- `WHERE user_id = ?` reads and the `users` foreign key keep a usable index
-- prefix; `profile_id` is what makes two profiles of one account able to hold
-- independent data for the same item.
SET @pkColumnCount = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'user_item_data' AND INDEX_NAME = 'PRIMARY');
SET @widenPrimaryKey = (SELECT IF(
    @pkColumnCount = 3,
    'SELECT 1',
    'ALTER TABLE user_item_data DROP PRIMARY KEY, ADD PRIMARY KEY (user_id, profile_id, item_id)'
));
PREPARE widenPrimaryKey FROM @widenPrimaryKey;
EXECUTE widenPrimaryKey;
DEALLOCATE PREPARE widenPrimaryKey;

-- (G) A profile-leading index for the profile-scoped reads S80/S81 introduce, and
-- for the foreign key below. A replay raises 1061 (duplicate key name), which the
-- runner classifies as an idempotent note rather than an error.
ALTER TABLE user_item_data ADD INDEX idx_profile_item (profile_id, item_id);

-- (H) Referential integrity, matching `watch_history`'s own profile foreign key:
-- deleting a profile removes that profile's per-item data and leaves every other
-- profile on the account untouched. Guarded because a duplicate constraint name
-- raises 1826, which is NOT an idempotent error code.
SET @profileFkCount = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = @dbname AND TABLE_NAME = 'user_item_data'
      AND CONSTRAINT_NAME = 'fk_user_item_data_profile' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @addProfileFk = (SELECT IF(
    @profileFkCount = 0,
    'ALTER TABLE user_item_data ADD CONSTRAINT fk_user_item_data_profile FOREIGN KEY (profile_id) REFERENCES user_profiles(id) ON DELETE CASCADE',
    'SELECT 1'
));
PREPARE addProfileFk FROM @addProfileFk;
EXECUTE addProfileFk;
DEALLOCATE PREPARE addProfileFk;
