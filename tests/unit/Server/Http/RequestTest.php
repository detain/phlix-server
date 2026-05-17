<?php

namespace Phlex\Tests\Unit\Server\Http;

use PHPUnit\Framework\TestCase;
use Phlex\Server\Http\Request;

/**
 * Unit tests for Request class.
 *
 * @covers \Phlex\Server\Http\Request
 */
class RequestTest extends TestCase
{
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

    /**
     * @covers \Phlex\Server\Http\Request::getBearerToken
     */
    public function testCanGetBearerToken(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-token-123';

        $request = Request::fromGlobals();

        $this->assertEquals('test-token-123', $request->getBearerToken());
    }

    /**
     * @covers \Phlex\Server\Http\Request::getHeader
     */
    public function testGetHeaderReturnsNullWhenNotPresent(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        unset($_SERVER['HTTP_X_CUSTOM_HEADER']);

        $request = Request::fromGlobals();

        $this->assertNull($request->getHeader('X-Custom-Header'));
    }

    /**
     * @covers \Phlex\Server\Http\Request::isGet
     * @covers \Phlex\Server\Http\Request::isPost
     * @covers \Phlex\Server\Http\Request::isPut
     * @covers \Phlex\Server\Http\Request::isDelete
     */
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

    /**
     * @covers \Phlex\Server\Http\Request::getClientIp
     */
    public function testGetClientIpWithForwardedHeader(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.1.1, 10.0.0.1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $request = Request::fromGlobals();

        $this->assertEquals('192.168.1.1', $request->getClientIp());
    }
}