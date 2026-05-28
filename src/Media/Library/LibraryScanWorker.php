<?php

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\StructuredLogger;
use Throwable;
use Workerman\Timer;

/**
 * Async library-scan worker (Step 1.1b).
 *
 * Drains the `library_scan_jobs` queue (the 1.1a table, which doubles as the
 * queue transport — there is no Redis / queue library in the stack) off the
 * HTTP request path. The HTTP {@see \Phlix\Server\Http\Controllers\LibraryController}
 * now only *enqueues* a job and returns `202`; this worker is the consumer that
 * actually runs the scan.
 *
 * Lifecycle per job: {@see ScanJobRepository::claimNext()} atomically claims the
 * oldest `queued` row and flips it to `running`; the worker then runs the
 * existing {@see LibraryManager::scanLibrary()} / {@see LibraryManager::rescanLibrary()}
 * by `type` and records the outcome via {@see ScanJobRepository::markCompleted()}
 * (success) or {@see ScanJobRepository::markFailed()} (on any `\Throwable`).
 *
 * **Coarse progress is intentional.** `LibraryManager::scanLibrary()` /
 * `rescanLibrary()` return `void` and emit no counts, so the worker records the
 * honest `queued → running → completed/failed` lifecycle and leaves `items_*`
 * at 0. It deliberately does NOT fabricate counts and does NOT expand the scan
 * internals to emit per-item progress (a future step can wire real counters
 * through {@see ScanJobRepository::updateProgress()}).
 *
 * **Resident-memory (Workerman) safety.** The loop uses {@see Timer::add()} —
 * never a blocking `sleep()` (cf. the legacy
 * {@see \Phlix\Media\Markers\Detection\BackgroundDetectorWorker::runLoop()},
 * which is the §4 violation this worker must not copy). The worker's only
 * instance state is its injected dependencies, so it holds no unbounded
 * `static`/`global` state.
 *
 * @package Phlix\Media\Library
 * @since   1.1b (Async scan worker)
 */
class LibraryScanWorker
{
    /** @var ScanJobRepository Queue + progress store the worker drains. */
    private ScanJobRepository $jobs;

    /** @var LibraryManager Existing scan engine the worker delegates to. */
    private LibraryManager $libraries;

    /** @var StructuredLogger Logger for the MEDIA channel. */
    private StructuredLogger $logger;

    /**
     * @param ScanJobRepository     $jobs      Queue + progress store.
     * @param LibraryManager        $libraries Existing scan engine.
     * @param StructuredLogger|null $logger    Optional logger; defaults to the
     *                                          MEDIA channel via
     *                                          {@see \Phlix\Common\Logger\LoggerFactory}.
     *
     * @since 1.1b
     */
    public function __construct(
        ScanJobRepository $jobs,
        LibraryManager $libraries,
        ?StructuredLogger $logger = null
    ) {
        $this->jobs = $jobs;
        $this->libraries = $libraries;
        $this->logger = $logger ?? \Phlix\Common\Logger\LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Process at most one queued job.
     *
     * Atomically claims the oldest `queued` job. When nothing is queued (or the
     * claim lost the race) returns `false` without touching the scan engine.
     * Otherwise runs the scan/rescan for the job's `type` and records the
     * outcome:
     *  - success → {@see ScanJobRepository::markCompleted()}, returns `true`;
     *  - any `\Throwable` → {@see ScanJobRepository::markFailed()} with the
     *    exception message + an error log, returns `true` (a job WAS processed,
     *    success or fail).
     *
     * Defensive: a claimed row missing a usable `id`/`library_id` is logged and
     * skipped (returns `true` so the caller advances) — it is never marked
     * completed, since it is not a real job.
     *
     * @return bool `true` when a job was processed (completed or failed),
     *              `false` when the queue was empty.
     *
     * @since 1.1b
     */
    public function runOnce(): bool
    {
        $job = $this->jobs->claimNext();
        if ($job === null) {
            return false;
        }

        $jobId = is_string($job['id'] ?? null) ? $job['id'] : '';
        $libraryId = is_string($job['library_id'] ?? null) ? $job['library_id'] : '';
        $type = is_string($job['type'] ?? null) ? $job['type'] : 'scan';

        if ($jobId === '' || $libraryId === '') {
            $this->logger->error('LibraryScanWorker: claimed an invalid job row; skipping', [
                'job_id' => $jobId,
                'library_id' => $libraryId,
                'type' => $type,
            ]);
            return true;
        }

        try {
            if ($type === 'rescan') {
                $this->libraries->rescanLibrary($libraryId);
            } else {
                $this->libraries->scanLibrary($libraryId);
            }

            $this->jobs->markCompleted($jobId);

            $this->logger->info('LibraryScanWorker: scan job completed', [
                'job_id' => $jobId,
                'library_id' => $libraryId,
                'type' => $type,
            ]);
        } catch (Throwable $e) {
            $this->jobs->markFailed($jobId, $e->getMessage());

            $this->logger->error('LibraryScanWorker: scan job failed', [
                'job_id' => $jobId,
                'library_id' => $libraryId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * Start the polling loop on the Workerman event loop.
     *
     * Installs a {@see Timer} that calls {@see self::runOnce()} once per tick
     * (one job per tick — a backlog of N drains in ≤ N ticks, which is fine for
     * infrequent scans and keeps a single tick from starving the event loop).
     * Must be called from inside a worker's `onWorkerStart` because
     * {@see Timer} requires a running event loop.
     *
     * @param int $pollSeconds Poll interval in seconds.
     *
     * @return void
     *
     * @since 1.1b
     */
    public function start(int $pollSeconds): void
    {
        Timer::add($pollSeconds, fn(): bool => $this->runOnce());
    }
}
