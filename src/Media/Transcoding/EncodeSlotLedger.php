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
use Psr\Log\NullLogger;

/**
 * Hard cross-worker admission ledger for on-demand segment encodes.
 *
 * Two files sit in the segment cache root:
 *
 *  - `<segmentDir>/.encode-slots`, the ledger: one line per admitted encode,
 *
 *        <token> <stamp> <pid>
 *
 *  - `<segmentDir>/.encode-slots.lock`, the lock file. Every read-modify-write holds
 *    flock(LOCK_EX) on this file. The lock is never taken on the ledger itself, because
 *    the ledger is replaced by rename() and a lock on a replaced file would not exclude
 *    anyone.
 *
 * - `token` is `p` (prewarm launch) or `d` (on-demand launch), then 16 hex characters
 *   from random_bytes(8). The prefix is how the prewarm budget is counted.
 * - `stamp` is the admission time in whole seconds from time(). It is a WALL-clock
 *   stamp on purpose: the ledger is read by every HTTP worker process, and only a
 *   wall clock is comparable across processes. The hrtime(true) rule in the repo
 *   applies to intervals measured inside one process, and it does not apply here.
 * - `pid` is 0 at admission. attachPid() writes the launched encode's process id once
 *   the launch returns. The pid is used for one purpose only: a line past its lifetime
 *   is kept while that process still runs (see parse()).
 *
 * Writes are atomic. Under the lock, the whole new file is written to a temp file in the
 * same directory and then rename()d over the ledger. A reader under the lock therefore
 * sees the old file or the new file, never a short or torn one. A failed write leaves the
 * previous ledger in place and refuses the admission (fail closed). The writes are not
 * fsync()ed. The guarantee is against a worker crash mid-write, not against a power loss
 * that rolls the file back to an earlier version (see the Exception 11 note).
 *
 * The file holds at most the live entries, which are at most the cap. Each write compacts it.
 * The locked section is one read, one rewrite and one rename of that much text. It has no
 * network I/O and no directory scan. The crash-leftover temp sweep (a readdir of the segment
 * root) runs AFTER the lock is released, after a rewrite, and at most once per lifetime per
 * instance (see removeStaleTempsWhenDue()).
 *
 * Expiry:
 *  - Age only, with one exception. A line older than the lifetime is dropped on the next
 *    locked access. The lifetime is the encode's wall bound (`ffmpeg.segment_max_wall_seconds`)
 *    plus LIFETIME_MARGIN_SECONDS. A worker that dies between acquire and launch therefore
 *    costs one slot for at most that long.
 *  - The exception: a line past its lifetime whose pid is still a running process is kept
 *    until HARD_CEILING_FACTOR times the lifetime. Past that it is dropped regardless.
 *  - Clock skew. A stamp more than FUTURE_SKEW_SECONDS ahead of the clock is clamped to now.
 *    The line is kept as fresh and rewritten with the clamped stamp, so a backward wall-clock
 *    step never deletes a live line. A FORWARD step makes live lines look older than they are.
 *    They then expire early, and the cap can be overshot by the encodes they belonged to
 *    while those encodes still run. A backward step holds lines longer, which is safe.
 *
 * Failure modes:
 *  - missing file or directory: both are created on the first locked access;
 *  - corrupt line: skipped, and dropped on the next rewrite;
 *  - the lock is contended, or flock is unavailable: the lock is taken with LOCK_EX | LOCK_NB and
 *    retried for at most LOCK_BUDGET_MICROSECONDS (10 ms) in steps of LOCK_RETRY_SLEEP_MICROSECONDS
 *    (2 ms). If it is still not held, acquire() refuses (fail closed: the caller answers 503 with
 *    Retry-After). release() and attachPid() report false, lastFailed() reports true, and the caller
 *    defers the token to its own retry queue; the line stays in the ledger and ages out if the retry
 *    never lands. The event loop is never blocked for longer than the budget;
 *  - the lock file, the ledger or the temp file cannot be opened, read, written or renamed:
 *    acquire() refuses. That is fail-closed, the caller answers
 *    503, and the failure is logged once per outage. release() and attachPid() report
 *    false, lastFailed() then reports true, and the caller may retry;
 *  - release of an unknown or already-released token: a no-op that returns false.
 *
 * @phpstan-type LedgerEntry array{token: string, stamp: int, pid: int}
 */
