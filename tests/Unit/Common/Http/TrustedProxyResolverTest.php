<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Http;

use Phlix\Common\Http\TrustedProxyResolver;
use PHPUnit\Framework\TestCase;

/**
 * SV-4.15 HIGH fix: trusted-proxy-aware client-IP resolution used to build
 * spoof-resistant rate-limit keys.
 */
final class TrustedProxyResolverTest extends TestCase
{
    /**
     * A direct (untrusted) peer: forwarding headers are attacker-controlled and
     * MUST be ignored — the peer address wins.
     */
    public function testUntrustedPeerIgnoresForwardingHeaders(): void
    {
        $resolver = new TrustedProxyResolver(); // default: loopback only

        self::assertSame(
            '198.51.100.10',
            $resolver->resolve('198.51.100.10', '1.2.3.4, 5.6.7.8', '9.9.9.9'),
        );
    }

    /**
     * The shipped topology: loopback proxy peer, XFF = `<forged>, <real client>`
     * (nginx appends `$remote_addr`). The rightmost untrusted entry is the client.
     */
    public function testLoopbackPeerReturnsRightmostUntrustedXffEntry(): void
    {
        $resolver = new TrustedProxyResolver();

        self::assertSame(
            '203.0.113.9',
            $resolver->resolve('127.0.0.1', '198.51.100.66, 203.0.113.9', null),
        );
    }

    /**
     * A forged leftmost value cannot mint a fresh bucket: distinct forged prefixes
     * with the same appended real client resolve to the SAME address.
     */
    public function testForgedLeftmostDoesNotChangeResolution(): void
    {
        $resolver = new TrustedProxyResolver();

        $a = $resolver->resolve('127.0.0.1', '1.1.1.1, 203.0.113.50', null);
        $b = $resolver->resolve('127.0.0.1', '9.9.9.9, 203.0.113.50', null);
        $c = $resolver->resolve('127.0.0.1', 'garbage-not-ip, 203.0.113.50', null);

        self::assertSame('203.0.113.50', $a);
        self::assertSame($a, $b);
        self::assertSame($a, $c);
    }

    /**
     * A trusted-proxy hop appearing in the chain is skipped right-to-left.
     */
    public function testTrustedHopInChainIsSkipped(): void
    {
        $resolver = new TrustedProxyResolver(['127.0.0.0/8', '10.0.0.0/8']);

        // client(203.0.113.7) -> trusted 10.0.0.2 -> loopback peer.
        self::assertSame(
            '203.0.113.7',
            $resolver->resolve('127.0.0.1', '203.0.113.7, 10.0.0.2', null),
        );
    }

    /**
     * When XFF is absent, the proxy-set X-Real-IP (overwritten by nginx, not
     * client-spoofable) is used.
     */
    public function testFallsBackToXRealIpWhenXffAbsent(): void
    {
        $resolver = new TrustedProxyResolver();

        self::assertSame(
            '203.0.113.20',
            $resolver->resolve('127.0.0.1', null, '203.0.113.20'),
        );
    }

    /**
     * When XFF has only trusted hops and X-Real-IP is absent, the peer is used.
     */
    public function testFallsBackToPeerWhenNoUntrustedSource(): void
    {
        $resolver = new TrustedProxyResolver();

        self::assertSame(
            '127.0.0.1',
            $resolver->resolve('127.0.0.1', '127.0.0.1', null),
        );
    }

    /**
     * Key-length safety: a malformed/oversized forwarded value can never produce a
     * value longer than the IPv6 max (45 chars), protecting the VARCHAR(191) PK.
     */
    public function testMalformedOrOversizedValueCannotOverflow(): void
    {
        $resolver = new TrustedProxyResolver();

        // Oversized non-IP XFF entry with a loopback peer -> falls back to peer.
        $resolved = $resolver->resolve('127.0.0.1', str_repeat('B', 4000), null);
        self::assertSame('127.0.0.1', $resolved);
        self::assertLessThanOrEqual(45, strlen($resolved));

        // Even a malformed PEER is hard-truncated to <=45 chars.
        $peer = $resolver->resolve(str_repeat('Z', 4000), null, null);
        self::assertLessThanOrEqual(45, strlen($peer));
    }

    /**
     * IPv6 clients resolve correctly through a loopback (`::1`) proxy.
     */
    public function testIpv6ClientThroughLoopbackProxy(): void
    {
        $resolver = new TrustedProxyResolver();

        self::assertSame(
            '2001:db8::42',
            $resolver->resolve('::1', 'dead::beef, 2001:db8::42', null),
        );
    }

    public function testCustomCidrTrustedProxy(): void
    {
        $resolver = new TrustedProxyResolver(['192.168.10.0/24']);

        // Peer inside the trusted CIDR -> walk XFF -> real client.
        self::assertSame(
            '203.0.113.99',
            $resolver->resolve('192.168.10.5', '203.0.113.99', null),
        );

        // Peer outside the CIDR -> direct -> ignore XFF.
        self::assertSame(
            '203.0.113.1',
            $resolver->resolve('203.0.113.1', '203.0.113.99', null),
        );
    }

    /**
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    public static function cidrCases(): array
    {
        return [
            ['10.0.0.5', '10.0.0.0/8', true],
            ['11.0.0.5', '10.0.0.0/8', false],
            ['192.168.1.50', '192.168.1.0/24', true],
            ['192.168.2.50', '192.168.1.0/24', false],
            ['127.0.0.1', '127.0.0.0/8', true],
            ['127.0.0.1', '127.0.0.1', true],
            ['127.0.0.2', '127.0.0.1', false],
            ['::1', '::1/128', true],
            ['2001:db8::1', '2001:db8::/32', true],
            ['2001:db9::1', '2001:db8::/32', false],
            // Mismatched families never match.
            ['10.0.0.1', '::1/128', false],
            ['::1', '10.0.0.0/8', false],
            // Malformed range -> no match.
            ['10.0.0.1', '10.0.0.0/notaninteger', false],
        ];
    }

    /**
     * @dataProvider cidrCases
     */
    public function testIpMatches(string $ip, string $range, bool $expected): void
    {
        self::assertSame($expected, TrustedProxyResolver::ipMatches($ip, $range));
    }

    public function testConfiguredProxiesDefaultsToLoopback(): void
    {
        $saved = getenv('TRUSTED_PROXIES');
        putenv('TRUSTED_PROXIES');

        try {
            self::assertSame(
                TrustedProxyResolver::DEFAULT_TRUSTED_PROXIES,
                TrustedProxyResolver::configuredProxies(),
            );
        } finally {
            if ($saved !== false) {
                putenv('TRUSTED_PROXIES=' . $saved);
            }
        }
    }

    public function testConfiguredProxiesParsesEnv(): void
    {
        $saved = getenv('TRUSTED_PROXIES');
        putenv('TRUSTED_PROXIES= 10.0.0.0/8 , 192.168.0.0/16 ,');

        try {
            self::assertSame(
                ['10.0.0.0/8', '192.168.0.0/16'],
                TrustedProxyResolver::configuredProxies(),
            );
        } finally {
            if ($saved === false) {
                putenv('TRUSTED_PROXIES');
            } else {
                putenv('TRUSTED_PROXIES=' . $saved);
            }
        }
    }
}
