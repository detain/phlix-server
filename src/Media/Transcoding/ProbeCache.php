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
 *    realpath, size and mtime, and a read is a hit only when all three still match.
 *    A changed file is therefore a miss and is re-probed, and the stale entry is
 *    overwritten on the next successful probe. Nothing else invalidates it.
 *  - Writes are atomic: the entry is written to a sibling `.tmp` file (mode 0600,
 *    since ffprobe tags can carry personal metadata) and renamed into place, so a
 *    reader never observes a partial file.
 *  - Bounded: an entry over {@see self::MAX_ENTRY_BYTES} is not stored, and the
 *    directory is pruned oldest-first down to {@see self::MAX_ENTRIES}.
 *  - Never fatal: every I/O failure (unwritable directory, corrupt JSON, a race with
 *    another worker's rename) is a cache miss. The caller then probes as it always
 *    did, so an unusable cache directory degrades to the pre-#11 behaviour and
 *    never fails a request.
 *
 * Local small-file reads and writes only: no network, no subprocess. The probe that
 * fills the cache is still run by {@see FfmpegRunner} through its coroutine-aware path.
 */
final class ProbeCache
{
    /** Bumped when the on-disk entry shape changes; a mismatched version is a miss. */
    public const FORMAT_VERSION = 1;

    /** Largest entry (bytes of encoded JSON) stored. Larger probes are simply not cached. */
    public const MAX_ENTRY_BYTES = 65536;

    /** Upper bound on cached entries; pruning removes the oldest down to 90 % of it. */
    public const MAX_ENTRIES = 10000;

    /** Prune scans the directory once per this many successful writes (cheap amortisation). */
    private const PRUNE_EVERY_WRITES = 64;

    /** Writes since the last directory scan. Per-instance, never static. */
    private int $writesSinceScan = 0;

    /** stat() mode bits: the file-type field and the directory type. */
    private const S_IFMT = 0170000;
    private const S_IFDIR = 0040000;

    /** The uid the directory must be owned by, resolved once per instance; null = untrusted. */
    private ?int $expectedUid;

    /**
     * @param string               $directory  Cache directory (created on first write, mode 0700).
     * @param int                  $maxEntries Entry cap that {@see prune()} enforces.
     * @param LoggerInterface|null $logger     Receives debug notes for skipped writes.
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
                $logger?->warning('ffprobe disk cache disabled: directory is not creatable', ['directory' => $directory]);
                return null;
            }
            $cache = new self($directory, $maxEntries, $logger, $expectedUid);
            if (!$cache->directoryIsTrusted()) {
                $logger?->warning(
                    'ffprobe disk cache disabled: directory must be a real directory owned by this user with no group or other write bit',
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
    public function read(string $realPath, int $size, int $mtime): ?array
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
    public function write(string $realPath, int $size, int $mtime, array $probe): void
    {
        try {
            if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
                $this->skip('probe cache directory is not creatable', $realPath);
                return;
            }
            if (!$this->directoryIsTrusted()) {
                $this->skip('probe cache directory is not trusted', $realPath);
                return;
            }

            $payload = json_encode(
                [
                    'v' => self::FORMAT_VERSION,
                    'path' => $realPath,
                    'size' => $size,
                    'mtime' => $mtime,
                    'probe' => $probe,
                ],
                JSON_UNESCAPED_SLASHES,
            );
            if (!is_string($payload)) {
                $this->skip('probe result is not JSON-encodable', $realPath);
                return;
            }
            if (strlen($payload) > self::MAX_ENTRY_BYTES) {
                $this->skip('probe result exceeds the cache entry bound', $realPath);
                return;
            }

            $final = $this->fileFor($realPath);
            $tmp = $this->directory . '/.' . basename($final) . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $payload) === false) {
                $this->skip('probe cache temp write failed', $realPath);
                return;
            }
            @chmod($tmp, 0600);
            if (!@rename($tmp, $final)) {
                @unlink($tmp);
                $this->skip('probe cache rename failed', $realPath);
                return;
            }

            $this->writesSinceScan++;
            if ($this->writesSinceScan >= self::PRUNE_EVERY_WRITES) {
                $this->writesSinceScan = 0;
                $this->prune();
            }
        } catch (\Throwable $e) {
            $this->skip('probe cache write raised ' . $e::class, $realPath);
        }
    }

    /**
     * Remove the oldest entries (by mtime) until at most 90 % of the entry cap remain.
     * Called automatically every {@see self::PRUNE_EVERY_WRITES} writes; public so the
     * bound can be asserted directly.
     */
    public function prune(): void
    {
        $entries = glob($this->directory . '/*.json');
        if ($entries === false || count($entries) <= $this->maxEntries) {
            return;
        }

        $ages = [];
        foreach ($entries as $entry) {
            $mtime = @filemtime($entry);
            $ages[$entry] = is_int($mtime) ? $mtime : 0;
        }
        asort($ages);

        $keep = (int) floor($this->maxEntries * 0.9);
        $excess = count($ages) - $keep;
        foreach (array_slice(array_keys($ages), 0, $excess) as $oldest) {
            @unlink($oldest);
        }
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

    private function skip(string $reason, string $realPath): void
    {
        $this->logger?->debug('ffprobe cache skipped: ' . $reason, ['path' => $realPath]);
    }
}
