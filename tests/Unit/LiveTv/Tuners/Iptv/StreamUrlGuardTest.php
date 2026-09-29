<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Tuners\Iptv;

use Phlix\Common\Net\SsrfGuard;
use Phlix\LiveTv\Tuners\Iptv\StreamUrlGuard;
use PHPUnit\Framework\TestCase;

/**
 * F-1 regression suite: IPTV stream/playlist URL jail.
 *
 * Pins the two-law policy: (1) http(s) scheme only, (2) loopback/link-local/
 * unspecified LITERAL hosts denied (with IPv4-in-IPv6 smuggling collapsed),
 * while LAN-reachable IPTV targets stay tunable by default; strict mode adds
 * full SsrfGuard (resolved-address) treatment.
 *
 * @since 2.3.0
 */
final class StreamUrlGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        SsrfGuard::reset();
        parent::tearDown();
    }

    public static function deniedSchemeProvider(): array
    {
        return [
            'file exfil' => ['file:///etc/passwd'],
            'gopher' => ['gopher://127.0.0.1:70/g'],
            'ftp' => ['ftp://example.com/loop.ts'],
            'udp multicast' => ['udp://@239.0.0.1:1234'],
            'pipe' => ['pipe://ffmpeg'],
            'bare path (no scheme)' => ['/etc/passwd'],
        ];
    }

    /**
     * @dataProvider deniedSchemeProvider
     */
    public function testNonHttpSchemeIsRefused(string $url): void
    {
        $this->assertFalse(StreamUrlGuard::isTunable($url));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not allowed');

        StreamUrlGuard::assertTunable($url);
    }

    public static function deniedLiteralHostProvider(): array
    {
        return [
            'ipv4 loopback' => ['http://127.0.0.1/live.m3u8'],
            'ipv4 loopback non-1' => ['http://127.53.53.53/live.m3u8'],
            'metadata ip' => ['http://169.254.169.254/latest/meta-data/iam/'],
            'link-local range' => ['http://169.254.1.1/x'],
            'this-network' => ['http://0.0.0.0:8080/x'],
            'ipv6 loopback' => ['http://[::1]:8080/x'],
            'ipv6 unspecified' => ['http://[::]:8080/x'],
            'ipv6 link-local' => ['http://[fe80::1]:8080/x'],
            'mapped ipv4 loopback' => ['http://[::ffff:127.0.0.1]:8080/x'],
            'mapped metadata' => ['http://[::ffff:169.254.169.254]/x'],
        ];
    }

    /**
     * @dataProvider deniedLiteralHostProvider
     */
    public function testDangerousLiteralHostIsRefused(string $url): void
    {
        $this->assertFalse(StreamUrlGuard::isTunable($url));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('loopback/link-local/unspecified');

        StreamUrlGuard::assertTunable($url);
    }

    public static function allowedDefaultProvider(): array
    {
        return [
            'public host' => ['http://iptv.example.com/live.m3u8'],
            'https public' => ['https://cdn.example.net:8443/stream'],
            'rfc1918 literal (operator LAN IPTV)' => ['http://192.168.1.50:8000/live/1.ts'],
            'rfc1918 10/8' => ['http://10.20.30.40/playlist'],
            'carrier-grade nat' => ['http://100.64.1.2/x.m3u8'],
            'tailnet hostname' => ['https://iptv.tailnet-xyz.ts.net/live'],
        ];
    }

    /**
     * @dataProvider allowedDefaultProvider
     */
    public function testLanAndPublicTargetsTuneInDefaultMode(string $url): void
    {
        $this->assertTrue(StreamUrlGuard::isTunable($url));
        $this->assertNull(StreamUrlGuard::refusalReason($url));
        StreamUrlGuard::assertTunable($url);
    }

    public function testHostlessUrlIsRefused(): void
    {
        // "http:///stream.ts" parses as scheme-less garbage in PHP, so it is
        // refused at the scheme gate; either way it can never be tuned.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stream URL rejected');

        StreamUrlGuard::assertTunable('http:///stream.ts');
    }

    public function testStrictModeAddsResolvedAddressDenylist(): void
    {
        // DNS rebinding shape: a PUBLIC hostname resolving to the metadata IP.
        // Default mode cannot see this (no resolution); strict mode must refuse.
        SsrfGuard::setResolver(static fn (string $host): array => $host === 'rebind.example'
            ? ['169.254.169.254']
            : ['93.184.216.34']);

        $this->assertTrue(StreamUrlGuard::isTunable('http://rebind.example/x.m3u8'));
        $this->assertFalse(StreamUrlGuard::isTunable('http://rebind.example/x.m3u8', strict: true));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('strict policy');

        StreamUrlGuard::assertTunable('http://rebind.example/x.m3u8', strict: true);
    }

    public function testStrictModeStillAllowsGenuinelyPublicHosts(): void
    {
        SsrfGuard::setResolver(static fn (string $host): array => ['93.184.216.34']);

        $this->assertTrue(StreamUrlGuard::isTunable('https://iptv.example.com/live', strict: true));
    }

    public function testStrictModeRefusesRfc1918ResolvedHosts(): void
    {
        // The documented consequence of strict mode: LAN names now refuse too.
        SsrfGuard::setResolver(static fn (string $host): array => ['192.168.1.50']);

        $this->assertFalse(StreamUrlGuard::isTunable('http://nas.local/live.ts', strict: true));
    }
}
