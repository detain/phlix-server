-- Migration 103: collapse the duplicate UNIQUE indexes on the three music
-- tables' `media_item_id` columns to exactly one NAMED constraint each, and
-- stop the chain from minting more.
-- S155MIGX4R9
--
-- THE DEFECT (S155). Migration 070 fixes the FK column types with
--
--     ALTER TABLE music_artists/music_albums/music_tracks
--         MODIFY COLUMN media_item_id CHAR(36) ... UNIQUE
--
-- (070:28, 070:35, 070:42). A column-level inline `UNIQUE` inside `MODIFY
-- COLUMN` makes MySQL AUTO-NAME the index (`media_item_id`, then
-- `media_item_id_2`, `media_item_id_3`, ...). An auto-named mint can never
-- collide with the name a previous replay produced, so it never raises 1061,
-- so `MigrationRunner`'s idempotent "already applied" squelch (errno 1061 —
-- see its `IDEMPOTENT_ERROR_CODES`) never fires, and every re-application
-- silently adds ONE MORE unique index over the SAME single column. Production
-- carried 24 per table (23 duplicates each; measured 2026-07-27 via
-- information_schema.STATISTICS, NON_UNIQUE=0). Every INSERT/UPDATE to these
-- tables maintains all of them — per-write amplification on live tables.
--
-- Growth is bounded by the SV-4.9 ledger (files with a matching recorded
-- checksum are skipped without executing), so this is cleanup of existing
-- damage plus future-proofing — not an active leak.
--
-- WHY A NEW FILE AND NOT AN EDIT OF 070: 070's checksum is recorded in every
-- install's `schema_migrations` ledger; editing it in place would make the
-- runner log "checksum diverged; re-applying" and re-run 070's type conversions
-- everywhere. 070 stays byte-identical forever. This file runs after it
-- (sorted later) and repairs what every historical replay left behind.
--
-- SELF-HEALING, NOT JUST ONE-TIME: if 070 ever re-applies again (an empty-ledger
-- transition re-runs every file in sort order), it mints one more auto-named
-- copy BEFORE this file runs, and this file collapses it. The invariant
-- "exactly one unique index on media_item_id" therefore holds at the END of
-- every full chain pass, regardless of how dirty the start was. A database
-- built by the chain alone today lands on TWO per table even on a first run
-- (065's own inline UNIQUE + 070's re-mint) — measured on MySQL 8.0.46 — which
-- this file also collapses to one.
--
-- ─────────────────────────────────────────────────────────────────────────
-- PER TABLE (music_artists, music_albums, music_tracks), THREE STEPS:
--
--  A. ENSURE the contractual named UNIQUE index exists with the right SHAPE.
--     Following the S161 lesson, "already there?" is answered by SHAPE
--     (NON_UNIQUE = 0 over exactly the single column `media_item_id`), never
--     by name alone — but the SURVIVOR must additionally carry the
--     contractual name `uq_music_<x>_media_item`, because step C drops every
--     other index of the correct shape. Outcomes:
--       - name exists, right shape      → no-op (replay; also 1061-safe).
--       - name exists, WRONG shape      → atomic `DROP INDEX, ADD UNIQUE INDEX`
--         in ONE ALTER (an imposter squatting the contractual name is replaced,
--         never left behind — the S156 review finding-1 blindness).
--       - name absent                   → plain `ADD UNIQUE INDEX`. On a
--         constraint-clean table this cannot raise 1062; if the data DOES carry
--         duplicates and no unique constraint, the guard in step B refuses
--         first, loudly, with a remedy.
--  B. GUARD (096 idiom): a `PREPARE` that is never executed. If duplicates
--     exist while no shape-adequate unique is in place, preparing an unknown
--     column whose name CARRIES THE REMEDY fails with 1054 — a genuine,
--     non-idempotent error, so nothing downstream alters and the file stays
--     UNRECORDED for retry. NULLs never collide under UNIQUE and `media_item_id`
--     is NULL on artists/albums, so the dup scan excludes NULLs exactly as the
--     constraint does. On clean data the guard prepares `SELECT 0`.
--  C. COLLAPSE duplicates: one dynamically built `ALTER TABLE ... DROP INDEX
--     ..., DROP INDEX ...` removing every UNIQUE index over exactly
--     {media_item_id} except the contractual one just ensured. When the set is
--     empty the prepared statement is `SELECT 0` — a true no-op, so re-runs are
--     silent.
--
-- THE FK LANDMINE (065:34 fk_artists_media_item, 065:65 fk_albums_media_item,
-- 065:102 fk_tracks_media_item — all ON media_items(id) ON media_item_id).
-- InnoDB refuses (1553) to drop an index a foreign key needs — but the check
-- validates the FINAL table state, not the starting one: as long as ANY
-- (media_item_id)-leading index survives the ALTER, every drop in it is
-- permitted and the FK re-associates. Step A guarantees the contractual name
-- survives step C, and the drops and the survivor are single-column indexes on
-- the SAME column, so the FK can never be left unbacked. Measured on MySQL
-- 8.0.46 against a chain-built schema: collapsing 4 duplicates while keeping
-- only the newly named constraint succeeds, `SHOW CREATE TABLE` keeps all three
-- FKs, and a violating INSERT still fails with 1452. The FK CONSTRAINTS are
-- never touched by this file — 1553/1826 are NOT in the runner's idempotent
-- set, so a migration that dropped and re-added a constraint could not replay
-- cleanly, and it does not need to.
--
-- WHY GROUP_CONCAT AND A RAISED LIMIT: a plain .sql file has no loop, and a
-- stored procedure needs CREATE ROUTINE, which scripts/install.sh does not
-- grant (the privilege analysis is recorded in the 096 header). One
-- GROUP_CONCAT of DROP clauses — the same SET/PREPARE/EXECUTE idiom as 011,
-- 096 and 097 — expresses "drop every survivor-except duplicate" as ONE
-- statement. group_concat_max_len defaults to 1024 bytes; the worst measured
-- prod shape (24 duplicates per table, names up to `media_item_id_24`) fits,
-- but the session limit is raised to 65536 so the collapse can never be
-- silently truncated into partial cleanup. Truncation would split a DROP list
-- mid-clause and raise 1064 — loud, but non-idempotent to a retrying operator;
-- the raised limit makes it impossible instead.
--
-- The `SET @phlix_*` session-variable style, the reused `stmt` handler name
-- (a failed PREPARE leaves nothing dangling; re-PREPARE implicitly deallocates),
-- and the unknown-identifier error channel are all precedents this repo
-- established in 011/096/097.
--
-- Proven against real MySQL by
-- tests/Integration/Common/Database/MusicMediaItemUniqueIndexGuardTest.php
-- (duplicate-injection collapse, twice-replay, FK 1452 after collapse,
-- unique 1062 after collapse, imposter + duplicates refusal).
--
-- @copyright 2026 Joe Huss <detain@interserver.net>
-- @license   MIT
-- @since     S155

