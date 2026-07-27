-- Migration: 084_library_scan_jobs_maintenance_types.sql
-- Description: Add the fine-grained library-maintenance job types to the
--              `library_scan_jobs.type` ENUM.
--
-- The async scan-job queue (migration 027, widened by 030 and 081) already backs
-- `scan` / `rescan` / `metadata` / `metadata_refresh`. This migration adds four
-- targeted maintenance operations that reuse the SAME queue + status + progress
-- infrastructure (so the existing scan-status badge/polling works unchanged),
-- each drained off the HTTP path by LibraryScanWorker:
--
--   * `prune`         — run ONLY the non-destructive pruneRemovedItems() pass to
--                       drop items whose source file is gone (per-root presence
--                       guards intact), WITHOUT a full rescan.
--   * `clear_metadata`— reset every item to its filesystem-derived basics:
--                       NULL out `metadata_refreshed_at` + strip provider-fetched
--                       keys from `metadata_json` (poster/backdrop/logo urls,
--                       trailers, overview, cast/crew, genres/tags, ratings/votes,
--                       still_url, external ids, …) and clear the materialized
--                       `content_rating`, while PRESERVING the item rows, their
--                       path / filename-derived title / type / parent hierarchy,
--                       and all user_item_data / watch history.
--   * `clear_artwork` — delete the locally cached artwork for the library's items
--                       (free disk; next match re-downloads), leaving user data
--                       and metadata text untouched.
--   * `delete_all`    — the destructive "remove every item in the library"
--                       (`DELETE FROM media_items WHERE library_id = ?`, cascading
--                       user data). The controller endpoint requires an explicit
--                       confirmation flag before enqueuing this type.
--
-- The new values are APPENDED after the existing ones so every already-stored
-- ENUM value keeps its ordinal (MySQL stores ENUMs by index) and no row is
-- rewritten. The existing DEFAULT 'scan' is preserved.
--
-- Idempotent: re-running MODIFY COLUMN with the same definition is a no-op (the
-- migration runner also downgrades duplicate-object errors to notes — see
-- scripts/run-migrations.php).
--
-- ─────────────────────────────────────────────────────────────────────────────
-- S145 (2026-07-27) — WHAT `scan` VS `rescan` ACTUALLY MEAN, AND A COST WARNING.
--
-- The COMMENT below says "scan=incremental, rescan=purge+rescan". Both halves
-- needed correcting, and only one of them was corrected in CODE:
--
--   * `rescan` has been NON-DESTRUCTIVE since the DELETE-then-rescan data-loss
--     fix — it never purges. It re-scans from disk and then prunes ONLY items
--     whose source file is gone. Read "purge" as "prune what disappeared".
--   * `rescan` was NOT a full re-read. For a music library
--     LibraryManager::rescanLibrary() ran exactly the same skip-index-enabled
--     scan `scan` runs, so a file whose (mtime, size) had not moved was never
--     opened. S145 makes `rescan` pass `readEveryFile: true`, which is what the
--     ENUM comment always implied. **Today's fast `rescan` is the thing that is
--     wrong** — the slowdown below is the promise being kept, not a regression.
--
-- ⚠ COST: a music `rescan` goes from MINUTES to roughly 3.5 HOURS (measured
--    basis: 61,111 tracks on the production library, every one opened and
--    tag-read). It is interruptible and idempotent — the scanner flushes per
--    album and stamps only files it actually read — so re-running continues.
--    It is also the ONLY operation that repairs a track filed under the wrong
--    album/artist after a retag, because the incremental skip fires before the
--    file is opened. Use `scan` for an incremental refresh.
--
-- The executable SQL below is deliberately left BYTE-IDENTICAL: this migration
-- is already applied everywhere, so re-wording the COMMENT string here would
-- only take effect on brand-new installs and would put fresh databases out of
-- step with existing ones over a column comment. The authoritative description
-- is this header.
-- ─────────────────────────────────────────────────────────────────────────────

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
            'delete_all'
        ) NOT NULL DEFAULT 'scan'
        COMMENT 'scan=incremental, rescan=purge+rescan, metadata=background metadata match, metadata_refresh=force re-match already-matched items, prune=drop items whose files are gone, clear_metadata=reset items to filesystem basics, clear_artwork=delete locally cached artwork, delete_all=destructive remove every item in the library';
