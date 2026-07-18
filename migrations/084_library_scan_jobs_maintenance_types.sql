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
        COMMENT 'scan=incremental, rescan=purge+rescan, metadata=background metadata match, '
            'metadata_refresh=force re-match already-matched items, prune=drop items whose files are gone, '
            'clear_metadata=reset items to filesystem basics, clear_artwork=delete locally cached artwork, '
            'delete_all=destructive remove every item in the library';
