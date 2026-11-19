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

    // ---------------------------------------------------------------------
    // DECODE-ADOPT (wave-5): the JSON body decode in fromWorkerman() moves
    // onto the shared inbound seam. fromGlobals() (:296) carries the SAME
    // textual decode expression and is migrated identically; a php://input
    // harness is unavailable under phpunit CLI (stdin-bound), so the seam
    // behavior is characterized through the Workerman leg.
    // ---------------------------------------------------------------------

    private static function workermanJsonRequest(string $body): \Workerman\Protocols\Http\Request
    {
        return new \Workerman\Protocols\Http\Request(
            "POST /api/v1/anything HTTP/1.1\r\nHost: phlix.test\r\n"
            . "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body,
        );
    }

    /** 600-deep nested array — past the seam's 512 named ceiling. */
    private static function deepBody(): string
    {
        return str_repeat('[', 600) . str_repeat(']', 600);
    }

    public function testFromWorkermanRejectsOverDepthJsonBodyLoudly(): void
    {
        // RED-FIRST FLIP: today the plain decode collapses a >512-deep body onto
        // null and the request proceeds with a SILENTLY EMPTY body (a structural
        // parser failure laundered as 'no parameters'). After the adoption the
        // depth class rethrows JsonException and the HTTP barrier answers the
        // machine 500 envelope + operator log line. Hostile-only; no real client
        // nests 512 levels.
        $this->expectException(\JsonException::class);

        Request::fromWorkerman(self::workermanJsonRequest(self::deepBody()));
    }

    public function testFromWorkermanKeepsEmptyBodyForMalformedJson(): void
    {
        // Characterization (zero wire flip): malformed -> null -> body [] exactly
        // as the historical plain decode answered.
        $request = Request::fromWorkerman(self::workermanJsonRequest('{bad'));

        $this->assertSame('{bad', $request->rawBody);
        $this->assertSame([], $request->body);
    }

    public function testFromWorkermanStillParsesValidJsonBodiesIntoTheBody(): void
    {
        $request = Request::fromWorkerman(self::workermanJsonRequest('{"a":1,"b":[1,2]}'));

        $this->assertSame(['a' => 1, 'b' => [1, 2]], $request->body);
    }
}
