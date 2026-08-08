<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use PHPUnit\Framework\TestCase;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * S113, site 6 of 6: `Router::notFound()` shipped a body on a `HEAD`.
 *
 * ## The measurement this test is built around
 *
 * `notFound()` answers with `Response::json()`, which encodes with
 * `JSON_PRETTY_PRINT` (`Response.php`), so the 404 envelope is **exactly 83 bytes**.
 * Before this step all 83 of them reached the wire on a `HEAD`, under a truthful
 * `Content-Length: 83` — the three `notFound()` call sites returned the response
 * directly instead of through `markHeadOnly()`, which is the only writer of
 * `Response::$headOnly`.
 *
 * RFC 9110 §9.3.2 forbids that body. A header-only client stops reading at the blank
 * line, so those 83 bytes stay in the socket buffer and the NEXT reply on the
 * keep-alive connection is parsed starting 83 bytes late.
 *
 * ## Why every assertion is on encoded bytes
 *
 * Following the S105 pattern ({@see \Phlix\Tests\Unit\Server\Workerman\BodylessResponseTest}):
 * `Response::$body` still holds the JSON after the fix — the suppression happens in
 * the ENCODER — so an assertion on the object's properties would pass on the broken
 * code and fail on the fixed code. Only `(string) $response->toWorkermanResponse()`
 * says what a client receives.
 *
 * ## Why the number 83 is load-bearing
 *
 * "The HEAD reply has no body" is also satisfied by a reply that lost its length, or
 * by a 404 whose envelope silently changed. Each arm therefore pins the SAME
 * `Content-Length: 83` on the `HEAD` and on the `GET`, shows 83 body bytes on the
 * GET and 0 on the HEAD, and derives the pre-fix bytes from the framework encoder as
 * a control — so a fix that answered `Content-Length: 0`, or one that emptied the
 * envelope, fails here.
 */
