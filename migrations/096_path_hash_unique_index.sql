-- Migration 096: put the `(library_id, path_hash)` UNIQUE index back into the
-- migration chain, so a fresh install actually HAS the path-dedupe constraint.
--
-- THE DEFECT (S152). Migration 072 added the `path_hash` generated column but
-- deliberately left the UNIQUE index to `migrations/cleanup_072.php`, and
-- migration 087 then DROPs the index (087:47-48) before rewriting the column.
-- The ONLY thing that ever (re-)creates it is `cleanup_072.php:121-125` — a
-- MANUAL post-deploy script that `scripts/run-migrations.php`, `bin/phlix
-- migrate`, `scripts/install.sh` and `docker/docker-entrypoint.sh` never call.
--
-- So a database built by the migration chain ALONE ends up with NO
--
--     UNIQUE KEY idx_media_items_library_path_hash (library_id, path_hash)
--
-- Two consequences, the second being the serious one:
--
--   1. Perf. `ItemRepository::findByPath()` / `findPathsMap()` and the S151
--      music-scanner track lookup all resolve through this index. Without it
--      the planner falls back to `idx_library` (`library_id` alone,
--      cardinality ~3) and filters the rest by hand — measured on a clean
--      migrations-only DB: `type=ref`, `key=idx_library`, `key_len=144`,
--      `rows=400`, `filtered=1.00`. With the index: `type=const`,
--      `key_len=305`, `rows=1`, `filtered=100.00`.
--   2. Data integrity. This UNIQUE key is what makes a duplicate media row
--      impossible, and what `ItemRepository::upsertByPath()` catches error 1062
--      on to win a concurrent-insert race. Without it, nothing stops a
--      duplicate mint. Production is protected only because somebody ran the
--      finalizer by hand once.
--
-- ORDERING. This file sorts AFTER 087, so on any run that applies both (a fresh
-- database, or the ledger-empty transition path where every file re-applies in
-- sorted order) 087's DROP happens first and this ADD happens last. It can
-- never fight the DROP.
--
-- WHY A BARE `ADD UNIQUE INDEX` IS NOT ENOUGH — the reason 072/087 refused to
-- do it at all. On an install that still holds duplicate `(library_id,
-- path_hash)` rows the ALTER fails with error 1062, and `MigrationRunner`
-- treats 1062 as a genuine error (it is NOT in `isAlreadyAppliedNote()`), so
-- the file is left unrecorded and retried — failing again — on every deploy,
-- reporting only an opaque `Duplicate entry '<uuid>-<sha1>' for key ...`. The
-- population that would see that is exactly the population that most needs the
-- constraint.
--
-- So the statement to run is chosen at runtime, with the same
-- `SET @sql = (SELECT CASE ...)` + `PREPARE`/`EXECUTE` idiom migration 011
-- already uses in this repo (011:8-25), over three outcomes:
--
--   a. Index already present (production; any DB where `cleanup_072.php` was
--      run) → both prepared statements are `SELECT 0`. A true no-op: no 1061
--      note, no table rebuild, re-runnable forever. This is the idempotency
--      guarantee.
--   b. Index absent AND duplicate rows remain → the GUARD prepare below fails
--      on purpose, and its error message CARRIES THE REMEDY:
--
--        Unknown column 'media_items duplicate paths: run php
--        migrations/cleanup_072.php' in 'field list'
--
--      Nothing is altered and no row is touched. The runner records one error,
--      prints it, does NOT abort the rest of the chain, and leaves this file
--      unrecorded so it retries after the operator has run the finalizer.
--   c. Index absent and no duplicates (every fresh install, and any clean
--      upgrade) → add the UNIQUE index.
--
-- WHY AN UNKNOWN IDENTIFIER IS THE ERROR CHANNEL. MySQL has no portable way to
-- raise a custom error from a plain `.sql` file: `SIGNAL` works standalone but
-- is rejected inside the prepared-statement protocol (error 1295 "This command
-- is not supported in the prepared statement protocol yet"), and wrapping it in
-- a stored procedure needs the CREATE ROUTINE privilege, which
-- `scripts/install.sh:1516` does NOT grant (SELECT, INSERT, UPDATE, DELETE,
-- CREATE, ALTER, INDEX, REFERENCES only). An unknown column identifier needs no
-- privilege at all and MySQL echoes it back verbatim (identifiers are capped at
-- 64 characters; the message below is 63).
--
-- WHY THE GUARD REUSES THE `stmt` NAME. A failed `PREPARE` leaves the name
-- unallocated, so a following `EXECUTE`/`DEALLOCATE` would add two cascading
-- 1243 "Unknown prepared statement handler" errors on top of the one that
-- matters. Re-`PREPARE`ing the SAME name for the real work is enough: MySQL
-- implicitly deallocates an existing statement of that name before preparing
-- the new one, so outcome (b) reports EXACTLY ONE error — the actionable one —
-- and leaves nothing dangling.
--
-- WHAT `cleanup_072.php` STILL OWNS: de-duplication, and only de-duplication.
-- Merging a duplicate group means picking a keeper by user data
-- (`PathDeduper::scoreItem()`) and repointing 20 referencing tables
-- collision-safely — logic that exists once, in PHP, shared with the
-- `media:dedupe-paths` CLI command. Re-implementing that in SQL would create a
-- second source of truth for the keeper rule, which is exactly what
-- `PathDeduper`'s docblock warns against. Adding the index is no longer the
-- script's job (its `ALTER` stays there, idempotent, for operators mid-upgrade
-- and for anyone who runs the finalizer on its own) — it is this migration's.

-- Is the unique index already there? (Production, and any DB whose operator ran
-- the finalizer.) Answering this first also lets the duplicate scan be skipped
-- entirely on such a DB: the constraint itself proves there are none.
SET @phlix_path_hash_index = (
    SELECT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'media_items'
        AND INDEX_NAME = 'idx_media_items_library_path_hash'
    )
);

-- Duplicates only matter when the index is missing. `path_hash` is NULL for the
-- 7 ENUM members outside the deduped scope (series, season, album, artist,
-- music, video, photo) and MySQL never collides NULLs under a UNIQUE index, so
-- those rows are correctly excluded here exactly as they are by the constraint.
SET @phlix_path_hash_dupes = IF(
    @phlix_path_hash_index,
    0,
    (
        SELECT EXISTS (
            SELECT 1 FROM media_items
            WHERE path_hash IS NOT NULL
            GROUP BY library_id, path_hash
            HAVING COUNT(*) > 1
        )
    )
);

-- Guard (outcome b). Prepared, never executed: on a dirty table the PREPARE
-- itself fails and the remedy travels in the error text.
SET @sql = IF(
    @phlix_path_hash_dupes,
    'SELECT `media_items duplicate paths: run php migrations/cleanup_072.php`',
    'SELECT 0'
);

PREPARE stmt FROM @sql;

-- Outcome (a) / (c). Re-preparing `stmt` implicitly frees whatever the guard
-- left behind.
SET @sql = IF(
    @phlix_path_hash_index = 0 AND @phlix_path_hash_dupes = 0,
    'ALTER TABLE media_items ADD UNIQUE INDEX idx_media_items_library_path_hash (library_id, path_hash)',
    'SELECT 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
