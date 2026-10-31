<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Roku;

use PHPUnit\Framework\TestCase;
use Phlix\Discovery\Mdns\MdnsDiscovery;
use Phlix\Discovery\Mdns\MdnsService;
use Phlix\Roku\RokuDevice;
use Phlix\Roku\RokuDiscovery;

class RokuDiscoveryTest extends TestCase
{
    public function testDiscoverDevicesReturnsRokuDevices(): void
    {
        // Create mock mDNS service
        $mdnsService = new MdnsService(
            name: 'Living Room Roku._roku-ecnp._tcp.local.',
            type: '_roku-ecnp._tcp.local.',
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

    public function testRealAdvertisementShapeStripsToTheFriendlyName(): void
    {
        // minors M-SERVER-82: the strip law had never been pinned — with the
        // old stray-space regex this very suffix rode into the device name.
        $mdnsService = new MdnsService(
            name: 'Family Room._roku-ecnp._tcp.local.',
            type: '_roku-ecnp._tcp.local.',
            port: 8060,
            host: '192.168.1.102',
            txtRecords: [],
            deviceId: 'roku-def123'
        );

        $mockMdns = $this->createMock(MdnsDiscovery::class);
        $mockMdns->method('discoverRoku')->willReturn([$mdnsService]);

        $discovery = new RokuDiscovery($mockMdns);
        $devices = $discovery->discoverDevices();

        $this->assertCount(1, $devices);
        $this->assertEquals('Family Room', $devices[0]->name);
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
                name: 'Living Room._roku-ecnp._tcp.local.',
                type: '_roku-ecnp._tcp.local.',
                port: 8060,
                host: '192.168.1.100',
                txtRecords: [],
                deviceId: 'roku-100'
            ),
            new MdnsService(
                name: 'Bedroom._roku-ecnp._tcp.local.',
                type: '_roku-ecnp._tcp.local.',
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
