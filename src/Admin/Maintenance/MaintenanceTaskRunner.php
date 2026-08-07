<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Admin\Maintenance;

use InvalidArgumentException;
use Phlix\Media\Library\PathDedupeRunner;
use Phlix\Media\Library\PathDeduper;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Stats\StatsCollector;
use Phlix\Stats\StorageSnapshotHelper;
use Workerman\MySQL\Connection;

/**
 * Executes ONE maintenance task (S77) — the only place a task name becomes work.
 *
 * ## Why the runner is shared between the request and the worker
 *
 * `reap-scan-jobs`, `reap-transcode-jobs` and `cleanup-orphaned-stats` run
 * inline in the admin request; `storage-snapshot` and `dedupe-paths` run in the
 * `phlix-background-timers` process via {@see MaintenanceQueueWorker}. Both
 * paths call {@see self::run()}, so "what the task does" has one definition and
 * the queued path cannot quietly diverge from the synchronous one.
 * {@see MaintenanceTask::mode()} is what decides which path a task takes; this
 * class does not care.
 *
 * ## Collaborators are optional, and that is load-bearing
 *
 * {@see TranscodeManager} is the only dependency that can genuinely fail to
 * build (it needs ffmpeg config and a transcode root). A task whose
 * collaborator is missing answers a clear failure instead of taking the whole
 * controller down at construction — but note the trap: PHP-DI's `autowire()`
 * silently SKIPS optional constructor parameters, so an optional dependency is
 * exactly the kind that ends up `null` in production while every hand-wired
 * test passes. {@see \Phlix\Tests\Unit\Admin\Maintenance\MaintenanceContainerWiringTest}
 * resolves this class from the real container and asserts the field is set.
 *
 * @package Phlix\Admin\Maintenance
 * @since 1.9
 */
class MaintenanceTaskRunner
{
    /**
     * Default and MINIMUM age before a `running` library scan job may be reaped.
     *
     * ⚠ SIX HOURS, and the number is measured rather than chosen for roundness.
     * `LibraryScanWorker::start()` records that a legitimate music scan of the
     * production library ran for **4 h 09 m** before its first durable write,
     * and `library_scan_jobs` has no heartbeat column — `started_at` is the only
     * age signal there is, and it is never refreshed while a scan runs. So any
     * threshold near "a few minutes" reaps live scans, marking a healthy job
     * `failed` and, worse, making `hasActiveJobForLibrary()` report the library
     * idle so a second concurrent scan can start over the same files.
     *
     * This is therefore a FLOOR as well as a default: {@see self::run()} raises
     * a smaller request up to it rather than honouring it.
     */
    public const MIN_SCAN_JOB_AGE_SECONDS = 21600;

    /**
     * Default age before a `running` transcode job is considered a corpse.
     *
     * Much shorter than the scan floor because the risk is inverted: a wedged
     * encode holds a concurrency slot that blocks other playback, and
     * {@see TranscodeManager::reapStaleRunningJobs()} additionally checks for a
     * missing working directory and for zero produced segments, so it is not
     * relying on age alone.
     */
    public const DEFAULT_TRANSCODE_JOB_AGE_SECONDS = 300;

    /** Most orphaned stats rows one `cleanup-orphaned-stats` run will delete per table. */
    public const DEFAULT_ORPHAN_DELETE_LIMIT = 5000;

    /** Hard ceiling on the orphan delete limit, so one request cannot ask for an unbounded DELETE. */
    public const MAX_ORPHAN_DELETE_LIMIT = 50000;

    /** Default number of duplicate-path groups one `dedupe-paths` run processes. */
    public const DEFAULT_DEDUPE_BATCH_SIZE = 500;

    /** Hard ceiling on the dedupe batch size. */
    public const MAX_DEDUPE_BATCH_SIZE = 5000;

    /**
     * The stats tables this cleanup touches, and the reference that makes a row
     * an orphan.
     *
     * `nullable` marks a reference column that is legitimately NULL (a library
     * change with no item, a snapshot with no library); those rows are NOT
     * orphans and must be excluded, or the cleanup would delete every
     * server-wide row it found.
     *
     * @var list<array{table: string, column: string, parent: string, nullable: bool}>
     */
    private const ORPHAN_TARGETS = [
        ['table' => 'stats_playback_events', 'column' => 'media_item_id',
            'parent' => 'media_items', 'nullable' => false],
        ['table' => 'stats_playback_events', 'column' => 'user_id',
            'parent' => 'users', 'nullable' => false],
        ['table' => 'stats_library_changes', 'column' => 'media_item_id',
            'parent' => 'media_items', 'nullable' => true],
        ['table' => 'stats_user_activity', 'column' => 'user_id',
            'parent' => 'users', 'nullable' => false],
    ];

