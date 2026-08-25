<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Discovery\Ssdp;

use PHPUnit\Framework\TestCase;
use Phlix\Discovery\Ssdp\SsdpSocket;
use Psr\Log\LoggerInterface;

/**
 * Three-arm multicast experiment for `SsdpSocket`'s group join (S297).
 *
 * ## The defect this pins
 *
 * `SsdpSocket::createSocket()` "joined" 239.255.255.250 by calling
 * `IP_MULTICAST_IF` with `'0.0.0.0'` under a comment reading "Join the
 * multicast group". `IP_MULTICAST_IF` selects the OUTBOUND interface — it is
 * not a membership join, so the socket received zero multicast datagrams while
 * every caller believed it was in the group. (S51's audit recorded the same
 * defect and left `SsdpSocket` untouched; S296 fixed the identical no-op in
 * `Discovery\Mdns\MdnsSocket`.) A TRUE return is NOT evidence of delivery,
 * which is why this test asserts delivery of a real datagram.
 *
 * ## The three arms (identical sockets, real datagrams)
 *
 *   A. no join at all
 *                              → receives NOTHING (non-vacuity control)
 *   B. the pre-S297 SsdpSocket spelling, replayed verbatim from the pre-fix
 *      class (`IP_MULTICAST_IF` + `'0.0.0.0'`)
 *                              → receives NOTHING (the failing control;
 *                                measured on PHP 8.3.6: set_option returned
 *                                FALSE here, and receipt was zero regardless —
 *                                the option is an outbound-interface selector,
 *                                never a join)
 *   C. `SsdpSocket::joinMulticastGroup()`, the production method, invoked on a
 *      real socket → receives the datagram
 *
 * Arm B is the pre-fix code, replayed verbatim. Arm C runs the production
 * METHOD — not a copy of it — so deleting the join line or regressing the
 * spelling reddens this test (S345 rule 3: mutate and check; both mutations
 * re-verified).
 *
 * ## STATED BLIND SPOT
 *
 * The call-site wiring in `createSocket()` (the `$this->joinMulticastGroup($socket)`
 * call after the bind) is NOT pinned by this test: `createSocket()` binds the
 * fixed port 1900, which risks a conflict with any other SSDP software on the
 * host, and its join runs with the production `interface => 0` default — which
 * on this host (multicast loops back only on `lo`, index 1) cannot deliver, so
 * a receipt assertion through `createSocket()` would be a false red by
 * construction. What IS pinned is the join method itself: its option spelling,
 * its failure behaviour, and the fact that it actually causes multicast delivery.
 *
 * ## STATED LIMIT
 *
 * All three arms run on whichever interface this host actually loops multicast
 * back on, which on a VM (and on this dev box, measured) is `lo` and NOT the
 * LAN interface production's `interface => 0` resolves to. What is therefore
 * proved is that the production METHOD, with the production OPTION SPELLING,
 * really does cause multicast delivery — and that the old spelling really does
 * not. The kernel's interface-0 route selection is NOT exercised; that needs a
 * second host on a real segment.
 */
