-- Migration: 081_library_scan_jobs_metadata_refresh_type.sql
-- Description: Add `metadata_refresh` to the library_scan_jobs.type ENUM.
--
-- A plain `metadata` job skips items that already have metadata
-- (metadata_refreshed_at IS NOT NULL), so once an item is matched its metadata
-- can never be re-fetched through the job pipeline — even to backfill newly
-- added fields (e.g. per-episode stills). The new `metadata_refresh` job type
-- runs the SAME LibraryMetadataMatcher but with forceRefresh enabled, so it
-- re-processes already-matched items too. It reuses the existing
-- library_scan_jobs queue + status infrastructure, exactly like `metadata`.
--
-- Migration 030 widened the ENUM to ('scan','rescan','metadata'); this migration
-- widens it further to admit `metadata_refresh` while leaving the existing
-- values + default unchanged.
--
-- Idempotent: re-running MODIFY COLUMN with the same definition is a no-op (the
-- migration runner also downgrades duplicate-object errors to notes — see
-- scripts/run-migrations.php).

ALTER TABLE `library_scan_jobs`
    MODIFY COLUMN `type` ENUM('scan', 'rescan', 'metadata', 'metadata_refresh') NOT NULL DEFAULT 'scan'
        COMMENT 'scan=incremental, rescan=purge+rescan, metadata=background metadata match, metadata_refresh=force re-match already-matched items';
