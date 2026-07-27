<?php

/**
 * Phlix media server component: Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Music;

use Psr\Log\LoggerInterface;

/**
 * Raises the number of file reads the music scan has in flight against the media
 * mount from **1 to at most 4**, by running a small pool of reader processes a
 * few files ahead of the tag-probing walk. **S122(b).**
 *
 * ## 🛑 THE CAP IS 4 BECAUSE IT WAS MEASURED. DO NOT "OPTIMISE" IT UPWARDS.
 *
 * Parallel cold `open()`s of disjoint file sets on the production vault mount
 * (`steps/vault-sshfs-read-perf-diagnostic.worklog.md`, 16 files per run):
 *
 * | threads | ms/file | files/s | speedup |
 * |---|---|---|---|
 * | 1  | 117.0 | 8.5  | 1.00x |
 * | **4**  | **67.7**  | **14.8** | **1.73x** |
 * | 8  | 197.8 | 5.1  | **0.59x — WORSE THAN SERIAL** |
 * | 16 | 115.9 | 8.6  | 1.01x |
 *
 * **More parallelism is not better here, and past 4 it COLLAPSES.** The backing
 * store is a single rotational spindle (`rotational=1`, `r_await` 10.58 ms,
 * `%util` 12.45 at `aqu-sz` **0.14** — idle but latency-bound), so a handful of
 * concurrent requests fills the seek pipeline and anything beyond that turns
 * into seek thrash. The diagnostic's own caveat is honoured too: the 8-vs-16
 * inversion is within noise on single 16-file runs, so the only claim this class
 * relies on is *"≈1.7x at 4, and it does not scale beyond"*.
 * {@see self::clampReaders()} enforces it, and two tests pin it separately —
 * `MusicScanPrefetcherTest::testTheConcurrencyCapIsFourAndEverythingAboveItClampsDown()`
 * for the behaviour and
 * `…::testTheCapCitesTheMeasurementThatJustifiesIt()` for the presence of these
 * very figures in this file, so that raising the cap means deleting a falsifiable
 * claim rather than editing a lone integer.
 *
 * ## Why a reader POOL and not coroutines or threads
 *
 * There is no in-process option on this runtime:
 *
 *  - **Swoole coroutines cannot do it.** `SwooleRuntime::SAFE_HOOK_NAMES` is an
 *    allowlist that deliberately EXCLUDES `SWOOLE_HOOK_FILE` — enabling it
 *    (via `io_uring`) crashed workers with recurring general-protection faults,
 *    which is why the mask is curated rather than `SWOOLE_HOOK_ALL`. Without
 *    that hook, `fopen`/`fread` inside a coroutine block the whole thread, so N
 *    coroutines are exactly as serial as one.
 *  - **No `ext-parallel`/`ext-pthreads`** on this box (`php -m`).
 *  - **`config/process.php` `count` MUST stay 1** — `LibraryScanWorker::start()`
 *    reaps EVERY `running` job row at boot, which is correct only under that
 *    single-consumer invariant. Raising it would have fork #2 fail fork #1's
 *    live job. So the concurrency has to live INSIDE one scan.
 *
 * ## Why this is safe: it cannot change what gets indexed
 *
 * The pool is a **page-cache warmer and nothing else**. It opens the files the
 * walk is about to reach, reads the byte ranges getID3 will ask for, and throws
 * the bytes away; it never parses a tag, never writes, and never reports
 * anything back to the scanner. Every failure mode — a child that will not
 * spawn, a child that dies, a full pipe, a path submitted for a file that has
 * since been deleted — degrades to "the scanner reads that file itself", which
 * is the pre-S122 behaviour. There is no code path by which a prefetch outcome
 * can influence `ScanResult`, and therefore none by which it can lose a file.
 *
 * ## Wire bytes are paid once, not twice
 *
 * The child's read populates the local page cache and the scanner's subsequent
 * getID3 read is served from it, so a prefetched file costs the network the same
 * bytes it always did. That holds because the production mount now carries
 * `-o auto_cache` (applied 2026-07-26, measured **51 MB/s cold → 2.6 GB/s
 * warm**, revalidated on mtime/size) and because an ordinary local filesystem
 * caches unconditionally. A mount using `direct_io` would defeat the cache and
 * pay the bytes twice — hence {@see self::READERS_DISABLED} and the
 * `scanner.music_read_concurrency` config knob: set it to 1 and the pool is not
 * created at all.
 *
 * ## Resident-memory note
 *
 * No `static` state. Child handles live on the instance, the instance lives for
 * one `scanDirectory()` call, and {@see self::close()} is called from a
 * `finally` so no process outlives the scan. A child also exits by itself on
 * stdin EOF, which is what happens if the worker is killed outright.
 *
 * @package Phlix\Media\Music
 * @since 1.2.0
 */
