-- Migration 097: put the `(session_id, media_item_id)` UNIQUE KEY on
-- `playback_state` into the migration chain, so a fresh install actually HAS
-- the constraint that makes progress reporting an UPSERT.
--
-- THE DEFECT (S156). Migration `090_playback_state_session_media_unique.sql`
-- is named for this key but carries NO EXECUTABLE STATEMENT AT ALL — it is a
-- pure comment block that reserves the number, records the decision and points
-- operators at `migrations/cleanup_090.php`. That script is MANUAL: it is
-- called by neither `scripts/run-migrations.php`, `bin/phlix migrate`,
-- `scripts/install.sh` nor `docker/docker-entrypoint.sh`. The only thing that
-- has ever created the key is `PlaybackStateDeduper::addUniqueKey()`, invoked
-- from `cleanup_090.php:60`.
--
-- So a database built by the migration chain ALONE ended up with NO
--
--     UNIQUE KEY uq_playback_state_session_media (session_id, media_item_id)
--
-- Production has it because somebody ran the finalizer by hand once. Every
-- fresh install still carries the original bug, in full:
--
--   `PlaybackController::reportProgress()` (`PlaybackController.php:171-186`)
--   and `StreamManager::persistStreamState()` (`StreamManager.php:488`) persist
--   progress with
--
--       INSERT INTO playback_state (id, session_id, media_item_id, ...)
--       VALUES (?, ?, ?, ...)
--       ON DUPLICATE KEY UPDATE position_ticks = VALUES(position_ticks), ...
--
--   whose intended conflict target is the `(session_id, media_item_id)` pair.
--   `playback_state` otherwise has only the `id` PRIMARY KEY — a fresh random
--   UUID on every call — so `ON DUPLICATE KEY` can never fire and every ~15 s
--   progress tick INSERTs a brand-new row.
--
-- The user-visible consequences, all one defect:
--
--   1. A finished episode never leaves Continue Watching. The finish signal
--      writes a new row instead of updating the in-progress one, so the stale
--      `position_ticks < duration * 0.95` row survives and keeps surfacing
--      (`PlaybackController::getContinueWatching()`).
--   2. Next Up and Most Watched cannot be built on top of that, because the
--      "current" row per pair is not single-valued.
--   3. `playback_state` grows without bound — one row per tick, forever.
--
-- The read paths mask it rather than fix it: the continue-watching query wraps
-- the join in `ROW_NUMBER() OVER (PARTITION BY ps.media_item_id ORDER BY
-- ps.updated_at DESC, ps.id DESC)` to pick a single row. That dedup is still
-- needed AFTER this migration — one user can have several SESSIONS watching the
-- same item, and this key only constrains a single session — but it can no
-- longer be asked to paper over a per-session write bug.
--
-- ORDERING. Nothing else in the chain touches `playback_state`'s keys: the
-- table is created in `001_initial_schema.sql:87-100` and no later migration
-- adds or drops an index on it, so this file only has to sort after 001. It
-- sorts last, which also satisfies the "no later migration drops it again"
-- guard in the unit test.
--
-- LEAF TABLE. Nothing references `playback_state.id`; it only has OUTBOUND
-- `ON DELETE CASCADE` FKs to `sessions` and `media_items`. So merging a
-- duplicate group is a plain DELETE of the losers with no reference
-- repointing — which is why `PlaybackStateDeduper` is so much simpler than
-- `PathDeduper`, and why adding this key cannot cascade anything away.
--
-- WHY A BARE `ADD UNIQUE KEY` IS NOT ENOUGH — the reason 090 refused to emit
-- one at all. On an install that still holds duplicate rows (i.e. any install
-- where anybody ever watched anything before the key existed) the ALTER fails
-- with error 1062, and `MigrationRunner` treats 1062 as a genuine error (it is
-- NOT in `isAlreadyAppliedNote()`), so the file is left unrecorded and retried
-- — failing again — on every deploy, reporting only an opaque
-- `Duplicate entry '<uuid>-<uuid>' for key ...`. The population that would see
-- that is exactly the population that most needs the constraint. Note this is
-- NOISY AND PERMANENT, not aborting: the runner records the error and carries
-- on with the rest of the chain, and `scripts/run-migrations.php` exits 0
-- regardless (`docker/docker-entrypoint.sh:9` even appends `|| true`). Only
-- `bin/phlix migrate` propagates a non-zero exit.
--
-- So the statement to run is chosen at runtime, with the same
-- `SET @sql = (SELECT CASE ...)` + `PREPARE`/`EXECUTE` idiom migration 011
-- already uses in this repo (011:8-24) and migration 096 uses for the
-- `media_items` path-dedupe index, over three outcomes:
--
--   a. Key already present (production; any DB whose operator ran the
--      finalizer; every re-run of this file) → both prepared statements are
--      `SELECT 0`. A true no-op: no 1061 note, no table rebuild, re-runnable
--      forever. This is the idempotency guarantee.
--   b. Key absent AND duplicate rows remain → the GUARD prepare below fails on
--      purpose, and its error message CARRIES THE REMEDY:
--
--        Unknown column 'playback_state duplicates: run php
--        migrations/cleanup_090.php' in 'field list'
--
--      Nothing is altered and no row is touched. The runner records one error,
--      prints it, does NOT abort the rest of the chain, and leaves this file
--      unrecorded so it retries after the operator has run the finalizer.
--   c. Key absent and no duplicates (every fresh install, and any clean
--      upgrade) → add the UNIQUE KEY.
--
-- WHY THE MERGE IS NOT DONE HERE. Deleting the "wrong" duplicate loses a user's
-- place in whatever they were watching, so the keeper rule is not something to
-- re-implement in SQL as a second source of truth. It lives once, in
-- `Phlix\Session\PlaybackStateDeduper::findKeeperId()`: keep the row with the
-- greatest `updated_at`, ties broken by the greatest `id` — i.e. the most
-- recently reported position, which is what "resume where I left off" means.
-- `cleanup_090.php` drives it in bounded batches so a bloated table is drained
-- without one table-wide DELETE. This migration's job is to make sure the key
-- ends up on the table; the finalizer's job is de-duplication, and only that.
--
-- WHY AN UNKNOWN IDENTIFIER IS THE ERROR CHANNEL. MySQL has no portable way to
-- raise a custom error from a plain `.sql` file: `SIGNAL` works standalone but
-- is rejected inside the prepared-statement protocol (error 1295 "This command
-- is not supported in the prepared statement protocol yet"), and wrapping it in
-- a stored procedure needs the CREATE ROUTINE privilege, which
-- `scripts/install.sh:1516` does NOT grant (SELECT, INSERT, UPDATE, DELETE,
-- CREATE, ALTER, INDEX, REFERENCES only). An unknown column identifier needs no
-- privilege at all and MySQL echoes it back verbatim (identifiers are capped at
-- 64 characters; the message below is 61).
--
-- WHY THE GUARD REUSES THE `stmt` NAME. A failed `PREPARE` leaves the name
-- unallocated, so a following `EXECUTE`/`DEALLOCATE` would add two cascading
-- 1243 "Unknown prepared statement handler" errors on top of the one that
-- matters. Re-`PREPARE`ing the SAME name for the real work is enough: MySQL
-- implicitly deallocates an existing statement of that name before preparing
-- the new one, so outcome (b) reports EXACTLY ONE error — the actionable one —
-- and leaves nothing dangling.
--
-- S161 — SHAPE, NOT NAME (measured in the S156 review, 2026-07-27). The
-- "already there?" probe below used to match `INDEX_NAME` alone. That made the
-- migration blind to the ONLY property it exists to guarantee:
--
--   * A NON-UNIQUE index carrying the exact expected name made the file no-op
--     AND record itself applied — the table kept its duplicate rows, the upsert
--     kept inserting one row per tick, and the chain could never retry, because
--     the ledger now says "done". That is the original S156 defect, made
--     permanently invisible.
--   * Conversely, an equivalent UNIQUE key under a DIFFERENT name (functionally
--     identical: `ON DUPLICATE KEY` does not care about index names) was not
--     recognised, so the file added a second, redundant unique index.
--
-- So "already there?" now means: some index with `NON_UNIQUE = 0` covering
-- exactly the `{session_id, media_item_id}` column SET (compared order-
-- independently — the uniqueness constraint that makes the upsert fire does not
-- depend on column order). A same-NAMED index of the wrong shape is an imposter
-- squatting on this migration's contractual name: it is DROPPED AND REPLACED in
-- one atomic ALTER (the ADD failing with 1062 rolls the whole statement back,
-- so an imposter on a dirty table is never half-removed). If a correctly-shaped
-- UNIQUE already exists under some other name AND an imposter squats on the
-- contractual name, the constraint is enforced — the file stays a no-op and
-- leaves the redundant imposter alone; deleting a possibly query-used index is
-- not this migration's job.
--
-- S161 FINDING 4 — RECORDED, NOT FIXED. When the constraint is missing on a
-- dirty install, this file deliberately stays UNRECORDED, so every deploy
-- re-pays `SELECT EXISTS (… GROUP BY session_id, media_item_id HAVING COUNT(*)
-- > 1)` with no usable index (the very index it is trying to add is what would
-- make that lookup cheap) — a full-table temp-table scan per deploy. Accepted:
-- a dirty install is already paying that scan on every write path, the cost
-- stops the moment the finalizer runs, and any caching that avoided it would
-- reintroduce the invisibility S161 exists to remove. The TOCTOU window between
-- the duplicate scan and the ALTER (a concurrent insert may create a duplicate
-- in between) is inherent to online DDL from a plain .sql file and FAILS SAFE:
-- the ALTER hits 1062, nothing is altered, the file stays unrecorded, and the
-- next deploy retries. That is the correct terminal behavior, not a bug —
-- recorded here so a future reader does not "fix" the re-pay scan into a
-- second permanently-invisible success path.

-- S161INDEXSHAPEX7R4
--
-- Is a UNIQUE key of the right SHAPE already there, under ANY name? (S161: the
-- old name-only probe let a non-unique imposter satisfy it and record success.)
-- Answering this first also lets the duplicate scan be skipped entirely on such
-- a DB: the constraint itself proves there are none. The column set is compared
-- order-independently because the conflict target `ON DUPLICATE KEY UPDATE`
-- matches on the key's column SET, not on name or order.
SET @phlix_playback_state_unique = (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'playback_state'
        AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'media_item_id,session_id'
    ) phlix_unique_key_shapes
);

