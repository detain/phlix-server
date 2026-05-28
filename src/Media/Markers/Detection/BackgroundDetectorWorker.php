<?php

declare(strict_types=1);

namespace Phlix\Media\Markers\Detection;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Workerman\Timer;

/**
 * Background worker that processes the marker detection queue.
 *
 * Runs as a separate PHP process and continuously polls the queue
 * for shows needing intro/outro detection.
 *
 * @since 0.12.0
 */
class BackgroundDetectorWorker
{
    /** @var IntroDetectionJob Detection job */
    private IntroDetectionJob $job;

    /** @var MarkerCandidateStore Job store */
    private MarkerCandidateStore $store;

    /** @var MarkerCandidateRepository Candidate repository */
    private MarkerCandidateRepository $candidateRepo;

    /** @var LoggerInterface Logger instance */
    private LoggerInterface $logger;

    /** @var bool When false, {@see self::runLoop()} returns at the next iteration. */
    private bool $running = true;

    /**
     * @param IntroDetectionJob           $job           Detection job
     * @param MarkerCandidateStore        $store         Job store
     * @param MarkerCandidateRepository   $candidateRepo Candidate repository
     * @param LoggerInterface|null         $logger        Optional logger
     *
     * @since 0.12.0
     */
    public function __construct(
        IntroDetectionJob $job,
        MarkerCandidateStore $store,
        MarkerCandidateRepository $candidateRepo,
        ?LoggerInterface $logger = null,
    ) {
        $this->job = $job;
        $this->store = $store;
        $this->candidateRepo = $candidateRepo;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Run one iteration: dequeue a show, detect, store results.
     *
     * @return bool True if a show was processed, false if queue was empty
     *
     * @since 0.12.0
     */
    public function runOnce(): bool
    {
        $showId = $this->store->dequeueShow();

        if ($showId === null) {
            $this->logger->debug('BackgroundDetectorWorker: queue empty, nothing to process');
            return false;
        }

        $this->logger->info('BackgroundDetectorWorker: processing show', [
            'show_id' => $showId,
        ]);

        try {
            $result = $this->job->detectForShow($showId);

            if ($result->hasMarkers()) {
                $this->candidateRepo->storeCandidates($showId, $result);
                $this->logger->info('BackgroundDetectorWorker: stored marker candidates', [
                    'show_id' => $showId,
                    'has_intro' => $result->intro_candidate !== null,
                    'has_outro' => $result->outro_candidate !== null,
                ]);
            }

            $this->store->completeShow($showId);

            $this->logger->info('BackgroundDetectorWorker: completed processing', [
                'show_id' => $showId,
                'episodes_processed' => count($result->episodes_processed),
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('BackgroundDetectorWorker: failed to process show', [
                'show_id' => $showId,
                'error' => $e->getMessage(),
            ]);

            $this->store->completeShow($showId);

            return true;
        }
    }

    /**
     * Run continuously with a sleep interval between iterations.
     *
     * @param int $sleepSeconds Seconds to sleep when queue is empty
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function runLoop(int $sleepSeconds = 30): void
    {
        $this->logger->info('BackgroundDetectorWorker: starting loop', [
            'sleep_interval' => $sleepSeconds,
        ]);

        while ($this->running) {
            $processed = $this->runOnce();

            if (!$processed) {
                Timer::sleep((float)$sleepSeconds);
            }
        }
    }

    /**
     * Request the {@see self::runLoop()} loop to exit at the start of
     * the next iteration. Useful for tests and signal handlers.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Get the number of pending jobs in the queue.
     *
     * @return int Number of pending jobs
     *
     * @since 0.12.0
     */
    public function getPendingCount(): int
    {
        return $this->store->queueSize();
    }
}
