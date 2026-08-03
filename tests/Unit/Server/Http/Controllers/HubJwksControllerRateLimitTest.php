<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Auth\RateLimitException;
use Phlix\Common\RateLimit\RateLimiterInterface;
use Phlix\Common\RateLimit\RateLimitState;
use Phlix\Hub\HubClient;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\HubJwksController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * SV-4.15(g): per-surface rate limiting wired into
 * {@see HubJwksController::handle}.
 *
 * The public JWKS endpoint is a cache-frontable, low-value DoS surface, so it
 * uses the worker-local in-memory {@see RateLimitProfiles::JWKS} limiter (not a
 * shared DB backend). The key is the TRUSTED client IP via
 * {@see Request::getTrustedClientIp()} (trusted-proxy-aware; a forged
 * X-Forwarded-For cannot mint a fresh bucket — SV-4.15 HIGH). An over-limit request
 * throws {@see RateLimitException}, which the central mapping (SV-4.15(c),
 * {@see Application::rateLimitResponse()}) turns into a 429 + `Retry-After` +
 * `code=rate_limited` response — the route is an inline closure in Application
 * that delegates to this method, so the throw propagates through the same
 * dispatch path the central mapping catches. An under-limit request returns the
 * normal JWKS body.
 *
 */
final class HubJwksControllerRateLimitTest extends TestCase
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
                    count: $this->limited ? 200 : 1,
                    remaining: 0,
                    resetAt: time() + 45,
                    limited: $this->limited,
                    limit: 120,
                );
            }

            public function reset(string $key): void
            {
            }

            public function peek(string $key): RateLimitState
            {
                return new RateLimitState(0, 120, 0, false, 120);
            }
        };
    }

    public function testOverLimitThrowsRateLimitExceptionMappingTo429(): void
    {
        $hubClient = $this->createMock(HubClient::class);
        // Over-limit trips BEFORE any key material is read — never called.
        $hubClient->expects($this->never())->method('getPublicKeysJwk');

        $limiter = $this->makeLimiter(true);
        $controller = new HubJwksController($hubClient, $limiter);

        // nginx front: loopback peer + forged leftmost, real client appended.
        $request = new Request();
        $request->headers = ['X-Forwarded-For' => '10.0.0.1, 203.0.113.77'];
        $request->remoteIp = '127.0.0.1';

        try {
            $controller->handle($request, []);
            self::fail('Expected RateLimitException on over-limit JWKS request.');
        } catch (RateLimitException $e) {
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

        // Key is the trusted (appended) client IP, NOT the forged leftmost value
        // and NOT the loopback peer.
        self::assertSame(['jwks:203.0.113.77'], $limiter->hits);
    }

    public function testUnderLimitReturnsJwksBody(): void
    {
        $keys = [['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => 'abc', 'kid' => 'k1']];

        $hubClient = $this->createMock(HubClient::class);
        $hubClient->expects($this->once())->method('getPublicKeysJwk')->willReturn($keys);

        $limiter = $this->makeLimiter(false);
        $controller = new HubJwksController($hubClient, $limiter);

        $request = new Request();
        $request->headers = ['X-Forwarded-For' => '198.51.100.3'];
        $request->remoteIp = '127.0.0.1';

        $response = $controller->handle($request, []);

        self::assertSame(200, $response->statusCode);

        /** @var array{keys?: array<int, array<string, string>>} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($keys, $body['keys'] ?? null);

        self::assertSame(['jwks:198.51.100.3'], $limiter->hits);
    }

    /**
     * The degraded no-container fallback constructs the controller without a
     * limiter; the endpoint must still serve the JWKS (limiter is a no-op).
     */
    public function testNullLimiterIsNoOp(): void
    {
        $keys = [['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => 'z', 'kid' => 'k9']];

        $hubClient = $this->createMock(HubClient::class);
        $hubClient->method('getPublicKeysJwk')->willReturn($keys);

        $controller = new HubJwksController($hubClient);

        $request = new Request();
        $request->headers = ['X-Forwarded-For' => '203.0.113.250'];

        $response = $controller->handle($request, []);
        self::assertSame(200, $response->statusCode);
    }
}