-- Does the CONTRACTUAL name exist at all? Only its SHAPE decides whether the
-- migration acts; this probe names the imposter to replace.
SET @phlix_playback_state_named = (
    SELECT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'playback_state'
        AND INDEX_NAME = 'uq_playback_state_session_media'
    )
);

-- An imposter: the contractual name, wrong shape, and no real constraint.
SET @phlix_playback_state_imposter = IF(
    @phlix_playback_state_unique = 0 AND @phlix_playback_state_named = 1,
    1,
    0
);

-- Duplicates only matter when the constraint is missing. Both columns are
-- `NOT NULL` (`001_initial_schema.sql:89-90`), so there is no NULL-never-collides
-- carve-out to make here — every row participates in the constraint.
SET @phlix_playback_state_dupes = IF(
    @phlix_playback_state_unique,
    0,
    (
        SELECT EXISTS (
            SELECT 1 FROM playback_state
            GROUP BY session_id, media_item_id
            HAVING COUNT(*) > 1
        )
    )
);

-- Guard (outcome b). Prepared, never executed: on a dirty table the PREPARE
-- itself fails and the remedy travels in the error text.
SET @sql = IF(
    @phlix_playback_state_dupes,
    'SELECT `playback_state duplicates: run php migrations/cleanup_090.php`',
    'SELECT 0'
);

PREPARE stmt FROM @sql;

-- Outcome (a) / (c), plus the S161 imposter replacement. Re-preparing `stmt`
-- implicitly frees whatever the guard left behind. `ADD UNIQUE KEY` (not
-- `ADD UNIQUE INDEX`) matches `PlaybackStateDeduper::addUniqueKey()` verbatim so
-- the two ways of arriving at this schema are textually identical; MySQL treats
-- KEY and INDEX as synonyms here. The imposter branch replaces the squatting
-- index in ONE atomic ALTER: a 1062 on the ADD rolls the DROP back with it, so
-- a dirty table is never left with the imposter deleted and no constraint.
SET @sql = IF(
    @phlix_playback_state_unique > 0 OR @phlix_playback_state_dupes = 1,
    'SELECT 0',
    IF(
        @phlix_playback_state_imposter = 1,
        'ALTER TABLE playback_state DROP INDEX `uq_playback_state_session_media`, ADD UNIQUE KEY uq_playback_state_session_media (session_id, media_item_id)',
        'ALTER TABLE playback_state ADD UNIQUE KEY uq_playback_state_session_media (session_id, media_item_id)'
    )
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
