<?php

declare(strict_types=1);

namespace Phlix\LiveTv\Tuners\HdHomeRun;

use Phlix\LiveTv\Tuners\Dvbt\DvbtDevice;
use Phlix\LiveTv\Tuners\HdHomeRun\HdHomeRunDevice;
use Phlix\LiveTv\Tuners\HdHomeRun\HdHomeRunDiscovery;
use Phlix\LiveTv\Tuners\HdHomeRun\HdHomeRunApiClient;
use Phlix\LiveTv\Tuners\Iptv\IptvDevice;
use Phlix\LiveTv\Tuners\TunerDriverInterface;
use Phlix\Common\Logger\StructuredLogger;
use Psr\Log\LoggerInterface;

/**
 * HDHomeRun tuner driver implementing TunerDriverInterface.
 *
 * This driver uses SSDP discovery to find HDHomeRun devices on the local network,
 * and uses an injected {@see HdHomeRunApiClient} for channel listing and streaming.
 *
 * @since 0.12.0
 */
class HdHomeRunTunerDriver implements TunerDriverInterface
{
    /** @var HdHomeRunDiscovery SSDP discovery service */
    private HdHomeRunDiscovery $discovery;

    /** @var HdHomeRunApiClient HTTP API client used for lineup, scan, and stream URLs */
    private HdHomeRunApiClient $apiClient;

    /** @var StructuredLogger|LoggerInterface|null Optional logger */
    private StructuredLogger|LoggerInterface|null $logger;

    /**
     * @param HdHomeRunDiscovery $discovery SSDP discovery service
     * @param HdHomeRunApiClient $apiClient HTTP API client used to talk to HDHomeRun devices
     * @param StructuredLogger|LoggerInterface|null $logger Optional logger instance
     */
    public function __construct(
        HdHomeRunDiscovery $discovery,
        HdHomeRunApiClient $apiClient,
        StructuredLogger|LoggerInterface|null $logger = null
    ) {
        $this->discovery = $discovery;
        $this->apiClient = $apiClient;
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
     * @param HdHomeRunDevice|IptvDevice|DvbtDevice $device The device to query
     * @return array<int, array{channel_number:int, name:string, type:string, transport_stream_id:int, program_id:int|null}> Channel list
     */
    public function getChannelLineup(HdHomeRunDevice|IptvDevice|DvbtDevice $device): array
    {
        if (!$device instanceof HdHomeRunDevice) {
            throw new \InvalidArgumentException('Expected HdHomeRunDevice for HDHomeRun tuner');
        }

        $lineup = $this->apiClient->getChannelLineup();

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
     * @param HdHomeRunDevice|IptvDevice|DvbtDevice $device The device to scan
     * @return array<int, array{channel_number:int, name:string, type:string, transport_stream_id:int, program_id:int|null}> Discovered channels
     */
    public function scanChannels(HdHomeRunDevice|IptvDevice|DvbtDevice $device): array
    {
        if (!$device instanceof HdHomeRunDevice) {
            throw new \InvalidArgumentException('Expected HdHomeRunDevice for HDHomeRun tuner');
        }

        $this->logger?->info('HDHomeRunTunerDriver: triggering channel scan', [
            'device_id' => $device->deviceId,
        ]);

        $this->apiClient->triggerScan();

        // After triggering scan, get updated lineup
        // Small delay to allow scan to start
        usleep(500000); // 500ms

        return $this->apiClient->getChannelLineup();
    }

    /**
     * @inheritDoc
     *
     * Returns the HLS stream URL for the specified channel.
     *
     * @param HdHomeRunDevice|IptvDevice|DvbtDevice $device The device to use
     * @param int $channelNumber The channel number to tune
     * @return string The HLS stream URL
     */
    public function getStreamUrl(HdHomeRunDevice|IptvDevice|DvbtDevice $device, int $channelNumber): string
    {
        if (!$device instanceof HdHomeRunDevice) {
            throw new \InvalidArgumentException('Expected HdHomeRunDevice for HDHomeRun tuner');
        }

        $streamUrl = $this->apiClient->getStreamUrl($channelNumber);

        $this->logger?->info('HDHomeRunTunerDriver: got stream URL', [
            'device_id' => $device->deviceId,
            'channel' => $channelNumber,
            'stream_url' => $streamUrl,
        ]);

        return $streamUrl;
    }
}
