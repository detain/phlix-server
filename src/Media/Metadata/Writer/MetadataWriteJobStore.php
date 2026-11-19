<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata\Writer;

use Phlix\Media\Support\JsonJobStore;

/**
 * File-based job queue for metadata write-back jobs (S87).
 *
 * One JSON file per pending job in a queue directory, created by the scan path
 * ({@see \Phlix\Media\Library\MediaScanner::processFile()}) and removed by the
 * consumer ({@see MetadataWriteWorker}) when dequeued. FIFO ordering by the
 * timestamp line stored in each file; bounded queue size to prevent unbounded
 * disk usage.
 *
 * The queue mechanics live in {@see JsonJobStore} (D-10 fold); this class is
 * the write-back-shaped door: the MetadataWriteJob payload, the
 * 'metadata_write' queue key, and lazy directory mint — the queue directory is
 * created on first enqueue, never in the constructor: a read-only store must
 * remain side-effect-free, and container-boot tests resolve this store through
 * the DI factory without ever enqueuing. (Historic note: while the R14-14
 * default lived under /tmp this also kept S439's zero-residue census quiet;
 * the default now resolves via {@see \Phlix\Config\JobQueuePaths::dir()}
 * under repo var/jobs/, so the minting concern moved, not the law.)
 *
 * Idempotency: enqueue() keys the file by item id, so a re-scan of the same
 * item while a job is still pending is a no-op — the freshest persisted row is
 * read at drain time anyway, so one pending job per item is sufficient.
 *
 * @since S87
 */
final class MetadataWriteJobStore extends JsonJobStore
{
    /**
     * @param string|null $queueDir Optional custom queue directory
     *                              (null = {@see \Phlix\Config\JobQueuePaths::dir()}
     *                              'metadata_write' — R14-14: env-tunable,
     *                              defaults under repo var/jobs/, survives
     *                              systemd PrivateTmp)
     */
    public function __construct(?string $queueDir = null)
    {
        parent::__construct($queueDir, 'metadata_write');
    }

    /**
     * The directory this store queues into.
     */
    public function getQueueDir(): string
    {
        return $this->queueDir;
    }

    /**
     * Enqueue a metadata write-back job.
     *
     * Idempotent — if the item is already enqueued, this is a no-op.
     *
     * @param MetadataWriteJob $job The job to enqueue
     */
    public function enqueue(MetadataWriteJob $job): void
    {
        $this->writeJob($job->itemId, $job->toArray());
    }

    /**
     * Dequeue the next job from the queue.
     *
     * Returns null if the queue is empty.
     *
     * @return MetadataWriteJob|null The next job or null if queue empty
     */
    public function dequeue(): ?MetadataWriteJob
    {
        return $this->takeOldestJob(
            static fn (array $data): MetadataWriteJob => MetadataWriteJob::fromArray($data)
        );
    }
}
