<?php

/**
 * LanEndpointGuard unit tests — proves the boundary laws that every device-integration
 * SSRF fix leans on, with zero sockets. A refusal here is the pre-socket guarantee.
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Net;

use Phlix\Common\Net\LanEndpointGuard;
use PHPUnit\Framework\TestCase;

final class LanEndpointGuardTest extends TestCase
{
    public function testNormalizeAcceptsBareHostPortAndAbsolute(): void
    {
        $this->assertSame(
            'http://192.168.1.50:8200/desc.xml',
            LanEndpointGuard::normalizeHttpUrl('192.168.1.50:8200/desc.xml')
        );
        $this->assertSame(
            'http://192.168.1.50:8200/desc.xml',
            LanEndpointGuard::normalizeHttpUrl('  http://192.168.1.50:8200/desc.xml  ')
        );
    }

    public function testNormalizeRejectsDangerousShapes(): void
    {
        $this->assertNull(LanEndpointGuard::normalizeHttpUrl(''));
        $this->assertNull(LanEndpointGuard::normalizeHttpUrl("http://10.0.0.1/\0evil"));
        $this->assertNull(LanEndpointGuard::normalizeHttpUrl('file:///etc/passwd'));
        $this->assertNull(LanEndpointGuard::normalizeHttpUrl('gopher://10.0.0.1:11211/_'));
        $this->assertNull(LanEndpointGuard::normalizeHttpUrl('http:///nohost'));
        $this->assertNull(LanEndpointGuard::normalizeHttpUrl('http://user:pw@10.0.0.1/x'));
    }

    public function testHostOfStripsIpv6Brackets(): void
    {
        $this->assertSame('fe80::1', LanEndpointGuard::hostOf('http://[fe80::1]:80/x'));
        $this->assertSame('192.168.1.5', LanEndpointGuard::hostOf('http://192.168.1.5/x'));
        $this->assertNull(LanEndpointGuard::hostOf('not-a-url'));
    }

    public function testLiteralIpEqualsSource(): void
    {
        $this->assertTrue(LanEndpointGuard::literalIpEqualsSource('192.168.1.50', '192.168.1.50'));
        $this->assertTrue(LanEndpointGuard::literalIpEqualsSource('::ffff:192.168.1.50', '192.168.1.50'));
        $this->assertFalse(LanEndpointGuard::literalIpEqualsSource('192.168.1.51', '192.168.1.50'));
        // Hostnames never pin — pinning must not resolve.
        $this->assertFalse(LanEndpointGuard::literalIpEqualsSource('evil.example.com', '192.168.1.50'));
    }

    public function testIsLanAddress(): void
    {
        $this->assertTrue(LanEndpointGuard::isLanAddress('192.168.1.100'));
        $this->assertTrue(LanEndpointGuard::isLanAddress('10.0.0.9'));
        $this->assertTrue(LanEndpointGuard::isLanAddress('172.20.5.6'));
        $this->assertFalse(LanEndpointGuard::isLanAddress('169.254.169.254'));
        $this->assertFalse(LanEndpointGuard::isLanAddress('8.8.8.8'));
        $this->assertFalse(LanEndpointGuard::isLanAddress('renderer.local'));
    }

    public function testIsRefusedAddressBlocksMetadataAndLoopback(): void
    {
        $this->assertTrue(LanEndpointGuard::isRefusedAddress('169.254.169.254'));
        $this->assertTrue(LanEndpointGuard::isRefusedAddress('127.0.0.1'));
        $this->assertTrue(LanEndpointGuard::isRefusedAddress('0.0.0.0'));
        $this->assertTrue(LanEndpointGuard::isRefusedAddress('100.64.0.1'));
        $this->assertTrue(LanEndpointGuard::isRefusedAddress('::1'));
        $this->assertTrue(LanEndpointGuard::isRefusedAddress('::ffff:127.0.0.1'));
        $this->assertFalse(LanEndpointGuard::isRefusedAddress('192.168.1.100'));
    }

    public function testSameHostAbsolute(): void
    {
        $this->assertSame(
            'http://192.168.1.5:8200/ctrl/A',
            LanEndpointGuard::sameHostAbsolute('http://192.168.1.5:8200/d.xml', '/ctrl/A')
        );
        $this->assertSame(
            'http://192.168.1.5:8200/ctrl',
            LanEndpointGuard::sameHostAbsolute('http://192.168.1.5:8200/d.xml', 'http://192.168.1.5:8200/ctrl')
        );
        // Cross-host absolute is refused.
        $this->assertNull(
            LanEndpointGuard::sameHostAbsolute('http://192.168.1.5:8200/d.xml', 'http://10.0.0.9/ctrl')
        );
        // Metadata host refused.
        $this->assertNull(
            LanEndpointGuard::sameHostAbsolute('http://192.168.1.5/d.xml', 'http://169.254.169.254/latest')
        );
    }

    public function testOriginOf(): void
    {
        $this->assertSame('http://192.168.1.5:8200', LanEndpointGuard::originOf('http://192.168.1.5:8200/d.xml'));
        $this->assertSame('https://10.0.0.1', LanEndpointGuard::originOf('https://10.0.0.1/d.xml'));
        $this->assertNull(LanEndpointGuard::originOf('file:///x'));
    }
}