    /**
     * @param Connection            $db               Async MySQL connection.
     * @param ScanJobRepository     $scanJobs         Library scan-job store.
     * @param PathDeduper           $pathDeduper      Duplicate-path scan + merge.
     * @param TranscodeManager|null $transcodeManager Transcode job store; null when
     *        it could not be built, which fails only the transcode reaper.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly ScanJobRepository $scanJobs,
        private readonly PathDeduper $pathDeduper,
        private readonly ?TranscodeManager $transcodeManager = null,
    ) {
    }

    /**
     * Run one task.
     *
     * @param string               $task   A member of {@see MaintenanceTask::ALL}.
     * @param array<string, mixed> $params Task parameters (already JSON-decoded).
     *
     * @return array<string, mixed> A JSON-ready summary of what the task did.
     *
     * @throws InvalidArgumentException When `$task` is not a known task name.
     */
    public function run(string $task, array $params = []): array
    {
        return match ($task) {
            MaintenanceTask::STORAGE_SNAPSHOT => $this->storageSnapshot(),
            MaintenanceTask::REAP_SCAN_JOBS => $this->reapScanJobs($params),
            MaintenanceTask::REAP_TRANSCODE_JOBS => $this->reapTranscodeJobs($params),
            MaintenanceTask::CLEANUP_ORPHANED_STATS => $this->cleanupOrphanedStats($params),
            MaintenanceTask::DEDUPE_PATHS => $this->dedupePaths($params),
            default => throw new InvalidArgumentException('Unknown maintenance task: ' . $task),
        };
    }

    // -----------------------------------------------------------------
    // Tasks
    // -----------------------------------------------------------------

    /**
     * Record one `stats_storage` snapshot immediately.
     *
     * ⚠ QUEUED-ONLY. {@see StorageSnapshotHelper::collectBuckets()} `@scandir`s
     * each vault root and then `shell_exec('du -sb …')` per bucket. On a real
     * library that is minutes of BLOCKING I/O, which in an HTTP worker stalls
     * every concurrent connection on that process.
     *
     * Unlike {@see StorageSnapshotHelper::bootstrapSnapshot()} this deliberately
     * skips the staleness check: the operator pressed "now".
     *
     * @return array<string, mixed>
     */
    private function storageSnapshot(): array
    {
        $buckets = StorageSnapshotHelper::collectBuckets($this->db);

        (new StatsCollector($this->db))->recordStorageSnapshots($buckets);

        $totalBytes = 0;
        $totalItems = 0;
        foreach ($buckets as $bucket) {
            $totalBytes += $bucket['bytes'];
            $totalItems += $bucket['count'];
        }

        return [
            'buckets' => count($buckets),
            'total_items' => $totalItems,
            'total_bytes' => $totalBytes,
        ];
    }

    /**
     * Fail `library_scan_jobs` rows that have been `running` too long.
     *
     * @param array<string, mixed> $params `older_than_seconds`.
     *
     * @return array<string, mixed>
     */
    private function reapScanJobs(array $params): array
    {
        $age = $this->intParam($params, 'older_than_seconds', self::MIN_SCAN_JOB_AGE_SECONDS);

        // A FLOOR, not a clamp for tidiness — see MIN_SCAN_JOB_AGE_SECONDS.
        // A caller asking for 60 seconds gets 21600 and is told so in the
        // response, rather than silently reaping a four-hour music scan.
        $requested = $age;
        $age = max(self::MIN_SCAN_JOB_AGE_SECONDS, $age);

        $reaped = $this->scanJobs->reapStaleJobs(
            'Reaped by an administrator: running for more than ' . $age . ' seconds',
            $age,
        );

        return [
            'reaped' => $reaped,
            'older_than_seconds' => $age,
            'requested_older_than_seconds' => $requested,
            'floor_applied' => $requested < self::MIN_SCAN_JOB_AGE_SECONDS,
        ];
    }

    /**
     * Fail wedged `transcode_jobs` rows so they release their concurrency slot.
     *
     * @param array<string, mixed> $params `older_than_seconds`.
     *
     * @return array<string, mixed>
     */
    private function reapTranscodeJobs(array $params): array
    {
        if ($this->transcodeManager === null) {
            throw new \RuntimeException(
                'TranscodeManager is unavailable, so transcode jobs cannot be reaped.'
            );
        }

        $age = max(1, $this->intParam($params, 'older_than_seconds', self::DEFAULT_TRANSCODE_JOB_AGE_SECONDS));
        $reaped = $this->transcodeManager->reapStaleRunningJobs($age);

        return [
            'reaped' => $reaped,
            'older_than_seconds' => $age,
        ];
    }

