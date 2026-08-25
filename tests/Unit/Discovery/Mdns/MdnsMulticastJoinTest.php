<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Discovery\Mdns;

use PHPUnit\Framework\TestCase;
use Phlix\Discovery\Mdns\MdnsSocket;

/**
 * Three-arm multicast experiment for `MdnsSocket`'s group join (S296).
 *
 * ## The defect this pins
 *
 * `MdnsSocket` used to join `224.0.0.251` with the BSD-style
 * `IP_ADD_MEMBERSHIP` spelling — an option number PHP does not define
 * (measured: `defined('IP_ADD_MEMBERSHIP') === false` on PHP 8.3.6), so the
 * call fell back to the raw option number `12` and handed a binary string
 * (`inet_pton($group) . inet_pton($iface)`) where an int is expected.
 * `socket_set_option()` returns TRUE for that call and joins nothing — so
 * mDNS/Bonjour discovery received zero multicast datagrams while every caller
 * believed the join succeeded. A TRUE return is exactly why the defect went
 * unnoticed; a TRUE return is NOT evidence of delivery, which is why this test
 * asserts delivery of a real datagram.
 *
 * ## The three arms (identical sockets, real datagrams)
 *
 *   A. no join at all
 *                              → receives NOTHING (non-vacuity control)
 *   B. the old MdnsSocket spelling, replayed verbatim from the pre-fix class
 *      (raw option `12` + `inet_pton($group)` alone as optval, because PHP
 *      does not define `IP_ADD_MEMBERSHIP`)
 *                              → `socket_set_option()` returns TRUE and
 *                                receives NOTHING (the failing control;
 *                                measured on PHP 8.3.6). S51's SSDP control
 *                                measured the 8-byte group+iface variant of
 *                                the same spelling with the identical outcome.
 *   C. `MdnsSocket::joinMulticastGroup()`, the production method, invoked on a
 *      real socket → receives the datagram
 *
 * Arm B is the pre-fix code, replayed verbatim. Arm C runs the production
 * METHOD — not a copy of it — so deleting the join line or regressing the
 * spelling reddens this test.
 *
 * ## STATED BLIND SPOT
 *
 * The call-site wiring in `createSocket()` (the `$this->joinMulticastGroup($socket)`
 * call after the bind) is NOT pinned by this test: `createSocket()` binds the
 * fixed port 5353, which risks an avahi/system-mDNS conflict, and its join
 * runs with the production `interface => 0` default — which on this host
 * (multicast loops back only on `lo`) cannot deliver, so a receipt assertion
 * through `createSocket()` would be a false red by construction. What IS
 * pinned is the join method itself: its option spelling, its failure
 * behaviour, and the fact that it actually causes multicast delivery.
 *
 * ## STATED LIMIT
 *
 * All three arms run on whichever interface this host actually loops
 * multicast back on, which on a VM (and on this dev box, measured) is `lo` and
 * NOT the LAN interface production's `interface => 0` resolves to. What is
 * therefore proved is that the production METHOD, with the production OPTION
 * SPELLING, really does cause multicast delivery — and that the old spelling
 * really does not. The kernel's interface-0 route selection is NOT exercised;
 * that needs a second host on a real segment.
 */
