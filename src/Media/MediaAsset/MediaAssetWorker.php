<?php

/**
 * Phlix media server component: Media Asset.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\MediaAsset;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Workerman\Timer;

/**
 * Background worker that processes the media-asset (chapter-thumbnail + trickplay)
 * generation queue with bounded concurrency.
 *
 * It also drains the stream-probe kind ({@see StreamProbeJob}) that playback info enqueues
 * for a legacy item, first in each tick. A failed stream probe is re-queued up to
 * {@see StreamProbeJob::MAX_ATTEMPTS} runs and then dropped.
 *
 * Uses the same Swoole coroutine semaphore pattern as
 * {@see \Phlix\Media\Library\MediaScanner::probeManyConcurrently()} to limit
 * concurrent ffmpeg executions when running inside a coroutine context.
 *
 * @since 0.36.0
 */
class MediaAssetWorker
{
    /** Default concurrency cap when not in a coroutine context */
    private const DEFAULT_MAX_CONCURRENT = 2;

    /** Most busy-lock retries held in memory at once. A retry beyond this is dropped with an error log. */
    private const DEFERRED_RETRY_CAP = 16;

    /** @var MediaAssetJobStore Job queue */
    private MediaAssetJobStore $store;

    /** @var MediaAssetGenerationJob Job processor */
    private MediaAssetGenerationJob $jobProcessor;

    /** @var LoggerInterface Logger instance */
    private LoggerInterface $logger;

    /** @var bool When false, {@see self::runLoop()} returns at the next iteration. */
    private bool $running = true;

    /** @var int Maximum concurrent ffmpeg executions (semaphore capacity) */
    private int $maxConcurrent;

    /**
     * @var list<StreamProbeJob> Failed stream-probe retries whose re-queue hit a busy queue lock. Each is
     *      retried first on the next tick. Bounded by {@see self::DEFERRED_RETRY_CAP}.
     */
    private array $deferredRetries = [];

    /**
     * @param MediaAssetJobStore       $store          Job queue
     * @param MediaAssetGenerationJob  $jobProcessor   Job processor
     * @param LoggerInterface|null     $logger         Optional logger
     * @param int                      $maxConcurrent  Concurrency cap (default 2)
     */
    public function __construct(
        MediaAssetJobStore $store,
        MediaAssetGenerationJob $jobProcessor,
        ?LoggerInterface $logger = null,
        int $maxConcurrent = self::DEFAULT_MAX_CONCURRENT,
    ) {
        $this->store = $store;
        $this->jobProcessor = $jobProcessor;
        $this->logger = $logger ?? new NullLogger();
        $this->maxConcurrent = $maxConcurrent > 0 ? $maxConcurrent : self::DEFAULT_MAX_CONCURRENT;
    }

    /**
     * Process one batch of jobs up to the concurrency cap.
     *
     * When running inside a Swoole coroutine, uses the Channel-as-semaphore
     * pattern to bound concurrency. Otherwise processes sequentially.
     *
     * @return int Number of jobs processed
     */
    public function runOnce(): int
    {
        // A retry that hit a busy queue lock on an earlier tick is re-queued before anything is dequeued.
        $this->retryDeferredStreamJobs();

        // Stream-probe jobs (a legacy item's background full-track-set probe) go first: each is one
        // ffprobe enqueued by a playback request, so it must not wait behind a chapter/trickplay backlog.
        $streamJobs = [];
        for ($i = 0; $i < $this->maxConcurrent; $i++) {
            $job = $this->store->dequeueStreamProbe();
            if ($job === null) {
                break;
            }
            $streamJobs[] = $job;
        }

        // Check how many jobs are pending (bounded to our concurrency cap)
        $jobsToProcess = [];
        for ($i = 0; $i < $this->maxConcurrent; $i++) {
            $job = $this->store->dequeue();
            if ($job === null) {
                break;
            }
            $jobsToProcess[] = $job;
        }

        if (empty($streamJobs) && empty($jobsToProcess)) {
            $this->logger->debug('MediaAssetWorker: queue empty, nothing to process');
            return 0;
        }

        $processed = 0;

        if ($streamJobs !== []) {
            $processed += $this->processBatch($streamJobs);
        }
        if ($jobsToProcess !== []) {
            $processed += $this->processBatch($jobsToProcess);
        }

        return $processed;
    }

    /**
     * Runs one dequeued batch, concurrently inside a coroutine and sequentially otherwise.
     *
     * @param array<MediaAssetJob|StreamProbeJob> $jobs
     *
     * @return int Number of jobs processed
     */
    private function processBatch(array $jobs): int
    {
        return $this->isCoroutineContext()
            ? $this->processConcurrently($jobs)
            : $this->processSequentially($jobs);
    }