    /**
     * Delete `stats_*` rows whose media item or user no longer exists.
     *
     * Closes out S14's deferred cleanup action. S14 shipped the READ-side fix
     * (INNER JOINs in `StatsCollector`, `continue` guards in `DashboardService`)
     * so the rows stopped appearing as blank dashboard entries; they were never
     * actually removed.
     *
     * ## The fail-dangerous case, and the guard for it
     *
     * `NOT EXISTS (SELECT 1 FROM media_items …)` is true for EVERY row when
     * `media_items` is empty — a fresh install, a half-restored backup, or a
     * database the query could not read. That would delete the entire playback
     * history in one click. So each parent table's row count is checked first
     * and the whole task refuses when any of them is empty. An empty parent is
     * not a state in which "everything is an orphan" is a safe conclusion.
     *
     * @param array<string, mixed> $params `limit`.
     *
     * @return array<string, mixed>
     */
    private function cleanupOrphanedStats(array $params): array
    {
        $limit = $this->intParam($params, 'limit', self::DEFAULT_ORPHAN_DELETE_LIMIT);
        $limit = max(1, min(self::MAX_ORPHAN_DELETE_LIMIT, $limit));

        foreach (['media_items', 'users'] as $parent) {
            if ($this->countRows($parent) < 1) {
                throw new \RuntimeException(sprintf(
                    'Refusing to clean up orphaned stats: `%s` reports zero rows, so every stats row '
                    . 'would look orphaned. Check the database before retrying.',
                    $parent
                ));
            }
        }

        $deleted = [];
        $total = 0;
        $truncated = false;

        foreach (self::ORPHAN_TARGETS as $target) {
            $key = $target['table'] . '.' . $target['column'];

            // Column and table names come from the private const above, never
            // from the request, so interpolating them carries no injection. The
            // LIMIT is an already-clamped int for the same reason the repository
            // interpolates its own: the client binds every value as a string and
            // MySQL rejects `LIMIT '5000'`.
            $nullGuard = $target['nullable'] ? sprintf('`%s` IS NOT NULL AND ', $target['column']) : '';

            $sql = sprintf(
                'DELETE FROM `%s` WHERE %sNOT EXISTS ('
                . 'SELECT 1 FROM `%s` WHERE `%s`.`id` = `%s`.`%s`) LIMIT %d',
                $target['table'],
                $nullGuard,
                $target['parent'],
                $target['parent'],
                $target['table'],
                $target['column'],
                $limit,
            );

            /** @var mixed $affected */
            $affected = $this->db->query($sql);
            $count = is_int($affected) ? $affected : 0;

            $deleted[$key] = ($deleted[$key] ?? 0) + $count;
            $total += $count;

            if ($count >= $limit) {
                $truncated = true;
            }
        }

        return [
            'deleted' => $deleted,
            'total' => $total,
            'limit' => $limit,
            // TRUE means at least one table hit the cap, so there is more to
            // remove and the operator should run it again. Reported rather than
            // looped, so one click can never turn into an unbounded delete.
            'truncated' => $truncated,
        ];
    }

    /**
     * Merge `media_items` rows sharing a filesystem path.
     *
     * ⚠ QUEUED-ONLY: {@see PathDeduper::findDuplicateGroups()} is an unbounded
     * `media_items` scan and each group costs its own transaction.
     *
     * Defaults to a DRY RUN. `apply` must be explicitly true to delete
     * anything — the same default `php bin/phlix media:dedupe-paths` has, and
     * for the same reason.
     *
     * @param array<string, mixed> $params `apply`, `batch_size`.
     *
     * @return array<string, mixed>
     */
    private function dedupePaths(array $params): array
    {
        $apply = ($params['apply'] ?? false) === true;
        $batchSize = $this->intParam($params, 'batch_size', self::DEFAULT_DEDUPE_BATCH_SIZE);
        $batchSize = max(1, min(self::MAX_DEDUPE_BATCH_SIZE, $batchSize));

        return PathDedupeRunner::run($this->pathDeduper, $apply, $batchSize);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Read an integer parameter, falling back when it is absent or not numeric.
     *
     * @param array<string, mixed> $params
     */
    private function intParam(array $params, string $key, int $default): int
    {
        /** @var mixed $raw */
        $raw = $params[$key] ?? null;

        return is_numeric($raw) ? (int) $raw : $default;
    }

    /**
     * Row count for a table named by a private constant — never by a request.
     */
    private function countRows(string $table): int
    {
        /** @var mixed $rows */
        $rows = $this->db->query(sprintf('SELECT COUNT(*) AS cnt FROM `%s`', $table));

        if (!is_array($rows) || $rows === []) {
            return 0;
        }

        $row = $rows[0];
        if (!is_array($row)) {
            return 0;
        }

        return is_numeric($row['cnt'] ?? null) ? (int) $row['cnt'] : 0;
    }
}
