<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
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
 * oldest `queued` row and flips it to `running`; the worker then dispatches on
 * `type` — the existing {@see LibraryManager::scanLibrary()} /
 * {@see LibraryManager::rescanLibrary()} for `scan`/`rescan`, or
 * {@see LibraryMetadataMatcher::matchLibrary()} for a `metadata` /
 * `metadata_refresh` job (the latter forcing a re-match of already-matched
 * items via {@see LibraryMetadataMatcher::setForceRefresh()}), or one of the
 * fine-grained maintenance ops — {@see LibraryManager::pruneLibrary()} (`prune`),
 * {@see LibraryManager::clearMetadata()} (`clear_metadata`),
 * {@see LibraryManager::clearArtwork()} (`clear_artwork`) or
 * {@see LibraryManager::deleteAllItems()} (`delete_all`) — and
 * records the outcome via {@see ScanJobRepository::markCompleted()} (success) or
 * {@see ScanJobRepository::markFailed()} (on any `\Throwable`).
 *
 * **Real per-file progress.** `scan`/`rescan` pass a progress sink to
 * {@see LibraryManager::scanLibrary()} / {@see LibraryManager::rescanLibrary()};
 * the scanner pre-counts media files (the denominator) and ticks once per
 * processed file, which {@see self::scanProgressSink()} coalesces onto the job
 * row as `items_found` (total) / `items_updated` (processed) + `current_path`,
 * matching the `metadata` job's percentage shape. Writes are throttled to one
 * every {@see self::PROGRESS_WRITE_EVERY} files so a large library does not
 * hammer the job row.
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
    /**
     * Persist scan/rescan progress at most once per this many processed files
     * (and always on the final file), to bound job-row writes on big libraries.
     */
    private const PROGRESS_WRITE_EVERY = 25;

    /** @var ScanJobRepository Queue + progress store the worker drains. */
    private ScanJobRepository $jobs;

    /** @var LibraryManager Existing scan engine the worker delegates to. */
    private LibraryManager $libraries;

    /** @var LibraryMetadataMatcher Background metadata matcher for `metadata` jobs. */
    private LibraryMetadataMatcher $metadataMatcher;

    /** @var StructuredLogger Logger for the MEDIA channel. */
    private StructuredLogger $logger;

    /**
     * @param ScanJobRepository      $jobs            Queue + progress store.
     * @param LibraryManager         $libraries       Existing scan engine.
     * @param LibraryMetadataMatcher $metadataMatcher Background metadata matcher
     *                                                run for `metadata` jobs.
     * @param StructuredLogger|null  $logger          Optional logger; defaults
     *                                                to the MEDIA channel via
     *                                                {@see \Phlix\Common\Logger\LoggerFactory}.
     *
     * @since 1.1b
     */
    public function __construct(
        ScanJobRepository $jobs,
        LibraryManager $libraries,
        LibraryMetadataMatcher $metadataMatcher,
        ?StructuredLogger $logger = null
    ) {
        $this->jobs = $jobs;
        $this->libraries = $libraries;
        $this->metadataMatcher = $metadataMatcher;
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

        $this->logger->info('LibraryScanWorker::runOnce Starting job [jobId=' .
            $jobId . '] [libraryId=' . $libraryId . '] [type=' . $type . ']');

        if ($jobId === '' || $libraryId === '') {
            $this->logger->error('LibraryScanWorker: claimed an invalid job row; skipping', [
                'job_id' => $jobId,
                'library_id' => $libraryId,
                'type' => $type,
            ]);
            return true;
        }

        $startTime = hrtime(true);

        try {
            if ($type === 'metadata' || $type === 'metadata_refresh') {
                // `metadata_refresh` forces a re-match of already-matched items
                // (metadata_refreshed_at IS NOT NULL), so users can backfill newly
                // added metadata fields (e.g. per-episode stills); a plain
                // `metadata` job keeps the skip-already-matched behaviour. Both set
                // the flag explicitly so a reused matcher instance never inherits a
                // stale mode from a previous job.
                $this->metadataMatcher->setForceRefresh($type === 'metadata_refresh');

                // Stream progress onto the job row so the UI can show a percentage
                // (items_updated / items_found) while the match runs.
                $this->metadataMatcher->matchLibrary(
                    $libraryId,
                    function (int $processed, int $total) use ($jobId): void {
                        $this->jobs->updateProgress($jobId, [
                            'items_found'   => $total,
                            'items_updated' => $processed,
                        ]);
                    },
                );
            } elseif ($type === 'rescan') {
                // The base LibraryManager derives the library's configured paths
                // (and routes each media type to its scanner) internally, so the
                // worker only forwards the progress sink; the empty $paths arg is
                // for signature parity with the media-specific subclass managers.
                $this->libraries->rescanLibrary($libraryId, [], $this->scanProgressSink($jobId));
            } elseif ($type === 'prune') {
                // Non-destructive: run ONLY the prune pass (per-root presence
                // guards intact). Record the removed count on the job row so the
                // final markCompleted() below preserves it.
                $removed = $this->libraries->pruneLibrary($libraryId);
                $this->jobs->updateProgress($jobId, ['items_removed' => $removed]);
            } elseif ($type === 'clear_metadata') {
                // Reset each item to filesystem basics; stream processed/total as
                // items_updated/items_found so the UI shows a percentage.
                $this->libraries->clearMetadata(
                    $libraryId,
                    function (int $processed, int $total) use ($jobId): void {
                        $this->jobs->updateProgress($jobId, [
                            'items_found'   => $total,
                            'items_updated' => $processed,
                        ]);
                    },
                );
            } elseif ($type === 'clear_artwork') {
                // Delete each item's locally cached artwork; stream progress the
                // same shape as clear_metadata.
                $this->libraries->clearArtwork(
                    $libraryId,
                    function (int $processed, int $total) use ($jobId): void {
                        $this->jobs->updateProgress($jobId, [
                            'items_found'   => $total,
                            'items_updated' => $processed,
                        ]);
                    },
                );
            } elseif ($type === 'delete_all') {
                // Destructive: remove every item in the library (cascades user
                // data). The controller gates this behind an explicit confirm.
                $removed = $this->libraries->deleteAllItems($libraryId);
                $this->jobs->updateProgress($jobId, ['items_removed' => $removed]);
            } else {
                $this->libraries->scanLibrary($libraryId, $this->scanProgressSink($jobId));
            }

            $this->jobs->markCompleted($jobId);

            $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
            $this->logger->info('LibraryScanWorker::runOnce Completed job [jobId=' . $jobId . '] [libraryId='
                . $libraryId . '] [type=' . $type . '] [duration=' . round($durationMs, 2) . 'ms]');

            $this->logger->info('LibraryScanWorker: scan job completed', [
                'job_id' => $jobId,
                'library_id' => $libraryId,
                'type' => $type,
            ]);
        } catch (Throwable $e) {
            $this->jobs->markFailed($jobId, $e->getMessage());

            $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
            $this->logger->error('LibraryScanWorker::runOnce FAILED job [jobId=' . $jobId . '] [libraryId='
                . $libraryId . '] [type=' . $type . '] [duration=' . round($durationMs, 2) . 'ms]'
                . ' [error=' . $e->getMessage() . ']');

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
     * Build a throttled progress sink for a `scan`/`rescan` job.
     *
     * Mirrors the `metadata` path's `items_updated / items_found` percentage,
     * but coalesces writes: the scanner ticks once per file, so we persist at
     * most every {@see self::PROGRESS_WRITE_EVERY} files (and always on the
     * final file) to keep a large library from hammering the job row with one
     * UPDATE per media file. The current path is recorded as the progress hint.
     *
     * @param string $jobId The scan job to stream progress onto.
     *
     * @return callable(int, int, string): void `(processed, total, currentPath)`.
     *
     * @since 0.34.0
     */
    private function scanProgressSink(string $jobId): callable
    {
        $lastWrite = 0;
        return function (int $processed, int $total, string $currentPath) use ($jobId, &$lastWrite): void {
            if ($processed !== $total && $processed - $lastWrite < self::PROGRESS_WRITE_EVERY) {
                return;
            }
            $lastWrite = $processed;
            $this->jobs->updateProgress(
                $jobId,
                ['items_found' => $total, 'items_updated' => $processed],
                $currentPath,
            );
        };
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
