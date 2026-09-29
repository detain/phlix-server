<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Network;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Network\NatPmpClient;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

class NatPmpClientTest extends TestCase
{
    private NatPmpClient $client;

    protected function setUp(): void
    {
        $this->client = new NatPmpClient(new NullLogger(), 500);
    }

    public function testClientCanBeInstantiated(): void
    {
        $client = new NatPmpClient();
        $this->assertInstanceOf(NatPmpClient::class, $client);
    }

    public function testClientWithCustomTimeout(): void
    {
        $client = new NatPmpClient(new NullLogger(), 5000);
        $this->assertInstanceOf(NatPmpClient::class, $client);
    }

    public function testDiscoverGatewayReturnsNullForInvalidIp(): void
    {
        $result = $this->client->discoverGateway('192.0.2.1');
        $this->assertNull($result);
    }

    public function testAddPortMappingReturnsNullForInvalidIp(): void
    {
        $result = $this->client->addPortMapping('192.0.2.1', 32400, 32400);
        $this->assertNull($result);
    }

    public function testRemovePortMappingReturnsFalseForInvalidIp(): void
    {
        $result = $this->client->removePortMapping('192.0.2.1', 32400);
        $this->assertFalse($result);
    }

    public function testRemovePortMappingWithUdpProtocol(): void
    {
        $result = $this->client->removePortMapping('192.0.2.1', 32400, 'UDP');
        $this->assertFalse($result);
    }

    // ------------------------------------------------------------------
    // Device-lane rework (item 3): the L4 wrong-source discard Exception 5
    // cites had ZERO executed coverage — every pre-existing case here only
    // proves timeouts against unroutable TEST-NET addresses. The case below
    // is a real wire exchange with sockets this test owns.
    // ------------------------------------------------------------------

    /**
     * A hostile host on the segment answers NAT-PMP with the same shape the
     * router would (12 bytes, valid opcode) — it must be DISCARDED, and
     * because discoverGateway() keeps polling after a discard, the gateway's
     * own reply 150 ms later must still be the one the client returns.
     *
     * Harness: the "gateway" is bound to 127.0.0.99:5350 (every 127/8 address
     * is local on Linux — no interface alias needed) and the "rogue" to
     * 127.0.0.1; a forked child learns the client's real source from the
     * request the gateway receives, fires the bogus reply from the rogue,
     * then the correct reply from the gateway. Remove the L4 check in
     * NatPmpClient and this test reddens with '198.51.100.66' — it is a true
     * tripwire, not a shape assertion.
     */
    public function testDiscoverGatewayDiscardsWrongSourceThenAcceptsGatewayReply(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix are required for the forked-responder harness.');
        }

        $gateway = '127.0.0.99';
        $server = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($server);
        if (@socket_bind($server, $gateway, 5350) === false) {
            $reason = socket_strerror(socket_last_error($server));
            socket_close($server);
            $this->markTestSkipped('cannot bind ' . $gateway . ':5350 on this host: ' . $reason);
        }

        $pid = pcntl_fork();
        if ($pid === 0) {
            // Child = the segment. Rogue answers first; the real gateway follows.
            $rogue = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($rogue === false || @socket_bind($rogue, '127.0.0.1', 0) === false) {
                posix_kill(posix_getpid(), SIGKILL);
            }

            $read = [$server];
            $write = null;
            $except = null;
            if (@socket_select($read, $write, $except, 5) < 1) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            $buf = '';
            $clientIp = '';
            $clientPort = 0;
            if (@socket_recvfrom($server, $buf, 1024, 0, $clientIp, $clientPort) === false) {
                posix_kill(posix_getpid(), SIGKILL);
            }

            @socket_sendto($rogue, $this->natPmpPublicAddressReply('198.51.100.66'), 12, 0, $clientIp, $clientPort);
            usleep(150000);
            @socket_sendto($server, $this->natPmpPublicAddressReply('203.0.113.9'), 12, 0, $clientIp, $clientPort);
            posix_kill(posix_getpid(), SIGKILL);
        }

        // Parent keeps no responder fd; the child owns the whole exchange.
        socket_close($server);

