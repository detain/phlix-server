<?php

declare(strict_types=1);

namespace Phlex\Media\Markers\Fingerprinting;

use Psr\Log\LoggerInterface;

/**
 * Factory for creating ChromaPrint instances.
 *
 * Tries FFI first, then falls back to shelled fpcalc binary.
 *
 * @since 0.12.0
 */
final class ChromaPrintFactory
{
    /**
     * Build a ChromaPrintInterface implementation.
     *
     * Attempts to use FFI first (zero binary dependency), falls back
     * to shelled fpcalc binary for systems where FFI is disabled.
     *
     * @param string $fpcalcPath Path to fpcalc binary (used for shelled fallback)
     * @param LoggerInterface|null $logger Optional PSR logger
     *
     * @return ChromaPrintInterface An available implementation
     *
     * @since 0.12.0
     */
    public static function build(
        string $fpcalcPath,
        ?LoggerInterface $logger = null
    ): ChromaPrintInterface {
        $ffi = new ChromaPrintFfi($logger);

        if ($ffi->isAvailable()) {
            return $ffi;
        }

        return new ChromaPrintShelled($fpcalcPath, $logger);
    }
}