-- Duplicate names reach `media_item_id_24` on the dirtiest measured install;
-- keep the DROP list far away from the 1024-byte default truncation ceiling.
SET SESSION group_concat_max_len = 65536;

-- ---------------------------------------------------------------------------
-- music_artists
-- ---------------------------------------------------------------------------

-- Shape probe: any UNIQUE index over exactly {media_item_id}, under ANY name.
-- Its existence proves the data is already duplicate-free.
SET @phlix_s155_artists_unique = (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'music_artists'
        AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'media_item_id'
    ) phlix_unique_index_shapes
);

-- Does the CONTRACTUAL name exist at all, and does it carry the right shape?
-- Only these two answers together decide the branch; a same-named wrong-shape
-- imposter must never satisfy the probe (S161).
SET @phlix_s155_artists_named = (
    SELECT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'music_artists'
        AND INDEX_NAME = 'uq_music_artists_media_item'
    )
);

SET @phlix_s155_artists_named_shape = (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'music_artists'
        AND NON_UNIQUE = 0
        AND INDEX_NAME = 'uq_music_artists_media_item'
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'media_item_id'
    ) phlix_named_unique_index_shapes
);

SET @phlix_s155_artists_imposter = IF(
    @phlix_s155_artists_named_shape = 0 AND @phlix_s155_artists_named = 1,
    1,
    0
);

SET @phlix_s155_artists_dupes = IF(
    @phlix_s155_artists_unique > 0,
    0,
    (
        SELECT EXISTS (
            SELECT 1 FROM music_artists
            WHERE media_item_id IS NOT NULL
            GROUP BY media_item_id
            HAVING COUNT(*) > 1
        )
    )
);

-- Guard (step B): prepared, never executed. On a dirty table the PREPARE
-- itself fails with 1054 and the remedy travels inside the identifier text
-- (63 chars, under the 64-char identifier cap).
SET @sql = IF(
    @phlix_s155_artists_dupes,
    'SELECT `music_artists duplicate media_item_id rows: dedupe first, S155`',
    'SELECT 0'
);

PREPARE stmt FROM @sql;

-- Ensure (step A). No-op when the contractual name already carries the shape
-- or when the guard has just refused; atomic replacement for an imposter;
-- plain ADD otherwise. Re-preparing `stmt` implicitly frees whatever the
-- guard left behind.
SET @sql = IF(
    @phlix_s155_artists_named_shape > 0 OR @phlix_s155_artists_dupes = 1,
    'SELECT 0',
    IF(
        @phlix_s155_artists_imposter = 1,
        'ALTER TABLE music_artists DROP INDEX `uq_music_artists_media_item`, ADD UNIQUE INDEX uq_music_artists_media_item (media_item_id)',
        'ALTER TABLE music_artists ADD UNIQUE INDEX uq_music_artists_media_item (media_item_id)'
    )
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Collapse (step C): every unique index over exactly {media_item_id} that is
-- NOT the contractual survivor. NULL result (nothing to drop) prepares
-- `SELECT 0`, so a re-run is silent.
SET @phlix_s155_artists_drops = (
    SELECT GROUP_CONCAT(CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ') FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'music_artists'
        AND NON_UNIQUE = 0
        AND INDEX_NAME <> 'uq_music_artists_media_item'
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'media_item_id'
    ) phlix_duplicate_unique_indexes
);

SET @sql = IF(
    @phlix_s155_artists_drops IS NULL OR @phlix_s155_artists_drops = '',
    'SELECT 0',
    CONCAT('ALTER TABLE music_artists ', @phlix_s155_artists_drops)
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- music_albums
-- ---------------------------------------------------------------------------

SET @phlix_s155_albums_unique = (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'music_albums'
        AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'media_item_id'
    ) phlix_unique_index_shapes
);

SET @phlix_s155_albums_named = (
    SELECT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'music_albums'
        AND INDEX_NAME = 'uq_music_albums_media_item'
    )
);

