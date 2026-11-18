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
 *    still match. ctime and inode catch a replace-by-rename. mtime and ctime are whole
 *    seconds, so a same-size in-place rewrite within the same second as the probe leaves all
 *    five unchanged. The racy-stat guard ({@see isRacy()}) covers that: when the file's mtime or
 *    ctime is at most RACY_WINDOW_SECONDS older than the wall clock at probe time, or is ahead of it
 *    by no more than MAX_FUTURE_SKEW_SECONDS, the result is neither persisted nor kept in the
 *    in-process memo, so the next probe runs ffprobe again. A stamp further ahead than that cap is
 *    not racy, and its result is persisted and memoized like an old one. So a racy file is re-probed
 *    on every {@see FfmpegRunner::probe()} call, with or without this cache, until the clock passes
 *    its stamp plus RACY_WINDOW_SECONDS. That is at most MAX_FUTURE_SKEW_SECONDS + RACY_WINDOW_SECONDS
 *    (86 402 s) after the probe. Each probe() call that runs is one ffprobe launch. The per-segment
 *    counts for the software and hwaccel paths are in docs/dev/BLOCKING_IO_EXCEPTIONS.md, Exception 11.
 *    A rewrite after the window cannot leave the stamp unchanged, because the mtime has moved on, so
 *    it is a miss. The residue is a filesystem whose timestamps are 4 seconds or coarser. A non-racy
 *    past stamp is at least RACY_WINDOW_SECONDS + 1 (3) seconds old when it is probed, so a later
 *    rewrite keeps the stamp only if the granularity is above 3 seconds. Another residue is a wall
 *    clock that moves backwards after the probe, so that a rewrite lands on the stored second. The
 *    last residue is the cap itself: a mount whose clock runs ahead of this host by more than
 *    MAX_FUTURE_SKEW_SECONDS stamps its files past the cap, so they are not racy. A same-second
 *    rewrite by that clock keeps the stamp unchanged, and a stale probe can then be served. A plain
 *    write from a host with sane skew cannot reach a stamp that far ahead, so the cap accepts this
 *    risk. No bound on that skew is set in the code or config. The guard also assumes that ctime is
 *    not refreshed on every stat. A mount whose ctime tracks the wall clock on every stat makes every
 *    file racy. Each probe then runs ffprobe, and nothing is memoized or persisted. The result stays
 *    correct, so this costs only the cache. Nothing logs it.
 *  - Trust: the directory is used only when all of these hold. The configured path is
 *    absolute and has no "." or ".." segment, which is refused rather than normalised,
 *    because lstat follows a link through "." (for example /x/link/.). Its trailing
 *    slashes are dropped before the lstat, so a symlink is never followed because of a
 *    slash. The directory is a real directory, not a symlink. It is owned by the effective
 *    uid, which is the identity that writes the entries, and it has no group or other write
 *    bit. The realpath() of the directory, and each ancestor of that realpath up to /, is
 *    not writable by group or other unless it is sticky AND owned by root or by the
 *    effective uid. A sticky directory's owner can rename or unlink the entries in it, so a
 *    sticky ancestor owned by anyone else is refused. A symlinked ancestor is judged by
 *    where it points, and every level of its target is checked as well. That location check
 *    runs once, by {@see createIfSecure()}, at container build. Every read and write
 *    re-checks the directory itself with one lstat, and compares its device and inode with
 *    the pair recorded at build. A directory that was replaced by a new inode is adopted
 *    only if that inode passes the same location and inode checks a fresh build runs. A new
 *    inode that fails them, or a directory that was widened in place, is refused, and the
 *    cache is switched off for the rest of the process with one warning. A failed check
 *    disables the disk cache and the caller probes as it always did.
 *  - Removal: a directory removed while the worker runs is a miss, logged at debug as
 *    missing and not as replaced. The next write recreates it and runs the whole location
 *    check again, including the ancestor walk, before it records the new device and inode.
 *    Another worker that still holds the old identity adopts the new directory on its next
 *    check, if the new inode passes the checks above, and logs once at info. A recreate that
 *    fails the location check switches the cache off with one warning. A recreate whose mkdir
 *    fails is retried no more often than {@see self::RECREATE_BACKOFF_SECONDS}.
 *  - Residual race: PHP has no openat(2), no directory file descriptors and no
 *    O_NOFOLLOW for directories, so the lstat and the later fopen or rename are two path
 *    lookups. A directory swapped inside that window (microseconds) is not closed. The
 *    ancestor check stops another local user from renaming the directory, because a
 *    sticky ancestor owned by root or by this uid only lets its owner rename entries, and
 *    any other writable ancestor is refused. The device and inode comparison catches a swap
 *    that is still in place at the next operation. Root, or the owner of the directory, is
 *    out of scope.
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
     * Racy-stat window, in whole seconds. A file whose mtime or ctime is this many seconds older than
     * the wall clock at probe time, or is ahead of it by no more than {@see MAX_FUTURE_SKEW_SECONDS},
     * is not persisted and is not memoized. See {@see isRacy()}.
     */
    public const RACY_WINDOW_SECONDS = 2;

    /**
     * Largest distance, in whole seconds, that a file timestamp may lie ahead of the wall clock and
     * still be racy. A stamp further ahead than this is not racy: it is persisted and memoized like an
     * old stamp, so it costs no repeat probe.
     *
     * The racy rule exists for a same-second rewrite. Such a rewrite leaves the stored stamp unchanged
     * only when the writer's clock reads the stored second, so the writer's clock must be about as far
     * ahead of this host's as the stamp is. A plain write from a host with sane skew cannot stamp a file
     * a day ahead. An explicit utime restore changes ctime to the restore time, and the ctime term of
     * {@see isRacy()} guards that. So for a stamp far past any plausible skew, the racy rule costs one
     * ffprobe per probe call and protects nothing. One day is well beyond the skew of an NFS or SMB
     * server, and it bounds how long a stamp can stay racy: at most this value plus RACY_WINDOW_SECONDS.
     * The residual risk of this cap is in the class docblock.
     */
    public const MAX_FUTURE_SKEW_SECONDS = 86400;

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

    /**
     * Seconds a failed recreate of a removed directory waits before it is tried again. The first
     * failed attempt logs one warning. Later failed attempts log at debug only, until a recreate
     * succeeds and re-arms the warning.
     */
    public const RECREATE_BACKOFF_SECONDS = 60;

    /** stat() mode bits: the file-type field, the directory type, and the sticky bit. */
    private const S_IFMT = 0170000;
    private const S_IFDIR = 0040000;
    private const S_ISVTX = 01000;

    /** The trust reason while the directory does not exist. It is a removal, not a replacement. */
    private const MISSING = 'directory is missing';

    /** The configured directory, without trailing slashes. Set by the constructor. */
    private readonly string $directory;

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

    /**
     * Why the cache is switched off for this process, or null while it is usable. Set once by
     * {@see latch()} and never cleared, so the refusal is logged once.
     */
    private ?string $latched = null;

    /** Why the path cannot be used at all (a "." or ".." segment), or null. Set once by the constructor. */
    private readonly ?string $pathFault;

    /**
     * The hrtime(true) value before which a failed recreate is not tried again, or null when no
     * backoff is in force. It is a monotonic interval, so hrtime is used, never time().
     */
    private ?int $recreateBackoffUntil = null;

    /**
     * True once a failed recreate has logged its warning. Later failed recreates log at debug, so a
     * permanently failing mkdir does not log a line every backoff window. A successful recreate clears
     * it, so the next outage warns once again.
     */
    private bool $recreateWarned = false;

    /**
     * @param string               $directory   Absolute cache directory (created on first write, mode 0700).
     * @param int                  $maxEntries  Entry cap that {@see prune()} enforces.
     * @param LoggerInterface|null $logger      Receives debug notes for skipped writes.
     * @param int|null             $expectedUid Owner the directory must have. Null = the
     *                                          effective uid (posix_geteuid()), which is the
     *                                          identity that writes the entries. Injectable for tests.
     */
    public function __construct(
        string $directory,
        private readonly int $maxEntries = self::MAX_ENTRIES,
        private readonly ?LoggerInterface $logger = null,
        ?int $expectedUid = null,
    ) {
        $this->directory = rtrim($directory, '/');
        $this->pathFault = self::dotSegmentFailure($this->directory);
        $this->expectedUid = $expectedUid ?? (function_exists('posix_geteuid') ? posix_geteuid() : null);
    }

    /**
     * Build the cache only when its directory passes the trust check. Called once per
     * worker by the container, never per probe. Returns null when the disk cache must be
     * disabled, and logs one warning with the reason. The reasons: the path is relative or is
     * the filesystem root; the path contains a "." or ".." segment; the path cannot be resolved;
     * the directory, or an ancestor of its realpath, is writable by group or other unless it is
     * sticky and owned by root or by the effective uid; the path is a symlink or not a directory;
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
            $normalized = rtrim($directory, '/');
            $uid = $expectedUid ?? (function_exists('posix_geteuid') ? posix_geteuid() : null);
            // The location check runs before anything is created: the nearest existing level is
            // resolved and checked, so the missing levels that mkdir creates sit under a checked path.
            $reason = str_starts_with($directory, '/') && $normalized !== ''
                ? (self::dotSegmentFailure($normalized) ?? self::chainFailure($normalized, $uid))
                : 'the directory path must be absolute and not the filesystem root';
            if ($reason !== null) {
                $logger?->warning('ffprobe disk cache disabled: ' . $reason, ['directory' => $directory]);
                return null;
            }
            if (!is_dir($normalized) && !@mkdir($normalized, 0700, true) && !is_dir($normalized)) {
                $logger?->warning(
                    'ffprobe disk cache disabled: directory is not creatable',
                    ['directory' => $directory]
                );
                return null;
            }
            $cache = new self($normalized, $maxEntries, $logger, $expectedUid);
            // Establishes the identity (device and inode) that every later check compares against.
            $reason = $cache->trustFailure();
            if ($reason !== null) {
                // A refusal of the directory's own inode is already latched and logged by trustFailure().
                if ($cache->latched === null) {
                    $logger?->warning(
                        'ffprobe disk cache disabled: ' . $reason,
                        ['directory' => $directory]
                    );
                }
                return null;
            }

            return $cache;
        } catch (\Throwable $e) {
            $logger?->warning('ffprobe disk cache disabled: ' . $e::class, ['directory' => $directory]);
            return null;
        }
    }

    /**
     * True when a file's mtime or ctime is racy. With age = $now - $stamp, a stamp is racy when
     * age <= {@see self::RACY_WINDOW_SECONDS} AND age >= -{@see self::MAX_FUTURE_SKEW_SECONDS}, that is,
     * when its age lies in the inclusive band from -MAX_FUTURE_SKEW_SECONDS to RACY_WINDOW_SECONDS.
     * So a stamp is racy when it is no more than RACY_WINDOW_SECONDS older than $now, or when it is
     * ahead of $now by no more than MAX_FUTURE_SKEW_SECONDS. A stamp further ahead than the cap is not
     * racy. A racy stamp can be
     * rewritten in place without changing its size, mtime, ctime or inode, so its probe result must
     * not be stored or memoized, and each probe of it runs ffprobe again. The cap and its residual
     * risk are described in the class docblock. The caller passes time(): this compares wall-clock
     * file timestamps, which the hrtime() rule for elapsed intervals does not cover.
     */
    public static function isRacy(int $mtime, int $ctime, int $now): bool
    {
        return ($now - $mtime <= self::RACY_WINDOW_SECONDS && $now - $mtime >= -self::MAX_FUTURE_SKEW_SECONDS)
            || ($now - $ctime <= self::RACY_WINDOW_SECONDS && $now - $ctime >= -self::MAX_FUTURE_SKEW_SECONDS);
    }

    /**
     * The probe result stored for this file, or null on any miss.
     *
     * @return array{streams: array<int, array<string, mixed>>, format: array<string, mixed>}|null
     */
    public function read(string $realPath, int $size, int $mtime, int $ctime, int $inode): ?array
    {
        try {
            $reason = $this->trustFailure();
            if ($reason !== null) {
                // A removed directory is a miss. It is logged here at debug, not only at the next write.
                if ($reason === self::MISSING) {
                    $this->skip('probe cache directory is missing, so this read is a miss');
                }
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
            $reason = $this->trustFailure();
            if ($reason === self::MISSING) {
                $reason = $this->recreate();
            }
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
     * caller logs or skips on. A missing directory returns {@see self::MISSING}, which the
     * caller treats as a removal. The checks, in order: the path is absolute and is not the
     * filesystem root; it has no "." or ".." segment; the posix extension is loaded; the path
     * exists (lstat, so a symlink is seen as itself); the device and inode are those recorded
     * when trust was first established (a changed pair is adopted by {@see adoptReplacement()}
     * or latches the cache off); it is a real directory; it is owned by the
     * expected uid; and it has no group or other write bit. An instance built without
     * {@see createIfSecure()} records its identity at its first check. A refusal of the directory's
     * own inode, including a widening in place, is latched by {@see latch()}, so it is logged once.
     */
    private function trustFailure(): ?string
    {
        if ($this->latched !== null) {
            return $this->latched;
        }
        if ($this->directory === '' || $this->directory[0] !== '/') {
            return 'the directory path must be absolute and not the filesystem root';
        }
        if ($this->pathFault !== null) {
            return $this->pathFault;
        }
        if ($this->expectedUid === null) {
            return 'the posix extension is not loaded, so the owner cannot be checked';
        }
        // PHP keeps the last stat() result per path. Without this, a re-check of the same
        // directory in a long-lived worker could return the mode from before a chmod.
        clearstatcache(true, $this->directory);
        $st = @lstat($this->directory);
        if ($st === false) {
            return self::MISSING;
        }
        if ($this->identity === null) {
            $this->identity = ['dev' => $st['dev'], 'ino' => $st['ino']];
        } elseif ($st['dev'] !== $this->identity['dev'] || $st['ino'] !== $this->identity['ino']) {
            // A new inode at the path: adopted only if it passes the checks a fresh build runs.
            $reason = $this->adoptReplacement($st);
            if ($reason !== null) {
                return $this->latch('directory was replaced after the trust check: ' . $reason);
            }
        }

        // The same inode can be widened in place (for example a group-write bit added). That refusal is
        // latched too, so the cache stays off for the process and is logged once, not silently skipped.
        $reason = $this->inodeFailure($st);

        return $reason === null ? null : $this->latch($reason);
    }

    /**
     * The checks on the directory's own inode: a real directory, owned by the expected uid, with
     * no group or other write bit. Null when all hold, otherwise the reason. The location is
     * checked by {@see chainFailure()}, not here.
     *
     * @param array<int|string, int> $st The lstat() result of the directory.
     */
    private function inodeFailure(array $st): ?string
    {
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
     * The directory at this path has a new device and inode, because it was recreated (by another
     * worker, or after a removal this instance did not see). It is adopted only if the location is
     * trusted and its inode passes {@see inodeFailure()}: the same checks a fresh build runs. So a
     * cache only trusts a directory that passes them at the moment of adoption.
     *
     * @param array<int|string, int> $st The lstat() result of the new directory.
     *
     * @return string|null Null when it is adopted, otherwise the reason it is refused.
     */
    private function adoptReplacement(array $st): ?string
    {
        $reason = self::chainFailure($this->directory, $this->expectedUid) ?? $this->inodeFailure($st);
        if ($reason !== null) {
            return $reason;
        }
        $this->identity = ['dev' => $st['dev'], 'ino' => $st['ino']];
        $this->logger?->info('ffprobe disk cache directory was recreated; adopted', ['directory' => $this->directory]);

        return null;
    }

    /**
     * Null when the location of $directory is trusted, otherwise the reason it is not. The
     * nearest existing level of the path is resolved with realpath(). That level, and each
     * ancestor of it up to /, must not be writable by group or other unless it is sticky AND owned
     * by root or by $uid.
     * The levels below it do not exist yet, so mkdir creates them private, under a level that
     * was just checked. Resolving first is what judges a symlinked ancestor by its target, and
     * checks the target's own parents too.
     */
    private static function chainFailure(string $directory, ?int $uid): ?string
    {
        if ($uid === null) {
            return 'the posix extension is not loaded, so the owner cannot be checked';
        }
        $existing = self::nearestExisting($directory);
        $real = $existing === null ? false : realpath($existing);
        if ($real === false) {
            return 'the directory path cannot be resolved';
        }
        $unsafe = self::unsafeAncestorOf($real, $uid);
        if ($unsafe !== null) {
            return 'a directory on the path is writable by group or other unless it has the sticky bit'
                . ' and is owned by root or this user: ' . $unsafe;
        }

        return null;
    }

    /**
     * Null when no segment of $path is "." or "..", otherwise the refusal reason. A dot segment is
     * refused, not normalised: lstat follows a link through "." (for example /x/link/.), so the
     * symlink refusal would otherwise be skipped.
     */
    private static function dotSegmentFailure(string $path): ?string
    {
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return 'the directory path must not contain a "." or ".." segment';
            }
        }

        return null;
    }

    /**
     * The deepest existing level of $path, found with lstat, or null when none exists.
     */
    private static function nearestExisting(string $path): ?string
    {
        $current = $path;
        while (@lstat($current) === false) {
            $parent = dirname($current);
            if ($parent === $current) {
                return null;
            }
            $current = $parent;
        }

        return $current;
    }

    /**
     * The first directory, starting at $real (an absolute, resolved path) and walking up to /,
     * that is writable by group or other unless it is sticky and owned by root or by $uid, or null
     * when none is. A level
     * that cannot be stat'ed is treated as unsafe, so an unknown level is never trusted.
     */
    private static function unsafeAncestorOf(string $real, int $uid): ?string
    {
        $current = $real;
        while (true) {
            clearstatcache(true, $current);
            $st = @stat($current);
            if ($st === false) {
                return $current;
            }
            // A sticky directory's owner can rename or unlink entries in it, so a sticky ancestor is
            // accepted only when root or this uid owns it. Any other writable ancestor is refused.
            $writable = ($st['mode'] & 0022) !== 0;
            $stickyOwnedSafely = ($st['mode'] & self::S_ISVTX) !== 0 && ($st['uid'] === 0 || $st['uid'] === $uid);
            if ($writable && !$stickyOwnedSafely) {
                return $current;
            }
            $parent = dirname($current);
            if ($parent === $current) {
                return null;
            }
            $current = $parent;
        }
    }

    /**
     * Rebuilds a directory that was removed while the worker ran. The location is checked again
     * in full, with {@see chainFailure()}, before the directory is created private (0700). Its
     * device and inode are then recorded afresh, and the normal check runs on the new directory.
     * A location that the check refuses, or a new directory that fails the check, switches the
     * cache off with one warning. A failed mkdir is a skip, and the next recreate is held back for
     * {@see self::RECREATE_BACKOFF_SECONDS}. The first failed mkdir logs one warning, and later failed
     * attempts log at debug, until a recreate succeeds. A success re-arms the warning for the next outage.
     *
     * @return string|null The refusal reason, or null when the directory is back and trusted.
     */
    private function recreate(): ?string
    {
        // After a failed mkdir, the same path is not walked and created again on every probe miss.
        $now = hrtime(true);
        if ($this->recreateBackoffUntil !== null && is_int($now) && $now < $this->recreateBackoffUntil) {
            return 'directory is missing and the recreate is backing off';
        }
        $wasTrusted = $this->identity !== null;
        $reason = self::chainFailure($this->directory, $this->expectedUid);
        if ($reason !== null) {
            return $this->latch($reason);
        }
        if (!@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            $this->recreateBackoffUntil = (is_int($now) ? $now : 0)
                + self::RECREATE_BACKOFF_SECONDS * 1_000_000_000;
            if ($this->recreateWarned) {
                $this->logger?->debug(
                    'ffprobe disk cache directory is still missing and could not be recreated',
                    ['directory' => $this->directory]
                );
            } else {
                $this->recreateWarned = true;
                $this->logger?->warning(
                    'ffprobe disk cache directory is missing and could not be recreated; retrying in '
                    . self::RECREATE_BACKOFF_SECONDS . ' s',
                    ['directory' => $this->directory]
                );
            }
            return 'directory is missing and could not be recreated';
        }
        $this->recreateBackoffUntil = null;
        $this->recreateWarned = false;
        $this->identity = null;
        $reason = $this->trustFailure();
        if ($reason !== null) {
            // trustFailure() has already latched and logged an inode refusal; any other reason is latched here.
            return $this->latched ?? $this->latch($reason);
        }
        if ($wasTrusted) {
            $this->logger?->warning(
                'ffprobe disk cache directory was removed while the worker ran; '
                . 'it was recreated and checked again',
                ['directory' => $this->directory]
            );
        }

        return null;
    }

    /**
     * Switches the cache off for the rest of this process and logs the reason once. Later
     * calls return the same reason without logging it again.
     */
    private function latch(string $reason): string
    {
        $this->latched = $reason;
        $this->logger?->warning('ffprobe disk cache disabled: ' . $reason, ['directory' => $this->directory]);

        return $reason;
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
