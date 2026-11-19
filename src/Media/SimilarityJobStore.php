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
 * File-based job queue for similarity computation.
 *
 * Uses a directory with one JSON file per job. Files are created when jobs
 * are enqueued and removed when complete. FIFO ordering by file mtime.
 * Bounded queue size to prevent unbounded disk usage.
 *
 * The queue mechanics live in {@see JsonJobStore} (D-10 fold); this class is
 * the similarity-shaped door: the SimilarityJob payload, the 'similarity'
 * queue key, and the eager directory mint its constructor has always had.
 *
 * @since 0.38.0
 */
final class SimilarityJobStore extends JsonJobStore
{
    /**
     * @param string|null $queueDir Optional custom queue directory (null =
     *                              {@see \Phlix\Config\JobQueuePaths::dir()}
     *                              'similarity' — R14-14: env-tunable,
     *                              defaults under repo var/jobs/, no longer
     *                              /tmp, so the backlog survives systemd
     *                              PrivateTmp).
     */
    public function __construct(?string $queueDir = null)
    {
        parent::__construct($queueDir, 'similarity');
        $this->ensureQueueDir();
    }

    /**
     * Enqueue a similarity computation job.
     *
     * Idempotent - if the item is already enqueued, this is a no-op.
     *
     * @param SimilarityJob $job The job to enqueue
     */
    public function enqueue(SimilarityJob $job): void
    {
        $this->writeJob($job->itemId, $job->toArray());
    }

    /**
     * Dequeue the next job from the queue.
     *
     * Returns null if the queue is empty.
     *
     * @return SimilarityJob|null The next job or null if queue empty
     */
    public function dequeue(): ?SimilarityJob
    {
        return $this->takeOldestJob(
            static fn (array $data): SimilarityJob => SimilarityJob::fromArray($data)
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
