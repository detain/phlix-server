<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

/**
 * The ONE definition of a `scan`/`rescan` progress sink.
 *
 * A scanner ticks its `$onProgress` callback once per media file. This builds the
 * callback that turns those ticks into `library_scan_jobs` writes, throttled to at
 * most one every {@see self::WRITE_EVERY} files (and always on the final file) so a
 * 61k-file library does not issue one UPDATE per media file.
 *
 * ## Why this is its own class (S150)
 *
 * The implementation used to be a private method on {@see LibraryScanWorker}. S150
 * gave it a SECOND consumer — {@see \Phlix\Console\Commands\LibraryScanCommand},
 * which before S150 wrote nothing at all to `library_scan_jobs`, so a CLI scan was
 * invisible to the admin Libraries page while a stale `failed` badge from an earlier
 * job kept claiming the last scan had broken. Two operator surfaces reporting
 * progress from two copies of this logic is precisely how they start disagreeing, so
 * there is one copy and both call it.
 *
 * A **static factory taking the repository** rather than a method ON the repository
 * is deliberate: {@see LibraryScanWorker}'s tests mock {@see ScanJobRepository} and
 * assert on the `updateProgress()` calls the sink makes. A sink built by a method on
 * that same mocked object would return `null` and those assertions would silently
 * observe nothing — a false green of exactly the kind this codebase has been bitten
 * by before.
 *
 * ⚠ **`items_updated` is the PROCESSED-FILE count, NOT {@see ScanResult::$updated}.**
 * The admin UI computes its percentage as `items_updated / items_found`, so writing a
 * semantic "rows updated" value into it would show ~0 % on a library whose files are
 * all unchanged. The overload is pre-existing and load-bearing: do not "correct" it.
 *
 * ## Resident-memory (Workerman) note
 *
 * Nothing here is `static` state. {@see self::for()} is a pure factory whose only
 * retained value is the per-sink `$lastWrite` counter captured in the returned
 * closure, which dies with the closure at the end of one scan.
 *
 * @package Phlix\Media\Library
 * @since   0.36.0 (S150)
 */
final class ScanProgressSink
{
    /**
     * Persist scan/rescan progress at most once per this many processed files (and
     * always on the final file), to bound job-row writes on big libraries.
     *
     * ⚠ This is the single definition. {@see LibraryScanWorker::PROGRESS_WRITE_EVERY}
     * aliases it and must never be given an independent value.
     */
    public const WRITE_EVERY = 25;

    /**
     * Build the throttled sink for one job.
     *
     * `items_added` / `items_failed` ride along in the SAME statement, taken from the
     * scanner's live counter snapshot (`$counts`, i.e.
     * {@see ScanResult::progressCounts()}) — deliberately not a second statement,
     * because the throttle exists precisely to bound job-row writes. A scanner that
     * supplies no counts (the video path has no per-file added/failed counter) simply
     * omits those keys, so the columns are left untouched rather than regressed to a
     * false 0.
     *
     * @param ScanJobRepository $jobs       Store the progress is written to.
     * @param string            $jobId      The job row to stream progress onto.
     * @param int               $writeEvery Persist at most one update per this many
     *                                      files. Values < 1 are clamped to 1 so a
     *                                      misconfiguration cannot divide by zero or
     *                                      suppress every write.
     *
     * @return callable(int, int, string, array<string, int>): void
     *         `(processed, total, currentPath, counts)`.
     */
    public static function for(
        ScanJobRepository $jobs,
        string $jobId,
        int $writeEvery = self::WRITE_EVERY
    ): callable {
        $lastWrite = 0;
        $every = $writeEvery > 0 ? $writeEvery : 1;

        return function (
            int $processed,
            int $total,
            string $currentPath,
            array $counts = []
        ) use (
            $jobs,
            $jobId,
            $every,
            &$lastWrite
        ): void {
            if ($processed !== $total && $processed - $lastWrite < $every) {
                return;
            }
            $lastWrite = $processed;

            $payload = ['items_found' => $total, 'items_updated' => $processed];
            if (isset($counts['added'])) {
                $payload['items_added'] = (int) $counts['added'];
            }
            if (isset($counts['failed'])) {
                $payload['items_failed'] = (int) $counts['failed'];
            }

            $jobs->updateProgress($jobId, $payload, $currentPath);
        };
    }
}
