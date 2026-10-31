-- Migration: 123_collection_items_sort_index.sql
--
-- minors M-SERVER-23 (server minors vein, W-min104 cluster; queue batch
-- label W-min106): the collection/playlist read rail
-- `CollectionItemRepository::findMediaItemIdsForCollection()` runs
--
--     SELECT media_item_id FROM collection_items
--      WHERE collection_id = ? ORDER BY sort_order, added_at
--
-- and migration 005's DDL carries only PRIMARY (collection_id, media_item_id)
-- plus idx_ci_media (media_item_id). The PK locates the collection's rows
-- fast, but nothing leads (collection_id, sort_order), so EVERY read sorted
-- the whole collection through a filesort. This composite serves the
-- WHERE-collection_id = ? ORDER BY sort_order, added_at shape directly — and
-- it covers the rail, too: InnoDB secondary indexes implicitly append the PK
-- columns, so media_item_id is read from the index itself (EXPLAIN Extra
-- 'Using index', no table lookback).
--
-- The two existing structures stay in place deliberately (same reasoning as
-- migrations 114/115): the PK is the uniqueness contract and serves the
-- pair lookups; idx_ci_media answers the media_item_id side of the FK added
-- by migration 122. This index does not subsume either.
--
-- Idempotence: ADD INDEX has no IF-[NOT-]EXISTS form, so the file follows the
-- INFORMATION_SCHEMA + PREPARE guard idiom of migrations 107/121 — a replay
-- short-circuits to SELECT 1 and never eats error 1061 mid-file.

SET @dbname = DATABASE();

-- @phlix-require-mysql 8.0.13 INFORMATION_SCHEMA guard against STATISTICS (dialect floor per the 011 precedent; the ALTER itself is version-neutral)
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'collection_items' AND INDEX_NAME = 'idx_ci_collection_sort') = 0,
    'ALTER TABLE collection_items ADD INDEX idx_ci_collection_sort (collection_id, sort_order, added_at)',
    'SELECT 1'
));
PREPARE addIndexIfNotExists FROM @preparedStatement;
EXECUTE addIndexIfNotExists;
DEALLOCATE PREPARE addIndexIfNotExists;
