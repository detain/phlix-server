<?php

/**
 * Phlix media server component: Media Asset.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\MediaAsset;

/**
 * File-based job queue for media asset (chapter-thumbnail + trickplay) generation.
 *
 * Uses a directory with one JSON file per job. Files are created when jobs
 * are enqueued and removed when complete. FIFO ordering by file mtime.
 *
 * @since 0.36.0
 */
final class MediaAssetJobStore
{
    /** Queue directory path */
    private const QUEUE_DIR = '/tmp/phlix_media_asset_jobs';

    /** Job file extension */
    private const JOB_EXT = '.job.json';

    /** @var string Queue directory path */
    private string $queueDir;

    /**
     * @param string|null $queueDir Optional custom queue directory
     */
    public function __construct(?string $queueDir = null)
    {
        $this->queueDir = $queueDir ?? self::QUEUE_DIR;
        $this->ensureQueueDir();
    }

    /**
     * Ensure the queue directory exists.
     */
    private function ensureQueueDir(): void
    {
        if (!is_dir($this->queueDir)) {
            mkdir($this->queueDir, 0755, true);
        }
    }

    /**
     * Enqueue a media asset generation job.
     *
     * Idempotent - if the item is already enqueued, this is a no-op.
     *
     * @param MediaAssetJob $job The job to enqueue
     */
    public function enqueue(MediaAssetJob $job): void
    {
        $jobFile = $this->getJobFilePath($job->itemId);

        if (file_exists($jobFile)) {
            return;
        }

        $json = json_encode($job->toArray(), JSON_THROW_ON_ERROR);
        $timestamp = microtime(true);
        file_put_contents($jobFile, $json . "\n" . $timestamp . "\n", LOCK_EX);
    }

    /**
     * Dequeue the next job from the queue.
     *
     * Returns null if the queue is empty.
     *
     * @return MediaAssetJob|null The next job or null if queue empty
     */
    public function dequeue(): ?MediaAssetJob
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
            return MediaAssetJob::fromArray($data);
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
     * Get all pending job item IDs.
     *
     * @return array<string>
     */
    public function getPendingItemIds(): array
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
     * Get all job files.
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
