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
 * Result of a library scan operation.
 *
 * @property int $scanned Total number of files scanned
 * @property int $added Number of new items added
 * @property int $updated Number of existing items updated
 * @property int $removed Number of items pruned (source file gone from disk)
 * @property int $failed Number of files the scan did NOT index because of an error
 * @property int $durationMs Duration of the scan in milliseconds
 */
final class ScanResult
{
    public int $scanned = 0;
    public int $added = 0;
    public int $updated = 0;

    /**
     * Number of items pruned during a non-destructive rescan because their
     * source file no longer exists on disk (including empty series/season
     * containers left behind by that pruning). Zero for a plain scan.
     */
    public int $removed = 0;

    /**
     * Number of files this scan FAILED to index (S96(f)).
     *
     * A partially-failed scan used to report clean success from top to bottom:
     * there was no `errors`/`failed` field here, `toArray()` therefore could not
     * expose one, and the job row had no failed-file counter — so a music scan
     * that skipped a file returned `scanned=4 added=3` with nothing anywhere
     * saying a file had been lost. The only trace was a
     * `logger->error('Skipping track after error during indexing')` which, until
     * S96(a), went into `sys_get_temp_dir()` INSIDE the systemd unit's
     * `PrivateTmp` — i.e. nowhere an operator can look.
     *
     * S95 made that strictly worse before this field existed: its `finally`
     * (which recomputes `music_albums.total_tracks` from the rows that really
     * exist) removed the last DB-VISIBLE trace, because the counts are now
     * correct and self-consistent while the library is one file short. Hence a
     * counter rather than "spot the inconsistency".
     *
     * Semantics: FILES not indexed because something threw or a write failed —
     * NOT files deliberately skipped by a scan policy (hidden files,
     * `scanner.ignore_patterns`, a non-audio extension), which are never counted
     * in `scanned` either. Exposure is bounded to one scan cycle since the next
     * clean scan re-adds the file, so this is an OBSERVABILITY counter, not a
     * data-loss alarm.
     */
    public int $failed = 0;

    public int $durationMs = 0;

    /**
     * Gets a summary array of the scan result.
     *
     * **There is exactly ONE production consumer of THIS METHOD**
     * ({@see \Phlix\Server\WebPortal\WebPortalRouter::scanMusicDirectory()}, `:2918`),
     * which returns the array verbatim as the body of `POST /api/v1/music/scan`.
     * Established by `grep -rn "result->toArray()" src/ bin/ scripts/ public/`, whose
     * only other two hits are different classes that happen to share the method name
     * (`Hub/SubdomainClient.php:202`, `Arr/SyncController.php:116` — a TRaSH-Guides
     * syncer result).
     *
     * ⚠ This is the THIRD version of this list, so it is worth saying how the first two
     * were wrong and what the rule is. v1 claimed "and the Arr sync response" — a
     * different class. v2 (review r1's fix) replaced that with "and `php bin/phlix
     * library:scan`" — also wrong, and review r2 caught it: the command reads
     * `$result->scanned`, `->added`, … as PROPERTIES and never calls `toArray()`
     * (`grep toArray src/Console/Commands/LibraryScanCommand.php` → no hit). **Reading
     * the object is not consuming this method's array**, and only the latter can be
     * broken by changing a key. The other two surfaces read the object directly:
     *
     *  - `php bin/phlix library:scan` — property access, so it is affected by a renamed
     *    or removed PROPERTY, not by this array's keys;
     *  - the async scan-job path — individual COLUMNS via
     *    {@see \Phlix\Media\Library\ScanJobRepository} (see {@see self::progressCounts()}).
     *
     * `failed` was add-only, so the one real consumer's contract did not break.
     *
     * @return array<string, int> Summary array
     */
    public function toArray(): array
    {
        return [
            'scanned' => $this->scanned,
            'added' => $this->added,
            'updated' => $this->updated,
            'removed' => $this->removed,
            'failed' => $this->failed,
            'duration_ms' => $this->durationMs,
        ];
    }

    /**
     * The live counter triple a scan streams onto its job row while it is still
     * running ({@see \Phlix\Media\Library\LibraryScanWorker::scanProgressSink()}).
     *
     * Deliberately NOT `toArray()`: that shape is a response body with a
     * `duration_ms` that is only meaningful once the scan has ended, whereas this
     * one is read mid-walk, once per throttled progress write.
     *
     * @return array{added: int, updated: int, failed: int}
     */
    public function progressCounts(): array
    {
        return [
            'added' => $this->added,
            'updated' => $this->updated,
            'failed' => $this->failed,
        ];
    }
}
