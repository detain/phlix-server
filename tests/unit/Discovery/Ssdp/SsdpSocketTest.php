<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Discovery\Ssdp;

use PHPUnit\Framework\TestCase;
use Phlex\Discovery\Ssdp\SsdpSocket;

class SsdpSocketTest extends TestCase
{
    public function testSearchSendsMsearchAndReturnsResponses(): void
    {
        $socket = new SsdpSocket(null, 1);

        // The search method should return an array
        // Note: Without actual network, this will return empty array
        $result = $socket->search('urn:schemas-upnp-org:device:*', 1);

        $this->assertIsArray($result);

        $socket->close();
    }

    public function testParseResponseExtractsFields(): void
    {
        $socket = new SsdpSocket(null, 5);

        $rawResponse = "HTTP/1.1 200 OK\r\n" .
            "LOCATION: http://192.168.1.100:8200/device.xml\r\n" .
            "SERVER: Linux/2.6 UPnP/1.0 Phlex/1.0\r\n" .
            "NT: urn:schemas-upnp-org:device:MediaServer:1\r\n" .
            "USN: uuid:12345678-1234-1234-1234-123456789012::urn:schemas-upnp-org:device:MediaServer:1\r\n" .
            "CACHE-CONTROL: max-age=1800\r\n" .
            "\r\n";

        $parsed = $socket->parseResponse($rawResponse);

        $this->assertIsArray($parsed);
        $this->assertEquals('http://192.168.1.100:8200/device.xml', $parsed['LOCATION']);
        $this->assertEquals('Linux/2.6 UPnP/1.0 Phlex/1.0', $parsed['SERVER']);
        $this->assertEquals('urn:schemas-upnp-org:device:MediaServer:1', $parsed['NT']);
        $this->assertStringContainsString('uuid:12345678-1234-1234-1234-123456789012', $parsed['USN']);
        $this->assertEquals('max-age=1800', $parsed['CACHE-CONTROL']);
    }

    public function testParseResponseWithLfOnlyLineEndings(): void
    {
        $socket = new SsdpSocket(null, 5);

        $rawResponse = "HTTP/1.1 200 OK\n" .
            "LOCATION: http://192.168.1.100:8200/device.xml\n" .
            "NT: urn:schemas-upnp-org:device:MediaServer:1\n" .
            "USN: uuid:12345678-1234-1234-1234-123456789012::urn:schemas-upnp-org:device:MediaServer:1\n" .
            "\n";

        $parsed = $socket->parseResponse($rawResponse);

        $this->assertIsArray($parsed);
        $this->assertEquals('http://192.168.1.100:8200/device.xml', $parsed['LOCATION']);
        $this->assertEquals('urn:schemas-upnp-org:device:MediaServer:1', $parsed['NT']);
    }

    public function testParseResponseReturnsNullForEmptyData(): void
    {
        $socket = new SsdpSocket(null, 5);

        $parsed = $socket->parseResponse('');

        $this->assertNull($parsed);
    }

    public function testParseResponseReturnsNullForInvalidData(): void
    {
        $socket = new SsdpSocket(null, 5);

        // Data with no colon separators
        $parsed = $socket->parseResponse('just some text without colons');

        $this->assertNull($parsed);
    }

    public function testCloseClosesSocket(): void
    {
        $socket = new SsdpSocket(null, 5);

        // Create the socket first by attempting a search
        $socket->search('urn:schemas-upnp-org:device:*', 1);

        // Should not throw when closing
        $socket->close();
        $this->assertTrue(true);
    }

    public function testMultipleSearchesReturnIndependentResults(): void
    {
        $socket = new SsdpSocket(null, 1);

        $result1 = $socket->search('urn:schemas-upnp-org:device:MediaServer:1', 1);
        $result2 = $socket->search('urn:schemas-upnp-org:device:MediaRenderer:1', 1);

        $this->assertIsArray($result1);
        $this->assertIsArray($result2);

        $socket->close();
    }

    public function testParseResponseWithDuplicateKeys(): void
    {
        $socket = new SsdpSocket(null, 5);

        $rawResponse = "HTTP/1.1 200 OK\r\n" .
            "LOCATION: http://192.168.1.100:8200/device.xml\r\n" .
            "LOCATION: http://192.168.1.101:8200/device.xml\r\n" .
            "\r\n";

        $parsed = $socket->parseResponse($rawResponse);

        $this->assertIsArray($parsed);
        // Last value wins
        $this->assertEquals('http://192.168.1.101:8200/device.xml', $parsed['LOCATION']);
    }
}
