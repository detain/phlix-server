<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Tuners\HdHomeRun;

use Phlex\LiveTv\Tuners\HdHomeRun\HdHomeRunDevice;
use Phlex\LiveTv\Tuners\HdHomeRun\HdHomeRunDiscovery;
use Phlex\LiveTv\Tuners\HdHomeRun\HdHomeRunApiClient;
use Phlex\LiveTv\Tuners\TunerDriverInterface;
use Phlex\Common\Logger\StructuredLogger;
use Psr\Log\LoggerInterface;

/**
 * HDHomeRun tuner driver implementing TunerDriverInterface.
 *
 * This driver uses SSDP discovery to find HDHomeRun devices on the local network,
 * and uses the HDHomeRun HTTP API for channel listing and streaming.
 *
 * @since 0.12.0
 */
class HdHomeRunTunerDriver implements TunerDriverInterface
{
    /** @var HdHomeRunDiscovery SSDP discovery service */
    private HdHomeRunDiscovery $discovery;

    /** @var StructuredLogger|LoggerInterface|null Optional logger */
    private StructuredLogger|LoggerInterface|null $logger;

    /**
     * @param HdHomeRunDiscovery $discovery SSDP discovery service
     * @param StructuredLogger|LoggerInterface|null $logger Optional logger instance
     */
    public function __construct(
        HdHomeRunDiscovery $discovery,
        StructuredLogger|LoggerInterface|null $logger = null
    ) {
        $this->discovery = $discovery;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'hdhomerun';
    }

    /**
     * @inheritDoc
     *
     * Uses SSDP discovery to find HDHomeRun devices on the network.
     *
     * @return HdHomeRunDevice[] Array of discovered devices
     */
    public function discoverDevices(): array
    {
        $this->logger?->info('HDHomeRunTunerDriver: discovering devices');

        $devices = $this->discovery->discover();

        $this->logger?->info('HDHomeRunTunerDriver: discovered devices', ['count' => count($devices)]);

        return $devices;
    }

    /**
     * @inheritDoc
     *
     * Uses HDHomeRun HTTP API to get channel lineup.
     *
     * @param HdHomeRunDevice $device The device to query
     * @return array<int, array{channel_number:int, name:string, type:string, transport_stream_id:int, program_id:int|null}> Channel list
     */
    public function getChannelLineup(HdHomeRunDevice $device): array
    {
        $client = $this->createApiClient($device);
        $lineup = $client->getChannelLineup();

        $this->logger?->info('HDHomeRunTunerDriver: got channel lineup', [
            'device_id' => $device->deviceId,
            'channel_count' => count($lineup),
        ]);

        return $lineup;
    }

    /**
     * @inheritDoc
     *
     * Triggers a channel scan on the device via HTTP API.
     *
     * @param HdHomeRunDevice $device The device to scan
     * @return array<int, array{channel_number:int, name:string, type:string, transport_stream_id:int, program_id:int|null}> Discovered channels
     */
    public function scanChannels(HdHomeRunDevice $device): array
    {
        $client = $this->createApiClient($device);

        $this->logger?->info('HDHomeRunTunerDriver: triggering channel scan', [
            'device_id' => $device->deviceId,
        ]);

        $client->triggerScan();

        // After triggering scan, get updated lineup
        // Small delay to allow scan to start
        usleep(500000); // 500ms

        return $client->getChannelLineup();
    }

    /**
     * @inheritDoc
     *
     * Returns the HLS stream URL for the specified channel.
     *
     * @param HdHomeRunDevice $device The device to use
     * @param int $channelNumber The channel number to tune
     * @return string The HLS stream URL
     */
    public function getStreamUrl(HdHomeRunDevice $device, int $channelNumber): string
    {
        $client = $this->createApiClient($device);

        $streamUrl = $client->getStreamUrl($channelNumber);

        $this->logger?->info('HDHomeRunTunerDriver: got stream URL', [
            'device_id' => $device->deviceId,
            'channel' => $channelNumber,
            'stream_url' => $streamUrl,
        ]);

        return $streamUrl;
    }

    /**
     * Create an API client for a specific device.
     *
     * @param HdHomeRunDevice $device The device to create a client for
     * @return HdHomeRunApiClient Configured API client for the device
     */
    private function createApiClient(HdHomeRunDevice $device): HdHomeRunApiClient
    {
        return new HdHomeRunApiClient($device->getBaseUrl(), $this->logger);
    }
}
