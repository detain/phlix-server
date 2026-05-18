<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Roku;

use PHPUnit\Framework\TestCase;
use Phlex\Discovery\Mdns\MdnsDiscovery;
use Phlex\Discovery\Mdns\MdnsService;
use Phlex\Roku\RokuDevice;
use Phlex\Roku\RokuDiscovery;

class RokuDiscoveryTest extends TestCase
{
    public function testDiscoverDevicesReturnsRokuDevices(): void
    {
        // Create mock mDNS service
        $mdnsService = new MdnsService(
            name: 'Living Room Roku._ roku-ecnp._tcp.local.',
            type: ' roku-ecnp._tcp.local.',
            port: 8060,
            host: '192.168.1.100',
            txtRecords: [],
            deviceId: 'roku-abc123'
        );

        // Create mock MdnsDiscovery
        $mockMdns = $this->createMock(MdnsDiscovery::class);
        $mockMdns->method('discoverRoku')
            ->willReturn([$mdnsService]);

        $discovery = new RokuDiscovery($mockMdns);
        $devices = $discovery->discoverDevices();

        $this->assertCount(1, $devices);
        $this->assertInstanceOf(RokuDevice::class, $devices[0]);
        $this->assertEquals('192.168.1.100', $devices[0]->host);
        $this->assertEquals(8060, $devices[0]->port);
    }

    public function testDiscoverReturnsEmptyOnNetworkError(): void
    {
        $mockMdns = $this->createMock(MdnsDiscovery::class);
        $mockMdns->method('discoverRoku')
            ->willReturn([]);

        $discovery = new RokuDiscovery($mockMdns);
        $devices = $discovery->discoverDevices();

        $this->assertCount(0, $devices);
    }

    public function testDiscoverDevicesWithMultipleServices(): void
    {
        $services = [
            new MdnsService(
                name: 'Living Room._ roku-ecnp._tcp.local.',
                type: ' roku-ecnp._tcp.local.',
                port: 8060,
                host: '192.168.1.100',
                txtRecords: [],
                deviceId: 'roku-100'
            ),
            new MdnsService(
                name: 'Bedroom._ roku-ecnp._tcp.local.',
                type: ' roku-ecnp._tcp.local.',
                port: 8060,
                host: '192.168.1.101',
                txtRecords: [],
                deviceId: 'roku-101'
            ),
        ];

        $mockMdns = $this->createMock(MdnsDiscovery::class);
        $mockMdns->method('discoverRoku')
            ->willReturn($services);

        $discovery = new RokuDiscovery($mockMdns);
        $devices = $discovery->discoverDevices();

        $this->assertCount(2, $devices);
        $this->assertEquals('192.168.1.100', $devices[0]->host);
        $this->assertEquals('192.168.1.101', $devices[1]->host);
    }
}
