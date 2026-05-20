<?php

declare(strict_types=1);

namespace Phlix\Media\Markers\Fingerprinting;

/**
 * Exception thrown when fingerprint generation fails.
 *
 * @since 0.12.0
 */
class ChromaPrintFingerprintFailedException extends \RuntimeException
{
    /**
     * Creates a new ChromaPrintFingerprintFailedException.
     *
     * @param string $message The exception message
     * @param int $code The exception code
     * @param \Throwable|null $previous The previous throwable
     *
     * @since 0.12.0
     */
    public function __construct(
        string $message = 'Fingerprint generation failed',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
