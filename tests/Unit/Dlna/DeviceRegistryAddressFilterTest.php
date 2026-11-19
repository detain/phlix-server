<?php

/**
 * Phlix media server component: tests for the Dlna device registry.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Dlna\DeviceRegistry;
use ReflectionMethod;

/**
 * SRV-3.2 (bug #13) — the hostname-fallback selection rule extracted by
 * DeviceRegistry::discoverLocalAddresses() into `advertisedAddressAllowed()`,
 * pinned directly on the helper. The exec/DNS branches around it are ambient
 * system data and not seam-able; the route-source branch stays deliberately
 * unfiltered (DLNA/SSDP is a LAN-serving protocol — see the helper docblock),
 * so this helper is the site's whole policy surface.
 *
 * ## THE UNION LAW PINNED HERE
 * Raw PHP-filter verdicts relay byte-for-byte by default — including the
 * measured 100.64.0.0/10 leniency documented in LocalAddressProbeTest — and
 * the operator's PHLIX_SSRF_ALLOW_CIDRS override (seam or env) only ever
 * ADDS admissible addresses. Malformed literals are refused no matter the
 * allowlist; the override admits an opted-in IPv6 host through a
 * family-correct /128 match.
 */
class DeviceRegistryAddressFilterTest extends TestCase
{
    protected function tearDown(): void
    {
        SsrfGuard::reset();
        putenv('PHLIX_SSRF_ALLOW_CIDRS');
    }

    private static function allowed(string $address): bool
    {
        return (bool) (new ReflectionMethod(DeviceRegistry::class, 'advertisedAddressAllowed'))
            ->invoke(null, $address);
    }

    /**
     * Default state (no override): today's verdicts, relayed exactly.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function defaultShapes(): array
    {
        return [
            'public dns' => ['8.8.8.8', true],
            'test-net-2' => ['198.51.100.7', true],
            'cgnat-php-filter-leniency' => ['100.64.0.1', true],
            'rfc1918-192-168' => ['192.168.1.5', false],
            'rfc1918-10' => ['10.1.2.3', false],
            'v4-loopback' => ['127.0.0.1', false],
            'link-local-metadata' => ['169.254.169.254', false],
            'v6-zone-id' => ['fe80::1%lo', false],
            'not-an-ip' => ['not-an-ip', false],
            'empty-string' => ['', false],
        ];
    }

    #[DataProvider('defaultShapes')]
    public function test_the_default_verdicts_relay_today_exactly(string $address, bool $expected): void
    {
        $this->assertSame(
            $expected,
            self::allowed($address),
            $address . ' verdict changed under SRV-3.2 — it must not'
        );
    }

    public function test_the_operator_cidr_override_admits_an_opted_in_private_address(): void
    {
        SsrfGuard::setAllowedCidrs(['192.168.1.0/24']);

        $this->assertTrue(self::allowed('192.168.1.5'), 'the opted-in range must be admissible');
        $this->assertFalse(self::allowed('192.168.9.9'), 'only the opted-in range is admissible');
    }

    public function test_the_operator_env_override_is_respected(): void
    {
        putenv('PHLIX_SSRF_ALLOW_CIDRS=10.0.0.0/8');

        $this->assertTrue(self::allowed('10.1.2.3'));
    }

    public function test_the_override_admits_an_opted_in_ipv6_literal(): void
    {
        $this->assertFalse(self::allowed('::1'), 'IPv6 loopback is refused by default');

        SsrfGuard::setAllowedCidrs(['::1/128']);

        $this->assertTrue(self::allowed('::1'), 'the opted-in v6 host must be admissible');
    }

    /**
     * Structural leg: branch 2 of discoverLocalAddresses() must route through
     * the helper — definition plus delegation. A re-inlined filter would
     * silently stop honoring the operator knob here again (bug #13's shape).
     */
    public function test_the_fallback_branch_delegates_to_the_helper(): void
    {
        $source = (string) file_get_contents('src/Dlna/DeviceRegistry.php');

        $this->assertGreaterThanOrEqual(
            2,
            substr_count($source, 'advertisedAddressAllowed('),
            'the hostname fallback must delegate to the single SsrfGuard-backed rule'
        );
    }
}