class MdnsMulticastJoinTest extends TestCase
{
    public function test_only_the_mcast_join_group_spelling_receives_a_datagram(): void
    {
        if (!defined('MCAST_JOIN_GROUP')) {
            self::markTestSkipped('ext-sockets multicast support is not available.');
        }

        $group = MdnsSocket::MULTICAST_ADDR;

        // Environment probe FIRST, with raw sockets and no production code. If
        // a correct join cannot receive on ANY interface here, the host does
        // not loop multicast back and this test has nothing to say.
        $ifIndex = $this->findMulticastLoopbackInterface($group);
        if ($ifIndex === null) {
            self::markTestSkipped('This host does not deliver IPv4 multicast back to a joined socket.');
        }

        // ---- Arm A: no join at all.
        [$noJoin, $portA] = $this->bindEphemeral();
        $armA = $this->multicastRoundTrip($noJoin, $group, $portA, $ifIndex);
        socket_close($noJoin);

        // ---- Arm B: the OLD MdnsSocket spelling, replayed verbatim from the
        // pre-fix class (raw option 12, inet_pton($group) alone as optval).
        [$oldStyle, $portB] = $this->bindEphemeral();
        $oldOption = defined('IP_ADD_MEMBERSHIP') ? IP_ADD_MEMBERSHIP : 12;
        $armBReturn = @socket_set_option(
            $oldStyle,
            IPPROTO_IP,
            $oldOption,
            inet_pton($group)
        );
        $armB = $this->multicastRoundTrip($oldStyle, $group, $portB, $ifIndex);
        socket_close($oldStyle);

        // ---- Arm C: the PRODUCTION join method on a real socket.
        [$newStyle, $portC] = $this->bindEphemeral();
        $mdns = new MdnsSocket(null, 1);
        $join = new \ReflectionMethod(MdnsSocket::class, 'joinMulticastGroup');
        $join->setAccessible(true);
        $armCJoined = $join->invoke($mdns, $newStyle, $ifIndex);
        $armC = $this->multicastRoundTrip($newStyle, $group, $portC, $ifIndex);
        socket_close($newStyle);

        self::assertFalse($armA, 'CONTROL: without a group join, multicast must NOT be delivered.');
        self::assertTrue(
            $armBReturn,
            'The old MdnsSocket spelling returns TRUE — that is precisely why it is dangerous.'
        );
        self::assertFalse(
            $armB,
            'The old MdnsSocket spelling must be shown to receive NOTHING despite returning TRUE. '
            . 'If this ever passes, the raw-12 spelling became correct and this warning can be retired.'
        );
        self::assertTrue($armCJoined, 'The production join must report success.');
        self::assertTrue(
            $armC,
            'The production join must actually deliver multicast to the socket — this is the assertion '
            . 'that separates a real join from a silent no-op.'
        );
    }

    // ==================================================================
    // Harness (raw sockets only — no production code in the probes)
    // ==================================================================

    /**
     * Bind a UDP socket on a free ephemeral port, `0.0.0.0` like production.
     *
     * A loopback-only socket cannot join an IPv4 multicast group on the
     * default interface, and production binds `0.0.0.0` too.
     *
     * @return array{0: \Socket, 1: int} Bound socket and its port.
     */
    private function bindEphemeral(): array
    {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        self::assertNotFalse($socket, 'socket_create failed');
        @socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        @socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);

        $bound = @socket_bind($socket, '0.0.0.0', 0);
        self::assertTrue($bound, 'socket_bind failed: ' . socket_strerror(socket_last_error($socket)));

        socket_getsockname($socket, $addr, $port);
        self::assertIsInt($port);
        self::assertGreaterThan(0, $port);

        return [$socket, $port];
    }

    /**
     * The lowest interface index on which this host loops multicast back to a
     * correctly joined socket, or null if there is none.
     *
     * Raw sockets only — no production code — so that a "no multicast here"
     * environment is detected BEFORE anything under test is built and can
     * never be mistaken for a broken join.
     */
    private function findMulticastLoopbackInterface(string $group): ?int
    {
        // 0 = kernel routes it; 1..4 covers lo plus the first few NICs.
        foreach ([0, 1, 2, 3, 4] as $ifIndex) {
            [$socket, $port] = $this->bindEphemeral();
            $joined = @socket_set_option(
                $socket,
                IPPROTO_IP,
                MCAST_JOIN_GROUP,
                ['group' => $group, 'interface' => $ifIndex]
            );
            $received = $joined === true && $this->multicastRoundTrip($socket, $group, $port, $ifIndex);
            socket_close($socket);

            if ($received) {
                return $ifIndex;
            }
        }

        return null;
    }

    /**
     * Send one datagram to a multicast group and report whether $socket got it.
     */
    private function multicastRoundTrip(\Socket $socket, string $group, int $port, int $ifIndex): bool
    {
        $sender = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sender === false) {
            return false;
        }

        // TTL 1 — never leaves the local segment.
        @socket_set_option($sender, IPPROTO_IP, IP_MULTICAST_LOOP, 1);
        @socket_set_option($sender, IPPROTO_IP, IP_MULTICAST_TTL, 1);
        if ($ifIndex > 0) {
            @socket_set_option($sender, IPPROTO_IP, IP_MULTICAST_IF, $ifIndex);
        }
        $payload = 'PHLIX-S296-MCAST-PROBE';
        @socket_sendto($sender, $payload, strlen($payload), 0, $group, $port);
        socket_close($sender);

        $data = '';
        $from = '';
        $fromPort = 0;
        $bytes = @socket_recvfrom($socket, $data, 1500, 0, $from, $fromPort);

        return $bytes !== false && $data === $payload;
    }
}