final class EncodeSlotLedger
{
    /** Ledger file name inside the segment cache root. */
    public const FILE_NAME = '.encode-slots';

    /** Lock file name, beside the ledger. Every read-modify-write holds flock on it. */
    public const LOCK_FILE_NAME = '.encode-slots.lock';

    /** Seconds added to the encode's wall bound. Covers the registry's grace and watchdog tick. */
    public const LIFETIME_MARGIN_SECONDS = 30;

    /** A line past its lifetime is kept while its pid runs, up to this many lifetimes. */
    public const HARD_CEILING_FACTOR = 2;

    /** A stamp further ahead of the clock than this is clamped to now. */
    public const FUTURE_SKEW_SECONDS = 5;

    /**
     * Total time, in microseconds, that one locked access may wait for the lock. This is the
     * bound on the event-loop block a contended ledger can cause. The retries sleep at most
     * LOCK_RETRY_SLEEP_MICROSECONDS each, and the wait never starts a sleep that would pass the budget.
     */
    public const LOCK_BUDGET_MICROSECONDS = 10_000;

    /** The sleep between two lock attempts. At most 2 ms. */
    public const LOCK_RETRY_SLEEP_MICROSECONDS = 2_000;

    /** errno for "no such process" (ESRCH), the only signal-0 result that says a line's pid is gone. */
    private const ERRNO_ESRCH = 3;

    private const TOKEN_PATTERN = '[pd][0-9a-f]{16}';

    private const LINE_PATTERN = '/^(' . self::TOKEN_PATTERN . ') ([0-9]{1,12}) ([0-9]{1,10})\z/';

    private string $path;

    private int $lifetimeSeconds;

    private LoggerInterface $logger;

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @var \Closure(resource): bool */
    private \Closure $lock;

    /** @var \Closure(string, string): (int|false) Writes the temp file. Returns the bytes written. */
    private \Closure $write;

    /** @var \Closure(string): (string|false) Reads the ledger file. Called only under the lock. */
    private \Closure $read;

    /** @var \Closure(string): (list<string>|false) Lists the ledger's directory. Called only outside the lock. */
    private \Closure $listDir;

    /**
     * Raw signal-0 probe for a line's pid: fn(int $pid): ?int. 0 when the signal was deliverable, the errno from
     * posix_get_last_error() when the call failed, and null when the posix extension is missing. Only ESRCH (3)
     * means gone. Overridable in tests, as the registry's probe is ({@see setSignalZeroProbe()}).
     *
     * @var \Closure(int): (int|null)
     */
    private \Closure $signalZero;

    /** True while a failure episode is being reported, so it is logged once per outage. */
    private bool $failing = false;

    /** Wall-clock second of the last stale-temp sweep by this instance, or null before the first one. */
    private ?int $lastStaleSweep = null;

