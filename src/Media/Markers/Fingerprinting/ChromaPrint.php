<?php

declare(strict_types=1);

namespace Phlix\Media\Markers\Fingerprinting;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Main wrapper class for ChromaPrint fingerprinting.
 *
 * This class provides a unified interface for fingerprinting media files.
 * It delegates to an appropriate implementation (FFI or shelled) based on
 * availability.
 *
 * @since 0.12.0
 */
class ChromaPrint
{
    /** @var ChromaPrintInterface The underlying implementation */
    private ChromaPrintInterface $impl;

    /** @var LoggerInterface Logger instance */
    private LoggerInterface $logger;

    /**
     * Creates a new ChromaPrint instance.
     *
     * @param string $fpcalcPath Path to the fpcalc binary (used for shelled mode)
     * @param LoggerInterface|null $logger Optional PSR logger
     *
     * @since 0.12.0
     */
    public function __construct(
        string $fpcalcPath,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->impl = ChromaPrintFactory::build($fpcalcPath, $this->logger);
    }

    /**
     * Generate a fingerprint for an audio file or media item.
     *
     * @param string $path Path to the media file to fingerprint
     *
     * @return string Raw fingerprint data
     *
     * @throws ChromaPrintNotAvailableException If fingerprinting is not available
     * @throws ChromaPrintFingerprintFailedException If fingerprinting fails
     *
     * @since 0.12.0
     */
    public function fingerprint(string $path): string
    {
        if (!$this->isAvailable()) {
            throw new ChromaPrintNotAvailableException(
                'ChromaPrint is not available on this system. ' .
                'Ensure either FFI is enabled with libchromaprint, ' .
                'or the fpcalc binary is installed and accessible.'
            );
        }

        try {
            $fingerprint = $this->impl->fingerprint($path);
            $this->logger->debug('Fingerprint generated', [
                'path' => $path,
                'fingerprint_length' => strlen($fingerprint),
            ]);
            return $fingerprint;
        } catch (ChromaPrintFingerprintFailedException $e) {
            $this->logger->error('Fingerprint generation failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Check if fingerprinting is available on this system.
     *
     * @return bool True if FFI or shelled fpcalc is available
     *
     * @since 0.12.0
     */
    public function isAvailable(): bool
    {
        return $this->impl->isAvailable();
    }
}
