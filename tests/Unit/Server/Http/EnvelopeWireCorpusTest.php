<?php

namespace Phlix\Tests\Unit\Server\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Auth\RateLimitException;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Middleware\CorsManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * W4 PREP (refactor-1119, lane W4-RESPONSE, finding A-04/A-03): wire corpus for
 * the canonical response families of the server HTTP layer.
 *
 * This file captures TODAY'S bytes as the specification, deliberately green at
 * the pre-refactor parent commit. When W3/S1 adopts the shared
 * `Phlix\Shared\Http\Envelope` (SHARED-056-DESIGN.md M-16), these pins become
 * the mechanical before/after equality proof: every family here must still
 * render byte-identically, or the adoption commit is wrong (not the pin —
 * ceiling-silence law, the pins govern).
 *
 * Family inventory (server side):
 *  - `{error, code}` and `{error, code, ...extra}` — Response::error() formula.
 *  - 429 rate_limited — Application::rateLimitResponse(), the single canonical
 *    limiter envelope (SV-4.15(c): the Workerman HttpHandler central catch is
 *    the whole map). NON-canonical 429/5xx families deliberately NOT merged
 *    here: stream.limit_exceeded (PreRouterFastPaths, charset-spelled CT +
 *    denial_type extras), the remote-subtitle quota 429 (no `code` key), and
 *    the HLS/Dash/LiveTV 503+Retry-After arms — different contracts, out of
 *    this seam's scope by design.
 *  - maintenance 503 — HUB-ONLY family (hub Application::maintenanceResponse);
 *    the server has no maintenance gate, so no arm exists here. Pinned on the
 *    hub side by the twin corpus (tests/Unit/Http/EnvelopeWireCorpusTest.php
 *    in phlix-hub, A-04 divergence note).
 *  - 204 / 304 no-body arms; HEAD no-body via asHeadReply().
 *  - CORS-with-credentials decoration + preflight (CorsManager — server-only
 *    capability: the hub's CorsPolicy never emits Allow-Credentials, the
 *    A-19 ratified divergence, pinned on the hub corpus).
 *
 * JSON whitespace divergence (A-03/R-11, intentional, NOT harmonized by this
 * lane): the server encodes with JSON_PRETTY_PRINT, the hub compact. Both sides
 * pin their own bytes here; the shapes (key sets, order) are claimed identical.
 */
