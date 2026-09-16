<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Auth;

use Phlix\Auth\AuthManager;
use Phlix\Auth\QuickConnectPair;
use Phlix\Auth\QuickConnectStateStoreInterface;
use Phlix\Auth\RateLimitException;
use Phlix\Common\RateLimit\RateLimiterInterface;
use Phlix\Common\RateLimit\RateLimitState;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\Auth\QuickConnectController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * S518 (AC-1): every quick-connect/telemetry leg is metered through the central
 * SV-4.15 machinery — throw {@see RateLimitException} over budget and the
 * response the client sees is the ONE 429 shape the estate maps
 * ({@see Application::rateLimitResponse()}: `error=Too Many Requests`,
 * `code=rate_limited`, integer `Retry-After`).
 *
 * The keys themselves are the security-relevant half: IP legs derive from
 * {@see Request::getTrustedClientIp()} (a forged leftmost X-Forwarded-For must
 * not mint fresh buckets — same HIGH as SV-4.15), and approve keys on the
 * SESSION SUBJECT, not anything body-supplied.
 */
final class QuickConnectControllerRateLimitTest extends TestCase
{
    public function testInitiateTripsOnTrustedIpAndNeverTouchesTheStore(): void
    {
        $store = $this->createMock(QuickConnectStateStoreInterface::class);
        $store->expects(self::never())->method('issue');
        $limiter = $this->limiter(true);
        $controller = $this->controller(initiateLimiter: $limiter, pairs: $store);

        $this->assertCentral429(fn () => $controller->initiate($this->xffRequest(), []));
        self::assertSame(['qc-init:203.0.113.77'], $limiter->hits);
    }

    public function testStatusTripsOnTrustedIp(): void
    {
        $store = $this->createMock(QuickConnectStateStoreInterface::class);
        $store->expects(self::never())->method('find');
        $limiter = $this->limiter(true);
        $controller = $this->controller(statusLimiter: $limiter, pairs: $store);

        $this->assertCentral429(fn () => $controller->status($this->xffRequest(), ['code' => 'ACDFGH']));
        self::assertSame(['qc-status:203.0.113.77'], $limiter->hits);
    }

    public function testTokenSharesTheStatusBucketBecausePollThenRedeemIsOneSurface(): void
    {
        $store = $this->createMock(QuickConnectStateStoreInterface::class);
        $store->expects(self::never())->method('consumeApproved');
        $limiter = $this->limiter(true);
        $controller = $this->controller(statusLimiter: $limiter, pairs: $store);

        $this->assertCentral429(
            fn () => $controller->token($this->xffRequest(body: ['secret' => 's']), ['code' => 'ACDFGH'])
        );
        // Same bucket key as the status poll — the doctrine documented on the
        // controller class and in the RateLimitProfiles entry.
        self::assertSame(['qc-status:203.0.113.77'], $limiter->hits);
    }

    public function testApproveKeysOnTheSessionSubjectNotTheIp(): void
    {
        $store = $this->createMock(QuickConnectStateStoreInterface::class);
        $store->expects(self::never())->method('approve');
        $limiter = $this->limiter(true);
        $controller = $this->controller(approveLimiter: $limiter, pairs: $store);

        $request = $this->xffRequest(userId: 'user-42', body: ['secret' => 's']);
        $this->assertCentral429(fn () => $controller->approve($request, ['code' => 'ACDFGH']));
        self::assertSame(['qc-approve:user-42'], $limiter->hits);
    }

    public function testApproveTripsBeforeTheAnonymousRejectionOrderIsDeliberate(): void
    {
        // An anonymous request reaches approve only if route wiring regressed;
        // it must still hit the 401 self-gate WITHOUT consuming limiter budget
        // keyed on an empty subject.
        $limiter = $this->limiter(true);
        $controller = $this->controller(approveLimiter: $limiter);

        $response = $controller->approve($this->xffRequest(), ['code' => 'ACDFGH']);

        self::assertSame(401, $response->statusCode);
        self::assertSame([], $limiter->hits);
    }

    public function testHeartbeatTripsOnTrustedIpBeforeTheConsentGate(): void
    {
        $limiter = $this->limiter(true);
        $controller = $this->controller(telemetryLimiter: $limiter);

        $this->assertCentral429(fn () => $controller->heartbeat($this->xffRequest(), []));
        self::assertSame(['telemetry:203.0.113.77'], $limiter->hits);
    }