    /**
     * Run continuously with a sleep interval between iterations.
     *
     * @param int $sleepSeconds Seconds to sleep when queue is empty
     */
    public function runLoop(int $sleepSeconds = 30): void
    {
        $this->logger->info('MediaAssetWorker: starting loop', [
            'sleep_interval' => $sleepSeconds,
            'max_concurrent' => $this->maxConcurrent,
        ]);

        while ($this->running) {
            $processed = $this->runOnce();

            if ($processed === 0) {
                Timer::sleep((float) $sleepSeconds);
            }
        }
    }

    /**
     * Request the {@see self::runLoop()} loop to exit at the next iteration.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Get the number of pending jobs in the queue.
     *
     * @return int
     */
    public function getPendingCount(): int
    {
        return $this->store->queueSize();
    }

    /**
     * Start the polling loop on the Workerman event loop.
     *
     * Installs a {@see Timer} that calls {@see self::runOnce()} once per tick.
     * Must be called from inside a worker's `onWorkerStart` because
     * {@see Timer} requires a running event loop.
     *
     * @param int $pollSeconds Poll interval in seconds
     */
    public function start(int $pollSeconds): void
    {
        $this->logger->info('MediaAssetWorker::start [poll_interval=' .
            $pollSeconds . '] [max_concurrent=' . $this->maxConcurrent . ']');

        Timer::add($pollSeconds, fn (): bool => $this->runOnce() > 0);
    }

    /**
     * Process a batch of jobs concurrently using Swoole coroutines.
     *
     * Uses the Channel-as-semaphore pattern from probeManyConcurrently.
     *
     * @param array<MediaAssetJob|StreamProbeJob> $jobs
     *
     * @return int Number of jobs processed
     */
    private function processConcurrently(array $jobs): int
    {
        $this->logger->debug('MediaAssetWorker::processConcurrently Starting batch [count=' . count($jobs) . ']');
        $startTime = hrtime(true);

        $processed = 0;
        $semaphore = new \Swoole\Coroutine\Channel(max(1, $this->maxConcurrent));
        $done = new \Swoole\Coroutine\Channel(count($jobs));

        foreach ($jobs as $job) {
            $semaphore->push(true);
            \Swoole\Coroutine::create(function () use ($job, &$processed, $semaphore, $done): void {
                try {
                    $this->processOneJob($job);
                    $processed++;
                } finally {
                    $semaphore->pop();
                    $done->push(true);
                }
            });
        }

        // Wait for all jobs to complete
        for ($i = 0; $i < count($jobs); $i++) {
            $done->pop();
        }

        $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
        $this->logger->debug('MediaAssetWorker::processConcurrently Completed batch [count=' . count($jobs) . ']'
            . ' [processed=' . $processed . '] [duration=' . round($durationMs, 2) . 'ms]');

        return $processed;
    }

    /**
     * Process a batch of jobs sequentially (non-coroutine context).
     *
     * @param array<MediaAssetJob|StreamProbeJob> $jobs
     *
     * @return int Number of jobs processed
     */
    private function processSequentially(array $jobs): int
    {
        $this->logger->debug('MediaAssetWorker::processSequentially Starting batch [count=' . count($jobs) . ']');
        $startTime = hrtime(true);

        $processed = 0;

        foreach ($jobs as $job) {
            $this->processOneJob($job);
            $processed++;
        }

        $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
        $this->logger->debug('MediaAssetWorker::processSequentially Completed batch [count=' . count($jobs) . ']'
            . ' [processed=' . $processed . '] [duration=' . round($durationMs, 2) . 'ms]');

        return $processed;
    }

    /**
     * Process a single job with error handling.
     *
     * @param MediaAssetJob|StreamProbeJob $job
     */
    private function processOneJob(MediaAssetJob|StreamProbeJob $job): void
    {
        if ($job instanceof StreamProbeJob) {
            $this->processOneStreamJob($job);
            return;
        }

        $this->logger->debug('MediaAssetWorker::processOneJob Starting [itemId=' . $job->itemId . ']');
        $startTime = hrtime(true);

        try {
            $this->jobProcessor->process($job);
            $this->store->complete($job->itemId);
            $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
            $this->logger->debug('MediaAssetWorker::processOneJob Completed [itemId=' . $job->itemId . '] [duration=' .
                    round($durationMs, 2) . 'ms]');
        } catch (\Throwable $e) {
            $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
            $this->logger->error('MediaAssetWorker::processOneJob FAILED [itemId=' . $job->itemId . '] [duration='
                . round($durationMs, 2) . 'ms] [error=' . $e->getMessage() . ']');
            // Mark complete anyway so we don't spin forever on a failing item
            $this->store->complete($job->itemId);
        }
    }

