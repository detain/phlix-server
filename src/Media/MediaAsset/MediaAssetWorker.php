<?php

/**
 * Phlix media server component: Media Asset.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
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
        // Check how many jobs are pending (bounded to our concurrency cap)
        $jobsToProcess = [];
        for ($i = 0; $i < $this->maxConcurrent; $i++) {
            $job = $this->store->dequeue();
            if ($job === null) {
                break;
            }
            $jobsToProcess[] = $job;
        }

        if (empty($jobsToProcess)) {
            $this->logger->debug('MediaAssetWorker: queue empty, nothing to process');
            return 0;
        }

        $processed = 0;

        if ($this->isCoroutineContext()) {
            $processed = $this->processConcurrently($jobsToProcess);
        } else {
            $processed = $this->processSequentially($jobsToProcess);
        }

        return $processed;
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
        $this->logger->info('[DEBUG] ' . date('Y-m-d H:i:s.v') . ' MediaAssetWorker::start [poll_interval=' . $pollSeconds . '] [max_concurrent=' . $this->maxConcurrent . ']');

        Timer::add($pollSeconds, fn (): bool => $this->runOnce() > 0);
    }

    /**
     * Process a batch of jobs concurrently using Swoole coroutines.
     *
     * Uses the Channel-as-semaphore pattern from probeManyConcurrently.
     *
     * @param array<MediaAssetJob> $jobs
     *
     * @return int Number of jobs processed
     */
    private function processConcurrently(array $jobs): int
    {
        $this->logger->debug('[DEBUG] ' . date('Y-m-d H:i:s.v') . ' MediaAssetWorker::processConcurrently Starting batch [count=' . count($jobs) . ']');
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
        $this->logger->debug('[DEBUG] ' . date('Y-m-d H:i:s.v') . ' MediaAssetWorker::processConcurrently Completed batch [count=' . count($jobs) . '] [processed=' . $processed . '] [duration=' . round($durationMs, 2) . 'ms]');

        return $processed;
    }

    /**
     * Process a batch of jobs sequentially (non-coroutine context).
     *
     * @param array<MediaAssetJob> $jobs
     *
     * @return int Number of jobs processed
     */
    private function processSequentially(array $jobs): int
    {
        $this->logger->debug('[DEBUG] ' . date('Y-m-d H:i:s.v') . ' MediaAssetWorker::processSequentially Starting batch [count=' . count($jobs) . ']');
        $startTime = hrtime(true);

        $processed = 0;

        foreach ($jobs as $job) {
            $this->processOneJob($job);
            $processed++;
        }

        $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
        $this->logger->debug('[DEBUG] ' . date('Y-m-d H:i:s.v') . ' MediaAssetWorker::processSequentially Completed batch [count=' . count($jobs) . '] [processed=' . $processed . '] [duration=' . round($durationMs, 2) . 'ms]');

        return $processed;
    }

    /**
     * Process a single job with error handling.
     *
     * @param MediaAssetJob $job
     */
    private function processOneJob(MediaAssetJob $job): void
    {
        $this->logger->debug('[DEBUG] ' . date('Y-m-d H:i:s.v') . ' MediaAssetWorker::processOneJob Starting [itemId=' . $job->itemId . ']');
        $startTime = hrtime(true);

        try {
            $this->jobProcessor->process($job);
            $this->store->complete($job->itemId);
            $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
            $this->logger->debug('[DEBUG] ' . date('Y-m-d H:i:s.v') . ' MediaAssetWorker::processOneJob Completed [itemId=' . $job->itemId . '] [duration=' . round($durationMs, 2) . 'ms]');
        } catch (\Throwable $e) {
            $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
            $this->logger->error('[DEBUG] ' . date('Y-m-d H:i:s.v') . ' MediaAssetWorker::processOneJob FAILED [itemId=' . $job->itemId . '] [duration=' . round($durationMs, 2) . 'ms] [error=' . $e->getMessage() . ']');
            // Mark complete anyway so we don't spin forever on a failing item
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