class SsdpMulticastJoinTest extends TestCase
{
    public function test_only_the_mcast_join_group_spelling_receives_a_datagram(): void
    {
        if (!defined('MCAST_JOIN_GROUP')) {
            self::markTestSkipped('ext-sockets multicast support is not available.');
        }

        $group = SsdpSocket::MULTICAST_ADDR;

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

        // ---- Arm B: the pre-S297 SsdpSocket spelling, replayed verbatim from
        // the pre-fix class (IP_MULTICAST_IF + '0.0.0.0', the line that sat
        // under the comment "Join the multicast group").
        [$oldStyle, $portB] = $this->bindEphemeral();
        $armBReturn = @socket_set_option($oldStyle, IPPROTO_IP, IP_MULTICAST_IF, '0.0.0.0');
        $armB = $this->multicastRoundTrip($oldStyle, $group, $portB, $ifIndex);
        socket_close($oldStyle);

        // ---- Arm C: the PRODUCTION join method on a real socket.
        [$newStyle, $portC] = $this->bindEphemeral();
        $ssdp = new SsdpSocket(null, 1);
        $join = new \ReflectionMethod(SsdpSocket::class, 'joinMulticastGroup');
        $join->setAccessible(true);
        $armCJoined = $join->invoke($ssdp, $newStyle, $ifIndex);
        $armC = $this->multicastRoundTrip($newStyle, $group, $portC, $ifIndex);
        socket_close($newStyle);

        self::assertFalse($armA, 'CONTROL: without a group join, multicast must NOT be delivered.');
        self::assertIsBool(
            $armBReturn,
            'The pre-fix spelling returns a bool (FALSE on this box, TRUE on hosts where the '
            . 'outbound-interface selector succeeds) — either way it is NOT a membership join.'
        );
        self::assertFalse(
            $armB,
            'The pre-fix SsdpSocket spelling must be shown to receive NOTHING. '
            . 'If this ever passes, IP_MULTICAST_IF became a join and this warning can be retired.'
        );
        self::assertTrue($armCJoined, 'The production join must report success.');
        self::assertTrue(
            $armC,
            'The production join must actually deliver multicast to the socket — this is the assertion '
            . 'that separates a real join from a silent no-op.'
        );
    }

    /**
     * The failure paths of `joinMulticastGroup()` must be LOUD and contained,
     * never a quiet degradation to "discovery hears nothing".
     *
     * Two failure modes, both on real calls into ext-sockets (measured on
     * PHP 8.3.6):
     *
     *  1. The join call FAILS — `socket_set_option()` returns false (here:
     *     interface index 9999 does not exist on any host → ENODEV). The
     *     method must return false and log a warning.
     *  2. The runtime THROWS — under the Swoole coroutine runtime a
     *     `Swoole\Coroutine\Socket` can make the hooked `setOption()` throw
     *     for an option it does not implement; the closest CLI analogue is a
     *     TypeError from a non-Socket value, which is exactly what the
     *     `catch (\Throwable)` exists to contain. It must log and return
     *     false, never fatal a worker.
     *
     * The mock logger's `exactly(2)` warning expectation is the guard that
     * BOTH failures are reported: remove either warning and the test reddens
     * even though every return value still looks right.
     */
    public function test_join_failure_paths_are_loud_and_contained(): void
    {
        if (!defined('MCAST_JOIN_GROUP')) {
            self::markTestSkipped('ext-sockets multicast support is not available.');
        }

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('warning');

        $ssdp = new SsdpSocket($logger, 1);
        $join = new \ReflectionMethod(SsdpSocket::class, 'joinMulticastGroup');
        $join->setAccessible(true);

        // Failure mode 1: setsockopt returns false (nonexistent interface
        // index → ENODEV, measured). The method must say so, loudly.
        [$socket] = $this->bindEphemeral();
        $joined = $join->invoke($ssdp, $socket, 9999);
        self::assertFalse(
            $joined,
            'A join that fails must report false — the old spelling reported success and joined nothing.'
        );
        socket_close($socket);

        // Failure mode 2: the socket layer throws (Swoole coroutine variance,
        // exercised here as a TypeError from a non-Socket value). The method
        // must contain it, not fatal.
        $joined = $join->invoke($ssdp, 'not-a-socket');
        self::assertFalse(
            $joined,
            'A throwing setOption must be contained and reported, never fatal.'
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
        $payload = 'PHLIX-S297-SSDP-MCAST-PROBE';
        @socket_sendto($sender, $payload, strlen($payload), 0, $group, $port);
        socket_close($sender);

        $data = '';
        $from = '';
        $fromPort = 0;
        $bytes = @socket_recvfrom($socket, $data, 1500, 0, $from, $fromPort);

        return $bytes !== false && $data === $payload;
    }
}