    /**
     * Runs one stream-probe attempt and applies the retry bound.
     *
     * A failed run leaves the item unprobed (the processor stamps nothing on failure) and is
     * re-queued with one more attempt. After {@see StreamProbeJob::MAX_ATTEMPTS} runs the job is
     * dropped. The next playback that still matches the legacy trigger enqueues it again, so a
     * drop is not permanent for the item. No probe runs in the request path, only here.
     *
     * @param StreamProbeJob $job
     */
    private function processOneStreamJob(StreamProbeJob $job): void
    {
        $startTime = hrtime(true);

        try {
            $succeeded = $this->jobProcessor->processStreamProbe($job);
            $error = $succeeded ? '' : 'probe did not complete';
        } catch (\Throwable $e) {
            $succeeded = false;
            $error = $e->getMessage();
        }

        $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;

        if ($succeeded) {
            $this->logger->debug('MediaAssetWorker::processOneStreamJob Completed [itemId=' . $job->itemId . ']'
                . ' [duration=' . round($durationMs, 2) . 'ms]');
            return;
        }

        $next = $job->nextAttempt();
        if ($next->isExhausted()) {
            $this->logger->error('MediaAssetWorker::processOneStreamJob DROPPED after ' . $next->attempts
                . ' attempts [itemId=' . $job->itemId . '] [error=' . $error . ']');
            return;
        }

        if ($this->requeueStreamJob($next)) {
            $this->logger->warning('MediaAssetWorker::processOneStreamJob FAILED, re-queued [itemId=' . $job->itemId
                . '] [attempt=' . $next->attempts . '] [error=' . $error . ']');
        }
    }

    /**
     * Writes a retry back to the stream queue. It never throws out of the timer callback. The retry is the
     * job {@see processOneStreamJob()} produced by {@see StreamProbeJob::nextAttempt()}, so a failed probe
     * is re-queued with its attempt count already incremented.
     *
     * - A busy queue lock (ERROR_LOCK_BUSY) keeps the retry pending: it is held in {@see $deferredRetries}
     *   and re-queued first on the next tick ({@see retryDeferredStreamJobs()}, called before any dequeue).
     *   A playback enqueue that lands in the meantime is not a duplicate problem, because a retry (attempts
     *   above 0) replaces any pending file for the item.
     * - When the held list is already at DEFERRED_RETRY_CAP, the retry is dropped with an error log.
     * - Any other failure (disk full, permissions) drops the job with a warning. The next playback that
     *   still matches the trigger re-enqueues it.
     *
     * @return bool True when the retry was written to the queue; false when it was deferred or dropped
     */
    private function requeueStreamJob(StreamProbeJob $job): bool
    {
        try {
            $this->store->enqueueStreamProbe($job);
            return true;
        } catch (\Throwable $e) {
            if ($e->getCode() === MediaAssetJobStore::ERROR_LOCK_BUSY) {
                if (count($this->deferredRetries) < self::DEFERRED_RETRY_CAP) {
                    $this->deferredRetries[] = $job;
                    $this->logger->warning('MediaAssetWorker::processOneStreamJob re-queue DEFERRED, '
                        . 'queue lock busy [itemId=' . $job->itemId . '] [attempt=' . $job->attempts . ']');
                    return false;
                }

                $this->logger->error('MediaAssetWorker::processOneStreamJob re-queue DROPPED, '
                    . 'deferred list full [itemId=' . $job->itemId . '] [attempt=' . $job->attempts . ']');
                return false;
            }

            $this->logger->warning('MediaAssetWorker::processOneStreamJob re-queue FAILED, job dropped [itemId='
                . $job->itemId . '] [attempt=' . $job->attempts . '] [error=' . $e->getMessage() . ']');
            return false;
        }
    }

    /**
     * Re-queues the retries deferred by an earlier busy lock. Each one that is still busy is deferred again,
     * within the cap. The list is taken first, so a deferral during this loop lands in the new list.
     */
    private function retryDeferredStreamJobs(): void
    {
        $pending = $this->deferredRetries;
        $this->deferredRetries = [];

        foreach ($pending as $job) {
            $this->requeueStreamJob($job);
        }
    }

    /**
     * Detect if we're running inside a Swoole coroutine context.
     *
     * @return bool True when inside a coroutine
     */
    private function isCoroutineContext(): bool
    {
        return extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0;
    }
}
