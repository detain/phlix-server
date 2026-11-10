<?php

/**
 * Phlix media server component: Auth tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\CookiePolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit legs for {@see CookiePolicy} — the primitive decisions behind CP-003
 * (COOKIE-DESIGN §2.1/§2.4). The end-to-end custody matrix lives in
 * CookieDualEmitTest; this file pins the header-level rules directly so a
 * regression names its own limb.
 */
final class CookiePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('PHLIX_COOKIE_INSECURE');
    }

    protected function tearDown(): void
    {
        putenv('PHLIX_COOKIE_INSECURE');
        parent::tearDown();
    }

    public function testDeviceMarkerForcesBearerEvenWithSameOriginBrowserSignals(): void
    {
        $policy = new CookiePolicy();

        $this->assertSame(CookiePolicy::MODE_BEARER, $policy->custodyMode(
            true,
            'mobile',
            'http://localhost:8096',
            'localhost:8096',
            'same-origin',
        ));
    }

    public function testForeignOriginForcesBearerAndWebviewSchemesToo(): void
    {
        $policy = new CookiePolicy();

        $this->assertSame(
            CookiePolicy::MODE_BEARER,
            $policy->custodyMode(
                false,
                null,
                'https://evil.example',
                'box.example',
                null,
            ),
        );
        $this->assertSame(
            CookiePolicy::MODE_BEARER,
            $policy->custodyMode(
                true,
                null,
                'capacitor://localhost',
                'localhost',
                null,
            ),
        );
        $this->assertSame(
            CookiePolicy::MODE_BEARER,
            $policy->custodyMode(
                true,
                null,
                'app://-',
                'localhost',
                null,
            ),
        );
    }

    public function testSignallessRequestStaysBearerLegacyPosture(): void
    {
        $policy = new CookiePolicy();

        $this->assertSame(CookiePolicy::MODE_BEARER, $policy->custodyMode(false, null, null, null, null));
    }

    public function testSameOriginOriginAndSecFetchMarkBrowserClass(): void
    {
        $policy = new CookiePolicy();

        $this->assertSame(
            CookiePolicy::MODE_COOKIE,
            $policy->custodyMode(
                false,
                null,
                'http://box.example:8096',
                'box.example:8096',
                null,
            ),
        );
        $this->assertSame(
            CookiePolicy::MODE_COOKIE,
            $policy->custodyMode(
                false,
                null,
                null,
                'box.example',
                'same-origin',
            ),
        );
        $this->assertSame(
            CookiePolicy::MODE_COOKIE,
            $policy->custodyMode(
                true,
                null,
                null,
                'box.example',
                null,
            ),
        );
    }

    public function testSecFetchCrossSiteWithoutBrowserSignalsStaysBearer(): void
    {
        $policy = new CookiePolicy();

        $this->assertSame(
            CookiePolicy::MODE_BEARER,
            $policy->custodyMode(
                false,
                null,
                null,
                'box.example',
                'cross-site',
            ),
        );
    }

    public function testHttpsForwardedReadsOnlyTheFirstHop(): void
    {
        $this->assertTrue(CookiePolicy::isHttpsForwarded('https, http'));
        $this->assertTrue(CookiePolicy::isHttpsForwarded('HTTPS'));
        $this->assertFalse(CookiePolicy::isHttpsForwarded('http, https'));
        $this->assertFalse(CookiePolicy::isHttpsForwarded(null));
        $this->assertFalse(CookiePolicy::isHttpsForwarded('gopher'));
    }

    public function testLocalhostHostMatchesAnyPortAndCase(): void
    {
        $this->assertTrue(CookiePolicy::isLocalhostHost('localhost:8096'));
        $this->assertTrue(CookiePolicy::isLocalhostHost('LOCALHOST'));
        $this->assertTrue(CookiePolicy::isLocalhostHost('127.0.0.1:80'));
        $this->assertTrue(CookiePolicy::isLocalhostHost('[::1]:8096'));
        $this->assertFalse(CookiePolicy::isLocalhostHost('box.lan:8096'));
        $this->assertFalse(CookiePolicy::isLocalhostHost(null));
    }

    public function testOriginSameSiteNormalizesDefaultPorts(): void
    {
        $this->assertTrue(CookiePolicy::originIsSameSite('https://box.example', 'box.example'));
        $this->assertTrue(CookiePolicy::originIsSameSite('https://box.example:443', 'box.example'));
        $this->assertTrue(CookiePolicy::originIsSameSite('http://box.example', 'box.example:80'));
        $this->assertFalse(CookiePolicy::originIsSameSite('https://box.example', 'box.example:8443'));
        $this->assertFalse(CookiePolicy::originIsSameSite('file://', 'box.example'));
        $this->assertFalse(CookiePolicy::originIsSameSite('not a url', 'box.example'));
    }

    public function testSchemeLawAcceptsHttpsLocalhostOrOverrideOnly(): void
    {
        $policy = new CookiePolicy();

        $this->assertTrue($policy->schemeEligible('https', 'box.example'));
        $this->assertTrue($policy->schemeEligible(null, 'localhost:8096'));
        $this->assertFalse($policy->schemeEligible('http', 'box.lan:8096'));

        putenv('PHLIX_COOKIE_INSECURE=1');
        $this->assertTrue($policy->schemeEligible('http', 'box.lan:8096'));
        $this->assertFalse($policy->cookiesSecure());
    }

    public function testStoredInsecureSettingOptsOutOfSecureAttribute(): void
    {
        $policy = new CookiePolicy($this->store([
            CookiePolicy::COOKIE_INSECURE_KEY => true,
        ]));

        $this->assertTrue($policy->insecureOverride());
        $this->assertFalse($policy->cookiesSecure());
        $this->assertTrue($policy->schemeEligible('http', 'box.lan:8096'));
    }

    public function testMasterSwitchFalseSuppressesAndAnythingElseEnables(): void
    {
        $this->assertFalse((new CookiePolicy($this->store([
            CookiePolicy::COOKIE_ENABLED_KEY => false,
        ])))->cookieModeEnabled());
        $this->assertTrue((new CookiePolicy($this->store([
            CookiePolicy::COOKIE_ENABLED_KEY => null,
        ])))->cookieModeEnabled());
        $this->assertTrue((new CookiePolicy())->cookieModeEnabled());
    }

    public function testThrowingStoreDegradesToShippedPosture(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willThrowException(new RuntimeException('db down'));

        $policy = new CookiePolicy($settings);

        $this->assertTrue($policy->cookieModeEnabled());
        $this->assertFalse($policy->insecureOverride());
        $this->assertTrue($policy->cookiesSecure());
    }

    /** @param array<string, mixed> $values */
    private function store(array $values): SettingsRepository
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturnCallback(
            static fn (string $key): mixed => $values[$key] ?? null
        );

        return $settings;
    }
}
