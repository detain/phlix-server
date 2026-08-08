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
use Phlix\Media\MarkerService;
use Phlix\Media\MarkerType;
use Phlix\Media\Markers\ChapterMarkerService;
use Phlix\Media\Streaming\Trickplay\BifWriter;
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
     * Default number of trickplay sprites.
     *
     * 60 thumbnails at 160x90 laid out by {@see FfmpegRunner} as **6 columns by
     * 10 rows** — the `10x6` this comment used to claim was the transpose, and
     * `config/trickplay.php`'s `grid_columns: 8` / `grid_rows: 4` belonged to the
     * deleted second implementation and never described anything that ran.
     */
    private const DEFAULT_TRICKPLAY_COUNT = 60;

    /** Seconds between BIF frames that the frame count is derived from. */
    private const BIF_TARGET_INTERVAL_SECONDS = 10;

    /**
     * Hard cap on BIF frames.
     *
     * The archive embeds every frame, so its size is linear in this number and
     * it is downloaded whole by the device before scrubbing works. 600 frames at
     * ~12 KB is roughly 7 MB; past a 100-minute runtime the effective interval
     * stretches rather than the file growing without bound.
     */
    private const BIF_MAX_FRAMES = 600;

    /** BIF frame width in pixels — Roku's recommended HD trickplay width. */
    private const BIF_FRAME_WIDTH = 320;

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

        // Only containers that support chapters
        if (!in_array($ext, ['mkv', 'mp4', 'webm'], true)) {
            return false;
        }

        try {
            $chapterService = new ChapterMarkerService($this->ffmpeg);
            $chapters = $chapterService->extractFromFile($path);

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

            foreach ($chapters as $index => $chapter) {
                $thumbPath = $chapterDir . '/' . $index . '.jpg';
                $startSeconds = $chapter->start_seconds;

                $success = $this->ffmpeg->generateThumbnail($path, $thumbPath, $startSeconds);
                if ($success) {
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

        try {
            $spriteDir = $this->ffmpeg->getTranscodeDir() . '/trickplay/' . $itemId;
            if (!is_dir($spriteDir)) {
                @mkdir($spriteDir, 0755, true);
            }

            $result = $this->ffmpeg->generateTrickplaySprites($path, $spriteDir, self::DEFAULT_TRICKPLAY_COUNT);

            if ($result === null) {
                return false;
            }

            [$spritePath, $timelinePath] = $result;
            $this->itemRepo->updateMarkers($itemId, [
                'trickplay_sprite_path' => $spritePath,
                'trickplay_timeline_path' => $timelinePath,
            ]);

            // Best-effort, and deliberately NOT folded into this method's return
            // value: the sprite sheet is what the web player needs, and losing
            // the BIF must not mark the whole asset job failed.
            $this->generateTrickplayBif($job, $spriteDir);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('MediaAssetGenerationJob: trickplay generation failed', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Write the Roku BIF archive next to the sprite sheet.
     *
     * Runs here, in the background media-asset worker, rather than on demand:
     * the HTTP side is a resident Workerman event loop, and shelling ffmpeg
     * across a whole video the first time somebody scrubs would stall every
     * other connection in the worker for the length of a full decode pass.
     * Pre-generating means the first scrub costs one static file read.
     *
     * @param MediaAssetJob $job       The job being processed.
     * @param string        $spriteDir The item's trickplay directory.
     *
     * @return bool True if `thumbs.bif` was written.
     */
    private function generateTrickplayBif(MediaAssetJob $job, string $spriteDir): bool
    {
        $frames = [];

        try {
            $count = self::DEFAULT_TRICKPLAY_COUNT;
            if ($job->duration > 0) {
                $count = (int) ceil($job->duration / self::BIF_TARGET_INTERVAL_SECONDS);
                $count = max(2, min(self::BIF_MAX_FRAMES, $count));
            }

            $extracted = $this->ffmpeg->generateBifFrames(
                $job->path,
                $spriteDir,
                $count,
                self::BIF_FRAME_WIDTH
            );

            if ($extracted === null) {
                return false;
            }

            [$frames, $intervalMs] = $extracted;

            BifWriter::writeFromFiles($frames, $intervalMs, $spriteDir . '/' . self::BIF_FILENAME);

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
        } finally {
            // The per-frame JPEGs are an intermediate; only the archive is served.
            foreach ($frames as $frame) {
                @unlink($frame);
            }
        }
    }
}