    public function testUnderLimitFlowsThroughEveryLegWithNullBudgetTrips(): void
    {
        $pair = new QuickConnectPair('ACDFGH', 's', QuickConnectPair::STATE_PENDING, null, time() + 300);
        $store = $this->createMock(QuickConnectStateStoreInterface::class);
        $store->method('issue')->willReturn($pair);
        $store->method('find')->willReturn($pair);

        $limiter = $this->limiter(false);
        $controller = $this->controller(
            initiateLimiter: $limiter,
            statusLimiter: $limiter,
            pairs: $store,
        );

        self::assertSame(200, $controller->initiate($this->xffRequest(), [])->statusCode);
        self::assertSame(200, $controller->status($this->xffRequest(), ['code' => 'ACDFGH'])->statusCode);
        // One shared double, one honest bucket each, none limited.
        self::assertSame(['qc-init:203.0.113.77', 'qc-status:203.0.113.77'], $limiter->hits);
    }

    public function testNullLimitersAreTheDegradedFallbackNoOp(): void
    {
        // The container-less Application fallback builds this controller without
        // limiters; endpoints must still serve (no-op budget), never 500.
        $pair = new QuickConnectPair('ACDFGH', 's', QuickConnectPair::STATE_PENDING, null, time() + 300);
        $store = $this->createMock(QuickConnectStateStoreInterface::class);
        $store->method('issue')->willReturn($pair);
        $controller = $this->controller(pairs: $store);

        self::assertSame(200, $controller->initiate($this->xffRequest(), [])->statusCode);
    }

    // -----------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------

    private function assertCentral429(callable $dispatch): void
    {
        try {
            $dispatch();
            self::fail('Expected RateLimitException over budget.');
        } catch (RateLimitException $e) {
            $response = Application::rateLimitResponse($e);
            self::assertSame(429, $response->statusCode);

            $retryAfter = $response->toWorkermanResponse()->getHeader('Retry-After');
            self::assertIsString($retryAfter);
            self::assertMatchesRegularExpression('/^\d+$/', $retryAfter);

            $body = json_decode((string) $response->body, true);
            self::assertSame('Too Many Requests', $body['error'] ?? null);
            self::assertSame('rate_limited', $body['code'] ?? null);
        }
    }

    /**
     * nginx-front shape: loopback peer, forged leftmost, real client appended —
     * the trusted derivation must return the appended address.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $extraHeaders
     */
    private function xffRequest(?string $userId = null, array $body = [], array $extraHeaders = []): Request
    {
        $request = new Request();
        $request->userId = $userId;
        $request->body = $body;
        $request->headers = $extraHeaders + ['X-Forwarded-For' => '10.0.0.1, 203.0.113.77'];
        $request->remoteIp = '127.0.0.1';

        return $request;
    }

    private function controller(
        ?RateLimiterInterface $initiateLimiter = null,
        ?RateLimiterInterface $statusLimiter = null,
        ?RateLimiterInterface $approveLimiter = null,
        ?RateLimiterInterface $telemetryLimiter = null,
        ?QuickConnectStateStoreInterface $pairs = null,
    ): QuickConnectController {
        return new QuickConnectController(
            $this->createMock(AuthManager::class),
            $pairs,
            null,
            $initiateLimiter,
            $statusLimiter,
            $approveLimiter,
            $telemetryLimiter,
        );
    }

    private function limiter(bool $limited): QcRecordingRateLimiter
    {
        return new QcRecordingRateLimiter($limited);
    }
}

/**
 * Recording limiter double (named class: the Psalm 6 object-shape rejection
 * documented in AuthControllerRateLimitTest applies to this estate).
 */
final class QcRecordingRateLimiter implements RateLimiterInterface
{
    /** @var list<string> */
    public array $hits = [];

    public function __construct(private readonly bool $limited)
    {
    }

    public function hit(string $key): RateLimitState
    {
        $this->hits[] = $key;

        return new RateLimitState(
            count: 1,
            remaining: $this->limited ? 0 : 9,
            resetAt: time() + 30,
            limited: $this->limited,
            limit: 10,
        );
    }

    public function reset(string $key): void
    {
    }

    public function peek(string $key): RateLimitState
    {
        return $this->hit($key);
    }
}
