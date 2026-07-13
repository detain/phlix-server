<?php

/**
 * Phlix media server component: Recording.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\LiveTv\Recording;

use Phlix\LiveTv\ComskipEdlParser;
use Phlix\LiveTv\ComskipRunner;
use Phlix\Media\Markers\ChapterMarker;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Workerman\MySQL\Connection;

/**
 * Wires Comskip commercial detection into the live-TV recording lifecycle.
 *
 * After a recording completes, this class runs Comskip on the file,
 * parses the resulting EDL, and persists commercial data to the DB.
 *
 * @since 0.12.0
 */
class ComskipIntegration
{
    /** @var ComskipRunner Comskip binary runner */
    private ComskipRunner $runner;

    /** @var ComskipEdlParser EDL file parser */
    private ComskipEdlParser $parser;

    /** @var Connection Database connection */
    private Connection $db;

    /** @var LoggerInterface PSR logger */
    private LoggerInterface $logger;

    /**
     * @var ChapterMarkerService|null Attaches the parsed EDL segments to the
     *      recording's REAL media item (SV-3.1d-comskip). Null in legacy /
     *      stats-only construction — then no chapter markers are attached.
     */
    private ?ChapterMarkerService $chapterService;

    /**
     * Create a new ComskipIntegration.
     *
     * @param ComskipRunner $runner Comskip binary runner
     * @param ComskipEdlParser $parser EDL file parser
     * @param Connection $db Database connection
     * @param LoggerInterface|null $logger Optional PSR logger, defaults to NullLogger
     * @param ChapterMarkerService|null $chapterService Optional service that persists
     *        the parsed commercial segments as chapter markers on the recording's
     *        registered media item (SV-3.1d-comskip). When null, only the recording
     *        row's commercial stats are stored (the historic behaviour).
     *
     * @since 0.12.0
     */
    public function __construct(
        ComskipRunner $runner,
        ComskipEdlParser $parser,
        Connection $db,
        ?LoggerInterface $logger = null,
        ?ChapterMarkerService $chapterService = null
    ) {
        $this->runner = $runner;
        $this->parser = $parser;
        $this->db = $db;
        $this->logger = $logger ?? new NullLogger();
        $this->chapterService = $chapterService;
    }

    /**
     * Run Comskip on a completed recording file.
     *
     * Executes Comskip on the recording, parses the EDL output,
     * and stores the commercial processing results in the database.
     *
     * @param string $recordingId The recording identifier
     * @param string $filePath Absolute path to the recorded video file
     *
     * @return array{edl_path: string, frame_count: int, duration_seconds: int, segments: int}
     *
     * @throws \RuntimeException If Comskip is not available or processing fails
     *
     * @since 0.12.0
     */
    public function processRecording(string $recordingId, string $filePath): array
    {
        // Guard: check if Comskip is available
        if (!$this->runner->isAvailable()) {
            throw new \RuntimeException(
                'Comskip is not available on this system'
            );
        }

        // Guard: check if recording file exists
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Recording file not found: {$filePath}");
        }

        // When wired to attach chapter markers (production, SV-3.1d-comskip), the
        // recording MUST already be linked to a registered media item — that is
        // what the markers attach to, and an unregistered (failed / empty) capture
        // is not a playable library entry. Skip WITHOUT spending a comskip run when
        // there is nothing to attach to.
        $mediaItemId = null;
        if ($this->chapterService !== null) {
            $mediaItemId = $this->resolveMediaItemId($recordingId);
            if ($mediaItemId === null) {
                $this->logger->info('Recording has no linked media item; skipping Comskip', [
                    'recording_id' => $recordingId,
                ]);

                return [
                    'edl_path' => '',
                    'frame_count' => 0,
                    'duration_seconds' => 0,
                    'segments' => 0,
                ];
            }
        }

        $this->logger->info('Processing recording with Comskip', [
            'recording_id' => $recordingId,
            'file_path' => $filePath,
            'media_item_id' => $mediaItemId,
        ]);

