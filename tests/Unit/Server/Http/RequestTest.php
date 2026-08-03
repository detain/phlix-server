<?php

namespace Phlix\Tests\Unit\Server\Http;

use PHPUnit\Framework\TestCase;
use Phlix\Server\Http\Request;

/**
 * Unit tests for Request class.
 */
class RequestTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalServer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        parent::tearDown();
    }

    public function testCanGetBearerToken(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-token-123';

        $request = Request::fromGlobals();

        $this->assertEquals('test-token-123', $request->getBearerToken());
    }

    public function testGetHeaderReturnsNullWhenNotPresent(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        unset($_SERVER['HTTP_X_CUSTOM_HEADER']);

        $request = Request::fromGlobals();

        $this->assertNull($request->getHeader('X-Custom-Header'));
    }

    public function testIsMethods(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/';

        $request = Request::fromGlobals();

        $this->assertFalse($request->isGet());
        $this->assertTrue($request->isPost());
        $this->assertFalse($request->isPut());
        $this->assertFalse($request->isDelete());
    }

    public function testGetClientIpWithForwardedHeader(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.1.1, 10.0.0.1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $request = Request::fromGlobals();

        // getClientIp() is the RAW, untrusted leftmost XFF entry (kept for
        // non-security display/logging only).
        $this->assertEquals('192.168.1.1', $request->getClientIp());
    }

    /**
     * SV-4.15 HIGH: getTrustedClientIp() is trusted-proxy-aware. Behind the
     * loopback proxy the rightmost (appended) XFF entry is the real client; the
     * forged leftmost value is ignored.
     */
    public function testGetTrustedClientIpReturnsRealClientNotForgedLeftmost(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.1.1, 203.0.113.9';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $request = Request::fromGlobals();

        // Rightmost/appended entry (the address nginx observed) — NOT the forged
        // leftmost 192.168.1.1.
        $this->assertEquals('203.0.113.9', $request->getTrustedClientIp());
    }

    /**
     * A direct (non-loopback) peer must ignore a client-supplied X-Forwarded-For.
     */
    public function testGetTrustedClientIpIgnoresXffFromUntrustedPeer(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.23';

        $request = Request::fromGlobals();

        $this->assertEquals('198.51.100.23', $request->getTrustedClientIp());
    }
}
