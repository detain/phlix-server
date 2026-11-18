<?php

/**
 * Phlix media server component: Transcoding.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\Transcoding;

use Psr\Log\LoggerInterface;
use Workerman\Timer;

/**
 * Persisted, cross-worker cache of ffprobe results (perf #11).
 *
 * {@see FfmpegRunner::probe()} memoizes in process memory only, so each of the
 * resident HTTP workers re-probed every file once and the memo was lost on restart.
 * This class keeps the same result on disk: one small JSON file per media path,
 * under a configured directory, shared by every worker and process that points at it.
 *
 * Contract:
 *  - Key: the realpath. The file is `sha1(realpath).json`; the entry also records the
 *    realpath, size, mtime, ctime and inode, and a read is a hit only when all five
 *    still match. ctime and inode are there because mtime is whole seconds: a
 *    same-size rewrite within one second, or a replace-by-rename, is a miss.
 *  - Trust: the directory is used only when it is a real directory (not a symlink),
 *    owned by the process uid, and has no group or other write bit. Another local user
 *    therefore cannot plant or replace entries. {@see createIfSecure()} checks this once
 *    at container build; every read and write re-checks it with one lstat, so a
 *    directory swapped after build is refused too. A failed check disables the disk
 *    cache and the caller probes as it always did.
 *  - Writes are atomic and private: the entry is written to a sibling `.tmp` file that
 *    is chmod'ed 0600 before any byte is written (ffprobe tags can carry personal
 *    metadata), then renamed into place, so a reader never observes a partial file.
 *  - Bounded: an entry over {@see self::MAX_ENTRY_BYTES} is not stored. Pruning is not
 *    on the probe path: {@see startPruneTimer()} runs {@see prune()} on the Workerman
 *    event loop, and each pass is bounded (scan, delete and a stop at the first failed
 *    unlink, so a failing unlink never causes a rescan loop).
 *  - Never fatal: every I/O failure (unwritable directory, corrupt JSON, a race with
 *    another worker's rename) is a cache miss. The caller then probes as it always
 *    did, so an unusable cache directory degrades to the pre-#11 behaviour and
 *    never fails a request.
 *
 * Local small-file reads and writes only: no network, no subprocess. The probe that
 * fills the cache is still run by {@see FfmpegRunner} through its coroutine-aware path.
 * The remaining blocking I/O (one stat, one read of at most 64 KiB, and one write of
 * at most 64 KiB per successful probe) is accepted in docs/dev/BLOCKING_IO_EXCEPTIONS.md.
 */
final class ProbeCache
{
    /** Bumped when the on-disk entry shape changes; a mismatched version is a miss. */
    public const FORMAT_VERSION = 2;

    /** Largest entry (bytes of encoded JSON) stored. Larger probes are simply not cached. */
    public const MAX_ENTRY_BYTES = 65536;

    /** Upper bound on cached entries; pruning removes the oldest down to 90 % of it. */
    public const MAX_ENTRIES = 10000;

    /** Directory names examined per prune pass (readdir bound). */
    public const PRUNE_SCAN_LIMIT = 20000;

    /** Files unlinked per prune pass. A failed unlink ends the pass; the next tick retries. */
    public const PRUNE_DELETE_LIMIT = 1000;

    /** A leftover `.tmp` (a crash between write and rename) older than this is removed. */
    public const STALE_TMP_SECONDS = 600;

    /** Default period of the background prune timer, in seconds. */
    public const PRUNE_INTERVAL_SECONDS = 300;

    /** stat() mode bits: the file-type field and the directory type. */
    private const S_IFMT = 0170000;
    private const S_IFDIR = 0040000;

    /** The uid the directory must be owned by, resolved once per instance; null = untrusted. */
    private ?int $expectedUid;

    /** Timer id of the prune timer, or null while none is scheduled for this instance. */
    private ?int $pruneTimerId = null;

    /**
     * @param string               $directory   Cache directory (created on first write, mode 0700).
     * @param int                  $maxEntries  Entry cap that {@see prune()} enforces.
     * @param LoggerInterface|null $logger      Receives debug notes for skipped writes.
     * @param int|null             $expectedUid Owner the directory must have. Null = the
     *                                          process uid (posix_getuid()). Injectable for tests.
     */
    public function __construct(
        private readonly string $directory,
        private readonly int $maxEntries = self::MAX_ENTRIES,
        private readonly ?LoggerInterface $logger = null,
        ?int $expectedUid = null,
    ) {
        $this->expectedUid = $expectedUid ?? (function_exists('posix_getuid') ? posix_getuid() : null);
    }

