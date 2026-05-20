<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Chromecast;

use PHPUnit\Framework\TestCase;
use Phlix\Chromecast\CastDevice;
use Phlix\Chromecast\CastDiscovery;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Discovery\Mdns\MdnsDiscovery;
use Phlix\Discovery\Mdns\MdnsService;

class CastDiscoveryTest extends TestCase
{
    private MdnsDiscovery $mdnsMock;
    private StructuredLogger $loggerMock;
    private CastDiscovery $discovery;

    protected function setUp(): void
    {
        $this->mdnsMock = $this->createMock(MdnsDiscovery::class);
        $this->loggerMock = $this->createMock(StructuredLogger::class);
        $this->discovery = new CastDiscovery($this->mdnsMock, $this->loggerMock);
    }

    public function testDiscoverDevicesReturnsCastDevices(): void
    {
        $mdnsServices = [
            new MdnsService(
                'Chromecast-LivingRoom-xxxx._googlecast._tcp.local.',
                '_googlecast._tcp.local.',
                8009,
                '192.168.1.100',
                ['id=abcd1234', 'md=Chromecast Ultra', 'uuid=uuid-123'],
                'abcd1234'
            ),
            new MdnsService(
                'NestHub-Kitchen-xxxx._googlecast._tcp.local.',
                '_googlecast._tcp.local.',
                8009,
                '192.168.1.101',
                ['id=efgh5678', 'md=Nest Hub'],
                'efgh5678'
            ),
        ];

        $this->mdnsMock
            ->expects($this->once())
            ->method('discoverChromecast')
            ->willReturn($mdnsServices);

        $devices = $this->discovery->discoverDevices();

        $this->assertCount(2, $devices);
        $this->assertInstanceOf(CastDevice::class, $devices[0]);
        $this->assertInstanceOf(CastDevice::class, $devices[1]);

        // Check first device
        $this->assertEquals('abcd1234', $devices[0]->deviceId);
        $this->assertEquals('Chromecast-LivingRoom-xxxx', $devices[0]->name);
        $this->assertEquals('192.168.1.100', $devices[0]->host);
        $this->assertEquals(8009, $devices[0]->port);
        $this->assertEquals('Chromecast Ultra', $devices[0]->model);
    }

    public function testDiscoverDevicesReturnsEmptyArrayWhenNoDevices(): void
    {
        $this->mdnsMock
            ->expects($this->once())
            ->method('discoverChromecast')
            ->willReturn([]);

        $devices = $this->discovery->discoverDevices();

        $this->assertCount(0, $devices);
        $this->assertIsArray($devices);
    }

    public function testDiscoverDevicesHandlesMissingTxtRecords(): void
    {
        $mdnsServices = [
            new MdnsService(
                'Chromecast-NoTxt-xxxx._googlecast._tcp.local.',
                '_googlecast._tcp.local.',
                8009,
                '192.168.1.102',
                [], // No TXT records
                'device-without-txt'
            ),
        ];

        $this->mdnsMock
            ->expects($this->once())
            ->method('discoverChromecast')
            ->willReturn($mdnsServices);

        $devices = $this->discovery->discoverDevices();

        // Should still create a CastDevice but with empty model and uuid
        $this->assertCount(1, $devices);
        $this->assertEquals('device-without-txt', $devices[0]->deviceId);
        $this->assertEquals('', $devices[0]->model);
    }

    public function testStripsServiceTypeSuffixFromName(): void
    {
        $mdnsServices = [
            new MdnsService(
                'MyDevice-abcd1234._googlecast._tcp.local.',
                '_googlecast._tcp.local.',
                8009,
                '192.168.1.100',
                ['id=abcd1234'],
                'abcd1234'
            ),
        ];

        $this->mdnsMock
            ->expects($this->once())
            ->method('discoverChromecast')
            ->willReturn($mdnsServices);

        $devices = $this->discovery->discoverDevices();

        // Name should have the service type suffix stripped
        $this->assertEquals('MyDevice-abcd1234', $devices[0]->name);
    }
}
