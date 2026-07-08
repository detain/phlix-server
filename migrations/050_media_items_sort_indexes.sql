-- Migration: 050_media_items_sort_indexes.sql
-- Description: Materialize the article-stripped sort key + content rating, and
--              add indexes for the library browse / genre-filter / rating-filter
--              hot paths (Stream Quality/ABR performance step S7).
--
-- Problem (from the perf audit):
--   * Every library listing sorts on SortTitle::sqlExpression() — a per-row
--     CASE/LOWER/SUBSTRING expression on `name` — so MySQL can never satisfy the
--     ORDER BY from an index and always filesorts the full result set.
--   * Genre filters used JSON_CONTAINS(metadata_json, ?, '$.genres') and rating
--     filters used JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.rating')); neither
--     can use an index, so both full-scan media_items (only idx_library /
--     idx_parent / idx_type + a FULLTEXT existed).
--
-- Fix (this migration + the matching ItemRepository write-path / query changes):
--   * `sort_title`     -- the article-stripped sort key, MATERIALIZED at write
--                         time by ItemRepository::create()/update() via
--                         SortTitle::from(). Its value is branch-for-branch
--                         identical to SortTitle::sqlExpression() (see that
--                         class), so ORDER BY sort_title reproduces the exact
--                         order the old runtime expression produced — but now
--                         from a plain, indexable column.
--   * `content_rating` -- the content rating (G/PG/PG-13/...) copied out of
--                         metadata_json.$.rating at write time, so rating
--                         filters/sorts hit an indexed column instead of a JSON
--                         extraction. VARCHAR(32) comfortably fits every real
--                         certificate label (e.g. "TV-Y7-FV", "NC-17").
--
--   * Index `(library_id, type, sort_title, name)` — the browse index. The
--     listing WHERE is `library_id = ? AND type = ?` (getByType) with
--     `ORDER BY sort_title, name`; including the trailing `name` tiebreak in the
--     index lets MySQL satisfy BOTH the equality range and the full ORDER BY from
--     the index with NO filesort (verified with EXPLAIN: Extra = NULL). This is a
--     superset of the plan's `(library_id, type, sort_title)` — the extra `name`
--     column only removes the residual filesort the `, name` stable-paging
--     tiebreak would otherwise force. utf8mb4 key length ≈ 145 + 1020 bytes, well
--     under the InnoDB DYNAMIC row-format 3072-byte index-key limit MySQL 8 uses
--     by default (the same limit migration 043's composite index relies on).
--   * Index `(content_rating)` — the rating filter / parental-control range scan.
--   * Multi-valued index on CAST(metadata_json->'$.genres' AS CHAR(255) ARRAY)
--     (MySQL 8.0.17+) — makes the genre membership predicate index-usable while
--     keeping genres in metadata_json exactly where distinctGenres()'s JSON_TABLE
--     facet scan (step S5) reads them. This is the "generated column" option the
--     S7 plan allows; it needs NO genre write-path change and NO genre backfill
--     (MySQL maintains the index from metadata_json automatically), so S5's
--     genre-facet cache and its invalidation wiring are untouched — there is no
--     new stale-cache surface. ItemRepository's genre filters are rewritten to
--     `? MEMBER OF (metadata_json->'$.genres')`, which the optimizer resolves
--     against this index (verified with EXPLAIN). CHAR(255) matches the width
--     distinctGenres() already unnests genres at, so no genre value that fits the
--     facet scan can overflow the functional-index key.
--
-- Backfill: existing rows get sort_title (via the same CASE expression
-- SortTitle::sqlExpression() emits — kept in lockstep with SortTitle::ARTICLES;
-- update BOTH if the article list ever changes) and content_rating (via the same
-- JSON extraction the old query used). Both UPDATEs are guarded on `IS NULL` so a
-- re-run — or a later run of scripts/backfill-sort-metadata.php — is a no-op and
-- never clobbers a value already written. Genres need no backfill (the
-- multi-valued index is derived from metadata_json).
--
-- Idempotent: MySQL 8 has no IF NOT EXISTS on ADD COLUMN / ADD INDEX, so a
-- re-run raises "Duplicate column name" / "Duplicate key name" which
-- MigrationRunner::isExpectedIdempotentError() downgrades to a note. The backfill
-- UPDATEs are naturally idempotent (deterministic + IS NULL guarded). Each
-- statement is split on `;` by the quote/comment-aware runner; keep them
-- separate.
--
-- ===========================================================================
-- DEPLOY RUNBOOK NOTE (for the I3 deploy step) — READ BEFORE RUNNING ON PROD
-- ===========================================================================
-- The multi-valued genre index below —
--   ADD INDEX idx_media_items_genres ((CAST(metadata_json->'$.genres' AS CHAR(255) ARRAY)))
-- is the ONLY statement in this migration that is NOT idempotency-safe against
-- malformed data. Unlike the "Duplicate column/key name" re-run errors that
-- MigrationRunner::isExpectedIdempotentError() swallows, this ALTER will HARD-FAIL
-- (aborting the migration on that instance) if ANY existing row has:
--   * a `metadata_json->'$.genres'` element longer than 255 chars
--     → "Data too long for functional index column" (ER_DATA_TOO_LONG-class), or
--   * a `$.genres` value that is NOT a JSON array (e.g. a JSON object, or a
--     scalar where an array is expected) → a CAST/cannot-store-array error.
-- These are NOT in isExpectedIdempotentError()'s allowlist, so the runner does
-- NOT downgrade them — the migration stops.
--
-- Real genre arrays are short, well-formed string arrays (this mirrors the same
-- array-of-strings assumption distinctGenres()'s JSON_TABLE facet scan already
-- makes, so it is not a NEW risk — but distinctGenres() degrades gracefully on a
-- bad row whereas this ALTER does not). Probability is low, but the failure mode
-- is a stopped deploy, so BEFORE running this migration on the live box, run a
-- pre-flight sanity check and clean up any offending rows:
--
--   -- rows whose genres are not a JSON array (object/scalar/null-shaped):
--   SELECT id, JSON_TYPE(metadata_json->'$.genres') AS genres_type
--     FROM media_items
--    WHERE metadata_json->'$.genres' IS NOT NULL
--      AND JSON_TYPE(metadata_json->'$.genres') <> 'ARRAY';
--
--   -- rows with an over-long (>255 char) genre element:
--   SELECT mi.id, jt.g
--     FROM media_items mi,
--          JSON_TABLE(mi.metadata_json, '$.genres[*]'
--                     COLUMNS (g VARCHAR(1024) PATH '$')) AS jt
--    WHERE CHAR_LENGTH(jt.g) > 255;
--
-- If either returns rows, normalize/repair those metadata_json.genres shapes
-- (or NULL out the malformed $.genres) before applying migration 050. Genres
-- need no backfill, so cleaning the blob is sufficient — the index derives from
-- it automatically once the shapes are valid.
-- ===========================================================================

ALTER TABLE media_items
    ADD COLUMN sort_title VARCHAR(255) NULL DEFAULT NULL AFTER canonical_key;

ALTER TABLE media_items
    ADD COLUMN content_rating VARCHAR(32) NULL DEFAULT NULL AFTER sort_title;

ALTER TABLE media_items
    ADD INDEX idx_media_items_library_type_sort_title (library_id, type, sort_title, name);

ALTER TABLE media_items
    ADD INDEX idx_media_items_content_rating (content_rating);

ALTER TABLE media_items
    ADD INDEX idx_media_items_genres ((CAST(metadata_json->'$.genres' AS CHAR(255) ARRAY)));

UPDATE media_items
   SET sort_title = TRIM(CASE WHEN LOWER(LEFT(name, 4)) COLLATE utf8mb4_bin = 'the ' THEN SUBSTRING(name, 5) WHEN LOWER(LEFT(name, 2)) COLLATE utf8mb4_bin = 'a ' THEN SUBSTRING(name, 3) WHEN LOWER(LEFT(name, 3)) COLLATE utf8mb4_bin = 'an ' THEN SUBSTRING(name, 4) WHEN LOWER(LEFT(name, 3)) COLLATE utf8mb4_bin = 'el ' THEN SUBSTRING(name, 4) WHEN LOWER(LEFT(name, 3)) COLLATE utf8mb4_bin = 'la ' THEN SUBSTRING(name, 4) WHEN LOWER(LEFT(name, 3)) COLLATE utf8mb4_bin = 'le ' THEN SUBSTRING(name, 4) WHEN LOWER(LEFT(name, 4)) COLLATE utf8mb4_bin = 'les ' THEN SUBSTRING(name, 5) WHEN LOWER(LEFT(name, 4)) COLLATE utf8mb4_bin = 'los ' THEN SUBSTRING(name, 5) WHEN LOWER(LEFT(name, 4)) COLLATE utf8mb4_bin = 'las ' THEN SUBSTRING(name, 5) WHEN LOWER(LEFT(name, 4)) COLLATE utf8mb4_bin = 'die ' THEN SUBSTRING(name, 5) WHEN LOWER(LEFT(name, 4)) COLLATE utf8mb4_bin = 'der ' THEN SUBSTRING(name, 5) WHEN LOWER(LEFT(name, 4)) COLLATE utf8mb4_bin = 'das ' THEN SUBSTRING(name, 5) ELSE name END)
 WHERE sort_title IS NULL;

UPDATE media_items
   SET content_rating = JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.rating'))
 WHERE content_rating IS NULL
   AND JSON_EXTRACT(metadata_json, '$.rating') IS NOT NULL;
