<?php

/**
 * Phlix media server component: Media.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media;

use Phlix\Media\Support\JsonJobStore;

/**
 * File-based job queue for TMDB box-set collection sync.
 *
 * One JSON file per pending job in a queue directory. Files are created when
 * jobs are enqueued (the scan path) and removed when dequeued/complete (the
 * worker). FIFO ordering by the timestamp line stored in each file. Bounded
 * queue size to prevent unbounded disk usage.
 *
 * The queue mechanics live in {@see JsonJobStore} (D-10 fold); this class is
 * the collection-shaped door: the CollectionJob payload, the 'collection'
 * queue key, and lazy directory mint — the queue directory is created on
 * first enqueue, never in the constructor: a read-only store must remain
 * side-effect-free, and container-boot tests resolve this store through the
 * DI factories without ever enqueuing. (Historic note: while the R14-14
 * default lived under /tmp this also kept S439's zero-residue census quiet;
 * the default now resolves via {@see \Phlix\Config\JobQueuePaths::dir()}
 * under repo var/jobs/, so the minting concern moved, not the law.)
 *
 * @since 0.38.0
 */
final class CollectionJobStore extends JsonJobStore
{
    /**
     * @param string|null $queueDir Optional custom queue directory
     *                              (null = {@see \Phlix\Config\JobQueuePaths::dir()}
     *                              'collection' — R14-14: env-tunable,
     *                              defaults under repo var/jobs/, survives
     *                              systemd PrivateTmp)
     */
    public function __construct(?string $queueDir = null)
    {
        parent::__construct($queueDir, 'collection');
    }

    /**
     * The directory this store queues into.
     */
    public function getQueueDir(): string
    {
        return $this->queueDir;
    }

    /**
     * Enqueue a collection sync job.
     *
     * Idempotent - if the item is already enqueued, this is a no-op.
     *
     * @param CollectionJob $job The job to enqueue
     */
    public function enqueue(CollectionJob $job): void
    {
        $this->writeJob($job->itemId, $job->toArray());
    }

    /**
     * Dequeue the next job from the queue.
     *
     * Returns null if the queue is empty.
     *
     * @return CollectionJob|null The next job or null if queue empty
     */
    public function dequeue(): ?CollectionJob
    {
        return $this->takeOldestJob(
            static fn (array $data): CollectionJob => CollectionJob::fromArray($data)
        );
    }

    /**
     * Get all pending job item IDs.
     *
     * @return array<string>
     */
    public function getPendingItemIds(): array
    {
        return $this->pendingItemIds();
    }
}
