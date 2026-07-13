<?php

/**
 * Phlix media server component: Recording.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\LiveTv\Recording;

use Phlix\Common\Runtime\WorkerContext;
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

    /** @var bool Whether an off-hot-path drain timer is currently armed. */
    private bool $drainScheduled = false;

    /**
     * Delay (seconds) before the deferred drain fires.
     *
     * A small non-zero delay guarantees the drain runs on a LATER event-loop
     * iteration — strictly after the completion's synchronous onComplete hooks
     * (notably {@see RecordingMediaRegistrar}, which sets the `media_item_id` the
     * comskip run needs) have finished.
     *
     * @var float
     */
    private const DRAIN_DELAY_SECONDS = 1.0;

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

        // Drain OFF the hot completion path (see scheduleDrain()).
        $this->scheduleDrain();
    }

    /**
     * Schedule draining of the queue off the hot completion path.
     *
     * `enqueue()` is invoked from a {@see \Phlix\LiveTv\Recorder} onComplete
     * callback, which runs inside the worker's scheduler-tick / stop-recording
     * coroutine. A comskip run can take up to 300s; running it inline there would
     * hold that completion coroutine for the whole duration. So on the resident
     * daemon (a RUNNING Workerman worker) the actual processing is deferred to a
     * ONE-SHOT Workerman timer ({@see self::armDrainTimer()}): the completion
     * coroutine returns immediately, and the timer fires on a later loop iteration
     * — strictly AFTER the completion's synchronous onComplete hooks (notably
     * {@see RecordingMediaRegistrar}, which persists the `media_item_id` the
     * comskip run attaches chapter markers to) have run. A single armed timer is
     * enough — a re-entrant enqueue while one is pending just appends to the queue
     * that the pending drain will pick up.
     *
     * When no worker loop is running (CLI / PHPUnit / FPM) there is no event loop
     * to protect — and `Workerman\Timer::add()` is not usable — so the queue is
     * drained inline, preserving the historic synchronous behaviour.
     *
     * @return void
     *
     * @since SV-3.1d-comskip
     */
    private function scheduleDrain(): void
    {
        if ($this->shouldDeferDrain()) {
            if ($this->drainScheduled) {
                return;
            }
            $this->drainScheduled = true;
            $this->armDrainTimer();
            return;
        }

        // No running worker loop to protect — drain synchronously.
        $this->drainQueue();
    }

    /**
     * Whether the drain must be deferred to a timer (running-worker environment).
     *
     * Deferral is only valid — and `Workerman\Timer::add()` only works — inside a
     * RUNNING Workerman worker ({@see WorkerContext::isEventLoopRunning()}), which
     * is exactly where the completion coroutine we must not hold executes.
     *
     * Overridable seam for tests (which cannot spin a real worker).
     *
     * @return bool
     *
     * @since SV-3.1d-comskip
     */
    protected function shouldDeferDrain(): bool
    {
        return WorkerContext::isEventLoopRunning() && class_exists(\Workerman\Timer::class);
    }

    /**
     * Arm the one-shot drain timer on the running worker's event loop.
     *
     * Overridable seam for tests (which cannot arm a real Workerman timer).
     *
     * @return void
     *
     * @since SV-3.1d-comskip
     */
    protected function armDrainTimer(): void
    {
        \Workerman\Timer::add(
            self::DRAIN_DELAY_SECONDS,
            function (): void {
                $this->onDrainTimer();
            },
            [],
            false, // one-shot
        );
    }

    /**
     * One-shot drain-timer callback: drain the queue, tolerating failures.
     *
     * Runs inside a coroutine under the Workerman Swoole event adapter (timer
     * callbacks are wrapped in Coroutine::create()), so the (cooperatively
     * yielding, SV-4.3) comskip run performed via {@see self::processNext()} runs
     * in a valid context without freezing the worker loop. Any late arrival that
     * landed after the queue emptied but before the flag reset re-arms the timer.
     *
     * @return void
     *
     * @since SV-3.1d-comskip
     */
    protected function onDrainTimer(): void
    {
        $this->drainScheduled = false;

        try {
            $this->drainQueue();
        } catch (\Throwable $e) {
            $this->logger->error('Comskip drain failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // Re-arm for any late arrival that landed after the queue emptied.
        // scheduleDrain() is a no-op if a timer is already armed.
        if (!empty($this->pendingQueue)) {
            $this->scheduleDrain();
        }
    }

    /**
     * Drain the pending queue, processing each recording in turn.
     *
     * Serialized: {@see self::processNext()} runs one recording at a time (its
     * per-run failures are already swallowed in {@see self::processRecordingSync()},
     * so the loop can never be aborted by a single comskip failure). Returns once
     * the queue is empty (or the concurrency limit is momentarily reached).
     *
     * @return int Number of recordings processed in this drain pass.
     *
     * @since SV-3.1d-comskip
     */
    public function drainQueue(): int
    {
        $processed = 0;
        while ($this->processNext()) {
            $processed++;
        }
        return $processed;
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
