<?php

/**
 * Phlix media server component: Detection.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
     * Whether the "queue empty" line has already been emitted for the CURRENT idle
     * streak.
     *
     * This worker is spawned as a managed worker (`config/managed_workers.php`)
     * polling every 30s (`config/process.php` → `marker-detection.poll_seconds`),
     * and app.log is at `debug` (`config/logger.php`). Logging "nothing to process"
     * on every idle tick is ~2,880 identical lines per day per box — pure noise
     * that buries the genuine work/error lines. So the line is emitted on the STATE
     * CHANGE only: the first idle tick after startup, and the first idle tick after
     * a backlog drains. It re-arms as soon as a show is processed.
     */
    private bool $idleLogged = false;

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
            // Idle is the steady state on a 30s poll — log the transition INTO idle,
            // not every tick. See {@see self::$idleLogged}.
            if (!$this->idleLogged) {
                $this->logger->debug('BackgroundDetectorWorker: queue empty, nothing to process');
                $this->idleLogged = true;
            }
            return false;
        }

        $this->idleLogged = false;

        $this->logger->info('BackgroundDetectorWorker::runOnce Starting [showId=' . $showId . ']');
        $startTime = hrtime(true);

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

            $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
            $this->logger->info('BackgroundDetectorWorker::runOnce Completed [showId=' . $showId . '] [duration='
                . round($durationMs, 2) . 'ms] [episodes=' . count($result->episodes_processed) . ']');

            return true;
        } catch (\Throwable $e) {
            $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
            $this->logger->error('BackgroundDetectorWorker::runOnce FAILED [showId=' . $showId . '] [duration='
                . round($durationMs, 2) . 'ms] [error=' . $e->getMessage() . ']');

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

    /**
     * Start the polling loop on the Workerman event loop.
     *
     * Installs a {@see Timer} that calls {@see self::runOnce()} once per tick
     * (one job per tick — a backlog of N drains in ≤ N ticks). Must be called
     * from inside a worker's `onWorkerStart` because {@see Timer} requires a
     * running event loop.
     *
     * Uses the same non-blocking {@see Timer::add()} pattern as
     * {@see \Phlix\Media\Library\LibraryScanWorker::start()} and
     * {@see \Phlix\Plugins\Catalog\PluginAutoUpdateWorker::start()}, replacing
     * the legacy blocking {@see self::runLoop()} which used {@see Timer::sleep()}
     * in a tight while-loop (unsuitable for Workerman's event loop context).
     *
     * @param int $pollSeconds Poll interval in seconds.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function start(int $pollSeconds): void
    {
        $this->logger->info('BackgroundDetectorWorker: starting supervised loop', [
            'poll_interval' => $pollSeconds,
        ]);

        Timer::add($pollSeconds, fn (): bool => $this->runOnce());
    }
}
