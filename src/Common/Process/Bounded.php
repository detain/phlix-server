<?php

/**
 * Phlix media server component: Common process primitives.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Common\Process;

/**
 * The estate's canonical BOUNDED-spawn grammar (E-12).
 *
 * "Bounded" is the proc_open family: the parent keeps the child's streams,
 * waits for it inside the request/worker tick, and guarantees the wait ends —
 * either the child finishes on its own, or a deadline escalates
 * SIGTERM → grace → SIGKILL. ComskipRunner, FfmpegRunner's probe wrappers,
 * DvbtSignalEngine, BackupManager and ChromaPrintShelled each re-implemented
 * a slice of this family (E-12 register); this class is the single source of
 * the grammar going forward. Sites whose exact observable behavior cannot
 * migrate without a change are REGISTERED IN THE LANE REPORT, not silently
 * bent — {@see capture()} exists precisely so a site can adopt the shared
 * shape while reproducing its historical blocking-read order verbatim.
 *
 * Secrets travel through $env ONLY — the MYSQL_PWD law (BackupManager):
 * never in argv, never in the result, never logged here.
 * {@see BoundedResult} carries no command/env fields for exactly that reason.
 */
final class Bounded
{
    /**
     * Descriptor mode meaning "give the child a stream the parent captures".
     */
    public const PIPE = 'pipe';

    /**
     * Default per-stream capture cap for run() (bytes of prefix kept).
     */
    public const DEFAULT_CAP_BYTES = 1_048_576;

    /**
     * Pump read chunk size for run().
     */
    private const PUMP_CHUNK_BYTES = 8192;

    /**
     * stream_select slice per pump iteration (100 ms): bounds deadline
     * granularity while keeping syscall pressure negligible.
     */
    private const PUMP_SLICE_MICRO = 100_000;

    /**
     * Post-SIGKILL reap poll bound (SIGKILL is uncatchable; this only
     * tolerates kernel scheduling latency).
     */
    private const KILL_REAP_BOUND_SEC = 5;

    /** Poll slice while waiting for a capped-run child to exit on its own. */
    private const AWAIT_SLICE_MICRO = 20_000;

    /**
     * Run to completion while blocking on captured streams (site-shape mode).
     *
     * ChromaPrintShelled and BackupManager historically did proc_open +
     * blocking stream_get_contents + proc_close. This mode reproduces that
     * shape EXACTLY — including the stdout-before-stderr read order and the
     * vacuous stream_set_timeout() relic (SV-4.3 doctrine: the timeout does
     * not interrupt stream_get_contents; it is carried so migrated sites stay
     * behavior-identical). Deadline enforcement is {@see run()}'s job.
     *
     * @param string|list<string> $command              argv array or shell line.
     * @param array<string,string>|null $env            FULL child environment when
     *                                                  non-null (null = inherit).
     *                                                  MYSQL_PWD-class secrets go
     *                                                  here, nowhere else.
     * @param string $stdin  'pipe' — handed to the child ALREADY CLOSED (the
     *                       parent never writes, so a stdin-reading child sees
     *                       EOF instead of deadlocking the reads) — or a file
     *                       path ('/dev/null' discards input).
     * @param string $stdout 'pipe' to capture, or a file path to redirect.
     * @param string $stderr 'pipe' to capture, or a file path to redirect.
     * @param int    $readTimeoutStdout Historical stream_set_timeout() seconds
     *                                  on the stdout pipe; 0 = none.
     *
     * @return BoundedResult|null null when proc_open failed — the CALLER throws
     *                            its own site-specific message; each site's
     *                            exception type and text are observable behavior.
     *
     * @since E-12
     */
    public static function capture(
        string|array $command,
        ?array $env = null,
        string $stdin = self::PIPE,
        string $stdout = self::PIPE,
        string $stderr = self::PIPE,
        int $readTimeoutStdout = 0
    ): ?BoundedResult {
        $descriptors = [
            0 => self::descriptor($stdin, 'r'),
            1 => self::descriptor($stdout, 'w'),
            2 => self::descriptor($stderr, 'w'),
        ];

        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            return null;
        }

