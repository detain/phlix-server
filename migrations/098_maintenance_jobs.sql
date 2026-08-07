-- Migration: 098_maintenance_jobs.sql
-- Description: Queue table for one-off admin maintenance tasks (S77).
--
-- ── Why a NEW table and not `library_scan_jobs` ──────────────────────────────
--
-- The obvious move is to add two members to `library_scan_jobs.type` and let
-- `LibraryScanWorker` drain them; migration 084 already widened that ENUM to
-- eight members for exactly that kind of maintenance work. It does not fit:
--
--   * `library_scan_jobs.library_id` is `CHAR(36) NOT NULL` with a foreign key
--     to `libraries.id` (migration 027). Neither of the tasks that need a queue
--     is library-scoped — a storage snapshot walks the vault roots and counts
--     `media_items` server-wide, and `PathDeduper::findDuplicateGroups()` is a
--     whole-table scan across every library at once. There is no honest value
--     to put in that column and no way to make it NULL without dropping the FK
--     that gives a deleted library its cascade cleanup.
--   * `LibraryScanWorker::runOnce()` refuses any claimed row whose `library_id`
--     is empty (it logs "claimed an invalid job row" and drops it), so such a
--     row would be silently discarded even if the column allowed it.
--
-- So this is a sibling queue with the same lifecycle vocabulary and the same
-- atomic single-claimer discipline, drained by a timer in the existing
-- `phlix-background-timers` worker rather than by a new process.
--
-- ── Why a queue at ALL, for only two of the five tasks ───────────────────────
--
-- Three of S77's tasks are bounded DB statements and run synchronously in the
-- request (reap stale scan jobs, reap stale transcode jobs, clean up orphaned
-- stats). Two are not, and running either inline would stall a Workerman HTTP
-- worker for the whole duration:
--
--   * `storage-snapshot` — `StorageSnapshotHelper::collectBuckets()` does
--     `@scandir()` over every vault root and then `shell_exec('du -sb …')` per
--     bucket. On this estate that is minutes of blocking I/O.
--   * `dedupe-paths` — `PathDeduper::findDuplicateGroups()` is an unbounded
--     `media_items` scan, followed by one transaction per duplicate group.
--
-- ── Columns ─────────────────────────────────────────────────────────────────
--
-- `task` is a VARCHAR, deliberately NOT an ENUM: the vocabulary lives in
-- `Phlix\Admin\Maintenance\MaintenanceTask::ALL` and is validated in PHP before
-- an INSERT, so adding a task does not need a schema change. An unknown value
-- that somehow reached the table is rejected by the worker rather than run.
--
-- `params_json` / `result_json` are TEXT holding a JSON object (or NULL). They
-- are read back with `json_decode(..., true)` and every consumer guards on
-- `is_array()`, so a malformed value degrades to "no params" / "no result".
--
-- Idempotent: `CREATE TABLE IF NOT EXISTS` means the runner can replay this
-- file safely (it also downgrades duplicate-object errors to notes — see
-- `src/Common/Database/MigrationRunner.php`).
--
-- NOTE: keep this statement free of semicolons inside string literals. The
-- migration runner strips comments then splits on `;` (MigrationRunner::
-- splitStatements), so a `;` inside a COMMENT string would shred the DDL.

CREATE TABLE IF NOT EXISTS `maintenance_jobs` (
    `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID identifier',
    `task` VARCHAR(64) NOT NULL
        COMMENT 'task name, validated against Phlix\\Admin\\Maintenance\\MaintenanceTask::ALL',
    `status` ENUM('queued', 'running', 'completed', 'failed') NOT NULL DEFAULT 'queued'
        COMMENT 'job lifecycle state, same vocabulary as library_scan_jobs',
    `params_json` TEXT NULL COMMENT 'JSON object of task parameters',
    `result_json` TEXT NULL COMMENT 'JSON object summarising what the task did',
    `error` TEXT NULL COMMENT 'failure message when status=failed',
    `requested_by` CHAR(36) NULL COMMENT 'users.id of the admin who queued it, NULL for a system enqueue',
    `queued_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'when enqueued',
    `started_at` TIMESTAMP NULL COMMENT 'when the worker claimed it',
    `completed_at` TIMESTAMP NULL COMMENT 'when it finished (completed or failed)',
    INDEX `idx_mj_status_queued` (`status`, `queued_at`),
    INDEX `idx_mj_task_status` (`task`, `status`),
    INDEX `idx_mj_queued_at` (`queued_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One-off admin maintenance tasks queued from the admin Tasks page (S77)';
