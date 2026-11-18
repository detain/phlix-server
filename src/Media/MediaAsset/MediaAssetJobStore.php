<?php

/**
 * Phlix media server component: Media Asset.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\MediaAsset;

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;
use Psr\Log\LoggerInterface;

/**
 * File-based job queue for media asset (chapter-thumbnail + trickplay) generation,
 * and for background full-track-set probes of legacy items ({@see StreamProbeJob}).
 *
 * Uses a directory with one JSON file per job. Files are created when jobs
 * are enqueued and removed when complete. Chapter/trickplay jobs are FIFO by file mtime.
 *
 * Two job KINDS share the directory, told apart by file-name suffix so they never
 * collide for the same item id:
 * - `<itemId>.job.json` — chapter/trickplay generation ({@see enqueue()}, {@see dequeue()}).
 * - `<20-digit-microseconds>-<itemId>.streams.job.json` — stream probe ({@see enqueueStreamProbe()},
 *   {@see dequeueStreamProbe()}). The zero-padded enqueue timestamp makes lexical order equal enqueue
 *   order, so the oldest pending job is always the first name in the listing.
 * The item id part is percent-encoded (see {@see sanitizeId()}): every byte outside `[A-Za-z0-9-]`
 * becomes `%XX`, so distinct ids (for example `a.b` and `a_b`) never share a file name. A dot never
 * appears in the id part, so the suffix alone identifies the kind.
 *
 * Concurrency for the stream kind:
 * - Enqueue is multi-writer (playback requests and the worker's retry path). The CHECK and the WRITE run
 *   under the dot-named `.streams.lock`, taken with a NON-blocking flock that is retried for at most
 *   {@see self::LOCK_BUDGET_MS}. On contention it throws with code {@see self::ERROR_LOCK_BUSY}; the
 *   playback caller drops the enqueue (the next playback re-enqueues it) and a retry is kept pending.
 * - Every directory listing runs BEFORE the lock is taken. Under the lock, a listed name is checked with
 *   file_exists() on its exact path, and a retry removes the exact listed names. No listing runs under the lock.
 * - A per-item PENDING SENTINEL (`.pending-<itemId>.streams`, dot-named) marks that a first-attempt job is
 *   pending, so a playback enqueue checks one file. A fresh sentinel (younger than
 *   {@see self::SENTINEL_TTL_SECONDS}) means pending and returns without taking the lock. A stale sentinel
 *   triggers one unlocked listing for the item. An absent sentinel means no pending job and no listing.
 * - Dequeue is single-drainer (the MediaAssetWorker timer). Writes are rename-atomic: every stream job
 *   file is written to a dot-named temp file and rename()d into place, so a reader never sees a partial
 *   file under a name the glob matches.
 *
 * @since 0.36.0
 */
final class MediaAssetJobStore
{
    /** Job file extension */
    private const JOB_EXT = '.job.json';

    /** Stream-probe job file extension (second kind in the same directory) */
    private const STREAM_JOB_EXT = '.streams.job.json';

    /**
     * Temp-file extension for an in-progress stream-probe write. Neither glob matches it
     * (leading dot, and not `*.job.json`).
     */
    private const STREAM_TMP_EXT = '.tmp';

    /** Dot-named lock file that serialises stream-probe enqueues across processes. No glob matches it. */
    private const STREAM_LOCK_NAME = '.streams.lock';

    /** Dot-named per-item pending sentinel prefix. No `*.job.json` glob matches it. */
    private const SENTINEL_PREFIX = '.pending-';

    /** Pending sentinel suffix (the part after the sanitised item id). */
    private const SENTINEL_EXT = '.streams';

    /**
     * Shape of a stream-probe job file name: a 20-digit zero-padded enqueue timestamp in microseconds,
     * a hyphen, the sanitised item id, then STREAM_JOB_EXT. Group 1 is the sanitised item id.
     */
    private const STREAM_NAME_RE = '/^\d{20}-([a-zA-Z0-9%-]+)\.streams\.job\.json$/';

    /**
     * Stream-probe scan window: the number of oldest names a dequeue call considers. Names are sorted
     * lexically, so the window always holds the oldest pending jobs. See {@see dequeueStreamProbe()}.
     */
    public const STREAM_SCAN_CAP = 512;

