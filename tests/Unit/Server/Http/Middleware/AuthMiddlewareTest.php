<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Middleware;

use Phlix\Server\Http\Middleware\AuthMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Server\Http\Middleware\AuthMiddleware
 */
final class AuthMiddlewareTest extends TestCase
{
    public function testReturns401WhenUserIdIsNull(): void
    {
        $response = (new AuthMiddleware())(new Request());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('auth.required', $body['code']);
    }

    public function testReturns401WhenUserIdIsEmptyString(): void
    {
        $request = new Request();
        $request->userId = '';

        $response = (new AuthMiddleware())($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->statusCode);
    }

    public function testPassesThroughWhenUserIdIsPresent(): void
    {
        $request = new Request();
        $request->userId = 'user-123';

        // null = "continue routing" per Router::runMiddleware() semantics.
        $this->assertNull((new AuthMiddleware())($request));
    }
}
