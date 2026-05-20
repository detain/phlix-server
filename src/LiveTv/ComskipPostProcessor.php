<?php

declare(strict_types=1);

namespace Phlix\LiveTv;

use Phlix\Media\Markers\ChapterMarker;
use Phlix\Media\Markers\MarkerService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Orchestrates comskip post-processing for completed Live TV recordings.
 *
 * After a recording completes, this processor checks if comskip is available,
 * runs it on the recording file, parses the resulting EDL, and stores the
 * commercial chapters in the media item's chapters_json column.
 *
 * Processing is idempotent — if chapters already exist for a recording,
 * calling processRecording() again is a no-op.
 *
 * @since 0.12.0
 */
class ComskipPostProcessor
{
    /** @var ComskipRunner Comskip binary runner */
    private ComskipRunner $comskip;

    /** @var ComskipEdlParser EDL file parser */
    private ComskipEdlParser $edlParser;

    /** @var MarkerService Marker storage service */
    private MarkerService $markerService;

    /** @var LoggerInterface PSR logger */
    private LoggerInterface $logger;

    /**
     * Create a new ComskipPostProcessor.
     *
     * @param ComskipRunner $comskip Comskip binary runner
     * @param ComskipEdlParser $edlParser EDL file parser
     * @param MarkerService $markerService Service for storing chapter markers
     * @param LoggerInterface|null $logger Optional PSR logger, defaults to NullLogger
     *
     * @since 0.12.0
     */
    public function __construct(
        ComskipRunner $comskip,
        ComskipEdlParser $edlParser,
        MarkerService $markerService,
        ?LoggerInterface $logger = null
    ) {
        $this->comskip = $comskip;
        $this->edlParser = $edlParser;
        $this->markerService = $markerService;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Process a completed recording: run comskip, parse EDL, store chapters.
     *
     * This method is idempotent — if chapters already exist for the media item,
     * the method returns early without re-processing.
     *
     * @param string $mediaItemId The media item ID to store chapters for
     * @param string $recordingPath Absolute path to the recorded video file
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function processRecording(string $mediaItemId, string $recordingPath): void
    {
        // Guard: check if already processed
        if ($this->isProcessed($mediaItemId)) {
            $this->logger->debug('Recording already processed, skipping', [
                'media_item_id' => $mediaItemId,
            ]);
            return;
        }

        // Guard: check if comskip is available
        if (!$this->comskip->isAvailable()) {
            $this->logger->info('Comskip not available, skipping processing', [
                'media_item_id' => $mediaItemId,
            ]);
            return;
        }

        $this->logger->info('Processing recording with comskip', [
            'media_item_id' => $mediaItemId,
            'recording_path' => $recordingPath,
        ]);

        try {
            // Run comskip and get EDL path
            $edlPath = $this->comskip->run($recordingPath);

            // Parse EDL to chapter markers
            $chapters = $this->edlParser->parse($edlPath);

            if (empty($chapters)) {
                $this->logger->info('No commercial chapters found in EDL', [
                    'media_item_id' => $mediaItemId,
                    'edl_path' => $edlPath,
                ]);
                return;
            }

            // Store chapters via MarkerService
            $this->storeChapters($mediaItemId, $chapters);

            $this->logger->info('Stored commercial chapters for recording', [
                'media_item_id' => $mediaItemId,
                'chapter_count' => count($chapters),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to process recording with comskip', [
                'media_item_id' => $mediaItemId,
                'error' => $e->getMessage(),
            ]);
            // Don't rethrow — processing failure should not affect the recording status
        }
    }

    /**
     * Check if a recording has already been processed.
     *
     * A recording is considered processed if it already has chapters stored
     * via the MarkerService.
     *
     * @param string $mediaItemId The media item ID to check
     *
     * @return bool True if chapters already exist for this item
     *
     * @since 0.12.0
     */
    public function isProcessed(string $mediaItemId): bool
    {
        $markerSet = $this->markerService->getMarkers($mediaItemId);

        return !empty($markerSet->chapters);
    }

    /**
     * Store chapter markers for a media item.
     *
     * @param string $mediaItemId The media item ID
     * @param ChapterMarker[] $chapters Array of chapter markers
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function storeChapters(string $mediaItemId, array $chapters): void
    {
        $this->markerService->storeChapters($mediaItemId, $chapters);
    }
}
