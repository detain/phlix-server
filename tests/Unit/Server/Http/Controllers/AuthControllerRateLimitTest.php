<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Auth\AuthManager;
use Phlix\Auth\RateLimitException;
use Phlix\Common\RateLimit\RateLimiterInterface;
use Phlix\Common\RateLimit\RateLimitState;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * SV-4.15(f): per-surface rate limiting wired into {@see AuthController::register}
 * and {@see AuthController::refresh}.
 *
 * Each surface is keyed on the REAL client IP via {@see Request::getClientIp()}
 * (X-Forwarded-For aware) — NOT the stale `$_SERVER['REMOTE_ADDR']` that
 * {@see AuthManager::getClientIp()} reads (unreliable under Workerman's resident
 * workers). An over-limit request throws {@see RateLimitException}, which the
 * central mapping (SV-4.15(c), {@see Application::rateLimitResponse()}) turns
 * into a 429 + `Retry-After` + `code=rate_limited` response. An under-limit
 * request proceeds to {@see AuthManager} normally.
 *
 * @covers \Phlix\Server\Http\Controllers\AuthController
 */
final class AuthControllerRateLimitTest extends TestCase
{
    /**
     * A recording {@see RateLimiterInterface} double: captures every key passed
     * to {@see hit()} and reports a fixed limited/not-limited state so tests can
     * assert BOTH the trip behaviour and the exact key that was built.
     */
    private function makeLimiter(bool $limited): RateLimiterInterface
    {
        return new class ($limited) implements RateLimiterInterface {
            /** @var list<string> */
            public array $hits = [];

            public function __construct(private bool $limited)
            {
            }

            public function hit(string $key): RateLimitState
            {
                $this->hits[] = $key;

                return new RateLimitState(
                    count: $this->limited ? 6 : 1,
                    remaining: 0,
                    resetAt: time() + 90,
                    limited: $this->limited,
                    limit: 5,
                );
            }

            public function reset(string $key): void
            {
            }

            public function peek(string $key): RateLimitState
            {
                return new RateLimitState(0, 5, 0, false, 5);
            }
        };
    }

    /**
     * Assert the caught exception yields the canonical 429 envelope when run
     * through the shared central mapping helper (SV-4.15(c)).
     */
    private function assertProduces429(RateLimitException $e): void
    {
        $response = Application::rateLimitResponse($e);
        self::assertSame(429, $response->statusCode);

        $retryAfter = $response->toWorkermanResponse()->getHeader('Retry-After');
        self::assertIsString($retryAfter);
        self::assertMatchesRegularExpression('/^\d+$/', $retryAfter);
        self::assertGreaterThan(0, (int) $retryAfter);

        /** @var array{error?: string, code?: string} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Too Many Requests', $body['error'] ?? null);
        self::assertSame('rate_limited', $body['code'] ?? null);
    }

    // --- register ------------------------------------------------------------

    public function testRegisterOverLimitThrowsRateLimitExceptionMappingTo429(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        // Over-limit trips BEFORE any real work — register() must never be called.
        $authManager->expects($this->never())->method('register');

        $limiter = $this->makeLimiter(true);
        $controller = new AuthController($authManager, $limiter, $this->makeLimiter(false));

        $request = new Request();
        $request->headers = ['X-Forwarded-For' => '203.0.113.9'];
        $request->remoteIp = '10.0.0.1';
        $request->body = [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'hunter2hunter2',
        ];

        try {
            $controller->register($request, []);
            self::fail('Expected RateLimitException on over-limit register.');
        } catch (RateLimitException $e) {
            $this->assertProduces429($e);
        }

        // Key is built from getClientIp() (X-Forwarded-For), NOT remoteIp/$_SERVER.
        self::assertSame(['register:203.0.113.9'], $limiter->hits);
    }

    public function testRegisterUnderLimitProceeds(): void
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

        $limiter = $this->makeLimiter(false);
        $controller = new AuthController($authManager, $limiter, $this->makeLimiter(false));

        $request = new Request();
        $request->headers = ['X-Forwarded-For' => '198.51.100.7'];
        $request->body = [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'hunter2hunter2',
        ];

        $response = $controller->register($request, []);

        self::assertSame(201, $response->statusCode);
        self::assertSame(['register:198.51.100.7'], $limiter->hits);
    }

    public function testRegisterKeyDerivesFromForwardedForNotServerGlobals(): void
    {
        $saved = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '192.0.2.55'; // the STALE source we must NOT use

        try {
            $authManager = $this->createMock(AuthManager::class);
            $limiter = $this->makeLimiter(true);
            $controller = new AuthController($authManager, $limiter, $this->makeLimiter(false));

            $request = new Request();
            $request->headers = ['X-Forwarded-For' => '203.0.113.200'];
            $request->remoteIp = '10.9.9.9';
            $request->body = ['username' => 'a', 'email' => 'a@b.c', 'password' => 'pw'];

            try {
                $controller->register($request, []);
            } catch (RateLimitException) {
                // expected
            }

            self::assertSame(['register:203.0.113.200'], $limiter->hits);
            self::assertStringNotContainsString('192.0.2.55', $limiter->hits[0]);
        } finally {
            if ($saved === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $saved;
            }
        }
    }

    // --- refresh -------------------------------------------------------------

    public function testRefreshOverLimitThrowsRateLimitExceptionMappingTo429(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->never())->method('refreshToken');

        $limiter = $this->makeLimiter(true);
        $controller = new AuthController($authManager, $this->makeLimiter(false), $limiter);

        $request = new Request();
        $request->headers = ['X-Forwarded-For' => '203.0.113.42'];
        $request->body = ['refresh_token' => 'some-refresh-token'];

        try {
            $controller->refresh($request, []);
            self::fail('Expected RateLimitException on over-limit refresh.');
        } catch (RateLimitException $e) {
            $this->assertProduces429($e);
        }

        self::assertSame(['refresh:203.0.113.42'], $limiter->hits);
    }

    public function testRefreshUnderLimitProceeds(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->once())
            ->method('refreshToken')
            ->with('some-refresh-token')
            ->willReturn([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
                'user' => ['id' => 'u-1', 'username' => 'alice'],
            ]);

        $limiter = $this->makeLimiter(false);
        $controller = new AuthController($authManager, $this->makeLimiter(false), $limiter);

        $request = new Request();
        $request->headers = ['X-Forwarded-For' => '198.51.100.9'];
        $request->body = ['refresh_token' => 'some-refresh-token'];

        $response = $controller->refresh($request, []);

        self::assertSame(200, $response->statusCode);
        self::assertSame(['refresh:198.51.100.9'], $limiter->hits);
    }

    /**
     * The degraded no-container fallback constructs AuthController without
     * limiters; the surfaces must still function (limiter is a no-op).
     */
    public function testNullLimitersAreNoOp(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('register')->willReturn([
            'access_token' => 'a',
            'refresh_token' => 'r',
            'user' => ['id' => 'u-1', 'username' => 'alice'],
        ]);

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->body = ['username' => 'alice', 'email' => 'a@b.c', 'password' => 'pw123456'];

        $response = $controller->register($request, []);
        self::assertSame(201, $response->statusCode);
    }
}
