<?php

/**
 * Phlix media server component: Media.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Workerman\Timer;

/**
 * Background worker that drains the {@see SimilarityJobStore} queue and computes
 * item-similarity for each queued media item with bounded concurrency.
 *
 * SV-2.9: the scanner enqueues a {@see SimilarityJob} per new item (instead of
 * running the O(N²) full-table similarity computation inline on the scan path).
 * This worker is the CONSUMER of that queue — without it the enqueued jobs would
 * accumulate forever in the file-based queue directory (disk leak). Each job is
 * handed to {@see SimilarityService::computeSimilarForItem()} with the job's
 * `libraryId`, so the candidate set stays bounded to a single library rather
 * than re-scanning the whole catalogue.
 *
 * Mirrors the {@see \Phlix\Media\MediaAsset\MediaAssetWorker} shape: the same
 * Swoole coroutine `Channel`-as-semaphore pattern bounds concurrent computations
 * when running inside a coroutine context, and the same {@see Timer}-driven
 * `start()`/`runOnce()` supervision model is used by `start.php`.
 *
 * @since 0.38.0
 */
final class SimilarityWorker
{
    /** Default concurrency cap when not in a coroutine context. */
    private const DEFAULT_MAX_CONCURRENT = 2;

    /** @var SimilarityJobStore Job queue. */
    private SimilarityJobStore $store;

    /** @var SimilarityService Similarity computation engine. */
    private SimilarityService $service;

    /** @var LoggerInterface Logger instance. */
    private LoggerInterface $logger;

    /** @var bool When false, {@see self::runLoop()} returns at the next iteration. */
    private bool $running = true;

    /** @var int Maximum concurrent similarity computations (semaphore capacity). */
    private int $maxConcurrent;

    /**
     * @param SimilarityJobStore   $store         Job queue
     * @param SimilarityService    $service       Similarity computation engine
     * @param LoggerInterface|null $logger        Optional logger
     * @param int                  $maxConcurrent Concurrency cap (default 2)
     */
    public function __construct(
        SimilarityJobStore $store,
        SimilarityService $service,
        ?LoggerInterface $logger = null,
        int $maxConcurrent = self::DEFAULT_MAX_CONCURRENT,
    ) {
        $this->store = $store;
        $this->service = $service;
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
        // Dequeue up to our concurrency cap of pending jobs.
        $jobsToProcess = [];
        for ($i = 0; $i < $this->maxConcurrent; $i++) {
            $job = $this->store->dequeue();
            if ($job === null) {
                break;
            }
            $jobsToProcess[] = $job;
        }

        if ($jobsToProcess === []) {
            $this->logger->debug('SimilarityWorker: queue empty, nothing to process');
            return 0;
        }

        if ($this->isCoroutineContext()) {
            return $this->processConcurrently($jobsToProcess);
        }

        return $this->processSequentially($jobsToProcess);
    }

    /**
     * Run continuously with a sleep interval between iterations.
     *
     * @param int $sleepSeconds Seconds to sleep when the queue is empty
     */
    public function runLoop(int $sleepSeconds = 30): void
    {
        $this->logger->info('SimilarityWorker: starting loop', [
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
        $this->logger->info('SimilarityWorker: starting supervised loop', [
            'poll_interval' => $pollSeconds,
            'max_concurrent' => $this->maxConcurrent,
        ]);

        Timer::add($pollSeconds, fn (): bool => $this->runOnce() > 0);
    }

    /**
     * Process a batch of jobs concurrently using Swoole coroutines.
     *
     * Uses the Channel-as-semaphore pattern from MediaAssetWorker.
     *
     * @param array<SimilarityJob> $jobs
     *
     * @return int Number of jobs processed
     */
    private function processConcurrently(array $jobs): int
    {
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

        // Wait for all jobs to complete.
        for ($i = 0; $i < count($jobs); $i++) {
            $done->pop();
        }

        return $processed;
    }

    /**
     * Process a batch of jobs sequentially (non-coroutine context).
     *
     * @param array<SimilarityJob> $jobs
     *
     * @return int Number of jobs processed
     */
    private function processSequentially(array $jobs): int
    {
        $processed = 0;

        foreach ($jobs as $job) {
            $this->processOneJob($job);
            $processed++;
        }

        return $processed;
    }

    /**
     * Process a single job with error handling.
     *
     * @param SimilarityJob $job
     */
    private function processOneJob(SimilarityJob $job): void
    {
        $this->logger->debug('SimilarityWorker: processing job', [
            'item_id' => $job->itemId,
            'library_id' => $job->libraryId,
        ]);

        try {
            $this->service->computeSimilarForItem($job->itemId, $job->libraryId);
            $this->store->complete($job->itemId);
        } catch (\Throwable $e) {
            $this->logger->error('SimilarityWorker: job failed', [
                'item_id' => $job->itemId,
                'error' => $e->getMessage(),
            ]);
            // Mark complete anyway so we don't spin forever on a failing item.
            $this->store->complete($job->itemId);
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
