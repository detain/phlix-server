<?php

/**
 * Phlix media server component: Media.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\Support;

/**
 * Shared file-based JSON job-queue engine behind the per-feature stores
 * (D-10: SimilarityJobStore, CollectionJobStore, MetadataWriteJobStore).
 *
 * One JSON file per pending job in a queue directory: created on enqueue,
 * removed on dequeue/complete. The three stores are the same engine with
 * three payload shapes, so the engine — grammar, path sanitization, listing,
 * ordering, eviction, minting policy and both constants — lives here once.
 * A store re-declares NONE of it: JsonJobStoreFormatTest's fold census
 * asserts the extension and the bound are absent from the concrete classes,
 * so a future "convenience" copy that could drift away from the engine
 * cannot land quietly.
 *
 * On-disk grammar (pinned byte-for-byte by JsonJobStoreFormatTest, written
 * against the standalone clones BEFORE this fold and unchanged since):
 *
 *   file name : {queueDir}/{preg_replace('/[^a-zA-Z0-9_-]/','_',itemId)}.job.json
 *   payload   : json_encode($payload, JSON_THROW_ON_ERROR) . "\n" .
 *               (string)microtime(true) . "\n"
 *   write     : file_put_contents(..., LOCK_EX); an existing job file is never
 *               rewritten (enqueue is idempotent per item id)
 *   drain     : lowest line-2 float first; the file is removed BEFORE the
 *               payload is parsed, so a job is claimed exactly once; an empty
 *               or corrupt first line dequeues as null after the drop
 *   eviction  : once the listing holds MAX_QUEUE_SIZE files, the enqueue drops
 *               the LEXICOGRAPHICALLY first file (sort() order, list taken
 *               before the newcomer is written) and then writes
 *
 * Directory minting is lazy by default: nothing under the queue directory is
 * touched until the first write. Stores whose constructors mint eagerly do so
 * through their own constructor calling ensureQueueDir(); the base never mints
 * on a read path (S439 zero-residue census law: a resolved-but-never-written
 * store leaves no residue).
 *
 * Payload file modes are the file_put_contents default (the family never
 * chmods; no 0600 exists in this lineage — grep-verified at fold time).
 */
abstract class JsonJobStore
{
    /** Job file extension */
    private const JOB_EXT = '.job.json';

    /** Maximum queue size to prevent unbounded disk usage */
    private const MAX_QUEUE_SIZE = 1000;

    /** @var string Queue directory path */
    protected string $queueDir;

    /**
     * @param string|null $queueDir Optional custom queue directory (null =
     *                              {@see \Phlix\Config\JobQueuePaths::dir()}
     *                              under $queueKey — R14-14: env-tunable,
     *                              defaults under repo var/jobs/, survives
     *                              systemd PrivateTmp)
     * @param string      $queueKey JobQueuePaths name for this store's queue
     */
    protected function __construct(?string $queueDir, string $queueKey)
    {
        $this->queueDir = $queueDir ?? \Phlix\Config\JobQueuePaths::dir($queueKey);
    }

    /**
     * Ensure the queue directory exists (writes call this; reads never do).
     */
    protected function ensureQueueDir(): void
    {
        if (!is_dir($this->queueDir)) {
            mkdir($this->queueDir, 0755, true);
        }
    }

    /**
     * Write one job file unless the item is already queued.
     *
     * Idempotent — re-enqueueing the same item id is a byte-level no-op. At the
     * bound, one lexicographically-first file is evicted before the write.
     *
     * @param string               $itemId  Job item id (sanitized into the name)
     * @param array<string, mixed> $payload Job array as the value object's toArray()
     */
    protected function writeJob(string $itemId, array $payload): void
    {
        $this->ensureQueueDir();
        $jobFile = $this->getJobFilePath($itemId);

        if (file_exists($jobFile)) {
            return;
        }

        // Check queue size and evict oldest if at limit
        $this->evictIfNeeded();

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = microtime(true);
        file_put_contents($jobFile, $json . "\n" . $timestamp . "\n", LOCK_EX);
    }

    /**
     * Claim and remove the oldest queued job, hydrating it through $hydrate.
     *
     * @template T
     * @param \Closure(array<string, mixed>): T $hydrate
     *
     * @return T|null null when the queue is empty or the oldest file is unreadable
     */
    protected function takeOldestJob(\Closure $hydrate): mixed
    {
        $files = $this->getJobFilesSortedByTime();

        if (empty($files)) {
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
            return $hydrate($data);
        } catch (\Throwable) {
            // Corrupt file — skip
            return null;
        }
    }

    /**
     * All queued item ids from the payload line, FIFO by content timestamp;
     * corrupt entries are skipped, not fatal.
     *
     * @return array<string>
     */
    protected function pendingItemIds(): array
    {
        $files = $this->getJobFilesSortedByTime();
        $itemIds = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $lines = explode("\n", trim($content));
            $json = $lines[0] ?? '';
            if ($json === '') {
                continue;
            }

            try {
                /** @var array<string, mixed> $data */
                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                $itemId = $data['item_id'] ?? null;
                if (is_string($itemId) && $itemId !== '') {
                    $itemIds[] = $itemId;
                }
            } catch (\Throwable) {
                // Skip corrupt entries
            }
        }

        return $itemIds;
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
        $files = $this->getJobFiles();

        foreach ($files as $file) {
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
     * @return array<string>
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
     * @return array<string>
     */
    private function getJobFilesSortedByTime(): array
    {
        $files = $this->getJobFiles();

        if (empty($files)) {
            return [];
        }

        usort($files, function (string $a, string $b): int {
            $contentA = file_get_contents($a);
            $contentB = file_get_contents($b);

            $linesA = is_string($contentA) ? explode("\n", trim($contentA)) : [];
            $linesB = is_string($contentB) ? explode("\n", trim($contentB)) : [];

            $timestampA = (float)($linesA[1] ?? 0);
            $timestampB = (float)($linesB[1] ?? 0);

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
            $oldestFile = $files[0];
            $this->removeJobFile($oldestFile);
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
