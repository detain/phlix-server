<?php

declare(strict_types=1);

namespace Phlex\Media\Transcoding\Hwaccel;

use Psr\Log\LoggerInterface;

/**
 * Interface for hardware acceleration vendor probes.
 *
 * Each vendor probe inspects the system for available hardware acceleration
 * support and returns a capability object if the vendor is available.
 *
 * @since 0.11.0
 */
interface VendorProbeInterface
{
    /**
     * Returns the vendor identifier (e.g., 'nvenc', 'vaapi', 'qsv').
     *
     * @return string
     *
     * @since 0.11.0
     */
    public function getVendorName(): string;

    /**
     * Checks if this vendor is available on the current system.
     *
     * @return bool True if the hardware/driver is available
     *
     * @since 0.11.0
     */
    public function isAvailable(): bool;

    /**
     * Probes the system and returns capability information.
     *
     * @param string $ffmpeg_path Path to the FFmpeg binary
     * @param LoggerInterface|null $logger Optional logger for debug output
     *
     * @return HwaccelCapability|null Capability object if probe succeeds, null otherwise
     *
     * @since 0.11.0
     */
    public function probe(string $ffmpeg_path, ?LoggerInterface $logger = null): ?HwaccelCapability;

    /**
     * Runs an acceptance test by encoding a short test clip.
     *
     * @param string $ffmpeg_path Path to the FFmpeg binary
     * @param string $test_clip_path Path to a test clip file
     * @param LoggerInterface|null $logger Optional logger
     *
     * @return bool True if the acceptance test passes
     *
     * @since 0.11.0
     */
    public function runAcceptanceTest(string $ffmpeg_path, string $test_clip_path, ?LoggerInterface $logger = null): bool;
}
