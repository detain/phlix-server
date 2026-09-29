<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Discovery\Mdns;

use PHPUnit\Framework\TestCase;
use Phlix\Discovery\Mdns\MdnsSocket;

/**
 * F-3 regression suite: mDNS DNS-name compression decompression must be
 * crash-proof (iterative walk, pointer budget, RFC 1035 backward-only rule)
 * while still decoding legitimate compressed wire shapes.
 *
 * The pre-fix decoder RECURSED on every pointer with no depth cap, no visited
 * set and no backward-pointer rule: a 14-byte datagram whose first name field
 * is the self-pointer C0 0C recursed until the process died — on the
 * always-on 0.0.0.0:5353 receive path, unauthenticated, one packet per worker
 * crash. These tests pin the repaired law.
 *
 * @since 2.3.0
 */
final class MdnsNameDecompressionTest extends TestCase
{
    private MdnsSocket $socket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->socket = new MdnsSocket(null, 1);
    }

    /**
     * Invoke the private iterative decoder directly to assert the malformed
     * pointer contract (the public path only exposes "null").
     *
     * @return array{string, int}
     */
    private function decode(string $data, int $offset): array
    {
        $method = new \ReflectionMethod(MdnsSocket::class, 'decodeDnsName');
        $method->setAccessible(true);

        /** @var array{string, int} $result */
        $result = $method->invoke($this->socket, $data, $offset);
        return $result;
    }

    /**
     * 12-byte DNS header with the given section counts.
     */
    private function header(int $questions = 0, int $answers = 0): string
    {
        return "\x00\x01" . "\x84\x00"
            . pack('n', $questions) . pack('n', $answers) . "\x00\x00\x00\x00";
    }

    public function testSelfPointerBombPacketIsRefusedNotRecursed(): void
    {
        // The classic bomb: header + ONE answer record whose name at offset 12
        // is the self-pointer C0 0C (target 12 === pointer offset). 14 bytes
        // total; infinite recursion pre-fix.
        $packet = $this->header(0, 1) . "\xc0\x0c";

        $this->assertNull($this->socket->parseResponse($packet));
    }

    public function testForwardPointerIsRejectedAsMalformed(): void
    {
        // Cyclic pair 12 -> 14 -> 12: the FIRST hop is already a forward
        // pointer (target 14 at pointer-start 12), which RFC 1035 forbids —
        // refused outright, no walk begins.
        $data = $this->header() . "\xc0\x0e" . "\xc0\x0c";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('malformed DNS compression pointer');

        $this->decode($data, 12);
    }

    public function testBackwardOnlyPointerCycleIsBoundedByJumpBudget(): void
    {
        // A cycle built ONLY from legal-shaped backward pointers: at 14 a
        // pointer back to 12, whose label consumes forward to 14 again — the
        // walk can only be stopped by the budget cap, which exists for
        // exactly this shape. Must throw, not spin.
        $data = $this->header() . "\x01z\xc0\x0c" . str_repeat("\x00", 32);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('compression-pointer budget exceeded');

        $this->decode($data, 12);
    }

    public function testPointerJumpBudgetAllowsLegitimateChains(): void
    {
        // 10 chained backward pointers (the documented maximum) still resolve:
        // each pair C0 <prev> steps back by 2 until landing on the real label.
        // Layout: header(12) + "\x01a\x00"(12-14) + 10 pointer pairs at 15..34,
        // each pointing at the pair BEFORE it, the first pointing at 12.
        $data = $this->header() . "\x01a\x00";
        $pointerSection = '';
        for ($i = 0; $i < 10; $i++) {
            // jump target: previous structure start (12 for the first, then each
            // preceding pointer pair offset)
            $target = $i === 0 ? 12 : 15 + ($i - 1) * 2;
            $pointerSection .= "\xc0" . chr($target);
        }
        $data .= $pointerSection;

        // Walk starts at the LAST pointer pair (offset 33): every hop is
        // strictly backward.
        [$name, $offset] = $this->decode($data, 33);

        $this->assertSame('a', $name);
        $this->assertSame(35, $offset, 'Caller must resume after the FIRST pointer pair');
    }

    public function testSingleCompressionPointerDecodesAndResumesAfterThePair(): void
    {
        // Real wire shape: name at 12 = "\x01a\x01b\x00" (5 bytes, 12..16);
        // compressed name at 20 points back to 12.
        $data = $this->header() . "\x01a\x01b\x00\x00\x00\x00\xc0\x0c";

        [$name, $offset] = $this->decode($data, 20);

        $this->assertSame('a.b', $name);
        $this->assertSame(22, $offset, 'Offset must resume past the pointer pair, not at the chain tail');
    }

    public function testDoubleChainedCompressionDecodes(): void
    {
        // 12: "\x01a\x00"; 15..16: pointer->12 (yields "a"); 19: "\x01z" then
        // 21..22 pointer->15 — a two-level chain that MUST decode to z.a.
        $data = $this->header() . "\x01a\x00\xc0\x0c\x00\x00\x01z\xc0\x0f";

        [$name, $offset] = $this->decode($data, 19);

        $this->assertSame('z.a', $name);
        $this->assertSame(23, $offset);
    }

    public function testTruncatedPointerPairDoesNotReadPastBuffer(): void
    {
        // Pointer byte at the very last offset of the buffer: its partner byte
        // does not exist. Decoder must terminate with what it has, never index
        // out of range. Layout: 12..13 label "z", 14 lone 0xC0.
        $data = $this->header() . "\x01z\xc0";

        [$name, $offset] = $this->decode($data, 12);

        $this->assertSame('z', $name);
        $this->assertSame(15, $offset);
    }

    public function testEndToEndWireFixtureWithCompressedQuestionAndRdata(): void
    {
        // FULL realistic answer (post-fix law: RDATA names are compressed
        // against the WHOLE message, RFC 1035 4.1.4):
        //   12..35  question name "_googlecast._tcp.local."
        //   36..39  QTYPE PTR / QCLASS IN
        //   40..41  answer name -> C0 0C (back to 12)
        //   42..51  TYPE PTR, CLASS IN, TTL, RDLENGTH 2
        //   52..53  RDATA C0 0C -> the same compressed name
        $packet = $this->header(1, 1);
        $packet .= "\x0b_googlecast\x04_tcp\x05local\x00";
        $packet .= "\x00\x0c\x00\x01"; // QTYPE=PTR QCLASS=IN
        $packet .= "\xc0\x0c";         // answer NAME compressed to 12
        $packet .= "\x00\x0c";         // TYPE=PTR
        $packet .= "\x00\x01";         // CLASS=IN
        $packet .= "\x00\x00\x0e\x10"; // TTL=3600
        $packet .= "\x00\x02";         // RDLENGTH=2
        $packet .= "\xc0\x0c";         // RDATA: compressed PTR target

        $parsed = $this->socket->parseResponse($packet);

        $this->assertIsArray($parsed);
        /** @var array{records: list<array{name: string, type: int, data: mixed}>} $parsed */
        $this->assertCount(1, $parsed['records']);
        $this->assertSame('_googlecast._tcp.local', $parsed['records'][0]['name']);
        $this->assertIsArray($parsed['records'][0]['data']);
        $this->assertSame(
            '_googlecast._tcp.local',
            $parsed['records'][0]['data']['ptr'],
            'Compressed RDATA names must resolve against the full packet, not the slice'
        );
    }

    public function testEndToEndSrvWithCompressedTargetResolves(): void
    {
        // SRV answer: RDATA = priority/weight/port (6 bytes) then the target
        // name, here a compression pointer back into the question name.
        $packet = $this->header(1, 1);
        $packet .= "\x06_phlix\x04_tcp\x05local\x00";  // 12..35
        $packet .= "\x00\x21\x00\x01";                 // QTYPE=SRV QCLASS=IN
        $packet .= "\xc0\x0c";                         // NAME -> 12
        $packet .= "\x00\x21\x00\x01";                 // TYPE=SRV CLASS=IN
        $packet .= "\x00\x00\x0e\x10";                 // TTL
        $packet .= "\x00\x08";                         // RDLENGTH=8
        // RDATA at 52: priority 0, weight 0, port 8096, then C0 0C target
        $packet .= "\x00\x00\x00\x00\x1f\xa0\xc0\x0c";

        $parsed = $this->socket->parseResponse($packet);

        $this->assertIsArray($parsed);
        /** @var array{records: list<array{name: string, type: int, data: mixed}>} $parsed */
        $data = $parsed['records'][0]['data'];
        $this->assertIsArray($data);
        $this->assertSame(8096, $data['port']);
        $this->assertSame('_phlix._tcp.local', $data['target']);
    }

    public function testPlainUncompressedNamesStillDecode(): void
    {
        // No pointers at all — the historical happy path must be untouched.
        $data = $this->header() . "\x03foo\x03bar\x00";

        [$name, $offset] = $this->decode($data, 12);

        $this->assertSame('foo.bar', $name);
        // 12 + 4 + 4 + 1 (root) = 21 — offset sits PAST the terminating zero.
        $this->assertSame(21, $offset);
    }
}