    /**
     * @param string                          $path            Absolute path of the ledger file.
     * @param int                             $lifetimeSeconds Age in seconds after which a line expires.
     * @param LoggerInterface|null            $logger          Receives the one-per-outage failure warning.
     * @param (\Closure(): int)|null          $clock           Wall-clock seam, seconds since the epoch.
     *                                                         Defaults to time().
     * @param (\Closure(resource): bool)|null $lock            Lock seam, applied to the LOCK FILE handle,
     *                                                         one non-blocking attempt per call. Defaults to
     *                                                         {@see lockExclusive()}. The retry loop around it
     *                                                         is the same for every seam. Tests use it to force
     *                                                         the fail-closed path.
     * @param (\Closure(string, string): (int|false))|null $write Temp-file write seam. Defaults to file_put_contents().
     *                                                         Tests use it to force a short or failed write.
     * @param (\Closure(string): (string|false))|null $read    Ledger read seam. Defaults to file_get_contents(). Tests
     *                                                         use it to observe whether the lock is held at each read.
     * @param (\Closure(string): (list<string>|false))|null $listDir Directory listing seam, for the stale-temp sweep.
     *                                                         Defaults to scandir(). Tests use it to observe that the
     *                                                         sweep runs with the lock released.
     */
    public function __construct(
        string $path,
        int $lifetimeSeconds,
        ?LoggerInterface $logger = null,
        ?\Closure $clock = null,
        ?\Closure $lock = null,
        ?\Closure $write = null,
        ?\Closure $read = null,
        ?\Closure $listDir = null,
    ) {
        $this->path = $path;
        $this->lifetimeSeconds = max(1, $lifetimeSeconds);
        $this->logger = $logger ?? new NullLogger();
        $this->clock = $clock ?? static fn (): int => time();
        $this->lock = $lock ?? static fn ($handle): bool => self::lockExclusive($handle);
        $this->write = $write ?? static fn (string $file, string $text): int|false => @file_put_contents($file, $text);
        $this->read = $read ?? static fn (string $file): string|false => @file_get_contents($file);
        $this->listDir = $listDir ?? static fn (string $dir): array|false => @scandir($dir);
        $this->signalZero = self::defaultSignalZeroProbe();
    }

    /**
     * Override the signal-0 probe used for a line's pid. Null restores the posix call. A test passes an errno
     * (1 for EPERM, 3 for ESRCH), 0 for delivered, or null for a missing posix extension.
     *
     * @param (\Closure(int): (int|null))|null $signalZero
     */
    public function setSignalZeroProbe(?\Closure $signalZero): void
    {
        $this->signalZero = $signalZero ?? self::defaultSignalZeroProbe();
    }

    /**
     * The real signal-0 probe: `posix_kill(pid, 0)`. The errno is read straight after the failed call, so no
     * other call can overwrite it. Null when the posix extension is missing.
     *
     * @return \Closure(int): (int|null)
     */
    private static function defaultSignalZeroProbe(): \Closure
    {
        return static function (int $pid): ?int {
            if (!function_exists('posix_kill')) {
                return null;
            }
            if (@posix_kill($pid, 0)) {
                return 0;
            }

            return posix_get_last_error();
        };
    }

    /**
     * The lifetime for a given encode wall bound: the wall bound plus the margin.
     */
    public static function lifetimeFor(int $maxWallSeconds): int
    {
        return max(1, $maxWallSeconds) + self::LIFETIME_MARGIN_SECONDS;
    }

    /**
     * Normalise a configured encode wall bound to one positive int of seconds. Every caller
     * (the registry watchdog, the ledger lifetime) takes its value from here, so they agree.
     *
     * An int, a float or a numeric string is cast to int (600.0 and '600' become 600). Anything
     * else, or a value below one second, falls back to $default. The result is never below 1.
     */
    public static function wallSecondsFrom(mixed $configured, int $default): int
    {
        if (is_int($configured) || is_float($configured) || (is_string($configured) && is_numeric($configured))) {
            $seconds = (int) $configured;
            if ($seconds >= 1) {
                return $seconds;
            }
        }

        return max(1, $default);
    }

    /**
     * The default lock: ONE non-blocking flock(LOCK_EX | LOCK_NB) attempt on the lock file's handle.
     * It never waits. {@see transact()} retries it within LOCK_BUDGET_MICROSECONDS. Public so a test
     * can apply the real lock through the seam and probe it.
     *
     * @param resource $handle
     */
    public static function lockExclusive($handle): bool
    {
        return flock($handle, LOCK_EX | LOCK_NB);
    }

