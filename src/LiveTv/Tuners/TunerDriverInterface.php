<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Tuners;

use Phlex\LiveTv\Tuners\HdHomeRun\HdHomeRunDevice;
use Phlex\LiveTv\Tuners\Iptv\IptvDevice;

/**
 * Interface for tuner device drivers.
 *
 * All tuner drivers (HDHomeRun, IPTV, DVB, etc.) must implement this interface
 * to be used by LiveTvManager for device discovery, channel listing, and streaming.
 *
 * @since 0.12.0
 */
interface TunerDriverInterface
{
    /**
     * Return the driver name identifier.
     *
     * @return string Driver name (e.g. 'hdhomerun', 'iptv', 'dvb_t')
     */
    public function getName(): string;

    /**
     * Discover all available tuner devices on the network.
     *
     * @return array<HdHomeRunDevice|IptvDevice> List of discovered devices
     */
    public function discoverDevices(): array;

    /**
     * Get the channel lineup for a discovered device.
     *
     * @param HdHomeRunDevice|IptvDevice $device The device to query
     * @return array<int, array{channel_number:int, name:string, type:string, transport_stream_id:int|null, program_id:int|null}> Channel list
     */
    public function getChannelLineup(HdHomeRunDevice|IptvDevice $device): array;

    /**
     * Trigger a channel scan on the device.
     *
     * @param HdHomeRunDevice|IptvDevice $device The device to scan
     * @return array<int, array{channel_number:int, name:string, type:string, transport_stream_id:int|null, program_id:int|null}> Discovered channels
     */
    public function scanChannels(HdHomeRunDevice|IptvDevice $device): array;

    /**
     * Get the HLS stream URL for a channel number.
     *
     * @param HdHomeRunDevice|IptvDevice $device The device to use
     * @param int $channelNumber The channel number to tune
     * @return string The stream URL
     */
    public function getStreamUrl(HdHomeRunDevice|IptvDevice $device, int $channelNumber): string;
}
