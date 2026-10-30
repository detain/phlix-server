<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use PHPUnit\Framework\TestCase;

/**
 * M-SERVER-52 (review server minors W-min98): a routing miss on an EXISTING
 * path answered 404, indistinguishable from a typo'd path. RFC 9110 §12.4.6
 * reserves 405 Method Not Allowed for "known resource, wrong verb" and REQUIRES
 * an `Allow` header on it; `Router::notFoundOrMethodNotAllowed()` now splits the
 * three dispatch miss sites on exactly that line.
 *
 * The envelope is pinned to the 404's shape (flat `{error, message}`, JSON_PRETTY_PRINT,
 * NO `code` key) — no new wire error codes is a frozen contract here; only the
 * status line and the `Allow` header are new on the wire.
 *
 * The 404 side of the boundary stays owned by RouterHeadNotFoundTest (its
 * unknown-path pins are paths registered under NO method, so the helper's
 * null-branch keeps answering 404 there); this file owns the 405 side and the
 * one cross-cutting head-framing arm.
 */
final class RouterMethodNotAllowedTest extends TestCase
{
    private static function makeRequest(string $method, string $path): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = $path;

        return $request;
    }

    /**
     * The exact JSON envelope the router builds for a 405, derived the same way
     * production does (same json_encode flags as Response::json()) rather than
     * transcribed, so a silent envelope change reddens the byte pins.
     */
    private static function envelope(string $method, string $allow): string
    {
        return (string) json_encode(
            [
                'error' => 'Method Not Allowed',
                'message' => 'The requested resource does not support ' . $method . ' (allowed: ' . $allow . ')',
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private static function dispatch(Router $router, string $method, string $path): Response
    {
        return $router->dispatch(self::makeRequest($method, $path));
    }

    public function testAWrongMethodOnAStaticPathAnswers405WithAnAllowHeader(): void
    {
        $router = new Router();
        $router->get('/media/items', static fn (): Response => (new Response())->status(200));

        $response = self::dispatch($router, 'DELETE', '/media/items');

        self::assertSame(405, $response->statusCode);
                self::assertSame(
                    'GET, HEAD',
                    $response->headers['Allow'] ?? '(missing)',
                    'GET-only registration allows HEAD too — dispatchAsHead really serves it',
                );
        self::assertSame(self::envelope('DELETE', 'GET, HEAD'), (string) $response->body);
    }

    public function testAWrongMethodOnAParametricPathAnswers405WithAnAllowHeader(): void
    {
        $router = new Router();
        $router->post('/libraries/{libraryId}/scan', static fn (): Response => (new Response())->status(202));

        $response = self::dispatch($router, 'GET', '/libraries/abc-123/scan');

        self::assertSame(405, $response->statusCode);
                self::assertSame(
                    'POST',
                    $response->headers['Allow'] ?? '(missing)',
                    'POST-only registration: no GET means no auto-HEAD either',
                );
        self::assertSame(self::envelope('GET', 'POST'), (string) $response->body);
    }

    public function testAPathRegisteredUnderNoMethodAnywhereStillAnswersThePlain404(): void
    {
        $router = new Router();
        $router->get('/exists/{id}', static fn (): Response => (new Response())->status(200));

        $response = self::dispatch($router, 'PUT', '/never-registered-anywhere');

                self::assertSame(
                    404,
                    $response->statusCode,
                    '405 is for known paths only; an unknown path must not become a method complaint',
                );
        self::assertArrayNotHasKey('Allow', $response->headers);
        self::assertSame((string) json_encode(
            ['error' => 'Not Found', 'message' => 'The requested resource was not found'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ), (string) $response->body);
    }

    public function testTheAllowHeaderListsEveryMethodClaimingThePathInCanonicalOrder(): void
    {
        $router = new Router();
        // Registered in scrambled order on purpose: the header must be the
        // canonical verb order, not an accident of registration order.
        $router->delete('/reports/{id}', static fn (): Response => (new Response())->status(200));
        $router->post('/reports/{id}', static fn (): Response => (new Response())->status(201));
        $router->patch('/reports/{id}', static fn (): Response => (new Response())->status(200));

        $response = self::dispatch($router, 'PUT', '/reports/7');

        self::assertSame(405, $response->statusCode);
        self::assertSame('POST, PATCH, DELETE', $response->headers['Allow'] ?? '(missing)');
    }

    public function testTheEnvelopeCarriesExactlyErrorAndMessageAndNoCodeKey(): void
    {
        $router = new Router();
        $router->get('/status', static fn (): Response => (new Response())->status(200));

        $response = self::dispatch($router, 'POST', '/status');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->body, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame(
                    ['error', 'message'],
                    array_keys($decoded),
                    'flat legacy-style envelope — NO contract code key (error codes are registry-frozen; adding one here would be a wire break)',
                );
        self::assertSame('Method Not Allowed', $decoded['error']);
        self::assertSame('application/json', $response->headers['Content-Type'] ?? '(missing)');
    }

    public function testAnyRegisteredRouteListsOptionsInAllowBecauseOptionsWasReallyRegistered(): void
    {
        $router = new Router();
        $router->any('/health', static fn (): Response => (new Response())->status(200));

        $response = self::dispatch($router, 'TRACE', '/health');

        self::assertSame(405, $response->statusCode);
        self::assertSame(
            'GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS',
            $response->headers['Allow'] ?? '(missing)',
            'any() registers OPTIONS outright and GET pulls HEAD in via the fallback — Allow states what works',
        );
    }

    public function testABareOptionsRequestOnAGetOnlyPathIsNow405Not404(): void
    {
        $router = new Router();
        $router->get('/media', static fn (): Response => (new Response())->status(200));

        $response = self::dispatch($router, 'OPTIONS', '/media');

                self::assertSame(
                    405,
                    $response->statusCode,
                    'a bare OPTIONS reaching the router (preflight is intercepted upstream in Workerman/HttpHandler, before Router) answers 405 on an existing path — RFC-correct; this is a deliberate 404-to-405 behavior change on a path that exists',
                );
                self::assertSame(
                    'GET, HEAD',
                    $response->headers['Allow'] ?? '(missing)',
                    'OPTIONS is not auto-added to Allow — it must be registered to be claimed',
                );
    }

    public function testAHeadRequestToAPostOnlyPathAnswersAHeadOnly405(): void
    {
        $router = new Router();
        $router->post('/feedback', static fn (): Response => (new Response())->status(201));

        $response = self::dispatch($router, 'HEAD', '/feedback');

                self::assertSame(
                    405,
                    $response->statusCode,
                    'HEAD went through the GET-fallback, found nothing GET, and the path exists under POST — 405 Allow: POST, not 404',
                );
        self::assertSame('POST', $response->headers['Allow'] ?? '(missing)');
                self::assertTrue(
                    $response->headOnly,
                    'the head-only framing guarantee (S113) covers the 405 envelope too — it is returned through markHeadOnly() like the 404',
                );

        $wire = (string) $response->toWorkermanResponse();
        self::assertStringStartsWith("HTTP/1.1 405 Method Not Allowed\r\n", $wire);
        self::assertStringContainsString("Allow: POST\r\n", $wire);
                self::assertSame(
                    '',
                    explode("\r\n\r\n", $wire, 2)[1] ?? 'no-separator',
                    'a HEAD never ships the envelope body',
                );
        self::assertStringContainsString(
            'Content-Length: ' . strlen(self::envelope('HEAD', 'POST')) . "\r\n",
            $wire,
            'the reply declares the length the equivalent GET would have returned — the S113 derivation applies to the 405 envelope too',
        );
    }

    public function testTheWrongMethodProbesBothMapsTogetherForOnePath(): void
    {
        $router = new Router();
        // Same path registered statically under GET and parametrically-impossible;
        // a sibling parametric pattern under POST claims a DIFFERENT concrete path,
        // so each concrete path gets its own honest Allow list.
        $router->get('/items', static fn (): Response => (new Response())->status(200));
        $router->post('/items/{id}', static fn (): Response => (new Response())->status(201));

        $parametricMiss = self::dispatch($router, 'GET', '/items/xyz');
        self::assertSame(405, $parametricMiss->statusCode, 'the parametric POST map claims /items/xyz via its pattern');
                self::assertSame(
                    'POST',
                    $parametricMiss->headers['Allow'] ?? '(missing)',
                    'GET does not claim this concrete path — no auto-HEAD',
                );

        $sibling = self::dispatch($router, 'DELETE', '/items');
        self::assertSame(405, $sibling->statusCode);
                self::assertSame(
                    'GET, HEAD',
                    $sibling->headers['Allow'] ?? '(missing)',
                    'the static map claims /items; the parametric pattern cannot match it',
                );
    }
}
