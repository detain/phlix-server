-- Migration 072: Add path_hash generated column for path deduplication.
--
-- Adds a SHA1 hash of the filesystem path as a STORED generated column so a
-- future double-insert of the same file can be rejected by a unique index. The
-- raw `path` column is VARCHAR(1000) (utf8mb4 → up to 4000 bytes), which exceeds
-- InnoDB's 3072-byte index-prefix limit, so it cannot be indexed directly; the
-- fixed 40-char SHA1 can.
--
-- WHY GENERATED (not a plain column + manual backfill): a generated STORED
-- column is maintained by MySQL for existing rows (at ALTER time) AND for every
-- future INSERT/UPDATE, with zero scanner changes. A plain column would be NULL
-- on every new scanner insert (the scanner never writes path_hash), and since
-- NULLs never collide in a unique index the constraint would silently protect
-- nothing.
--
-- SCOPE: path_hash is non-NULL only for the item types the deduper manages
-- (episode/movie/audio/book) that carry a real, non-empty path. Everything else
-- — containers (series/season) with synthetic paths, music_* rows, and any row
-- with an empty path — hashes to NULL and is therefore exempt from the unique
-- index. This keeps the constraint's scope identical to
-- PathDeduper::findDuplicateGroups(), so the index can never be tripped by a
-- type the cleanup does not dedupe.
--
-- The UNIQUE INDEX itself is NOT added here. Any DB with pre-existing duplicate
-- paths (the whole reason this migration exists) would make an inline
-- `ADD UNIQUE INDEX` fail with error 1062, and the migration runner treats 1062
-- as a hard error. Instead the index is created by `migrations/cleanup_072.php`
-- AFTER it merges the existing duplicates (on a clean DB it merges nothing and
-- adds the index immediately). Run once, post-migration:
--
--   php migrations/cleanup_072.php
--
-- Adding the generated column is idempotent: on replay the runner downgrades the
-- "Duplicate column name" error to a note.

ALTER TABLE media_items
    ADD COLUMN path_hash CHAR(40)
        GENERATED ALWAYS AS (
            CASE
                WHEN type IN ('episode', 'movie', 'audio', 'book')
                     AND path IS NOT NULL AND path <> ''
                THEN SHA1(path)
                ELSE NULL
            END
        ) STORED
        COMMENT 'SHA1(path) for the deduped types; NULL (index-exempt) otherwise'
        AFTER path;
