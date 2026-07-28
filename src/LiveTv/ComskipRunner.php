<?php

/**
 * Phlix media server component: LiveTv.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\LiveTv;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Comskip binary detection and execution.
 *
 * Comskip is a third-party C application for detecting commercial breaks
 * in video recordings. This class detects whether comskip is available
 * on the system and executes it against recorded files.
 *
 * @since 0.12.0
 */
class ComskipRunner
{
    /** @var string Path to the comskip binary */
    private string $comskipPath;

    /** @var LoggerInterface Logger instance */
    private LoggerInterface $logger;

    /** @var int Default timeout for comskip execution in seconds */
    private const TIMEOUT_SECONDS = 300;

    /** @var int Effective timeout for comskip execution in seconds (per-instance) */
    private int $timeoutSeconds;

    /**
     * Create a new ComskipRunner.
     *
     * @param string $comskipPath Path to the comskip binary (e.g., '/usr/bin/comskip')
     * @param LoggerInterface|null $logger Optional PSR logger, defaults to NullLogger
     * @param int|null $timeoutSeconds Optional execution timeout override in seconds
     *                                (default {@see self::TIMEOUT_SECONDS} when null
     *                                or non-positive). Kept configurable so the wedged
     *                                -process timeout path (SV-4.3) is testable without
     *                                a real 300s wait.
     *
     * @since 0.12.0
     */
    public function __construct(
        string $comskipPath,
        ?LoggerInterface $logger = null,
        ?int $timeoutSeconds = null
    ) {
        $this->comskipPath = $comskipPath;
        $this->logger = $logger ?? new NullLogger();
        $this->timeoutSeconds = ($timeoutSeconds !== null && $timeoutSeconds > 0)
            ? $timeoutSeconds
            : self::TIMEOUT_SECONDS;
    }

    /**
     * Check if the comskip binary is available on the system.
     *
     * @return bool True if comskip exists and is executable, false otherwise
     *
     * @since 0.12.0
     */
    public function isAvailable(): bool
    {
        if (!file_exists($this->comskipPath)) {
            $this->logger->debug('Comskip binary not found', ['path' => $this->comskipPath]);
            return false;
        }

        if (!is_executable($this->comskipPath)) {
            $this->logger->debug('Comskip binary not executable', ['path' => $this->comskipPath]);
            return false;
        }

        $this->logger->debug('Comskip binary available', ['path' => $this->comskipPath]);
        return true;
    }

