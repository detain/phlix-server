-- Migration: 124_media_items_type_sort_index.sql
--
-- review minor 58 (dba2): `ItemRepository::getAllByType()` — the unscoped
-- browse rail (`WHERE type = ? ORDER BY sort_title, name, id LIMIT n OFFSET m`)
-- — filesorted the whole type partition on every call. Migration 050's
-- composite leads with `library_id`, so it only serves the per-library browse;
-- `idx_type` locates the partition but contributes no order. The episodes
-- partition (the largest slice of the largest table) paid a full sort per
-- page request, and the DLNA browse walk calls it repeatedly.
--
-- This index serves the shape directly: with `type` pinned by the equality,
-- `(sort_title, name)` is already the index order, and InnoDB implicitly
-- appends the PRIMARY KEY (`id`) to every secondary index — exactly the
-- TOTAL tiebreak the query carries. No prefix trick: `sort_title` is
-- VARCHAR(255) utf8mb4, and a 64-char PREFIX index cannot answer an ORDER BY
-- on the full column (MySQL still filesorts), so the full column rides here.
-- Key length: type(20)*4 + sort_title(255)*4 + name(255)*4 ≈ 2,120 bytes,
-- inside InnoDB's 3,072-byte DYNAMIC-format limit.
--
-- Deliberate overlap: 050's (library_id, type, sort_title, name) stays — the
-- library-scoped browse leads with library_id and cannot use this one.
-- Write cost: one more secondary index per media_items insert/update, the
-- same trade 114/115/123 already accepted for read rails.
--
-- Idempotence: ADD INDEX has no IF-[NOT-]EXISTS form, so the file follows the
-- INFORMATION_SCHEMA + PREPARE guard idiom of migrations 107/121/123 — a
-- replay short-circuits to SELECT 1 and never eats error 1061 mid-file.

SET @dbname = DATABASE();

-- Dialect-neutral as shipped: the guard's INFORMATION_SCHEMA query, the SET +
-- PREPARE/EXECUTE/DEALLOCATE idiom and the plain composite ALTER are all
-- long-standing MariaDB syntax (measured executing cleanly, twice, on
-- 10.6.28-MariaDB on 2026-11-03 — same venue proof as migration 123). No
-- dialect directive: a directive would be both unnecessary and — per the
-- M28 law — incapable of guarding this SET + dynamic-prepare shape.
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'media_items' AND INDEX_NAME = 'idx_media_items_type_sort') = 0,
    'ALTER TABLE media_items ADD INDEX idx_media_items_type_sort (type, sort_title, name)',
    'SELECT 1'
));
PREPARE addIndexIfNotExists FROM @preparedStatement;
EXECUTE addIndexIfNotExists;
DEALLOCATE PREPARE addIndexIfNotExists;
