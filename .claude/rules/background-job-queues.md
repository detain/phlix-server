---
paths:
  - "src/Admin/Maintenance/**"
  - "src/Media/MediaAsset/**"
  - "src/Media/Library/ScanJobRepository.php"
  - "src/Media/Library/LibraryScanWorker.php"
  - "src/Server/Http/Controllers/Admin/MaintenanceController.php"
  - "config/media_asset_jobs.php"
  - "migrations/098_maintenance_jobs.sql"
  - "migrations/101_library_scan_jobs_media_assets_type.sql"
---

# Background Job Queues (maintenance · media assets · library scans)

- **Three queues, three transports — do not merge them.** `library_scan_jobs` (DB, `ScanJobRepository`, drained by `LibraryScanWorker`) is library-scoped: `library_id` is `CHAR(36) NOT NULL` with an FK, and `LibraryScanWorker::runOnce()` drops any claimed row whose `library_id` is empty. `maintenance_jobs` (`migrations/098_maintenance_jobs.sql`, `MaintenanceJobRepository`) is the sibling queue for server-wide work that has no honest `library_id`. `MediaAssetJobStore` is a FILE queue — one `*.job.json` per item under `config/media_asset_jobs.php`'s `job_queue_dir`, FIFO by mtime.
- **A claim is a conditional UPDATE, never a SELECT-then-UPDATE.** `MaintenanceJobRepository::claimNext()` mirrors `ScanJobRepository::claimNext()`: `UPDATE … SET status = 'running' WHERE id = ? AND status = 'queued'`, and an affected-row count below 1 means another drainer won the race and this call claimed nothing. Keep that shape when adding a queue — the Workerman MySQL client returns affected rows for an `UPDATE`, which is the only thing making a second drainer safe.
- **Long work is queued, bounded work is inline — the split is declared, not ad hoc.** `MaintenanceTask::CATALOGUE` tags each task `MODE_SYNC` (`reap-scan-jobs`, `reap-transcode-jobs`, `cleanup-orphaned-stats` → `200` in the request) or `MODE_QUEUED` (`storage-snapshot`, `dedupe-paths` → `202` + a row to poll). Anything that does `scandir()`/`shell_exec()` over vault roots or an unbounded `media_items` scan is `MODE_QUEUED`; running it inline stalls the whole Workerman HTTP worker.
- **Drain on a `Workerman\Timer` inside an existing worker, not a new process.** `MaintenanceQueueWorker::start()` first reaps `running` rows orphaned by a restart, then adds a `POLL_SECONDS = 5` timer whose callback loops `runOnce()` until the queue empties. `MediaAssetWorker::start($pollSeconds)` does the same with `DEFAULT_MAX_CONCURRENT = 2` ffmpeg slots. Both are `autowire()`d (`AdminServicesProvider`, `MediaServicesProvider`) and started from `src/Server/Core/Application.php`.
- **A `running` row is never re-claimed.** Only `queued` rows are claimable, so a worker that dies leaves its row `running` until the next `start()` reaps it. Do not add a timeout-based re-claim; it would double-run a `dedupe-paths` transaction.
- **Re-priming a queue is a job type, not a rescan.** `migrations/101_library_scan_jobs_media_assets_type.sql` appends `media_assets` to `library_scan_jobs.type` so a library's existing rows can be re-enqueued for chapter thumbnails / trickplay / BIF without reading media files or writing `media_items`. New ENUM values are APPENDED so stored ordinals are preserved.