final class RouterHeadNotFoundTest extends TestCase
{
    /**
     * The exact JSON envelope `Router::notFound()` builds, derived the same way the
     * production code derives it rather than transcribed.
     */
    private static function envelope(): string
    {
        return (string) json_encode(
            [
                'error' => 'Not Found',
                'message' => 'The requested resource was not found',
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private static function makeRequest(string $method, string $path): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = $path;

        return $request;
    }

    /**
     * The body bytes that followed the head, i.e. what a header-only client leaves
     * buffered in the socket.
     */
    private static function bodyBytes(string $wire): string
    {
        $parts = explode("\r\n\r\n", $wire, 2);
        self::assertArrayHasKey(1, $parts, "the head was never terminated. Encoded bytes were:\n" . $wire);

        return $parts[1];
    }

    /**
     * The shared contract for a `HEAD` 404, asserted on the bytes.
     *
     * @param string $wire The encoded HEAD reply.
     * @param string $context Which of the three `notFound()` call sites produced it.
     */
    private function assertConformantHeadNotFound(string $wire, string $context): void
    {
        $envelope = self::envelope();
        self::assertSame(83, strlen($envelope), 'premise: JSON_PRETTY_PRINT makes the 404 envelope 83 bytes');

        self::assertStringStartsWith("HTTP/1.1 404 Not Found\r\n", $wire, $context . ": encoded bytes were:\n" . $wire);
        self::assertSame(
            '',
            self::bodyBytes($wire),
            $context . ': a HEAD must carry NO body (RFC 9110 §9.3.2). Encoded bytes were:' . "\n" . $wire,
        );
        self::assertStringNotContainsString(
            'The requested resource was not found',
            $wire,
            $context . ': not one byte of the envelope may reach a header-only client',
        );
        self::assertSame(
            1,
            substr_count($wire, 'Content-Length:'),
            $context . ": exactly ONE Content-Length (RFC 9110 §8.6). Encoded bytes were:\n" . $wire,
        );
        self::assertStringContainsString(
            "Content-Length: 83\r\n",
            $wire,
            $context . ': the HEAD must report the length the equivalent GET would have returned — 83, '
            . "NOT 0. Encoded bytes were:\n" . $wire,
        );
    }

    /**
     * CONTROL for every arm below: this is what the site shipped before S113, and it
     * is derived from the framework encoder rather than transcribed. It is also
     * exactly what a `GET` must still ship, which is asserted directly against a live
     * GET dispatch in {@see self::testAGetToAnUnregisteredPathStillShipsTheWholeEnvelope()}.
     */
    public function testThePreFixBytesReallyDidCarryTheEnvelopeUnderTheSameLength(): void
    {
        $envelope = self::envelope();
        $preFix = (string) new WorkermanResponse(404, ['Content-Type' => 'application/json'], $envelope);

        self::assertSame(83, strlen($envelope));
        self::assertSame(
            $envelope,
            self::bodyBytes($preFix),
            "premise: unflagged, the 404 ships all 83 body bytes. Encoded bytes were:\n" . $preFix,
        );
        self::assertStringContainsString("Content-Length: 83\r\n", $preFix, 'premise: under a truthful length');
        self::assertSame(185, strlen($preFix), 'premise: the whole pre-fix message is 185 bytes');
    }

    /**
     * Call site 1 of 3 — `dispatch()`'s "no route map for this method at all" arm.
     * Reached by a `HEAD` when NOTHING is registered for `GET` either, so the
     * `dispatchAsHead()` fallback guard cannot fire.
     */
    public function testTheNoRouteMapArmAnswersAHeadWithoutABody(): void
    {
        $router = new Router();
        $router->post('/things', static fn (): Response => (new Response())->status(201));

        $response = $router->dispatch(self::makeRequest('HEAD', '/nothing/here'));
        $wire = (string) $response->toWorkermanResponse();

        self::assertSame(404, $response->statusCode);
        self::assertTrue($response->headOnly, 'the 404 must be flagged head-only');
        $this->assertConformantHeadNotFound($wire, 'dispatch(): no route map for HEAD');

        // The response OBJECT still holds the envelope — proof that this test could
        // not have been written against Response::$body.
        self::assertSame(self::envelope(), $response->body, 'the suppression is in the encoder, not the model');
    }

    /**
     * Call site 2 of 3 — `dispatch()`'s "map exists, no pattern matched" arm. A
     * parametric `HEAD` route is registered so `$routes['HEAD']` is set, and NO `GET`
     * route exists anywhere, so the fallback guard below the loop cannot fire either.
     */
    public function testTheUnmatchedPatternArmAnswersAHeadWithoutABody(): void
    {
        $router = new Router();
        $router->match(['HEAD'], '/probe/{id}', static fn (): Response => (new Response())->status(200));

        $response = $router->dispatch(self::makeRequest('HEAD', '/probe/a/b/c'));
        $wire = (string) $response->toWorkermanResponse();

        self::assertSame(404, $response->statusCode);
        $this->assertConformantHeadNotFound($wire, 'dispatch(): HEAD map present, no pattern matched');
    }

    /**
     * Call site 3 of 3 — `dispatchAsHead()`'s own 404, i.e. the GET→HEAD fallback ran
     * and found no matching `GET` either. A `GET` route must exist (otherwise the
     * fallback is never entered) but must not match.
     */
    public function testTheGetToHeadFallbackArmAnswersAHeadWithoutABody(): void
    {
        $router = new Router();
        $router->get('/registered/{id}', static fn (): Response => (new Response())->status(200));
        $router->get('/also-static', static fn (): Response => (new Response())->status(200));

        $response = $router->dispatch(self::makeRequest('HEAD', '/no-such-path'));
        $wire = (string) $response->toWorkermanResponse();

        self::assertSame(404, $response->statusCode);
        $this->assertConformantHeadNotFound($wire, 'dispatchAsHead(): fallback found no GET route');
    }

    /**
     * DISCRIMINATING CONTROL: the fix must key on the METHOD, not on the status. A
     * `GET` to the same unregistered path still ships all 83 bytes, byte-identical to
     * the framework encoder — so this suite cannot be passed by suppressing every
     * 404 body.
     */
    public function testAGetToAnUnregisteredPathStillShipsTheWholeEnvelope(): void
    {
        $router = new Router();
        $router->get('/registered/{id}', static fn (): Response => (new Response())->status(200));

        $response = $router->dispatch(self::makeRequest('GET', '/no-such-path'));
        $wire = (string) $response->toWorkermanResponse();

        self::assertFalse($response->headOnly, 'a GET must never be flagged head-only');
        self::assertSame(
            (string) new WorkermanResponse(404, ['Content-Type' => 'application/json'], self::envelope()),
            $wire,
            "a GET 404 must be byte-identical to the framework encoder, body included. Encoded bytes were:\n" . $wire,
        );
        self::assertSame(self::envelope(), self::bodyBytes($wire), 'a GET 404 still carries all 83 bytes');
        self::assertStringContainsString("Content-Length: 83\r\n", $wire);
    }

    /**
     * The keep-alive consequence, stated as bytes rather than as prose: two `HEAD`
     * 404s pipelined on one connection. Before S113 the second reply began 83 bytes
     * into the stream from a header-only client's point of view; now the stream is
     * exactly two complete messages and nothing else.
     */
    public function testTwoPipelinedHeadNotFoundsLeaveNothingBufferedBetweenThem(): void
    {
        $router = new Router();
        $router->get('/registered/{id}', static fn (): Response => (new Response())->status(200));

        $first = (string) $router->dispatch(self::makeRequest('HEAD', '/gone-1'))->toWorkermanResponse();
        $second = (string) $router->dispatch(self::makeRequest('HEAD', '/gone-2'))->toWorkermanResponse();
        $stream = $first . $second;

        // A header-only client consumes up to and including each CRLFCRLF. If the
        // stream is exactly two heads, splitting on the terminator yields two
        // messages and an empty tail.
        $chunks = explode("\r\n\r\n", $stream);
        self::assertCount(3, $chunks, "the stream must be exactly two heads. Bytes were:\n" . $stream);
        self::assertSame('', $chunks[2], 'nothing may trail the second head');
        self::assertSame(
            strlen($first),
            strpos($stream, 'HTTP/1.1 404 Not Found', 1),
            'the second reply must start exactly where the first ended — no 83-byte desync',
        );

        // The pre-fix control: the same two replies WITH their bodies put the second
        // status line 83 bytes further along than a header-only client expects.
        $preFixOne = (string) new WorkermanResponse(404, ['Content-Type' => 'application/json'], self::envelope());
        $preFixStream = $preFixOne . $preFixOne;
        $preFixHeadEnd = strpos($preFixStream, "\r\n\r\n");
        self::assertIsInt($preFixHeadEnd);
        self::assertSame(
            83,
            (int) strpos($preFixStream, 'HTTP/1.1 404 Not Found', 1) - ($preFixHeadEnd + 4),
            'premise: before the fix, 83 stray bytes sat between the first head and the second reply',
        );
    }
}
