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
     * A body with only `email` (no `username`) still authenticates: the
     * controller forwards the email value as the identifier to AuthManager::login.
     */
    public function testLoginAcceptsEmailWhenUsernameAbsent(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->once())
            ->method('login')
            ->with('alice@example.com', 'hunter2hunter2', 'unknown')
            ->willReturn([
                'access_token' => 'access-tok',
                'refresh_token' => 'refresh-tok',
                'user' => ['id' => 'u-1', 'username' => 'alice'],
            ]);

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->body = ['email' => 'alice@example.com', 'password' => 'hunter2hunter2'];
        $request->headers = [];

        $response = $controller->login($request, []);

        $this->assertSame(200, $response->statusCode);
    }

    /**
     * Negative: login() returns 400 when neither `username` nor `email` is present.
     */
    public function testLoginReturns400WhenNoIdentifier(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->never())->method('login');

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->body = ['password' => 'hunter2hunter2'];
        $request->headers = [];

        $response = $controller->login($request, []);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Missing required fields: username, password', $body['error']);
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

    /**
     * S4: the browser login flow must set both auth cookies with the
     * `Secure`, `HttpOnly`, and `SameSite=Lax` attributes so the
     * session/refresh credentials never traverse plain HTTP and are
     * unreadable from JavaScript.
     */
    public function testBrowserLoginSetsSecureHttpOnlyLaxCookies(): void
    {
        putenv('PHLIX_COOKIE_INSECURE');

        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('login')->willReturn([
            'access_token' => 'access-tok',
            'refresh_token' => 'refresh-tok',
            'expires_in' => 3600,
            'user' => ['id' => 'u-1', 'username' => 'alice'],
        ]);

        $controller = new AuthController($authManager);

        // The `/auth/` path marks this as a browser request → redirect + cookies.
        $request = new Request();
        $request->path = '/auth/login';
        $request->body = ['username' => 'alice', 'password' => 'hunter2hunter2'];

        $response = $controller->login($request, []);

        $this->assertSame(302, $response->statusCode);

        $setCookies = $response->toWorkermanResponse()->getHeader('Set-Cookie');
        $cookieLines = is_array($setCookies) ? $setCookies : [$setCookies];

        $session = $this->findCookieLine($cookieLines, AuthController::SESSION_COOKIE);
        $refresh = $this->findCookieLine($cookieLines, AuthController::REFRESH_COOKIE);

        foreach ([$session, $refresh] as $line) {
            $this->assertStringContainsString('Secure', $line);
            $this->assertStringContainsString('HttpOnly', $line);
            $this->assertStringContainsString('SameSite=Lax', $line);
        }
    }

    /**
     * S4: the `PHLIX_COOKIE_INSECURE=1` local-dev opt-out drops the
     * `Secure` attribute so an HTTP dev server can still set cookies,
     * while HttpOnly/SameSite stay intact.
     */
    public function testBrowserLoginOmitsSecureWhenInsecureOptOut(): void
    {
        putenv('PHLIX_COOKIE_INSECURE=1');

        try {
            $authManager = $this->createMock(AuthManager::class);
            $authManager->method('login')->willReturn([
                'access_token' => 'access-tok',
                'refresh_token' => 'refresh-tok',
                'expires_in' => 3600,
                'user' => ['id' => 'u-1', 'username' => 'alice'],
            ]);

            $controller = new AuthController($authManager);

            $request = new Request();
            $request->path = '/auth/login';
            $request->body = ['username' => 'alice', 'password' => 'hunter2hunter2'];

            $response = $controller->login($request, []);

            $setCookies = $response->toWorkermanResponse()->getHeader('Set-Cookie');
            $cookieLines = is_array($setCookies) ? $setCookies : [$setCookies];

            $session = $this->findCookieLine($cookieLines, AuthController::SESSION_COOKIE);
            $refresh = $this->findCookieLine($cookieLines, AuthController::REFRESH_COOKIE);

            foreach ([$session, $refresh] as $line) {
                $this->assertStringNotContainsString('Secure', $line);
                $this->assertStringContainsString('HttpOnly', $line);
                $this->assertStringContainsString('SameSite=Lax', $line);
            }
        } finally {
            putenv('PHLIX_COOKIE_INSECURE');
        }
    }

    /**
     * Locate the Set-Cookie line for the given cookie name.
     *
     * @param array<int, string> $cookieLines
     */
    private function findCookieLine(array $cookieLines, string $name): string
    {
        foreach ($cookieLines as $line) {
            if (str_starts_with($line, $name . '=')) {
                return $line;
            }
        }

        $this->fail("Set-Cookie line for '{$name}' not found");
    }
}
