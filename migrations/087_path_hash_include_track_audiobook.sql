-- Migration 087: widen path_hash's scope to cover `track` and `audiobook`.
--
-- Migration 072 scoped the `path_hash` generated column — and therefore the
-- `(library_id, path_hash)` unique index — to ('episode','movie','audio','book').
-- That list has to stay in lockstep with PathDeduper::findDuplicateGroups(),
-- which this migration widens to include `track` and `audiobook`.
--
-- WHY THESE TWO:
--
--   `track` is the type the music scanner actually writes (AudioScanner), and
--   it is the type MOST exposed to duplicate paths: MusicLibraryManager
--   persists tracks via ItemRepository::create(), NOT upsertByPath(), so the
--   race-safe find-or-create the video scanners rely on never runs for music.
--   Nothing dedupes music today, by path or otherwise.
--
--   `audiobook` is written by AudiobookScanner/AudiobookLibraryManager and is
--   an ordinary file-backed leaf, exactly like the already-covered `book`.
--
-- Containers (series/season) and `photo` are deliberately still excluded, in
-- line with 072: containers carry synthetic paths that legitimately repeat.
--
-- ORDER OF OPERATIONS — the two statements below are the parts that CANNOT
-- fail on a dirty DB, and nothing else:
--
--   1. DROP the unique index. Widening the column's scope hands a hash to rows
--      that previously hashed to NULL (and NULLs never collide), so leaving the
--      index in place would make step 2 fail with error 1062 on any DB that
--      already holds duplicate track/audiobook paths — precisely the DBs this
--      migration exists to fix. On a DB where `cleanup_072.php` was never run
--      the index does not exist and MySQL raises 1091, which the migration
--      runner downgrades to a note ("check that column/key exists").
--   2. MODIFY the generated column to the widened expression. With no unique
--      index present this cannot collide, and MySQL recomputes every row.
--
-- The unique index is NOT re-added here, for the same reason migration 072 did
-- not add it in the first place: any pre-existing duplicate would make an
-- inline ADD UNIQUE INDEX fail. Re-run the finalizer ONCE post-migration:
--
--     php migrations/cleanup_072.php
--
-- It merges duplicate groups under the NEW (widened) PathDeduper scope and then
-- re-adds the index. It is documented and built to be re-run safely.
--
-- 🔴 CORRECTION (S152) — the paragraph above was the whole defect. Nothing in
-- `scripts/run-migrations.php` / `bin/phlix migrate` ever ran that finalizer, so
-- the DROP below was permanent on any install nobody hand-finalized: no unique
-- index, no path-dedupe constraint, and the S151 track lookup degraded from
-- `const`/rows=1 back to `ref`/key_len=144. The index is now re-added by the
-- migration chain itself, in `096_path_hash_unique_index.sql`, which sorts
-- after this file so it can never fight the DROP; it de-duplicates NOTHING and
-- refuses to run on a dirty table, pointing at cleanup_072.php instead. Running
-- the finalizer by hand is therefore only needed when duplicates actually exist.
-- (Comment-only edit: MigrationRunner::checksum() strips full-line comments
-- before hashing, so this does NOT re-run migration 087.)
--
-- NOTE: rewriting a STORED generated column is a full table rebuild
-- (ALGORITHM=COPY). Schedule accordingly on a large media_items.

ALTER TABLE media_items
    DROP INDEX idx_media_items_library_path_hash;

ALTER TABLE media_items
    MODIFY COLUMN path_hash CHAR(40)
        GENERATED ALWAYS AS (
            CASE
                WHEN type IN ('episode', 'movie', 'audio', 'book', 'track', 'audiobook')
                     AND path IS NOT NULL AND path <> ''
                THEN SHA1(path)
                ELSE NULL
            END
        ) STORED
        COMMENT 'SHA1(path) for the deduped types; NULL (index-exempt) otherwise';
