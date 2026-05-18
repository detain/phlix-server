<?php

declare(strict_types=1);

namespace Phlex\Chromecast;

use Phlex\Common\Logger\StructuredLogger;
use Phlex\Discovery\Mdns\MdnsDiscovery;
use Phlex\Discovery\Mdns\MdnsService;

/**
 * Chromecast device discovery via mDNS.
 *
 * Uses the mDNS discovery service to find Chromecast devices
 * advertising `_googlecast._tcp.local.` service type.
 *
 * @since 0.12.0
 */
class CastDiscovery
{
    /** @var MdnsDiscovery */
    private MdnsDiscovery $mdns;

    /** @var StructuredLogger|null */
    private ?StructuredLogger $logger;

    /**
     * @param MdnsDiscovery $mdns mDNS discovery service
     * @param StructuredLogger|null $logger Optional logger instance
     *
     * @since 0.12.0
     */
    public function __construct(MdnsDiscovery $mdns, ?StructuredLogger $logger = null)
    {
        $this->mdns = $mdns;
        $this->logger = $logger;
    }

    /**
     * Discover all Chromecast devices on the network.
     *
     * Queries mDNS for `_googlecast._tcp.local.` services and
     * returns them as CastDevice objects with parsed metadata.
     *
     * @return CastDevice[] Array of discovered Chromecast devices
     *
     * @since 0.12.0
     */
    public function discoverDevices(): array
    {
        $mdnsServices = $this->mdns->discoverChromecast();

        $devices = [];
        foreach ($mdnsServices as $service) {
            $device = $this->mdnsServiceToCastDevice($service);
            if ($device !== null) {
                $devices[] = $device;
            }
        }

        $this->log('debug', 'Discovered {count} Chromecast devices', ['count' => count($devices)]);

        return $devices;
    }

    /**
     * Get detailed device info from the Cast device's API pages.
     *
     * @param CastDevice $device Device to query
     *
     * @return array<string, mixed>|null Device info array or null on failure
     *
     * @since 0.12.0
     */
    public function getDeviceInfo(CastDevice $device): ?array
    {
        $client = new CastApiClient($device->host, $device->port, $this->logger);

        try {
            $info = $client->connect();
            return $info;
        } catch (\Throwable $e) {
            $this->log('warning', 'Failed to get device info', [
                'device_id' => $device->deviceId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Convert an mDNS service to a CastDevice.
     *
     * @param MdnsService $service mDNS service descriptor
     *
     * @return CastDevice|null CastDevice or null if conversion fails
     */
    private function mdnsServiceToCastDevice(MdnsService $service): ?CastDevice
    {
        // Extract device ID from TXT records or instance name
        $deviceId = $this->extractFromTxt($service->txtRecords, 'id');
        if ($deviceId === '') {
            $deviceId = $service->deviceId;
        }

        // Extract model from TXT records
        $model = $this->extractFromTxt($service->txtRecords, 'md');

        // Extract UUID from TXT records
        $uuid = $this->extractFromTxt($service->txtRecords, 'uuid');
        if ($uuid === '') {
            $uuid = $deviceId;
        }

        // Strip `._googlecast._tcp.local.` suffix from name
        $name = $this->stripServiceName($service->name);

        if ($deviceId === '' || $name === '') {
            $this->log('debug', 'Skipping Chromecast with missing id or name', [
                'name' => $service->name,
            ]);
            return null;
        }

        return new CastDevice(
            $deviceId,
            $name,
            $service->host,
            $service->port,
            $model,
            $uuid
        );
    }

    /**
     * Extract a key's value from TXT records.
     *
     * @param array<string> $txtRecords TXT record values
     * @param string $key Key to extract (without trailing '=')
     *
     * @return string Value or empty string if not found
     */
    private function extractFromTxt(array $txtRecords, string $key): string
    {
        $prefix = $key . '=';
        foreach ($txtRecords as $record) {
            if (str_starts_with($record, $prefix)) {
                return substr($record, strlen($prefix));
            }
        }
        return '';
    }

    /**
     * Strip the service type suffix from a service name.
     *
     * Converts 'Chromecast-xxxx._googlecast._tcp.local.' to 'Chromecast-xxxx'.
     *
     * @param string $name Full mDNS service name
     *
     * @return string Friendly name
     */
    private function stripServiceName(string $name): string
    {
        // Remove `._service_type.local.` suffix
        return preg_replace('/\._[^.]+\.[^.]+\.local\.$/', '', $name) ?: $name;
    }

    /**
     * Log a message if logger is available.
     *
     * @param string $level Log level (debug, info, warning, error)
     * @param string $message Message to log
     * @param array<string, mixed> $context Context data
     *
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger === null) {
            return;
        }

        $formatted = 'CastDiscovery: ' . $message;
        match ($level) {
            'debug' => $this->logger->debug($formatted, $context),
            'info' => $this->logger->info($formatted, $context),
            'warning' => $this->logger->warning($formatted, $context),
            'error' => $this->logger->error($formatted, $context),
            default => $this->logger->info($formatted, $context),
        };
    }
}
