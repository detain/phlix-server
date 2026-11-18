<?php

/**
 * Phlix media server component: Media Asset.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\MediaAsset;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\MarkerService;
use Phlix\Media\MarkerType;
use Phlix\Media\Markers\ChapterMarkerService;
use Phlix\Media\Streaming\Trickplay\BifWriter;
use Phlix\Media\Streaming\Trickplay\TrickplayArtefacts;
use Phlix\Media\Transcoding\FfmpegRunner;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Workerman\MySQL\Connection;

/**
 * Executes chapter-thumbnail and trickplay sprite generation for a media item.
 *
 * This is the "job body" — the actual work previously done inline in
 * {@see \Phlix\Media\Library\MediaScanner::processFile()}. Extracted into a
 * separate class so it can run asynchronously in a background worker after
 * the scan completes.
 *
 * @since 0.36.0
 */
class MediaAssetGenerationJob
{
    /**
     * Chapters extracted per ffmpeg process.
     *
     * The batch opens the media file once per timestamp, so one process's input
     * count, open file handles and memory grow with the batch. 20 keeps a
     * 200-chapter title to ten processes without an unbounded command line.
     */
    private const CHAPTER_THUMB_BATCH_SIZE = 20;

    /**
     * File name of the BIF archive inside a media item's trickplay directory.
     *
     * Aliased from {@see BifWriter::FILENAME}, which
     * {@see \Phlix\Media\Streaming\Trickplay\TrickplayController} also reads, so
     * the written name and the served name are one string.
     */
    public const BIF_FILENAME = BifWriter::FILENAME;

    /** @var LoggerInterface Logger instance */
    private LoggerInterface $logger;

