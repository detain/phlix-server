<?php

/**
 * Phlix media server component: Logger.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\Logger;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Utils;
use RuntimeException;

/**
 * A file handler with a hard BYTE ceiling, closing the gap Monolog's own
 * {@see \Monolog\Handler\RotatingFileHandler} leaves open.
 *
 * Monolog's rotating handler rotates by DAY and prunes by COUNT (`max_files`),
 * so the NUMBER of files is bounded but their SIZE is not: a single chatty day
 * — a six-hour media scan, an error storm — can grow one daily file without
 * limit. That is the exact hazard S130 is about (`max_files: 30` caps the daily
 * file count, not the on-disk size). This handler bounds size directly: when the
 * active file reaches `maxBytes` it is rolled to a numbered shard and a fresh
 * active file is opened, and only the newest `maxFiles` shards are kept, so the
 * total footprint is hard-bounded at
 *
 *     (maxFiles + 1) * maxBytes   bytes
 *
 * (the +1 is the active file), regardless of how loud the workload becomes.
 *
 * The active file keeps a STABLE name — the configured path — so operators and
 * the admin log viewer always tail the same file; rolled shards take a `.1`,
 * `.2`, … suffix. Those shard names are deliberately invisible to the admin
 * `*.log` glob and its `^[A-Za-z0-9._-]+\.log$` tail allowlist, so the log
 * viewer keeps listing and tailing exactly the active file as before.
 *
 * Multi-worker safety: production runs 14 HTTP workers plus the background
 * workers, all sharing these files. The size check and the roll run after the
 * active stream is closed, and every filesystem step is error-suppressed, so a
 * worker that loses a rename to another simply finds the file already rotated
 * and moves on — the same concurrent-rotation tolerance Monolog documents for
 * its own `unlink()` cleanup, applied to `rename()`. Writes themselves are
 * serialised by `flock(LOCK_EX)` (`useLocking`, enabled below).
 *
 * @package Phlix\Common\Logger
 */
final class SizeRotatingFileHandler extends StreamHandler
{
    /** Canonical active log file path (stable across rolls). */
    private readonly string $baseFile;

    /** Number of rolled shards to keep in addition to the active file. */
    private readonly int $maxFiles;

    /** Hard per-file byte ceiling that triggers a roll. */
    private readonly int $maxBytes;

    /**
     * @param string $baseFile Active log file path; kept stable across rolls.
     * @param int    $maxFiles Rolled shards to keep (>= 0). 0 keeps no history:
     *                         an oversized active file is dropped, not renamed.
     * @param int    $maxBytes Per-file byte ceiling (>= 1) that triggers a roll.
     *
     * @throws RuntimeException When a non-positive ceiling or negative shard
     *                          count is configured — a mis-set bound is a bug,
     *                          not something to quietly write past.
     */
    public function __construct(string $baseFile, int $maxFiles, int $maxBytes, Level $level = Level::Debug)
    {
        if ($maxBytes < 1) {
            throw new RuntimeException("maxBytes must be >= 1, got {$maxBytes}");
        }
        if ($maxFiles < 0) {
            throw new RuntimeException("maxFiles must be >= 0, got {$maxFiles}");
        }

        // Canonicalise exactly as StreamHandler will store $this->url, so the
        // filesize()/rename() targets below hit the very file Monolog writes.
        $this->baseFile = Utils::canonicalizePath($baseFile);
        $this->maxFiles = $maxFiles;
        $this->maxBytes = $maxBytes;

        // useLocking = true: the flock Monolog takes around each write also
        // serialises the shared-file lifecycle against concurrent workers.
        parent::__construct($this->baseFile, $level, true, null, true);
    }

    protected function write(LogRecord $record): void
    {
        $this->rotateIfOversized();
        parent::write($record);
    }

    /**
     * Roll the active file to a shard once it has reached the byte ceiling.
     *
     * Returns without touching the disk when the active file is absent or still
     * under the ceiling — the overwhelming majority of writes take this path.
     */
    private function rotateIfOversized(): void
    {
        // PHP caches filesize(); flush it so each write sees the true size after
        // the previous append, otherwise the ceiling is checked against a stale
        // value and a single file overshoots it.
        clearstatcache(true, $this->baseFile);
        $size = @filesize($this->baseFile);
        if ($size === false || $size < $this->maxBytes) {
            return;
        }

        $this->roll();
    }

    /**
     * Close the active stream, shift the shard chain, and start a fresh file.
     *
     * Must run before any further append: the parent's cached stream still
     * points at the old inode, so closing it here forces the next
     * parent::write() to reopen the (now-renamed-away) base path afresh.
     */
    private function roll(): void
    {
        // Release the write handle so the rename does not leave another
        // descriptor appending to the rolled-away inode.
        $this->close();

        // Drop the oldest shard to make room at the tail of the chain.
        if ($this->maxFiles > 0) {
            $this->removeFile($this->shardPath($this->maxFiles));
        }

        // base.(n-1) -> base.n, working oldest-ward so no live shard is clobbered.
        for ($index = $this->maxFiles - 1; $index >= 1; $index--) {
            $this->renameIfPresent($this->shardPath($index), $this->shardPath($index + 1));
        }

        // Retire the active file itself; the next write recreates it empty.
        // With maxFiles = 0 there is no shard to keep, so the full file is dropped.
        if ($this->maxFiles === 0) {
            $this->removeFile($this->baseFile);
            return;
        }
        $this->renameIfPresent($this->baseFile, $this->shardPath(1));
    }

    private function shardPath(int $index): string
    {
        return $this->baseFile . '.' . $index;
    }

    /**
     * @return bool Whether the (suppressed) rename reported success. Concurrent
     *              workers make this a best-effort operation; the byte ceiling,
     *              not the rename return, is the guarantee.
     */
    private function renameIfPresent(string $from, string $to): bool
    {
        if (!@file_exists($from)) {
            return false;
        }
        // @-suppressed: a concurrent worker that already renamed this shard is
        // an expected race, not an error to surface or a record to lose.
        return @rename($from, $to);
    }

    private function removeFile(string $path): void
    {
        if (@file_exists($path)) {
            @unlink($path);
        }
    }
}
