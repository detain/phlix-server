<?php

declare(strict_types=1);

namespace Phlix\Media\Markers\Fingerprinting;

/**
 * Exception thrown when ChromaPrint is not available on the system.
 *
 * @since 0.12.0
 */
class ChromaPrintNotAvailableException extends \RuntimeException
{
    /**
     * Creates a new ChromaPrintNotAvailableException.
     *
     * @param string $message The exception message
     *
     * @since 0.12.0
     */
    public function __construct(string $message = 'ChromaPrint is not available')
    {
        parent::__construct($message);
    }
}
