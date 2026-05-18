<?php

declare(strict_types=1);

namespace Phlex\Roku;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Discovery\Mdns\MdnsDiscovery;
use Phlex\Discovery\Mdns\MdnsService;

/**
 * Discovers Roku devices on the network via mDNS.
 *
 * Uses MdnsDiscovery::discoverRoku() which queries the
 * `_ roku-ecnp._tcp.local.` service type (note leading space).
 *
 * @since 0.12.0
 */
class RokuDiscovery
{
    /** @var MdnsDiscovery mDNS discovery service */
    private MdnsDiscovery $mdns;

    /** @var StructuredLogger|null Logger instance */
    private ?StructuredLogger $logger;

    /**
     * @param MdnsDiscovery $mdns mDNS discovery service
     * @param StructuredLogger|null $logger Optional logger instance
     *
     * @since 0.12.0
     */
    public function __construct(
        MdnsDiscovery $mdns,
        ?StructuredLogger $logger = null
    ) {
        $this->mdns = $mdns;
        $this->logger = $logger;
    }

    /**
     * Discover all Roku devices on the network.
     *
     * Uses mDNS to discover devices advertising the Roku ECP service
     * (` roku-ecnp._tcp.local.` with leading space).
     *
     * @return RokuDevice[] Array of discovered Roku devices
     *
     * @since 0.12.0
     */
    public function discoverDevices(): array
    {
        $services = $this->mdns->discoverRoku();

        $devices = [];
        foreach ($services as $service) {
            $device = $this->mdnsServiceToDevice($service);
            $devices[] = $device;
        }

        $this->log('info', 'Discovered {count} Roku devices', ['count' => count($devices)]);

        return $devices;
    }

    /**
     * Get detailed device info from ECP.
     *
     * Queries the device's ECP /query/device-info endpoint
     * to obtain friendlyName, modelName, and softwareVersion.
     *
     * @param RokuDevice $device Device to query
     *
     * @return array<string, mixed>|null Device info array or null on failure
     *
     * @since 0.12.0
     */
    public function getDeviceInfo(RokuDevice $device): ?array
    {
        $client = new RokuEcpClient($device->host, $device->port, $this->logger);

        try {
            return $client->getDeviceInfo();
        } catch (\Throwable $e) {
            $this->log('warning', 'Failed to get device info for {deviceId}', [
                'deviceId' => $device->deviceId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Convert an mDNS service to a RokuDevice.
     *
     * @param MdnsService $service mDNS service descriptor
     *
     * @return RokuDevice Converted device
     */
    private function mdnsServiceToDevice(MdnsService $service): RokuDevice
    {
        // Use the instance name or deviceId as the device identifier
        $deviceId = $service->deviceId ?: $service->name;

        // Clean up the name - remove service type suffix
        $name = preg_replace('/\._ roku-ecnp\._tcp\.local\.$/', '', $service->name);
        if ($name === '' || $name === null) {
            $name = 'Roku Device';
        }

        return new RokuDevice(
            deviceId: $deviceId,
            name: $name,
            host: $service->host,
            port: $service->port,
        );
    }

    /**
     * Log a message if logger is available.
     *
     * @param string $level Log level (info, warning, error, debug)
     * @param string $message Log message
     * @param array<string, mixed> $context Log context
     *
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->$level($message, $context);
    }
}
