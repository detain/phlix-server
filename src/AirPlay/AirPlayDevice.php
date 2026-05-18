<?php

declare(strict_types=1);

namespace Phlex\AirPlay;

/**
 * AirPlay device descriptor.
 *
 * Represents a discovered AirPlay 2 compatible device (Apple TV, HomePod,
 * AirPlay 2-compatible receivers) with its network address and capabilities.
 *
 * @since 0.12.0
 */
class AirPlayDevice
{
    /**
     * @param string $deviceId       Unique device identifier from TXT `deviceid`
     * @param string $name          Friendly name (from mDNS name)
     * @param string $host          Device IP address or hostname
     * @param int    $port          Main control port (usually 7000)
     * @param int    $raopPort      RAOP (audio streaming) port from _raop._tcp.local.
     * @param string $model         Model identifier, e.g. "AppleTV5,3"
     * @param bool   $supportsVideo True if device supports video AirPlay
     */
    public function __construct(
        public readonly string $deviceId,
        public readonly string $name,
        public readonly string $host,
        public readonly int $port,
        public readonly int $raopPort,
        public readonly string $model = '',
        public readonly bool $supportsVideo = false,
    ) {
    }

    /**
     * Get the device address in "host:port" format.
     *
     * @return string Address string (e.g., '192.168.1.100:7000')
     *
     * @since 0.12.0
     */
    public function getAddress(): string
    {
        return $this->host . ':' . $this->port;
    }

    /**
     * Get the RAOP address in "host:port" format.
     *
     * @return string RAOP address for audio streaming
     *
     * @since 0.12.0
     */
    public function getRaopAddress(): string
    {
        return $this->host . ':' . $this->raopPort;
    }
}
