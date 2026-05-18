<?php

declare(strict_types=1);

namespace Phlex\Discovery\Mdns;

/**
 * Discovered mDNS service descriptor.
 *
 * Represents a service discovered via mDNS (multicast DNS / Bonjour/Avahi),
 * such as Chromecast, AirPlay, or Roku devices.
 *
 * @since 0.12.0
 */
class MdnsService
{
    /**
     * @param string $name Full service name (e.g., 'Chromecast-xxxx._googlecast._tcp.local.')
     * @param string $type Service type (e.g., '_googlecast._tcp.local.')
     * @param int $port Service port number
     * @param string $host Hostname or IP address
     * @param array<string> $txtRecords TXT record values
     * @param string $deviceId Unique device identifier extracted from name or TXT
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly int $port,
        public readonly string $host,
        public readonly array $txtRecords = [],
        public readonly string $deviceId = ''
    ) {
    }

    /**
     * Get the address in "host:port" format.
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
