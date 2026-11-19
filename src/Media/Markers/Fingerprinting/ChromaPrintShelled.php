<?php

/**
 * Phlix media server component: Fingerprinting.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\Markers\Fingerprinting;

use Phlix\Common\Process\Bounded;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Shelled fpcalc-based ChromaPrint implementation.
 *
 * Wraps the fpcalc binary via proc_open() to generate audio fingerprints.
 * Used as a fallback when FFI is unavailable or disabled.
 *
 * @since 0.12.0
 */
class ChromaPrintShelled implements ChromaPrintInterface
{
    /** @var string Path to the fpcalc binary */
    private string $fpcalcPath;

    /** @var LoggerInterface Logger instance */
    private LoggerInterface $logger;

    /** @var int Timeout for fpcalc execution in seconds */
    private const TIMEOUT_SECONDS = 60;

    /**
     * Creates a new ChromaPrintShelled instance.
     *
     * @param string $fpcalcPath Path to the fpcalc binary
     * @param LoggerInterface|null $logger Optional PSR logger
     *
     * @since 0.12.0
     */
    public function __construct(
        string $fpcalcPath,
        ?LoggerInterface $logger = null
    ) {
        $this->fpcalcPath = $fpcalcPath;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * {@inheritdoc}
     */
    public function fingerprint(string $path): string
    {
        if (!file_exists($path)) {
            throw new ChromaPrintFingerprintFailedException(
                sprintf('File not found: %s', $path)
            );
        }

        $cmd = sprintf(
            '%s %s 2>/dev/null',
            escapeshellarg($this->fpcalcPath),
            escapeshellarg($path)
        );

        $this->logger->debug('Running fpcalc', ['command' => $cmd]);

        // E-12: bounded capture-of-both-streams proc_open grammar lives in the
        // Bounded SSOT. The stdin pipe keeps its historical shape (handed to the
        // child already closed) and the stdout read timeout is preserved.
        $result = Bounded::capture(
            $cmd,
            null,
            Bounded::PIPE,
            Bounded::PIPE,
            Bounded::PIPE,
            self::TIMEOUT_SECONDS
        );

        if ($result === null) {
            throw new ChromaPrintFingerprintFailedException(
                sprintf('Failed to start fpcalc process for %s', $path)
            );
        }

        if ($result->exitCode !== 0) {
            $this->logger->error('fpcalc failed', [
                'path' => $path,
                'exit_code' => $result->exitCode,
                'stderr' => $result->stderr,
            ]);
            throw new ChromaPrintFingerprintFailedException(
                sprintf(
                    'fpcalc exited with code %d for %s: %s',
                    $result->exitCode,
                    $path,
                    trim($result->stderr ?: 'Unknown error')
                )
            );
        }

        $fingerprint = $this->parseFingerprintOutput($result->stdout);

        if ($fingerprint === '') {
            throw new ChromaPrintFingerprintFailedException(
                sprintf('No FINGERPRINT= line found in fpcalc output for %s', $path)
            );
        }

        return $fingerprint;
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        if (!file_exists($this->fpcalcPath)) {
            $this->logger->debug('fpcalc binary not found', ['path' => $this->fpcalcPath]);
            return false;
        }

        if (!is_executable($this->fpcalcPath)) {
            $this->logger->debug('fpcalc binary not executable', ['path' => $this->fpcalcPath]);
            return false;
        }

        $cmd = sprintf('%s -help 2>/dev/null', escapeshellarg($this->fpcalcPath));
        $output = shell_exec($cmd);

        if (!is_string($output) || !str_contains($output, 'Usage')) {
            $this->logger->debug('fpcalc binary not functional', ['path' => $this->fpcalcPath]);
            return false;
        }

        $this->logger->debug('fpcalc is available', ['path' => $this->fpcalcPath]);
        return true;
    }

    /**
     * Parse the FINGERPRINT= line from fpcalc stdout.
     *
     * @param string $output The stdout output from fpcalc
     *
     * @return string The fingerprint value or empty string if not found
     *
     * @since 0.12.0
     */
    private function parseFingerprintOutput(string $output): string
    {
        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            if (str_starts_with($line, 'FINGERPRINT=')) {
                return substr($line, 12);
            }
        }

        return '';
    }
}
