-- Migration: 105_stats_storage_unique_key_accumulation.sql
-- Description: make a duplicate `stats_storage` snapshot structurally impossible:
--              deterministic `library_id`, merge of pre-existing duplicates with
--              ACCUMULATED totals, then the UNIQUE key
--              `(recorded_at, media_type, library_id)` (S114).
-- S114STATSUPSERTX3F6
--
-- THE DEFECT (S114, fix r1 for S102's aftermath). `stats_storage` has carried no
-- unique index since migration 019 created it, so two concurrent snapshot writers
-- (the daemon timer and `public/index.php`'s FPM bootstrap, or two FPM requests
-- that both observe stale data) can each write a full five-row generation for the
-- same second. S102 made the READER survive that by SUMming every row of the
-- newest `recorded_at` per bucket, but the duplicate rows are still there and every
-- future reader has to remember to aggregate. The write path gains the matching
-- accumulating upsert in `StatsCollector::recordStorageSnapshots()` — THAT half
-- ships in the same release as this file; this migration alone would turn the
-- second writer's plain INSERT into a rejected (contained, logged) 1062.
--
-- WHY THE INDEX CANNOT BE ADDED BLIND — the NULL correction, measured on real
-- MySQL 8.0.46 with STRICT_TRANS_TABLES (this repo's session mode, pinned by
-- tests/Integration/Stats/PlaybackEventMediaTypeEnumTest::testStrictTransTablesIsActive):
--
--   * `library_id` is NULLABLE today and MySQL treats NULLs as DISTINCT in a
--     UNIQUE index. The bare `ALTER … ADD UNIQUE (recorded_at, media_type,
--     library_id)` SUCCEEDS with duplicate NULL rows present and then rejects
--     NOTHING: three identical NULL-library tuples gave accepted=3, rejected=0.
--     Shipping only the index would look like a clean pass while changing nothing
--     (the INERT failure mode).
--   * With a non-NULL `library_id` the same index bites hard: duplicate rows
--     collide (1062), so a plain INSERT or INSERT IGNORE behind it DESTROYS
--     per-run data (the DESTRUCTIVE failure mode). Only the combination
--     deterministic column + UNIQUE key + SUMMING upsert is correct, and in that
--     order.
--   * `ALTER TABLE … MODIFY library_id CHAR(36) NOT NULL` on rows that still hold
--     NULL does NOT quietly convert them on this build: it fails with error 1138
--     "Invalid use of NULL value" (measured). So determinism is established in TWO
--     steps — an explicit `UPDATE … SET library_id = '' WHERE library_id IS NULL`
--     first, the `MODIFY` second — with the duplicate merge BETWEEN them: the
--     UPDATE can turn a former-NULL row into the twin of an existing `''` row
--     (`''` is the sentinel the write path now binds for "not library-scoped"), and
--     merge-before-MODIFY also lets one merge statement cover both spellings
--     instead of two.
--
-- MERGE, NOT REFUSAL. Unlike 097 (whose keeper rule is a semantic choice that had
-- to live in PHP) the merge here is unambiguous arithmetic: the reader's totals are
-- SUMs, so summing each duplicate group into one surviving row preserves exactly
-- what every reader computes — `SUM(SUM(group)) = SUM(table)`, byte-exact, whatever
-- the group order. The survivor is `MIN(id)` per group (deterministic under a fixed
-- id set) and keeps its original id, matching the upsert's rule that the surviving
-- row's identity never changes. First-write-wins is FORBIDDEN (S102 reader sums
-- every row, so dropping a row silently shrinks reader-visible totals).
--
-- IDEMPOTENCY. The SV-4.9 `schema_migrations` ledger (MigrationRunner) skips an
-- already-recorded, unchanged file on a normal deploy, so this file is NOT re-run
-- per deploy. It still must be a true no-op on the two ledger-bypassing paths —
-- a raw `mysql < file` replay and a lost/deleted ledger row — and it is: every
-- mutating statement is behind an information_schema shape probe with the 103/096/
-- 097 conditional PREPARE/EXECUTE idiom (`IDEMPOTENT_ERROR_CODES` = 1050/1060/
-- 1061/1091 does NOT include 1062 or 1138, so an unguarded collision on a replay
-- would fail LOUDLY — by intent). A run that dies mid-file converges on retry:
-- each pass re-derives its probes from the live schema, and each phase is
-- individually replay-safe (UPDATE matches no NULL rows once the column is NOT
-- NULL; the merge finds no groups once the UNIQUE key enforces one; MODIFY of an
-- identical definition is a no-op; ADD is guarded).
--
-- TOCTOU, RECORDED NOT FIXED (097 doctrine). Between the NULL-clobbering UPDATE
-- and the ADD UNIQUE, the still-deployed OLD write path could insert a fresh
-- NULL-`library_id` row, or two same-key rows. Both fail SAFE and loud: the UPDATE
-- already ran so the MODIFY may still hit a new NULL (1138 → error recorded, file
-- stays unrecorded, retried next deploy — which now ships the upsert), or the ADD
-- hits 1062 (same terminal behavior). A retrying operator converges BYTE-EXACTLY:
-- the merge deliberately ignores NULL-keyed rows (see STEP 1b) so a raced-in NULL
-- pair is never half-merged — nothing is inflated, deleted, or silently
-- half-applied, and the retry's clobber folds those rows with the rest.
--
-- FORWARD-ONLY (R4). 019 and 086 stay byte-identical forever; their ledger
-- checksums e7032697acbfc7b31f5f45b5ec16581a and 4ad8d1c323159c2206b34e62320f7189
-- are pinned by this file's guard test.
--
-- SURVIVAL TOKEN: S114STATSUPSERTX3F6 (code-resident in
-- tests/Integration/Stats/StatsStorageUniqueKeyUpsertGuardTest.php).
--
-- Proven against real MySQL by
-- tests/Integration/Stats/StatsStorageUniqueKeyUpsertGuardTest.php:
--   * column metadata after apply: char(36), IS_NULLABLE=NO, COLUMN_DEFAULT='';
--   * SHOW INDEX: named unique over exactly (recorded_at, media_type, library_id)
--     in that order, NON_UNIQUE=0;
--   * duplicate merge (seeded INCLUDING NULL-library rows) preserves accumulated
--     totals byte-exact, keeps MIN(id) per group with its original id, and is
--     provably NOT first-write-wins;
--   * pre-105 control: the same duplicate NULL set under the old schema accepts
--     everything (the inert condition is real), post-105 explicit-NULL INSERT
--     fails 1048 and same-triple INSERT fails 1062;
--   * the accumulating upsert (write path) yields ONE summed row for a
--     same-second double write — the destructive-clobber scenario cannot recur;
--   * DashboardService::getStorageSummary() output is byte-identical before and
--     after this migration over the same data;
--   * ledger-bypassing replay of the whole file is a silent no-op; the ledger row
--     exists after a clean apply; 019/086 checksums unmoved.
--
-- @copyright 2026 Joe Huss <detain@interserver.net>
-- @license   MIT
-- @since     S114

