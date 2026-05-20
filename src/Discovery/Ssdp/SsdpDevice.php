<?php

declare(strict_types=1);

namespace Phlix\Discovery\Ssdp;

/**
 * Discovered SSDP device descriptor.
 *
 * Represents a device discovered via SSDP (Simple Service Discovery Protocol),
 * containing the Unique Service Name (USN), Notification Type (NT), device
 * description URL, server string, and cache timeout.
 *
 * @since 0.12.0
 */
class SsdpDevice
{
    /**
     * @param string $usn Unique Service Name - device identifier
     * @param string $nt Notification Type - UPnP device type
     * @param string $location Device description URL (host:port format)
     * @param string $server Server string (OS/version info)
     * @param int $cacheTimeout MAX-AGE seconds from CACHE-CONTROL header
     * @param string|null $deviceType Optional parsed device type from NT
     */
    public function __construct(
        public readonly string $usn,
        public readonly string $nt,
        public readonly string $location,
        public readonly string $server,
        public readonly int $cacheTimeout,
        public readonly ?string $deviceType = null
    ) {
    }

    /**
     * Extract UUID from USN (Unique Service Name).
     *
     * USN format is typically: uuid:device-UUID::urn:schemas-upnp-org:device:...
     * or just: uuid:device-UUID
     *
     * @return string Extracted UUID or empty string if not found
     *
     * @since 0.12.0
     */
    public function getDeviceId(): string
    {
        if (strpos($this->usn, 'uuid:') === 0) {
            $remainder = substr($this->usn, 5);
            $parts = explode('::', $remainder);
            return $parts[0];
        }

        return '';
    }

    /**
     * Parse Location host:port into a base URL.
     *
     * Extracts the scheme, host, and port from the Location URL.
     *
     * @return string|null Base URL (e.g., 'http://192.168.1.100:8200') or null if invalid
     *
     * @since 0.12.0
     */
    public function getBaseUrl(): ?string
    {
        if ($this->location === '') {
            return null;
        }

        // Already a full URL
        if (strpos($this->location, 'http://') === 0 || strpos($this->location, 'https://') === 0) {
            $parsed = parse_url($this->location);
            if ($parsed === false) {
                return null;
            }

            $scheme = $parsed['scheme'] ?? 'http';
            $host = $parsed['host'] ?? '';
            $port = $parsed['port'] ?? 80;

            if ($host === '') {
                return null;
            }

            return "{$scheme}://{$host}:{$port}";
        }

        // Just host:port format
        if (strpos($this->location, ':') !== false) {
            $parts = explode(':', $this->location);
            if (count($parts) === 2) {
                return 'http://' . $this->location;
            }
        }

        return null;
    }
}