    /**
     * Corrupt stream-probe files removed per dequeue call before the call gives up until the next tick.
     */
    public const STREAM_CORRUPT_SKIP_BUDGET = 16;

    /**
     * Exception code for "the stream-probe queue lock stayed busy for the whole budget". The enqueue was
     * not written. Callers match on this code.
     */
    public const ERROR_LOCK_BUSY = 4101;

    /** Budget, in milliseconds, for taking the stream-probe lock before giving up. */
    public const LOCK_BUDGET_MS = 10;

    /** Pause between non-blocking lock attempts, in microseconds (at most 2 ms). */
    private const LOCK_RETRY_SLEEP_US = 1000;

    /**
     * Age, in seconds, after which a pending sentinel is no longer trusted on its own. A stale sentinel
     * triggers a listing for the item, so a job beyond the window is still found.
     */
    public const SENTINEL_TTL_SECONDS = 3600;

    /** @var string Queue directory path */
    private string $queueDir;

    /** @var \Closure(string, string): (int|false) Writes a temp file. Test seam; default is file_put_contents. */
    private \Closure $fileWriter;

    /** @var \Closure(string, string): bool Moves the temp file into place. Test seam; default is rename. */
    private \Closure $fileRenamer;

    /** @var \Closure(string): (string|false) Reads a stream-probe job file. Test seam; default is file_get_contents. */
    private \Closure $fileReader;

    /**
     * @var (\Closure(string, string): void)|null Called before each file mutation with (event, path). Events are
     *      `write` (temp file), `rename` (final name), `touch` (sentinel) and `unlink`. Test seam for ordering only.
     */
    private ?\Closure $observer;

    /** @var LoggerInterface|null Built on first use, only on the failure path that logs */
    private ?LoggerInterface $logger = null;