        try {
            // Run Comskip and get EDL path
            $edlPath = $this->runner->run($filePath);

            // Parse EDL to chapter markers
            $chapters = $this->parser->parse($edlPath);

            // Calculate commercial stats
            $frameCount = count($chapters);
            $totalDuration = 0;
            foreach ($chapters as $chapter) {
                $totalDuration += ($chapter->end_seconds - $chapter->start_seconds);
            }

            // Store results in database
            $this->storeCommercialData(
                $recordingId,
                $edlPath,
                $frameCount,
                $totalDuration
            );

            // Attach the parsed commercial segments as chapter markers on the
            // recording's REAL media item (SV-3.1d-comskip). persistChapters
            // overwrites metadata_json.commercial_chapters, so a re-run is
            // idempotent (belt to the ComskipLifecycleManager already-processed
            // guard).
            if ($this->chapterService !== null && $mediaItemId !== null) {
                $this->chapterService->persistChapters($mediaItemId, $chapters);

                $this->logger->info('Attached commercial chapter markers to media item', [
                    'recording_id' => $recordingId,
                    'media_item_id' => $mediaItemId,
                    'chapter_count' => $frameCount,
                ]);
            }

            $this->logger->info('Comskip processing completed', [
                'recording_id' => $recordingId,
                'edl_path' => $edlPath,
                'frame_count' => $frameCount,
                'duration_seconds' => $totalDuration,
            ]);

            return [
                'edl_path' => $edlPath,
                'frame_count' => $frameCount,
                'duration_seconds' => $totalDuration,
                'segments' => count($chapters),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Comskip processing failed', [
                'recording_id' => $recordingId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get parsed EDL segments for a recording.
     *
     * Returns the commercial segments stored in the database for
     * the given recording.
     *
     * @param string $recordingId The recording identifier
     *
     * @return ChapterMarker[] Array of chapter markers derived from commercial segments
     *
     * @since 0.12.0
     */
    public function getEdlSegments(string $recordingId): array
    {
        $recording = $this->getRecording($recordingId);

        if ($recording === null) {
            return [];
        }

        $edlPath = $recording['commercial_edl_path'] ?? null;

        if ($edlPath === null || !is_string($edlPath) || !file_exists($edlPath)) {
            return [];
        }

        try {
            /** @var string $edlPath */
            return $this->parser->parse($edlPath);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to parse EDL file', [
                'recording_id' => $recordingId,
                'edl_path' => $edlPath,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Mark a recording's commercial processing as complete.
     *
     * Sets the commercial_processed_at timestamp for a recording.
     *
     * @param string $recordingId The recording identifier
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function markProcessed(string $recordingId): void
    {
        $this->db->query(
            "UPDATE livetv_recordings
             SET commercial_processed_at = NOW()
             WHERE recording_id = ?",
            [$recordingId]
        );

        $this->logger->debug('Recording marked as commercial processed', [
            'recording_id' => $recordingId,
        ]);
    }

    /**
     * Store commercial processing data in the database.
     *
     * @param string $recordingId The recording identifier
     * @param string $edlPath Path to the generated EDL file
     * @param int $frameCount Number of commercial frames detected
     * @param int $durationSeconds Total duration of commercials in seconds
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function storeCommercialData(
        string $recordingId,
        string $edlPath,
        int $frameCount,
        int $durationSeconds
    ): void {
        $this->db->query(
            "UPDATE livetv_recordings
             SET commercial_edl_path = ?,
                 commercial_frame_count = ?,
                 commercial_duration_seconds = ?,
                 commercial_processed_at = NOW()
             WHERE recording_id = ?",
            [$edlPath, $frameCount, $durationSeconds, $recordingId]
        );
    }

    /**
     * Get a recording by ID from the database.
     *
     * @param string $recordingId The recording identifier
     *
     * @return array<string, mixed>|null The recording row or null if not found
     *
     * @since 0.12.0
     */
    /**
     * Resolve the media item a completed recording is linked to.
     *
     * The linkage is written by {@see RecordingMediaRegistrar} (a Recorder
     * onComplete hook that runs BEFORE the deferred comskip drain), so by the
     * time comskip processing runs the column is populated for any genuinely
     * registered recording.
     *
     * @param string $recordingId The recording identifier
     *
     * @return string|null The linked `media_items.id`, or null when unlinked.
     *
     * @since SV-3.1d-comskip
     */
    private function resolveMediaItemId(string $recordingId): ?string
    {
        /** @var mixed $result */
        $result = $this->db->query(
            "SELECT media_item_id FROM livetv_recordings WHERE recording_id = ?",
            [$recordingId]
        );

        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            return null;
        }

        /** @var mixed $id */
        $id = $result[0]['media_item_id'] ?? null;

        return (is_string($id) && $id !== '') ? $id : null;
    }

    /**
     * Get a recording by ID from the database.
     *
     * @param string $recordingId The recording identifier
     *
     * @return array<string, mixed>|null The recording row or null if not found
     *
     * @since 0.12.0
     */
    private function getRecording(string $recordingId): ?array
    {
        /** @var mixed $result */
        $result = $this->db->query(
            "SELECT * FROM livetv_recordings WHERE recording_id = ?",
            [$recordingId]
        );

        if (!is_array($result) || empty($result)) {
            return null;
        }

        /** @var mixed $firstRow */
        $firstRow = $result[0];
        if (!is_array($firstRow)) {
            return null;
        }

        /** @var array<string, mixed> $firstRow */
        return $firstRow;
    }
}