        // A PIPE stdin is handed to the child already closed: capture() never
        // writes, and leaving the parent write-end open while blocking-read
        // below would deadlock any child that reads stdin (the latent hazard
        // in the historical site shape — the sites got away with it only
        // because fpcalc/mysql never read input). No migrated site observes
        // a difference: fpcalc never reads stdin, BackupManager passes a file.
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
            unset($pipes[0]);
        }

        $stdoutText = '';
        if ($stdout === self::PIPE && isset($pipes[1]) && is_resource($pipes[1])) {
            if ($readTimeoutStdout > 0) {
                stream_set_timeout($pipes[1], $readTimeoutStdout);
            }
            $text = stream_get_contents($pipes[1]);
            $stdoutText = is_string($text) ? $text : '';
        }

        $stderrText = '';
        if ($stderr === self::PIPE && isset($pipes[2]) && is_resource($pipes[2])) {
            $text = stream_get_contents($pipes[2]);
            $stderrText = is_string($text) ? $text : '';
        }

        self::closePipes($pipes);

        return new BoundedResult(proc_close($process), $stdoutText, $stderrText, false);
    }

    /**
     * Run to completion under a wall-clock deadline with a kill ladder.
     *
     * The canonical bounded-execution grammar: at the deadline the child gets
     * SIGTERM; after $killGraceSecs it gets SIGKILL. Both captured streams are
     * pumped concurrently (non-blocking, select slices), so a child that
     * floods stderr while stdout sits unread cannot deadlock the parent — the
     * hazard {@see capture()} inherits from its migrated sites BY DESIGN for
     * behavior identity. On cap overflow the exact byte prefix is kept and
     * the parent's read end is closed.
     *
     * $timeoutSecs = 0 means NO deadline: run() then inherits proc_close()'s
     * unboundedness, exactly like the historical sites it generalises.
     *
     * @param string|list<string> $command argv array or shell line.
     * @param array<string,string>|null $env    FULL child environment (null =
     *                                          inherit); never in the result.
     * @param int $timeoutSecs                  Deadline seconds; 0 = none.
     * @param int $killGraceSecs                SIGTERM grace before SIGKILL.
     *
     * @return BoundedResult|null null when proc_open failed (caller owns the
     *                            failure message and its exception type).
     *
     * @since E-12
     */
    public static function run(
        string|array $command,
        ?array $env = null,
        int $timeoutSecs = 0,
        int $killGraceSecs = 5,
        int $maxStdoutBytes = self::DEFAULT_CAP_BYTES,
        int $maxStderrBytes = self::DEFAULT_CAP_BYTES
    ): ?BoundedResult {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            return null;
        }
        if (!isset($pipes[1], $pipes[2]) || !is_resource($pipes[1]) || !is_resource($pipes[2])) {
            self::closePipes($pipes);
            proc_close($process);
            throw new \RuntimeException('Bounded::run(): proc_open succeeded without output pipes.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $outOpen = true;
        $errOpen = true;
        $timedOut = false;
        $deadlineNs = $timeoutSecs > 0 ? hrtime(true) + $timeoutSecs * 1_000_000_000 : 0;

        while ($outOpen || $errOpen) {
            if ($deadlineNs > 0 && hrtime(true) >= $deadlineNs) {
                $timedOut = true;
                break;
            }

            $read = [];
            if ($outOpen && isset($pipes[1])) {
                $read[] = $pipes[1];
            }
            if ($errOpen && isset($pipes[2])) {
                $read[] = $pipes[2];
            }
            if ($read === []) {
                break;
            }

            $write = null;
            $except = null;
            $ready = stream_select($read, $write, $except, 0, self::PUMP_SLICE_MICRO);
            if ($ready === false) {
                // select interrupted with no ready stream: the deadline check
                // at the top of the loop arbitrates on the next iteration.
                continue;
            }

            foreach ($read as $stream) {
                $isStdout = $outOpen && isset($pipes[1]) && $stream === $pipes[1];
                if (!is_resource($stream)) {
                    if ($isStdout) {
                        $outOpen = false;
                    } else {
                        $errOpen = false;
                    }
                    continue;
                }

                $chunk = fread($stream, self::PUMP_CHUNK_BYTES);
                if ($chunk === false || $chunk === '') {
                    if ($chunk === false || feof($stream)) {
                        if ($isStdout) {
                            $outOpen = false;
                        } else {
                            $errOpen = false;
                        }
                    }
                    continue;
                }

                if ($isStdout) {
                    $stdout .= $chunk;
                    $cap = $maxStdoutBytes;
                    if (strlen($stdout) >= $cap) {
                        $stdout = substr($stdout, 0, $cap);
                        $outOpen = false;
                        fclose($stream);
                        unset($pipes[1]);
                    }
                } else {
                    $stderr .= $chunk;
                    $cap = $maxStderrBytes;
                    if (strlen($stderr) >= $cap) {
                        $stderr = substr($stderr, 0, $cap);
                        $errOpen = false;
                        fclose($stream);
                        unset($pipes[2]);
                    }
                }
            }
        }

        if (!$timedOut && $deadlineNs > 0 && !self::awaitChildExit($process, $deadlineNs)) {
            // r1b MAJOR: both pumps may have closed via cap-overflow (or early
            // EOF) while the child lives on. The deadline promise survives by
            // bounded-polling the exit status here; expiry arms the SAME kill
            // ladder the mid-pump timeout path uses.
            $timedOut = true;
        }

        if ($timedOut) {
            $exitCode = self::killLadder($process, $killGraceSecs);
            $stdout = self::drainTail($pipes[1] ?? null, $stdout, $maxStdoutBytes);
            $stderr = self::drainTail($pipes[2] ?? null, $stderr, $maxStderrBytes);
        } else {
            $exitCode = proc_close($process);
        }
        self::closePipes($pipes);

        return new BoundedResult($exitCode, $stdout, $stderr, $timedOut);
    }

    /**
     * Terminate a live child and reap it (SIGTERM by default, no escalation).
     *
     * The shape DvbtSignalEngine's stop leg has always used: terminate the
     * process whose output was streamed, close it, move on.
     *
     * @param resource $process A live proc_open handle (pre-8.5 Proc) — terminate
     *                          and reap it.
     *
     * @since E-12
     */
    public static function terminate($process, int $signal = SIGTERM): void
    {
        proc_terminate($process, $signal);
        proc_close($process);
    }

    /**
     * Poll the child's exit status until its deadline budget runs out.
     *
     * True the moment the process is observed not running — the caller's
     * plain proc_close then reaps it with the ordinary exit status, so the
     * happy path stays byte-identical. False ONLY when the deadline expired
     * with the child still alive, which sends the caller through killLadder().
     *
     * @param resource  $process    A live proc_open handle.
     * @param int|float $deadlineNs Monotonic hrtime nanosecond budget end (the
     *                              run() deadline arithmetic promotes to float
     *                              only for absurd timeouts; comparisons hold).
     */
    private static function awaitChildExit($process, int|float $deadlineNs): bool
    {
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return true;
            }
            usleep(self::AWAIT_SLICE_MICRO);
        } while (hrtime(true) < $deadlineNs);

        $status = proc_get_status($process);

        return !$status['running'];
    }

    /**
     * SIGTERM, poll the grace window, SIGKILL uncatchable holdouts; reaps the
     * child and returns its exit status.
     *
     * @param resource $process A live proc_open handle.
     */
    private static function killLadder($process, int $killGraceSecs): int
    {
        proc_terminate($process, SIGTERM);

        $graceNs = hrtime(true) + max($killGraceSecs, 0) * 1_000_000_000;
        $status = proc_get_status($process);
        while ($status['running'] && hrtime(true) < $graceNs) {
            usleep(50_000);
            $status = proc_get_status($process);
        }

        if ($status['running']) {
            proc_terminate($process, SIGKILL);
            $reapNs = hrtime(true) + self::KILL_REAP_BOUND_SEC * 1_000_000_000;
            do {
                usleep(20_000);
                $status = proc_get_status($process);
            } while ($status['running'] && hrtime(true) < $reapNs);
        }

        $exitCode = $status['exitcode'];
        proc_close($process);

        return $exitCode;
    }

    /**
     * Best-effort tail read after a kill (the pipe is already non-blocking;
     * whatever the dead child buffered is still readable).
     */
    private static function drainTail(mixed $pipe, string $existing, int $cap): string
    {
        if ($cap <= 0 || strlen($existing) >= $cap) {
            return $existing;
        }
        if (!is_resource($pipe)) {
            return $existing;
        }
        $tail = stream_get_contents($pipe);
        if (!is_string($tail) || $tail === '') {
            return $existing;
        }

        return substr($existing . $tail, 0, $cap);
    }

    /**
     * Child-stream descriptor for a mode: PIPE means a parent-captured pipe,
     * anything else is a literal file path ('/dev/null' discards).
     *
     * @return list<string> A proc_open descriptor: ['pipe', dir] or ['file', path, dir].
     */
    private static function descriptor(string $mode, string $direction): array
    {
        if ($mode === self::PIPE) {
            return ['pipe', $direction];
        }

        return ['file', $mode, $direction];
    }

    /**
     * Close every still-open captured pipe and forget it (proc_close refuses
     * to reap while read handles remain, so this MUST run before it).
     *
     * @param array<array-key, mixed> $pipes
     */
    private static function closePipes(array &$pipes): void
    {
        foreach ($pipes as $key => $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
            unset($pipes[$key]);
        }
    }
}
