<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Recording;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\StructuredLogger;

/**
 * Runs post-recording hooks asynchronously after a recording completes.
 *
 * Enqueues post-processing tasks (such as Comskip analysis) to be run
 * in the background after the recording file is finalized.
 *
 * @since 0.12.0
 */
class RecordingHooksRunner
{
    /** @var string Base path for recording storage */
    private string $storagePath;

    /** @var StructuredLogger Structured logger instance */
    private StructuredLogger $logger;

    /** @var array<string, array{recording_id: string, file_path: string, enqueued_at: int}> Pending hooks */
    private array $pendingHooks = [];

    /**
     * Creates a new RecordingHooksRunner instance.
     *
     * @param string $storagePath Base path for recording files
     * @param StructuredLogger|null $logger Optional logger, defaults to Livetv channel
     *
     * @since 0.12.0
     */
    public function __construct(
        string $storagePath = '/var/recordings',
        ?StructuredLogger $logger = null
    ) {
        $this->storagePath = $storagePath;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::LIVETV);
    }

    /**
     * Enqueue post-recording processing for a completed recording.
     *
     * Adds the recording to the pending hooks queue for async processing.
     * The actual processing is done by calling processPendingHooks().
     *
     * @param string $recordingId The recording identifier
     * @param string $filePath Absolute path to the recording file
     * @return void
     *
     * @since 0.12.0
     */
    public function enqueue(string $recordingId, string $filePath): void
    {
        $this->pendingHooks[$recordingId] = [
            'recording_id' => $recordingId,
            'file_path' => $filePath,
            'enqueued_at' => time(),
        ];

        $this->logger->debug('Post-recording hook enqueued', [
            'recording_id' => $recordingId,
            'file_path' => $filePath,
        ]);
    }

    /**
     * Process all pending post-recording hooks.
     *
     * Should be called periodically (e.g., via Workerman timer) to
     * process queued hooks. Each hook is processed once and removed
     * from the queue.
     *
     * @param callable $processor Callback to process each hook (recordingId, filePath) => void
     * @return int Number of hooks processed
     *
     * @since 0.12.0
     */
    public function processPendingHooks(callable $processor): int
    {
        $processed = 0;

        foreach ($this->pendingHooks as $recordingId => $hook) {
            try {
                $processor($hook['recording_id'], $hook['file_path']);
                unset($this->pendingHooks[$recordingId]);
                $processed++;

                $this->logger->debug('Post-recording hook processed', [
                    'recording_id' => $recordingId,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Post-recording hook failed', [
                    'recording_id' => $recordingId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $processed;
    }

    /**
     * Get the configured base path for recording storage.
     *
     * @return string Base path used when post-processing hooks need to
     *                resolve file locations relative to the storage root.
     *
     * @since 0.12.0
     */
    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    /**
     * Get the number of pending hooks.
     *
     * @return int Number of hooks waiting to be processed
     *
     * @since 0.12.0
     */
    public function getPendingCount(): int
    {
        return count($this->pendingHooks);
    }

    /**
     * Clear all pending hooks without processing.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function clearPending(): void
    {
        $this->pendingHooks = [];
    }
}