    /**
     * Run comskip on a recording file.
     *
     * Executes comskip with the --quiet flag and waits for completion.
     * Returns the path to the generated .edl file (same basename as the input
     * with .edl extension).
     *
     * @param string $recordingPath Absolute path to the recorded video file
     *
     * @return string Absolute path to the generated .edl file
     *
     * @throws \RuntimeException If comskip is not available or execution fails
     *
     * @since 0.12.0
     */
    public function run(string $recordingPath): string
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException(
                "Comskip is not available at path: {$this->comskipPath}"
            );
        }

        if (!file_exists($recordingPath)) {
            throw new \RuntimeException("Recording file not found: {$recordingPath}");
        }

        $edlPath = $this->resolveEdlPath($recordingPath);

        $this->logger->info('Running comskip on recording', [
            'recording' => $recordingPath,
            'edl_output' => $edlPath,
        ]);

        $command = sprintf(
            '%s --quiet --output-dir %s %s 2>&1',
            escapeshellcmd($this->comskipPath),
            escapeshellarg(dirname($edlPath)),
            escapeshellarg($recordingPath)
        );

        $output = [];
        // Exit status of the comskip process. Stays null until it has actually
        // been observed — see the resolution step after the poll loop. Seeding
        // this with 0 made an unobserved status read as a successful run.
        $returnCode = null;

        $descriptorSpec = [
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start comskip process');
        }

        // SV-4.3: Set pipes to non-blocking mode for bounded reads in poll loop.
        // stream_set_timeout alone does NOT enforce timeout on stream_get_contents
        // (it only affects select-based operations). We must use non-blocking I/O.
        $startTime = hrtime(true);
        $timeoutNanos = $this->timeoutSeconds * 1_000_000_000;

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                stream_set_blocking($pipe, false);
            }
        }

        // Read output buffers — bounded reads in poll loop.
        // Using non-blocking reads with bounded chunk size so timeout is reachable.
        $stdout = '';
        $stderr = '';
        $readChunks = 0;
        $maxChunksPerPipe = 200; // ~200 * 8KB = 1.6MB per pipe max
        $chunkSize = 8192;

        $pipesByIdx = [];
        if (is_resource($pipes[1])) {
            $pipesByIdx[1] = $pipes[1];
        }
        if (is_resource($pipes[2])) {
            $pipesByIdx[2] = $pipes[2];
        }

        // Poll loop: interleave bounded reads from stdout and stderr.
        // Uses stream_select() to efficiently wait for data with timeout.
        while (!empty($pipesByIdx)) {
            // Check timeout using monotonic clock
            $elapsedNanos = hrtime(true) - $startTime;
            if ($elapsedNanos >= $timeoutNanos) {
                foreach ($pipesByIdx as $p) {
                    if (is_resource($p)) {
                        fclose($p);
                    }
                }
                proc_terminate($process, SIGKILL);
                throw new \RuntimeException(
                    "Comskip timed out after " . $this->timeoutSeconds . " seconds"
                );
            }

            $readable = $pipesByIdx;
            $write = null;
            $except = null;
            $sec = 0;
            $usec = 100_000; // 100ms per select call
            if (@stream_select($readable, $write, $except, $sec, $usec) === false) {
                // Interrupted or error — treat as still running, retry
                $this->nonBlockingSleep(0.05);
                continue;
            }

            foreach ($readable as $idx => $pipe) {
                $readChunks++;
                if ($readChunks > $maxChunksPerPipe) {
                    // Safety limit: something is flooding us
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                    unset($pipesByIdx[$idx]);
                    continue;
                }

                $chunk = fread($pipe, $chunkSize);
                if ($chunk === false || $chunk === '') {
                    // EOF or error — close pipe
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                    unset($pipesByIdx[$idx]);
                    continue;
                }

                // Accumulate into correct buffer
                if ($idx === 1) {
                    $stdout .= $chunk;
                } elseif ($idx === 2) {
                    $stderr .= $chunk;
                }
            }

            // Check if process exited
            $status = $this->processStatus($process);
            if (!$status['running']) {
                // Process finished — drain any remaining buffered data
                $this->drainRemainingPipes($pipesByIdx, $stdout, $stderr);
                $returnCode = $status['exitcode'];
                break;
            }
        }

        // Close any remaining pipes
        foreach ($pipesByIdx as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        // The poll loop has a second way out: every pipe drained, so the `while`
        // condition went false without the status check ever seeing the process
        // exit. That ordering is routine, not exotic — the kernel closes a dying
        // child's descriptors before it makes the child reapable, so EOF can
        // reach us a scheduler slice ahead of proc_get_status() reporting it, and
        // the chunk-cap above force-closes pipes on its own. Falling through with
        // an assumed 0 reported a *failed* comskip as a success, which then
        // surfaced as a misleading "EDL file not found". Resolve the real status
        // instead. (proc_close() also returns it, but it blocks with no deadline,
        // which would give up the bounded-timeout guarantee below.)
        if ($returnCode === null) {
            $returnCode = $this->awaitExitCode($process, $startTime, $timeoutNanos);
        }

        proc_close($process);

        if ($returnCode !== 0) {
            $this->logger->error('Comskip execution failed', [
                'return_code' => $returnCode,
                'stderr' => $stderr,
                'stdout' => $stdout,
            ]);
            throw new \RuntimeException(
                "Comskip failed with exit code {$returnCode}: {$stderr}"
            );
        }

        // Wait a moment for the EDL file to be written
        $maxWait = 5;
        $waited = 0;
        while (!file_exists($edlPath) && $waited < $maxWait) {
            // Non-blocking sleep when in Swoole coroutine context
            if (class_exists(\Swoole\Coroutine::class) && \Swoole\Coroutine::getCid() > 0) {
                \Swoole\Coroutine::sleep(0.1);
            } else {
                usleep(100000);
            }
            $waited++;
        }

        if (!file_exists($edlPath)) {
            $this->logger->warning('Comskip completed but EDL file not found', [
                'expected_edl' => $edlPath,
            ]);
            throw new \RuntimeException("Comskip completed but EDL file not found: {$edlPath}");
        }

        $this->logger->info('Comskip completed successfully', [
            'recording' => $recordingPath,
            'edl_path' => $edlPath,
        ]);

        return $edlPath;
    }

    /**
     * Read the current status of the comskip process.
     *
     * Wraps {@see proc_get_status()} as an overridable seam so tests can
     * reproduce the pipes-drained-before-the-process-is-reapable ordering
     * deterministically rather than racing the kernel for it.
     *
     * Narrowed to the two fields this class actually reads, and built explicitly
     * rather than returned through: Psalm treats array shapes as sealed, so
     * handing back the full proc_get_status() shape under this signature is an
     * InvalidReturnType there even though PHPStan accepts the wider array.
     *
     * @param resource $process Process handle from {@see proc_open()}
     *
     * @return array{running: bool, exitcode: int} Subset of proc_get_status() this class reads
     *
     * @since 0.12.0
     */
    protected function processStatus($process): array
    {
        $status = proc_get_status($process);

        return [
            'running' => $status['running'],
            'exitcode' => $status['exitcode'],
        ];
    }

    /**
     * Wait for a process whose pipes have closed to report its exit status.
     *
     * Polls until the process is no longer running, honouring the same monotonic
     * deadline as the read loop so a process that closes its output but never
     * exits still cannot wedge the caller.
     *
     * @param resource $process Process handle from {@see proc_open()}
     * @param float $startTime Monotonic start timestamp in nanoseconds
     * @param float $timeoutNanos Timeout budget in nanoseconds
     *
     * @return int Exit code reported by the process
     *
     * @throws \RuntimeException If the process outlives the timeout budget
     *
     * @since 0.12.0
     */
    private function awaitExitCode($process, float $startTime, float $timeoutNanos): int
    {
        while (true) {
            $status = $this->processStatus($process);
            if (!$status['running']) {
                return $status['exitcode'];
            }

            if ((hrtime(true) - $startTime) >= $timeoutNanos) {
                proc_terminate($process, SIGKILL);
                proc_close($process);
                throw new \RuntimeException(
                    "Comskip timed out after " . $this->timeoutSeconds . " seconds"
                );
            }

            $this->nonBlockingSleep(0.01);
        }
    }

    /**
     * Drain any remaining data from pipes before closing.
     *
     * @param array<int, resource> $pipesByIdx Pipes to drain (modified in-place)
     * @param string &$stdout Accumulated stdout
     * @param string &$stderr Accumulated stderr
     *
     * @since SV-4.3
     */
    private function drainRemainingPipes(array &$pipesByIdx, string &$stdout, string &$stderr): void
    {
        $chunkSize = 8192;

        // Quick drain with non-blocking reads - don't loop forever
        foreach ($pipesByIdx as $idx => $pipe) {
            if (!is_resource($pipe)) {
                unset($pipesByIdx[$idx]);
                continue;
            }

            // Use stream_set_blocking just for this drain
            stream_set_blocking($pipe, false);
            $drained = false;

            while (!$drained) {
                $chunk = @fread($pipe, $chunkSize);
                if ($chunk === false || $chunk === '') {
                    fclose($pipe);
                    unset($pipesByIdx[$idx]);
                    $drained = true;
                    continue;
                }

                if ($idx === 1) {
                    $stdout .= $chunk;
                } elseif ($idx === 2) {
                    $stderr .= $chunk;
                }

                // Safety: limit drain iterations
                break;
            }
        }
    }

    /**
     * Yield to event loop with non-blocking sleep.
     *
     * @param float $seconds Sleep duration in seconds
     *
     * @since SV-4.3
     */
    private function nonBlockingSleep(float $seconds): void
    {
        if (class_exists(\Swoole\Coroutine::class) && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::sleep($seconds);
        } else {
            usleep((int) ($seconds * 1_000_000));
        }
    }

    /**
     * Resolve the expected EDL file path for a recording.
     *
     * @param string $recordingPath Path to the recording file
     *
     * @return string Path to the expected EDL file
     *
     * @since 0.12.0
     */
    private function resolveEdlPath(string $recordingPath): string
    {
        $dir = dirname($recordingPath);
        $basename = pathinfo($recordingPath, PATHINFO_FILENAME);

        return $dir . DIRECTORY_SEPARATOR . $basename . '.edl';
    }
}
