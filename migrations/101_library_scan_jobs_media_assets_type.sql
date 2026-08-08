-- Migration: 101_library_scan_jobs_media_assets_type.sql
-- Description: Add the `media_assets` job type to the `library_scan_jobs.type`
--              ENUM (S284 — re-enqueue the media-asset queue for a library).
--
-- ## Why this type exists
--
-- The media-asset queue (chapter thumbnails + trickplay sprite + Roku BIF) is a
-- FILE queue under `media_asset_jobs.job_queue_dir`, and its only producer is
-- `MediaScanner::processFile()` — i.e. it is populated at SCAN time and nowhere
-- else. S275 established that the trickplay producer had failed 100% of the time
-- on every install (`tile=6:10` is not a valid ffmpeg tile layout), so no library
-- anywhere holds a `sprite.jpg`, a `timeline.json` or a `thumbs.bif`. Fixing the
-- producer therefore only helps items processed AFTER the fix; every existing
-- library stays empty forever because nothing re-primes the queue.
--
-- Telling operators to rescan is not the answer: a full rescan is expensive
-- (a production music rescan ran 9 h 55 m — see migration 084's header) and S153
-- recorded that a healing rescan creates MORE orphan container rows than it
-- clears. `media_assets` is the targeted alternative — it re-enqueues the
-- media-asset jobs for a library's existing rows and touches nothing else. It
-- does not read media files, does not write `media_items`, and does not start a
-- scan.
--
-- It reuses the SAME queue + status + progress infrastructure as the four
-- migration-084 maintenance ops, so the existing scan-status badge/polling shows
-- its progress unchanged and no parallel progress mechanism is introduced.
--
-- The new value is APPENDED after the existing ones so every already-stored ENUM
-- value keeps its ordinal (MySQL stores ENUMs by index) and no row is rewritten.
-- The existing DEFAULT 'scan' is preserved.
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
            'delete_all',
            'media_assets'
        ) NOT NULL DEFAULT 'scan'
        COMMENT 'scan=incremental, rescan=purge+rescan, metadata=background metadata match, metadata_refresh=force re-match already-matched items, prune=drop items whose files are gone, clear_metadata=reset items to filesystem basics, clear_artwork=delete locally cached artwork, delete_all=destructive remove every item in the library, media_assets=re-enqueue chapter-thumbnail/trickplay/BIF generation for the library existing items';
