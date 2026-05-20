<?php

declare(strict_types=1);

namespace Phlix\Chromecast;

/**
 * Chromecast device descriptor.
 *
 * Represents a discovered Chromecast device on the network with
 * its connection details, model information, and unique identifier.
 *
 * @since 0.12.0
 */
class CastDevice
{
    /**
     * @param string $deviceId Chromecast device ID (from TXT record `id`)
     * @param string $name Friendly name (mDNS name stripped of service type)
     * @param string $host IP address or hostname
     * @param int $port API port number
     * @param string $model Model identifier (from TXT `md`)
     * @param string $uuid UUID of the Chromecast device
     */
    public function __construct(
        public readonly string $deviceId,
        public readonly string $name,
        public readonly string $host,
        public readonly int $port,
        public readonly string $model,
        public readonly string $uuid,
    ) {
    }

    /**
     * Get the device address in "host:port" format.
     *
     * @return string Address string (e.g., '192.168.1.100:8009')
     *
     * @since 0.12.0
     */
    public function getAddress(): string
    {
        return $this->host . ':' . $this->port;
    }
}
