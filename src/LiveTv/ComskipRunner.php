<?php

declare(strict_types=1);

namespace Phlex\LiveTv;

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

        // Set timeout on the process
        $startTime = time();

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                stream_set_timeout($pipe, self::TIMEOUT_SECONDS);
            }
        }

        // Read output to prevent blocking
        $stdout = '';
        $stderr = '';

        if (is_resource($pipes[1])) {
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }

        if (is_resource($pipes[2])) {
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
        }

        // Wait for process with timeout
        while (true) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                $returnCode = $status['exitcode'];
                break;
            }

            if ((time() - $startTime) >= self::TIMEOUT_SECONDS) {
                proc_terminate($process, SIGKILL);
                throw new \RuntimeException(
                    "Comskip timed out after " . self::TIMEOUT_SECONDS . " seconds"
                );
            }

            usleep(100000); // 100ms
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
            usleep(100000);
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
