<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Recording;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Manages the lifecycle of Comskip processing for completed recordings.
 *
 * Maintains an in-memory queue of pending recording IDs and processes
 * them respecting the max_concurrent configuration limit.
 *
 * @since 0.12.0
 */
class ComskipLifecycleManager
{
    /** @var ComskipIntegration Comskip integration service */
    private ComskipIntegration $integration;

    /** @var \Workerman\MySQL\Connection Database connection */
    private $db;

    /** @var LoggerInterface PSR logger */
    private LoggerInterface $logger;

    /** @var bool Whether queue processing is enabled */
    private bool $queueProcessingEnabled;

    /** @var int Maximum number of concurrent Comskip processes */
    private int $maxConcurrent;

    /** @var array<string> Queue of pending recording IDs */
    private array $pendingQueue = [];

    /** @var int Currently running process count */
    private int $runningCount = 0;

    /**
     * Create a new ComskipLifecycleManager.
     *
     * @param ComskipIntegration $integration Comskip integration service
     * @param \Workerman\MySQL\Connection $db Database connection
     * @param LoggerInterface|null $logger Optional PSR logger, defaults to NullLogger
     * @param bool $queueProcessingEnabled Whether to use queue processing (default: true)
     * @param int $maxConcurrent Maximum concurrent processes (default: 2)
     *
     * @since 0.12.0
     */
    public function __construct(
        ComskipIntegration $integration,
        $db,
        ?LoggerInterface $logger = null,
        bool $queueProcessingEnabled = true,
        int $maxConcurrent = 2
    ) {
        $this->integration = $integration;
        $this->db = $db;
        $this->logger = $logger ?? new NullLogger();
        $this->queueProcessingEnabled = $queueProcessingEnabled;
        $this->maxConcurrent = $maxConcurrent;
    }

    /**
     * Enqueue a completed recording for Comskip processing.
     *
     * If queue processing is disabled, processes immediately.
     * Otherwise, adds to the internal queue for async processing.
     *
     * @param string $recordingId The recording identifier
     * @param string $filePath Absolute path to the recorded video file
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function enqueue(string $recordingId, string $filePath): void
    {
        // Check if already processed
        if ($this->isAlreadyProcessed($recordingId)) {
            $this->logger->debug('Recording already processed, skipping', [
                'recording_id' => $recordingId,
            ]);
            return;
        }

        if (!$this->queueProcessingEnabled) {
            // Process immediately if queue processing is disabled
            $this->processRecordingSync($recordingId, $filePath);
            return;
        }

        // Add to queue
        $this->pendingQueue[] = $recordingId;

        $this->logger->info('Recording enqueued for Comskip processing', [
            'recording_id' => $recordingId,
            'queue_size' => count($this->pendingQueue),
        ]);

        // Try to process if under concurrent limit
        $this->processNext();
    }

    /**
     * Process the next queued recording.
     *
     * Pops the next recording from the queue and processes it
     * if the concurrent limit hasn't been reached.
     *
     * @return bool True if a recording was processed, false if queue is empty or limit reached
     *
     * @since 0.12.0
     */
    public function processNext(): bool
    {
        // Check concurrent limit
        if ($this->runningCount >= $this->maxConcurrent) {
            $this->logger->debug('Max concurrent limit reached', [
                'running' => $this->runningCount,
                'max' => $this->maxConcurrent,
            ]);
            return false;
        }

        // Get next recording from queue
        if (empty($this->pendingQueue)) {
            return false;
        }

        $recordingId = array_shift($this->pendingQueue);
        $recording = $this->getRecordingData($recordingId);

        if ($recording === null) {
            $this->logger->warning('Recording not found, skipping', [
                'recording_id' => $recordingId,
            ]);
            return $this->processNext();
        }

        /** @var mixed $filePath */
        $filePath = $recording['storage_path'] ?? null;

        if ($filePath === null || !is_string($filePath)) {
            $this->logger->warning('Recording has no storage path, skipping', [
                'recording_id' => $recordingId,
            ]);
            return $this->processNext();
        }

        $this->runningCount++;

        try {
            $this->processRecordingSync($recordingId, $filePath);
        } finally {
            $this->runningCount--;
        }

        return true;
    }

    /**
     * Get the count of pending recordings in the queue.
     *
     * @return int Number of recordings waiting to be processed
     *
     * @since 0.12.0
     */
    public function getPendingCount(): int
    {
        return count($this->pendingQueue);
    }

    /**
     * Get the current number of running processes.
     *
     * @return int Number of currently running processes
     *
     * @since 0.12.0
     */
    public function getRunningCount(): int
    {
        return $this->runningCount;
    }

    /**
     * Process a recording synchronously.
     *
     * @param string $recordingId The recording identifier
     * @param string $filePath Path to the recording file
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function processRecordingSync(string $recordingId, string $filePath): void
    {
        $this->logger->info('Processing recording with Comskip', [
            'recording_id' => $recordingId,
            'file_path' => $filePath,
        ]);

        try {
            $this->integration->processRecording($recordingId, $filePath);

            $this->logger->info('Recording processed successfully', [
                'recording_id' => $recordingId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to process recording', [
                'recording_id' => $recordingId,
                'error' => $e->getMessage(),
            ]);
            // Don't rethrow - processing failure should not affect the recording status
        }
    }

    /**
     * Check if a recording has already been processed.
     *
     * @param string $recordingId The recording identifier
     *
     * @return bool True if already processed
     *
     * @since 0.12.0
     */
    private function isAlreadyProcessed(string $recordingId): bool
    {
        $result = $this->db->query(
            "SELECT commercial_processed_at FROM livetv_recordings
             WHERE recording_id = ? AND commercial_processed_at IS NOT NULL",
            [$recordingId]
        );

        return !empty($result);
    }

    /**
     * Get recording data from the database.
     *
     * @param string $recordingId The recording identifier
     *
     * @return array<string, mixed>|null The recording row or null
     *
     * @since 0.12.0
     */
    private function getRecordingData(string $recordingId): ?array
    {
        /** @var mixed $result */
        $result = $this->db->query(
            "SELECT recording_id, storage_path, commercial_processed_at
             FROM livetv_recordings WHERE recording_id = ?",
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
