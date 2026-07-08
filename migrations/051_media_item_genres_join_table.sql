-- Migration: 051_media_item_genres_join_table.sql
-- Description: Replace migration 050's multi-valued functional index (MVI) on
--              `metadata_json->'$.genres'` with a normalized `media_item_genres`
--              join table (Stream Quality/ABR performance step S7b).
--
-- Problem (confirmed real via CI + a dedicated stress test, see
-- quality_worklog.md's "S7 InnoDB/MVI risk" Coordinator entries for the full
-- investigation):
--   Migration 050 added `ADD INDEX idx_media_items_genres
--   ((CAST(metadata_json->'$.genres' AS CHAR(255) ARRAY)))` on `media_items`.
--   Under sustained create+cascade-delete churn (the exact pattern production
--   scan/rescan/re-match traffic sustains indefinitely), this multi-valued
--   index reproducibly triggers real InnoDB purge-thread errors:
--     [MY-012869] [InnoDB] Record in index idx_media_items_genres was not
--     found on update: TUPLE (...) at: ...
--   CI's single round (50 rows created + cascade-deleted) already logged 73 of
--   these error lines. A dedicated stress harness replaying the same
--   create/cascade-delete shape at realistic scan-churn volume — 50 rounds x
--   300 rows = 15,000 rows total, run against MySQL 8.4.10 (prod's exact
--   version) — escalated this to 29,900 error lines, recurring continuously
--   across 58 distinct one-second timestamp buckets spanning the ENTIRE ~5.5
--   minute run, not a single isolated burst. The error count scaled with churn
--   volume rather than converging on a small fixed number, and distinct row
--   UUIDs appeared on both sides of the error throughout the run — ruling out
--   the original "one stale leftover entry" theory. This is a confirmed real
--   MySQL 8 MVI bug-surface risk under production-scale load, not log noise
--   (see MySQL bug trackers #109542 / #114756, both "Can't repeat" upstream but
--   consistent with what this stress test reproduced).
--
-- Fix (this migration + the matching ItemRepository write-path / query
-- changes in the same PR):
--   Replace the MVI with an ordinary normalized join table,
--   `media_item_genres (media_item_id, genre)`, with a plain B-tree PRIMARY
--   KEY and a plain B-tree secondary index on `genre` alone. Neither uses the
--   MVI feature at all, so this sidesteps that MySQL feature's bug surface
--   entirely — it is the same InnoDB machinery every other join table in this
--   schema (e.g. `user_item_data`, migration 039) already relies on without
--   incident.
--
--   COLLATION (Reviewer finding, S7b fix round): `genre` is declared
--   `COLLATE utf8mb4_bin` — deliberately NOT the table's/schema's default
--   `utf8mb4_unicode_ci`. The predicate this table replaces,
--   `? MEMBER OF (metadata_json->'$.genres')`, is a case/accent-SENSITIVE
--   exact-value JSON string comparison (verified empirically: 'action' MEMBER
--   OF '["Action"]' is false). A `_unicode_ci`-collated `genre` column would
--   make `WHERE genre IN (...)` case/accent-INSENSITIVE instead — a silent,
--   undocumented behavior change from the pre-051 filter semantics
--   (`getByAllowedGenres()` / `buildFilters()`'s genre predicate). `utf8mb4_bin`
--   restores the original exact-match semantics. Declaring the collation on
--   the COLUMN (not applying `COLLATE utf8mb4_bin` only at query time against
--   a `_unicode_ci`-collated column) also keeps `idx_media_item_genres_genre`
--   fully usable for the comparison — a query-time `COLLATE` clause against a
--   differently-collated indexed column can force MySQL to evaluate the
--   collation conversion per row instead of using the index, i.e. exactly the
--   full-scan risk this migration exists to avoid re-introducing by a
--   different route. `distinctGenres()` (the facet-scan) is UNCHANGED by this
--   — see that method's own comment for why its case-insensitive DISTINCT
--   behavior is intentionally kept as-is.
--
--   IMPORTANT: `metadata_json.$.genres` on `media_items` REMAINS the canonical
--   source of truth for genre data — API responses, MediaItemShaper, etc. all
--   continue to read genres directly out of metadata_json exactly as before.
--   `media_item_genres` is purely a DERIVED secondary index, kept in sync by
--   ItemRepository's write path (create()/update() via the new
--   extractGenres()/syncGenreRows() helpers) whenever metadata_json is
--   written. It is not a replacement for the JSON blob and must never be
--   treated as an independent source of genre data — if it and metadata_json
--   ever disagree, metadata_json wins; re-running the backfill below (or a
--   rescan) will reconcile it.
--
-- Backfill: INSERT IGNORE ... SELECT ... JSON_TABLE(...) unnests every
-- existing row's metadata_json.$.genres array into media_item_genres. This is
-- idempotent by construction: INSERT IGNORE silently skips rows that already
-- satisfy the (media_item_id, genre) PRIMARY KEY, so re-running this migration
-- (the apply-all-every-time contract every migration file in this repo obeys
-- — there is no migration-tracking table) is always a safe no-op once the
-- backfill has completed once. `jt.genre IS NOT NULL AND jt.genre <> ''`
-- mirrors the same non-empty-string filter ItemRepository::extractGenres()
-- applies on the PHP write-path side, so backfilled rows and freshly-written
-- rows share the same shape.
--
-- Dropping the MVI: `ALTER TABLE media_items DROP INDEX
-- idx_media_items_genres` removes the risky index now that
-- `media_item_genres` supersedes it for filtering/faceting. This DROP INDEX
-- is safe to run even on an environment where migration 050 was never applied
-- (e.g. any environment that hasn't yet been deployed since 050 shipped) —
-- MigrationRunner::isExpectedIdempotentError() already downgrades the
-- resulting "check that column/key exists" error to a note (matches the same
-- allowlisted substring a re-run of a DROP INDEX on an already-absent index
-- always raises), so 050 and 051 are safe to ship and deploy together in any
-- order/state.
--
-- NOT implicated / unchanged: migration 050's `sort_title` and
-- `content_rating` columns and their indexes
-- (idx_media_items_library_type_sort_title, idx_media_items_content_rating)
-- are completely untouched by this migration. They involve no multi-valued
-- index and no genre data at all, and the stress test's `CHECK TABLE
-- media_items EXTENDED` (which covers the whole table, not just the genre
-- index) came back clean — only the genre MVI is being removed here.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS is safe to re-run (matches migration
-- 039's convention). The backfill INSERT IGNORE is safe to re-run (PK-guarded,
-- see above). The DROP INDEX is safe to re-run (idempotent-error-downgraded,
-- see above). Each statement is split on `;` by the quote/comment-aware
-- runner; keep them separate.

CREATE TABLE IF NOT EXISTS media_item_genres (
    media_item_id CHAR(36)     NOT NULL,
    genre         VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    PRIMARY KEY (media_item_id, genre),
    INDEX idx_media_item_genres_genre (genre),
    CONSTRAINT fk_media_item_genres_media_item_id
        FOREIGN KEY (media_item_id) REFERENCES media_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO media_item_genres (media_item_id, genre)
SELECT mi.id, jt.genre
  FROM media_items mi,
       JSON_TABLE(mi.metadata_json, '$.genres[*]'
                  COLUMNS (genre VARCHAR(255) PATH '$')) AS jt
 WHERE jt.genre IS NOT NULL
   AND jt.genre <> '';

ALTER TABLE media_items
    DROP INDEX idx_media_items_genres;
