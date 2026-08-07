<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Admin\Maintenance;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Throwable;

/**
 * Drains the `maintenance_jobs` queue (S77).
 *
 * ## Where this runs, and why not in a Worker of its own
 *
 * Inside the existing `phlix-background-timers` process — a `count = 1` fork
 * that already owns the storage-snapshot timer, the backup timer and the
 * transcode reaper. Two reasons that is the right home rather than a new
 * `Worker`:
 *
 *  - `count = 1` is what makes "one maintenance job at a time" true. The
 *    atomic claim in {@see MaintenanceJobRepository::claimNext()} would keep
 *    two drainers from claiming the SAME row, but not from running two
 *    expensive whole-table jobs at once.
 *  - The blocking work these jobs do (`du -sb`, an unbounded `media_items`
 *    scan) is the same shape as what that worker's existing timers already do,
 *    so nothing new is exposed to it. Putting it in the HTTP worker would
 *    stall every concurrent connection on that process; putting it in a new
 *    Worker would need a `start.php` change for no gain.
 *
 * ## Poll, not push
 *
 * There is no IPC between the HTTP fork that enqueues and this fork that
 * drains, so the queue is polled on a {@see self::POLL_SECONDS} timer. Latency
 * is bounded by that interval, which is why it is short: the admin pressed a
 * button and is watching a spinner.
 *
 * @package Phlix\Admin\Maintenance
 * @since 1.9
 */
class MaintenanceQueueWorker
{
    /** Seconds between queue polls. Short: an admin is watching a spinner. */
    public const POLL_SECONDS = 5;

    private StructuredLogger $logger;

    public function __construct(
        private readonly MaintenanceJobRepository $jobs,
        private readonly MaintenanceTaskRunner $runner,
        ?StructuredLogger $logger = null,
    ) {
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::APPLICATION);
    }

    /**
     * Reap orphans from a previous process, then arm the poll timer.
     *
     * The boot reap is not optional bookkeeping. A `running` row is never
     * claimed again — {@see MaintenanceJobRepository::claimNext()} only takes
     * `queued` rows — so a job whose process died stays `running` forever, and
     * {@see MaintenanceJobRepository::enqueue()}'s already-pending check then
     * refuses every future request for that task. The admin's button would
     * silently stop working, with no error anywhere.
     *
     * Fully guarded: this runs inside a forked worker where an uncaught
     * throwable takes the process down, and losing maintenance must never cost
     * the backup/snapshot timers that share the fork.
     */
    public function start(): void
    {
        try {
            $reaped = $this->jobs->reapRunning('Interrupted by a server restart');
            if ($reaped > 0) {
                $this->logger->info('MaintenanceQueueWorker: reaped interrupted jobs at startup', [
                    'reaped' => $reaped,
                ]);
            }
        } catch (Throwable $e) {
            $this->logger->error('MaintenanceQueueWorker: startup reap failed', [
                'error' => $e->getMessage(),
            ]);
        }

        \Workerman\Timer::add(self::POLL_SECONDS, function (): void {
            $this->drain();
        });
    }

    /**
     * Run every job currently queued, one at a time.
     *
     * Loops rather than doing one per tick so a burst of clicks is not spread
     * over a minute of polls. Bounded by the queue emptying; each iteration
     * re-claims, so a job enqueued mid-drain is picked up in the same pass.
     *
     * @return int How many jobs ran.
     */
    public function drain(): int
    {
        $ran = 0;
        while ($this->runOnce()) {
            $ran++;
        }

        return $ran;
    }

    /**
     * Claim and execute at most one job.
     *
     * @return bool True when a job was claimed (so the caller should look
     *              again), false when the queue was empty.
     */
    public function runOnce(): bool
    {
        try {
            $job = $this->jobs->claimNext();
        } catch (Throwable $e) {
            $this->logger->error('MaintenanceQueueWorker: claim failed', ['error' => $e->getMessage()]);

            return false;
        }

        if ($job === null) {
            return false;
        }

        $jobId = is_string($job['id'] ?? null) ? $job['id'] : '';
        $task = is_string($job['task'] ?? null) ? $job['task'] : '';
        /** @var array<string, mixed> $params */
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];

        if ($jobId === '') {
            // Nothing to mark, and nothing sane to do with the row. Report the
            // claim so the caller stops looping on it.
            $this->logger->error('MaintenanceQueueWorker: claimed a job row with no id');

            return true;
        }

        // A task name that is not in the vocabulary is FAILED, never run. The
        // column is a VARCHAR precisely so the vocabulary can live in PHP, which
        // means the database cannot reject a bad value on this codebase's behalf.
        if (!MaintenanceTask::isValid($task)) {
            $this->jobs->markFailed($jobId, 'Unknown maintenance task: ' . $task);
            $this->logger->error('MaintenanceQueueWorker: unknown task', [
                'job_id' => $jobId,
                'task' => $task,
            ]);

            return true;
        }

        $startedAt = hrtime(true);

        try {
            $result = $this->runner->run($task, $params);
            $this->jobs->markCompleted($jobId, $result);

            $this->logger->info('MaintenanceQueueWorker: task completed', [
                'job_id' => $jobId,
                'task' => $task,
                'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000.0, 2),
            ]);
        } catch (Throwable $e) {
            $this->jobs->markFailed($jobId, $e->getMessage());

            $this->logger->error('MaintenanceQueueWorker: task failed', [
                'job_id' => $jobId,
                'task' => $task,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }
}
