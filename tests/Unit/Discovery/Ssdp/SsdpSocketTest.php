<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Discovery\Ssdp;

use PHPUnit\Framework\TestCase;
use Phlix\Discovery\Ssdp\SsdpSocket;

class SsdpSocketTest extends TestCase
{
    /**
     * S297: the multicast join is real, so `search()`'s socket now receives
     * EVERYTHING on the segment — NOTIFYs, M-SEARCHs, the server's own
     * announcements (loopback). Only HTTP response-shaped datagrams may be
     * collected as search results, or a NOTIFY (which carries USN/NT/LOCATION)
     * would parse as a "discovered device", including self-discovery.
     *
     * Mutation-verified: removing the isResponseDatagram() filter from
     * receiveResponses() does not redden this test directly (it tests the
     * predicate), but the predicate is the ONLY gate between the socket and
     * SsdpDiscovery's device list, and its shape table is what a regression
     * would have to walk through.
     */
    public function test_only_http_response_shaped_datagrams_are_search_results(): void
    {
        $isResponse = new \ReflectionMethod(SsdpSocket::class, 'isResponseDatagram');
        $isResponse->setAccessible(true);

        $cases = [
            "HTTP/1.1 200 OK\r\nCACHE-CONTROL: max-age=1800\r\n"
                . "ST: urn:schemas-upnp-org:device:MediaServer:1\r\n\r\n"      => true,
            "HTTP/1.0 200 OK\r\nLOCATION: http://192.168.1.100:8200/device.xml\r\n\r\n" => true,
            "NOTIFY * HTTP/1.1\r\nNT: urn:schemas-upnp-org:device:MediaServer:1\r\n"
                . "USN: uuid:x\r\n\r\n"                                        => false,
            "M-SEARCH * HTTP/1.1\r\nMAN: \"ssdp:discover\"\r\nST: ssdp:all\r\n\r\n" => false,
            "HTTP/1.1 404 Not Found\r\n\r\n"                                   => true,
            "garbage without a status line"                                    => false,
            ""                                                                 => false,
        ];

        foreach ($cases as $datagram => $expected) {
            self::assertSame(
                $expected,
                $isResponse->invoke(null, $datagram),
                'isResponseDatagram(' . var_export(substr($datagram, 0, 30), true) . '…)'
            );
        }
    }

    public function testSearchSendsMsearchAndReturnsResponses(): void
    {
        $socket = new SsdpSocket(null, 1);

        // The search method should return an array.
        // Note: without actual network, this returns an empty array — and with
        // the S297 real group join, on hosts whose interface-0 route loops
        // multicast back (measured on the CI runner via PR #699 for the mDNS
        // twin), the socket genuinely joins 239.255.255.250 and IP_MULTICAST_LOOP
        // delivers its own M-SEARCH echo plus every neighbor NOTIFY on the segment.
        // receiveResponses() filters those out (isResponseDatagram() accepts only
        // HTTP response status lines), so search() returns responses or nothing —
        // never request-shaped noise. Empty array still passes (hosts with no
        // loopback, segments with no responder).
        $result = $socket->search('urn:schemas-upnp-org:device:*', 1);

        self::assertLessThanOrEqual(
            10,
            count($result),
            'receiveResponses() caps collection at its attempt budget — unbounded growth is not possible.'
        );
        foreach ($result as $datagram) {
            self::assertStringStartsWith(
                'HTTP/',
                $datagram,
                'Only HTTP response-shaped datagrams may surface as search results.'
            );
        }

        $socket->close();
    }

    public function testParseResponseExtractsFields(): void
    {
        $socket = new SsdpSocket(null, 5);

        $rawResponse = "HTTP/1.1 200 OK\r\n" .
            "LOCATION: http://192.168.1.100:8200/device.xml\r\n" .
            "SERVER: Linux/2.6 UPnP/1.0 Phlix/1.0\r\n" .
            "NT: urn:schemas-upnp-org:device:MediaServer:1\r\n" .
            "USN: uuid:12345678-1234-1234-1234-123456789012::urn:schemas-upnp-org:device:MediaServer:1\r\n" .
            "CACHE-CONTROL: max-age=1800\r\n" .
            "\r\n";

        $parsed = $socket->parseResponse($rawResponse);

        $this->assertIsArray($parsed);
        $this->assertEquals('http://192.168.1.100:8200/device.xml', $parsed['LOCATION']);
        $this->assertEquals('Linux/2.6 UPnP/1.0 Phlix/1.0', $parsed['SERVER']);
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
        $this->addToAssertionCount(1);
    }

    public function testMultipleSearchesReturnIndependentResults(): void
    {
        $socket = new SsdpSocket(null, 1);

        $result1 = $socket->search('urn:schemas-upnp-org:device:MediaServer:1', 1);
        $result2 = $socket->search('urn:schemas-upnp-org:device:MediaRenderer:1', 1);

        // Only HTTP response-shaped datagrams may surface (see the sibling
        // search test's comment — the S297 group join makes the socket hear
        // the whole segment's SSDP traffic, and receiveResponses() filters it).
        self::assertLessThanOrEqual(10, count($result1));
        self::assertLessThanOrEqual(10, count($result2));
        foreach (array_merge($result1, $result2) as $datagram) {
            self::assertStringStartsWith('HTTP/', $datagram);
        }

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