-- ---------------------------------------------------------------------------
-- STATE PROBES. Captured before anything mutates, and re-derived after the
-- index drop, so one pass is self-describing and a mid-file crash converges.
-- ---------------------------------------------------------------------------

-- Is `library_id` still nullable? 1 when the pre-105 shape is live.
SET @phlix_s114_nullable = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stats_storage'
    AND COLUMN_NAME = 'library_id'
    AND IS_NULLABLE = 'YES'
);

-- ---------------------------------------------------------------------------
-- STEP 0 — DEFUSE AN INERT INDEX. If a UNIQUE key over exactly the three
-- constraint columns already exists WHILE the column is still nullable, it is
-- the inert artifact of a blind add (its NULL rows collide with nothing —
-- measured). Drop every shape-adequate unique BEFORE normalizing, because the
-- normalization UPDATE below could otherwise raise 1062 against that very
-- index while converting NULL rows to ''. When the set is empty the prepared
-- statement is `SELECT 0` — a true no-op, so this is silent on the canonical
-- pre-105 chain-built box.
-- ---------------------------------------------------------------------------
SET @phlix_s114_inert_drops = IF(
    @phlix_s114_nullable > 0,
    (
        SELECT GROUP_CONCAT(CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ') FROM (
            SELECT INDEX_NAME
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'stats_storage'
            AND NON_UNIQUE = 0
            GROUP BY INDEX_NAME
            HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'library_id,media_type,recorded_at'
        ) phlix_inert_unique_indexes
    ),
    NULL
);

