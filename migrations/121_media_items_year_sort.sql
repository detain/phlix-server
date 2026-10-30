-- Migration: 121_media_items_year_sort.sql
--
-- minors M-SERVER-20 (W-min100): materialize the browse release-year sort key.
-- (ID note: "M-SERVER-20" here is the MINORS-queue item; the majors queue
-- reuses the same literal for a different ask — pair queue + ID, estate law.)
--
-- WHY: `GET /api/v1/libraries/{id}/items?sort=year` — the hot library browse
-- path — ORDER BYed a per-row function of the JSON blob:
--
--   CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.year')) AS SIGNED)
--
-- No index can cover that expression, so the whole candidate set is
-- extracted, cast and filesorted per query. It is the LAST unpinned sibling
-- of the materialize-what-you-sort family: migrations 050/051 gave
-- `sort_title` and `content_rating` real indexed columns for exactly this
-- reason (050 is code-maintained; this one is generated — see SHAPE).
--
-- SHAPE: a STORED generated column. Every write path that touches
-- metadata_json (library scan, metadata resolvers, manual edits, refresh
-- jobs) would have to remember to re-stamp a code-maintained key; the
-- database derives it from the same extraction the ORDER BY used, so the
-- materialized value can NEVER drift from the semantics this file cures.
-- `metadata_json` is JSON-typed (001), so the extraction cannot error on
-- malformed JSON.
--
-- WHY THE CASE GUARD IS NOT OPTIONAL (measured on MySQL 8.4, scratch DB):
-- a bare `CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.year')) AS SIGNED)`
-- as the column expression FAILS the ALTER with `ERROR 1292 Truncated
-- incorrect INTEGER value: 'null'` the moment one row carries `{"year":
-- null}` — JSON null unquotes to the STRING 'null', whose CAST warns in a
-- SELECT but is promoted to an error while evaluating a STORED generated
-- column. The guard: SQL NULL (path missing) stays NULL (the legacy
-- expression NULL-sorted first); a digit-only value parses exactly as the
-- legacy CAST did; anything else folds to 0 — matching the legacy
-- CAST-returns-0 behavior for 'abc'-class garbage. Stated deviation,
-- accepted: legacy CAST prefix-parsed junk strings ({"year":"1999 specials"}
-- sorted as 1999) and rounded decimals; under the guard those join the 0
-- cluster. Real metadata stores year as a number or a clean digit string —
-- every probe value in both families is byte-identical; the fold only
-- re-ranks values that were never years.
--
-- TYPE: the ask said SMALLINT, shipped SMALLINT WITH a clamp (LEAST/
-- GREATEST). A bare SMALLINT would turn a hostile metadata year (99999999)
-- into an out-of-range write ERROR — this column must never break a
-- metadata write. The clamp folds every out-of-range value to the domain
-- edge, which preserves ranking against real 4-digit years exactly as the
-- legacy bigint-wide CAST ranked them.
--
-- COST, stated honestly: ADD COLUMN ... GENERATED ... STORED rebuilds
-- media_items (INSTANT cannot add stored generated columns). That is the
-- same rebuild class 050's backfill-era ALTERs accepted on this table. Run
-- it inside a normal deploy window.
--
-- INDEX: (library_id, year_sort) — browse requests filter by library, then
-- sort by year, so the read resolves as an ordered range scan. Name follows
-- the 118 `idx_media_items_*` precedent. ADD INDEX on the rebuilt table is
-- INPLACE with concurrent DML.
--
-- BACKFILL: none needed — STORED generated columns are computed by the
-- rebuild itself (contrast 112, whose plain column needed explicit DML).
--
-- IDEMPOTENCE: the INFORMATION_SCHEMA + PREPARE guard of migrations
-- 107/112 (ADD COLUMN / ADD INDEX have no IF-[NOT-]EXISTS form); both guards
-- short-circuit to SELECT 1 on replay.

SET @dbname = DATABASE();

-- (1) The materialized year key, guarded (107 idiom).
-- @phlix-require-mysql 8.0.13 JSON-typed metadata_json generated-column extraction (dialect floor per the 011 precedent; the syntax itself is MySQL 5.7+)
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'media_items' AND COLUMN_NAME = 'year_sort') = 0,
    'ALTER TABLE media_items ADD COLUMN year_sort SMALLINT GENERATED ALWAYS AS (CASE WHEN JSON_EXTRACT(metadata_json, ''$.year'') IS NULL THEN NULL WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata_json, ''$.year'')) REGEXP ''^[0-9]+$'' THEN LEAST(GREATEST(CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, ''$.year'')) AS SIGNED), -32768), 32767) ELSE 0 END) STORED',
    'SELECT 1'
));
PREPARE addColumnIfNotExists FROM @preparedStatement;
EXECUTE addColumnIfNotExists;
DEALLOCATE PREPARE addColumnIfNotExists;

-- (2) The browse index, guarded (same idiom against INFORMATION_SCHEMA.STATISTICS
-- so a replay never eats error 1061 mid-file).
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'media_items' AND INDEX_NAME = 'idx_media_items_library_year') = 0,
    'ALTER TABLE media_items ADD INDEX idx_media_items_library_year (library_id, year_sort)',
    'SELECT 1'
));
PREPARE addIndexIfNotExists FROM @preparedStatement;
EXECUTE addIndexIfNotExists;
DEALLOCATE PREPARE addIndexIfNotExists;
