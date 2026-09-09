-- Migration: 104_library_scan_jobs_rescan_comment_correction.sql
-- Description: Correct the false "rescan=purge+rescan" claim baked into the live
--              `library_scan_jobs.type` column COMMENT (S154).
--
-- THE DEFECT. `rescan` does NOT purge the library. It re-reads every file from
-- disk and then prunes ONLY the items whose source file has disappeared
-- (LibraryManager::pruneRemovedItems(), behind a per-root presence guard). The
-- comment has asserted "rescan=purge+rescan" since migration 027, and every later
-- ENUM widening (030, 081, 084, 101) restated the full definition and carried the
-- same falsehood forward. Migration 101's comment is the copy a DBA reading
-- SHOW FULL COLUMNS sees today. S149 removed this claim from 11 documentation
-- pages; this is the SQL copy that survived because it lives in an ALTER.
--
-- WHY A NEW FILE, NOT AN EDIT OF 084 / 101 IN PLACE. The COMMENT string is part of
-- the executable ALTER, not a full-line `--`/`#` comment, so
-- MigrationRunner::checksum() (src/Common/Database/MigrationRunner.php) does NOT
-- strip it. Re-wording 084's or 101's COMMENT would change that file's checksum,
-- no longer match the ledger row every deployed install already holds, and force a
-- re-apply of the whole ALTER everywhere. 084's checksum is
-- c948944dfc1bbb4e82b0675efdb341e2 and MUST stay that value; 027/030/081/084/101
-- are left byte-identical and this file re-issues the definition forward instead.
-- (Contrast S147/S151, which safely edited a FULL-LINE `--` comment in 084's
-- header — a different class of comment that the runner strips before hashing.)
--
-- RE-ISSUES THE FULL COLUMN DEFINITION. MODIFY COLUMN requires the whole
-- definition restated, so the nine ENUM members (084's eight plus 101's
-- media_assets), their ORDINALS (MySQL stores ENUM by index), NOT NULL,
-- DEFAULT 'scan' and the inherited utf8mb4_unicode_ci collation are copied
-- byte-for-byte from the live schema; ONLY the COMMENT text changes. Re-run safe:
-- MODIFY COLUMN with the same definition is a no-op.
--
-- SURVIVAL TOKEN: S154ENUMCOMMENTX9D2 (also code-resident in
-- tests/Integration/Common/Database/RescanEnumCommentGuardTest.php).
--
-- Proven against real MySQL by tests/Integration/Common/Database/RescanEnumCommentGuardTest.php:
--   * the nine ENUM members and their ordinals are byte-identical before/after;
--   * NOT NULL, DEFAULT 'scan' and the collation are unchanged;
--   * only the `type` column's COMMENT moved; every other column untouched;
--   * re-running this migration is idempotent;
--   * migration 084's own ledger checksum is still c948944dfc1bbb4e82b0675efdb341e2.
--
-- @copyright 2026 Joe Huss <detain@interserver.net>
-- @license   MIT
-- @since     S154

ALTER TABLE `library_scan_jobs`
    MODIFY COLUMN `type`
        ENUM(
            'scan',
            'rescan',
            'metadata',
            'metadata_refresh',
            'prune',
            'clear_metadata',
            'clear_artwork',
            'delete_all',
            'media_assets'
        ) NOT NULL DEFAULT 'scan'
        COMMENT 'scan=incremental, rescan=re-read every file then prune items whose source file is gone (no library purge), metadata=background metadata match, metadata_refresh=force re-match already-matched items, prune=drop items whose files are gone, clear_metadata=reset items to filesystem basics, clear_artwork=delete locally cached artwork, delete_all=destructive remove every item in the library, media_assets=re-enqueue chapter-thumbnail/trickplay/BIF generation for the library existing items';