final class EnvelopeWireCorpusTest extends TestCase
{
    // ------------------------------------------------------------------
    // Family 1: canonical {error, code} envelopes (Response::error)
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: int, 1: string, 2: string, 3: array<string, mixed>, 4: string}>
     */
    public static function errorEnvelopeCorpus(): array
    {
        return [
            'two_field_404' => [
                404,
                'not_found',
                'Item not found',
                [],
                "{\n    \"error\": \"Item not found\",\n    \"code\": \"not_found\"\n}",
            ],
            'extra_fields_409' => [
                409,
                'conflict',
                'nope',
                ['message' => 'nope', 'detail' => 'why'],
                // Insertion order law: error, code, then extras in their own
                // order (left-biased union in Response::error()).
                "{\n    \"error\": \"nope\",\n    \"code\": \"conflict\",\n"
                . "    \"message\": \"nope\",\n    \"detail\": \"why\"\n}",
            ],
            'reserved_word_code_429_manual' => [
                429,
                'rate_limited',
                'Too Many Requests',
                [],
                "{\n    \"error\": \"Too Many Requests\",\n    \"code\": \"rate_limited\"\n}",
            ],
        ];
    }

    #[DataProvider('errorEnvelopeCorpus')]
    public function testCanonicalErrorEnvelopeBytesPinned(
        int $status,
        string $code,
        string $message,
        array $extra,
        string $expectedBody,
    ): void {
        $response = (new Response())->error($status, $code, $message, $extra);

        self::assertSame($status, $response->statusCode);
        self::assertSame($expectedBody, $response->body);
        // Header insertion order: json() sets Content-Type first, then seeds
        // Cache-Control no-store (case-insensitive hasHeader guard, L-7 law).
        self::assertSame(
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            $response->headers,
        );
    }

    // ------------------------------------------------------------------
    // Family 2: 429 rate_limited (Application::rateLimitResponse)
    // ------------------------------------------------------------------

    public function testRateLimitedEnvelopeIsTheCanonicalPairPlusRetryAfter(): void
    {
        // Past resetAt: retryAfterSeconds() clamps to the deterministic 0.
        $response = Application::rateLimitResponse(new RateLimitException(resetAt: 1));

        self::assertSame(429, $response->statusCode);
        self::assertSame(
            "{\n    \"error\": \"Too Many Requests\",\n    \"code\": \"rate_limited\"\n}",
            $response->body,
        );
        // Retry-After is written BEFORE json() runs: the header map order is
        // Retry-After, Content-Type, Cache-Control. (S-b adopts the canonical
        // Response seam at this site; corpus green after = byte-identity proof.)
        self::assertSame(
            [
                'Retry-After' => '0',
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store',
            ],
            $response->headers,
        );
    }

    public function testRateLimitedRetryAfterIsIntegerStringOfExceptionMath(): void
    {
        $e = new RateLimitException(resetAt: time() + 300);

        $before = $e->retryAfterSeconds();
        $response = Application::rateLimitResponse($e);
        $after = $e->retryAfterSeconds();

        $header = $response->headers['Retry-After'] ?? null;
        self::assertIsString($header);
        self::assertMatchesRegularExpression('/^\d+$/', $header);
        // The header equals the exception's own math (bracketed for the tick).
        self::assertGreaterThanOrEqual($after, (int) $header);
        self::assertLessThanOrEqual($before, (int) $header);
    }

    // ------------------------------------------------------------------
    // Family 3: 204 / 304 no-body arms
    // ------------------------------------------------------------------

    public function testNoContentCarriesNoHeadersNoBody(): void
    {
        $response = (new Response())->noContent();

        self::assertSame(204, $response->statusCode);
        self::assertSame('', $response->body);
        // noContent() touches no header: no Content-Type, no Cache-Control seed.
        self::assertSame([], $response->headers);

        $wire = (string) $response->toWorkermanResponse();
        self::assertStringStartsWith("HTTP/1.1 204 No Content\r\n", $wire);
        // Current law (captured as spec): the Response OBJECT carries no
        // headers, but the framework encoder still appends its defaults —
        // text/html Content-Type + Content-Length: 0 + keep-alive — to the
        // header block. A conforming client ignores Content-Type on a 204;
        // this pins today's byte reality, not an aspiration.
        $separator = (int) strpos($wire, "\r\n\r\n");
        $prefix = "HTTP/1.1 204 No Content\r\n";
        self::assertSame(
            "Content-Type: text/html;charset=utf-8\r\nContent-Length: 0\r\nConnection: keep-alive",
            substr($wire, strlen($prefix), $separator - strlen($prefix)),
        );
        // Nothing follows the final blank line: the 204 is bodyless on the wire.
        self::assertSame('', substr($wire, $separator + 4));
    }

    public function testNotModifiedFollowsCallerConventionOfEmptyBody(): void
    {
        // The 304 sites (ArtworkByteResponder, PhotoController,
        // TranscodeFileServer, MediaItemController) set status(304) and leave
        // the body empty themselves — toWorkermanResponse() has NO special
        // casing for 304. This arm pins that law honestly: empty body in,
        // empty body out, no Content-Type added.
        $response = (new Response())->status(304);

        self::assertSame(304, $response->statusCode);
        self::assertSame('', $response->body);
        self::assertSame([], $response->headers);

        $wire = (string) $response->toWorkermanResponse();
        self::assertStringStartsWith("HTTP/1.1 304 Not Modified\r\n", $wire);
        self::assertSame('', substr($wire, (int) strpos($wire, "\r\n\r\n") + 4));
    }

    // ------------------------------------------------------------------
    // Family 4: HEAD no-body (asHeadReply + BodylessResponse)
    // ------------------------------------------------------------------

    public function testHeadReplySuppressesBodyAndPinsContentLength(): void
    {
        $rate = Application::rateLimitResponse(new RateLimitException(resetAt: 1));
        $bodyLength = strlen($rate->body);

        $head = (new Response())
            ->status(429)
            ->header('Retry-After', '0')
            ->json(['error' => 'Too Many Requests', 'code' => 'rate_limited'])
            ->asHeadReply();

        // asHeadReply(): headOnly flag + Content-Length pinned from the body
        // that WOULD have been sent (strcasecmp scan first — idempotent).
        self::assertSame((string) $bodyLength, $head->headers['Content-Length']);

        $wire = (string) $head->toWorkermanResponse();
        self::assertStringStartsWith("HTTP/1.1 429 Too Many Requests\r\n", $wire);
        self::assertStringNotContainsString('rate_limited', $wire);
        self::assertStringContainsString("Content-Length: $bodyLength\r\n", $wire);
        // Exactly ONE Content-Length reaches the wire (the narrowed
        // BodylessResponse encoder does not append a contradictory 0).
        self::assertSame(1, substr_count($wire, 'Content-Length:'));
    }

    public function testAsHeadReplyIsIdempotentAndKeepsCallerLength(): void
    {
        $response = (new Response())->json(['error' => 'x', 'code' => 'y'])->asHeadReply();
        $first = $response->headers['Content-Length'];

        $response->asHeadReply();
        self::assertSame($first, $response->headers['Content-Length']);

        // A caller-supplied length (any case) wins and survives re-marking.
        $custom = (new Response())->json(['error' => 'x', 'code' => 'y']);
        $custom->header('content-length', '42');
        $custom->asHeadReply();
        self::assertSame('42', $custom->headers['content-length']);
        self::assertArrayNotHasKey('Content-Length', $custom->headers);
    }

    // ------------------------------------------------------------------
    // Family 5: CORS-with-credentials arms (server-only capability)
    // ------------------------------------------------------------------

    private static function corsRequest(string $method, string $origin): Request
    {
        $request = new Request();
        $request->method = $method;
        // Lower-case spelling on purpose: Request::getHeader is strcasecmp.
        $request->headers = ['origin' => $origin];

        return $request;
    }

    public function testPreflightAnswerIsFullHeaderMapInOrder(): void
    {
        $manager = new CorsManager(['https://app.example.com']);
        $response = $manager->preflightResponse(self::corsRequest('OPTIONS', 'https://app.example.com'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(204, $response->statusCode);
        self::assertSame('', $response->body);
        self::assertSame(
            [
                'Access-Control-Allow-Origin' => 'https://app.example.com',
                'Access-Control-Allow-Credentials' => 'true',
                'Vary' => 'Origin',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Device-Id, X-Session-ID, '
                    . 'X-Phlix-Session-ID, X-Request-Id, X-Phlix-Device-Type, '
                    . 'X-Phlix-Device-ID, X-Phlix-Device-Name, X-Phlix-Client-Capabilities',
                'Access-Control-Max-Age' => '600',
            ],
            $response->headers,
        );
    }

    public function testPreflightPassesThroughForUnlistedOrigin(): void
    {
        $manager = new CorsManager(['https://app.example.com']);

        self::assertNull($manager->preflightResponse(self::corsRequest('OPTIONS', 'https://evil.example')));
        self::assertNull($manager->preflightResponse(new Request()));
    }

    public function testDecorateEchoesCredentialedOriginOnCanonical429(): void
    {
        $manager = new CorsManager(['https://app.example.com']);
        $response = $manager->decorate(
            self::corsRequest('GET', 'https://app.example.com'),
            Application::rateLimitResponse(new RateLimitException(resetAt: 1)),
        );

        self::assertSame(
            [
                'Retry-After' => '0',
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store',
                'Access-Control-Allow-Origin' => 'https://app.example.com',
                'Access-Control-Allow-Credentials' => 'true',
                'Vary' => 'Origin',
            ],
            $response->headers,
        );
        // The envelope body itself is untouched by decoration.
        self::assertSame(
            "{\n    \"error\": \"Too Many Requests\",\n    \"code\": \"rate_limited\"\n}",
            $response->body,
        );
    }

    public function testDecorateIsANoOpForUnlistedOrigin(): void
    {
        $manager = new CorsManager(['https://app.example.com']);
        $rate = Application::rateLimitResponse(new RateLimitException(resetAt: 1));
        $response = $manager->decorate(self::corsRequest('GET', 'https://evil.example'), $rate);

        self::assertSame(
            ['Retry-After' => '0', 'Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            $response->headers,
        );
    }

    public function testDecorateMergesIntoExistingVaryWithoutDuplicating(): void
    {
        $manager = new CorsManager(['https://app.example.com']);
        $base = (new Response())->json(['error' => 'x', 'code' => 'y']);
        $base->header('vary', 'Accept-Encoding'); // non-canonical spelling

        $response = $manager->decorate(self::corsRequest('GET', 'https://app.example.com'), $base);

        // withCorsHeaders() drops the non-canonical 'vary' spelling and rewrites
        // the canonical 'Vary' with Origin appended.
        self::assertArrayNotHasKey('vary', $response->headers);
        self::assertSame('Accept-Encoding, Origin', $response->headers['Vary']);
        self::assertSame('https://app.example.com', $response->headers['Access-Control-Allow-Origin']);
        self::assertSame('true', $response->headers['Access-Control-Allow-Credentials']);
    }
}
