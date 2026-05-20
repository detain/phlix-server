<?php

declare(strict_types=1);

namespace Phlix\AirPlay;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Discovery\Mdns\MdnsDiscovery;
use Phlix\Discovery\Mdns\MdnsService;

/**
 * Discovers AirPlay 2 devices via mDNS.
 *
 * Uses MdnsDiscovery to query _airplay._tcp.local. and _raop._tcp.local.
 * for AirPlay-compatible devices on the local network.
 *
 * @since 0.12.0
 */
class AirPlayDiscovery
{
    /** @var MdnsDiscovery mDNS discovery service */
    private MdnsDiscovery $mdns;

    /** @var StructuredLogger|null Optional logger */
    private ?StructuredLogger $logger;

    /**
     * @param MdnsDiscovery    $mdns   mDNS discovery service
     * @param StructuredLogger|null $logger Optional logger
     */
    public function __construct(
        MdnsDiscovery $mdns,
        ?StructuredLogger $logger = null,
    ) {
        $this->mdns = $mdns;
        $this->logger = $logger;
    }

    /**
     * Discover all AirPlay devices on the network.
     *
     * Queries both _airplay._tcp.local. (control) and _raop._tcp.local. (audio).
     * Merges results and extracts device capabilities from TXT records.
     *
     * @return AirPlayDevice[] Array of discovered AirPlay devices
     *
     * @since 0.12.0
     */
    public function discoverDevices(): array
    {
        $this->logger?->debug('AirPlayDiscovery: Starting device discovery');

        $services = $this->mdns->discoverAirPlay();

        if (empty($services)) {
            $this->logger?->debug('AirPlayDiscovery: No services found');
            return [];
        }

        $this->logger?->debug('AirPlayDiscovery: Found {count} mDNS services', [
            'count' => count($services),
        ]);

        // Group services by device ID to handle both control and RAOP ports
        /** @var array<string, array{airplay?: MdnsService, raop?: MdnsService}> $grouped */
        $grouped = [];
        foreach ($services as $service) {
            $deviceId = $service->deviceId ?: $this->extractDeviceIdFromService($service);
            if (!isset($grouped[$deviceId])) {
                $grouped[$deviceId] = [];
            }

            if ($service->type === MdnsDiscovery::SERVICE_AIRPLAY) {
                $grouped[$deviceId]['airplay'] = $service;
            } elseif ($service->type === MdnsDiscovery::SERVICE_RAOP) {
                $grouped[$deviceId]['raop'] = $service;
            }
        }

        $devices = [];
        foreach ($grouped as $deviceId => $group) {
            $airplay = $group['airplay'] ?? null;
            $raop = $group['raop'] ?? null;

            // Primary service is airplay (has control port); fallback to raop
            $primary = $airplay ?? $raop;
            if ($primary === null) {
                continue;
            }

            $devices[] = $this->buildDevice($deviceId, $primary, $raop);
        }

        $this->logger?->info('AirPlayDiscovery: Discovered {count} AirPlay devices', [
            'count' => count($devices),
        ]);

        return $devices;
    }

    /**
     * Build an AirPlayDevice from mDNS services.
     *
     * @param string      $deviceId Device identifier
     * @param MdnsService $primary Primary service (airplay or raop)
     * @param MdnsService|null $raop RAOP service if available
     *
     * @return AirPlayDevice
     */
    private function buildDevice(string $deviceId, MdnsService $primary, ?MdnsService $raop): AirPlayDevice
    {
        $name = $this->extractName($primary->name);
        $txtRecords = $primary->txtRecords;

        // Extract model from TXT records
        $model = $this->extractTxtValue($txtRecords, 'model') ?: '';

        // Check features for video support
        $features = $this->extractTxtValue($txtRecords, 'features') ?: '0';
        $supportsVideo = $this->extractVideoSupport($features);

        // RAOP port from _raop._tcp.local. SRV record
        $raopPort = $raop !== null ? $raop->port : $primary->port;

        return new AirPlayDevice(
            $deviceId,
            $name,
            $primary->host,
            $primary->port,
            $raopPort,
            $model,
            $supportsVideo,
        );
    }

    /**
     * Extract device ID from service if not in TXT records.
     *
     * @param MdnsService $service mDNS service
     *
     * @return string Device ID
     */
    private function extractDeviceIdFromService(MdnsService $service): string
    {
        // Try to extract from instance name format: DeviceName-xxxx.serial._service._type.local.
        if (preg_match('/^([^-]+)-([^-]+)/', $service->name, $matches)) {
            return $matches[2];
        }

        return $service->name;
    }

    /**
     * Extract friendly name from mDNS instance name.
     *
     * @param string $instanceName Full mDNS instance name
     *
     * @return string Friendly name
     */
    private function extractName(string $instanceName): string
    {
        // Remove service type suffix: "Apple TV-xxxx._airplay._tcp.local." -> "Apple TV-xxxx"
        // Also handles MAC address suffix like "Apple TV-AA:BB:CC:DD:EE:FF._airplay._tcp.local."
        // Pattern captures name up to (but not including) the MAC address suffix or numeric suffix
        if (preg_match('/^(.+?)(?:[-][0-9A-F:]+)?\._(?:airplay|raop)\._tcp\.local\.$/', $instanceName, $matches)) {
            return str_replace('-', ' ', trim($matches[1]));
        }

        // Fallback: remove common suffixes
        $pattern = '/\._(?:airplay|raop|googlecast|roku-ecnp)\._tcp\.local\.$/';
        return preg_replace($pattern, '', $instanceName) ?: $instanceName;
    }

    /**
     * Extract a value from TXT records by key.
     *
     * @param array<string> $txtRecords TXT record values
     * @param string        $key       Key to find
     *
     * @return string|null Value or null if not found
     */
    private function extractTxtValue(array $txtRecords, string $key): ?string
    {
        $prefix = $key . '=';
        foreach ($txtRecords as $txt) {
            if (str_starts_with($txt, $prefix)) {
                return substr($txt, strlen($prefix));
            }
        }
        return null;
    }

    /**
     * Determine if device supports video from features flag.
     *
     * @param string $features Hex features flags
     *
     * @return bool True if video is supported
     */
    private function extractVideoSupport(string $features): bool
    {
        // Features is a hex value; video support bit varies by device
        // Most Apple TVs and AirPlay 2 receivers support video
        // Simple heuristic: if features != 0, likely supports something
        if ($features === '0' || $features === '') {
            return false;
        }

        // Parse hex features - Video support is typically bit 3 (0x08)
        $flags = hexdec($features);
        return ($flags & 0x08) !== 0;
    }
}
