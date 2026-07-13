<?php

namespace Phlix\Tests\Unit\Server\Http;

use PHPUnit\Framework\TestCase;
use Phlix\Server\Http\Router;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

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
}
