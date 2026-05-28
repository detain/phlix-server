<?php

declare(strict_types=1);

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
 * Running both run paths at once is SAFE: `ScanJobRepository::claimNext()` is an
 * atomic conditional UPDATE and each worker is `count:1`, so at most one claimer
 * wins each job (by default the two paths are mutually exclusive).
 *
 * Each entry:
 *   - `enabled`      bool — when false, `start.php` does not spawn the worker.
 *   - `count`        int  — number of worker processes (1 = single claimer;
 *                           `claimNext()` is atomic regardless).
 *   - `poll_seconds` int  — `Workerman\Timer` poll interval for the loop.
 *
 * @return array<string, array{enabled: bool, count: int, poll_seconds: int}>
 */

return [
    'library-scan' => [
        'enabled'      => true,
        'count'        => 1,
        'poll_seconds' => 5,
    ],
];
