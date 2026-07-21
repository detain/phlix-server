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
    /** Default number of trickplay sprites (60 = 10x6 grid at 160x90) */
    private const DEFAULT_TRICKPLAY_COUNT = 60;

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
        // This is the LIVE trickplay implementation. There is a second, older one
        // (TrickplayGenerator + TrickplayConfig, driven by config/trickplay.php's
        // interval_seconds/grid_*/thumb_* keys) which is dead code: its only entry
        // point is StreamManager::generateTrickplay(), which throws unless
        // StreamManager::setTrickplay() has been called, and setTrickplay() has no
        // callers anywhere in the tree.
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

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('MediaAssetGenerationJob: trickplay generation failed', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
