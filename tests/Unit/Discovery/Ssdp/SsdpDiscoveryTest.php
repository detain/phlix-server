<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Discovery\Ssdp;

use PHPUnit\Framework\TestCase;
use Phlix\Discovery\Ssdp\SsdpDevice;
use Phlix\Discovery\Ssdp\SsdpDiscovery;
use Phlix\Discovery\Ssdp\SsdpSocket;

class SsdpDiscoveryTest extends TestCase
{
    public function testDiscoverDevicesReturnsArray(): void
    {
        $socket = $this->createMock(SsdpSocket::class);
        $socket->method('search')->willReturn([
            "HTTP/1.1 200 OK\r\nLOCATION: http://192.168.1.100:8200/device.xml\r\nNT: urn:schemas-upnp-org:device:MediaServer:1\r\nUSN: uuid:12345678-1234-1234-1234-123456789012::urn:schemas-upnp-org:device:MediaServer:1\r\nCACHE-CONTROL: max-age=1800\r\nSERVER: Linux/2.6 UPnP/1.0\r\n\r\n"
        ]);
        $socket->method('parseResponse')->willReturn([
            'LOCATION' => 'http://192.168.1.100:8200/device.xml',
            'NT' => 'urn:schemas-upnp-org:device:MediaServer:1',
            'USN' => 'uuid:12345678-1234-1234-1234-123456789012::urn:schemas-upnp-org:device:MediaServer:1',
            'CACHE-CONTROL' => 'max-age=1800',
            'SERVER' => 'Linux/2.6 UPnP/1.0',
        ]);

        $discovery = new SsdpDiscovery($socket, null);
        $devices = $discovery->discoverDevices();

        $this->assertIsArray($devices);
        $this->assertCount(1, $devices);
        $this->assertInstanceOf(SsdpDevice::class, $devices[0]);
    }

    public function testDiscoverReturnsEmptyOnNetworkError(): void
    {
        $socket = $this->createMock(SsdpSocket::class);
        $socket->method('search')->willReturn([]); // Empty = network error

        $discovery = new SsdpDiscovery($socket, null);
        $devices = $discovery->discoverDevices();

        $this->assertIsArray($devices);
        $this->assertEmpty($devices);
    }

    public function testDiscoverDevicesWithCustomSt(): void
    {
        $socket = $this->createMock(SsdpSocket::class);
        $socket->expects($this->once())
            ->method('search')
            ->with('urn:schemas-upnp-org:device:MediaRenderer:1', $this->anything())
            ->willReturn([]);

        $discovery = new SsdpDiscovery($socket, null);
        $devices = $discovery->discoverDevices('urn:schemas-upnp-org:device:MediaRenderer:1');

        $this->assertIsArray($devices);
    }

    public function testDeviceHasCorrectProperties(): void
    {
        $socket = $this->createMock(SsdpSocket::class);
        $socket->method('search')->willReturn([
            "HTTP/1.1 200 OK\r\n" .
            "LOCATION: http://192.168.1.100:8200/device.xml\r\n" .
            "NT: urn:schemas-upnp-org:device:MediaServer:1\r\n" .
            "USN: uuid:12345678-1234-1234-1234-123456789012::urn:schemas-upnp-org:device:MediaServer:1\r\n" .
            "CACHE-CONTROL: max-age=1800\r\n" .
            "SERVER: Linux/2.6 UPnP/1.0 Phlix/1.0\r\n" .
            "\r\n"
        ]);
        $socket->method('parseResponse')->willReturn([
            'LOCATION' => 'http://192.168.1.100:8200/device.xml',
            'NT' => 'urn:schemas-upnp-org:device:MediaServer:1',
            'USN' => 'uuid:12345678-1234-1234-1234-123456789012::urn:schemas-upnp-org:device:MediaServer:1',
            'CACHE-CONTROL' => 'max-age=1800',
            'SERVER' => 'Linux/2.6 UPnP/1.0 Phlix/1.0',
        ]);

        $discovery = new SsdpDiscovery($socket, null);
        $devices = $discovery->discoverDevices();

        $this->assertCount(1, $devices);
        $device = $devices[0];

        $this->assertEquals('http://192.168.1.100:8200/device.xml', $device->location);
        $this->assertEquals('urn:schemas-upnp-org:device:MediaServer:1', $device->nt);
        $this->assertEquals('uuid:12345678-1234-1234-1234-123456789012::urn:schemas-upnp-org:device:MediaServer:1', $device->usn);
        $this->assertEquals(1800, $device->cacheTimeout);
        $this->assertEquals('Linux/2.6 UPnP/1.0 Phlix/1.0', $device->server);
    }

