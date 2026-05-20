<?php

declare(strict_types=1);

namespace Phlix\Media\Markers\Fingerprinting;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * FFI-based ChromaPrint implementation.
 *
 * Uses PHP's FFI to call libchromaprint directly when available.
 * This is the preferred implementation as it avoids spawning a subprocess.
 *
 * @since 0.12.0
 */
class ChromaPrintFfi implements ChromaPrintInterface
{
    /** @var LoggerInterface Logger instance */
    private LoggerInterface $logger;

    /** @var bool Whether FFI is available */
    private ?bool $available = null;

    /**
     * Creates a new ChromaPrintFfi instance.
     *
     * @param LoggerInterface|null $logger Optional PSR logger
     *
     * @since 0.12.0
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * {@inheritdoc}
     */
    public function fingerprint(string $path): string
    {
        if (!$this->isAvailable()) {
            throw new ChromaPrintNotAvailableException(
                'FFI is not available. Ensure FFI is enabled in php.ini ' .
                'and libchromaprint is installed.'
            );
        }

        if (!file_exists($path)) {
            throw new ChromaPrintFingerprintFailedException(
                sprintf('File not found: %s', $path)
            );
        }

        try {
            return $this->generateFingerprint($path);
        } catch (\Throwable $e) {
            $this->logger->error('FFI fingerprint generation failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            throw new ChromaPrintFingerprintFailedException(
                sprintf('Failed to generate fingerprint for %s: %s', $path, $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        $this->available = false;

        if (!extension_loaded('FFI')) {
            $this->logger->debug('FFI extension not loaded');
            return false;
        }

        if (!ini_get('ffi.enable')) {
            $this->logger->debug('FFI is disabled via ini setting');
            return false;
        }

        try {
            $libPath = $this->findLibrary();
            if ($libPath === null) {
                $this->logger->debug('libchromaprint shared library not found');
                return false;
            }

            $this->available = true;
            $this->logger->debug('ChromaPrint FFI is available', ['library' => $libPath]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->debug('Failed to initialize ChromaPrint FFI', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Find the libchromaprint shared library.
     *
     * @return string|null Path to library or null if not found
     */
    private function findLibrary(): ?string
    {
        $possiblePaths = [
            '/usr/lib/libchromaprint.so',
            '/usr/lib64/libchromaprint.so',
            '/usr/local/lib/libchromaprint.so',
            '/usr/local/lib64/libchromaprint.so',
            'libchromaprint.so',
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Generate fingerprint using FFI.
     *
     * @param string $path Path to the file
     *
     * @return string Raw fingerprint data
     *
     * @throws ChromaPrintFingerprintFailedException If generation fails
     */
    private function generateFingerprint(string $path): string
    {
        $libPath = $this->findLibrary();
        if ($libPath === null) {
            throw new ChromaPrintFingerprintFailedException('libchromaprint library not found');
        }

        try {
            $ffi = \FFI::cdef(
                $this->getFFIDecls(),
                $libPath
            );

            /** @phpstan-ignore-next-line */
            $fingerprint = $ffi->chromaprint_generate_fingerprint($path);
            if ($fingerprint === null || $fingerprint === '') {
                throw new ChromaPrintFingerprintFailedException(
                    sprintf('FFI returned empty fingerprint for %s', $path)
                );
            }

            return $fingerprint;
        } catch (\FFI\Exception $e) {
            throw new ChromaPrintFingerprintFailedException(
                sprintf('FFI call failed: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Get FFI C declarations for libchromaprint.
     *
     * @return string C function declarations
     */
    private function getFFIDecls(): string
    {
        return '
        char* chromaprint_generate_fingerprint(const char* path);
        void chromaprint_free_fingerprint(char* fingerprint);
        ';
    }
}
