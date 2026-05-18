<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\AirPlay;

use PHPUnit\Framework\TestCase;
use Phlex\AirPlay\AirPlayDevice;
use Phlex\AirPlay\AirPlayDiscovery;
use Phlex\Discovery\Mdns\MdnsDiscovery;
use Phlex\Discovery\Mdns\MdnsService;

class AirPlayDiscoveryTest extends TestCase
{
    public function test_discover_devices_returns_airplay_devices(): void
    {
        // Create mock mDNS discovery returning AirPlay services
        $mockMdns = $this->createMock(MdnsDiscovery::class);

        $airplayService = new MdnsService(
            name: 'Living Room-AA:BB:CC:DD:EE:FF._airplay._tcp.local.',
            type: MdnsDiscovery::SERVICE_AIRPLAY,
            port: 7000,
            host: '192.168.1.100',
            txtRecords: ['deviceid=AA:BB:CC:DD:EE:FF', 'model=AppleTV5,3', 'features=0x5A7FFFF7'],
            deviceId: 'AA:BB:CC:DD:EE:FF',
        );

        $raopService = new MdnsService(
            name: 'Living Room-AA:BB:CC:DD:EE:FF._raop._tcp.local.',
            type: MdnsDiscovery::SERVICE_RAOP,
            port: 7000,
            host: '192.168.1.100',
            txtRecords: ['deviceid=AA:BB:CC:DD:EE:FF'],
            deviceId: 'AA:BB:CC:DD:EE:FF',
        );

        $mockMdns->method('discoverAirPlay')
            ->willReturn([$airplayService, $raopService]);

        $discovery = new AirPlayDiscovery($mockMdns);
        $devices = $discovery->discoverDevices();

        $this->assertCount(1, $devices);

        $device = $devices[0];
        $this->assertInstanceOf(AirPlayDevice::class, $device);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $device->deviceId);
        $this->assertSame('192.168.1.100', $device->host);
        $this->assertSame(7000, $device->port);
        $this->assertSame(7000, $device->raopPort);
        $this->assertSame('AppleTV5,3', $device->model);
    }

    public function test_discover_devices_returns_empty_when_no_services(): void
    {
        $mockMdns = $this->createMock(MdnsDiscovery::class);
        $mockMdns->method('discoverAirPlay')
            ->willReturn([]);

        $discovery = new AirPlayDiscovery($mockMdns);
        $devices = $discovery->discoverDevices();

        $this->assertSame([], $devices);
    }

    public function test_device_name_extracted_from_instance_name(): void
    {
        $mockMdns = $this->createMock(MdnsDiscovery::class);

        $service = new MdnsService(
            name: 'Living Room-AA:BB:CC:DD:EE:FF._raop._tcp.local.',
            type: MdnsDiscovery::SERVICE_RAOP,
            port: 7000,
            host: '192.168.1.200',
            txtRecords: [],
            deviceId: '',
        );

        $mockMdns->method('discoverAirPlay')
            ->willReturn([$service]);

        $discovery = new AirPlayDiscovery($mockMdns);
        $devices = $discovery->discoverDevices();

        $this->assertCount(1, $devices);
        $this->assertSame('Living Room', $devices[0]->name);
    }
}