    public function testDeviceGetDeviceId(): void
    {
        $device = new SsdpDevice(
            'uuid:12345678-1234-1234-1234-123456789012::urn:schemas-upnp-org:device:MediaServer:1',
            'urn:schemas-upnp-org:device:MediaServer:1',
            'http://192.168.1.100:8200/device.xml',
            'Linux/2.6 UPnP/1.0',
            1800
        );

        $this->assertEquals('12345678-1234-1234-1234-123456789012', $device->getDeviceId());
    }

    public function testDeviceGetBaseUrl(): void
    {
        $device = new SsdpDevice(
            'uuid:12345678-1234-1234-1234-123456789012::urn:schemas-upnp-org:device:MediaServer:1',
            'urn:schemas-upnp-org:device:MediaServer:1',
            'http://192.168.1.100:8200/device.xml',
            'Linux/2.6 UPnP/1.0',
            1800
        );

        $this->assertEquals('http://192.168.1.100:8200', $device->getBaseUrl());
    }

    public function testDeviceGetBaseUrlWithJustHostPort(): void
    {
        $device = new SsdpDevice(
            'uuid:12345678-1234-1234-1234-123456789012::urn:schemas-upnp-org:device:MediaServer:1',
            'urn:schemas-upnp-org:device:MediaServer:1',
            '192.168.1.100:8200',
            'Linux/2.6 UPnP/1.0',
            1800
        );

        $this->assertEquals('http://192.168.1.100:8200', $device->getBaseUrl());
    }

    public function testAnnounceServerSendsNotify(): void
    {
        $socket = $this->createMock(SsdpSocket::class);
        $socket->expects($this->once())
            ->method('announce')
            ->with(
                'urn:schemas-upnp-org:device:MediaServer:1',
                'http://192.168.1.100:8200',
                'uuid:phlix-server-test-server-id::urn:schemas-upnp-org:device:MediaServer:1'
            );

        $discovery = new SsdpDiscovery($socket, null);
        $discovery->announceServer('test-server-id', 'Phlix Server', 'http://192.168.1.100', 8200);
    }

    public function testDiscoverSkipsInvalidResponses(): void
    {
        $socket = $this->createMock(SsdpSocket::class);
        $socket->method('search')->willReturn([
            "HTTP/1.1 200 OK\r\nLOCATION: http://192.168.1.100:8200/device.xml\r\nNT: urn:schemas-upnp-org:device:MediaServer:1\r\nUSN: uuid:12345678-1234-1234-1234-123456789012::urn:schemas-upnp-org:device:MediaServer:1\r\nCACHE-CONTROL: max-age=1800\r\n\r\n",
            "invalid response without proper format",
        ]);
        $socket->method('parseResponse')
            ->willReturnOnConsecutiveCalls(
                [
                    'LOCATION' => 'http://192.168.1.100:8200/device.xml',
                    'NT' => 'urn:schemas-upnp-org:device:MediaServer:1',
                    'USN' => 'uuid:12345678-1234-1234-1234-123456789012::urn:schemas-upnp-org:device:MediaServer:1',
                    'CACHE-CONTROL' => 'max-age=1800',
                ],
                null // Invalid response returns null
            );

        $discovery = new SsdpDiscovery($socket, null);
        $devices = $discovery->discoverDevices();

        $this->assertCount(1, $devices);
    }

    public function testDiscoverSkipsDevicesWithMissingUsn(): void
    {
        $socket = $this->createMock(SsdpSocket::class);
        $socket->method('search')->willReturn([
            "HTTP/1.1 200 OK\r\nLOCATION: http://192.168.1.100:8200/device.xml\r\nNT: urn:schemas-upnp-org:device:MediaServer:1\r\nCACHE-CONTROL: max-age=1800\r\n\r\n"
        ]);
        $socket->method('parseResponse')->willReturn([
            'LOCATION' => 'http://192.168.1.100:8200/device.xml',
            'NT' => 'urn:schemas-upnp-org:device:MediaServer:1',
            // Missing USN
            'CACHE-CONTROL' => 'max-age=1800',
        ]);

        $discovery = new SsdpDiscovery($socket, null);
        $devices = $discovery->discoverDevices();

        $this->assertEmpty($devices);
    }
}
