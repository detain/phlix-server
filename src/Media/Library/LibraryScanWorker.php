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
 * {@see LibraryManager::deleteAllItems()} (`delete_all`) — or
 * {@see \Phlix\Media\MediaAsset\MediaAssetBackfill::reenqueueLibrary()} for a
 * `media_assets` job (S284: re-prime the chapter/trickplay/BIF file queue for a
 * library scanned before the trickplay producer worked) — and
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
 * **Truthful counters (S96(b)).** The sink also writes `items_added` and
 * `items_failed` from the live counter snapshot the scanner passes as its 4th
 * argument, and {@see self::runOnce()} stamps the authoritative final values via
 * {@see ScanJobRepository::markCompleted()}'s `$finalCounts` (a parameter that
 * existed but had no caller). Before this, `items_added` was NEVER written for a
 * `scan`/`rescan` job, so a fully successful scan reported `0 added` for its whole
 * life — which is why "is this scan actually writing anything?" had to be answered
 * by reverse-engineering `music_artists.created_at` timestamps during the
 * empty-music-library investigation.
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
     *
     * ⚠ **Aliases {@see ScanProgressSink::WRITE_EVERY}, which is the single definition
     * (S150).** The sink itself moved to {@see ScanProgressSink} when the CLI
     * `library:scan` command became a second consumer of it; this constant is kept so
     * the existing references in this file and in
     * {@see \Phlix\Media\Music\MusicLibraryScanner} keep resolving, and it must never
     * be given an independent value.
     */
    private const PROGRESS_WRITE_EVERY = ScanProgressSink::WRITE_EVERY;

    /** @var ScanJobRepository Queue + progress store the worker drains. */
    private ScanJobRepository $jobs;

    /** @var LibraryManager Existing scan engine the worker delegates to. */
    private LibraryManager $libraries;

    /** @var LibraryMetadataMatcher Background metadata matcher for `metadata` jobs. */
    private LibraryMetadataMatcher $metadataMatcher;

    /** @var StructuredLogger Logger for the MEDIA channel. */
    private StructuredLogger $logger;

    /**
     * Re-enqueues the media-asset (chapter/trickplay/BIF) file queue for a
     * library's EXISTING rows — the `media_assets` job type (S284).
     *
     * ⚠ **Nullable, and PHP-DI will leave it null unless it is NAMED at the
     * wiring site.** `MediaServicesProvider` registers this class with
     * `autowire()`, which SKIPS ctor params that have a default — so an optional
     * dependency added here is silently absent in production unless a matching
     * `->constructorParameter('mediaAssetBackfill', …)` is added too
     * ([[project_di_provider_silent_degradation]]). When it IS null a
     * `media_assets` job fails loudly via the catch below rather than being
     * silently treated as a plain `scan`, which is the shape that would start an
     * expensive rescan nobody asked for.
     *
     * @var \Phlix\Media\MediaAsset\MediaAssetBackfill|null
     */
    private ?\Phlix\Media\MediaAsset\MediaAssetBackfill $mediaAssetBackfill;

    /**
     * @param ScanJobRepository      $jobs            Queue + progress store.
     * @param LibraryManager         $libraries       Existing scan engine.
     * @param LibraryMetadataMatcher $metadataMatcher Background metadata matcher
     *                                                run for `metadata` jobs.
     * @param StructuredLogger|null  $logger          Optional logger; defaults
     *                                                to the MEDIA channel via
     *                                                {@see \Phlix\Common\Logger\LoggerFactory}.
     * @param \Phlix\Media\MediaAsset\MediaAssetBackfill|null $mediaAssetBackfill
     *        Runs `media_assets` jobs. See the property docblock for why it must
     *        be named explicitly in the container.
     *
     * @since 1.1b
     */
    public function __construct(
        ScanJobRepository $jobs,
        LibraryManager $libraries,
        LibraryMetadataMatcher $metadataMatcher,
        ?StructuredLogger $logger = null,
        ?\Phlix\Media\MediaAsset\MediaAssetBackfill $mediaAssetBackfill = null
    ) {
        $this->jobs = $jobs;
        $this->libraries = $libraries;
        $this->metadataMatcher = $metadataMatcher;
        $this->logger = $logger ?? \Phlix\Common\Logger\LoggerFactory::get(LogChannels::MEDIA);
        $this->mediaAssetBackfill = $mediaAssetBackfill;
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

        /**
         * Authoritative counter values stamped by `markCompleted()` (S96(b)). Only
         * the `scan`/`rescan` branches can fill it; every other job type reports
         * through `updateProgress()` as before and leaves this empty, which
         * `markCompleted()` treats as "write no counters".
         *
         * @var array<string, int> $finalCounts
         */
        $finalCounts = [];

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
                $rescan = $this->libraries->rescanLibrary($libraryId, [], $this->scanProgressSink($jobId));
                // Final counters. `items_removed` is the prune count, which was
                // computed and then DISCARDED before S96 — a rescan that pruned rows
                // reported 0 removed. `items_updated` is deliberately absent: that
                // column doubles as the progress numerator (processed files), so
                // writing a semantic "updated" count here would collapse the UI
                // percentage at the very moment the job completes.
                //
                // ⚠ THIS IS THE ONE PLACE A SINGLE JOB ROW CHANGES THE MEANING OF
                // `items_added` MID-LIFETIME (review r1 LOW-4). Above, the sink
                // streamed the scanner's own new-LEAF count (for music: new tracks);
                // here `rescanLibrary()` hands over a row-count DELTA over ALL
                // `media_items` types, so it also counts the artist/album container
                // rows. Usually the delta is the larger of the two, but not always —
                // a `music_tracks` row added against a pre-existing `media_items` row
                // (the S96(e) residue shape) makes it smaller, and a counter that goes
                // 12 → 3 at completion reads as data disappearing. That is why
                // `markCompleted()` writes `items_added` through `GREATEST()`: the row is
                // a high-water mark for it, so the two definitions can coexist without the
                // number ever retracting. Unifying the definitions instead would mean
                // changing `rescanLibrary()`'s public return semantics.
                //
                // ⚠ Of the three keys below, exactly TWO are clamped —
                // `ScanJobRepository::MONOTONIC_FINAL_COLUMNS` is `items_added` +
                // `items_failed`. `items_removed` is a PLAIN assignment: review r2 F5
                // measured its clamp as provably inert (the only writers that set it live
                // in the `prune`/`delete_all` branches, which reach `markCompleted()` with
                // an EMPTY `$finalCounts`, so the prior value at clamp time was always the
                // column default 0). This comment said "these three counters" until review
                // r3 finding 2 caught that the same round had made it false.
                $finalCounts = [
                    'items_added' => $rescan->added,
                    'items_removed' => $rescan->removed,
                    'items_failed' => $rescan->failed,
                ];
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
            } elseif ($type === 'media_assets') {
                // S284: re-prime the FILE-based media-asset queue for this
                // library's existing rows. Reads no media file and writes no
                // media_items — the ffmpeg work is done afterwards by
                // MediaAssetWorker at its own bounded concurrency.
                if ($this->mediaAssetBackfill === null) {
                    // Loud, not silent. Falling through to the `else` branch would
                    // start a full library SCAN for an operator who asked for a
                    // targeted backfill — the single most expensive way to be
                    // wrong here (a production music rescan ran 9 h 55 m).
                    throw new \RuntimeException(
                        'media_assets job requires a MediaAssetBackfill dependency; '
                        . 'the container did not supply one'
                    );
                }
                $backfill = $this->mediaAssetBackfill->reenqueueLibrary(
                    $libraryId,
                    function (int $processed, int $total) use ($jobId): void {
                        $this->jobs->updateProgress($jobId, [
                            'items_found'   => $total,
                            'items_updated' => $processed,
                        ]);
                    },
                );
                // `items_added` is the number of media-asset jobs enqueued, which
                // is what the admin surface should show as the work this job
                // produced. It is GREATEST()-clamped by markCompleted(), and the
                // live sink above never writes the column, so the prior value is
                // always the column default 0 — the clamp cannot lower it.
                $finalCounts = [
                    'items_found'   => $backfill->scanned,
                    'items_updated' => $backfill->scanned,
                    'items_added'   => $backfill->enqueued,
                ];
            } elseif ($type === 'delete_all') {
                // Destructive: remove every item in the library (cascades user
                // data). The controller gates this behind an explicit confirm.
                $removed = $this->libraries->deleteAllItems($libraryId);
                $this->jobs->updateProgress($jobId, ['items_removed' => $removed]);
            } else {
                $scan = $this->libraries->scanLibrary($libraryId, $this->scanProgressSink($jobId));
                // See the `rescan` branch for why `items_updated` is not written.
                $finalCounts = [
                    'items_added' => $scan->added,
                    'items_failed' => $scan->failed,
                ];
            }

            $this->jobs->markCompleted($jobId, $finalCounts);

            $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
            $this->logger->info('LibraryScanWorker::runOnce Completed job [jobId=' . $jobId . '] [libraryId='
                . $libraryId . '] [type=' . $type . '] [duration=' . round($durationMs, 2) . 'ms]');

            $this->logger->info('LibraryScanWorker: scan job completed', [
                'job_id' => $jobId,
                'library_id' => $libraryId,
                'type' => $type,
            ]);
        } catch (Throwable $e) {
            // No final counters here: the throw destroyed the ScanResult, so the row
            // keeps whatever the live sink last wrote — accurate to within
            // PROGRESS_WRITE_EVERY files for music, still 0 for the scanner paths that
            // report their added count only per completed path. See
            // ScanJobRepository::markFailed() (review r1 LOW-7).
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
     * **`items_added` / `items_failed` ride along (S96(b)).** `$counts` is the
     * scanner's live {@see ScanResult::progressCounts()} snapshot, folded into the
     * UPDATE this sink was already issuing — deliberately NOT a second statement,
     * because the throttle exists precisely to bound job-row writes on a 61k-file
     * library. When a scanner supplies no counts (the video path has no per-file
     * added/failed counter) the keys are simply absent and those columns are left
     * untouched, so nothing regresses to a false 0.
     *
     * ⚠ `items_updated` stays the PROCESSED-FILE count, not
     * {@see ScanResult::$updated}. The admin UI computes its percentage as
     * `items_updated / items_found`, so writing a semantic "updated items" value into
     * it would show ~0 % on a library whose files are all unchanged. That overload is
     * pre-existing; this method is the reason it must not be disturbed.
     *
     * ⚠ **THE IMPLEMENTATION MOVED (S150).** It now lives in {@see ScanProgressSink}
     * so the CLI `library:scan` command — which before S150 wrote NOTHING to
     * `library_scan_jobs`, leaving the admin Libraries page showing a stale `failed`
     * badge for the whole of a live CLI run — streams progress in exactly the same
     * shape as this worker. This method is kept as the worker's seam and must remain a
     * pure delegation: two implementations is how the two operator surfaces would
     * start disagreeing.
     *
     * @param string $jobId The scan job to stream progress onto.
     *
     * @return callable(int, int, string, array<string, int>): void
     *         `(processed, total, currentPath, counts)`.
     *
     * @since 0.34.0
     */
    private function scanProgressSink(string $jobId): callable
    {
        return ScanProgressSink::for($this->jobs, $jobId, self::PROGRESS_WRITE_EVERY);
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
     * ⚠ **THIS METHOD DEPENDS ON THE `count:1` SINGLE-CONSUMER INVARIANT — see the
     * reaper block below before changing how the worker is spawned.**
     *
     * @param int $pollSeconds Poll interval in seconds.
     *
     * @return void
     *
     * @since 1.1b
     */
    public function start(int $pollSeconds): void
    {
        // Reap orphaned `running` jobs left behind by a previous worker's
        // restart/crash before we begin draining. Such a row would otherwise sit
        // `running` forever and keep a scan UI spinner alive (cf. the music-scan
        // hang incident).
        //
        // ⚠ S96(c) — THE BLAST RADIUS IS DELIBERATE, AND IT IS THE WHOLE TABLE.
        // reapStaleJobs() fails EVERY `running` row, not just this library's and not
        // just old ones.
        //
        // ⚠ S150 FALSIFIED THE INVARIANT THIS USED TO CLAIM, AND THE CLAIM HAS BEEN
        // REMOVED RATHER THAN SOFTENED. The old text here said that because this
        // worker is the single (`count:1`) consumer, "on a fresh start nothing is
        // legitimately running", so every reaped row was by definition an orphan.
        // That is no longer true: `php bin/phlix library:scan` inserts its OWN
        // `running` row (ScanJobRepository::startRunning()) for a scan it executes in
        // its own process. That row is never queued and never claimed, so this worker
        // has no knowledge of it — and a `phlix-server` restart mid-CLI-scan reaps a
        // row whose scan is alive. The observable damage is a `failed` badge on a
        // healthy scan (until the CLI's markCompleted() overwrites it) PLUS
        // hasActiveJobForLibrary() reporting the library idle, which re-opens the door
        // to a second, genuinely concurrent scan. Bounding the reaper needs per-job
        // worker ownership (an owner id / heartbeat column) — a schema change outside
        // S150/S151, filed rather than half-done.
        //
        // The `count:1` constraint is still REAL and still load-bearing for the rest
        // of the queue, so it stays documented here:
        //
        //   * `config/process.php` sets `library-scan` to `count: 1`, and `start.php`
        //     calls this method once per fork — so `count: 2` would have fork #2 fail
        //     fork #1's just-claimed job while its scan kept running, silently, since
        //     nothing re-reads the job row mid-scan;
        //   * `scripts/run-library-scan-worker.php` is an alternative standalone
        //     spawner for the SAME worker. Running it alongside the managed worker is
        //     safe for CLAIMING (claimNext() is an atomic conditional UPDATE) but NOT
        //     for this reaper. `config/process.php` documents that constraint.
        //
        // AN AGE GUARD WAS CONSIDERED AND REJECTED, because this is the only call
        // site and it runs ONCE at boot: `started_at < NOW() - INTERVAL n` would mean
        // an orphan younger than `n` at the instant of the restart is never reaped at
        // all (the reaper does not run again), which is exactly the "spinner alive
        // forever" hang it exists to prevent. Moving it onto a Timer to close that
        // hole is worse still: a legitimate music scan of the production library ran
        // for 4 h 09 m before its first durable write, so any age threshold small
        // enough to be useful would fail live scans. Bounding the radius safely needs
        // per-job worker ownership (an owner id / heartbeat column), which is a schema
        // change well outside this step.
        try {
            $reaped = $this->jobs->reapStaleJobs('Interrupted by server restart');
            if ($reaped > 0) {
                $this->logger->info('LibraryScanWorker: reaped stale running jobs at startup', [
                    'reaped' => $reaped,
                ]);
            }
        } catch (Throwable $e) {
            // Never let a reaper hiccup stop the worker from draining new jobs.
            $this->logger->error('LibraryScanWorker: stale-job reaper failed at startup', [
                'error' => $e->getMessage(),
            ]);
        }

        Timer::add($pollSeconds, fn(): bool => $this->runOnce());
    }
}
