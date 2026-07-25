<?php

namespace Phlix\Tests\Unit\Server\Http;

use PHPUnit\Framework\TestCase;
use Phlix\Server\Http\Router;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Tests\Unit\Server\Http\Fixtures\RouterFixtureController;
use Psr\Container\ContainerInterface;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * Unit tests for Router class.
 *
 * @covers \Phlix\Server\Http\Router
 */
class RouterTest extends TestCase
{
    /** @var Router Router instance under test */
    private Router $router;

    /**
     * Set up router for each test.
     */
    protected function setUp(): void
    {
        $this->router = new Router();
    }

    /**
     * @covers \Phlix\Server\Http\Router::get
     * @covers \Phlix\Server\Http\Router::getRoutes
     */
    public function testCanRegisterGetRoute(): void
    {
        $this->router->get('/test', function ($req) {
            return (new Response())->json(['ok' => true]);
        });

        $routes = $this->router->getRoutes();

        $this->assertArrayHasKey('GET', $routes);
    }

    /**
     * @covers \Phlix\Server\Http\Router::post
     * @covers \Phlix\Server\Http\Router::put
     * @covers \Phlix\Server\Http\Router::delete
     */
    public function testCanRegisterMultipleHttpMethods(): void
    {
        $this->router->post('/test', fn() => new Response());
        $this->router->put('/test', fn() => new Response());
        $this->router->delete('/test', fn() => new Response());

        $routes = $this->router->getRoutes();

        $this->assertArrayHasKey('POST', $routes);
        $this->assertArrayHasKey('PUT', $routes);
        $this->assertArrayHasKey('DELETE', $routes);
    }

    /**
     * @covers \Phlix\Server\Http\Router::get
     * @covers \Phlix\Server\Http\Router::dispatch
     */
    public function testCanUsePathParameters(): void
    {
        $this->router->get('/users/{id}', function ($req, $params) {
            return (new Response())->json($params);
        });

        // Create a mock request
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/users/123';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $request = Request::fromGlobals();
        $response = $this->router->dispatch($request);

        $this->assertEquals(200, $response->statusCode);
    }

    /**
     * @covers \Phlix\Server\Http\Router::dispatch
     */
    public function testReturns404ForUnknownRoute(): void
    {
        $this->router->get('/exists', fn() => new Response());

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/unknown';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $request = Request::fromGlobals();
        $response = $this->router->dispatch($request);

        $this->assertEquals(404, $response->statusCode);
    }

    /**
     * SV-3.1 f-c route-ordering guard: the timeshift `.../stream` playlist route
     * and the `.../{segment}` route are BOTH parametric (they carry {sessionId}),
     * so they are matched by regex in registration order — not via the O(1)
     * static-route map. Registering `.../stream` FIRST must make a `.../stream`
     * request hit the playlist handler, and a segment name fall through to the
     * segment handler. Guards against a future re-ordering regression.
     *
     * @covers \Phlix\Server\Http\Router::dispatch
     */
    public function testTimeshiftStreamRouteDispatchesToPlaylistBeforeSegment(): void
    {
        $hit = null;
        // Registration order mirrors Application::loadStreamingRoutes(): stream first.
        $this->router->get('/livetv/timeshift/{sessionId}/stream', function ($req, $params) use (&$hit) {
            $hit = 'playlist:' . ($params['sessionId'] ?? '');
            return new Response();
        });
        $this->router->get('/livetv/timeshift/{sessionId}/{segment}', function ($req, $params) use (&$hit) {
            $hit = 'segment:' . ($params['sessionId'] ?? '') . ':' . ($params['segment'] ?? '');
            return new Response();
        });

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/livetv/timeshift/sess-1/stream';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->router->dispatch(Request::fromGlobals());

        $this->assertSame('playlist:sess-1', $hit, '.../stream must match the playlist handler');
    }

    /**
     * The companion assertion: a real segment name (which does not end in /stream)
     * falls through to the segment handler with both params captured.
     *
     * @covers \Phlix\Server\Http\Router::dispatch
     */
    public function testTimeshiftSegmentRouteDispatchesToSegmentHandler(): void
    {
        $hit = null;
        $this->router->get('/livetv/timeshift/{sessionId}/stream', function ($req, $params) use (&$hit) {
            $hit = 'playlist';
            return new Response();
        });
        $this->router->get('/livetv/timeshift/{sessionId}/{segment}', function ($req, $params) use (&$hit) {
            $hit = 'segment:' . ($params['sessionId'] ?? '') . ':' . ($params['segment'] ?? '');
            return new Response();
        });

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/livetv/timeshift/sess-1/seg_00001.ts';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->router->dispatch(Request::fromGlobals());

        $this->assertSame('segment:sess-1:seg_00001.ts', $hit, 'a segment name must match the segment handler');
    }

