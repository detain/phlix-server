-- Migration: 030_library_scan_jobs_metadata_type.sql
-- Description: Add `metadata` to the library_scan_jobs.type ENUM.
--
-- The async metadata matcher (LibraryMetadataMatcher) reuses the existing
-- library_scan_jobs queue + status infrastructure so the admin UI's scan-status
-- badge/polling shows progress for a background metadata match exactly as it
-- does for a scan/rescan. The 027 table defined `type` as ENUM('scan','rescan'),
-- which would reject the new `metadata` job type; this migration widens the ENUM
-- to admit it while leaving the existing values + default unchanged.
--
-- Idempotent: re-running MODIFY COLUMN with the same definition is a no-op (the
-- migration runner also downgrades duplicate-object errors to notes — see
-- scripts/run-migrations.php).

ALTER TABLE `library_scan_jobs`
    MODIFY COLUMN `type` ENUM('scan', 'rescan', 'metadata') NOT NULL DEFAULT 'scan'
        COMMENT 'scan=incremental, rescan=purge+rescan, metadata=background metadata match';
