<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use Phlix\Auth\RateLimitException;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shared 429 envelope {@see Application::rateLimitResponse()}
 * (SV-4.15(c)).
 *
 * This static helper is the single source of the canonical rate-limit response
 * emitted by ALL THREE server dispatch entrypoints — the Workerman
 * {@see \Phlix\Server\Workerman\HttpHandler} central catch, this class's
 * {@see Application::handleException()} (the {@see Application::run()} CGI path),
 * and `public/index.php`'s exception handler — so they cannot drift. Exercising
 * it directly (as the hub does for its mirror helper) pins the status,
 * Retry-After header and JSON body shape once.
 */
final class ApplicationRateLimitResponseTest extends TestCase
{
    public function testProduces429WithCanonicalJsonBody(): void
    {
        $response = Application::rateLimitResponse(new RateLimitException(resetAt: time() + 300));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(429, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type'] ?? null);

        /** @var array{error?: string, code?: string} $decoded */
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Too Many Requests', $decoded['error'] ?? null);
        self::assertSame('rate_limited', $decoded['code'] ?? null);
    }

    public function testRetryAfterHeaderMatchesTheExceptionMath(): void
    {
        $e = new RateLimitException(resetAt: 1_160);

        // The header must equal the exception's own retryAfterSeconds() math.
        $before = $e->retryAfterSeconds();
        $response = Application::rateLimitResponse($e);
        $after = $e->retryAfterSeconds();

        $header = $response->headers['Retry-After'] ?? null;
        self::assertIsString($header);
        self::assertMatchesRegularExpression('/^\d+$/', $header, 'Retry-After must be a non-negative integer string.');

        $value = (int) $header;
        self::assertGreaterThanOrEqual(min($before, $after), $value);
        self::assertLessThanOrEqual(max($before, $after), $value);
    }

    public function testRetryAfterHeaderFloorsAtZeroForAnElapsedWindow(): void
    {
        // A window already in the past must still yield a valid (0) Retry-After,
        // never a negative header value.
        $response = Application::rateLimitResponse(new RateLimitException(resetAt: time() - 600));

        self::assertSame('0', $response->headers['Retry-After'] ?? null);
    }
}
