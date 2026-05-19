<?php

declare(strict_types=1);

namespace Phlex\Media\Markers\Detection;

/**
 * File-based job queue for marker detection.
 *
 * Uses a directory with one lock file per show being processed.
 * Files are created when shows are enqueued and removed when complete.
 *
 * @since 0.12.0
 */
final class MarkerCandidateStore
{
    /** Queue directory path */
    private const QUEUE_DIR = '/tmp/phlex_marker_jobs';

    /** Lock file extension */
    private const LOCK_EXT = '.lock';

    /** @var string Queue directory path */
    private string $queueDir;

    /**
     * Creates a new MarkerCandidateStore.
     *
     * @param string|null $queueDir Optional custom queue directory
     *
     * @since 0.12.0
     */
    public function __construct(?string $queueDir = null)
    {
        $this->queueDir = $queueDir ?? self::QUEUE_DIR;
        $this->ensureQueueDir();
    }

    /**
     * Ensure the queue directory exists.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function ensureQueueDir(): void
    {
        if (!is_dir($this->queueDir)) {
            mkdir($this->queueDir, 0755, true);
        }
    }

    /**
     * Enqueue a show for intro/outro detection.
     *
     * Idempotent - if the show is already enqueued, this is a no-op.
     *
     * @param string $showId The show/series media item ID
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function enqueueShow(string $showId): void
    {
        $lockFile = $this->getLockFilePath($showId);

        if (file_exists($lockFile)) {
            return;
        }

        $timestamp = microtime(true);
        file_put_contents($lockFile, $showId . "\n" . $timestamp . "\n", LOCK_EX);
    }

    /**
     * Dequeue the next show ID from the queue.
     *
     * Returns null if the queue is empty.
     *
     * @return string|null The next show ID or null if queue empty
     *
     * @since 0.12.0
     */
    public function dequeueShow(): ?string
    {
        $files = $this->getQueueFilesSortedByTime();

        if (empty($files)) {
            return null;
        }

        $oldestFile = $files[0];
        $content = file_get_contents($oldestFile);
        if ($content === false) {
            $this->removeLockFile($oldestFile);
            return null;
        }
        $lines = explode("\n", trim($content));
        $showId = $lines[0] ?? '';

        if ($showId === '') {
            $this->removeLockFile($oldestFile);
            return null;
        }

        // Remove the file so we don't dequeue the same item again
        $this->removeLockFile($oldestFile);

        return $showId;
    }

    /**
     * Mark a show's job as complete.
     *
     * Removes the show from the queue.
     *
     * @param string $showId The show/series media item ID
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function completeShow(string $showId): void
    {
        $lockFile = $this->getLockFilePath($showId);

        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }

    /**
     * Get all pending show IDs.
     *
     * @return array<string> Array of pending show IDs
     *
     * @since 0.12.0
     */
    public function getPendingShows(): array
    {
        $files = $this->getQueueFilesSortedByTime();
        $showIds = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $lines = explode("\n", trim($content));
            $showId = $lines[0] ?? '';
            if ($showId !== '') {
                $showIds[] = $showId;
            }
        }

        return $showIds;
    }

    /**
     * Check if a show is enqueued.
     *
     * @param string $showId The show/series media item ID
     *
     * @return bool True if show is in queue
     *
     * @since 0.12.0
     */
    public function isEnqueued(string $showId): bool
    {
        return file_exists($this->getLockFilePath($showId));
    }

    /**
     * Get the queue size.
     *
     * @return int Number of shows in queue
     *
     * @since 0.12.0
     */
    public function queueSize(): int
    {
        return count($this->getQueueFiles());
    }

    /**
     * Clear the entire queue.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function clear(): void
    {
        $files = $this->getQueueFiles();

        foreach ($files as $file) {
            unlink($file);
        }
    }

    /**
     * Get the lock file path for a show.
     *
     * @param string $showId The show/series media item ID
     *
     * @return string Full path to lock file
     *
     * @since 0.12.0
     */
    private function getLockFilePath(string $showId): string
    {
        $sanitizedId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $showId);
        return $this->queueDir . '/' . $sanitizedId . self::LOCK_EXT;
    }

    /**
     * Get all queue lock files sorted by modification time.
     *
     * @return array<string> Array of lock file paths
     *
     * @since 0.12.0
     */
    private function getQueueFiles(): array
    {
        if (!is_dir($this->queueDir)) {
            return [];
        }

        $files = glob($this->queueDir . '/*' . self::LOCK_EXT);

        if ($files === false) {
            return [];
        }

        sort($files);

        return $files;
    }

    /**
     * Get all queue lock files sorted by timestamp in file content.
     *
     * @return array<string> Array of lock file paths in FIFO order
     *
     * @since 0.12.0
     */
    private function getQueueFilesSortedByTime(): array
    {
        $files = $this->getQueueFiles();

        if (empty($files)) {
            return [];
        }

        // Sort by timestamp in file content (second line)
        usort($files, function (string $a, string $b): int {
            $contentA = file_get_contents($a);
            $contentB = file_get_contents($b);

            $linesA = is_string($contentA) ? explode("\n", trim($contentA)) : [];
            $linesB = is_string($contentB) ? explode("\n", trim($contentB)) : [];

            $timestampA = (float)($linesA[1] ?? 0);
            $timestampB = (float)($linesB[1] ?? 0);

            if ($timestampA < $timestampB) {
                return -1;
            }
            if ($timestampA > $timestampB) {
                return 1;
            }
            return 0;
        });

        return $files;
    }

    /**
     * Remove a lock file.
     *
     * @param string $lockFile Lock file path
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function removeLockFile(string $lockFile): void
    {
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }
}
