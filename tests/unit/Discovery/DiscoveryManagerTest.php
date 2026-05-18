<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Discovery;

use PHPUnit\Framework\TestCase;
use Phlex\Discovery\DiscoveryManager;
use Phlex\Discovery\Mdns\MdnsDiscovery;
use Phlex\Discovery\Mdns\MdnsService;
use Phlex\Discovery\Ssdp\SsdpDevice;
use Phlex\Discovery\Ssdp\SsdpDiscovery;

class DiscoveryManagerTest extends TestCase
{
    public function testDiscoverDlnaServersDelegatesToSsdp(): void
    {
        $ssdp = $this->createMock(SsdpDiscovery::class);
        $ssdp->expects($this->once())
            ->method('discoverDevices')
            ->with(SsdpDiscovery::ST_MEDIA_SERVER)
            ->willReturn([]);

        $mdns = $this->createMock(MdnsDiscovery::class);

        $manager = new DiscoveryManager($ssdp, $mdns, null);
        $result = $manager->discoverDlnaServers();

        $this->assertIsArray($result);
    }

    public function testDiscoverDlnaRenderersDelegatesToSsdp(): void
    {
        $ssdp = $this->createMock(SsdpDiscovery::class);
        $ssdp->expects($this->once())
            ->method('discoverDevices')
            ->with(SsdpDiscovery::ST_MEDIA_RENDERER)
            ->willReturn([]);

        $mdns = $this->createMock(MdnsDiscovery::class);

        $manager = new DiscoveryManager($ssdp, $mdns, null);
        $result = $manager->discoverDlnaRenderers();

        $this->assertIsArray($result);
    }

    public function testDiscoverChromecastDevicesDelegatesToMdns(): void
    {
        $ssdp = $this->createMock(SsdpDiscovery::class);
        $mdns = $this->createMock(MdnsDiscovery::class);
        $mdns->expects($this->once())
            ->method('discoverChromecast')
            ->willReturn([]);

        $manager = new DiscoveryManager($ssdp, $mdns, null);
        $result = $manager->discoverChromecastDevices();

        $this->assertIsArray($result);
    }

    public function testDiscoverAirPlayDevicesDelegatesToMdns(): void
    {
        $ssdp = $this->createMock(SsdpDiscovery::class);
        $mdns = $this->createMock(MdnsDiscovery::class);
        $mdns->expects($this->once())
            ->method('discoverAirPlay')
            ->willReturn([]);

        $manager = new DiscoveryManager($ssdp, $mdns, null);
        $result = $manager->discoverAirPlayDevices();

        $this->assertIsArray($result);
    }

    public function testDiscoverRokuDevicesDelegatesToMdns(): void
    {
        $ssdp = $this->createMock(SsdpDiscovery::class);
        $mdns = $this->createMock(MdnsDiscovery::class);
        $mdns->expects($this->once())
            ->method('discoverRoku')
            ->willReturn([]);

        $manager = new DiscoveryManager($ssdp, $mdns, null);
        $result = $manager->discoverRokuDevices();

        $this->assertIsArray($result);
    }

    public function testAnnounceServerCallsBothSsdpAndMdns(): void
    {
        $ssdp = $this->createMock(SsdpDiscovery::class);
        $ssdp->expects($this->once())
            ->method('announceServer')
            ->with(
                'test-server-id',
                'Phlex Test Server',
                'http://192.168.1.100',
                8200
            );

        $mdns = $this->createMock(MdnsDiscovery::class);
        $mdns->expects($this->once())
            ->method('announceServer')
            ->with(
                'Phlex._phlex._tcp.local.',
                '_phlex._tcp.local.',
                8200,
                $this->anything()
            );

        $manager = new DiscoveryManager($ssdp, $mdns, null);
        $manager->announcePhlexServer('test-server-id', 'Phlex Test Server', 'http://192.168.1.100', 8200);
    }

    public function testStartListenersDoesNotThrow(): void
    {
        $ssdp = $this->createMock(SsdpDiscovery::class);
        $mdns = $this->createMock(MdnsDiscovery::class);

        $manager = new DiscoveryManager($ssdp, $mdns, null);

        // Should not throw
        $manager->startListeners(function ($device) {
            // Device discovered callback
        });

        $this->assertTrue(true);
    }

    public function testDiscoverDlnaServersReturnsDevices(): void
    {
        $ssdpDevice = new SsdpDevice(
            'uuid:12345678-1234-1234-1234-123456789012::urn:schemas-upnp-org:device:MediaServer:1',
            'urn:schemas-upnp-org:device:MediaServer:1',
            'http://192.168.1.100:8200/device.xml',
            'Linux/2.6 UPnP/1.0',
            1800
        );

        $ssdp = $this->createMock(SsdpDiscovery::class);
        $ssdp->method('discoverDevices')
            ->with(SsdpDiscovery::ST_MEDIA_SERVER)
            ->willReturn([$ssdpDevice]);

        $mdns = $this->createMock(MdnsDiscovery::class);

        $manager = new DiscoveryManager($ssdp, $mdns, null);
        $result = $manager->discoverDlnaServers();

        $this->assertCount(1, $result);
        $this->assertInstanceOf(SsdpDevice::class, $result[0]);
    }

    public function testDiscoverChromecastDevicesReturnsServices(): void
    {
        $mdnsService = new MdnsService(
            'Chromecast-xxxx._googlecast._tcp.local.',
            '_googlecast._tcp.local.',
            8009,
            '192.168.1.100',
            ['id=abcd1234'],
            'xxxx'
        );

        $ssdp = $this->createMock(SsdpDiscovery::class);
        $mdns = $this->createMock(MdnsDiscovery::class);
        $mdns->method('discoverChromecast')
            ->willReturn([$mdnsService]);

        $manager = new DiscoveryManager($ssdp, $mdns, null);
        $result = $manager->discoverChromecastDevices();

        $this->assertCount(1, $result);
        $this->assertInstanceOf(MdnsService::class, $result[0]);
    }
}
