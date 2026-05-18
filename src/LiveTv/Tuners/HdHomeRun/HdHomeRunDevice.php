<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Tuners\HdHomeRun;

/**
 * Immutable descriptor for a discovered HDHomeRun device.
 *
 * @since 0.12.0
 */
final class HdHomeRunDevice
{
    /**
     * @param string $deviceId Unique device identifier (e.g. "12345678")
     * @param string $ipAddress Device IP address on the local network
     * @param int $tunerCount Number of available tuners on this device
     * @param string $lineupUrl URL to the device's channel lineup JSON endpoint
     */
    public function __construct(
        public readonly string $deviceId,
        public readonly string $ipAddress,
        public readonly int $tunerCount,
        public readonly string $lineupUrl,
    ) {
    }

    /**
     * Get the number of tuners on this device.
     *
     * @return int Tuner count
     */
    public function getTunerCount(): int
    {
        return $this->tunerCount;
    }

    /**
     * Get the base URL for the device API.
     *
     * @return string Base URL (e.g. "http://192.168.1.100")
     */
    public function getBaseUrl(): string
    {
        return 'http://' . $this->ipAddress;
    }
}
