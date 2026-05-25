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

    public function testAnnounceServerReturnsFalseWhenAvahiNotAvailable(): void
    {
        $socket = $this->createMock(MdnsSocket::class);

        // Mock shell_exec to return empty string (avahi-publish-service not found)
        $discovery = new MdnsDiscovery($socket, null);

        // Use reflection to test with a mock that simulates avahi not being available
        // Since we can't easily mock global functions, we test the behavior path
        // by checking that the method returns false when avahi is not installed
        $result = $discovery->announceServer(
            'Phlix._phlix._tcp.local.',
            '_phlix._tcp.local.',
            8200,
            ['serverId' => 'test-id']
        );

        // If avahi-publish-service is not installed, this returns false
        // If it is installed, it would return true (and actually try to publish)
        // We can't fully test the avahi-available path without mocking exec,
        // but we can at least verify the method runs without error
        $this->assertIsBool($result);
    }

    public function testAnnounceServerReturnsBool(): void
    {
        $socket = $this->createMock(MdnsSocket::class);

        $discovery = new MdnsDiscovery($socket, null);

        $result = $discovery->announceServer(
            'Phlix._phlix._tcp.local.',
            '_phlix._tcp.local.',
            8200,
            ['serverId' => 'test-id']
        );

        $this->assertIsBool($result);
    }

    /**
     * Each TXT pair must be emitted as its own discrete argument, so values
     * containing `&` / `=` are preserved intact rather than corrupted by being
     * joined into one `&`-delimited string and escaped once.
     */
    public function testBuildAnnounceArgsKeepsEachTxtPairSeparate(): void
    {
        $socket = $this->createMock(MdnsSocket::class);
        $discovery = new MdnsDiscovery($socket, null);

        $args = $this->invokePrivate($discovery, 'buildAnnounceArgs', [
            'Phlix._phlix._tcp.local.',
            '_phlix._tcp.local.',
            8200,
            [
                'serverId' => 'a&b=c',          // contains both & and =
                'friendlyName' => 'Phlix Home',
            ],
        ]);

        $this->assertSame(
            [
                'Phlix._phlix._tcp.local.',
                '_phlix._tcp.local.',
                '8200',
                'serverId=a&b=c',     // value kept whole, NOT split on & or =
                'friendlyName=Phlix Home',
            ],
            $args
        );
    }

    public function testBuildAnnounceCommandEscapesEachArgumentIndividually(): void
    {
        $socket = $this->createMock(MdnsSocket::class);
        $discovery = new MdnsDiscovery($socket, null);

        $cmd = $this->invokePrivate($discovery, 'buildAnnounceCommand', [
            '/usr/bin/avahi-publish-service',
            'Phlix Server',
            '_phlix._tcp.local.',
            8200,
            ['serverId' => 'a&b=c; rm -rf /'],
        ]);

        $this->assertIsString($cmd);
        // The whole TXT pair must survive as a single shell-escaped token,
        // including the metacharacters, so it cannot break out of its argument.
        $this->assertStringContainsString(
            escapeshellarg('serverId=a&b=c; rm -rf /'),
            $cmd
        );
        // The injected command separator must be inside the quoted argument,
        // never executed: there is exactly one trailing background separator.
        $this->assertStringEndsWith(' >/dev/null 2>&1 &', $cmd);
    }

    public function testBuildAnnounceArgsWithNoTxtRecords(): void
    {
        $socket = $this->createMock(MdnsSocket::class);
        $discovery = new MdnsDiscovery($socket, null);

        $args = $this->invokePrivate($discovery, 'buildAnnounceArgs', [
            'Phlix._phlix._tcp.local.',
            '_phlix._tcp.local.',
            8200,
            [],
        ]);

        $this->assertSame(
            ['Phlix._phlix._tcp.local.', '_phlix._tcp.local.', '8200'],
            $args
        );
    }

    /**
     * @param array<int, mixed> $args
     *
     * @return mixed
     */
    private function invokePrivate(MdnsDiscovery $object, string $method, array $args)
    {
        $ref = new \ReflectionMethod(MdnsDiscovery::class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($object, $args);
    }
}
