<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Network;

use PHPUnit\Framework\TestCase;
use Phlix\Network\NatPmpClient;
use Psr\Log\NullLogger;

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
     * NAT-PMP public-address reply sized to the 12-byte floor discoverGateway()
     * requires, with the address field placed exactly where the CURRENT
     * parseExternalIp() reads it (bytes 4-7 — an RFC 6886 §3.5 offset deviation
     * flagged to the orchestrator, deliberately NOT re-pinned as "correct"
     * here; the guarantee this test owns is the L4 source discard + the
     * poll-loop continuation, both of which sit downstream of the size gate).
     */
    private function natPmpPublicAddressReply(string $ip): string
    {
        $packed = inet_pton($ip);
        $this->assertIsString($packed);
        return chr(0) . chr(0) . pack('n', 0) . $packed . pack('N', 0);
    }
}
