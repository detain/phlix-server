<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Server\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Phlex\Hub\HubJwtValidatorInterface;
use Phlex\Hub\HubUserClaims;
use Phlex\Server\Http\Middleware\HubJwtMiddleware;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;

class HubJwtMiddlewareTest extends TestCase
{
    public function testValidHubJwtSetsHubUser(): void
    {
        $claims = new HubUserClaims(
            userId: 'hub-user-123',
            serverId: 'server-456',
            subject: 'hub-user-123',
            issuer: 'phlex-hub',
            expiresAt: time() + 3600,
            scope: ['media:read'],
        );

        $validator = $this->createMock(HubJwtValidatorInterface::class);
        $validator->method('validate')->willReturn($claims);

        $middleware = new HubJwtMiddleware($validator);
        $request = $this->createRequestWithBearer('valid-hub-token');

        $response = $middleware($request);

        $this->assertNull($response);
        $this->assertSame($claims, $request->hubUser);
    }

    public function testMissingTokenReturnsNull(): void
    {
        $validator = $this->createMock(HubJwtValidatorInterface::class);
        $validator->expects($this->never())->method('validate');

        $middleware = new HubJwtMiddleware($validator);
        $request = $this->createRequestWithBearer(null);

        $response = $middleware($request);

        $this->assertNull($response);
        $this->assertNull($request->hubUser);
    }

    public function testExpiredTokenReturns401(): void
    {
        $validator = $this->createMock(HubJwtValidatorInterface::class);
        $validator->method('validate')->willReturn(null);

        $middleware = new HubJwtMiddleware($validator);
        $request = $this->createRequestWithBearer('expired-token');

        $response = $middleware($request);

        $this->assertNotNull($response);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(401, $response->statusCode);
        $this->assertNull($request->hubUser);
    }

    public function testNullValidatorPassesThrough(): void
    {
        $middleware = new HubJwtMiddleware(null);
        $request = $this->createRequestWithBearer('any-token');

        $response = $middleware($request);

        $this->assertNull($response);
    }

    private function createRequestWithBearer(?string $token): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/hub-token';
        $request->headers = [];
        $request->query = [];
        $request->body = [];
        $request->files = [];
        $request->remoteIp = '127.0.0.1';
        $request->remotePort = 12345;
        $request->protocol = 'HTTP/1.1';
        $request->pathParams = [];

        if ($token !== null) {
            $request->bearerToken = $token;
            $request->headers['Authorization'] = 'Bearer ' . $token;
        } else {
            $request->bearerToken = null;
        }

        return $request;
    }
}
