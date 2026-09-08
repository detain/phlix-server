<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata\Writer;

/**
 * File-based job queue for metadata write-back jobs (S87).
 *
 * One JSON file per pending job in a queue directory, created by the scan path
 * ({@see \Phlix\Media\Library\MediaScanner::processFile()}) and removed by the
 * consumer ({@see MetadataWriteWorker}) when dequeued. FIFO ordering by the
 * timestamp line stored in each file; bounded queue size to prevent unbounded
 * disk usage.
 *
 * Mirrors {@see \Phlix\Media\CollectionJobStore}, including its deliberate
 * deviation from {@see \Phlix\Media\SimilarityJobStore}: the queue directory is
 * created LAZILY on first enqueue, never in the constructor. S439's
 * zero-residue census fails the suite on any `/tmp/phlix_*` directory a test
 * run mints and does not sweep, and container-boot tests resolve this store
 * through the DI factory without ever enqueuing — a constructor-side `mkdir()`
 * would leak `/tmp/phlix_metadata_write_jobs` on clean CI runners, and a
 * read-only store must remain side-effect-free.
 *
 * Idempotency: enqueue() keys the file by item id, so a re-scan of the same
 * item while a job is still pending is a no-op — the freshest persisted row is
 * read at drain time anyway, so one pending job per item is sufficient.
 *
 * @since S87
 */
final class MetadataWriteJobStore
{
    /** Default queue directory path */
    private const QUEUE_DIR = '/tmp/phlix_metadata_write_jobs';

    /** Job file extension */
    private const JOB_EXT = '.job.json';

    /** Maximum queue size to prevent unbounded disk usage */
    private const MAX_QUEUE_SIZE = 1000;

    /** @var string Queue directory path */
    private string $queueDir;

    /**
     * @param string|null $queueDir Optional custom queue directory
     *                              (null = {@see self::QUEUE_DIR})
     */
    public function __construct(?string $queueDir = null)
    {
        $this->queueDir = $queueDir ?? self::QUEUE_DIR;
    }

    /**
     * The directory this store queues into.
     */
    public function getQueueDir(): string
    {
        return $this->queueDir;
    }

    /**
     * Ensure the queue directory exists (lazy — first write pays for it).
     */
    private function ensureQueueDir(): void
    {
        if (!is_dir($this->queueDir)) {
            mkdir($this->queueDir, 0755, true);
        }
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
        $this->ensureQueueDir();
        $jobFile = $this->getJobFilePath($job->itemId);

        if (file_exists($jobFile)) {
            return;
        }

        // Check queue size and evict oldest if at limit
        $this->evictIfNeeded();

        $json = json_encode($job->toArray(), JSON_THROW_ON_ERROR);
        $timestamp = microtime(true);
        file_put_contents($jobFile, $json . "\n" . $timestamp . "\n", LOCK_EX);
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
        $files = $this->getJobFilesSortedByTime();

        if ($files === []) {
            return null;
        }

        $oldestFile = $files[0];
        $content = file_get_contents($oldestFile);
        if ($content === false) {
            $this->removeJobFile($oldestFile);
            return null;
        }

        $lines = explode("\n", trim($content));
        $json = $lines[0] ?? '';

        if ($json === '') {
            $this->removeJobFile($oldestFile);
            return null;
        }

        // Remove the file so we don't dequeue the same job again
        $this->removeJobFile($oldestFile);

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return MetadataWriteJob::fromArray($data);
        } catch (\Throwable) {
            // Corrupt file — skip
            return null;
        }
    }

    /**
     * Mark a job as complete (remove from queue).
     *
     * @param string $itemId The media item ID
     */
    public function complete(string $itemId): void
    {
        $jobFile = $this->getJobFilePath($itemId);

        if (file_exists($jobFile)) {
            unlink($jobFile);
        }
    }

    /**
     * Check if an item is already enqueued.
     *
     * @param string $itemId The media item ID
     *
     * @return bool True if already in queue
     */
    public function isEnqueued(string $itemId): bool
    {
        return file_exists($this->getJobFilePath($itemId));
    }

    /**
     * Get the queue size.
     *
     * @return int Number of jobs in queue
     */
    public function queueSize(): int
    {
        return count($this->getJobFiles());
    }

    /**
     * Clear the entire queue.
     */
    public function clear(): void
    {
        foreach ($this->getJobFiles() as $file) {
            unlink($file);
        }
    }

    /**
     * Get the job file path for an item.
     *
     * @param string $itemId The media item ID
     *
     * @return string Full path to job file
     */
    private function getJobFilePath(string $itemId): string
    {
        $sanitizedId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $itemId);
        return $this->queueDir . '/' . $sanitizedId . self::JOB_EXT;
    }

    /**
     * Get all job files (empty when the queue dir was never minted).
     *
     * @return list<string>
     */
    private function getJobFiles(): array
    {
        if (!is_dir($this->queueDir)) {
            return [];
        }

        $files = glob($this->queueDir . '/*' . self::JOB_EXT);

        if ($files === false) {
            return [];
        }

        sort($files);

        return $files;
    }

    /**
     * Get all job files sorted by timestamp in file content (FIFO order).
     *
     * @return list<string>
     */
    private function getJobFilesSortedByTime(): array
    {
        $files = $this->getJobFiles();

        if ($files === []) {
            return [];
        }

        usort($files, function (string $a, string $b): int {
            $contentA = file_get_contents($a);
            $contentB = file_get_contents($b);

            $linesA = is_string($contentA) ? explode("\n", trim($contentA)) : [];
            $linesB = is_string($contentB) ? explode("\n", trim($contentB)) : [];

            $timestampA = (float) ($linesA[1] ?? 0);
            $timestampB = (float) ($linesB[1] ?? 0);

            return $timestampA <=> $timestampB;
        });

        return $files;
    }

    /**
     * Evict oldest job if queue is at capacity.
     */
    private function evictIfNeeded(): void
    {
        $files = $this->getJobFiles();

        if (count($files) >= self::MAX_QUEUE_SIZE) {
            // Remove the oldest (first in sorted order)
            $this->removeJobFile($files[0]);
        }
    }

    /**
     * Remove a job file.
     *
     * @param string $jobFile Job file path
     */
    private function removeJobFile(string $jobFile): void
    {
        if (file_exists($jobFile)) {
            unlink($jobFile);
        }
    }
}
