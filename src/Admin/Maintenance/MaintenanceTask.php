<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Admin\Maintenance;

/**
 * THE vocabulary of one-off admin maintenance tasks (S77).
 *
 * ## One list, three consumers
 *
 * The task name appears in the HTTP route, in the `maintenance_jobs.task`
 * column, and in the `match` that decides what to run. Those three drifting
 * apart is the defect class this repo keeps hitting (see
 * {@see \Phlix\Media\MediaItemType}'s docblock for four instances of it), so
 * the names live here once and
 * {@see \Phlix\Tests\Unit\Admin\Maintenance\MaintenanceTaskCoverageTest}
 * fails the build if any consumer stops covering the list.
 *
 * ## `sync` vs `queued` is a PROPERTY OF THE TASK, not of the caller
 *
 * Phlix runs on a Workerman event loop: a handler that blocks stalls every
 * concurrent connection on that worker. So each task declares which it is, and
 * the controller reads that declaration rather than each handler deciding for
 * itself:
 *
 *  - {@see self::MODE_SYNC} — a bounded DB statement. Runs in the request and
 *    answers `200` with what it did.
 *  - {@see self::MODE_QUEUED} — filesystem walks, `du -sb`, or an unbounded
 *    table scan with a transaction per group. Enqueued onto `maintenance_jobs`
 *    and answered `202` with the job row; drained by
 *    {@see MaintenanceQueueWorker} inside the `phlix-background-timers`
 *    process.
 *
 * @package Phlix\Admin\Maintenance
 * @since 1.9
 */
final class MaintenanceTask
{
    /** Runs inline in the HTTP request; answers 200 with its result. */
    public const MODE_SYNC = 'sync';

    /** Enqueued onto `maintenance_jobs`; answers 202 with the job row. */
    public const MODE_QUEUED = 'queued';

    /**
     * Record ONE storage snapshot now (`stats_storage`).
     *
     * QUEUED: {@see \Phlix\Stats\StorageSnapshotHelper::collectBuckets()}
     * `@scandir()`s each vault root and then `shell_exec('du -sb …')` per
     * bucket — minutes of blocking I/O on a real library.
     */
    public const STORAGE_SNAPSHOT = 'storage-snapshot';

    /**
     * Fail `library_scan_jobs` rows stuck in `running`.
     *
     * SYNC: one `UPDATE … WHERE status = 'running' AND started_at < …`.
     */
    public const REAP_SCAN_JOBS = 'reap-scan-jobs';

    /**
     * Fail `transcode_jobs` rows stuck in `running` so their concurrency slot
     * is released.
     *
     * SYNC: one `SELECT` over the `running` rows plus an `UPDATE` per corpse —
     * bounded by the concurrency cap, not by library size.
     */
    public const REAP_TRANSCODE_JOBS = 'reap-transcode-jobs';

    /**
     * Delete `stats_*` rows whose `media_item_id` / `user_id` no longer exists.
     *
     * SYNC, and bounded by an explicit `LIMIT`: closes out S14's deferred
     * cleanup action (`plan_updates.md` S14 "Out of scope"), which shipped the
     * read-side INNER JOINs but never the delete.
     */
    public const CLEANUP_ORPHANED_STATS = 'cleanup-orphaned-stats';

    /**
     * Merge `media_items` rows that share a filesystem path.
     *
     * QUEUED: {@see \Phlix\Media\Library\PathDeduper::findDuplicateGroups()} is
     * a whole-table scan and each group is merged in its own transaction.
     * Defaults to a DRY RUN — the destructive form needs `apply: true`.
     */
    public const DEDUPE_PATHS = 'dedupe-paths';

    /**
     * Every task, in the order the admin Tasks page should list them.
     *
     * @var list<string>
     */
    public const ALL = [
        self::STORAGE_SNAPSHOT,
        self::REAP_SCAN_JOBS,
        self::REAP_TRANSCODE_JOBS,
        self::CLEANUP_ORPHANED_STATS,
        self::DEDUPE_PATHS,
    ];