    /**
     * @param string|null $queueDir   Optional custom queue directory (null =
     *                                {@see \Phlix\Config\JobQueuePaths::dir()}
     *                                'media_asset' — R14-14: env-tunable,
     *                                defaults under repo var/jobs/, no longer
     *                                /tmp, so the backlog survives systemd
     *                                PrivateTmp).
     * @param \Closure|null $fileWriter  Optional seam for writing a stream-probe temp file
     *                                   (path, contents): int|false. Null uses file_put_contents.
     * @param \Closure|null $fileRenamer Optional seam for moving a temp file into place
     *                                   (from, to): bool. Null uses rename.
     * @param \Closure|null $fileReader  Optional seam for reading a stream-probe job file
     *                                   (path): string|false. Null uses file_get_contents.
     * @param \Closure|null $observer    Optional seam, called with (event, path) before each file mutation.
     *                                   Null records nothing.
     */
    public function __construct(
        ?string $queueDir = null,
        ?\Closure $fileWriter = null,
        ?\Closure $fileRenamer = null,
        ?\Closure $fileReader = null,
        ?\Closure $observer = null,
    ) {
        $this->queueDir = $queueDir ?? \Phlix\Config\JobQueuePaths::dir('media_asset');
        $this->fileWriter = $fileWriter
            ?? static fn (string $path, string $data): int|false => @file_put_contents($path, $data);
        $this->fileRenamer = $fileRenamer
            ?? static fn (string $from, string $to): bool => @rename($from, $to);
        $this->fileReader = $fileReader
            ?? static fn (string $path): string|false => @file_get_contents($path);
        $this->observer = $observer;
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
     * Enqueue a background stream probe for a legacy item.
     *
     * The job is written as `<20-digit-microseconds>-<itemId>.streams.job.json` through a dot-named temp
     * file and a rename(), so the name only appears once the file is complete. The timestamp is taken at
     * write time, so a job's queue position is its enqueue time. The pending sentinel is written AFTER the
     * job file, so a sentinel never points at a job that was not written.
     *
     * Locking: the check and the write run under the `.streams.lock` flock, which is taken without blocking
     * and retried for at most {@see self::LOCK_BUDGET_MS}. Every directory listing runs before the lock is
     * taken, so the locked section is O(1) apart from the write itself. Under the lock, a listed name is
     * checked with file_exists() on its exact path (never re-listed), and a retry removes only the exact
     * names listed before the lock.
     *
     * Behaviour is keyed on the job's attempt count:
     * - A FIRST attempt (attempts 0, from a playback request) is idempotent per item:
     *   - A FRESH sentinel means a job is pending. The call returns before taking the lock and writes nothing.
     *   - An ABSENT sentinel means no job is pending. The call takes the lock, rechecks the sentinel, and writes.
     *     No directory scan runs, so a job whose sentinel was never written (a crash between the job write and
     *     the sentinel write, or a job written before sentinels existed) is not seen. The next playback then
     *     writes a duplicate. The worker's re-check makes that duplicate a no-op: one extra queue file, no extra
     *     probe.
     *   - A STALE sentinel is not trusted. The item's pending files are listed BEFORE the lock. Under the lock,
     *     any listed file that still exists means the job is pending, so the sentinel is refreshed and nothing is
     *     written. Otherwise the job is written. This scan is the only listing on the playback path, and it runs
     *     at most once per item per {@see self::SENTINEL_TTL_SECONDS} while a job is pending.
     *   A playback enqueue never resets a re-queued job's attempt count.
     * - A RETRY (attempts above 0, written by the worker after a failed run) lists the item's pending files
     *   BEFORE the lock. Under the lock it removes exactly those names, then writes the job under a NEW timestamp
     *   prefix, so it goes to the BACK of the queue and carries the incremented count. The original prefix is NOT
     *   kept: keeping it would put a failing item at the front and retry it back to back. If a listed file cannot
     *   be removed, the call throws and writes nothing. A file that a playback wrote AFTER the listing is not
     *   removed, so two pending files for one item are possible in that narrow window. Both are harmless: the
     *   worker's re-check makes the second run a no-op.
     *
     * It never probes and never reads the media file.
     *
     * @param StreamProbeJob $job The job to enqueue
     *
     * @throws \RuntimeException With code {@see self::ERROR_LOCK_BUSY} when the lock stayed busy for the
     *                           whole budget (nothing was written). Otherwise when the lock file, the job
     *                           file, the sentinel, or a listed pending file cannot be written or removed.
     *                           The scheduler swallows it; the worker keeps a busy retry pending and logs
     *                           the others.
     */
    public function enqueueStreamProbe(StreamProbeJob $job): void
    {
        $sanitizedId = $this->sanitizeId($job->itemId);
        $firstAttempt = $job->attempts === 0;

        // Unlocked: decide whether a listing is needed. Nothing is written here.
        $listed = [];
        if ($firstAttempt) {
            if ($this->sentinelIsFresh($sanitizedId)) {
                return;
            }
            if ($this->sentinelExists($sanitizedId)) {
                $listed = $this->streamJobFilesForItem($job->itemId);
            }
        } else {
            $listed = $this->streamJobFilesForItem($job->itemId);
        }

        $lock = $this->acquireStreamLock($job->itemId);

        try {
            if ($firstAttempt) {
                if ($this->sentinelIsFresh($sanitizedId)) {
                    return;
                }
                if (array_filter($listed, 'file_exists') !== []) {
                    // A listed job still exists, so it is pending: refresh the sentinel, write nothing.
                    $this->touchSentinel($sanitizedId);
                    return;
                }
            } else {
                $this->removeStreamJobFilesForRetry($listed, $job->itemId);
            }

            $jobFile = $this->writeStreamJobFile($job);
            $this->markSentinel($sanitizedId, $jobFile);
        } finally {
            $this->releaseStreamLock($lock);
        }
    }

    /**
     * Dequeue the oldest stream-probe job, removing its file.
     *
     * "Oldest" is the first name in lexical order. Every name starts with a fixed-width microsecond
     * timestamp, so lexical order is enqueue order. Ties at the same microsecond fall back to item id.
     *
     * Work per call:
     * - One flat, names-only directory listing. The call keeps the {@see self::STREAM_SCAN_CAP} (512) oldest
     *   names with a bounded selection, and never sorts the whole backlog. The listing is O(backlog) per call,
     *   accepted as one flat names-only read.
     * - The call then walks that window in order. For each file it reads the file and then removes it. The first
     *   file that yields a valid job is returned. A corrupt or junk file is removed and counted against
     *   {@see self::STREAM_CORRUPT_SKIP_BUDGET}. An UNREADABLE file (an I/O error, not corrupt content) is left
     *   in place and skipped for this call, with a warning, so a transient read failure does not lose the job.
     *   The rest wait for the next tick.
     * - A retry is re-enqueued with a new, newest prefix (see {@see enqueueStreamProbe()}), so it never
     *   jumps ahead of jobs already waiting.
     * - Each removed valid-named file also removes its item's pending sentinel, after the file itself.
     *
     * Returns null when no valid stream-probe job is left in the window, or when the skip budget ran out
     * on corrupt files.
     */
    public function dequeueStreamProbe(): ?StreamProbeJob
    {
        $window = $this->oldestStreamJobFiles(self::STREAM_SCAN_CAP);

        $examined = 0;
        foreach ($window as $file) {
            if ($examined >= self::STREAM_CORRUPT_SKIP_BUDGET) {
                break;
            }
            $examined++;

            if (preg_match(self::STREAM_NAME_RE, basename($file)) !== 1) {
                // Not in the stream-probe name shape: junk, so remove it and count it against the budget.
                $this->removeStreamJobFile($file);
                continue;
            }

            $content = ($this->fileReader)($file);
            if ($content === false) {
                // Unreadable (I/O error) or gone since the listing. A file that is still there stays for a later call.
                if (file_exists($file)) {
                    $this->log()->warning(
                        'MediaAssetJobStore: a stream-probe job file could not be read, left for a later call',
                        ['file' => basename($file)],
                    );
                }
                continue;
            }

            $data = $this->decodeFirstJsonLine($content);

            // Corrupt content: remove the file either way, so the same job is not dequeued twice and a corrupt
            // file never blocks the queue.
            $this->removeStreamJobFile($file);

            if ($data === null) {
                continue;
            }

            try {
                return StreamProbeJob::fromArray($data);
            } catch (\Throwable) {
                // Valid JSON but not a stream-probe job: treat it as corrupt and try the next file.
                continue;
            }
        }

        return null;
    }

    /**
     * Whether a stream-probe job is pending for the item, at any queue position.
     *
     * @param string $itemId The media item ID
     */
    public function isStreamProbeEnqueued(string $itemId): bool
    {
        return $this->streamJobFilesForItem($itemId) !== [];
    }

    /**
     * Number of pending stream-probe jobs (the trickplay/chapter count is {@see queueSize()}).
     */
    public function streamProbeQueueSize(): int
    {
        return count($this->listStreamJobFiles());
    }

    /**
     * Get the queue size.
     *
     * Counts the chapter/trickplay kind only; stream-probe jobs are counted by
     * {@see streamProbeQueueSize()}.
     *
     * @return int Number of jobs in queue
     */
    public function queueSize(): int
    {
        return count($this->getJobFiles());
    }

    /**
     * Clear the entire queue, both kinds, and every pending sentinel.
     */
    public function clear(): void
    {
        $sentinels = glob($this->queueDir . '/' . self::SENTINEL_PREFIX . '*' . self::SENTINEL_EXT) ?: [];
        $files = array_merge($this->getJobFiles(), $this->listStreamJobFiles(), $sentinels);

        foreach ($files as $file) {
            @unlink($file);
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
     * Encode an item id for use in a stream-probe file name, injectively.
     *
     * Every byte outside `[a-zA-Z0-9-]` is written as `%XX` (uppercase hex), including `%` itself. Distinct
     * ids therefore give distinct names: `a.b` becomes `a%2Eb` and `a_b` becomes `a%5Fb`. The output contains
     * only `[a-zA-Z0-9%-]`, so it has no glob metacharacters.
     *
     * @param string $itemId The media item ID
     */
    private function sanitizeId(string $itemId): string
    {
        return preg_replace_callback(
            '/[^a-zA-Z0-9-]/',
            static fn (array $match): string => sprintf('%%%02X', ord($match[0])),
            $itemId,
        ) ?? '';
    }

    /**
     * Path of the per-item pending sentinel. It is dot-named, so no glob of the job kinds matches it.
     *
     * @param string $sanitizedId The sanitised item id
     */
    private function sentinelPath(string $sanitizedId): string
    {
        return $this->queueDir . '/' . self::SENTINEL_PREFIX . $sanitizedId . self::SENTINEL_EXT;
    }

    /**
     * Whether the item's pending sentinel exists and is younger than {@see self::SENTINEL_TTL_SECONDS}.
     *
     * One stat, no listing. A fresh sentinel means a first-attempt job is pending.
     *
     * @param string $sanitizedId The sanitised item id
     */
    private function sentinelIsFresh(string $sanitizedId): bool
    {
        $sentinel = $this->sentinelPath($sanitizedId);
        clearstatcache(true, $sentinel);
        $mtime = @filemtime($sentinel);

        return $mtime !== false && time() - $mtime < self::SENTINEL_TTL_SECONDS;
    }

    /**
     * Whether the item's pending sentinel exists at all, fresh or stale. One stat.
     *
     * @param string $sanitizedId The sanitised item id
     */
    private function sentinelExists(string $sanitizedId): bool
    {
        return file_exists($this->sentinelPath($sanitizedId));
    }

    /**
     * Every pending stream-probe job file for the item, at any queue position.
     *
     * Scans the whole directory listing by name (no reads, no stat), so a pending job beyond the window is
     * found. It is called only before the stream lock is taken (see {@see enqueueStreamProbe()}). The glob
     * pattern contains only the sanitised id, which has no glob metacharacters. A name is kept only when its
     * id group matches exactly, so `a-b` does not match the id `b`.
     *
     * @param string $itemId The media item ID
     *
     * @return array<string>
     */
    private function streamJobFilesForItem(string $itemId): array
    {
        if (!is_dir($this->queueDir)) {
            return [];
        }

        $sanitizedId = $this->sanitizeId($itemId);
        $files = glob($this->queueDir . '/*-' . $sanitizedId . self::STREAM_JOB_EXT, GLOB_NOSORT);

        if ($files === false) {
            return [];
        }

        return array_values(array_filter(
            $files,
            static function (string $file) use ($sanitizedId): bool {
                $match = [];
                return preg_match(self::STREAM_NAME_RE, basename($file), $match) === 1
                    && $match[1] === $sanitizedId;
            },
        ));
    }

    /**
     * Removes the pending files a retry replaces. Runs under the lock, on the exact names listed before it.
     * A file that cannot be removed and still exists fails the retry: a warning is logged and nothing is written,
     * so the retry does not add a second pending file beside an unremovable one.
     *
     * @param array<string> $files  The pending stream-probe files listed for the item before the lock
     * @param string        $itemId The media item ID (for the log line)
     *
     * @throws \RuntimeException When a listed file still exists after the removal attempt.
     */
    private function removeStreamJobFilesForRetry(array $files, string $itemId): void
    {
        foreach ($files as $stale) {
            $this->observe('unlink', $stale);
            if (@unlink($stale) || !file_exists($stale)) {
                continue;
            }

            $this->log()->warning(
                'MediaAssetJobStore: a stale stream-probe job could not be removed, retry not written',
                [
                    'item_id' => $itemId,
                    'file' => basename($stale),
                ],
            );

            throw new \RuntimeException(
                'MediaAssetJobStore: could not remove a stale stream-probe job [itemId=' . $itemId . ']',
            );
        }
    }

    /**
     * Microseconds since the epoch, used as the sortable enqueue stamp.
     *
     * This is a wall-clock ordering key, not an interval, so microtime is correct here. The stamp is a
     * 16-digit value today and is zero-padded to the 20-digit field.
     */
    private function enqueueStamp(): int
    {
        return (int) round(microtime(true) * 1_000_000);
    }

    /**
     * Write one stream-probe job file: temp file first, then rename() into its final, sortable name.
     *
     * Runs under the stream lock (see {@see enqueueStreamProbe()}). A failed write or rename removes the
     * temp file and throws, so no partial file is left under a name the glob matches.
     *
     * @return string The final path of the job file
     *
     * @throws \RuntimeException When the temp file cannot be written or moved into place.
     */
    private function writeStreamJobFile(StreamProbeJob $job): string
    {
        $json = json_encode($job->toArray(), JSON_THROW_ON_ERROR);
        $final = sprintf(
            '%s/%020d-%s%s',
            $this->queueDir,
            $this->enqueueStamp(),
            $this->sanitizeId($job->itemId),
            self::STREAM_JOB_EXT,
        );
        $temp = sprintf('%s/.%s%s', $this->queueDir, bin2hex(random_bytes(8)), self::STREAM_TMP_EXT);

        $this->observe('write', $temp);
        $written = ($this->fileWriter)($temp, $json . "\n" . microtime(true) . "\n");
        if ($written === false) {
            $this->unlinkQuietly($temp);
            throw new \RuntimeException('MediaAssetJobStore: could not write a stream-probe job file');
        }

        $this->observe('rename', $final);
        if (!($this->fileRenamer)($temp, $final)) {
            $this->unlinkQuietly($temp);
            throw new \RuntimeException('MediaAssetJobStore: could not move a stream-probe job file into place');
        }

        return $final;
    }

    /**
     * Refreshes the item's pending sentinel when a listed job is still pending. Runs under the lock.
     *
     * @param string $sanitizedId The sanitised item id
     *
     * @throws \RuntimeException When the sentinel cannot be written. The pending job is left as it is.
     */
    private function touchSentinel(string $sanitizedId): void
    {
        $sentinel = $this->sentinelPath($sanitizedId);
        $this->observe('touch', $sentinel);
        if (!@touch($sentinel)) {
            throw new \RuntimeException(
                'MediaAssetJobStore: could not refresh the pending sentinel of a stream-probe job'
            );
        }
    }

    /**
     * Creates or refreshes the item's pending sentinel, after its job file is in place. Runs under the lock.
     *
     * If the sentinel cannot be written, the job file just written is removed as well, and the enqueue
     * throws. A sentinel is never left behind without the job it describes.
     *
     * @param string $sanitizedId The sanitised item id
     * @param string $jobFile     The job file written for the item
     *
     * @throws \RuntimeException When the sentinel cannot be written.
     */
    private function markSentinel(string $sanitizedId, string $jobFile): void
    {
        $sentinel = $this->sentinelPath($sanitizedId);
        $this->observe('touch', $sentinel);
        if (@touch($sentinel)) {
            return;
        }

        $this->unlinkQuietly($jobFile);
        $this->unlinkQuietly($sentinel);

        throw new \RuntimeException('MediaAssetJobStore: could not mark a pending stream-probe job');
    }

    /**
     * Take the stream-queue lock without blocking, retrying for at most {@see self::LOCK_BUDGET_MS}.
     *
     * The wait is at most the budget plus one pause of {@see self::LOCK_RETRY_SLEEP_US}. On contention the
     * handle is closed, nothing is written, and the exception carries {@see self::ERROR_LOCK_BUSY} and the
     * item id, so the log names the dropped enqueue.
     *
     * @return resource The locked handle; pass it to {@see releaseStreamLock()}
     *
     * @throws \RuntimeException When the lock file cannot be opened, or stays locked for the whole budget.
     */
    private function acquireStreamLock(string $itemId)
    {
        $handle = @fopen($this->queueDir . '/' . self::STREAM_LOCK_NAME, 'c');
        if ($handle === false) {
            throw new \RuntimeException('MediaAssetJobStore: could not open the stream-probe lock file');
        }

        $deadline = hrtime(true) + self::LOCK_BUDGET_MS * 1_000_000;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (hrtime(true) >= $deadline) {
                fclose($handle);
                throw new \RuntimeException(
                    'MediaAssetJobStore: stream-probe queue lock busy, enqueue not written [itemId=' . $itemId . ']',
                    self::ERROR_LOCK_BUSY,
                );
            }

            usleep(self::LOCK_RETRY_SLEEP_US);
        }

        return $handle;
    }

    /**
     * Release the stream-queue lock and close its handle.
     *
     * @param resource $handle The handle returned by {@see acquireStreamLock()}
     */
    private function releaseStreamLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Removes one stream-probe file, then the pending sentinel of its item when the name has the stream shape.
     *
     * The file goes first, so a crash between the two leaves a sentinel with no job, which the TTL bounds.
     *
     * @param string $file A stream-probe job file path, or a junk file
     */
    private function removeStreamJobFile(string $file): void
    {
        $this->unlinkQuietly($file);

        $match = [];
        if (preg_match(self::STREAM_NAME_RE, basename($file), $match) === 1) {
            $this->unlinkQuietly($this->sentinelPath($match[1]));
        }
    }

    /**
     * Unlinks one stream-kind path, silently, after reporting it to the observer.
     *
     * @param string $path A job, temp or sentinel path
     */
    private function unlinkQuietly(string $path): void
    {
        $this->observe('unlink', $path);
        @unlink($path);
    }

    /**
     * Reports one mutation to the optional observer. A no-op unless a test seam is set.
     *
     * @param string $event `write`, `rename`, `touch` or `unlink`
     * @param string $path  The path the mutation targets
     */
    private function observe(string $event, string $path): void
    {
        if ($this->observer !== null) {
            ($this->observer)($event, $path);
        }
    }

    /**
     * Decodes the JSON payload on the first line of a job file's content.
     *
     * @return array<string, mixed>|null Null when the content is empty, not JSON, or not a JSON object
     */
    private function decodeFirstJsonLine(string $content): ?array
    {
        $lines = explode("\n", trim($content));
        $json = $lines[0] ?? '';
        if ($json === '') {
            return null;
        }

        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return is_array($data) ? $data : null;
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

        // `*.job.json` also matches the stream-probe kind; keep only the chapter/trickplay kind.
        $files = array_values(array_filter(
            $files,
            static fn (string $file): bool => !str_ends_with($file, self::STREAM_JOB_EXT),
        ));

        sort($files);

        return $files;
    }

    /**
     * Every stream-probe job file name, unsorted. One flat names-only glob. Used for the count and the clear, which
     * need every name, and as the input to {@see oldestStreamJobFiles()}.
     *
     * @return array<string>
     */
    private function listStreamJobFiles(): array
    {
        if (!is_dir($this->queueDir)) {
            return [];
        }

        $files = glob($this->queueDir . '/*' . self::STREAM_JOB_EXT, GLOB_NOSORT);

        return $files === false ? [] : $files;
    }

    /**
     * The `$count` oldest stream-probe job names, oldest first, without sorting the whole listing.
     *
     * A max-heap of at most `$count` names keeps the smallest names seen so far. Each name is compared as a
     * string, so the fixed-width timestamp prefix orders them chronologically. Work is O(backlog * log $count),
     * and memory is O($count) beyond the listing itself.
     *
     * @param int $count The window size
     *
     * @return array<string>
     */
    private function oldestStreamJobFiles(int $count): array
    {
        // Largest name on top: extract() then drops the newest name whenever the heap holds more than $count.
        $heap = new class extends \SplHeap {
            protected function compare(mixed $value1, mixed $value2): int
            {
                if (!is_string($value1) || !is_string($value2)) {
                    throw new \LogicException('MediaAssetJobStore: the stream-job heap holds file names only');
                }
                return strcmp($value1, $value2);
            }
        };

        foreach ($this->listStreamJobFiles() as $file) {
            $heap->insert($file);
            if ($heap->count() > $count) {
                $heap->extract();
            }
        }

        $oldest = [];
        foreach ($heap as $file) {
            if (!is_string($file)) {
                throw new \LogicException('MediaAssetJobStore: the stream-job heap holds file names only');
            }
            $oldest[] = $file;
        }

        return array_reverse($oldest);
    }

    /**
     * Get all job files sorted by timestamp in file content (FIFO order).
     *
     * @return array<string>
     */
    private function getJobFilesSortedByTime(): array
    {
        return $this->sortByTimestamp($this->getJobFiles());
    }

    /**
     * Sorts job files oldest first by filemtime (FIFO order), ties broken by filename.
     *
     * Stat only: each file is stat'ed once, and the sort compares the cached integers, so no job
     * file is read here. A file that vanishes between the glob and the stat is dropped.
     * filemtime() has one-second resolution, so jobs written within the same second keep filename order.
     *
     * @param array<string> $files
     *
     * @return array<string>
     */
    private function sortByTimestamp(array $files): array
    {
        if ($files === []) {
            return [];
        }

        clearstatcache(true);

        /** @var array<string, int> $mtimes */
        $mtimes = [];
        foreach ($files as $file) {
            // @ silences the warning for a file removed since the glob; that file is then dropped.
            $mtime = @filemtime($file);
            if ($mtime !== false) {
                $mtimes[$file] = $mtime;
            }
        }

        $sorted = array_keys($mtimes);
        usort($sorted, static function (string $a, string $b) use ($mtimes): int {
            $byTime = $mtimes[$a] <=> $mtimes[$b];

            return $byTime !== 0 ? $byTime : strcmp($a, $b);
        });

        return $sorted;
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

    /**
     * The MEDIA-channel logger, built on first use. Only the failure paths that log use it.
     */
    private function log(): LoggerInterface
    {
        return $this->logger ??= LoggerFactory::get(LogChannels::MEDIA);
    }
}
