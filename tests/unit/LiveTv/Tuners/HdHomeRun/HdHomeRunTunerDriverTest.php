<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\LiveTv\Tuners\HdHomeRun;

use PHPUnit\Framework\TestCase;
use Phlex\LiveTv\Tuners\HdHomeRun\HdHomeRunApiClient;
use Phlex\LiveTv\Tuners\HdHomeRun\HdHomeRunDevice;
use Phlex\LiveTv\Tuners\HdHomeRun\HdHomeRunDiscovery;
use Phlex\LiveTv\Tuners\HdHomeRun\HdHomeRunTunerDriver;

class HdHomeRunTunerDriverTest extends TestCase
{
    public function testGetNameReturnsHdhomerun(): void
    {
        $discovery = new HdHomeRunDiscovery(null, 1);
        $apiClient = new HdHomeRunApiClient('http://127.0.0.1');
        $driver = new HdHomeRunTunerDriver($discovery, $apiClient);

        $this->assertEquals('hdhomerun', $driver->getName());
    }

    public function testDiscoverDevicesDelegatesToDiscovery(): void
    {
        $discovery = $this->createMock(HdHomeRunDiscovery::class);
        $apiClient = new HdHomeRunApiClient('http://127.0.0.1');

        $device = new HdHomeRunDevice(
            deviceId: '12345678',
            ipAddress: '192.168.1.100',
            tunerCount: 2,
            lineupUrl: 'http://192.168.1.100/lineup.json'
        );

        $discovery->method('discover')
            ->willReturn([$device]);

        $driver = new HdHomeRunTunerDriver($discovery, $apiClient);

        $devices = $driver->discoverDevices();

        $this->assertCount(1, $devices);
        $this->assertSame($device, $devices[0]);
    }

    public function testDiscoverDevicesReturnsEmptyOnNetworkError(): void
    {
        $discovery = $this->createMock(HdHomeRunDiscovery::class);
        $apiClient = new HdHomeRunApiClient('http://127.0.0.1');

        $discovery->method('discover')
            ->willReturn([]);

        $driver = new HdHomeRunTunerDriver($discovery, $apiClient);

        $devices = $driver->discoverDevices();

        $this->assertIsArray($devices);
        $this->assertEmpty($devices);
    }

    public function testGetStreamUrlUsesDeviceIp(): void
    {
        $discovery = new HdHomeRunDiscovery(null, 1);
        $apiClient = $this->createMock(HdHomeRunApiClient::class);
        $driver = new HdHomeRunTunerDriver($discovery, $apiClient);

        $device = new HdHomeRunDevice(
            deviceId: '12345678',
            ipAddress: '192.168.1.200',
            tunerCount: 2,
            lineupUrl: 'http://192.168.1.200/lineup.json'
        );

        $apiClient->method('getStreamUrl')
            ->with(10)
            ->willReturn('http://192.168.1.200/watch?channel=10');

        $streamUrl = $driver->getStreamUrl($device, 10);

        $this->assertEquals('http://192.168.1.200/watch?channel=10', $streamUrl);
    }

    public function testGetChannelLineupDelegatesToApiClient(): void
    {
        $discovery = new HdHomeRunDiscovery(null, 1);
        $apiClient = $this->createMock(HdHomeRunApiClient::class);
        $driver = new HdHomeRunTunerDriver($discovery, $apiClient);

        $device = new HdHomeRunDevice(
            deviceId: '12345678',
            ipAddress: '192.168.1.100',
            tunerCount: 2,
            lineupUrl: 'http://192.168.1.100/lineup.json'
        );

        $expectedLineup = [
            ['channel_number' => 2, 'name' => 'ABC', 'type' => 'off', 'transport_stream_id' => 1, 'program_id' => null],
            ['channel_number' => 5, 'name' => 'NBC', 'type' => 'off', 'transport_stream_id' => 2, 'program_id' => null],
        ];

        $apiClient->method('getChannelLineup')
            ->willReturn($expectedLineup);

        $lineup = $driver->getChannelLineup($device);

        $this->assertEquals($expectedLineup, $lineup);
    }

    public function testScanChannelsTriggersScanAndReturnsLineup(): void
    {
        $discovery = new HdHomeRunDiscovery(null, 1);
        $apiClient = $this->createMock(HdHomeRunApiClient::class);
        $driver = new HdHomeRunTunerDriver($discovery, $apiClient);

        $device = new HdHomeRunDevice(
            deviceId: '12345678',
            ipAddress: '192.168.1.100',
            tunerCount: 2,
            lineupUrl: 'http://192.168.1.100/lineup.json'
        );

        $apiClient->expects($this->once())
            ->method('triggerScan');

        $expectedLineup = [
            ['channel_number' => 2, 'name' => 'ABC', 'type' => 'off', 'transport_stream_id' => 1, 'program_id' => null],
        ];

        $apiClient->method('getChannelLineup')
            ->willReturn($expectedLineup);

        $lineup = $driver->scanChannels($device);

        $this->assertEquals($expectedLineup, $lineup);
    }
}
