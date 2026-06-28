<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Middleware;

use Phlix\Server\Http\Middleware\CorsManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see CorsManager} — credentialed, reflected-origin CORS that
 * stays OFF (no headers) until an exact origin is allowlisted.
 */
class CorsManagerTest extends TestCase
{
    private const ORIGIN = 'https://app.example.com';

    /**
     * An allowlisted Origin gets the reflected ACAO, credentials flag, and Vary.
     */
    public function testDecorateAddsCredentialedHeadersForAllowedOrigin(): void
    {
        $cors = new CorsManager([self::ORIGIN]);

        $request = $this->requestWithOrigin('GET', self::ORIGIN);
        $response = $cors->decorate($request, (new Response())->json(['ok' => true]));

        $this->assertSame(self::ORIGIN, $response->headers['Access-Control-Allow-Origin']);
        $this->assertSame('true', $response->headers['Access-Control-Allow-Credentials']);
        $this->assertSame('Origin', $response->headers['Vary']);
        // NEVER a wildcard alongside credentials.
        $this->assertNotSame('*', $response->headers['Access-Control-Allow-Origin']);
    }

    /**
     * A non-allowlisted Origin gets NO CORS headers (same-origin unchanged).
     */
    public function testDecorateEmitsNoHeadersForDisallowedOrigin(): void
    {
        $cors = new CorsManager([self::ORIGIN]);

        $request = $this->requestWithOrigin('GET', 'https://evil.example.com');
        $response = $cors->decorate($request, (new Response())->json(['ok' => true]));

        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $response->headers);
        $this->assertArrayNotHasKey('Vary', $response->headers);
    }

    /**
     * An empty allowlist disables CORS entirely — even an Origin header that
     * would otherwise match emits nothing (this preserves today's behavior).
     */
    public function testDecorateEmitsNoHeadersWhenAllowlistEmpty(): void
    {
        $cors = new CorsManager([]);

        $request = $this->requestWithOrigin('GET', self::ORIGIN);
        $response = $cors->decorate($request, (new Response())->json(['ok' => true]));

        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
    }

    /**
     * A request with no Origin header is untouched.
     */
    public function testDecorateEmitsNoHeadersWithoutOrigin(): void
    {
        $cors = new CorsManager([self::ORIGIN]);

        $request = new Request();
        $request->method = 'GET';
        $response = $cors->decorate($request, (new Response())->json(['ok' => true]));

        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
    }

    /**
     * Preflight OPTIONS from an allowlisted origin → 204 with allow-methods,
     * allow-headers, max-age, and the credentialed reflected ACAO.
     */
    public function testPreflightReturns204WithAllowHeadersForAllowedOrigin(): void
    {
        $cors = new CorsManager([self::ORIGIN]);

        $request = $this->requestWithOrigin('OPTIONS', self::ORIGIN);
        $response = $cors->preflightResponse($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(204, $response->statusCode);
        $this->assertSame('', $response->body);

        $this->assertSame(self::ORIGIN, $response->headers['Access-Control-Allow-Origin']);
        $this->assertSame('true', $response->headers['Access-Control-Allow-Credentials']);
        $this->assertSame('Origin', $response->headers['Vary']);

        $methods = $response->headers['Access-Control-Allow-Methods'];
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $this->assertStringContainsString($method, $methods);
        }

        $allowHeaders = $response->headers['Access-Control-Allow-Headers'];
        $this->assertStringContainsString('Authorization', $allowHeaders);
        $this->assertStringContainsString('Content-Type', $allowHeaders);

        $this->assertArrayHasKey('Access-Control-Max-Age', $response->headers);
    }

    /**
     * Preflight OPTIONS from a non-allowlisted origin → null (no short-circuit;
     * the request routes normally and gets no CORS headers).
     */
    public function testPreflightReturnsNullForDisallowedOrigin(): void
    {
        $cors = new CorsManager([self::ORIGIN]);

        $request = $this->requestWithOrigin('OPTIONS', 'https://evil.example.com');

        $this->assertNull($cors->preflightResponse($request));
    }

    /**
     * Preflight only triggers on OPTIONS — a GET from an allowed origin returns
     * null from preflightResponse() (decoration handles the actual request).
     */
    public function testPreflightReturnsNullForNonOptionsMethod(): void
    {
        $cors = new CorsManager([self::ORIGIN]);

        $request = $this->requestWithOrigin('GET', self::ORIGIN);

        $this->assertNull($cors->preflightResponse($request));
    }

    /**
     * `Vary: Origin` is merged into an existing Vary header without losing the
     * prior token or duplicating Origin.
     */
    public function testDecorateMergesIntoExistingVaryHeader(): void
    {
        $cors = new CorsManager([self::ORIGIN]);

        $request = $this->requestWithOrigin('GET', self::ORIGIN);
        $base = (new Response())->json(['ok' => true])->header('Vary', 'Accept-Encoding');
        $response = $cors->decorate($request, $base);

        $this->assertStringContainsString('Accept-Encoding', $response->headers['Vary']);
        $this->assertStringContainsString('Origin', $response->headers['Vary']);
    }

    /**
     * fromEnv() reads PHLIX_CORS_ALLOWED_ORIGINS and trims/splits it.
     */
    public function testFromEnvParsesCommaSeparatedAllowlist(): void
    {
        putenv(CorsManager::ENV_ALLOWED_ORIGINS . '=https://a.example.com, https://b.example.com');

        try {
            $cors = CorsManager::fromEnv();

            $allowed = $this->requestWithOrigin('GET', 'https://b.example.com');
            $response = $cors->decorate($allowed, (new Response())->json(['ok' => true]));
            $this->assertSame('https://b.example.com', $response->headers['Access-Control-Allow-Origin']);
        } finally {
            putenv(CorsManager::ENV_ALLOWED_ORIGINS);
        }
    }

    /**
     * fromEnv() with the env var unset/empty disables CORS.
     */
    public function testFromEnvWithUnsetVarDisablesCors(): void
    {
        putenv(CorsManager::ENV_ALLOWED_ORIGINS);

        $cors = CorsManager::fromEnv();
        $request = $this->requestWithOrigin('GET', self::ORIGIN);
        $response = $cors->decorate($request, (new Response())->json(['ok' => true]));

        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
    }

    /**
     * parseOrigins() drops blanks and trims whitespace.
     */
    public function testParseOriginsTrimsAndDropsBlanks(): void
    {
        $this->assertSame(
            ['https://a.example.com', 'https://b.example.com'],
            CorsManager::parseOrigins('  https://a.example.com , ,https://b.example.com  '),
        );
        $this->assertSame([], CorsManager::parseOrigins(''));
        $this->assertSame([], CorsManager::parseOrigins('   ,  , '));
    }

    private function requestWithOrigin(string $method, string $origin): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->headers = ['ORIGIN' => $origin];

        return $request;
    }
}