    /**
     * Admit one encode, or refuse.
     *
     * Rules, evaluated under the lock after expired and corrupt lines are dropped:
     *  1. Global cap: refuse when the live entries number at least $cap. Prewarm and
     *     on-demand launches both count here.
     *  2. Prewarm only: refuse when the live entries number at least $prewarmTotalCap
     *     (the cap less the interactive headroom, so prewarm yields to a seek).
     *  3. Prewarm only: refuse when the live prewarm entries number at least $prewarmBudget.
     *
     * @param int  $cap            Global in-flight ceiling (server.hls.max_concurrent_segments).
     * @param bool $prewarm        True for a C6 head-start launch.
     * @param int  $prewarmTotalCap Prewarm ceiling on the total live entries.
     * @param int  $prewarmBudget  Ceiling on the live prewarm entries.
     *
     * @return array{token: string, inFlight: int}|null The token and the live count including this
     *         entry, or null when refused or fail-closed.
     */
    public function acquire(int $cap, bool $prewarm = false, int $prewarmTotalCap = 0, int $prewarmBudget = 0): ?array
    {
        $token = ($prewarm ? 'p' : 'd') . bin2hex(random_bytes(8));
        $rules = ['cap' => $cap, 'prewarm' => $prewarm, 'totalCap' => $prewarmTotalCap, 'budget' => $prewarmBudget];
        /** @var array{token: string, inFlight: int}|null $grant */
        $grant = null;

        $ok = $this->transact(
            function (array $entries, int $now) use (&$grant, $rules, $token): ?array {
                $total = count($entries);
                $prewarmLive = 0;
                foreach ($entries as $entry) {
                    if ($entry['token'][0] === 'p') {
                        $prewarmLive++;
                    }
                }
                if ($total >= $rules['cap']) {
                    return null;
                }
                if ($rules['prewarm'] && ($total >= $rules['totalCap'] || $prewarmLive >= $rules['budget'])) {
                    return null;
                }
                $entries[] = ['token' => $token, 'stamp' => $now, 'pid' => 0];
                $grant = ['token' => $token, 'inFlight' => $total + 1];

                return $entries;
            }
        );

        return $ok ? $grant : null;
    }

    /**
     * Record the launched encode's process id on its line. Called once, after the launch returns
     * a positive pid. A line whose pid is still running is kept past its lifetime (see parse()).
     *
     * @return bool True when this call wrote the pid onto a live line.
     */
    public function attachPid(string $token, int $pid): bool
    {
        if ($pid <= 0 || preg_match('/^' . self::TOKEN_PATTERN . '\z/', $token) !== 1) {
            return false;
        }

        $attached = false;

        $ok = $this->transact(
            function (array $entries) use (&$attached, $token, $pid): ?array {
                foreach ($entries as $i => $entry) {
                    if ($entry['token'] === $token) {
                        $entries[$i]['pid'] = $pid;
                        $attached = true;

                        return $entries;
                    }
                }

                return null;
            }
        );

        return $ok && $attached;
    }

    /**
     * Remove one admitted encode's line. Idempotent: a token that is not in the ledger
     * (already released, expired, or never admitted) is a no-op that returns false.
     *
     * A false return is either the no-op above or a failed write. {@see lastFailed()} tells
     * them apart: it is true only after a failed write, and the caller may retry then.
     *
     * @return bool True when this call removed a line.
     */
    public function release(string $token): bool
    {
        if (preg_match('/^' . self::TOKEN_PATTERN . '\z/', $token) !== 1) {
            return false;
        }

        $removed = false;

        $ok = $this->transact(
            function (array $entries) use (&$removed, $token): ?array {
                $kept = [];
                foreach ($entries as $entry) {
                    if ($entry['token'] !== $token) {
                        $kept[] = $entry;
                    }
                }
                if (count($kept) === count($entries)) {
                    return null;
                }
                $removed = true;

                return $kept;
            }
        );

        return $ok && $removed;
    }

    /**
     * True when the most recent locked operation failed (lock, read, write or rename), and false
     * after one that succeeded. A release that returned false because of a failure can be retried.
     */
    public function lastFailed(): bool
    {
        return $this->failing;
    }

