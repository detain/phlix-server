<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata\Writer;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaItem;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Workerman\Timer;

/**
 * Background consumer that drains the {@see MetadataWriteJobStore} queue (S87).
 *
 * The scanner only ENQUEUES (S87 AC 1); this worker is the sole place a
 * {@see MetadataWriterInterface} is ever invoked, which is what makes S87 AC 2
 * ("no blocking filesystem I/O happens inline in the scan path") structurally
 * true: disk-write capability lives exclusively in this managed process, away
 * from every scan and HTTP worker.
 *
 * S87 shipped the plumbing with zero writers; S88 added the built-in
 * {@see SidecarWriter} through the registry's DI definition (ruling R1), so a
 * drain now executes real writes for movie/episode/track rows. The embedded-tag
 * writer remains S89. A plugin registered through the S87 capability arm
 * executes alongside it — `supporting()` returns every registered writer whose
 * type sniff passes, each isolated by the per-writer catch below.
 *
 * Two invariants this class exists to hold:
 *  - The plugin registry is PER-PROCESS resident state, so the fork running
 *    this worker must have wired its enabled plugins first. `start.php` calls
 *    `PluginLoader::bootstrapEnabled()` inside the `metadata-write` fork for
 *    exactly this reason; a worker that skipped it would silently no-op every
 *    job because its registry is empty.
 *  - Without this consumer the queue accumulates undrained on disk (the exact
 *    SV-1.3 / SV-2.9 disk-leak bug class), so `config/process.php` +
 *    `config/managed_workers.php` spawn it alongside the queue's producer.
 *
 * Writers are invoked SEQUENTIALLY here — deliberately, not for
 * backward-compatibility: an S88/S89 write is blocking filesystem I/O of
 * unknown cost (poster download-and-copy, ffmpeg remux to a temp file, atomic
 * rename), and bounded parallelism over that belongs to the step that
 * measures it, not to the plumbing step. S88/S89 may adopt the Swoole
 * Channel-as-semaphore pattern from {@see \Phlix\Media\SimilarityWorker} if
 * drain latency demands it.
 *
 * @since S87
 */
final class MetadataWriteWorker
{
    /** Default per-batch job cap. */
    private const DEFAULT_MAX_CONCURRENT = 2;

    /** @var MetadataWriteJobStore File queue to drain */
    private MetadataWriteJobStore $store;

    /** @var MetadataWriterRegistry Enabled plugin writers for THIS process */
    private MetadataWriterRegistry $registry;

    /** @var ItemRepository Re-reads the freshest canonical row at drain time */
    private ItemRepository $itemRepository;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /** @var bool When false, {@see self::runLoop()} returns at the next iteration */
    private bool $running = true;

    /** @var int Maximum jobs drained per batch */
    private int $maxConcurrent;

    public function __construct(
        MetadataWriteJobStore $store,
        MetadataWriterRegistry $registry,
        ItemRepository $itemRepository,
        ?LoggerInterface $logger = null,
        int $maxConcurrent = self::DEFAULT_MAX_CONCURRENT,
    ) {
        $this->store = $store;
        $this->registry = $registry;
        $this->itemRepository = $itemRepository;
        $this->logger = $logger ?? new NullLogger();
        $this->maxConcurrent = $maxConcurrent > 0 ? $maxConcurrent : self::DEFAULT_MAX_CONCURRENT;
    }

    /**
     * Process one batch of queued jobs (up to the batch cap), sequentially.
     *
     * @return int Number of jobs drained (a job whose type has no writer still counts as drained)
     */
    public function runOnce(): int
    {
        $jobs = [];
        for ($i = 0; $i < $this->maxConcurrent; $i++) {
            $job = $this->store->dequeue();
            if ($job === null) {
                break;
            }
            $jobs[] = $job;
        }

        if ($jobs === []) {
            $this->logger->debug('MetadataWriteWorker: queue empty, nothing to process');
            return 0;
        }

        $processed = 0;
        foreach ($jobs as $job) {
            $this->processOneJob($job);
            $processed++;
        }

        return $processed;
    }

    /**
     * Run continuously with a sleep interval between iterations.
     *
     * @param int $sleepSeconds Seconds to sleep when the queue is empty
     */
    public function runLoop(int $sleepSeconds = 30): void
    {
        $this->logger->info('MetadataWriteWorker: starting loop', [
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
        $this->logger->info('MetadataWriteWorker::start [poll_interval=' .
            $pollSeconds . '] [max_concurrent=' . $this->maxConcurrent . ']');

        Timer::add($pollSeconds, fn (): bool => $this->runOnce() > 0);
    }

    /**
     * Get the number of pending jobs in the queue.
     */
    public function getPendingCount(): int
    {
        return $this->store->queueSize();
    }

    /**
     * Resolve the job's item and hand it to every registered writer that
     * supports its type.
     *
     * Failure isolation: a throwing writer must never stop the batch (the S88
     * is_writable()/S89 overwrite-policy error paths will throw exactly this
     * way) and a vanished row (item deleted between enqueue and drain) is a
     * legitimate drop.
     */
    private function processOneJob(MetadataWriteJob $job): void
    {
        $row = $this->itemRepository->findById($job->itemId);
        if ($row === null) {
            $this->logger->debug('MetadataWriteWorker: item vanished before drain, dropping job', [
                'item_id' => $job->itemId,
            ]);
            return;
        }

        $item = MediaItem::fromRow($row);
        $writers = $this->registry->supporting($item->type);

        if ($writers === []) {
            // S87-era normal path: writers arrive with S88/S89.
            $this->logger->debug('MetadataWriteWorker: no writer supports this type, no-op', [
                'item_id' => $item->id,
                'type' => $item->type,
            ]);
            return;
        }

        $mediaDir = dirname($item->path);
        foreach ($writers as $writer) {
            try {
                $writer->write($item, $item->metadata, $mediaDir);
            } catch (Throwable $e) {
                $this->logger->warning('MetadataWriteWorker: writer failed for item', [
                    'item_id' => $item->id,
                    'writer' => $writer::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
