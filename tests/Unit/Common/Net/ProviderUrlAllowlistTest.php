<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Net;

use Phlix\Common\Net\ProviderUrlAllowlist;
use Phlix\Common\Net\SsrfGuard;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the S73 two-layer SSRF gate ({@see ProviderUrlAllowlist}).
 *
 * The load-bearing property is ORDER: layer one (host allowlist) must refuse
 * an unallowlisted host BEFORE layer two (DNS) is consulted — a hostile host
 * must never be resolved at all, or the gate itself becomes a DNS oracle for
 * attacker-chosen names. The counting resolver is the witness.
 */
final class ProviderUrlAllowlistTest extends TestCase
{
    /** @var int Number of DNS lookups the injected resolver has performed. */
    private int $resolverCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolverCalls = 0;
    }

    protected function tearDown(): void
    {
        SsrfGuard::reset();
        parent::tearDown();
    }

    /**
     * Install a fake resolver returning $ip that counts every invocation.
     *
     * @param list<string> $ip
     */
    private function watchResolver(array $ip = ['151.101.4.137']): void
    {
        SsrfGuard::setResolver(function (string $host) use ($ip): array {
            $this->resolverCalls++;
            return $ip;
        });
    }

    // -----------------------------------------------------------------
    // Layer one: the host allowlist
    // -----------------------------------------------------------------

    public function testAllowlistedTmdbUrlPassesBothLayers(): void
    {
        $this->watchResolver();

        ProviderUrlAllowlist::assertFetchable('https://image.tmdb.org/t/p/w500/abc123.jpg');

        self::assertSame(1, $this->resolverCalls, 'layer two must run for an allowlisted URL');
    }

    public function testUnallowlistedHostIsRefusedBeforeAnyDnsLookup(): void
    {
        $this->watchResolver();

        try {
            ProviderUrlAllowlist::assertFetchable('https://evil.example.org/t/p/w500/x.jpg');
            self::fail('an unallowlisted host must be refused');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('allowlisted provider image URL', $refusal->getMessage());
        }

        self::assertSame(
            0,
            $this->resolverCalls,
            'layer one must refuse BEFORE layer two resolves the hostile hostname',
        );
    }

    public function testPlainHttpIsRefusedEvenOnTheCdnHost(): void
    {
        $this->watchResolver();

        $this->expectException(\InvalidArgumentException::class);
        ProviderUrlAllowlist::assertFetchable('http://image.tmdb.org/t/p/w500/x.jpg');
    }

    public function testSubdomainSuffixSpoofIsRefused(): void
    {
        $this->watchResolver();

        try {
            ProviderUrlAllowlist::assertFetchable('https://image.tmdb.org.evil.test/t/p/w500/x.jpg');
            self::fail('a suffix-spoofed host must be refused');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('allowlisted', $refusal->getMessage());
        }

        self::assertSame(0, $this->resolverCalls);
    }

    public function testUserinfoSpoofIsRefusedByTheAnchoredPattern(): void
    {
        $this->watchResolver();

        $this->expectException(\InvalidArgumentException::class);
        ProviderUrlAllowlist::assertFetchable('https://image.tmdb.org@evil.test/t/p/w500/x.jpg');
    }

    public function testForeignPathShapeOnTheCdnHostIsRefused(): void
    {
        $this->watchResolver();

        $this->expectException(\InvalidArgumentException::class);
        ProviderUrlAllowlist::assertFetchable('https://image.tmdb.org/redirect?url=https://evil.test/');
    }

    // -----------------------------------------------------------------
    // Layer two: the resolved address
    // -----------------------------------------------------------------

    public function testAllowlistedHostResolvingToMetadataIpIsRefusedByLayerTwo(): void
    {
        $this->watchResolver(['169.254.169.254']);

        try {
            ProviderUrlAllowlist::assertFetchable('https://image.tmdb.org/t/p/w500/x.jpg');
            self::fail('a poisoned resolver pointing the CDN at the metadata IP must be refused');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringNotContainsString('allowlisted', $refusal->getMessage());
        }

        self::assertSame(1, $this->resolverCalls, 'layer two must have been the one to refuse');
    }

    // -----------------------------------------------------------------
    // Redirect resolution (the input layer one re-checks per hop)
    // -----------------------------------------------------------------

    public function testAbsoluteHttpRedirectIsNormalisedWithoutUserinfo(): void
    {
        self::assertSame(
            'http://169.254.169.254/latest/meta-data/',
            ProviderUrlAllowlist::resolveRedirect(
                'https://image.tmdb.org/t/p/original/a.jpg',
                'http://internal@169.254.169.254/latest/meta-data/',
            ),
            'userinfo must be stripped so the rebuilt URL cannot spoof the allowlisted host',
        );
    }

    public function testSchemeRelativeRedirectTakesTheBaseScheme(): void
    {
        self::assertSame(
            'https://evil.test/x.jpg',
            ProviderUrlAllowlist::resolveRedirect('https://image.tmdb.org/t/p/original/a.jpg', '//evil.test/x.jpg'),
        );
    }

    public function testRootRelativeRedirectStaysOnTheBaseOrigin(): void
    {
        self::assertSame(
            'https://image.tmdb.org/t/p/w780/b.jpg',
            ProviderUrlAllowlist::resolveRedirect('https://image.tmdb.org/t/p/original/a.jpg', '/t/p/w780/b.jpg'),
        );
    }

    public function testRelativeRedirectResolvesAgainstTheBaseDirectoryWithDotSegmentsRemoved(): void
    {
        self::assertSame(
            'https://image.tmdb.org/t/p/w342/c.jpg',
            ProviderUrlAllowlist::resolveRedirect(
                'https://image.tmdb.org/t/p/original/../original/a.jpg',
                '../w342/c.jpg',
            ),
        );
    }

    public function testNonHttpSchemeRedirectIsNeverFollowed(): void
    {
        self::assertNull(
            ProviderUrlAllowlist::resolveRedirect('https://image.tmdb.org/t/p/original/a.jpg', 'file:///etc/passwd'),
            'a file:// hop must STOP the loop, not normalise into a fetchable URL',
        );
        self::assertNull(
            ProviderUrlAllowlist::resolveRedirect('https://image.tmdb.org/t/p/original/a.jpg', 'gopher://10.0.0.9:7/'),
        );
        self::assertNull(
            ProviderUrlAllowlist::resolveRedirect('https://image.tmdb.org/t/p/original/a.jpg', ''),
        );
    }
}
