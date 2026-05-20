<?php

declare(strict_types=1);

namespace Phlix\Media\Markers\Fingerprinting;

/**
 * Interface for ChromaPrint fingerprint implementations.
 *
 * Implementations can use either FFI bindings to libchromaprint or
 * wrap the shelled fpcalc binary.
 *
 * @since 0.12.0
 */
interface ChromaPrintInterface
{
    /**
     * Generate a fingerprint for an audio file.
     *
     * @param string $path Path to the audio/media file to fingerprint
     *
     * @return string Raw fingerprint data
     *
     * @throws ChromaPrintFingerprintFailedException If fingerprinting fails
     *
     * @since 0.12.0
     */
    public function fingerprint(string $path): string;

    /**
     * Check if this implementation is available on the current system.
     *
     * @return bool True if the implementation can be used
     *
     * @since 0.12.0
     */
    public function isAvailable(): bool;
}
