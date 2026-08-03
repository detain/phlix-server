<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Network;

use PHPUnit\Framework\TestCase;
use Phlix\Network\PortProbeOutcome;

/**
 * S169 — the errno classifier, tested as the pure function it is.
 *
 * The live probe tests in {@see StunClientTest} can only produce two outcomes
 * deterministically (a handshake against a listener this suite owns, and an
 * immediate RST from a closed loopback port). "Timed out" and "unreachable"
 * depend on the host's firewall policy — which is exactly what made the OLD
 * StunClient test give opposite answers on the dev box and in CI — so they are
 * pinned here instead, on the classification rather than on the network.
 */
final class PortProbeOutcomeTest extends TestCase
{
    public function testOnlyAConnectedHandshakeCountsAsOpen(): void
    {
        $this->assertTrue(PortProbeOutcome::Open->isOpen());

        foreach (PortProbeOutcome::cases() as $case) {
            if ($case === PortProbeOutcome::Open) {
                continue;
            }
            $this->assertFalse(
                $case->isOpen(),
                "{$case->value} must not read as an open port — see the class docblock for why "
                . 'ECONNREFUSED in particular does not (S169).'
            );
        }
    }

    public function testEconnrefusedIsRefusedAndThereforeNotOpen(): void
    {
        // THE defect. The old coroutine arm returned true here ("refused also
        // means accessible") and the old fallback did too (`$errno === 111`).
        $outcome = PortProbeOutcome::fromErrno(PortProbeOutcome::ECONNREFUSED);

        $this->assertSame(PortProbeOutcome::Refused, $outcome);
        $this->assertFalse($outcome->isOpen());
    }

    public function testTimeoutIsItsOwnOutcomeAndNotOpen(): void
    {
        $outcome = PortProbeOutcome::fromErrno(PortProbeOutcome::ETIMEDOUT);

        $this->assertSame(PortProbeOutcome::TimedOut, $outcome);
        $this->assertFalse($outcome->isOpen());
    }

    public function testHostAndNetworkUnreachableBothClassifyAsUnreachable(): void
    {
        $this->assertSame(
            PortProbeOutcome::Unreachable,
            PortProbeOutcome::fromErrno(PortProbeOutcome::EHOSTUNREACH)
        );
        $this->assertSame(
            PortProbeOutcome::Unreachable,
            PortProbeOutcome::fromErrno(PortProbeOutcome::ENETUNREACH)
        );
    }

    public function testSwooleDnsFailureIsUnresolved(): void
    {
        $this->assertSame(
            PortProbeOutcome::Unresolved,
            PortProbeOutcome::fromErrno(PortProbeOutcome::SWOOLE_DNS_LOOKUP_FAILED)
        );
    }

    public function testAnUnreportedErrnoFailsClosedRatherThanOpen(): void
    {
        // The other half of the old fallback's `return $errno === 111 || $errno === 0;`.
        // errno 0 is what fsockopen() reports for a name that will not resolve
        // (measured: errno 0, "php_network_getaddresses: getaddrinfo ... failed"),
        // and what PHP leaves behind when it has nothing to say. An unmeasurable
        // probe must never read as success.
        $outcome = PortProbeOutcome::fromErrno(0);

        $this->assertSame(PortProbeOutcome::Failed, $outcome);
        $this->assertFalse($outcome->isOpen());
    }

    public function testAnUnknownErrnoFailsClosed(): void
    {
        // EACCES — a plausible connect() failure that is none of the classified
        // cases. The classifier is total, and its default is not optimistic.
        $outcome = PortProbeOutcome::fromErrno(13);

        $this->assertSame(PortProbeOutcome::Failed, $outcome);
        $this->assertFalse($outcome->isOpen());
    }

    /**
     * The four errno constants are hard-coded integers because ext-sockets is
     * neither in composer.json's require list nor loaded in CI's static-analysis
     * jobs. When the extension IS present, they must agree with it.
     */
    public function testTheErrnoConstantsMatchExtSocketsWhenItIsAvailable(): void
    {
        if (!extension_loaded('sockets')) {
            $this->markTestSkipped('ext-sockets is not loaded, so there is nothing to cross-check against.');
        }

        $this->assertSame(SOCKET_ECONNREFUSED, PortProbeOutcome::ECONNREFUSED);
        $this->assertSame(SOCKET_ETIMEDOUT, PortProbeOutcome::ETIMEDOUT);
        $this->assertSame(SOCKET_EHOSTUNREACH, PortProbeOutcome::EHOSTUNREACH);
        $this->assertSame(SOCKET_ENETUNREACH, PortProbeOutcome::ENETUNREACH);
    }
}