final class MusicScanPrefetcher
{
    /**
     * Total reads in flight against the mount, INCLUDING the scanner's own.
     *
     * See the measured table in the class docblock: 1.73x at 4, 0.59x at 8.
     */
    public const MAX_READERS = 4;

    /** The value that means "no pool at all — behave exactly as pre-S122". */
    public const READERS_DISABLED = 1;

    /**
     * Default reads in flight, i.e. the measured optimum.
     */
    public const DEFAULT_READERS = 4;

    /**
     * How many files ahead of the walk the pool is kept supplied.
     *
     * Twice {@see self::MAX_READERS}, so every child always has one path queued
     * and one in progress. Larger buys nothing — a child cannot read faster than
     * the spindle answers — and costs prefetches for files the walk may never
     * reach if the scan is interrupted.
     */
    public const LOOKAHEAD = 8;

    /** `config/scanner.php` key backing {@see self::configuredReaders()}. */
    public const CONFIG_KEY = 'music_read_concurrency';

    /**
     * Per-child write buffers, keyed by child index.
     *
     * A path is only enqueued for a child whose buffer is EMPTY, so a
     * short/blocked write can never leave a half-written path in the child's
     * NUL-delimited stream. A child whose buffer will not drain simply stops
     * being given work.
     *
     * @var array<int, string>
     */
    private array $pending = [];

    /** @var array<int, resource> Live child processes, by index. */
    private array $procs = [];

    /** @var array<int, resource> Each child's stdin, by index. */
    private array $stdin = [];

    /** Round-robin cursor. */
    private int $next = 0;

    /** Paths handed to a child (for the completion summary). */
    private int $submitted = 0;

    /** Paths dropped because no child could take them. */
    private int $dropped = 0;

    /**
     * @param LoggerInterface $logger  Shared MEDIA-channel logger.
     * @param int             $readers Desired reads in flight including the
     *        scanner's own; clamped by {@see self::clampReaders()}. The pool size
     *        is therefore `$readers - 1`.
     * @param string|null $readerScript Reader program to execute. NULL means
     *        {@see self::readerScript()}. A constructor parameter rather than an
     *        overridable method so this class can stay `final`, and so the "reader
     *        program is missing" branch — a broken deployment, otherwise unreachable
     *        from a test — is coverable.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $readers = self::DEFAULT_READERS,
        private readonly ?string $readerScript = null,
    ) {
    }

    /**
     * Clamps a requested concurrency into the measured-safe range `[1, 4]`.
     *
     * ⚠ **THE UPPER BOUND IS THE MEASUREMENT, NOT A GUESS.** 4 concurrent cold
     * opens on the production mount measured **1.73x**; 8 measured **0.59x**,
     * i.e. worse than serial. Raising {@see self::MAX_READERS} without a new
     * measurement makes the scan slower, not faster. The lower bound is 1 rather
     * than 0 because 1 is the honest description of the pre-S122 scanner: the
     * scanner itself is always one reader.
     *
     * @param int $requested Whatever the operator/config asked for.
     *
     * @return int A value in `[self::READERS_DISABLED, self::MAX_READERS]`.
     */
    public static function clampReaders(int $requested): int
    {
        return max(self::READERS_DISABLED, min(self::MAX_READERS, $requested));
    }

    /**
     * The effective, clamped reader count from `config/scanner.php`.
     *
     * Read through the same effective-config path as `scanner.ignore_patterns`
     * (`config/scanner.php` is deliberately NOT composed into `config/server.php`,
     * so a plain `$appConfig['scanner']` lookup resolves to nothing), and clamped
     * regardless of what it says — a config file cannot raise the cap.
     *
     * @param array<array-key, mixed> $scannerConfig Effective `scanner` config.
     *
     * @return int A value in `[self::READERS_DISABLED, self::MAX_READERS]`.
     */
    public static function configuredReaders(array $scannerConfig): int
    {
        $raw = $scannerConfig[self::CONFIG_KEY] ?? self::DEFAULT_READERS;

        return self::clampReaders(is_numeric($raw) ? (int) $raw : self::DEFAULT_READERS);
    }

    /**
     * Absolute path of the reader program the pool executes.
     *
     * @return string
     */
    public static function readerScript(): string
    {
        return dirname(__DIR__, 3) . '/scripts/prefetch-audio-headers.php';
    }