    /**
     * Build the cache only when its directory passes the trust check. Called once per
     * worker by the container, never per probe. Returns null when the disk cache must be
     * disabled: the path is a symlink or not a directory, it is not owned by the process
     * uid, or it has a group or other write bit. Logs one warning when it refuses.
     */
    public static function createIfSecure(
        string $directory,
        int $maxEntries = self::MAX_ENTRIES,
        ?LoggerInterface $logger = null,
        ?int $expectedUid = null,
    ): ?self {
        try {
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                $logger?->warning(
                    'ffprobe disk cache disabled: directory is not creatable',
                    ['directory' => $directory]
                );
                return null;
            }
            $cache = new self($directory, $maxEntries, $logger, $expectedUid);
            if (!$cache->directoryIsTrusted()) {
                $logger?->warning(
                    'ffprobe disk cache disabled: directory must be a private directory owned by this user',
                    ['directory' => $directory]
                );
                return null;
            }

            return $cache;
        } catch (\Throwable $e) {
            $logger?->warning('ffprobe disk cache disabled: ' . $e::class, ['directory' => $directory]);
            return null;
        }
    }

    /**
     * The probe result stored for this file, or null on any miss.
     *
     * @return array{streams: array<int, array<string, mixed>>, format: array<string, mixed>}|null
     */
    public function read(string $realPath, int $size, int $mtime, int $ctime, int $inode): ?array
    {
        try {
            if (!$this->directoryIsTrusted()) {
                return null;
            }
            $file = $this->fileFor($realPath);
            if (!is_file($file)) {
                return null;
            }
            $bytes = @filesize($file);
            if (!is_int($bytes) || $bytes <= 0 || $bytes > self::MAX_ENTRY_BYTES) {
                return null;
            }
            $raw = @file_get_contents($file);
            if (!is_string($raw)) {
                return null;
            }

            $data = json_decode($raw, true);
            if (
                !is_array($data)
                || ($data['v'] ?? null) !== self::FORMAT_VERSION
                || ($data['path'] ?? null) !== $realPath
                || ($data['size'] ?? null) !== $size
                || ($data['mtime'] ?? null) !== $mtime
                || ($data['ctime'] ?? null) !== $ctime
                || ($data['ino'] ?? null) !== $inode
            ) {
                return null;
            }

            $probe = $data['probe'] ?? null;
            if (
                !is_array($probe)
                || !is_array($probe['streams'] ?? null)
                || !is_array($probe['format'] ?? null)
            ) {
                return null;
            }

            // Re-normalise to the string-keyed shape FfmpegRunner::probe() returns, so a
            // hit is indistinguishable from a live probe.
            $streams = [];
            foreach ($probe['streams'] as $stream) {
                if (!is_array($stream)) {
                    continue;
                }
                $normalized = [];
                foreach ($stream as $key => $value) {
                    if (is_string($key)) {
                        $normalized[$key] = $value;
                    }
                }
                $streams[] = $normalized;
            }

            $format = [];
            foreach ($probe['format'] as $key => $value) {
                if (is_string($key)) {
                    $format[$key] = $value;
                }
            }

            return ['streams' => $streams, 'format' => $format];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Store the probe result for this file. Silently a no-op when it cannot be stored.
     *
     * @param array{streams: array<int, array<string, mixed>>, format: array<string, mixed>} $probe
     */
    public function write(string $realPath, int $size, int $mtime, int $ctime, int $inode, array $probe): void
    {
        try {
            if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
                $this->skip('probe cache directory is not creatable');
                return;
            }
            if (!$this->directoryIsTrusted()) {
                $this->skip('probe cache directory is not trusted');
                return;
            }

            $payload = json_encode(
                [
                    'v' => self::FORMAT_VERSION,
                    'path' => $realPath,
                    'size' => $size,
                    'mtime' => $mtime,
                    'ctime' => $ctime,
                    'ino' => $inode,
                    'probe' => $probe,
                ],
                JSON_UNESCAPED_SLASHES,
            );
            if (!is_string($payload)) {
                $this->skip('probe result is not JSON-encodable');
                return;
            }
            if (strlen($payload) > self::MAX_ENTRY_BYTES) {
                $this->skip('probe result exceeds the cache entry bound');
                return;
            }

            $final = $this->fileFor($realPath);
            $tmp = $this->directory . '/.' . basename($final) . '.' . bin2hex(random_bytes(4)) . '.tmp';
            // Create the file empty, restrict it, and only then write the payload. The
            // process umask is never changed, so no other coroutine's file is affected,
            // and the private mode is in place before any personal metadata is written.
            $handle = @fopen($tmp, 'xb');
            if ($handle === false) {
                $this->skip('probe cache temp file could not be created');
                return;
            }
            if (!@chmod($tmp, 0600)) {
                fclose($handle);
                @unlink($tmp);
                $this->skip('probe cache temp file could not be restricted');
                return;
            }
            $written = @fwrite($handle, $payload);
            $closed = fclose($handle);
            if ($written !== strlen($payload) || $closed !== true) {
                @unlink($tmp);
                $this->skip('probe cache temp write failed');
                return;
            }
            if (!@rename($tmp, $final)) {
                @unlink($tmp);
                $this->skip('probe cache rename failed');
            }
        } catch (\Throwable $e) {
            $this->skip('probe cache write raised ' . $e::class);
        }
    }

    /**
     * Schedule {@see prune()} on the Workerman event loop, once per instance, so pruning
     * never runs on a probe request. Outside a running worker (CLI, unit tests)
     * Timer::add() throws; that is caught and this process simply does not prune.
     */
    public function startPruneTimer(int $intervalSeconds = self::PRUNE_INTERVAL_SECONDS): void
    {
        if ($this->pruneTimerId !== null) {
            return;
        }
        try {
            $this->pruneTimerId = Timer::add($intervalSeconds, function (): void {
                try {
                    $this->prune();
                } catch (\Throwable $e) {
                    $this->logger?->debug('ffprobe cache prune failed', ['exception' => $e::class]);
                }
            });
        } catch (\Throwable $e) {
            $this->logger?->debug('ffprobe cache prune timer not started', ['exception' => $e::class]);
        }
    }

    /**
     * One bounded maintenance pass. Removes stale `.tmp` files, then the oldest `.json`
     * entries down to 90 % of the cap. Examines at most {@see self::PRUNE_SCAN_LIMIT}
     * names and unlinks at most {@see self::PRUNE_DELETE_LIMIT} files. The first failed
     * unlink ends the pass: there is no rescan within a pass, and the next tick retries.
     * File ages compare against wall-clock file mtimes, so a monotonic clock does not apply.
     *
     * @return int Number of files removed.
     */
    public function prune(): int
    {
        $dh = @opendir($this->directory);
        if ($dh === false) {
            return 0;
        }

        $entries = [];
        $staleTmp = [];
        $cutoff = time() - self::STALE_TMP_SECONDS;
        $scanned = 0;
        while ($scanned < self::PRUNE_SCAN_LIMIT && ($name = readdir($dh)) !== false) {
            $scanned++;
            $path = $this->directory . '/' . $name;
            if (str_ends_with($name, '.json')) {
                $mtime = @filemtime($path);
                $entries[$path] = is_int($mtime) ? $mtime : 0;
            } elseif (str_ends_with($name, '.tmp')) {
                $mtime = @filemtime($path);
                if (is_int($mtime) && $mtime < $cutoff) {
                    $staleTmp[] = $path;
                }
            }
        }
        closedir($dh);

        $removed = 0;
        foreach ($staleTmp as $tmp) {
            if ($removed >= self::PRUNE_DELETE_LIMIT || !@unlink($tmp)) {
                break;
            }
            $removed++;
        }

        if (count($entries) > $this->maxEntries) {
            asort($entries);
            $keep = (int) floor($this->maxEntries * 0.9);
            $excess = min(count($entries) - $keep, self::PRUNE_DELETE_LIMIT - $removed);
            foreach (array_slice(array_keys($entries), 0, $excess) as $oldest) {
                if (!@unlink($oldest)) {
                    break;
                }
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * True when the directory is a real directory (lstat, so a symlink is refused), owned
     * by the expected uid, and free of group and other write bits.
     */
    private function directoryIsTrusted(): bool
    {
        if ($this->expectedUid === null) {
            return false;
        }
        // PHP keeps the last stat() result per path. Without this, a re-check of the same
        // directory in a long-lived worker could return the mode from before a chmod.
        clearstatcache(true, $this->directory);
        $st = @lstat($this->directory);
        if ($st === false) {
            return false;
        }

        return ($st['mode'] & self::S_IFMT) === self::S_IFDIR
            && ($st['mode'] & 0022) === 0
            && $st['uid'] === $this->expectedUid;
    }

    /** Absolute path of the entry for a realpath. */
    private function fileFor(string $realPath): string
    {
        return $this->directory . '/' . sha1($realPath) . '.json';
    }

    private function skip(string $reason): void
    {
        $this->logger?->debug('ffprobe cache skipped: ' . $reason);
    }
}
