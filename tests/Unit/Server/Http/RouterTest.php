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
}
