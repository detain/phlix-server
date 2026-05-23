<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use InvalidArgumentException;
use Phlix\Auth\AuthManager;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AuthController}.
 *
 * Covers the four handler methods now wired in Application::loadApiRoutes():
 *   POST /api/v1/auth/register
 *   POST /api/v1/auth/login
 *   POST /api/v1/auth/refresh
 *   GET  /api/v1/auth/me
 *
 * Uses createMock(AuthManager::class) following the project's existing
 * controller-test conventions (see SessionControllerTest, HubJwksControllerTest).
 */
class AuthControllerTest extends TestCase
{
    /**
     * Happy path: register() returns 201 with the AuthManager payload.
     */
    public function testRegisterReturns201OnSuccess(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->once())
            ->method('register')
            ->with('alice', 'alice@example.com', 'hunter2hunter2')
            ->willReturn([
                'access_token' => 'access-tok',
                'refresh_token' => 'refresh-tok',
                'user' => ['id' => 'u-1', 'username' => 'alice'],
            ]);

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->body = [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'hunter2hunter2',
        ];

        $response = $controller->register($request, []);

        $this->assertSame(201, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('access-tok', $body['access_token']);
        $this->assertSame('refresh-tok', $body['refresh_token']);
        $this->assertSame('alice', $body['user']['username']);
    }

    /**
     * Negative: register() returns 400 when AuthManager rejects the input.
     */
    public function testRegisterReturns400OnInvalidArgument(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('register')
            ->willThrowException(new InvalidArgumentException('Username already taken'));

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->body = [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'hunter2hunter2',
        ];

        $response = $controller->register($request, []);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Username already taken', $body['error']);
    }

    /**
     * Happy path: login() returns 200 with tokens.
     */
    public function testLoginReturns200OnSuccess(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->once())
            ->method('login')
            ->with('alice', 'hunter2hunter2', 'device-123')
            ->willReturn([
                'access_token' => 'access-tok',
                'refresh_token' => 'refresh-tok',
                'user' => ['id' => 'u-1', 'username' => 'alice'],
            ]);

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->body = ['username' => 'alice', 'password' => 'hunter2hunter2'];
        $request->headers = ['X-DEVICE-ID' => 'device-123'];

        $response = $controller->login($request, []);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('access-tok', $body['access_token']);
    }

    /**
     * Negative: login() returns 401 when credentials are wrong.
     */
    public function testLoginReturns401OnBadCredentials(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('login')
            ->willThrowException(new InvalidArgumentException('Invalid credentials'));

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->body = ['username' => 'alice', 'password' => 'wrong'];
        $request->headers = [];

        $response = $controller->login($request, []);

        $this->assertSame(401, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Invalid credentials', $body['error']);
    }

    /**
     * Happy path: refresh() returns 200 with a fresh token pair.
     */
    public function testRefreshReturns200OnSuccess(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->once())
            ->method('refreshToken')
            ->with('refresh-tok')
            ->willReturn([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
            ]);

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->body = ['refresh_token' => 'refresh-tok'];

        $response = $controller->refresh($request, []);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('new-access', $body['access_token']);
        $this->assertSame('new-refresh', $body['refresh_token']);
    }

    /**
     * Negative: refresh() returns 400 when refresh_token field is missing.
     */
    public function testRefreshReturns400WhenRefreshTokenMissing(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->never())->method('refreshToken');

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->body = [];

        $response = $controller->refresh($request, []);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('refresh_token is required', $body['error']);
    }

    /**
     * Happy path: me() returns 200 with user data for an authenticated request.
     */
    public function testMeReturns200WhenAuthenticated(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->once())
            ->method('getUser')
            ->with('u-1')
            ->willReturn([
                'id' => 'u-1',
                'username' => 'alice',
                'email' => 'alice@example.com',
            ]);

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->userId = 'u-1';

        $response = $controller->me($request, []);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('alice', $body['user']['username']);
    }

    /**
     * Negative: me() returns 401 when no userId is present on the request.
     * This is the in-controller "is the upstream auth middleware satisfied?"
     * gate; mirrors how SessionController guards /api/v1/me/continue-watching.
     */
    public function testMeReturns401WhenUnauthenticated(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->never())->method('getUser');

        $controller = new AuthController($authManager);

        $request = new Request();
        // request->userId intentionally left null

        $response = $controller->me($request, []);

        $this->assertSame(401, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Unauthorized', $body['error']);
    }
}
