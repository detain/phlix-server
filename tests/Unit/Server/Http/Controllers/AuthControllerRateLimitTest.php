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
 * SV-4.15(f) + HIGH fix: per-surface rate limiting wired into
 * {@see AuthController::register} and {@see AuthController::refresh}.
 *
 * Each surface is keyed on the TRUSTED client IP via
 * {@see Request::getTrustedClientIp()} — trusted-proxy-aware, so a forged
 * `X-Forwarded-For` can no longer mint a fresh bucket. The shipped nginx front
 * APPENDS the connecting address to XFF (`$proxy_add_x_forwarded_for`) over a
 * loopback upstream, so the peer Phlix sees is `127.0.0.1` and the REAL client is
 * the RIGHTMOST XFF entry; any client-forged value sits to its LEFT and is
 * ignored. An over-limit request throws {@see RateLimitException}, which the
 * central mapping (SV-4.15(c), {@see Application::rateLimitResponse()}) turns into
 * a 429 + `Retry-After` + `code=rate_limited` response. An under-limit request
 * proceeds to {@see AuthManager} normally.
 *
 */
final class AuthControllerRateLimitTest extends TestCase
{
    /**
     * A recording {@see RateLimiterInterface} double: captures every key passed
     * to {@see hit()} and reports a fixed limited/not-limited state so tests can
     * assert BOTH the trip behaviour and the exact key that was built.
     *
     * S128: the intersection is what lets the assertions below read `->hits`. The
     * native return type can only name the interface, and the interface has no such
     * property, so at PHPStan level 2 every `$limiter->hits` read is an error against
     * a property that demonstrably exists.
     *
     * @return RateLimiterInterface&object{hits: list<string>}
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
     * Build a request as it arrives from the shipped nginx front: the loopback
     * upstream is the peer, and `X-Forwarded-For` is `<client-supplied>,
     * <appended-real-client>` (nginx appends `$remote_addr`).
     *
     * @param array<string, mixed> $body
     */
    private function proxiedRequest(string $xff, array $body, string $peer = '127.0.0.1'): Request
    {
        $request = new Request();
        $request->headers = ['X-Forwarded-For' => $xff];
        $request->remoteIp = $peer;
        $request->body = $body;
        return $request;
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

        // Forged leftmost (198.51.100.66) + real client appended by nginx.
        $request = $this->proxiedRequest('198.51.100.66, 203.0.113.9', [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'hunter2hunter2',
        ]);

        try {
            $controller->register($request, []);
            self::fail('Expected RateLimitException on over-limit register.');
        } catch (RateLimitException $e) {
            $this->assertProduces429($e);
        }

        // Key is the TRUSTED (rightmost/appended) client IP, NOT the forged
        // leftmost value and NOT the loopback peer.
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

        $request = $this->proxiedRequest('198.51.100.7', [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'hunter2hunter2',
        ]);

        $response = $controller->register($request, []);

        self::assertSame(201, $response->statusCode);
        self::assertSame(['register:198.51.100.7'], $limiter->hits);
    }

    /**
     * SV-4.15 HIGH: a forged leftmost X-Forwarded-For no longer resets the bucket.
     * Two requests from the SAME real client but DIFFERENT forged leftmost values
     * must build the SAME key — the spoof cannot hand out a fresh budget.
     */
    public function testForgedLeftmostXffDoesNotMintFreshBucket(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $limiter = $this->makeLimiter(true);
        $controller = new AuthController($authManager, $limiter, $this->makeLimiter(false));

        $body = ['username' => 'a', 'email' => 'a@b.c', 'password' => 'pw123456'];

        foreach (['1.2.3.4', '9.9.9.9', 'not-an-ip'] as $forged) {
            try {
                $controller->register(
                    $this->proxiedRequest($forged . ', 203.0.113.50', $body),
                    [],
                );
            } catch (RateLimitException) {
                // expected on every attempt
            }
        }

        // All three collapse onto the one real-client bucket.
        self::assertSame(
            ['register:203.0.113.50', 'register:203.0.113.50', 'register:203.0.113.50'],
            $limiter->hits,
        );
    }

    /**
     * A trusted-proxy hop in the chain (an extra loopback entry, as a
     * two-hop proxy would append) is SKIPPED right-to-left — the first untrusted
     * address is still the real client.
     */
    public function testTrustedProxyHopInChainIsSkipped(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $limiter = $this->makeLimiter(true);
        $controller = new AuthController($authManager, $limiter, $this->makeLimiter(false));

        // client -> edge -> loopback: XFF ends with a trusted 127.0.0.1 hop.
        $request = $this->proxiedRequest('203.0.113.77, 127.0.0.1', [
            'username' => 'a',
            'email' => 'a@b.c',
            'password' => 'pw123456',
        ]);

        try {
            $controller->register($request, []);
        } catch (RateLimitException) {
            // expected
        }

        self::assertSame(['register:203.0.113.77'], $limiter->hits);
    }

    /**
     * Key-length safety (SV-4.15 HIGH amplifier): a malformed/oversized forwarded
     * value can never produce a key that would overflow the VARCHAR(191)
     * `rate_limit_buckets.rate_key` PK. The resolver falls back to a validated
     * peer address (loopback here), so the whole key stays short.
     */
    public function testOversizedForwardedValueCannotOverflowKey(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $limiter = $this->makeLimiter(true);
        $controller = new AuthController($authManager, $limiter, $this->makeLimiter(false));

        // A single 5000-char garbage XFF entry (non-IP) with a loopback peer.
        $request = $this->proxiedRequest(str_repeat('A', 5000), [
            'username' => 'a',
            'email' => 'a@b.c',
            'password' => 'pw123456',
        ]);

        try {
            $controller->register($request, []);
        } catch (RateLimitException) {
            // expected
        }

        self::assertCount(1, $limiter->hits);
        self::assertLessThanOrEqual(191, strlen($limiter->hits[0]));
        // Non-IP garbage is rejected; the resolver falls back to the peer.
        self::assertSame('register:127.0.0.1', $limiter->hits[0]);
    }

    // --- refresh -------------------------------------------------------------

    public function testRefreshOverLimitThrowsRateLimitExceptionMappingTo429(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->never())->method('refreshToken');

        $limiter = $this->makeLimiter(true);
        $controller = new AuthController($authManager, $this->makeLimiter(false), $limiter);

        $request = $this->proxiedRequest('203.0.113.42', ['refresh_token' => 'some-refresh-token']);

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

        $request = $this->proxiedRequest('198.51.100.9', ['refresh_token' => 'some-refresh-token']);

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
