-- Migration: 043_media_items_canonical_key.sql
-- Description: Add an indexed `canonical_key` column to media_items for the
--              series/movie auto-merge dedup feature (Feature 1, Step 1.5).
--
-- Feature 1 (auto-merge duplicate series/movies) resolves a top-level container
-- (series or movie) by a normalised dedup key produced by
-- src/Media/Library/CanonicalKey.php, so that title-slug variance (separators,
-- year bleed, a parse failure, a flat->per-directory re-scan, or a concurrent-scan
-- race) no longer forks a second top-level row. Since Step 1.2 the scanner has
-- stamped that key into `metadata_json.canonical_key`, and
-- ItemRepository::findTopLevelByCanonical() matched it with
-- JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.canonical_key')) — which can never
-- use an index. This migration promotes the key to a first-class indexed column
-- so the lookup is a fast indexed scan.
--
--   * `canonical_key` is NULLABLE: a row whose title has nothing alphanumeric
--     and no year/external id has no meaningful key, so it stays NULL (never
--     collapsed into other unkeyable rows). VARCHAR(191) — the utf8mb4 index
--     key-length limit (191 * 4 bytes = 764 < the 767-byte legacy InnoDB index
--     prefix limit), NOT 255 (which would be unindexable under utf8mb4 on older
--     InnoDB row formats).
--
--   * The index `(library_id, type, canonical_key)` is deliberately NON-UNIQUE.
--     Historical duplicates already exist (the very rows this feature merges),
--     so a UNIQUE constraint would reject the migration / future writes.
--     Uniqueness is enforced in application code (Step 1.2's
--     findOrCreateContainer reuses an existing container on a canonical hit), NOT
--     by the database. The index column order matches the
--     findTopLevelByCanonical() WHERE (library_id = ? AND type = ? AND
--     canonical_key = ?) so the lookup is index-covered.
--
--   * Backfill: every top-level row (parent_id IS NULL) that already carries a
--     `metadata_json.canonical_key` (stamped by the Step 1.2 scanner) has the
--     value copied into the new column. JSON_EXTRACT yields SQL NULL for rows
--     without the key, so the WHERE guard skips them and they stay NULL.
--
-- Idempotent: re-running the ADD COLUMN raises "Duplicate column name" and the
-- ADD INDEX raises "Duplicate key name"; the migration runner downgrades both to
-- notes rather than failures (MySQL 8 has no IF NOT EXISTS on ADD COLUMN /
-- ADD INDEX — see MigrationRunner::isExpectedIdempotentError). The backfill
-- UPDATE is naturally idempotent (re-running re-derives the same values). Each
-- statement is split on `;` by the quote/comment-aware runner; keep them
-- separate.

ALTER TABLE media_items
    ADD COLUMN canonical_key VARCHAR(191) NULL DEFAULT NULL AFTER path;

ALTER TABLE media_items
    ADD INDEX idx_media_items_library_type_canonical (library_id, type, canonical_key);

UPDATE media_items
   SET canonical_key = JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.canonical_key'))
 WHERE parent_id IS NULL
   AND JSON_EXTRACT(metadata_json, '$.canonical_key') IS NOT NULL;
