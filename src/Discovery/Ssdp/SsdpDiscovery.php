<?php

declare(strict_types=1);

namespace Phlex\Discovery\Ssdp;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * SSDP discovery service for finding DLNA/UPnP devices on the network.
 *
 * Provides methods to discover devices via M-SEARCH requests and to
 * announce the Phlex server via SSDP NOTIFY messages.
 *
 * @since 0.12.0
 */
class SsdpDiscovery
{
    /** Default search target for all UPnP devices */
    public const DEFAULT_ST = 'urn:schemas-upnp-org:device:*';

    /** Default search target for MediaServer devices */
    public const ST_MEDIA_SERVER = 'urn:schemas-upnp-org:device:MediaServer:1';

    /** Default search target for MediaRenderer devices */
    public const ST_MEDIA_RENDERER = 'urn:schemas-upnp-org:device:MediaRenderer:1';

    /** @var SsdpSocket */
    private SsdpSocket $socket;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /**
     * @param SsdpSocket $socket SSDP socket instance
     * @param LoggerInterface|null $logger Optional logger
     */
    public function __construct(
        SsdpSocket $socket,
        ?LoggerInterface $logger = null
    ) {
        $this->socket = $socket;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Discover all DLNA/UPnP devices on the network.
     *
     * Sends an M-SEARCH request and collects responses, then parses
     * each response into SsdpDevice objects.
     *
     * @param string $st Search target (default: all UPnP devices)
     * @return SsdpDevice[] Array of discovered devices
     *
     * @since 0.12.0
     */
    public function discoverDevices(string $st = self::DEFAULT_ST): array
    {
        $responses = $this->socket->search($st, 3);

        if (empty($responses)) {
            $this->logger->info('SSDP: No devices discovered');
            return [];
        }

        $devices = [];
        foreach ($responses as $response) {
            $parsed = $this->socket->parseResponse($response);
            if ($parsed === null) {
                continue;
            }

            $device = $this->createDeviceFromParsed($parsed);
            if ($device !== null) {
                $devices[] = $device;
            }
        }

        $this->logger->debug('SSDP: Discovered {count} devices', ['count' => count($devices)]);
        return $devices;
    }

    /**
     * Announce the Phlex server via SSDP NOTIFY.
     *
     * @param string $serverId Unique server identifier (UUID)
     * @param string $friendlyName Human-readable server name
     * @param string $baseUrl Base URL of the Phlex server
     * @param int $port Phlex server port
     *
     * @since 0.12.0
     */
    public function announceServer(string $serverId, string $friendlyName, string $baseUrl, int $port): void
    {
        $usn = "uuid:phlex-server-{$serverId}::urn:schemas-upnp-org:device:MediaServer:1";
        $nt = 'urn:schemas-upnp-org:device:MediaServer:1';
        $location = rtrim($baseUrl, '/') . ':' . $port;

        $this->socket->announce($nt, $location, $usn);

        $this->logger->info('SSDP: Announced server', [
            'serverId' => $serverId,
            'friendlyName' => $friendlyName,
            'location' => $location,
        ]);
    }

    /**
     * Parse a device description URL and return parsed data.
     *
     * Fetches the device description XML and extracts key information.
     *
     * @param string $locationUrl Device description URL
     * @return array<string, mixed>|null Parsed device data or null on failure
     *
     * @since 0.12.0
     */
    public function resolveDeviceDescription(string $locationUrl): ?array
    {
        if ($locationUrl === '') {
            return null;
        }

        // Ensure URL has scheme
        if (strpos($locationUrl, 'http') !== 0) {
            $locationUrl = 'http://' . $locationUrl;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $xmlContent = @file_get_contents($locationUrl, false, $context);
        if ($xmlContent === false) {
            $this->logger->warning('SSDP: Failed to fetch device description', ['url' => $locationUrl]);
            return null;
        }

        $xml = @simplexml_load_string($xmlContent);
        if ($xml === false) {
            $this->logger->warning('SSDP: Invalid XML in device description', ['url' => $locationUrl]);
            return null;
        }

        $result = [
            'url' => $locationUrl,
            'xml' => $xmlContent,
        ];

        // Try to extract device info from XML
        if (isset($xml->device)) {
            $device = $xml->device;
            $result['deviceType'] = (string)($device->deviceType ?? '');
            $result['friendlyName'] = (string)($device->friendlyName ?? '');
            $result['manufacturer'] = (string)($device->manufacturer ?? '');
            $result['modelName'] = (string)($device->modelName ?? '');
            $result['udn'] = (string)($device->UDN ?? '');
        }

        return $result;
    }

    /**
     * Create an SsdpDevice from parsed SSDP response fields.
     *
     * @param array<string, string> $parsed Parsed response fields
     * @return SsdpDevice|null Device instance or null if invalid
     */
    private function createDeviceFromParsed(array $parsed): ?SsdpDevice
    {
        $usn = $parsed['USN'] ?? '';
        $nt = $parsed['NT'] ?? '';
        $location = $parsed['LOCATION'] ?? '';
        $server = $parsed['SERVER'] ?? '';
        $cacheControl = $parsed['CACHE-CONTROL'] ?? '';

        if ($usn === '' || $nt === '') {
            return null;
        }

        $cacheTimeout = $this->parseCacheTimeout($cacheControl);

        // Try to extract device type from NT
        $deviceType = null;
        if (strpos($nt, 'urn:') === 0) {
            $deviceType = $nt;
        }

        return new SsdpDevice(
            $usn,
            $nt,
            $location,
            $server,
            $cacheTimeout,
            $deviceType
        );
    }

    /**
     * Parse CACHE-CONTROL max-age value.
     *
     * @param string $cacheControl CACHE-CONTROL header value
     * @return int Timeout in seconds
     */
    private function parseCacheTimeout(string $cacheControl): int
    {
        if (preg_match('/max-age=(\d+)/', $cacheControl, $matches)) {
            return (int)$matches[1];
        }

        return 1800; // Default 30 minutes
    }
}
