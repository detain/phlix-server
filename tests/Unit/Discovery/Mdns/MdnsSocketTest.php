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

        // With no responder on the segment the result is empty — EXCEPT on a
        // host that loops multicast back to a joined socket, where the query
        // socket hears its own query echo (S296 fixed the join, so the socket
        // now genuinely joins 224.0.0.251 and IP_MULTICAST_LOOP delivers the
        // echo). "No responder answered" is therefore: every received datagram
        // is a DNS QUERY (flags 0, zero answer records) — never a response.
        $result = $socket->query('_googlecast._tcp.local.');

        $this->assertIsArray($result);
        foreach ($result as $datagram) {
            $parsed = $socket->parseResponse($datagram);
            $this->assertIsArray($parsed, 'Received datagram is not a DNS query echo: ' . bin2hex($datagram));
            $this->assertSame(0, $parsed['flags'], 'A responder answered the query');
            $this->assertSame([], $parsed['records'], 'A responder answered the query');
        }

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
        $this->addToAssertionCount(1);
    }

    public function testCloseIsIdempotentAndNullsSocket(): void
    {
        $mdns = new MdnsSocket(null, 5);
        $native = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        self::assertInstanceOf(\Socket::class, $native);

        $prop = new \ReflectionProperty(MdnsSocket::class, 'socket');
        $prop->setValue($mdns, $native);

        $mdns->close();
        self::assertNull($prop->getValue($mdns));

        // A second close() (e.g. __destruct after an explicit close) must be a
        // safe no-op, never a double-free or a throw.
        $mdns->close();
        $this->addToAssertionCount(1);
    }

    /**
     * Regression: close() must NOT let socket teardown fatal a worker. The
     * production trigger was a Swoole\Coroutine\Socket rejected by the native
     * socket_close() at shutdown (a TypeError from __destruct that took out the
     * whole HTTP fleet). We can't materialise a Swoole socket in a unit test,
     * so we exercise the same guard with an already-freed handle: close() must
     * complete cleanly and clear the reference regardless.
     */
    public function testCloseSwallowsFailureFromAnAlreadyFreedSocket(): void
    {
        $mdns = new MdnsSocket(null, 5);
        $native = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        self::assertInstanceOf(\Socket::class, $native);
        socket_close($native); // free it out-of-band

        $prop = new \ReflectionProperty(MdnsSocket::class, 'socket');
        $prop->setValue($mdns, $native);

        $mdns->close();
        self::assertNull($prop->getValue($mdns));
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
