<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Net;

use InvalidArgumentException;
use Phlix\Common\Net\SsrfGuard;
use PHPUnit\Framework\TestCase;

/**
 * Covers the shared SSRF guard: scheme enforcement, each blocked address range,
 * a passing public host, and the CIDR-allowlist override. The DNS-resolution
 * seam is injected so the suite stays deterministic and offline.
 */
final class SsrfGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        SsrfGuard::reset();
        parent::tearDown();
    }

    /**
     * Stub the resolver so a hostname maps to a fixed set of addresses.
     *
     * @param array<string, list<string>> $map host => IPs
     */
    private function stubResolver(array $map): void
    {
        SsrfGuard::setResolver(static function (string $host) use ($map): array {
            return $map[$host] ?? [];
        });
    }

    public function test_rejects_non_http_scheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertPublicUrl('ftp://example.com/x');
    }

    public function test_rejects_file_scheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertPublicUrl('file:///etc/passwd');
    }

    public function test_rejects_empty_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertPublicUrl('   ');
    }

    public function test_rejects_url_without_host(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertPublicUrl('http:///nohost');
    }

    public function test_rejects_unresolvable_host(): void
    {
        $this->stubResolver(['nope.invalid' => []]);
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertPublicUrl('http://nope.invalid/');
    }

    public function test_accepts_public_host(): void
    {
        $this->stubResolver(['example.com' => ['93.184.216.34']]);
        SsrfGuard::assertPublicUrl('https://example.com/path');
        $this->addToAssertionCount(1);
    }

    public function test_accepts_public_ipv4_literal(): void
    {
        SsrfGuard::assertPublicUrl('http://8.8.8.8/resolve');
        $this->addToAssertionCount(1);
    }

    public function test_accepts_public_ipv6_literal(): void
    {
        SsrfGuard::assertPublicUrl('https://[2001:4860:4860::8888]/');
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blockedLiteralProvider(): array
    {
        return [
            'loopback 127.0.0.1' => ['http://127.0.0.1/'],
            'loopback 127.5.5.5' => ['http://127.5.5.5/'],
            'ipv6 loopback ::1' => ['http://[::1]/'],
            'cloud metadata 169.254.169.254' => ['http://169.254.169.254/latest/meta-data/'],
            'link-local 169.254.0.5' => ['http://169.254.0.5/'],
            'rfc1918 10/8' => ['http://10.1.2.3/'],
            'rfc1918 172.16/12' => ['http://172.16.5.5/'],
            'rfc1918 192.168/16' => ['http://192.168.1.1/'],
            'cgnat 100.64/10' => ['http://100.64.0.1/'],
            'unspecified 0.0.0.0' => ['http://0.0.0.0/'],
            'ipv6 ula fc00::/7' => ['http://[fc00::1]/'],
            'ipv6 link-local fe80::/10' => ['http://[fe80::1]/'],
        ];
    }

    /**
     * @dataProvider blockedLiteralProvider
     */
    public function test_rejects_blocked_ip_literal(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertPublicUrl($url);
    }

    /**
     * IPv4-mapped / IPv4-compatible IPv6 literals that embed a denied IPv4
     * address must be rejected. Without the embedded-IPv4 collapse these all
     * evaluate as "public" IPv6 (PHP's NO_PRIV_RANGE|NO_RES_RANGE does not
     * flag mapped addresses, and a 16-byte address never matches a 4-byte v4
     * deny CIDR in the binary prefix compare).
     *
     * @return array<string, array{string}>
     */
    public static function ipv4MappedLiteralProvider(): array
    {
        return [
            'mapped loopback ::ffff:127.0.0.1' => ['http://[::ffff:127.0.0.1]/'],
            'mapped metadata ::ffff:169.254.169.254' => ['http://[::ffff:169.254.169.254]/latest/meta-data/'],
            'mapped rfc1918 ::ffff:10.0.0.1' => ['http://[::ffff:10.0.0.1]/'],
            'mapped rfc1918 ::ffff:192.168.1.1' => ['http://[::ffff:192.168.1.1]/'],
            'mapped rfc1918 ::ffff:172.16.5.5' => ['http://[::ffff:172.16.5.5]/'],
            'mapped cgnat ::ffff:100.64.0.1' => ['http://[::ffff:100.64.0.1]/'],
            'compat loopback ::127.0.0.1' => ['http://[::127.0.0.1]/'],
            'compat metadata ::169.254.169.254' => ['http://[::169.254.169.254]/'],
            'mapped hex metadata ::ffff:a9fe:a9fe' => ['http://[::ffff:a9fe:a9fe]/'],
        ];
    }

    /**
     * @dataProvider ipv4MappedLiteralProvider
     */
    public function test_rejects_ipv4_mapped_ipv6_literal(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertPublicUrl($url);
    }

    public function test_rejects_hostname_resolving_to_mapped_loopback(): void
    {
        $this->stubResolver(['evil.example' => ['::ffff:127.0.0.1']]);
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertPublicUrl('http://evil.example/');
    }

    public function test_rejects_hostname_resolving_to_mapped_metadata(): void
    {
        // AAAA record that smuggles the cloud-metadata endpoint as a mapped v6.
        $this->stubResolver(['rebind.example' => ['::ffff:169.254.169.254']]);
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertPublicUrl('http://rebind.example/');
    }

    public function test_rejects_hostname_resolving_to_mapped_rfc1918(): void
    {
        $this->stubResolver(['lan.example' => ['::ffff:10.0.0.1']]);
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertPublicUrl('http://lan.example/');
    }

    public function test_mapped_public_ipv4_still_allowed(): void
    {
        // ::ffff:8.8.8.8 collapses to a public v4 and must remain permitted.
        SsrfGuard::assertPublicUrl('http://[::ffff:8.8.8.8]/');
        $this->addToAssertionCount(1);
    }

    public function test_rejects_hostname_resolving_to_loopback(): void
    {
        $this->stubResolver(['evil.example' => ['127.0.0.1']]);
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertPublicUrl('http://evil.example/');
    }

    public function test_rejects_hostname_resolving_to_metadata(): void
    {
        $this->stubResolver(['rebind.example' => ['169.254.169.254']]);
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertPublicUrl('http://rebind.example/');
    }

    public function test_rejects_when_any_resolved_address_is_private(): void
    {
        // Mixed public + private: must still reject (the private one is reachable).
        $this->stubResolver(['mixed.example' => ['93.184.216.34', '10.0.0.5']]);
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertPublicUrl('http://mixed.example/');
    }

    public function test_cidr_allowlist_override_via_injection(): void
    {
        SsrfGuard::setAllowedCidrs(['10.10.0.0/24']);
        $this->stubResolver(['broker.internal' => ['10.10.0.5']]);
        SsrfGuard::assertPublicUrl('http://broker.internal:8080/topic');
        $this->addToAssertionCount(1);
    }

    public function test_cidr_allowlist_does_not_widen_to_unlisted_private(): void
    {
        SsrfGuard::setAllowedCidrs(['10.10.0.0/24']);
        $this->stubResolver(['other.internal' => ['10.20.0.5']]);
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertPublicUrl('http://other.internal/');
    }

    public function test_cidr_allowlist_via_env(): void
    {
        putenv('PHLIX_SSRF_ALLOW_CIDRS=192.168.50.0/24,10.0.0.5/32');
        try {
            $this->stubResolver(['lan.example' => ['192.168.50.10']]);
            SsrfGuard::assertPublicUrl('http://lan.example/');
            $this->addToAssertionCount(1);
        } finally {
            putenv('PHLIX_SSRF_ALLOW_CIDRS');
        }
    }

    public function test_ipv6_cidr_allowlist(): void
    {
        SsrfGuard::setAllowedCidrs(['fc00::/7']);
        SsrfGuard::assertPublicUrl('http://[fc00::1234]/');
        $this->addToAssertionCount(1);
    }

    public function test_is_public_url_returns_bool(): void
    {
        $this->stubResolver(['example.com' => ['93.184.216.34']]);
        self::assertTrue(SsrfGuard::isPublicUrl('https://example.com/'));
        self::assertFalse(SsrfGuard::isPublicUrl('http://127.0.0.1/'));
    }
}
