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

    // -----------------------------------------------------------------
    // Layer two under a live coroutine (the S73 CI-probe hang)
    //
    // swoole 6.2.2 routes the default resolver's dns_get_record() through its
    // RemoteObject subsystem — each call SPAWNS a detached php subprocess that
    // inherits the supervisor's stdout pipe (measured: 131 servers, one hung
    // assertion-escape audit). Inside a coroutine the gate must therefore
    // resolve via swoole's native coroutine resolver instead — while NEVER
    // replacing an explicitly injected one (that is every unit test's seam),
    // and restoring the default afterwards so the swap cannot leak.
    // -----------------------------------------------------------------

    private function requireSwoole(): void
    {
        if (!\extension_loaded('swoole') || !class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('ext-swoole required for the coroutine resolver arm');
        }
    }

    public function testUsesDefaultResolverReflectsTheInjectionSeam(): void
    {
        self::assertTrue(SsrfGuard::usesDefaultResolver(), 'no resolver injected yet');

        $this->watchResolver();
        self::assertFalse(SsrfGuard::usesDefaultResolver(), 'an injected resolver is observable');

        SsrfGuard::setResolver(null);
        self::assertTrue(SsrfGuard::usesDefaultResolver(), 'reset returns to the default');
    }

    public function testExplicitlyInjectedResolverIsNeverReplacedInsideACoroutine(): void
    {
        $this->requireSwoole();
        $this->watchResolver();

        \Swoole\Coroutine\run(function (): void {
            ProviderUrlAllowlist::assertFetchable('https://image.tmdb.org/t/p/w500/abc.jpg');
        });

        self::assertSame(
            1,
            $this->resolverCalls,
            'a test/boot-injected resolver must be honoured verbatim even inside a coroutine',
        );
    }

    public function testLayerOneStillRefusesBeforeResolutionInsideACoroutine(): void
    {
        $this->requireSwoole();
        $this->watchResolver();

        $refused = null;
        \Swoole\Coroutine\run(function () use (&$refused): void {
            try {
                ProviderUrlAllowlist::assertFetchable('https://evil.example.org/t/p/w500/x.jpg');
            } catch (\InvalidArgumentException $e) {
                $refused = $e;
            }
        });

        self::assertInstanceOf(\InvalidArgumentException::class, $refused);
        self::assertSame(0, $this->resolverCalls, 'the coroutine arm must not weaken layer one');
    }

    public function testDefaultResolverInsideACoroutineUsesTheNativeArmAndRestores(): void
    {
        $this->requireSwoole();
        if (gethostbyname('image.tmdb.org') === 'image.tmdb.org') {
            self::markTestSkipped('no outbound DNS on this box for the provider host');
        }

        // Default resolver in effect: inside a coroutine the gate swaps to
        // swoole's native resolver (no dns_get_record, no spawned servers).
        \Swoole\Coroutine\run(function (): void {
            ProviderUrlAllowlist::assertFetchable('https://image.tmdb.org/t/p/w185/z.jpg');
        });

        self::assertTrue(
            SsrfGuard::usesDefaultResolver(),
            'the coroutine resolver swap must be released after assertFetchable returns',
        );

        // And the default arm is genuinely restored: a fresh injected spy is
        // used again outside the coroutine.
        $this->watchResolver();
        ProviderUrlAllowlist::assertFetchable('https://image.tmdb.org/t/p/w185/z.jpg');
        self::assertSame(1, $this->resolverCalls);
    }
}