SET @phlix_s155_albums_named_shape = (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'music_albums'
        AND NON_UNIQUE = 0
        AND INDEX_NAME = 'uq_music_albums_media_item'
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'media_item_id'
    ) phlix_named_unique_index_shapes
);

SET @phlix_s155_albums_imposter = IF(
    @phlix_s155_albums_named_shape = 0 AND @phlix_s155_albums_named = 1,
    1,
    0
);

SET @phlix_s155_albums_dupes = IF(
    @phlix_s155_albums_unique > 0,
    0,
    (
        SELECT EXISTS (
            SELECT 1 FROM music_albums
            WHERE media_item_id IS NOT NULL
            GROUP BY media_item_id
            HAVING COUNT(*) > 1
        )
    )
);

SET @sql = IF(
    @phlix_s155_albums_dupes,
    'SELECT `music_albums duplicate media_item_id rows: dedupe first, S155`',
    'SELECT 0'
);

PREPARE stmt FROM @sql;

SET @sql = IF(
    @phlix_s155_albums_named_shape > 0 OR @phlix_s155_albums_dupes = 1,
    'SELECT 0',
    IF(
        @phlix_s155_albums_imposter = 1,
        'ALTER TABLE music_albums DROP INDEX `uq_music_albums_media_item`, ADD UNIQUE INDEX uq_music_albums_media_item (media_item_id)',
        'ALTER TABLE music_albums ADD UNIQUE INDEX uq_music_albums_media_item (media_item_id)'
    )
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @phlix_s155_albums_drops = (
    SELECT GROUP_CONCAT(CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ') FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'music_albums'
        AND NON_UNIQUE = 0
        AND INDEX_NAME <> 'uq_music_albums_media_item'
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'media_item_id'
    ) phlix_duplicate_unique_indexes
);

SET @sql = IF(
    @phlix_s155_albums_drops IS NULL OR @phlix_s155_albums_drops = '',
    'SELECT 0',
    CONCAT('ALTER TABLE music_albums ', @phlix_s155_albums_drops)
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- music_tracks
-- ---------------------------------------------------------------------------

SET @phlix_s155_tracks_unique = (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'music_tracks'
        AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'media_item_id'
    ) phlix_unique_index_shapes
);

SET @phlix_s155_tracks_named = (
    SELECT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'music_tracks'
        AND INDEX_NAME = 'uq_music_tracks_media_item'
    )
);

SET @phlix_s155_tracks_named_shape = (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'music_tracks'
        AND NON_UNIQUE = 0
        AND INDEX_NAME = 'uq_music_tracks_media_item'
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'media_item_id'
    ) phlix_named_unique_index_shapes
);

SET @phlix_s155_tracks_imposter = IF(
    @phlix_s155_tracks_named_shape = 0 AND @phlix_s155_tracks_named = 1,
    1,
    0
);

SET @phlix_s155_tracks_dupes = IF(
    @phlix_s155_tracks_unique > 0,
    0,
    (
        SELECT EXISTS (
            SELECT 1 FROM music_tracks
            WHERE media_item_id IS NOT NULL
            GROUP BY media_item_id
            HAVING COUNT(*) > 1
        )
    )
);

SET @sql = IF(
    @phlix_s155_tracks_dupes,
    'SELECT `music_tracks duplicate media_item_id rows: dedupe first, S155`',
    'SELECT 0'
);

PREPARE stmt FROM @sql;

SET @sql = IF(
    @phlix_s155_tracks_named_shape > 0 OR @phlix_s155_tracks_dupes = 1,
    'SELECT 0',
    IF(
        @phlix_s155_tracks_imposter = 1,
        'ALTER TABLE music_tracks DROP INDEX `uq_music_tracks_media_item`, ADD UNIQUE INDEX uq_music_tracks_media_item (media_item_id)',
        'ALTER TABLE music_tracks ADD UNIQUE INDEX uq_music_tracks_media_item (media_item_id)'
    )
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @phlix_s155_tracks_drops = (
    SELECT GROUP_CONCAT(CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ') FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'music_tracks'
        AND NON_UNIQUE = 0
        AND INDEX_NAME <> 'uq_music_tracks_media_item'
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'media_item_id'
    ) phlix_duplicate_unique_indexes
);

SET @sql = IF(
    @phlix_s155_tracks_drops IS NULL OR @phlix_s155_tracks_drops = '',
    'SELECT 0',
    CONCAT('ALTER TABLE music_tracks ', @phlix_s155_tracks_drops)
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
