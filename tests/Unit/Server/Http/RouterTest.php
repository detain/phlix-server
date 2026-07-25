<?php

namespace Phlix\Tests\Unit\Server\Http;

use PHPUnit\Framework\TestCase;
use Phlix\Server\Http\Router;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Tests\Unit\Server\Http\Fixtures\RouterFixtureController;
use Psr\Container\ContainerInterface;

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
