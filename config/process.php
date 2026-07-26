<?php

/**
 * Managed worker-process settings (Step 1.1b).
 *
 * Single source of truth for the long-running worker processes this app
 * supervises alongside its HTTP worker.
 *
 * IMPORTANT: this app uses a HAND-ROLLED `start.php` that builds `Worker`s and
 * calls `Worker::runAll()` itself — it does NOT boot through Webman's
 * `support\App::run()`, so this file is NOT auto-consumed by the framework and
 * deliberately does NOT use Webman's `handler`/`constructor` instantiation
 * contract (that contract cannot supply this worker's DI dependencies). Instead
 * it carries PLAIN SETTINGS read by:
 *   - `start.php` — spawns each enabled entry as a managed `count`-sized sibling
 *     `Worker` under the same `Worker::runAll()` process group; and
 *   - `scripts/run-library-scan-worker.php` — the standalone alternative for
 *     operators who run the scan worker as its own isolated service.
 *
 * ⚠ **DO NOT RUN BOTH PATHS AT ONCE, AND DO NOT RAISE `library-scan`'s `count`
 * (corrected 2026-07-25, S96(c)).** This paragraph used to say running both was
 * "SAFE" because `ScanJobRepository::claimNext()` is an atomic conditional UPDATE.
 * That is true of CLAIMING and false of the startup reaper:
 * `LibraryScanWorker::start()` calls `ScanJobRepository::reapStaleJobs()`, which
 * fails EVERY `running` row in the table with no age guard and no `library_id`
 * filter — correct only while nothing else is draining this queue, since it is how
 * a job orphaned by a crash stops spinning the UI forever. A second concurrent
 * consumer (the standalone script alongside the managed worker, or a `count > 1`
 * fork whose siblings each call `start()`) therefore fails the OTHER consumer's
 * in-flight job the moment it boots, while that scan keeps running unaware —
 * nothing re-reads the job row mid-scan. See `LibraryScanWorker::start()` for the
 * full invariant and for why an age guard was rejected rather than added.
 *
 * Each entry:
 *   - `enabled`      bool — when false, `start.php` does not spawn the worker.
 *   - `count`        int  — number of worker processes. For `library-scan` this MUST
 *                           stay 1 (see the reaper warning above); `claimNext()`
 *                           being atomic is not sufficient on its own.
 *   - `poll_seconds` int  — `Workerman\Timer` poll interval for the loop.
 *
 * @return array<string, array{enabled: bool, count: int, poll_seconds: int}>
 */

declare(strict_types=1);

return [
    // ⚠ `count` MUST remain 1: LibraryScanWorker::start()'s stale-job reaper is
    // unscoped, so a second consumer fails the first one's live job (S96(c)).
    'library-scan' => [
        'enabled'      => true,
        'count'        => 1,
        'poll_seconds' => 5,
    ],

    // Plugin auto-update worker. Each tick is a cheap no-op unless the operator
    // has enabled auto-update in the admin Plugins section, so a slow daily
    // cadence is fine — the toggle is honoured without a restart.
    'plugin-auto-update' => [
        'enabled'      => true,
        'count'        => 1,
        'poll_seconds' => 86400,
    ],

    // SV-0.7: marker/intro-detection worker. Drains the file-based job queue
    // (marker_detection.job_queue_dir) of shows needing intro/outro detection.
    // Each tick runs one show's detection; a backlog of N drains in ≤ N ticks.
    'marker-detection' => [
        'enabled'      => true,
        'count'        => 1,
        'poll_seconds' => 30,   // matches config/marker_detection.php worker_interval
    ],

    // SV-1.3: chapter-thumbnail + trickplay generation worker. Drains the
    // file-based job queue (media_asset_jobs.job_queue_dir) of media items
    // awaiting per-chapter thumbnail and trickplay sprite generation.
    // Bounded concurrency (max_concurrent) limits parallel ffmpeg runs.
    'media-asset' => [
        'enabled'      => true,
        'count'        => 1,
        'poll_seconds' => 30,   // matches config/media_asset_jobs.php worker_interval
    ],

    // SV-2.9: similarity computation worker. Drains the file-based job queue
    // (similarity_jobs.job_queue_dir) of media items awaiting item-similarity
    // computation. Without this consumer the scanner's per-item enqueue would
    // accumulate undrained in /tmp (disk leak). Bounded concurrency
    // (max_concurrent) limits parallel per-library candidate scans.
    'similarity' => [
        'enabled'      => true,
        'count'        => 1,
        'poll_seconds' => 30,   // matches config/similarity_jobs.php worker_interval
    ],
];
