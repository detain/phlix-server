<?php

declare(strict_types=1);

namespace Phlex\Roku;

/**
 * Roku device descriptor.
 *
 * Represents a discovered Roku device on the network with
 * its connection details, model information, and unique identifier.
 *
 * @since 0.12.0
 */
class RokuDevice
{
    /**
     * @param string $deviceId Device ID from ECP device info
     * @param string $name Friendly name from ECP device info
     * @param string $host IP address or hostname
     * @param int $port ECP port number (default 8060)
     * @param string $model Model identifier (e.g., "Roku Express")
     * @param string $softwareVersion Software version string
     */
    public function __construct(
        public readonly string $deviceId,
        public readonly string $name,
        public readonly string $host,
        public readonly int $port = 8060,
        public readonly string $model = '',
        public readonly string $softwareVersion = '',
    ) {
    }

    /**
     * Get the device address in "host:port" format.
     *
     * @return string Address string (e.g., '192.168.1.100:8060')
     *
     * @since 0.12.0
     */
    public function getAddress(): string
    {
        return $this->host . ':' . $this->port;
    }
}
