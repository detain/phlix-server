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
-- ⚠ COST: a music `rescan` goes from MINUTES to HOURS (61,111 tracks on the
--    production library, every one opened and tag-read). It is interruptible and
--    idempotent — the scanner flushes per album and stamps only files it actually
--    read — so re-running continues.
--
--    🔴 CORRECTION (S151, 2026-07-27). This line used to say "roughly 3.5 HOURS
--    (measured basis: …)". That was an ESTIMATE presented as a MEASUREMENT, and
--    it was wrong. The last *completed* music rescan of the production library
--    (job `d8e21a1b`, 2026-07-25) took **9 h 55 m** of wall clock; earlier full
--    walks of the same library recorded 5:20, 3:29 and 2:22. S151 removes what
--    was then measured to be the DOMINANT cost — a per-file existence lookup
--    that examined 48,512 rows instead of 1, 61,122 times per scan — so the
--    figure is expected to fall sharply. **That reduction is UNMEASURED**: no
--    post-S151 rescan has been timed, so no new duration is stated here.
--    It is also the ONLY operation that repairs a track filed under the wrong
--    album/artist after a retag, because the incremental skip fires before the
--    file is opened. Use `scan` for an incremental refresh.
--
-- The executable SQL below is deliberately left BYTE-IDENTICAL, so that fresh
-- databases do not end up out of step with existing ones over a column comment.
-- The authoritative description is this header.
--
-- ⚠ NOTE on editing THIS HEADER — and a correction to an earlier note here.
-- A previous revision of this comment claimed that editing the header diverges
-- the ledger checksum and re-executes the ALTER. That is WRONG.
-- `MigrationRunner::checksum()` (src/Common/Database/MigrationRunner.php:361-378)
-- STRIPS full-line `--` and `#` comments before hashing, precisely so that
-- documentation edits like this one are free. Header edits do NOT re-run the
-- migration.
--
-- Measured on production immediately after the S145 deploy, which shipped an
-- edit to this very header:
--     ledger checksum : c948944dfc1bbb4e82b0675efdb341e2
--     md5 of the file : 94181b1754ad498bdd64bbef1754ea85   (differs)
--     runner result   : "98 statement(s) skipped (already applied)"
--     applied_at      : still 2026-07-18 — no re-apply
-- The two hashes differ because the ledger stores the COMMENT-STRIPPED hash;
-- comparing a plain md5 of the file against it proves nothing.
--
-- What IS executable, and therefore does diverge the checksum, is the SQL below
-- — including the `COMMENT` string inside the ALTER. That is why it is left
-- byte-identical: re-wording it would re-run the migration everywhere for the
-- sake of a column comment.
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