    /**
     * One locked read-modify-write.
     *
     * $mutate receives the live entries and the current time. It returns the entries to
     * write, or null for no change to the entries. The surviving entries are written back
     * whenever their text differs from the file, so expired and corrupt lines are dropped
     * on any locked access. The caller carries its result out through a captured variable.
     * The ledger and the lock file are both created when missing.
     *
     * @param callable(list<LedgerEntry>, int): (list<LedgerEntry>|null) $mutate
     *
     * @return bool True when the lock and the file were both obtained. False is fail closed.
     */
    private function transact(callable $mutate): bool
    {
        $rewritten = false;
        $ok = $this->locked($mutate, $rewritten);
        if ($rewritten) {
            // The lock handle is closed by now, so the directory listing runs with the lock released.
            $this->removeStaleTempsWhenDue();
        }

        return $ok;
    }

    /**
     * The locked section of {@see transact()}: one read, the mutation, and at most one rewrite. Sets
     * $rewritten when the ledger was replaced. Nothing here lists a directory.
     *
     * @param callable(list<LedgerEntry>, int): (list<LedgerEntry>|null) $mutate
     */
    private function locked(callable $mutate, bool &$rewritten): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $this->fail('segment cache directory cannot be created');
        }

        $lock = @fopen($this->lockPath(), 'c');
        if ($lock === false) {
            return $this->fail('lock file cannot be opened');
        }

        try {
            if (!$this->acquireLock($lock)) {
                return $this->fail('lock not held within the wait budget (contended or unavailable)');
            }

            $raw = $this->readLedger();
            if ($raw === null) {
                return $this->fail('ledger file cannot be read');
            }

            $now = ($this->clock)();
            $entries = $this->parse($raw, $now);
            $next = $mutate($entries, $now);
            $text = self::render($next ?? $entries);

            if ($text !== $raw) {
                if (!$this->replaceLedger($text)) {
                    return $this->fail('ledger file cannot be replaced');
                }
                $rewritten = true;
            }

            $this->failing = false;

            return true;
        } finally {
            // Closing the lock handle releases the flock.
            fclose($lock);
        }
    }

    private function lockPath(): string
    {
        return dirname($this->path) . '/' . self::LOCK_FILE_NAME;
    }

    /**
     * Take the lock within the budget. Each attempt is one non-blocking call of the lock seam. Between
     * attempts it sleeps at most LOCK_RETRY_SLEEP_MICROSECONDS, and it never starts a sleep that would
     * take the total past LOCK_BUDGET_MICROSECONDS. So the event loop waits at most the budget.
     *
     * @param resource $handle
     */
    private function acquireLock($handle): bool
    {
        $start = hrtime(true);
        while (true) {
            if (($this->lock)($handle)) {
                return true;
            }
            $elapsedUs = intdiv(hrtime(true) - $start, 1000);
            if ($elapsedUs + self::LOCK_RETRY_SLEEP_MICROSECONDS > self::LOCK_BUDGET_MICROSECONDS) {
                return false;
            }
            usleep(self::LOCK_RETRY_SLEEP_MICROSECONDS);
        }
    }

    /**
     * The current ledger text, or null when it exists but cannot be read. A missing ledger is empty.
     * Called only under the lock.
     */
    private function readLedger(): ?string
    {
        if (!is_file($this->path)) {
            return '';
        }
        $raw = ($this->read)($this->path);

        return is_string($raw) ? $raw : null;
    }

    /**
     * Remove crash leftovers: `.encode-slots.<16 hex>.tmp` files whose mtime is older than the lifetime.
     * A temp is left behind only when a worker dies between its write and its rename. It is called after a
     * rewrite and AFTER the lock is released, so the directory listing never runs under the ledger lock. It
     * runs at most once per lifetime per instance (a wall-clock gate on {@see $lastStaleSweep}), so the
     * listing of the segment root is bounded by time, not by the number of rewrites. A temp is removed only
     * when its mtime is at least one lifetime old. No writer holds a temp that old: a live writer renames its
     * temp within the same locked section, which takes milliseconds. The ledger itself is never a temp name.
     */
    private function removeStaleTempsWhenDue(): void
    {
        $now = ($this->clock)();
        if ($this->lastStaleSweep !== null && $now - $this->lastStaleSweep < $this->lifetimeSeconds) {
            return;
        }
        $this->lastStaleSweep = $now;

        $dir = dirname($this->path);
        $names = ($this->listDir)($dir);
        if (!is_array($names)) {
            return;
        }
        $cutoff = $now - $this->lifetimeSeconds;
        $pattern = '/^' . preg_quote(self::FILE_NAME, '/') . '\.[0-9a-f]{16}\.tmp\z/';
        foreach ($names as $name) {
            $file = $dir . '/' . $name;
            if (preg_match($pattern, $name) !== 1 || !is_file($file)) {
                continue;
            }
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime <= $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Replace the ledger atomically: write a temp file beside it, then rename() it over the ledger.
     * Called only under the lock. On any failure the temp file is removed and the old ledger stays.
     */
    private function replaceLedger(string $text): bool
    {
        $temp = dirname($this->path) . '/' . self::FILE_NAME . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $written = ($this->write)($temp, $text);
        if ($written !== strlen($text)) {
            @unlink($temp);

            return false;
        }
        if (!@rename($temp, $this->path)) {
            @unlink($temp);

            return false;
        }

        return true;
    }

    /**
     * Parse the file body. Corrupt lines are skipped. A stamp more than FUTURE_SKEW_SECONDS ahead
     * of the clock is clamped to now, so it is kept and treated as fresh. A line past its lifetime
     * is dropped, unless {@see stillRunning()} keeps it.
     *
     * @return list<LedgerEntry>
     */
    private function parse(string $raw, int $now): array
    {
        $entries = [];
        foreach (explode("\n", $raw) as $line) {
            if ($line === '' || preg_match(self::LINE_PATTERN, $line, $m) !== 1) {
                continue; // corrupt line: skipped
            }
            $pid = (int) $m[3];
            $stamp = (int) $m[2];
            if ($stamp > $now + self::FUTURE_SKEW_SECONDS) {
                $stamp = $now; // clock stepped back, or a skewed writer: keep the line, treat it as fresh
            }
            $age = $now - $stamp;
            if ($age >= $this->lifetimeSeconds && !$this->stillRunning($pid, $age)) {
                continue; // expired: the encode's wall bound has passed, and its process is not running
            }
            $entries[] = ['token' => $m[1], 'stamp' => $stamp, 'pid' => $pid];
        }

        return $entries;
    }

    /**
     * Whether an expired line is still held: its launched pid is running, and the line is younger
     * than the hard ceiling. A pid of 0 (launch not yet recorded) never holds a line.
     */
    private function stillRunning(int $pid, int $ageSeconds): bool
    {
        return $pid > 0
            && $ageSeconds < self::HARD_CEILING_FACTOR * $this->lifetimeSeconds
            && $this->pidAlive($pid);
    }

    /**
     * Whether a process id names a running process. A zombie has exited and does not count.
     * Only ESRCH says the PID is gone. Delivered, EPERM and any other errno mean it exists, and without
     * posix nothing shows it gone, so it is alive. The `/proc` zombie check applies wherever `/proc` is
     * readable. Non-blocking: one signal-0 probe and one /proc read.
     */
    private function pidAlive(int $pid): bool
    {
        if (($this->signalZero)($pid) === self::ERRNO_ESRCH) {
            return false;
        }

        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if (is_string($stat)) {
            // The state letter follows the last ')' of the stat line, after one space.
            $close = strrpos($stat, ')');
            if ($close !== false && ($stat[$close + 2] ?? '') === 'Z') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<LedgerEntry> $entries
     */
    private static function render(array $entries): string
    {
        $text = '';
        foreach ($entries as $entry) {
            $text .= $entry['token'] . ' ' . $entry['stamp'] . ' ' . $entry['pid'] . "\n";
        }

        return $text;
    }

    private function fail(string $reason): bool
    {
        // Fail closed. The caller refuses the admission, or leaves a release to be retried or to expire by age.
        // The warning is logged once per outage: the flag clears only on the next successful locked access.
        $firstInOutage = !$this->failing;
        $this->failing = true;
        if ($firstInOutage) {
            $this->logger->warning('Encode slot ledger unavailable: refusing segment admission (fail closed)', [
                'path' => $this->path,
                'reason' => $reason,
            ]);
        }

        return false;
    }
}