    /**
     * @param FfmpegRunner    $ffmpeg       FFmpeg runner for thumbnail/sprite generation
     * @param ItemRepository  $itemRepo     Item repository for marker updates
     * @param Connection      $db           Database connection for MarkerService
     * @param LoggerInterface|null $logger   Optional logger; defaults to NullLogger
     */
    /**
     * @param \Phlix\Admin\SettingsRepository|null $settings When supplied, the
     *        `trickplay.enabled` admin setting gates sprite generation. Null
     *        (tests, or a container that cannot supply one) means enabled, which
     *        preserves the historical behaviour.
     */
    public function __construct(
        private readonly FfmpegRunner $ffmpeg,
        private readonly ItemRepository $itemRepo,
        private readonly Connection $db,
        ?LoggerInterface $logger = null,
        private readonly ?\Phlix\Admin\SettingsRepository $settings = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Whether trickplay sprite generation is enabled.
     *
     * Reads the effective value at use-time, so a change applies on the next job
     * with no restart. Degrades to enabled on any settings-store failure — a
     * transient DB problem must not silently stop asset generation.
     */
    private function trickplayEnabled(): bool
    {
        if ($this->settings === null) {
            return true;
        }

        try {
            return $this->settings->getEffective('trickplay.enabled') !== false;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Execute the generation job for a single media item.
     *
     * This method is best-effort — individual failures (missing codec support,
     * corrupt file, etc.) are caught and logged but never propagate. A failure
     * on one item must not prevent other items from being processed.
     *
     * @param MediaAssetJob $job The job to process
     *
     * @return bool True if both chapter thumbnails and trickplay sprites succeeded
     */
    public function process(MediaAssetJob $job): bool
    {
        $this->logger->info('MediaAssetGenerationJob: processing item', [
            'item_id' => $job->itemId,
            'path' => $job->path,
        ]);

        $chapterOk = $this->generateChapterThumbnails($job);
        $trickplayOk = $this->generateTrickplaySprites($job);

        $this->logger->info('MediaAssetGenerationJob: item complete', [
            'item_id' => $job->itemId,
            'chapters_ok' => $chapterOk,
            'trickplay_ok' => $trickplayOk,
        ]);

        return $chapterOk && $trickplayOk;
    }

    /**
     * Reads the chapter list from the container (ffprobe `-show_chapters`).
     *
     * A seam, so the batch path can be exercised without a media file. The
     * {@see ChapterMarkerService} this job uses is the Media one
     * (`Phlix\Media\Markers`), not the LiveTv recording one.
     *
     * @return array<int, \Phlix\Media\Markers\ChapterMarker>
     */
    protected function extractChapters(string $path): array
    {
        return (new ChapterMarkerService($this->ffmpeg))->extractFromFile($path);
    }

    /**
     * Generate per-chapter thumbnail images and store chapter markers.
     *
     * @param MediaAssetJob $job
     *
     * @return bool True if at least one chapter thumbnail was generated
     */
    private function generateChapterThumbnails(MediaAssetJob $job): bool
    {
        $path = $job->path;
        $itemId = $job->itemId;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Only containers that support chapters. Shares its list with the two
        // ENQUEUE gates (the scanner and S284's backfill) via MediaAssetJob, so a
        // producer can never queue an item this method would silently refuse.
        if (!in_array($ext, MediaAssetJob::SUPPORTED_EXTENSIONS, true)) {
            return false;
        }

        try {
            $chapters = $this->extractChapters($path);

            if (empty($chapters)) {
                return false;
            }

            // Create chapter output directory
            $chapterDir = $this->ffmpeg->getTranscodeDir() . '/chapters/' . $itemId;
            if (!is_dir($chapterDir)) {
                mkdir($chapterDir, 0755, true);
            }

            $mediaMarkerService = new MarkerService($this->db);
            $anySuccess = false;

            // One ffmpeg process per batch of chapters, not one per chapter. Each
            // process pays its own start-up, probe and open cost, which dominated a
            // long chapter list. Batch frames are renamed to their chapter index.
            foreach (array_chunk($chapters, self::CHAPTER_THUMB_BATCH_SIZE, true) as $batch) {
                $timestamps = [];
                foreach ($batch as $chapter) {
                    $timestamps[] = $chapter->start_seconds;
                }

                for ($position = 0; $position < count($batch); $position++) {
                    @unlink($chapterDir . '/' . FfmpegRunner::batchFrameFileName($position));
                }
                $this->ffmpeg->generateThumbnailBatch($path, $timestamps, $chapterDir);

                // Judge each chapter by the frame file it produced, not by the
                // batch's exit code: a batch with one unreachable timestamp still
                // writes the others, and the exit code does not say which.
                $position = 0;
                foreach ($batch as $index => $chapter) {
                    $frame = $chapterDir . '/' . FfmpegRunner::batchFrameFileName($position++);
                    $thumbPath = $chapterDir . '/' . $index . '.jpg';
                    $startSeconds = $chapter->start_seconds;

                    if (!is_file($frame) || filesize($frame) === 0 || !rename($frame, $thumbPath)) {
                        @unlink($frame);
                        continue;
                    }

                    $mediaMarkerService->upsert(
                        $itemId,
                        MarkerType::Chapter,
                        (int) ($startSeconds * 1000),
                        (int) ($chapter->end_seconds * 1000),
                        $chapter->title ?? ('Chapter ' . ($index + 1)),
                        null,
                        $thumbPath
                    );
                    $anySuccess = true;
                }
            }

            return $anySuccess;
        } catch (\Throwable $e) {
            $this->logger->warning('MediaAssetGenerationJob: chapter thumbnail generation failed', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Generate trickplay sprite sheet (60-thumbnail grid at 160x90).
     *
     * @param MediaAssetJob $job
     *
     * @return bool True if sprite sheet was generated and paths persisted
     */
    private function generateTrickplaySprites(MediaAssetJob $job): bool
    {
        $path = $job->path;
        $itemId = $job->itemId;

        // Honour the `trickplay.enabled` admin setting.
        //
        // This is the ONLY trickplay implementation. There used to be a second,
        // older one (TrickplayGenerator + TrickplayConfig, nominally driven by
        // config/trickplay.php's interval_seconds/grid_*/thumb_* keys) whose only
        // entry point was StreamManager::generateTrickplay(), which threw unless
        // StreamManager::setTrickplay() had been called — and setTrickplay() had
        // no callers anywhere in the tree. S275 confirmed that at runtime (pcov
        // over a full media-asset run never even autoloaded the class) and
        // deleted it, so this comment can no longer go stale against it.
        //
        // `trickplay.enabled` was therefore inert — an operator on production had
        // set it to 0 and sprites kept being generated. Gating here, on the path
        // that actually runs, is what makes that toggle real. Read at use-time via
        // getEffective(), so it applies immediately with no restart.
        if (!$this->trickplayEnabled()) {
            $this->logger->debug('MediaAssetGenerationJob: trickplay disabled by setting', [
                'item_id' => $itemId,
            ]);

            // Disabled is a successful no-op, not a failure: process() ANDs the
            // two results, and returning false here would mark every job failed.
            return true;
        }

        $lock = null;
        try {
            $spriteDir = $this->ffmpeg->getTranscodeDir() . '/trickplay/' . $itemId;
            if (!is_dir($spriteDir)) {
                @mkdir($spriteDir, 0755, true);
            }

            // One pass per item at a time: the backfill script writes the same
            // directory. Skip without waiting if it holds the lock; its pass produces
            // the same artefacts.
            $lock = TrickplayArtefacts::tryLock($spriteDir);
            if ($lock === null) {
                $this->logger->info('MediaAssetGenerationJob: trickplay skipped, another pass holds the item', [
                    'item_id' => $itemId,
                ]);

                return true;
            }

            // ONE ffmpeg pass produces the sprite, its timeline and the BIF frames.
            // The sprite and the BIF used to be two passes, each decoding the whole
            // file. See FfmpegRunner::generateTrickplayArtefacts().
            $result = $this->ffmpeg->generateTrickplayArtefacts(
                $path,
                $spriteDir,
                TrickplayArtefacts::SPRITE_COUNT,
                TrickplayArtefacts::bifFrameCount($job->duration),
                TrickplayArtefacts::BIF_FRAME_WIDTH,
                ['item_id' => $itemId]
            );

            if ($result === null || $result['sprite'] === null || $result['timeline'] === null) {
                return false;
            }

            $frames = $result['bif_frames'];
            try {
                $this->itemRepo->updateMarkers($itemId, [
                    'trickplay_sprite_path' => $result['sprite'],
                    'trickplay_timeline_path' => $result['timeline'],
                ]);

                // Best-effort, and deliberately NOT folded into this method's return
                // value: the sprite sheet is what the web player needs, and losing
                // the BIF must not mark the whole asset job failed.
                $this->writeTrickplayBif($job, $frames, $result['bif_interval_ms'], $spriteDir);
            } finally {
                // The per-frame JPEGs are an intermediate; only the archive is served.
                foreach ($frames as $frame) {
                    @unlink($frame);
                }
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('MediaAssetGenerationJob: trickplay generation failed', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            TrickplayArtefacts::unlock($lock);
        }
    }

    /**
     * Write the Roku BIF archive next to the sprite sheet from frames the
     * combined pass already extracted.
     *
     * Runs here, in the background media-asset worker, rather than on demand:
     * the HTTP side is a resident Workerman event loop, and shelling ffmpeg
     * across a whole video the first time somebody scrubs would stall every
     * other connection in the worker for the length of a full decode pass.
     * Pre-generating means the first scrub costs one static file read.
     *
     * The caller owns the frame files and deletes them; this method only
     * builds the archive from them.
     *
     * @param MediaAssetJob  $job        The job being processed.
     * @param list<string>   $frames     BIF frame JPEGs in capture order (empty when the BIF branch failed).
     * @param int            $intervalMs Milliseconds between consecutive frames.
     * @param string         $spriteDir  The item's trickplay directory.
     *
     * @return bool True if `thumbs.bif` was written.
     */
    private function writeTrickplayBif(MediaAssetJob $job, array $frames, int $intervalMs, string $spriteDir): bool
    {
        try {
            if (!TrickplayArtefacts::writeBif($frames, $intervalMs, $spriteDir)) {
                return false;
            }

            $this->logger->info('MediaAssetGenerationJob: BIF written', [
                'item_id' => $job->itemId,
                'frames' => count($frames),
                'interval_ms' => $intervalMs,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('MediaAssetGenerationJob: BIF generation failed', [
                'item_id' => $job->itemId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
