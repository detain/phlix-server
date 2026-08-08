<?php

/**
 * Phlix media server component: Media Asset.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\MediaAsset;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Streaming\Trickplay\BifWriter;
use Phlix\Media\Transcoding\FfmpegRunner;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Re-primes the media-asset queue for a library that was scanned already.
 *
 * ## Why this exists (S284)
 *
 * The media-asset queue — chapter thumbnails, the trickplay sprite sheet, the
 * Roku BIF — is a FILE queue ({@see MediaAssetJobStore}) whose only producer is
 * {@see \Phlix\Media\Library\MediaScanner::processFile()}. It is therefore
 * written at SCAN time and at no other moment. S275 established that the sprite
 * producer had failed 100 % of the time on every install (`tile=6:10` is not a
 * valid ffmpeg tile layout, so the filtergraph always died), which means **no
 * library anywhere holds a `sprite.jpg`, a `timeline.json` or a `thumbs.bif`**.
 * Fixing the producer only helps items processed after the fix; without a
 * re-enqueue path an existing library stays empty forever.
 *
 * ⚠ **This is deliberately NOT "rescan the library".** A full rescan is
 * expensive — a production music rescan ran 9 h 55 m (migration 084's header) —
 * and S153 recorded that a healing rescan creates MORE orphan container rows
 * than it clears. This class opens no media file, issues no write to
 * `media_items`, and starts no scan: it walks the rows that already exist and
 * writes queue files. The expensive part (ffmpeg) is then done by the existing
 * {@see MediaAssetWorker}, off the HTTP path, at its own bounded concurrency.
 *
 * ## Idempotency, and where each half of it lives
 *
 * Two independent guards, because they fail in different ways:
 *
 *  1. **`alreadyQueued`** — {@see MediaAssetJobStore::enqueue()} is keyed by item
 *     id and no-ops when the job file exists. This class asks
 *     {@see MediaAssetJobStore::isEnqueued()} first only so the outcome is
 *     COUNTABLE; the store's own guard is what makes it safe.
 *  2. **`alreadyComplete`** — an item whose sprite AND BIF are both already on
 *     disk is skipped outright, so a second pass over a finished library
 *     enqueues nothing at all rather than re-running ffmpeg over every file.
 *
 * ⚠ There is no `force` escape hatch, deliberately: the flag would have to
 * survive the round trip through a `library_scan_jobs` row and that table has no
 * per-job parameter column, so it could only ever be a flag nothing reads. Filed
 * as a follow-up. It costs nothing today — per S275 no install holds any
 * trickplay artefact, so guard 2 never fires on a first backfill.
 *
 * ## Resident-memory (Workerman) safety
 *
 * Rows are walked in pages of {@see self::PAGE_SIZE} and never accumulated, so a
 * 60k-item library costs one page of hydrated rows at a time. The only instance
 * state is the injected dependencies — no `static`, no request state. It runs in
 * {@see \Phlix\Media\Library\LibraryScanWorker}, never in an HTTP handler.
 *
 * @since 0.36.0 (S284)
 */
class MediaAssetBackfill
{
    /**
     * Rows fetched per page.
     *
     * Mirrors `LibraryManager::MAINTENANCE_PAGE_SIZE`, the page size the other
     * library-wide maintenance passes already use.
     */
    private const PAGE_SIZE = 500;

    /** @var LoggerInterface Logger instance. */
    private LoggerInterface $logger;

