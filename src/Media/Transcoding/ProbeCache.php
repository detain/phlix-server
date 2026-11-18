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
 *  - Trust: the directory is used only when all of these hold. It is a real directory
 *    (not a symlink). It is owned by the effective uid, which is the identity that
 *    writes the entries. It has no group or other write bit. Each ancestor directory,
 *    walked up to /, is not writable by group or other unless it has the sticky bit.
 *    That chain is checked once, by {@see createIfSecure()}, at container build. Every
 *    read and write re-checks the directory itself with one lstat, and compares its
 *    device and inode with the pair recorded at build. A directory that was replaced
 *    or widened is refused, and the cache is switched off for the rest of the process
 *    with one warning. A failed check disables the disk cache and the caller probes as
 *    it always did.
 *  - Residual race: PHP has no openat(2), no directory file descriptors and no
 *    O_NOFOLLOW for directories, so the lstat and the later fopen or rename are two path
 *    lookups. A directory swapped inside that window (microseconds) is not closed. The
 *    ancestor check stops another local user from renaming the directory, because a
 *    sticky ancestor only lets a file's owner rename it, and a non-sticky writable
 *    ancestor is refused. The device and inode comparison catches a swap that is still
 *    in place at the next operation. Root, or the owner of the directory, is out of scope.
 *  - Writes are atomic and private: the entry is written to a sibling `.tmp` file that
 *    is chmod'ed 0600 before any byte is written (ffprobe tags can carry personal
 *    metadata), then renamed into place, so a reader never observes a partial file.
 *  - Bounded: an entry over {@see self::MAX_ENTRY_BYTES} is not stored. Pruning is not
 *    on the probe path: {@see startPruneTimer()} runs {@see pruneIfDue()} on the Workerman
 *    event loop. Every worker shares the directory, so {@see pruneIfDue()} takes a
 *    non-blocking lock and a TTL before it scans, and one pass runs per interval across
 *    all workers. Each pass is bounded and stops at the first failed unlink, so a
 *    failing unlink never causes a rescan loop.
 *  - Never fatal: every I/O failure (unwritable directory, corrupt JSON, a race with
 *    another worker's rename) is a cache miss. The caller then probes as it always
 *    did, so an unusable cache directory degrades to the pre-#11 behaviour and
 *    never fails a request.
 *
 * Local small-file reads and writes only: no network, no subprocess. The probe that
 * fills the cache is still run by {@see FfmpegRunner} through its coroutine-aware path.
 * The remaining blocking I/O (one stat, one read of at most 64 KiB, one write of at
 * most 64 KiB per successful probe, and the prune lock file) is accepted in
 * docs/dev/BLOCKING_IO_EXCEPTIONS.md.
 */
final class ProbeCache
{
    /** Bumped when the on-disk entry shape changes; a mismatched version is a miss. */
    public const FORMAT_VERSION = 2;

    /** Largest entry (bytes of encoded JSON) stored. Larger probes are simply not cached. */
    public const MAX_ENTRY_BYTES = 65536;

    /**
     * Entry-count cap. A prune pass evicts only while the count is above this cap, and once
     * above it evicts down to 90 % of the cap. A directory at or below the cap is left alone.
     */
    public const MAX_ENTRIES = 10000;

    /**
     * Directory names read per prune pass (readdir bound). Above this many names, the
     * pass sees only the first PRUNE_SCAN_LIMIT names in readdir order, so eviction is not
     * a global oldest-first. It converges over successive ticks, because each pass removes
     * the oldest of the names it sees and the next pass reads further.
     */
    public const PRUNE_SCAN_LIMIT = 20000;

    /** Files unlinked per prune pass. A failed unlink ends the pass; the next tick retries. */
    public const PRUNE_DELETE_LIMIT = 1000;

    /** A leftover `.tmp` (a crash between write and rename) older than this is removed. */
    public const STALE_TMP_SECONDS = 600;

    /** Default period of the background prune timer, in seconds. */
    public const PRUNE_INTERVAL_SECONDS = 300;

    /** Lock and TTL file that makes one worker per interval prune the shared directory. */
    public const PRUNE_LOCK_FILE = '.prune.lock';

    /** stat() mode bits: the file-type field, the directory type, and the sticky bit. */
    private const S_IFMT = 0170000;
    private const S_IFDIR = 0040000;
    private const S_ISVTX = 01000;

    /** The uid the directory must be owned by, resolved once per instance; null = untrusted. */
    private ?int $expectedUid;

    /** Timer id of the prune timer, or null while none is scheduled for this instance. */
    private ?int $pruneTimerId = null;

    /**
     * Device and inode of the directory, recorded when trust is first established (by
     * {@see createIfSecure()}), and compared on every later check. Null until then.
     *
     * @var array{dev: int, ino: int}|null
     */
    private ?array $identity = null;

    /** True once the directory's identity changed. The cache stays off for this process. */
    private bool $replaced = false;

    /**
     * @param string               $directory   Cache directory (created on first write, mode 0700).
     * @param int                  $maxEntries  Entry cap that {@see prune()} enforces.
     * @param LoggerInterface|null $logger      Receives debug notes for skipped writes.
     * @param int|null             $expectedUid Owner the directory must have. Null = the
     *                                          effective uid (posix_geteuid()), which is the
     *                                          identity that writes the entries. Injectable for tests.
     */
    public function __construct(
        private readonly string $directory,
        private readonly int $maxEntries = self::MAX_ENTRIES,
        private readonly ?LoggerInterface $logger = null,
        ?int $expectedUid = null,
    ) {
        $this->expectedUid = $expectedUid ?? (function_exists('posix_geteuid') ? posix_geteuid() : null);
    }

    /**
     * Build the cache only when its directory passes the trust check. Called once per
     * worker by the container, never per probe. Returns null when the disk cache must be
     * disabled, and logs one warning with the reason. The reasons: an ancestor is writable
     * by group or other without the sticky bit; the path is a symlink or not a directory;
     * it is not owned by the effective uid; it has a group or other write bit; or the posix
     * extension is missing, so ownership cannot be checked.
     */
    public static function createIfSecure(
        string $directory,
        int $maxEntries = self::MAX_ENTRIES,
        ?LoggerInterface $logger = null,
        ?int $expectedUid = null,
    ): ?self {
        try {
            $unsafeAncestor = self::unsafeAncestorOf($directory);
            if ($unsafeAncestor !== null) {
                $logger?->warning(
                    'ffprobe disk cache disabled: an ancestor directory is writable by group or other '
                    . 'without the sticky bit',
                    ['directory' => $directory, 'ancestor' => $unsafeAncestor]
                );
                return null;
            }
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                $logger?->warning(
                    'ffprobe disk cache disabled: directory is not creatable',
                    ['directory' => $directory]
                );
                return null;
            }
            $cache = new self($directory, $maxEntries, $logger, $expectedUid);
            // Establishes the identity (device and inode) that every later check compares against.
            $reason = $cache->trustFailure();
            if ($reason !== null) {
                $logger?->warning(
                    'ffprobe disk cache disabled: ' . $reason,
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
            if ($this->trustFailure() !== null) {
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
            $reason = $this->trustFailure();
            if ($reason !== null) {
                $this->skip('probe cache directory is not trusted: ' . $reason);
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
     * Schedule {@see pruneIfDue()} on the Workerman event loop, once per instance, so pruning
     * never runs on a probe request. Outside a running worker (CLI, unit tests)
     * Timer::add() throws; that is caught and this process simply does not prune.
     */
    public function startPruneTimer(int $intervalSeconds = self::PRUNE_INTERVAL_SECONDS): void
    {
        if ($this->pruneTimerId !== null) {
            return;
        }
        try {
            $this->pruneTimerId = Timer::add($intervalSeconds, function () use ($intervalSeconds): void {
                try {
                    $this->pruneIfDue($intervalSeconds);
                } catch (\Throwable $e) {
                    $this->logger?->debug('ffprobe cache prune failed', ['exception' => $e::class]);
                }
            });
        } catch (\Throwable $e) {
            $this->logger?->debug('ffprobe cache prune timer not started', ['exception' => $e::class]);
        }
    }

    /**
     * Runs {@see prune()} for this directory, unless another worker holds the prune lock or
     * pruned the directory within the last half interval. Every worker has its own timer and
     * all of them share one directory, so without this the scan repeats once per worker. The
     * lock is a non-blocking flock on {@see self::PRUNE_LOCK_FILE}, and that file's mtime is
     * the TTL. The lock is held only for the synchronous pass, and it is released with the handle.
     *
     * @return int Number of files removed; 0 when this call skipped the pass.
     */
    public function pruneIfDue(int $intervalSeconds = self::PRUNE_INTERVAL_SECONDS): int
    {
        if ($this->trustFailure() !== null) {
            return 0;
        }
        $lock = $this->directory . '/' . self::PRUNE_LOCK_FILE;
        // A lock file created by this call has no earlier pass to honour, so the TTL applies only
        // to a file that already existed (its mtime is the last pass).
        $existed = is_file($lock);
        $handle = @fopen($lock, 'c');
        if ($handle === false) {
            return 0;
        }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return 0;
            }
            clearstatcache(true, $lock);
            $last = $existed ? @filemtime($lock) : false;
            if (is_int($last) && time() - $last < intdiv($intervalSeconds, 2)) {
                return 0;
            }
            @touch($lock);

            return $this->prune();
        } finally {
            fclose($handle);
        }
    }

    /**
     * One bounded maintenance pass. It returns 0 at once when the directory fails the trust
     * check. Otherwise it removes stale `.tmp` files, then, only while the `.json` count is
     * above the cap, the oldest entries down to 90 % of the cap. It reads at most
     * {@see self::PRUNE_SCAN_LIMIT} names, in readdir order. Above that count the eviction is
     * therefore not a global oldest-first: it sees only those names, and it converges over
     * successive ticks. It unlinks at most {@see self::PRUNE_DELETE_LIMIT} files. The first
     * failed unlink, whether of a stale temp or of an entry, ends the pass at once: there is
     * no further unlink, no eviction and no rescan, and the next tick retries.
     * File ages compare against wall-clock file mtimes, so a monotonic clock does not apply.
     *
     * @return int Number of files removed.
     */
    public function prune(): int
    {
        if ($this->trustFailure() !== null) {
            return 0;
        }
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
            if ($removed >= self::PRUNE_DELETE_LIMIT) {
                return $removed;
            }
            if (!@unlink($tmp)) {
                return $removed;
            }
            $removed++;
        }

        if (count($entries) > $this->maxEntries) {
            asort($entries);
            $keep = (int) floor($this->maxEntries * 0.9);
            $excess = min(count($entries) - $keep, self::PRUNE_DELETE_LIMIT - $removed);
            foreach (array_slice(array_keys($entries), 0, max(0, $excess)) as $oldest) {
                if (!@unlink($oldest)) {
                    return $removed;
                }
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Null when the directory passes every check. Otherwise the reason it does not, which the
     * caller logs or skips on. The checks: a real directory (lstat, so a symlink is refused),
     * owned by the expected uid, no group or other write bit, and the same device and inode
     * as when trust was first established. An instance built without {@see createIfSecure()}
     * records its identity at its first check. A changed identity switches the cache off for
     * this process and logs one warning.
     */
    private function trustFailure(): ?string
    {
        if ($this->replaced) {
            return 'directory was replaced after the trust check';
        }
        if ($this->expectedUid === null) {
            return 'the posix extension is not loaded, so the owner cannot be checked';
        }
        // PHP keeps the last stat() result per path. Without this, a re-check of the same
        // directory in a long-lived worker could return the mode from before a chmod.
        clearstatcache(true, $this->directory);
        $st = @lstat($this->directory);
        if ($st === false) {
            return 'directory does not exist';
        }
        // The identity comparison runs before the type, owner and mode checks, so a swapped
        // path is always reported as a replacement, even when the new one would also fail.
        if ($this->identity === null) {
            $this->identity = ['dev' => $st['dev'], 'ino' => $st['ino']];
        } elseif ($st['dev'] !== $this->identity['dev'] || $st['ino'] !== $this->identity['ino']) {
            $this->replaced = true;
            $this->logger?->warning(
                'ffprobe disk cache disabled: directory was replaced after the trust check',
                ['directory' => $this->directory]
            );

            return 'directory was replaced after the trust check';
        }
        if (($st['mode'] & self::S_IFMT) !== self::S_IFDIR) {
            return 'path is a symlink or not a directory';
        }
        if ($st['uid'] !== $this->expectedUid) {
            return 'directory is not owned by this user';
        }
        if (($st['mode'] & 0022) !== 0) {
            return 'directory is writable by group or other';
        }

        return null;
    }

    /**
     * The first ancestor of $directory, walking up to /, that is writable by group or other
     * without the sticky bit, or null when none is. An ancestor is checked with stat(), so a
     * symlinked ancestor is judged by its target. A missing ancestor is skipped, because the
     * cache creates the missing levels itself, private, with mkdir(0700, recursive).
     */
    private static function unsafeAncestorOf(string $directory): ?string
    {
        $current = dirname($directory);
        while (true) {
            $st = @stat($current);
            if (
                $st !== false
                && ($st['mode'] & 0022) !== 0
                && ($st['mode'] & self::S_ISVTX) === 0
            ) {
                return $current;
            }
            $parent = dirname($current);
            if ($parent === $current) {
                return null;
            }
            $current = $parent;
        }
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
