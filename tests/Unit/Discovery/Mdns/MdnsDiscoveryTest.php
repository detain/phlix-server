<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Discovery\Mdns;

use PHPUnit\Framework\TestCase;
use Phlix\Discovery\Mdns\MdnsDiscovery;
use Phlix\Discovery\Mdns\MdnsService;
use Phlix\Discovery\Mdns\MdnsSocket;

class MdnsDiscoveryTest extends TestCase
{
    public function testDiscoverChromecastReturnsArray(): void
    {
        $socket = $this->createMock(MdnsSocket::class);
        $socket->method('query')->willReturn([]);

        $discovery = new MdnsDiscovery($socket, null);
        $result = $discovery->discoverChromecast();

        $this->assertIsArray($result);
    }

    public function testDiscoverAirplayReturnsArray(): void
    {
        $socket = $this->createMock(MdnsSocket::class);
        $socket->method('query')->willReturn([]);

        $discovery = new MdnsDiscovery($socket, null);
        $result = $discovery->discoverAirPlay();

        $this->assertIsArray($result);
    }

    public function testDiscoverAirplayQueriesBothAirplayAndRaop(): void
    {
        $socket = $this->createMock(MdnsSocket::class);

        // First call for _airplay._tcp.local. (QTYPE_PTR)
        // Second call for _raop._tcp.local. (QTYPE_PTR)
        $socket->expects($this->exactly(2))
            ->method('query')
            ->willReturn([]);

        $discovery = new MdnsDiscovery($socket, null);
        $discovery->discoverAirPlay();
    }

    public function testServiceConstants(): void
    {
        $this->assertEquals('_googlecast._tcp.local.', MdnsDiscovery::SERVICE_CHROMECAST);
        $this->assertEquals('_airplay._tcp.local.', MdnsDiscovery::SERVICE_AIRPLAY);
        $this->assertEquals('_raop._tcp.local.', MdnsDiscovery::SERVICE_RAOP);
        $this->assertEquals('_ roku-ecnp._tcp.local.', MdnsDiscovery::SERVICE_ROKU);
    }

    public function testMdnsServiceGetAddress(): void
    {
        $service = new MdnsService(
            'Chromecast-xxxx._googlecast._tcp.local.',
            '_googlecast._tcp.local.',
            8009,
            '192.168.1.100',
            ['id=abcd1234'],
            'xxxx'
        );

        $this->assertEquals('192.168.1.100:8009', $service->getAddress());
    }

    public function testMdnsServiceProperties(): void
    {
        $service = new MdnsService(
            'Chromecast-xxxx._googlecast._tcp.local.',
            '_googlecast._tcp.local.',
            8009,
            '192.168.1.100',
            ['id=abcd1234', 'rs=1'],
            'xxxx'
        );

        $this->assertEquals('Chromecast-xxxx._googlecast._tcp.local.', $service->name);
        $this->assertEquals('_googlecast._tcp.local.', $service->type);
        $this->assertEquals(8009, $service->port);
        $this->assertEquals('192.168.1.100', $service->host);
        $this->assertEquals(['id=abcd1234', 'rs=1'], $service->txtRecords);
        $this->assertEquals('xxxx', $service->deviceId);
    }

    public function testAnnounceServerLogsMessage(): void
    {
        $socket = $this->createMock(MdnsSocket::class);

        $discovery = new MdnsDiscovery($socket, null);

        // Should not throw
        $discovery->announceServer('Phlix._phlix._tcp.local.', '_phlix._tcp.local.', 8200, [
            'serverId' => 'test-id',
            'friendlyName' => 'Phlix Server',
        ]);

        $this->assertTrue(true);
    }
}