SET @sql = IF(
    @phlix_s114_inert_drops IS NULL OR @phlix_s114_inert_drops = '',
    'SELECT 0',
    CONCAT('ALTER TABLE stats_storage ', @phlix_s114_inert_drops)
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- STEP 1 — MAKE `library_id` DETERMINISTIC, part 1: the explicit clobber.
-- MODIFY … NOT NULL does NOT convert existing NULLs on this build — it fails
-- 1138 (measured above) — so NULLs are collapsed onto the '' sentinel here
-- FIRST. The sentinel means "this row is not scoped to one library", exactly
-- what NULL carried; both live snapshot callers (MaintenanceTaskRunner's
-- storage-snapshot task, StorageSnapshotHelper::bootstrapSnapshot) have always
-- written unscoped rows. This can create same-(recorded_at, media_type) pairs
-- with a pre-existing '' twin — STEP 1b merges them — which is why the merge
-- is BETWEEN the two halves of determinism, not before both.
-- ---------------------------------------------------------------------------
SET @sql = IF(
    @phlix_s114_nullable > 0,
    'UPDATE stats_storage SET library_id = '''' WHERE library_id IS NULL',
    'SELECT 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- STEP 1b — MERGE PRE-EXISTING DUPLICATES, PRESERVING ACCUMULATED TOTALS.
-- One group = every row sharing (recorded_at, media_type, library_id). The
-- survivor (MIN(id)) receives the group's SUMs; every other member is deleted.
-- Unconditional: cheap (`HAVING COUNT(*) > 1` yields nothing on a clean table,
-- both statements then touch 0 rows), self-healing on half-applied replays,
-- and it is the reason the ADD below cannot meet a duplicate on data that was
-- merely present at entry. The reader invariant `SUM(SUM(group)) = SUM(table)`
-- makes this invisible to `getStorageSummary()` byte-for-byte.
--
-- ⚠ The survivor SUMs each group even for buckets whose bytes were always
-- NULL-safe: `item_count`/`total_bytes`/`transcode_cache_bytes` are nullable
-- columns (019) and `SUM()` over an all-NULL group yields NULL, which is what
-- the reader already summed them to before 105 — so the copy is faithful, and
-- the write path never binds NULL into them (ints, defaulting 0).
--
-- ⚠ `WHERE library_id IS NOT NULL` is load-bearing under the TOCTOU window
-- above: GROUP BY folds NULL keys into ONE group, but the DELETE's equality
-- JOIN can never match a NULL loser — folding such a group would inflate the
-- survivor with values whose rows survive (double-counted on retry, breaking
-- byte-exactness). Excluding NULL-keyed rows leaves any raced-in NULL pair
-- UNTOUCHED for the MODIFY to reject loudly (1138), and the retry's clobber
-- then merges it correctly. For every non-racing input the filter is inert:
-- STEP 1 has already collapsed entry NULLs, and a NOT NULL column holds none.
-- ---------------------------------------------------------------------------
UPDATE stats_storage survivor
JOIN (
    SELECT recorded_at, media_type, library_id,
           SUM(item_count) AS sum_item_count,
           SUM(total_bytes) AS sum_total_bytes,
           SUM(transcode_cache_bytes) AS sum_transcode_cache_bytes,
           MIN(id) AS keep_id
    FROM stats_storage
    WHERE library_id IS NOT NULL
    GROUP BY recorded_at, media_type, library_id
    HAVING COUNT(*) > 1
) phlix_duplicate_groups ON survivor.id = phlix_duplicate_groups.keep_id
SET survivor.item_count = phlix_duplicate_groups.sum_item_count,
    survivor.total_bytes = phlix_duplicate_groups.sum_total_bytes,
    survivor.transcode_cache_bytes = phlix_duplicate_groups.sum_transcode_cache_bytes;

DELETE loser
FROM stats_storage loser
JOIN (
    SELECT recorded_at, media_type, library_id, MIN(id) AS keep_id
    FROM stats_storage
    WHERE library_id IS NOT NULL
    GROUP BY recorded_at, media_type, library_id
    HAVING COUNT(*) > 1
) phlix_duplicate_survivors
    ON loser.recorded_at = phlix_duplicate_survivors.recorded_at
    AND loser.media_type = phlix_duplicate_survivors.media_type
    AND loser.library_id = phlix_duplicate_survivors.library_id
    AND loser.id <> phlix_duplicate_survivors.keep_id;

-- ---------------------------------------------------------------------------
-- STEP 1c — DETERMINISM, part 2: NOT NULL DEFAULT ''. No NULL rows can exist
-- here: they were collapsed at step 1 (or were impossible already). Re-stating
-- the full definition: CHAR(36), the table-inherited utf8mb4/utf8mb4_unicode_ci,
-- the '' default. MODIFY with an identical definition is a no-op, so the guarded
-- replay is silent.
-- ---------------------------------------------------------------------------
SET @sql = IF(
    @phlix_s114_nullable > 0,
    'ALTER TABLE stats_storage MODIFY COLUMN library_id CHAR(36) NOT NULL DEFAULT ''''',
    'SELECT 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- STEP 2 — THE UNIQUE KEY, shape-guarded the S161 way: "already there?" is
-- answered by the COLUMN SET under ANY name (the conflict target of the upsert
-- does not care about index names), never by name alone — a same-named
-- NON-UNIQUE imposter must not satisfy the probe and is dropped-and-replaced in
-- ONE atomic ALTER (a 1062 on the ADD rolls the DROP back with it). A
-- correctly-shaped UNIQUE under a foreign name on an already-deterministic
-- column enforces the constraint, so this file stays a no-op and leaves it
-- alone — same doctrine as 097/103.
-- ---------------------------------------------------------------------------
SET @phlix_s114_unique_ok = (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'stats_storage'
        AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'library_id,media_type,recorded_at'
    ) phlix_unique_index_shapes
);

SET @phlix_s114_named = (
    SELECT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'stats_storage'
        AND INDEX_NAME = 'uq_stats_storage_recorded_media_library'
    )
);

SET @phlix_s114_imposter = IF(
    @phlix_s114_unique_ok = 0 AND @phlix_s114_named = 1,
    1,
    0
);

SET @sql = IF(
    @phlix_s114_unique_ok > 0,
    'SELECT 0',
    IF(
        @phlix_s114_imposter = 1,
        'ALTER TABLE stats_storage DROP INDEX `uq_stats_storage_recorded_media_library`, ADD UNIQUE INDEX uq_stats_storage_recorded_media_library (recorded_at, media_type, library_id)',
        'ALTER TABLE stats_storage ADD UNIQUE INDEX uq_stats_storage_recorded_media_library (recorded_at, media_type, library_id)'
    )
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