    /**
     * Task → `{mode, label, description, destructive}`.
     *
     * EXHAUSTIVE over {@see self::ALL}; the coverage test compares the two as
     * ordered lists, so a task added without an entry here reddens rather than
     * silently becoming an un-listable, un-runnable name.
     *
     * `destructive` is what the S78 UI keys its confirmation prompt off. Note
     * that {@see self::DEDUPE_PATHS} is marked destructive even though it
     * defaults to a dry run: the button that matters is the `apply: true` one.
     *
     * @var array<string, array{mode: string, label: string, description: string, destructive: bool}>
     */
    public const CATALOGUE = [
        self::STORAGE_SNAPSHOT => [
            'mode' => self::MODE_QUEUED,
            'label' => 'Record storage snapshot now',
            'description' => 'Measure per-bucket disk usage and item counts and write one '
                . 'stats_storage snapshot, without waiting for the periodic timer.',
            'destructive' => false,
        ],
        self::REAP_SCAN_JOBS => [
            'mode' => self::MODE_SYNC,
            'label' => 'Reap stale library scan jobs',
            'description' => 'Mark library scan jobs that have been running longer than the '
                . 'given age as failed, so a crashed worker stops showing a stuck progress bar.',
            'destructive' => false,
        ],
        self::REAP_TRANSCODE_JOBS => [
            'mode' => self::MODE_SYNC,
            'label' => 'Reap stale transcode jobs',
            'description' => 'Mark wedged or abandoned transcode jobs as failed so they release '
                . 'the concurrency slot they are holding.',
            'destructive' => false,
        ],
        self::CLEANUP_ORPHANED_STATS => [
            'mode' => self::MODE_SYNC,
            'label' => 'Clean up orphaned statistics rows',
            'description' => 'Delete stats rows that reference a media item or user that no '
                . 'longer exists. These are already hidden from the dashboard - this removes them.',
            'destructive' => true,
        ],
        self::DEDUPE_PATHS => [
            'mode' => self::MODE_QUEUED,
            'label' => 'Merge duplicate media paths',
            'description' => 'Find media items that share a filesystem path and merge each group '
                . 'onto its best row. Runs as a preview unless "apply" is set.',
            'destructive' => true,
        ],
    ];

    /**
     * Is `$task` a known task name?
     */
    public static function isValid(string $task): bool
    {
        return in_array($task, self::ALL, true);
    }

    /**
     * {@see self::MODE_SYNC} or {@see self::MODE_QUEUED} for a known task.
     *
     * @param string $task A member of {@see self::ALL}.
     *
     * @return string The declared mode; {@see self::MODE_QUEUED} for an unknown
     *                task, which is the safe direction — an unrecognised name
     *                must never be run inline on the event loop.
     */
    public static function mode(string $task): string
    {
        $mode = self::CATALOGUE[$task]['mode'] ?? null;

        return is_string($mode) ? $mode : self::MODE_QUEUED;
    }

    /**
     * Is `$task` one that runs inline in the HTTP request?
     */
    public static function isSynchronous(string $task): bool
    {
        return self::isValid($task) && self::mode($task) === self::MODE_SYNC;
    }

    /**
     * The catalogue as a JSON-ready list for the admin Tasks page.
     *
     * @return list<array{task: string, mode: string, label: string, description: string, destructive: bool}>
     */
    public static function catalogue(): array
    {
        $out = [];
        foreach (self::ALL as $task) {
            $entry = self::CATALOGUE[$task];
            $out[] = [
                'task' => $task,
                'mode' => $entry['mode'],
                'label' => $entry['label'],
                'description' => $entry['description'],
                'destructive' => $entry['destructive'],
            ];
        }

        return $out;
    }

    /**
     * Prevent instantiation — this class is a static vocabulary holder only.
     */
    private function __construct()
    {
    }
}
