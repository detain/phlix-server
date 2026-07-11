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

    /** @var int Timeout for comskip execution in seconds */
    private const TIMEOUT_SECONDS = 300;

    /**
     * Create a new ComskipRunner.
     *
     * @param string $comskipPath Path to the comskip binary (e.g., '/usr/bin/comskip')
     * @param LoggerInterface|null $logger Optional PSR logger, defaults to NullLogger
     *
     * @since 0.12.0
     */
    public function __construct(string $comskipPath, ?LoggerInterface $logger = null)
    {
        $this->comskipPath = $comskipPath;
        $this->logger = $logger ?? new NullLogger();
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
        $returnCode = 0;

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
        $timeoutNanos = self::TIMEOUT_SECONDS * 1_000_000_000;

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
                    "Comskip timed out after " . self::TIMEOUT_SECONDS . " seconds"
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
            $status = proc_get_status($process);
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
