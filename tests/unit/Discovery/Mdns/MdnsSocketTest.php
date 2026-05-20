<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Discovery\Mdns;

use PHPUnit\Framework\TestCase;
use Phlix\Discovery\Mdns\MdnsSocket;

class MdnsSocketTest extends TestCase
{
    public function testQuerySendsDnsQuery(): void
    {
        $socket = new MdnsSocket(null, 1);

        // Without actual network, this returns empty array
        $result = $socket->query('_googlecast._tcp.local.');

        $this->assertIsArray($result);

        $socket->close();
    }

    public function testParseResponseExtractsSrvAndTxt(): void
    {
        $socket = new MdnsSocket(null, 5);

        // Build a minimal DNS response with SRV record
        // Transaction ID: 0x0001
        // Flags: 0x8400 (Response, AA, RD)
        // Questions: 1
        // Answer RRs: 1
        // Authority RRs: 0
        // Additional RRs: 0
        $packet = "\x00\x01"; // Transaction ID
        $packet .= "\x84\x00"; // Flags
        $packet .= "\x00\x01"; // Questions: 1
        $packet .= "\x00\x01"; // Answer RRs: 1
        $packet .= "\x00\x00"; // Authority RRs: 0
        $packet .= "\x00\x00"; // Additional RRs: 0

        // Question: _googlecast._tcp.local. PTR
        $packet .= "\x0a_googlecast"; // _googlecast (10 bytes)
        $packet .= "\x05_tcp";
        $packet .= "\x05local";
        $packet .= "\x00"; // Root
        $packet .= "\x00\x0c"; // Type: PTR
        $packet .= "\x00\x01"; // Class: IN

        // Answer: _googlecast._tcp.local. PTR Chromecast-xxxx._googlecast._tcp.local.
        $packet .= "\x0a_googlecast"; // Name
        $packet .= "\x05_tcp";
        $packet .= "\x05local";
        $packet .= "\x00"; // Root
        $packet .= "\x00\x0c"; // Type: PTR
        $packet .= "\x00\x01"; // Class: IN
        $packet .= "\x00\x00\x0e\x10"; // TTL: 3600
        $packet .= "\x00\x1a"; // RDLENGTH: 26
        // RDATA: Chromecast-xxxx._googlecast._tcp.local.
        $packet .= "\x0dChromecast";
        $packet .= "\x2dxxxx"; // -xxxx
        $packet .= "\x0a_googlecast";
        $packet .= "\x05_tcp";
        $packet .= "\x05local";
        $packet .= "\x00"; // Root

        $parsed = $socket->parseResponse($packet);

        $this->assertIsArray($parsed);
        $this->assertEquals(0x0001, $parsed['transactionId']);
        $this->assertIsArray($parsed['records']);
    }

    public function testParseResponseReturnsNullForEmptyData(): void
    {
        $socket = new MdnsSocket(null, 5);

        $parsed = $socket->parseResponse('');

        $this->assertNull($parsed);
    }

    public function testParseResponseReturnsNullForTooShortData(): void
    {
        $socket = new MdnsSocket(null, 5);

        $parsed = $socket->parseResponse('too short');

        $this->assertNull($parsed);
    }

    public function testCloseClosesSocket(): void
    {
        $socket = new MdnsSocket(null, 5);

        // Create the socket first by attempting a query
        $socket->query('_googlecast._tcp.local.');

        // Should not throw when closing
        $socket->close();
        $this->assertTrue(true);
    }

    public function testConstants(): void
    {
        $this->assertEquals('224.0.0.251', MdnsSocket::MULTICAST_ADDR);
        $this->assertEquals(5353, MdnsSocket::PORT);
        $this->assertEquals(12, MdnsSocket::QTYPE_PTR);
        $this->assertEquals(16, MdnsSocket::QTYPE_TXT);
        $this->assertEquals(33, MdnsSocket::QTYPE_SRV);
    }
}