    /**
     * Builds a bare Request without touching superglobals — the new SV-4.8
     * tests only need method + path, and this keeps global state clean.
     */
    private function makeRequest(string $method, string $path): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = $path;

        return $request;
    }

    /**
     * SV-4.8 DI branch: when a PSR-11 container is supplied, a string
     * `[Controller::class, 'method']` handler is resolved via
     * `$container->get($class)` (enabling constructor injection) rather than
     * `new $class()`. The container is asserted to be consulted exactly once
     * with the class name, and the instance IT returns is the one invoked.
     *
     * @covers \Phlix\Server\Http\Router::__construct
     * @covers \Phlix\Server\Http\Router::dispatch
     */
    public function testStringHandlerResolvedViaContainer(): void
    {
        $spy = new RouterFixtureController();

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('get')
            ->with(RouterFixtureController::class)
            ->willReturn($spy);

        $router = new Router($container);
        $router->get('/di', [RouterFixtureController::class, 'handle']);

        $response = $router->dispatch($this->makeRequest('GET', '/di'));

        $this->assertTrue($spy->handled, 'the container-returned instance must be the one invoked');
        $this->assertSame(200, $response->statusCode);
        /** @var array{from?: string} $body */
        $body = json_decode($response->body, true);
        $this->assertSame('RouterFixtureController', $body['from'] ?? null);
    }

    /**
     * SV-4.8 fallback branch: with NO container, a string handler is
     * instantiated directly via `new $class()`. Because no container exists,
     * the marked Response can only come from that direct instantiation.
     *
     * @covers \Phlix\Server\Http\Router::dispatch
     */
    public function testStringHandlerFallsBackToDirectInstantiation(): void
    {
        // Default setUp() router has no container.
        $this->router->get('/plain', [RouterFixtureController::class, 'handle']);

        $response = $this->router->dispatch($this->makeRequest('GET', '/plain'));

        $this->assertSame(200, $response->statusCode);
        /** @var array{from?: string} $body */
        $body = json_decode($response->body, true);
        $this->assertSame(
            'RouterFixtureController',
            $body['from'] ?? null,
            'without a container the handler must be instantiated via new $class()',
        );
    }

    /**
     * SV-4.8 static-map fast path: a static route is served from the O(1)
     * `$staticRoutes` map BEFORE the parametric regex loop is consulted. A
     * `{slug}` route that would also match `/fast` is registered alongside;
     * its closure must never run, proving the regex loop is skipped on a
     * static hit (independent of registration order).
     *
     * @covers \Phlix\Server\Http\Router::dispatch
     */
    public function testStaticRouteServedBeforeParametricMatcher(): void
    {
        $paramHit = false;
        // Register the parametric catch-all FIRST; if the static map were not
        // consulted first, this would win for `/fast`.
        $this->router->get('/{slug}', function ($req, $params) use (&$paramHit) {
            $paramHit = true;
            return (new Response())->status(200)->json(['matched' => 'param']);
        });
        $this->router->get('/fast', function ($req, $params) {
            return (new Response())->status(200)->json(['matched' => 'static']);
        });

        $response = $this->router->dispatch($this->makeRequest('GET', '/fast'));

        $this->assertFalse($paramHit, 'the parametric matcher must not be consulted for a static hit');
        /** @var array{matched?: string} $body */
        $body = json_decode($response->body, true);
        $this->assertSame('static', $body['matched'] ?? null);
    }

    /**
     * SV-4.8 HEAD via static map: a HEAD request to a GET-only STATIC route
     * resolves through dispatchAsHead()'s O(1) static lookup, running the GET
     * handler with body suppression (headOnly = true). A parametric GET route
     * is also present so the HEAD→GET fallback guard (isset routes['GET']) is
     * satisfied — mirroring production, where parametric GET routes always exist.
     *
     * @covers \Phlix\Server\Http\Router::dispatch
     */
    public function testHeadRequestResolvesStaticGetRouteViaHeadFallback(): void
    {
        // Parametric GET route ensures $this->routes['GET'] is populated so the
        // HEAD fallback branch is entered; the static route lives in $staticRoutes.
        $this->router->get('/items/{id}', fn($req, $params) => (new Response())->json(['p' => $params]));
        $this->router->get('/health', fn($req, $params) => (new Response())->status(200)->json(['ok' => true]));

        $response = $this->router->dispatch($this->makeRequest('HEAD', '/health'));

        $this->assertTrue($response->headOnly, 'HEAD dispatch must flag the response head-only');
        $this->assertSame(200, $response->statusCode);
        /** @var array{ok?: bool} $body */
        $body = json_decode($response->body, true);
        $this->assertTrue($body['ok'] ?? false, 'the GET /health handler must be the one that ran');
    }

    /**
     * HEAD hardening (flagged during SV-4.8): a GET path registered ONLY as a
     * static route, with ZERO parametric GET routes, must still resolve a HEAD
     * request via dispatchAsHead()'s static branch — NOT 404. Previously the
     * fallback guard checked only isset($this->routes['GET']) (the parametric
     * map), which is unset here, so this HEAD would have 404'd. The broadened
     * guard also consults $staticRoutes['GET']. This is the static-only edge
     * that testHeadRequestResolvesStaticGetRouteViaHeadFallback does NOT cover
     * (that test also registers a parametric GET route).
     *
     * @covers \Phlix\Server\Http\Router::dispatch
     * @covers \Phlix\Server\Http\Router::dispatchAsHead
     */
    public function testHeadResolvesStaticOnlyGetRouteWithNoParametricGetRoutes(): void
    {
        // Register a GET route that is PURELY static (no {param}) — this lands
        // ONLY in $staticRoutes['GET'] and leaves $this->routes['GET'] unset.
        $this->router->get('/ping', fn($req, $params) => (new Response())->status(200)->json(['pong' => true]));

        $response = $this->router->dispatch($this->makeRequest('HEAD', '/ping'));

        $this->assertSame(200, $response->statusCode, 'a HEAD to a static-only GET route must resolve, not 404');
        $this->assertTrue($response->headOnly, 'the HEAD fallback must flag the response head-only');
        /** @var array{pong?: bool} $body */
        $body = json_decode($response->body, true);
        $this->assertTrue($body['pong'] ?? false, 'the GET /ping handler must be the one that ran');
    }

    /**
     * HEAD hardening companion: the broadened guard must not change the normal
     * case. With BOTH a parametric and a static GET route present, a HEAD to a
     * path that matches NO GET route (neither map) still 404s — the fallback is
     * entered (a GET route exists) but dispatchAsHead() finds no match.
     *
     * @covers \Phlix\Server\Http\Router::dispatch
     * @covers \Phlix\Server\Http\Router::dispatchAsHead
     */
    public function testHeadStillReturns404WhenNoGetRouteMatchesPath(): void
    {
        $this->router->get('/users/{id}', fn($req, $params) => (new Response())->json(['p' => $params]));
        $this->router->get('/health', fn($req, $params) => (new Response())->status(200)->json(['ok' => true]));

        $response = $this->router->dispatch($this->makeRequest('HEAD', '/nope'));

        $this->assertSame(404, $response->statusCode, 'HEAD to an unregistered path must still 404');
    }

    /**
     * THE TRAP, executed (S105 / S52 post-merge finding 1): a route registered for
     * `HEAD` **in its own right** whose handler declares the real `Content-Length`
     * and never touches `headOnly` must STILL put exactly one `Content-Length` on
     * the wire.
     *
     * ## Why this test exists
     *
     * The two-`Content-Length` defect (`Content-Length: 123456789` … then
     * Workerman's own `Content-Length: 0`, invalid per RFC 9110 §8.6) is selected
     * away by `Response::toWorkermanResponse()` on `Response::$headOnly`. That flag
     * used to be set ONLY by `Router::dispatchAsHead()`, i.e. only on the GET→HEAD
     * *fallback*, so a `match(['GET', 'HEAD'], …)` route — the exact registration the
     * DLNA stream route uses — depended on its handler remembering the flag by hand.
     * A handler that forgot shipped the original defect verbatim with the whole suite
     * green. `Router::markHeadOnly()` now makes it structural, and this is its pin:
     * the handler below deliberately does NOT set the flag.
     *
     * ## Why the assertion is on the encoded bytes
     *
     * `Response::$headers` cannot observe this defect at all — Workerman appends its
     * generated length inside the encoder, not into the array — and asserting on the
     * header array is precisely the mistake that let this defect ship twice. So this
     * asserts `(string) $response->toWorkermanResponse()`, and a CONTROL renders the
     * same shape through the framework encoder to show the defect is real.
     *
     * @covers \Phlix\Server\Http\Router::dispatch
     * @covers \Phlix\Server\Http\Router::markHeadOnly
     */
    public function testAHeadRegisteredRouteThatForgetsTheFlagStillPutsOneContentLengthOnTheWire(): void
    {
        $headers = [
            'Content-Type' => 'video/mp4',
            'Accept-Ranges' => 'bytes',
            'Content-Length' => '123456789',
        ];

        // Registered exactly like Application::loadCdsRoutes()' stream route, and the
        // HEAD arm sets NO headOnly flag — that is the whole point of the test.
        $this->router->match(['GET', 'HEAD'], '/trap/stream/{id}', function ($req, $params) use ($headers) {
            if ($req->method === 'HEAD') {
                $head = (new Response())->status(200);
                foreach ($headers as $name => $value) {
                    $head->header($name, $value);
                }
                return $head;
            }

            return (new Response())->status(200)->header('Content-Type', 'video/mp4')->body('PAYLOAD');
        });

        $response = $this->router->dispatch($this->makeRequest('HEAD', '/trap/stream/abc'));

        // The WIRE assertion comes first on purpose: it is the property that
        // matters, and it must be the one that names the failure if the guarantee
        // is ever removed.
        $wire = (string) $response->toWorkermanResponse();

        $this->assertSame(
            1,
            substr_count($wire, 'Content-Length:'),
            "A HEAD reply must carry exactly ONE Content-Length. Encoded bytes were:\n" . $wire,
        );
        $this->assertStringContainsString("HTTP/1.1 200 OK\r\n", $wire);
        $this->assertStringContainsString("Content-Length: 123456789\r\n", $wire);
        $this->assertStringNotContainsString('Content-Length: 0', $wire);
        $this->assertStringContainsString("Accept-Ranges: bytes\r\n", $wire);

        // …and no body after the header terminator (RFC 9110 §9.3.2).
        $parts = explode("\r\n\r\n", $wire, 2);
        $this->assertSame('', $parts[1] ?? 'HEADER TERMINATOR MISSING', 'A HEAD reply must have no body');

        // The mechanism, asserted after the outcome.
        $this->assertTrue(
            $response->headOnly,
            'the ROUTER must flag a HEAD-registered route head-only — not the handler',
        );

        // CONTROL — the same status/headers/body through the FRAMEWORK encoder is
        // the defect: two contradictory lengths with the bogus 0 LAST. Without it
        // this test could pass against an encoder that emitted nothing at all.
        $unfixed = (string) new WorkermanResponse(200, $headers, '');
        $this->assertSame(
            2,
            substr_count($unfixed, 'Content-Length:'),
            'premise: the unflagged framework encoder really does emit two lengths',
        );
        $this->assertStringContainsString("Content-Length: 0\r\n", $unfixed);
    }

    /**
     * Same guarantee on the O(1) STATIC map: a HEAD-registered path with no
     * `{param}` lands in `$staticRoutes['HEAD']`, which is a different arm of
     * `dispatch()` (and therefore a separate `markHeadOnly()` call site).
     *
     * @covers \Phlix\Server\Http\Router::dispatch
     * @covers \Phlix\Server\Http\Router::markHeadOnly
     */
    public function testAStaticHeadRegisteredRouteIsFlaggedByTheRouterToo(): void
    {
        $this->router->match(['GET', 'HEAD'], '/trap/static', function ($req, $params) {
            return (new Response())
                ->status(200)
                ->header('Content-Type', 'audio/mpeg')
                ->header('Content-Length', '4242');
        });

        $response = $this->router->dispatch($this->makeRequest('HEAD', '/trap/static'));

        $wire = (string) $response->toWorkermanResponse();
        $this->assertSame(
            1,
            substr_count($wire, 'Content-Length:'),
            "A HEAD reply on the static arm must carry exactly ONE Content-Length. Encoded bytes were:\n" . $wire,
        );
        $this->assertStringContainsString("Content-Length: 4242\r\n", $wire);
        $this->assertStringNotContainsString('Content-Length: 0', $wire);
        $this->assertTrue($response->headOnly, 'the static arm must flag a HEAD reply head-only');
    }

    /**
     * A middleware SHORT-CIRCUIT on a HEAD-registered route is flagged as well —
     * both short-circuit arms of `dispatch()` route through `markHeadOnly()`, so a
     * gate's own reply cannot ship a body on a HEAD either.
     *
     * @covers \Phlix\Server\Http\Router::dispatch
     * @covers \Phlix\Server\Http\Router::markHeadOnly
     */
    public function testAMiddlewareShortCircuitOnAHeadRouteIsFlaggedHeadOnly(): void
    {
        $gate = fn($req) => (new Response())->status(403)->json(['error' => 'not on the allowlist']);

        $this->router->group('/gated', function (Router $r): void {
            $r->match(['GET', 'HEAD'], '/stream/{id}', fn($req, $params) => (new Response())->status(200));
            $r->get('/plain', fn($req, $params) => (new Response())->status(200));
        }, [$gate]);

        $parametric = $this->router->dispatch($this->makeRequest('HEAD', '/gated/stream/abc'));
        $this->assertSame(403, $parametric->statusCode);
        $this->assertTrue($parametric->headOnly, 'a parametric middleware short-circuit must flag HEAD replies');

        // The static arm's short-circuit, reached via the GET→HEAD fallback.
        $static = $this->router->dispatch($this->makeRequest('HEAD', '/gated/plain'));
        $this->assertSame(403, $static->statusCode);
        $this->assertTrue($static->headOnly, 'a static middleware short-circuit must flag HEAD replies');
    }

    /**
     * THE UNPINNED FALLBACK ARM, executed (S105 review r1, finding MED-1):
     * `dispatchAsHead()`'s PARAMETRIC handler return — the live GET→HEAD path for
     * every parametric GET route reached by a `HEAD` (`/api/v1/media/{id}`,
     * `/hls/{job}/{seg}`, `/stream/theme-media/{libraryId}/audio`, …) — was pinned
     * by NOTHING: r1 deleted its `markHeadOnly()` call and the whole 6,984-test
     * suite stayed green while a `HEAD` began shipping
     * `Content-Length: 4242 … Content-Length: 4242` **plus 4,242 body bytes**
     * (4,377 B on the wire instead of 113 B) — i.e. the exact two-`Content-Length`
     * defect this step exists to make structurally impossible, surviving inside the
     * step itself.
     *
     * The route below is shaped exactly like
     * `ThemeMusicStreamController::streamAudio()`: registered for **GET only**, so a
     * `HEAD` can reach it ONLY through `dispatch()`'s fallback guard; **parametric**,
     * so it misses `dispatchAsHead()`'s O(1) static lookup and lands on the handler
     * arm; and its handler declares the real `Content-Length` **and** returns the
     * buffered body while never touching `headOnly`.
     *
     * @covers \Phlix\Server\Http\Router::dispatch
     * @covers \Phlix\Server\Http\Router::dispatchAsHead
     * @covers \Phlix\Server\Http\Router::markHeadOnly
     */
    public function testTheGetToHeadFallbackFlagsAParametricRouteHandlerOnTheWire(): void
    {
        $body = str_repeat('A', 4242);
        $headers = [
            'Content-Type' => 'audio/mpeg',
            'Accept-Ranges' => 'bytes',
            'Content-Length' => '4242',
        ];

        $this->router->get('/stream/theme-media/{libraryId}/audio', function ($req, $params) use ($headers, $body) {
            $response = (new Response())->status(200);
            foreach ($headers as $name => $value) {
                $response->header($name, $value);
            }

            return $response->body($body);
        });

        $response = $this->router->dispatch($this->makeRequest('HEAD', '/stream/theme-media/lib-1/audio'));

        // Asserted as WHOLE BYTES, deliberately literal rather than derived: these
        // are the bytes measured on the fixed router, and a dependency bump that
        // moves them is a framing change that must be looked at, not absorbed.
        $wire = (string) $response->toWorkermanResponse();
        $this->assertSame(
            "HTTP/1.1 200 OK\r\n"
            . "Content-Type: audio/mpeg\r\n"
            . "Accept-Ranges: bytes\r\n"
            . "Content-Length: 4242\r\n"
            . "Connection: keep-alive\r\n"
            . "\r\n",
            $wire,
            "the GET->HEAD fallback must render a parametric route head-only. Encoded bytes were:\n" . $wire,
        );
        $this->assertSame(113, strlen($wire), 'a correct HEAD reply for this shape is 113 bytes');
        $this->assertSame(1, substr_count($wire, 'Content-Length:'), 'exactly ONE Content-Length (RFC 9110 §8.6)');
        $this->assertSame('', explode("\r\n\r\n", $wire, 2)[1] ?? 'TERMINATOR MISSING', 'a HEAD carries no body');
        $this->assertTrue($response->headOnly, 'the fallback arm must flag the reply head-only');

        // CONTROL — the same shape unflagged is exactly what r1 measured with this
        // call site deleted: 4,377 B, two Content-Length fields, and the whole body.
        $unflagged = (string) new WorkermanResponse(200, $headers, $body);
        $this->assertSame(4377, strlen($unflagged), 'premise: unflagged, this shape is 4377 bytes');
        $this->assertSame(2, substr_count($unflagged, 'Content-Length:'), 'premise: two lengths reach the wire');
        $this->assertStringEndsWith($body, $unflagged, 'premise: unflagged, the body ships on a HEAD');
    }

    /**
     * Same finding, the fallback's OTHER unpinned arm (S105 review r1, MED-1):
     * a middleware SHORT-CIRCUIT on the parametric GET→HEAD fallback
     * (`dispatchAsHead()`'s first parametric return). Deleting its
     * `markHeadOnly()` call also left the full suite green — together with the
     * handler arm above, the ENTIRE parametric fallback arm was untested.
     *
     * The gate is shaped like `DlnaAllowlistMiddleware`: a 403 JSON refusal that
     * declares no `Content-Length` of its own, so the flag's whole effect is
     * dropping the body (and letting Workerman state `Content-Length: 0`) — which
     * is what RFC 9110 §9.3.2 requires of a `HEAD` reply.
     *
     * @covers \Phlix\Server\Http\Router::dispatch
     * @covers \Phlix\Server\Http\Router::dispatchAsHead
     * @covers \Phlix\Server\Http\Router::markHeadOnly
     */
    public function testTheGetToHeadFallbackFlagsAParametricMiddlewareShortCircuitOnTheWire(): void
    {
        $payload = [
            'error' => 'DLNA access is not permitted from this network address.',
            'code'  => 'dlna.forbidden',
        ];
        $gate = fn($req) => (new Response())->status(403)->json($payload);

        // GET-ONLY registration inside a gated group: no HEAD route exists at all,
        // so the HEAD below can only arrive via dispatch()'s fallback guard.
        $this->router->group('/dlna', function (Router $r): void {
            $r->get('/stream/{id}', fn($req, $params) => (new Response())->status(200));
        }, [$gate]);

        $response = $this->router->dispatch($this->makeRequest('HEAD', '/dlna/stream/abc123'));

        $wire = (string) $response->toWorkermanResponse();
        $json = (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // A refusal that set no length of its own is rendered by the FRAMEWORK
        // encoder with an EMPTY body, so the expectation is derived from Workerman
        // itself — the property is "same reply, no body".
        $this->assertSame(
            (string) new WorkermanResponse(403, ['Content-Type' => 'application/json'], ''),
            $wire,
            "a gated HEAD must ship the refusal head-only. Encoded bytes were:\n" . $wire,
        );
        $this->assertSame(1, substr_count($wire, 'Content-Length:'), 'exactly ONE Content-Length');
        $this->assertStringNotContainsString('dlna.forbidden', $wire, 'the JSON body must not reach a HEAD client');
        $this->assertSame(403, $response->statusCode);
        $this->assertTrue($response->headOnly, 'the fallback middleware arm must flag the reply head-only');

        // CONTROL — unflagged, the same refusal ships its whole body.
        $unflagged = (string) new WorkermanResponse(403, ['Content-Type' => 'application/json'], $json);
        $this->assertStringEndsWith($json, $unflagged, 'premise: unflagged, the refusal body ships on a HEAD');
        $this->assertGreaterThan(strlen($wire), strlen($unflagged), 'premise: the flag is what removes the body');
    }

    /**
     * The third unpinned site (S105 review r1, MED-1) and the only one NEW in this
     * step: `dispatch()`'s **static** middleware short-circuit. A HEAD-registered
     * path with no `{param}` behind a short-circuiting gate returns from
     * `$staticRoutes['HEAD']`, an arm the existing short-circuit test never reaches
     * (its static case arrives through the GET→HEAD fallback instead, which is a
     * different `markHeadOnly()` call).
     *
     * @covers \Phlix\Server\Http\Router::dispatch
     * @covers \Phlix\Server\Http\Router::markHeadOnly
     */
    public function testAStaticHeadRegisteredRouteBehindAGateIsFlaggedOnTheWire(): void
    {
        $payload = ['error' => 'DLNA access is not permitted from this network address.'];
        $gate = fn($req) => (new Response())->status(403)->json($payload);

        // Registered for HEAD IN ITS OWN RIGHT and static, so $staticRoutes['HEAD']
        // is hit directly and the gate short-circuits inside dispatch().
        $this->router->group('/dlna', function (Router $r): void {
            $r->match(['GET', 'HEAD'], '/control', fn($req, $params) => (new Response())->status(200));
        }, [$gate]);

        $response = $this->router->dispatch($this->makeRequest('HEAD', '/dlna/control'));

        $wire = (string) $response->toWorkermanResponse();
        $this->assertSame(
            (string) new WorkermanResponse(403, ['Content-Type' => 'application/json'], ''),
            $wire,
            "the STATIC short-circuit arm must ship the refusal head-only. Encoded bytes were:\n" . $wire,
        );
        $this->assertSame(1, substr_count($wire, 'Content-Length:'), 'exactly ONE Content-Length');
        $this->assertStringNotContainsString('not permitted', $wire, 'the JSON body must not reach a HEAD client');
        $this->assertSame(403, $response->statusCode);
        $this->assertTrue($response->headOnly, 'the static short-circuit arm must flag the reply head-only');

        // The same route on a GET keeps the framework encoder byte for byte, so this
        // test cannot pass by flagging everything.
        $get = $this->router->dispatch($this->makeRequest('GET', '/dlna/control'));
        $json = (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->assertFalse($get->headOnly, 'a gated GET must never be flagged head-only');
        $this->assertSame(
            (string) new WorkermanResponse(403, ['Content-Type' => 'application/json'], $json),
            (string) $get->toWorkermanResponse(),
            'a gated GET must be byte-identical to the framework encoder, body included',
        );
    }

    /**
     * The other half of the invariant: `markHeadOnly()` must NEVER flag a
     * non-HEAD reply. A GET whose handler declared a stale non-zero
     * `Content-Length` and produced an empty body must keep the FRAMEWORK encoder,
     * because treating that length as authoritative on a GET is a keep-alive
     * framing desync rather than a fix (see `Response::toWorkermanResponse()`).
     *
     * DISCRIMINATING: drop the `$request->method === 'HEAD'` test in
     * `markHeadOnly()` and this goes red on both the flag and the wire bytes.
     *
     * @covers \Phlix\Server\Http\Router::dispatch
     * @covers \Phlix\Server\Http\Router::markHeadOnly
     */
    public function testAGetIsNeverFlaggedHeadOnlyEvenWhenItsBodyCameOutEmpty(): void
    {
        // Models ThemeMusicStreamController: length from a filesize() taken before
        // the read, then the read yields '' because the file was truncated.
        $this->router->match(['GET', 'HEAD'], '/theme/{id}', fn($req, $params) => (new Response())
            ->status(200)
            ->header('Content-Type', 'audio/mpeg')
            ->header('Content-Length', '4242')
            ->body(''));

        foreach (['GET', 'POST'] as $method) {
            $this->router->match([$method], '/verb/' . strtolower($method), fn($req, $params) => (new Response())
                ->status(200)
                ->header('Content-Length', '4242'));
        }

        $get = $this->router->dispatch($this->makeRequest('GET', '/theme/abc'));

        // Wire bytes first, as in the HEAD tests above: the byte-for-byte parity
        // with the framework encoder IS the property, and it is the one that must
        // name the failure. Expected bytes are DERIVED from Workerman's own encoder
        // so a dependency bump cannot make this pass vacuously.
        $wire = (string) $get->toWorkermanResponse();
        $this->assertSame(
            (string) new WorkermanResponse(200, ['Content-Type' => 'audio/mpeg', 'Content-Length' => '4242'], ''),
            $wire,
            'a GET must be byte-identical to the framework encoder',
        );
        $this->assertSame(
            2,
            substr_count($wire, 'Content-Length:'),
            "a GET keeps the framework encoder byte for byte, including its appended length:\n" . $wire,
        );
        $this->assertFalse($get->headOnly, 'a GET must never be flagged head-only');

        $this->assertFalse($this->router->dispatch($this->makeRequest('GET', '/verb/get'))->headOnly);
        $this->assertFalse($this->router->dispatch($this->makeRequest('POST', '/verb/post'))->headOnly);
    }

    /**
     * SV-4.8 guard: a resolved array/DI string handler whose method returns a
     * non-Response value triggers BadMethodCallException. This covers the
     * is_array()+container path of callHandler(), previously untested.
     *
     * @covers \Phlix\Server\Http\Router::dispatch
     */
    public function testResolvedHandlerReturningNonResponseThrows(): void
    {
        $spy = new RouterFixtureController();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with(RouterFixtureController::class)
            ->willReturn($spy);

        $router = new Router($container);
        $router->get('/bad', [RouterFixtureController::class, 'returnsNonResponse']);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Route handler must return a Response');

        $router->dispatch($this->makeRequest('GET', '/bad'));
    }

    /**
     * A throw from inside a `group()` callback must not leak that group's
     * middleware onto routes registered AFTERWARDS.
     *
     * ## Why this is a security test and not a tidiness test
     *
     * `addRoute()` copies `$this->groupMiddleware` onto every route it creates, so a
     * leaked group middleware is silently attached to every later registration. The
     * live shape is `Application::loadCdsRoutes()`, whose group carries the DLNA IP
     * allowlist: one throw inside it (a logger, a config read, a container miss)
     * would attach that allowlist to the ~15 route loaders that run after it, and
     * those endpoints would begin refusing every non-LAN caller — an
     * availability-wide outage produced by an unrelated error. `group()` restores its
     * bookkeeping in a `finally` for exactly that reason.
     *
     * DISCRIMINATING: this test is the pin for that `finally`. Replace the
     * `try/finally` with a plain post-callback restore and `/after` carries the
     * throwing group's middleware, so both the registration assertion and the
     * dispatch assertion below go red.
     *
     * @covers \Phlix\Server\Http\Router::group
     */
    public function testAThrowInsideAGroupDoesNotLeakItsMiddlewareOntoLaterRoutes(): void
    {
        $ok = fn($req) => (new Response())->status(200)->json(['ok' => true]);
        $outerGate = fn($req) => null;
        // Stands in for DlnaAllowlistMiddleware: if it leaks, later routes 403.
        $leakyGate = fn($req) => (new Response())->status(403)->json(['error' => 'not on the allowlist']);

        $this->router->group('/outer', function (Router $r) use ($ok): void {
            $r->get('/a', $ok);
        }, [$outerGate]);

        $threw = false;
        try {
            $this->router->group('/boom', function (Router $r) use ($ok): void {
                $r->get('/x', $ok);
                throw new \RuntimeException('registration blew up');
            }, [$leakyGate]);
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertSame('registration blew up', $e->getMessage());
        }
        $this->assertTrue($threw, 'the exception itself must still propagate to the caller');

        $this->router->get('/after', $ok);

        $routes = $this->router->getRoutes();

        // The PREFIX must be restored too, or the later route is registered at an
        // entirely different path (asserted first so the failure names that cause).
        $this->assertArrayHasKey(
            '/after',
            $routes['GET'],
            'a route registered after a throwing group must not inherit its PREFIX either',
        );
        $this->assertArrayNotHasKey('/boom/after', $routes['GET']);
        $this->assertSame(
            [],
            $routes['GET']['/after']['middleware'],
            'a route registered after a throwing group must carry NO group middleware',
        );
        // Control: the non-throwing group's own route still has its middleware, so
        // this cannot pass by the router simply losing middleware everywhere.
        $this->assertCount(1, $routes['GET']['/outer/a']['middleware']);

        // The consequence, asserted end to end: the later route is not gated.
        $this->assertSame(200, $this->router->dispatch($this->makeRequest('GET', '/after'))->statusCode);
        $this->assertSame(200, $this->router->dispatch($this->makeRequest('GET', '/outer/a'))->statusCode);
        // And the leaky middleware really would have produced a 403 had it leaked.
        $this->assertSame(403, $this->router->dispatch($this->makeRequest('GET', '/boom/x'))->statusCode);
    }

    /**
     * NESTED case: the restore puts back the PREVIOUS prefix and middleware, not
     * empty ones — including when the nested group throws.
     *
     * Both halves matter. Restoring to empty would silently drop the OUTER group's
     * gate from every sibling route registered after a nested group, which is the
     * same class of defect as the leak, in the opposite direction: routes that were
     * meant to be gated would stop being gated.
     *
     * DISCRIMINATING: dropping the `finally` makes `/inner-boom` leak, so `/c` is
     * registered under the wrong prefix (`/inner-boom/c`) with two middleware
     * instead of one, and all three assertions on it fail.
     *
     * @covers \Phlix\Server\Http\Router::group
     */
    public function testANestedGroupRestoresThePreviousPrefixAndMiddlewareNotEmptyOnes(): void
    {
        $ok = fn($req) => (new Response())->status(200)->json(['ok' => true]);
        $outerGate = fn($req) => null;
        $innerGate = fn($req) => null;

        $this->router->group('/outer', function (Router $r) use ($ok, $innerGate): void {
            $r->get('/a', $ok);

            // A nested group that completes normally.
            $r->group('/inner', function (Router $n) use ($ok): void {
                $n->get('/b', $ok);
            }, [$innerGate]);

            // A nested group that blows up mid-registration.
            try {
                $r->group('/inner-boom', function (Router $n): void {
                    throw new \RuntimeException('nested registration blew up');
                }, [$innerGate]);
            } catch (\RuntimeException) {
                // swallowed on purpose: the point is what the router looks like now
            }

            // Registered back at the OUTER level, after both nested groups.
            $r->get('/c', $ok);
        }, [$outerGate]);

        $routes = $this->router->getRoutes();

        $this->assertArrayHasKey('/outer/a', $routes['GET']);
        $this->assertCount(1, $routes['GET']['/outer/a']['middleware']);

        // Nested groups OVERWRITE the prefix rather than concatenating it — a
        // pre-existing quirk, asserted so this test documents the real behaviour
        // instead of an assumed one.
        $this->assertArrayHasKey('/inner/b', $routes['GET']);
        $this->assertCount(2, $routes['GET']['/inner/b']['middleware'], 'outer + inner');

        // THE POINT: after a nested group — throwing or not — the outer prefix and
        // the outer middleware are both back, and neither has been cleared.
        $this->assertArrayHasKey(
            '/outer/c',
            $routes['GET'],
            'the outer prefix must be restored after a nested group throws, not left leaked',
        );
        $this->assertCount(
            1,
            $routes['GET']['/outer/c']['middleware'],
            'the OUTER middleware must be restored — not cleared to [], and not left with the inner one',
        );
        $this->assertSame($outerGate, $routes['GET']['/outer/c']['middleware'][0]);
    }
}