    /**
     * @param ItemRepository     $items  Source of the library's existing rows.
     * @param MediaAssetJobStore $store  The file queue being re-primed.
     * @param FfmpegRunner       $ffmpeg Consulted ONLY for
     *        {@see FfmpegRunner::getTranscodeDir()} — the artefact directory the
     *        producer writes to. No ffmpeg process is started here.
     * @param LoggerInterface|null $logger Optional; defaults to a NullLogger.
     */
    public function __construct(
        private readonly ItemRepository $items,
        private readonly MediaAssetJobStore $store,
        private readonly FfmpegRunner $ffmpeg,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Walk a library's items and enqueue a media-asset job for each eligible one.
     *
     * @param string        $libraryId  Library UUID.
     * @param callable|null $onProgress `(int $processed, int $total): void`, called
     *                                  once per row walked. Matches the shape the
     *                                  `clear_metadata` / `clear_artwork` jobs use,
     *                                  so `GET /api/v1/libraries/{id}/scan-status`
     *                                  renders its percentage unchanged.
     *
     * @return MediaAssetBackfillResult Disjoint per-outcome counters.
     */
    public function reenqueueLibrary(
        string $libraryId,
        ?callable $onProgress = null,
    ): MediaAssetBackfillResult {
        $total = $this->items->countByLibrary($libraryId);

        $scanned = 0;
        $enqueued = 0;
        $alreadyComplete = 0;
        $alreadyQueued = 0;
        $missingFile = 0;
        $ineligible = 0;

        $offset = 0;

        while (true) {
            $page = $this->items->getByLibrary($libraryId, self::PAGE_SIZE, $offset);
            if ($page === []) {
                break;
            }

            foreach ($page as $item) {
                $scanned++;

                $id = is_string($item['id'] ?? null) ? $item['id'] : '';
                $path = is_string($item['path'] ?? null) ? $item['path'] : '';

                if ($id === '' || $path === '' || !$this->isSupportedContainer($path)) {
                    // Container rows (series/seasons/artists/albums) have no path,
                    // and audio/images/books are not trickplay subjects.
                    $ineligible++;
                } elseif (!is_file($path)) {
                    // The row outlived its file. Enqueuing would guarantee a failed
                    // job; `prune` is the operation for this, not this one.
                    $missingFile++;
                } elseif ($this->hasArtefacts($id)) {
                    $alreadyComplete++;
                } elseif ($this->store->isEnqueued($id)) {
                    $alreadyQueued++;
                } else {
                    $this->store->enqueue(new MediaAssetJob($id, $path, $this->durationOf($item)));
                    $enqueued++;
                }

                if ($onProgress !== null) {
                    $onProgress($scanned, $total);
                }
            }

            if (count($page) < self::PAGE_SIZE) {
                break;
            }
            $offset += self::PAGE_SIZE;
        }

        $result = new MediaAssetBackfillResult(
            $scanned,
            $enqueued,
            $alreadyComplete,
            $alreadyQueued,
            $missingFile,
            $ineligible,
        );

        $this->logger->info('MediaAssetBackfill: library re-enqueued', array_merge(
            ['library_id' => $libraryId],
            $result->toArray(),
        ));

        return $result;
    }

    /**
     * Whether a path's container is one the media-asset pipeline can process.
     *
     * @param string $path Absolute media path.
     */
    private function isSupportedContainer(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, MediaAssetJob::SUPPORTED_EXTENSIONS, true);
    }

    /**
     * Whether an item's trickplay artefacts are ALL present already.
     *
     * Both the sprite and the BIF must exist. Requiring both (rather than either)
     * means a run interrupted between the two — which is exactly the state a
     * crashed worker leaves — is retried instead of being recorded as done.
     *
     * The directory is the PRODUCER's:
     * `FfmpegRunner::getTranscodeDir() . '/trickplay/' . $itemId`, matching
     * {@see MediaAssetGenerationJob::generateTrickplaySprites()}. Reading it from
     * the same source as the writer is the point — S275 found the serving
     * directory and the producing directory had drifted apart, and a backfill that
     * consulted the wrong one would report every item complete (or none).
     *
     * @param string $itemId Media item UUID.
     */
    private function hasArtefacts(string $itemId): bool
    {
        $dir = $this->ffmpeg->getTranscodeDir() . '/trickplay/' . $itemId;

        return is_file($dir . '/' . FfmpegRunner::SPRITE_FILENAME)
            && is_file($dir . '/' . BifWriter::FILENAME);
    }

    /**
     * Duration in whole seconds for a hydrated media item row.
     *
     * The scanner stores it as `metadata_json.duration_seconds`; `duration_ticks`
     * on the row is a playback-state column the scanner never writes. 0 means
     * "unknown", which {@see MediaAssetGenerationJob} handles by falling back to
     * its default frame count rather than failing.
     *
     * @param array<string, mixed> $item Hydrated `media_items` row.
     */
    private function durationOf(array $item): int
    {
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $duration = $metadata['duration_seconds'] ?? null;

        if (!is_numeric($duration)) {
            return 0;
        }

        return max(0, (int) $duration);
    }
}
