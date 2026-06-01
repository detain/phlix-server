-- Migration: 031_media_items_metadata_refreshed_at.sql
-- Description: Add the `metadata_refreshed_at` column to media_items.
--
-- LibraryMetadataMatcher::matchItem() persists a metadata match via
-- ItemRepository::update(), stamping `metadata_refreshed_at = NOW()` alongside
-- the merged `metadata_json` (see src/Media/Metadata/LibraryMetadataMatcher.php
-- lines 185-186). The original `media_items` table in 001_initial_schema.sql
-- only ever defined `metadata_json`; the column the matcher writes to was never
-- created, so every match failed with:
--   SQLSTATE[42S22]: Column not found: 1054 Unknown column 'metadata_refreshed_at'
-- and the matcher logged "item match failed; skipping".
--
-- The column records when an item's metadata was last refreshed by the matcher
-- (DATETIME, written as 'Y-m-d H:i:s'); NULL means it has never been matched.
--
-- Idempotent: re-running ADD COLUMN raises "Duplicate column name", which the
-- migration runner downgrades to a note (see src/Common/Database/MigrationRunner.php).

-- NOTE: keep this statement free of semicolons inside string literals. The
-- migration runner strips comments then splits on `;` (see MigrationRunner::
-- splitStatements), so a `;` inside the COMMENT text would shred the ALTER.
ALTER TABLE media_items
    ADD COLUMN metadata_refreshed_at DATETIME NULL DEFAULT NULL
        COMMENT 'When LibraryMetadataMatcher last refreshed this item, NULL means never matched';
