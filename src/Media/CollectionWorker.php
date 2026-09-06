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
 * Background worker that drains the {@see CollectionJobStore} queue and syncs
 * each queued movie's TMDB box-set membership with bounded concurrency.
 *
 * S215: the scanner enqueues a {@see CollectionJob} per newly indexed,
 * tmdb-matched item instead of running {@see CollectionService::syncCollectionForMovie()}
 * inline. That sync issues TMDB HTTPS requests, and on this transport (https is
 * forced through blocking cURL — see MetadataHttpClient::requestCurl /
 * EventLoopTls::requiresBlockingCurl) every call would stall the scan loop.
 * This worker is the CONSUMER of that queue — without it the enqueued jobs
 * would accumulate forever in the file-based queue directory (disk leak).
 *
 * Mirrors the {@see SimilarityWorker} shape: the same Swoole coroutine
 * `Channel`-as-semaphore pattern bounds concurrent syncs when running inside a
 * coroutine context, and the same {@see Timer}-driven `start()`/`runOnce()`
 * supervision model is used by `start.php`.
 *
 * @since 0.38.0
 */
final class CollectionWorker
{
    /** Default concurrency cap when not in a coroutine context. */
    private const DEFAULT_MAX_CONCURRENT = 1;

    /** @var CollectionJobStore Job queue. */
    private CollectionJobStore $store;

    /** @var CollectionService Collection sync engine. */
    private CollectionService $service;

    /** @var LoggerInterface Logger instance. */
    private LoggerInterface $logger;

    /** @var bool When false, {@see self::runLoop()} returns at the next iteration. */
    private bool $running = true;

    /** @var int Maximum concurrent collection syncs (semaphore capacity). */
    private int $maxConcurrent;

    /**
     * @param CollectionJobStore   $store         Job queue
     * @param CollectionService    $service       Collection sync engine
     * @param LoggerInterface|null $logger        Optional logger
     * @param int                  $maxConcurrent Concurrency cap (default 1 — the
     *                                            syncs are external-HTTP-bound and
     *                                            TMDB rate-limits per key)
     */
    public function __construct(
        CollectionJobStore $store,
        CollectionService $service,
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
            $this->logger->debug('CollectionWorker: queue empty, nothing to process');
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
        $this->logger->info('CollectionWorker: starting loop', [
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
        $this->logger->info('CollectionWorker::start [poll_interval=' .
            $pollSeconds . '] [max_concurrent=' . $this->maxConcurrent . ']');

        Timer::add($pollSeconds, fn (): bool => $this->runOnce() > 0);
    }

    /**
     * Process a batch of jobs concurrently using Swoole coroutines.
     *
     * Uses the Channel-as-semaphore pattern from SimilarityWorker.
     *
     * @param array<CollectionJob> $jobs
     *
     * @return int Number of jobs processed
     */
    private function processConcurrently(array $jobs): int
    {
        $this->logger->debug('CollectionWorker::processConcurrently Starting batch [count=' . count($jobs) . ']');
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

        // Wait for all jobs to complete.
        for ($i = 0; $i < count($jobs); $i++) {
            $done->pop();
        }

        $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
        $this->logger->debug('CollectionWorker::processConcurrently Completed batch [count=' . count($jobs) . ']'
            . ' [processed=' . $processed . '] [duration=' . round($durationMs, 2) . 'ms]');

        return $processed;
    }

    /**
     * Process a batch of jobs sequentially (non-coroutine context).
     *
     * @param array<CollectionJob> $jobs
     *
     * @return int Number of jobs processed
     */
    private function processSequentially(array $jobs): int
    {
        $this->logger->debug('CollectionWorker::processSequentially Starting batch [count=' . count($jobs) . ']');
        $startTime = hrtime(true);

        $processed = 0;

        foreach ($jobs as $job) {
            $this->processOneJob($job);
            $processed++;
        }

        $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
        $this->logger->debug('CollectionWorker::processSequentially Completed batch [count=' . count($jobs) . ']'
            . ' [processed=' . $processed . '] [duration=' . round($durationMs, 2) . 'ms]');

        return $processed;
    }

    /**
     * Process a single job with error handling.
     *
     * @param CollectionJob $job
     */
    private function processOneJob(CollectionJob $job): void
    {
        $this->logger->debug('CollectionWorker::processOneJob Starting [itemId=' . $job->itemId . ']');
        $startTime = hrtime(true);

        try {
            $this->service->syncCollectionForMovie($job->itemId);
            $this->store->complete($job->itemId);
            $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
            $this->logger->debug('CollectionWorker::processOneJob Completed [itemId=' . $job->itemId . '] [duration=' .
                    round($durationMs, 2) . 'ms]');
        } catch (\Throwable $e) {
            $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
            $this->logger->error('CollectionWorker::processOneJob FAILED [itemId=' . $job->itemId . '] [duration='
                . round($durationMs, 2) . 'ms] [error=' . $e->getMessage() . ']');
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