        $client = new NatPmpClient(new NullLogger(), 3000);
        try {
            $this->assertSame(
                '203.0.113.9',
                $client->discoverGateway($gateway),
                'the rogue reply must be discarded (L4) and the poll loop must still '
                . 'accept the gateway answer that follows it'
            );
        } finally {
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * NAT-PMP public-address reply built per RFC 6886 §3.2, byte for byte:
     * version(1)=0, opcode(1)=128+0, result code(2)=0 success, seconds
     * since start of epoch(4), external IPv4(4) at bytes 8-11 — the size
     * floor discoverGateway() requires and the field parseExternalIp()
     * reads.
     *
     * Earlier revisions of this fixture placed the address at the parser's
     * then-wrong offset (bytes 4-7) and documented the deviation instead of
     * blessing it; the epoch is deliberately non-zero here so the fixture
     * is RFC-constructed and mutation-honest: a parser reverted to bytes
     * 4-7 would decode 0.15.66.64, never the asserted address. The
     * guarantee this test owns — L4 source discard plus poll-loop
     * continuation — now runs against the spec-correct wire shape.
     */
    private function natPmpPublicAddressReply(string $ip): string
    {
        $packed = inet_pton($ip);
        $this->assertIsString($packed);
        return chr(0) . chr(0x80) . pack('n', 0) . pack('N', 1000000) . $packed;
    }

    // ------------------------------------------------------------------
    // Wire-fidelity pass (items 1-2): RFC 6886 §3.3 port-slot order and
    // the §3.2/§3.5 result-code gate, pinned byte-for-byte on the private
    // request builders / reply parser (ReflectionMethod idiom as used in
    // UpnpIgdClientTest). These are the offsets themselves under test —
    // a non-symmetric internal≠external request is what makes the pin
    // mutation-honest: the pre-fix swapped order reddens the port-offset
    // assertions, and a result-blind parser reddens the failure arms.
    //
    // Opcode pass (this follow-up): the §3.3 opcode constants were corrected
    // (RFC: 1 = Map UDP, 2 = Map TCP) and mapPort() gained the §3.5
    // result-code gate. New pins below: the constant assignment itself,
    // per-protocol wire bytes, the 2-byte §3.2 address request, and two
    // fork-responder round trips proving TCP opcode 2 leaves on the socket
    // and that a refused mapping reply yields null — never the old defect
    // of returning the reply's zeroed (or junk) port slot as a success.
    // ------------------------------------------------------------------

    private function invokeWireMethod(string $name, int|string ...$args): mixed
    {
        $method = new ReflectionMethod(NatPmpClient::class, $name);
        $method->setAccessible(true);
        return $method->invoke($this->client, ...$args);
    }

    /** @return array<int, int|string> */
    private function u16(string $packet, int $offset): array
    {
        $unpacked = unpack('n', substr($packet, $offset, 2));
        $this->assertIsArray($unpacked);
        return $unpacked;
    }

    /**
     * Reads one of NatPmpClient's private OP_CODE_MAP_* constants so the
     * byte pins below travel with the class's actual assignment instead of
     * hard-coding a value that a revert could re-symmetrize.
     */
    private static function opCode(string $name): int
    {
        $value = (new ReflectionClass(NatPmpClient::class))->getConstant($name);
        self::assertIsInt($value, $name . ' must exist as an int constant');
        return $value;
    }

    /**
     * §3.3 mapping request layout — 12 bytes:
     *   byte 0 version, byte 1 opcode, bytes 2-3 reserved(0),
     *   bytes 4-5 Internal Port, bytes 6-7 Suggested External Port,
     *   bytes 8-11 lifetime.
     * The ports are deliberately NON-symmetric (internal 8080, suggested
     * external 32400): the swapped pre-fix order wrote 32400@4-5 and
     * 8080@6-7, so both port assertions below fail if the order regresses.
     * The opcode byte is the corrected §3.3 TCP value ("2 - Map TCP"),
     * read live from the class constant — see
     * testOpcodeConstantsMatchRfc6886Section33 for the assignment tripwire
     * and testTcpAndUdpMapRequestsCarryDistinctRfcOpcodesForTheirProtocol
     * for the per-protocol wire bytes.
     */
    public function testMapRequestPutsInternalPortAtBytes4AndSuggestedExternalAtBytes6(): void
    {
        $tcp = self::opCode('OP_CODE_MAP_TCP');
        $request = $this->invokeWireMethod('buildMapRequest', $tcp, 32400, 8080, 7200);
        $this->assertIsString($request);

        $this->assertSame(12, strlen($request), '§3.3 mapping request is 12 bytes');
        $this->assertSame(0, ord($request[0]), 'version 0');
        $this->assertSame(2, ord($request[1]), 'TCP rides opcode 2 (§3.3: "2 - Map TCP")');
        $this->assertSame(0, $this->u16($request, 2)[1], 'reserved bytes 2-3 MUST be zero');
        $this->assertSame(8080, $this->u16($request, 4)[1], 'Internal Port lives at bytes 4-5');
        $this->assertSame(32400, $this->u16($request, 6)[1], 'Suggested External Port lives at bytes 6-7');

        $lifetime = unpack('N', substr($request, 8, 4));
        $this->assertIsArray($lifetime);
        $this->assertSame(7200, $lifetime[1], 'lifetime lives at bytes 8-11');
    }

    /**
     * §3.3 anonymous-port shape: "set the Suggested External Port to zero"
     * asks the gateway to allocate a high port. Pre-fix, that zero landed
     * in the Internal Port slot — telling the gateway the client listened
     * on port 0 — while the real internal port was sent as the suggestion.
     */
    public function testMapRequestWithZeroSuggestedExternalPortKeepsInternalPortInItsOwnSlot(): void
    {
        $request = $this->invokeWireMethod('buildMapRequest', self::opCode('OP_CODE_MAP_UDP'), 0, 9000, 3600);
        $this->assertIsString($request);

        $this->assertSame(12, strlen($request));
        $this->assertSame(9000, $this->u16($request, 4)[1], 'internal port must survive at bytes 4-5');
        $this->assertSame(0, $this->u16($request, 6)[1], 'suggested external 0 = allocate-any-high-port');
    }

    /**
     * §3.4 deletion request: "sending a message to the NAT gateway
     * requesting the mapping, with the Requested Lifetime in Seconds set
     * to zero. The Suggested External Port MUST be set to zero by the
     * client on sending, and MUST be ignored by the gateway on reception."
     * A deletion is keyed by the INTERNAL Port slot (bytes 4-5) — the
     * number callers hand removePortMapping() fills it (mappings are
     * created symmetrically, internal == external, see PortForwardService).
     * This pin guards the swap trap: "fixing" unmap the way buildMapRequest
     * was fixed would move the port to bytes 6-7 — a slot §3.4 requires to
     * be zero and gateways MUST ignore — leaving internal 0, which is the
     * wildcard shape that deletes ALL of the client's mappings for the
     * opcode's protocol, not the targeted one.
     */
    public function testUnmapRequestCarriesPortInInternalSlotWithZeroExternalAndLifetime(): void
    {
        $request = $this->invokeWireMethod('buildUnmapRequest', self::opCode('OP_CODE_MAP_TCP'), 32400);
        $this->assertIsString($request);

        $this->assertSame(12, strlen($request), '§3.4 deletion reuses the §3.3 12-byte request shape');
        $this->assertSame(0, ord($request[0]), 'version 0');
        $this->assertSame(2, ord($request[1]), 'a TCP deletion rides opcode 2 (§3.3: "2 - Map TCP")');
        $this->assertSame(0, $this->u16($request, 2)[1], 'reserved bytes 2-3 MUST be zero');
        $this->assertSame(
            32400,
            $this->u16($request, 4)[1],
            'deletion key (the mapped port) at the Internal Port slot'
        );
        $this->assertSame(0, $this->u16($request, 6)[1], 'Suggested External Port MUST be zero on a deletion (§3.4)');

        $lifetime = unpack('N', substr($request, 8, 4));
        $this->assertIsArray($lifetime);
        $this->assertSame(0, $lifetime[1], 'lifetime 0 marks the deletion');
    }

    /**
     * §3.2 public-address reply with result code 0: the address at
     * bytes 8-11 parses normally — the success arm the discoverGateway()
     * fork test already exercises end-to-end, pinned here at the unit.
     */
    public function testParseExternalIpReturnsAddressOnSuccessResultCodeZero(): void
    {
        $parsed = $this->invokeWireMethod('parseExternalIp', $this->natPmpPublicAddressReply('203.0.113.9'));
        $this->assertSame('203.0.113.9', $parsed);
    }

    /**
     * §3.5's defined failure codes (1 Unsupported Version, 2 Not
     * Authorized/Refused, 3 Network Failure, 4 Out of resources,
     * 5 Unsupported opcode; undefined codes are likewise fatal per §3.5).
     * The reply is built the way a NON-compliant gateway might send it —
     * result code failed but the External IPv4 field still holding a real
     * address, which §3.2 makes MUST-ignore ("MUST be set to zero on
     * transmission, and MUST be ignored on reception"). The gate must read
     * the RESULT, never the address bytes: pre-fix this parsed straight
     * through to an address, and on RFC-compliant all-zero fields it
     * returned the string '0.0.0.0' as a success-looking result.
     */
    #[DataProvider('failedResultCodes')]
    public function testParseExternalIpRejectsFailedResultCodesEvenWithNonZeroAddressField(int $code): void
    {
        $reply = chr(0) . chr(0x80) . pack('n', $code) . pack('N', 1000000) . inet_pton('198.51.100.66');

        $this->assertNull(
            $this->invokeWireMethod('parseExternalIp', $reply),
            'result code ' . $code . ' is a failed request and its address field must never parse'
        );
    }

    /**
     * The RFC-compliant failure shape: non-zero result, zero-filled
     * address field. Pre-fix the parser returned the string '0.0.0.0' —
     * indistinguishable downstream from a "success" whose gateway IP
     * happens to be all zeros.
     */
    public function testParseExternalIpDoesNotReturnZeroAddressOnFailedResultCode(): void
    {
        $reply = chr(0) . chr(0x80) . pack('n', 2) . pack('N', 1000000) . pack('N', 0);
        $this->assertNull($this->invokeWireMethod('parseExternalIp', $reply));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function failedResultCodes(): array
    {
        return [
            'unsupported version (§3.5 code 1)' => [1],
            'not authorized/refused (§3.5 code 2)' => [2],
            'network failure (§3.5 code 3)' => [3],
            'out of resources (§3.5 code 4)' => [4],
            'unsupported opcode (§3.5 code 5)' => [5],
            'undefined code (§3.5: "MUST be treated as fatal errors")' => [9],
        ];
    }

    // ------------------------------------------------------------------
    // Opcode pass: the §3.3 assignment tripwire, per-protocol wire bytes,
    // the 2-byte §3.2 address request, the mapPort()/parseMappingResponse()
    // §3.5 result-code gate, and two real socket round trips through the
    // public addPortMapping() API.
    // ------------------------------------------------------------------

    /**
     * RFC 6886 §3.3 ("Opcodes supported: 1 - Map UDP, 2 - Map TCP",
     * rfc-editor.org/rfc/rfc6886.txt lines 526-528) assigns UDP first and
     * TCP second. The constants held the inverse until this pass, which put
     * every TCP forward PortForwardService requests on the wire as a UDP
     * mapping — this test is the dedicated anti-inversion tripwire: flipping
     * either constant reddens it regardless of every other byte pin.
     */
    public function testOpcodeConstantsMatchRfc6886Section33(): void
    {
        $this->assertSame(1, self::opCode('OP_CODE_MAP_UDP'), '§3.3: "1 - Map UDP"');
        $this->assertSame(2, self::opCode('OP_CODE_MAP_TCP'), '§3.3: "2 - Map TCP"');
    }

    /**
     * Per-protocol wire bytes on the corrected constants, NON-symmetric on
     * purpose: TCP 8080 -> opcode byte 2, UDP 9090 -> opcode byte 1, and the
     * two requests are provably distinct packets. Under the old inverted
     * constants both opcode assertions fail simultaneously.
     */
    public function testTcpAndUdpMapRequestsCarryDistinctRfcOpcodesForTheirProtocol(): void
    {
        $tcp = $this->invokeWireMethod('buildMapRequest', self::opCode('OP_CODE_MAP_TCP'), 8080, 8080, 3600);
        $udp = $this->invokeWireMethod('buildMapRequest', self::opCode('OP_CODE_MAP_UDP'), 9090, 9090, 3600);
        $this->assertIsString($tcp);
        $this->assertIsString($udp);

        $this->assertSame(2, ord($tcp[1]), 'a TCP mapping request rides opcode 2');
        $this->assertSame(1, ord($udp[1]), 'a UDP mapping request rides opcode 1');
        $this->assertNotSame($tcp, $udp, 'the two protocols must not produce identical packets');
    }

    /**
     * §3.2's address-request diagram is a single 16-bit row
     * ("Vers = 0 | OP = 0") — 2 bytes, no Reserved field. The builder used
     * to append two zero bytes of slack; gateways tolerated it (fixed-offset
     * parsing), so this pin is spec fidelity rather than a live-defect fix,
     * and the fork round trip below re-proves the address path end to end.
     */
    public function testPublicAddressRequestIsTheTwoByteSection32Shape(): void
    {
        $request = $this->invokeWireMethod('buildPublicAddressRequest');
        $this->assertIsString($request);

        $this->assertSame(2, strlen($request), '§3.2 address request is exactly 2 bytes');
        $this->assertSame(0, ord($request[0]), 'version 0');
        $this->assertSame(0, ord($request[1]), 'opcode 0 = public address request');
    }

    /**
     * §3.3 mapping reply built to spec, 16 bytes: 128+opcode, result code,
     * epoch seconds, Internal Port echo, Mapped External Port, lifetime.
     */
    private function natPmpMappingReply(
        int $requestOpCode,
        int $resultCode,
        int $internalPort,
        int $mappedExternalPort,
        int $lifetime
    ): string {
        return chr(0) . chr($requestOpCode | 0x80)
            . pack('n', $resultCode) . pack('N', 1000000)
            . pack('n', $internalPort) . pack('n', $mappedExternalPort)
            . pack('N', $lifetime);
    }

    /**
     * Success arm: result 0 -> the Mapped External Port at bytes 10-11 is
     * returned. The reply's mapped port (41000) deliberately differs from
     * any port the request could have carried, so a parser that echoed the
     * request (or read the wrong slot) cannot pass.
     */
    public function testParseMappingResponseReturnsMappedPortOnSuccessResultCodeZero(): void
    {
        $reply = $this->natPmpMappingReply(2, 0, 32400, 41000, 7200);
        $this->assertSame(16, strlen($reply), '§3.3 mapping reply is 16 bytes');

        $this->assertSame(41000, $this->invokeWireMethod('parseMappingResponse', $reply, 2));
    }

    /**
     * The result-code gate this pass adds to the mapping path (§3.5: failed
     * replies carry zeroed Mapped External Port / lifetime, and undefined
     * codes are fatal). Built NON-compliant on purpose — failure result but
     * a real port still parked at bytes 10-11 — so the assertion proves the
     * gate reads the RESULT, never the port slot. Pre-gate, every one of
     * these returned 41000; on the RFC-compliant all-zero shape (pinned by
     * the fork test below) the pre-gate client returned int 0 as if the
     * gateway had assigned port 0.
     */
    #[DataProvider('failedResultCodes')]
    public function testParseMappingResponseRejectsFailedResultCodesEvenWithJunkPortFields(int $code): void
    {
        $reply = $this->natPmpMappingReply(2, $code, 32400, 41000, 0);

        $this->assertNull(
            $this->invokeWireMethod('parseMappingResponse', $reply, 2),
            'result code ' . $code . ' is a failed mapping; its port field must never parse'
        );
    }

    /**
     * §3.3: "The 'x' in the OP field MUST match what the client requested."
     * A UDP reply (128+1) must not satisfy a TCP (2) request even with a
     * zero result code.
     */
    public function testParseMappingResponseIgnoresReplyWhoseOpcodeDoesNotMatchRequest(): void
    {
        $reply = $this->natPmpMappingReply(1, 0, 32400, 41000, 7200);

        $this->assertNull($this->invokeWireMethod('parseMappingResponse', $reply, 2));
    }

    public function testParseMappingResponseRejectsTruncatedReply(): void
    {
        $this->assertNull($this->invokeWireMethod('parseMappingResponse', chr(0) . chr(0x82), 2));
    }

    /**
     * Binds the fork-responder "gateway" on loopback (every 127/8 address is
     * local on Linux — see testDiscoverGatewayDiscardsWrongSourceThenAcceptsGatewayReply).
     */
    private function bindLoopbackGateway(string $gateway): \Socket
    {
        $server = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($server);
        if (@socket_bind($server, $gateway, 5350) === false) {
            $reason = socket_strerror(socket_last_error($server));
            socket_close($server);
            $this->markTestSkipped('cannot bind ' . $gateway . ':5350 on this host: ' . $reason);
        }
        return $server;
    }

    /**
     * End-to-end wire truth through the PUBLIC API: addPortMapping() must
     * put RFC opcode 2 on the socket, and a successful RFC-shaped reply's
     * mapped port (the reply's 41000, different from the requested 32400)
     * must come back out. The child captures the exact request bytes to a
     * probe file, so this asserts the live packet, not a builder guess.
     */
    public function testAddPortMappingSendsTcpOpcodeTwoAndParsesMappedPortOnTheWire(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix are required for the forked-responder harness.');
        }

        $gateway = '127.0.0.99';
        $server = $this->bindLoopbackGateway($gateway);
        $probeFile = tempnam(sys_get_temp_dir(), 'natpmp-probe-');
        $this->assertIsString($probeFile);

        $pid = pcntl_fork();
        if ($pid === 0) {
            $read = [$server];
            $write = null;
            $except = null;
            if (@socket_select($read, $write, $except, 5) < 1) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            $buf = '';
            $clientIp = '';
            $clientPort = 0;
            if (@socket_recvfrom($server, $buf, 1024, 0, $clientIp, $clientPort) === false) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            file_put_contents($probeFile, $buf);
            // Echo the requested opcode's reply (per §3.3 "128 + x") with a
            // zero result code, internal port echoed, and a DIFFERENT
            // assigned external port.
            $reply = $this->natPmpMappingReply(ord($buf[1]), 0, 32400, 41000, 7200);
            @socket_sendto($server, $reply, strlen($reply), 0, $clientIp, $clientPort);
            posix_kill(posix_getpid(), SIGKILL);
        }

        socket_close($server);

        $client = new NatPmpClient(new NullLogger(), 3000);
        try {
            $assigned = $client->addPortMapping($gateway, 32400, 32400);
        } finally {
            pcntl_waitpid($pid, $status);
        }

        $observed = file_get_contents($probeFile);
        unlink($probeFile);
        $this->assertIsString($observed);

        $this->assertSame(12, strlen($observed), '§3.3 mapping request is 12 bytes on the wire');
        $this->assertSame(2, ord($observed[1]), 'the TCP API must put opcode 2 ("Map TCP") on the wire');
        $this->assertSame(32400, $this->u16($observed, 4)[1], 'internal port at bytes 4-5');
        $this->assertSame(32400, $this->u16($observed, 6)[1], 'suggested external at bytes 6-7');
        $this->assertSame(41000, $assigned, 'the reply\'s Mapped External Port (not the request) is returned');
    }

    /**
     * End-to-end gate: an RFC-compliant REFUSED reply (§3.5 code 2, and per
     * §3.5 "Mapped External Port ... zero if no successful port mapping was
     * created") must yield null. Pre-gate, mapPort() returned int 0 from
     * this exact packet — PortForwardService would then persist the endpoint
     * "203.0.113.9:0" as a success. The same harness also re-pins the
     * corrected opcode and exercises the 2-byte address-request path via the
     * shared builder shape assertions above.
     */
    public function testAddPortMappingReturnsNullOnRefusedResultCodeInsteadOfPortZero(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix are required for the forked-responder harness.');
        }

        $gateway = '127.0.0.99';
        $server = $this->bindLoopbackGateway($gateway);

        $pid = pcntl_fork();
        if ($pid === 0) {
            $read = [$server];
            $write = null;
            $except = null;
            if (@socket_select($read, $write, $except, 5) < 1) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            $buf = '';
            $clientIp = '';
            $clientPort = 0;
            if (@socket_recvfrom($server, $buf, 1024, 0, $clientIp, $clientPort) === false) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            // Refused (code 2), RFC-compliant zeroed port/lifetime fields.
            $reply = $this->natPmpMappingReply(ord($buf[1]), 2, 32400, 0, 0);
            @socket_sendto($server, $reply, strlen($reply), 0, $clientIp, $clientPort);
            posix_kill(posix_getpid(), SIGKILL);
        }

        socket_close($server);

        $client = new NatPmpClient(new NullLogger(), 3000);
        try {
            $this->assertNull(
                $client->addPortMapping($gateway, 32400, 32400),
                'a refused mapping reply must never surface its (zeroed) port slot'
            );
        } finally {
            pcntl_waitpid($pid, $status);
        }
    }
}
