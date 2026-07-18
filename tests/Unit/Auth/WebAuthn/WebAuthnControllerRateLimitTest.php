<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth\WebAuthn;

use Phlix\Auth\AuthManager;
use Phlix\Auth\RateLimitException;
use Phlix\Auth\WebAuthn\WebAuthnManager;
use Phlix\Common\RateLimit\RateLimiterInterface;
use Phlix\Common\RateLimit\RateLimitState;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\WebAuthnController;
use Phlix\Server\Http\Request;
use Phlix\Shared\Auth\AuthResult;
use PHPUnit\Framework\TestCase;

/**
 * SV-4.15(f): per-surface rate limiting wired into
 * {@see WebAuthnController::startAuthentication} and
 * {@see WebAuthnController::finishAuthentication}.
 *
 * Both ceremonies are keyed on the submitted username (throttling
 * credential-enumeration against a single account), falling back to the real
 * client IP via {@see Request::getClientIp()} (X-Forwarded-For aware, NOT the
 * stale `$_SERVER['REMOTE_ADDR']`) when no username is present. An over-limit
 * request throws {@see RateLimitException}, which the central mapping
 * (SV-4.15(c)) turns into a 429 + `Retry-After` + `code=rate_limited`.
 *
 * @covers \Phlix\Server\Http\Controllers\WebAuthnController
 */
final class WebAuthnControllerRateLimitTest extends TestCase
{
    /**
     * Recording {@see RateLimiterInterface} double capturing every key and
     * reporting a fixed limited/not-limited state.
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
                    count: $this->limited ? 11 : 1,
                    remaining: 0,
                    resetAt: time() + 60,
                    limited: $this->limited,
                    limit: 10,
                );
            }

            public function reset(string $key): void
            {
            }

            public function peek(string $key): RateLimitState
            {
                return new RateLimitState(0, 10, 0, false, 10);
            }
        };
    }

    private function assertProduces429(RateLimitException $e): void
    {
        $response = Application::rateLimitResponse($e);
        self::assertSame(429, $response->statusCode);

        $retryAfter = $response->toWorkermanResponse()->getHeader('Retry-After');
        self::assertIsString($retryAfter);
        self::assertMatchesRegularExpression('/^\d+$/', $retryAfter);

        /** @var array{error?: string, code?: string} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Too Many Requests', $body['error'] ?? null);
        self::assertSame('rate_limited', $body['code'] ?? null);
    }

    // --- startAuthentication -------------------------------------------------

    public function testStartAuthenticationOverLimitThrowsKeyedByUsername(): void
    {
        $webauthn = $this->createMock(WebAuthnManager::class);
        $webauthn->expects($this->never())->method('startAuthentication');
        $authManager = $this->createMock(AuthManager::class);

        $limiter = $this->makeLimiter(true);
        $controller = new WebAuthnController($webauthn, $authManager, $limiter, $this->makeLimiter(false));

        $request = new Request();
        $request->body = ['username' => 'alice'];

        try {
            $controller->startAuthentication($request, []);
            self::fail('Expected RateLimitException on over-limit start.');
        } catch (RateLimitException $e) {
            $this->assertProduces429($e);
        }

        self::assertSame(['webauthn_start:alice'], $limiter->hits);
    }

    public function testStartAuthenticationUnderLimitProceeds(): void
    {
        $webauthn = $this->createMock(WebAuthnManager::class);
        $webauthn->expects($this->once())
            ->method('startAuthentication')
            ->with('alice')
            ->willReturn(['challenge' => 'abc', 'timeout' => 60000]);
        $authManager = $this->createMock(AuthManager::class);

        $limiter = $this->makeLimiter(false);
        $controller = new WebAuthnController($webauthn, $authManager, $limiter, $this->makeLimiter(false));

        $request = new Request();
        $request->body = ['username' => 'alice'];

        $response = $controller->startAuthentication($request, []);

        self::assertSame(200, $response->statusCode);
        self::assertSame(['webauthn_start:alice'], $limiter->hits);
    }

    public function testStartAuthenticationFallsBackToClientIpWhenUsernameAbsent(): void
    {
        $webauthn = $this->createMock(WebAuthnManager::class);
        $authManager = $this->createMock(AuthManager::class);

        $limiter = $this->makeLimiter(true);
        $controller = new WebAuthnController($webauthn, $authManager, $limiter, $this->makeLimiter(false));

        $request = new Request();
        $request->headers = ['X-Forwarded-For' => '203.0.113.77'];
        $request->remoteIp = '10.0.0.5';
        $request->body = []; // no username

        try {
            $controller->startAuthentication($request, []);
        } catch (RateLimitException) {
            // expected — trips on the IP-keyed bucket before the missing-field 400.
        }

        self::assertSame(['webauthn_start:203.0.113.77'], $limiter->hits);
    }

    // --- finishAuthentication ------------------------------------------------

    public function testFinishAuthenticationOverLimitThrowsKeyedByUsername(): void
    {
        $webauthn = $this->createMock(WebAuthnManager::class);
        $webauthn->expects($this->never())->method('finishAuthentication');
        $authManager = $this->createMock(AuthManager::class);

        $limiter = $this->makeLimiter(true);
        $controller = new WebAuthnController($webauthn, $authManager, $this->makeLimiter(false), $limiter);

        $request = new Request();
        $request->body = [
            'username' => 'alice',
            'credential' => ['id' => 'x'],
            'challenge' => 'chal',
        ];

        try {
            $controller->finishAuthentication($request, []);
            self::fail('Expected RateLimitException on over-limit finish.');
        } catch (RateLimitException $e) {
            $this->assertProduces429($e);
        }

        self::assertSame(['webauthn_finish:alice'], $limiter->hits);
    }

    public function testFinishAuthenticationUnderLimitProceeds(): void
    {
        $webauthn = $this->createMock(WebAuthnManager::class);
        $webauthn->expects($this->once())
            ->method('finishAuthentication')
            ->with('alice')
            ->willReturn(new AuthResult(success: true, userId: 'u-1'));

        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->once())
            ->method('buildAuthResponse')
            ->with('u-1')
            ->willReturn([
                'access_token' => 'access-tok',
                'refresh_token' => 'refresh-tok',
                'user' => ['id' => 'u-1', 'username' => 'alice'],
            ]);

        $limiter = $this->makeLimiter(false);
        $controller = new WebAuthnController($webauthn, $authManager, $this->makeLimiter(false), $limiter);

        $request = new Request();
        $request->body = [
            'username' => 'alice',
            'credential' => ['id' => 'x'],
            'challenge' => 'chal',
        ];

        $response = $controller->finishAuthentication($request, []);

        self::assertSame(200, $response->statusCode);
        self::assertSame(['webauthn_finish:alice'], $limiter->hits);
    }

    /**
     * Direct-construction (no limiters) stays a no-op so existing call sites and
     * the finish ceremony keep working.
     */
    public function testNullLimitersAreNoOp(): void
    {
        $webauthn = $this->createMock(WebAuthnManager::class);
        $webauthn->method('startAuthentication')->willReturn(['challenge' => 'abc']);
        $authManager = $this->createMock(AuthManager::class);

        $controller = new WebAuthnController($webauthn, $authManager);

        $request = new Request();
        $request->body = ['username' => 'alice'];

        $response = $controller->startAuthentication($request, []);
        self::assertSame(200, $response->statusCode);
    }
}