    /**
     * Spawns the pool. A no-op when concurrency is 1 or the reader program is
     * missing.
     *
     * Both child output streams go to `/dev/null` rather than to pipes: a pipe
     * nobody drains fills at 64 KB and would block the child forever, and this
     * pool has nothing to say. Its health is read from `proc_get_status()`
     * instead.
     *
     * @return void
     */
    public function open(): void
    {
        $poolSize = $this->readers - 1;
        if ($poolSize < 1) {
            return;
        }

        $script = $this->readerScript ?? self::readerScript();
        if (!is_file($script)) {
            $this->logger->warning('Music scan read-ahead pool disabled: reader program is missing', [
                'script' => $script,
            ]);
            return;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        for ($i = 0; $i < $poolSize; $i++) {
            $pipes = [];
            $proc = @proc_open([PHP_BINARY, $script], $descriptors, $pipes);
            if (!is_resource($proc) || !isset($pipes[0]) || !is_resource($pipes[0])) {
                $this->logger->warning('Music scan read-ahead pool could not start a reader', [
                    'index' => $i,
                ]);
                continue;
            }

            // Non-blocking, so a child that is busy on a slow spindle can never
            // stall the scanner that is feeding it.
            stream_set_blocking($pipes[0], false);

            $this->procs[$i] = $proc;
            $this->stdin[$i] = $pipes[0];
            $this->pending[$i] = '';
        }

        if ($this->procs !== []) {
            $this->logger->info('Music scan read-ahead pool started', [
                'readers_in_flight' => count($this->procs) + 1,
                'cap' => self::MAX_READERS,
            ]);
        }
    }

    /**
     * Asks the pool to warm one file, if any child can take it right now.
     *
     * Never blocks and never throws. A path that cannot be placed is counted and
     * forgotten — the scanner will read that file itself, exactly as it did
     * before this class existed.
     *
     * @param string $path Absolute path of a file the walk is about to probe.
     *
     * @return void
     */
    public function submit(string $path): void
    {
        if ($this->procs === [] || $path === '' || str_contains($path, "\0")) {
            return;
        }

        $this->drain();

        $count = count($this->stdin);
        $indexes = array_keys($this->stdin);

        for ($attempt = 0; $attempt < $count; $attempt++) {
            $index = $indexes[($this->next + $attempt) % $count];
            if (($this->pending[$index] ?? '') !== '') {
                continue;
            }

            $this->next = ($index + 1) % max(1, $count);
            $this->pending[$index] = $path . "\0";
            $this->flush($index);
            $this->submitted++;

            return;
        }

        // Every child is still busy with the path it already has. Dropping is the
        // correct answer: queueing would grow without bound and prefetching a
        // file the walk has already passed is pure waste.
        $this->dropped++;
    }

    /**
     * Pushes whatever is still buffered towards each child.
     *
     * @return void
     */
    public function drain(): void
    {
        foreach (array_keys($this->pending) as $index) {
            $this->flush($index);
        }
    }

    /**
     * Shuts the pool down: stdin EOF (which is how a reader learns to exit),
     * then a terminate for anything that has not noticed.
     *
     * @return void
     */
    public function close(): void
    {
        foreach ($this->stdin as $handle) {
            if (is_resource($handle)) {
                @fclose($handle);
            }
        }
        $this->stdin = [];
        $this->pending = [];

        foreach ($this->procs as $proc) {
            if (!is_resource($proc)) {
                continue;
            }
            // A reader's whole job is one bounded read, so it exits on EOF almost
            // at once. proc_terminate() covers the case where it is mid-read on a
            // stalled mount; proc_close() then reaps it so no zombie is left in a
            // resident worker.
            @proc_terminate($proc);
            @proc_close($proc);
        }
        $this->procs = [];
    }

    /** Live pool size (children only — the scanner is the extra reader). */
    public function poolSize(): int
    {
        return count($this->procs);
    }

    /** Reads in flight when the pool is fully busy, including the scanner's own. */
    public function readersInFlight(): int
    {
        return $this->procs === [] ? self::READERS_DISABLED : count($this->procs) + 1;
    }

    /**
     * Counters for the scan's completion summary.
     *
     * @return array{submitted: int, dropped: int, readers_in_flight: int}
     */
    public function stats(): array
    {
        return [
            'submitted' => $this->submitted,
            'dropped' => $this->dropped,
            'readers_in_flight' => $this->readersInFlight(),
        ];
    }

    /**
     * Writes as much of one child's buffer as the pipe will take right now.
     *
     * A dead or unwritable child is removed from the pool rather than retried
     * forever.
     *
     * @param int $index Child index.
     *
     * @return void
     */
    private function flush(int $index): void
    {
        $buffer = $this->pending[$index] ?? '';
        if ($buffer === '') {
            return;
        }

        $handle = $this->stdin[$index] ?? null;
        if (!is_resource($handle)) {
            $this->retire($index);
            return;
        }

        $written = @fwrite($handle, $buffer);
        if ($written === false) {
            $this->retire($index);
            return;
        }

        $this->pending[$index] = substr($buffer, $written);
    }

    /**
     * Drops one child from the pool.
     *
     * @param int $index Child index.
     *
     * @return void
     */
    private function retire(int $index): void
    {
        $handle = $this->stdin[$index] ?? null;
        if (is_resource($handle)) {
            @fclose($handle);
        }

        $proc = $this->procs[$index] ?? null;
        if (is_resource($proc)) {
            @proc_terminate($proc);
            @proc_close($proc);
        }

        unset($this->stdin[$index], $this->procs[$index], $this->pending[$index]);
    }
}
