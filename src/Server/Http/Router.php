<?php

declare(strict_types=1);

namespace Phlex\Server\Http;

/**
 * HTTP Router for the Phlex Media Server.
 *
 * This class handles route registration and request dispatching.
 * It supports path parameters, middleware, and route groups.
 *
 * @author Phlex Media Server Team
 * @version 1.0.0
 * @description HTTP Router with support for path parameters, middleware, and route groups.
 * @see Request For request representation
 * @see Response For response generation
 *
 * @example
 * ```php
 * $router = new Router();
 * $router->get('/users/{id}', [UserController::class, 'show']);
 * $router->post('/users', [UserController::class, 'create']);
 * $response = $router->dispatch($request);
 * ```
 */
class Router
{
    /** @var array<string, array<string, array{handler: callable|array, middleware: array<callable>, path: string}>> Registered routes by method and pattern */
    private array $routes = [];

    /** @var array<string, string> Named routes mapping name to path */
    private array $namedRoutes = [];

    /** @var array<callable> Stack of global middleware */
    private array $middleware = [];

    /** @var array<callable> Middleware for the current route group */
    private array $groupMiddleware = [];

    /** @var string|null Current route group prefix */
    private ?string $groupPrefix = null;

    /**
     * Registers a GET route.
     *
     * @param string $path The route path (supports {param} placeholders)
     * @param callable|array $handler The handler callback or [Controller::class, 'method']
     * @return self For method chaining
     *
     * @example
     * ```php
     * $router->get('/users', fn($req) => (new Response())->json(['users' => []]));
     * ```
     */
    public function get(string $path, callable|array $handler): self
    {
        return $this->addRoute('GET', $path, $handler);
    }

    /**
     * Registers a POST route.
     *
     * @param string $path The route path (supports {param} placeholders)
     * @param callable|array $handler The handler callback or [Controller::class, 'method']
     * @return self For method chaining
     */
    public function post(string $path, callable|array $handler): self
    {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * Registers a PUT route.
     *
     * @param string $path The route path (supports {param} placeholders)
     * @param callable|array $handler The handler callback or [Controller::class, 'method']
     * @return self For method chaining
     */
    public function put(string $path, callable|array $handler): self
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Registers a PATCH route.
     *
     * @param string $path The route path (supports {param} placeholders)
     * @param callable|array $handler The handler callback or [Controller::class, 'method']
     * @return self For method chaining
     */
    public function patch(string $path, callable|array $handler): self
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * Registers a DELETE route.
     *
     * @param string $path The route path (supports {param} placeholders)
     * @param callable|array $handler The handler callback or [Controller::class, 'method']
     * @return self For method chaining
     */
    public function delete(string $path, callable|array $handler): self
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Registers an OPTIONS route.
     *
     * @param string $path The route path (supports {param} placeholders)
     * @param callable|array $handler The handler callback or [Controller::class, 'method']
     * @return self For method chaining
     */
    public function options(string $path, callable|array $handler): self
    {
        return $this->addRoute('OPTIONS', $path, $handler);
    }

    /**
     * Registers a route for all common HTTP methods.
     *
     * @param string $path The route path (supports {param} placeholders)
     * @param callable|array $handler The handler callback or [Controller::class, 'method']
     * @return self For method chaining
     */
    public function any(string $path, callable|array $handler): self
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $this->addRoute($method, $path, $handler);
        }
        return $this;
    }

    /**
     * Registers a route for specific HTTP methods.
     *
     * @param array<string> $methods Array of HTTP method names
     * @param string $path The route path (supports {param} placeholders)
     * @param callable|array $handler The handler callback or [Controller::class, 'method']
     * @return self For method chaining
     *
     * @example
     * ```php
     * $router->match(['GET', 'POST'], '/resource', handler);
     * ```
     */
    public function match(array $methods, string $path, callable|array $handler): self
    {
        foreach ($methods as $method) {
            $this->addRoute(strtoupper($method), $path, $handler);
        }
        return $this;
    }

    /**
     * Internal method to add a route to the routing table.
     *
     * @param string $method The HTTP method
     * @param string $path The route path
     * @param callable|array $handler The handler
     * @return self For method chaining
     */
    private function addRoute(string $method, string $path, callable|array $handler): self
    {
        $fullPath = $this->groupPrefix ? $this->groupPrefix . $path : $path;

        // Convert path parameters like {id} to named regex capture groups
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $fullPath);
        $pattern = '#^' . $pattern . '$#';

        $this->routes[$method][$pattern] = [
            'handler' => $handler,
            'middleware' => $this->groupMiddleware,
            'path' => $fullPath,
        ];

        return $this;
    }

    /**
     * Adds middleware to the current group.
     *
     * @param callable $middleware The middleware callback
     * @return self For method chaining
     */
    public function middleware(callable $middleware): self
    {
        $this->groupMiddleware[] = $middleware;
        return $this;
    }

    /**
     * Creates a route group with shared prefix and middleware.
     *
     * @param string $prefix Common path prefix for all routes in the group
     * @param callable $callback Callback that registers routes in the group
     * @param array<callable> $middleware Optional middleware for all routes in the group
     * @return self For method chaining
     *
     * @example
     * ```php
     * $router->group('/api/v1', function($r) {
     *     $r->get('/users', handler);
     *     $r->post('/users', handler);
     * }, [authMiddleware()]);
     * ```
     */
    public function group(string $prefix, callable $callback, array $middleware = []): self
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix = $prefix;
        $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;

        return $this;
    }

    /**
     * Dispatches a request to the appropriate route handler.
     *
     * @param Request $request The request to dispatch
     * @return Response The response from the matched handler
     *
     * @example
     * ```php
     * $response = $router->dispatch($request);
     * $response->send();
     * ```
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method;
        $path = $request->path;

        if (!isset($this->routes[$method])) {
            return $this->notFound();
        }

        foreach ($this->routes[$method] as $pattern => $route) {
            if (preg_match($pattern, $path, $matches)) {
                // Extract path parameters (named capture groups only)
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $request->pathParams = $params;

                // Apply route middleware
                $middlewareResponse = $this->runMiddleware($route['middleware'], $request);
                if ($middlewareResponse instanceof Response) {
                    return $middlewareResponse;
                }

                // Call the route handler
                return $this->callHandler($route['handler'], $request, $params);
            }
        }

        return $this->notFound();
    }

    /**
     * Runs middleware stack and returns early if a response is produced.
     *
     * @param array<callable> $middlewareStack Array of middleware to run
     * @param Request $request The current request
     * @return Response|null The middleware response, or null to continue
     */
    private function runMiddleware(array $middlewareStack, Request $request): ?Response
    {
        foreach ($middlewareStack as $middleware) {
            $result = $middleware($request);
            if ($result instanceof Response) {
                return $result;
            }
        }
        return null;
    }

    /**
     * Calls the appropriate handler for a matched route.
     *
     * @param callable|array $handler The handler callback or [Controller, method]
     * @param Request $request The current request
     * @param array<string, string> $params Extracted path parameters
     * @return Response The handler's response
     *
     * @throws \BadMethodCallException If handler format is invalid
     */
    private function callHandler(callable|array $handler, Request $request, array $params): Response
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $instance = is_string($class) ? new $class() : $class;
            return $instance->$method($request, $params);
        }

        return $handler($request, $params);
    }

    /**
     * Registers the hub token exchange endpoint.
     *
     * POST /api/v1/auth/hub-token — exchanges a hub JWT for a server session token.
     *
     * @return self
     */
    public function hubToken(string $controllerClass, string $method = 'handle'): self
    {
        return $this->post('/api/v1/auth/hub-token', [$controllerClass, $method]);
    }

    /**
     * Registers the DASH streaming routes.
     *
     * GET /dash/{jobId}/manifest.mpd           — master manifest
     * GET /dash/{jobId}/{setId}/manifest.mpd  — adaptation set manifest
     * GET /dash/{jobId}/{setId}/segment_{n}.m4s — segment file
     *
     * @param string $controllerClass The DashController class name
     * @return self
     */
    public function dashStreaming(string $controllerClass): self
    {
        $this->get('/dash/{job_id}/manifest.mpd', [$controllerClass, 'getMasterManifest']);
        $this->get('/dash/{job_id}/{set_id}/manifest.mpd', [$controllerClass, 'getAdaptationSetManifest']);
        $this->get('/dash/{job_id}/{set_id}/segment_{segment_number}.m4s', [$controllerClass, 'getSegment']);

        return $this;
    }

    /**
     * Registers the OIDC authentication routes.
     *
     * GET /auth/oidc/authorize  — redirect to OIDC provider authorization
     * GET /auth/oidc/callback    — handle OIDC provider callback
     *
     * @param string $controllerClass The callback controller class
     * @param string $authorizeMethod The authorize method name
     * @param string $callbackMethod The callback method name
     * @return self
     */
    public function oidcAuth(
        string $controllerClass,
        string $authorizeMethod = 'authorize',
        string $callbackMethod = 'callback'
    ): self {
        $this->get('/auth/oidc/authorize', [$controllerClass, $authorizeMethod]);
        $this->get('/auth/oidc/callback', [$controllerClass, $callbackMethod]);

        return $this;
        $this->get('/auth/oidc/authorize', [$controllerClass, $authorizeMethod]);
        $this->get('/auth/oidc/callback', [$controllerClass, $callbackMethod]);

        return $this;
    }

    /**
     * Registers the trickplay (thumbnail seek) routes.
     *
     * GET /trickplay/{jobId}/thumb-{index}.jpg  — thumbnail grid image
     * GET /trickplay/{jobId}/index.xml          — BIF index XML
     *
     * @param string $controllerClass The TrickplayController class name
     * @return self
     *
     * @since 0.11.0
     */
    public function trickplay(string $controllerClass): self
    {
        $this->get('/trickplay/{jobId}/thumb-{index}.jpg', [$controllerClass, 'getThumbnail']);
        $this->get('/trickplay/{jobId}/index.xml', [$controllerClass, 'getIndex']);

        return $this;
    }

    /**
     * Registers the marker (intro/outro/chapters) API routes.
     *
     * GET /api/v1/media/{id}/markers        — all markers for an item
     * GET /api/v1/media/{id}/markers/intro  — intro marker only
     * GET /api/v1/media/{id}/markers/outro  — outro marker only
     * GET /api/v1/shows/{id}/markers/bulk   — all episode markers for a show
     *
     * @param string $controllerClass The MarkerController class name
     * @return self
     *
     * @since 0.12.0
     */
    public function markers(string $controllerClass): self
    {
        $this->get('/api/v1/media/{id}/markers', [$controllerClass, 'getMarkers']);
        $this->get('/api/v1/media/{id}/markers/intro', [$controllerClass, 'getIntroMarker']);
        $this->get('/api/v1/media/{id}/markers/outro', [$controllerClass, 'getOutroMarker']);
        $this->get('/api/v1/shows/{id}/markers/bulk', [$controllerClass, 'getShowMarkers']);

        return $this;
    }

    /**
     * Registers the music library API routes.
     *
     * GET /music/artists              — list all artists
     * GET /music/artists/{mbid}        — get artist detail with albums
     * GET /music/albums               — list all albums
     * GET /music/albums/{mbid}        — get album detail with tracks
     * GET /music/tracks               — list all tracks (paginated)
     * GET /music/tracks/{id}          — get single track
     * GET /music/now-playing          — get current playback state
     *
     * @param string $controllerClass The MusicController class name
     * @return self
     *
     * @since 0.14.0
     */
    public function music(string $controllerClass): self
    {
        $this->get('/music/artists', [$controllerClass, 'listArtists']);
        $this->get('/music/artists/{mbid}', [$controllerClass, 'getArtist']);
        $this->get('/music/albums', [$controllerClass, 'listAlbums']);
        $this->get('/music/albums/{mbid}', [$controllerClass, 'getAlbum']);
        $this->get('/music/tracks', [$controllerClass, 'listTracks']);
        $this->get('/music/tracks/{id}', [$controllerClass, 'getTrack']);
        $this->get('/music/now-playing', [$controllerClass, 'nowPlaying']);

        return $this;
    }

    /**
     * Creates a 404 Not Found response.
     *
     * @return Response The 404 response
     */
    private function notFound(): Response
    {
        return (new Response())
            ->status(404)
            ->json([
                'error' => 'Not Found',
                'message' => 'The requested resource was not found',
            ]);
    }

    /**
     * Gets all registered routes.
     *
     * @return array<string, array<string, array{handler: callable|array, middleware: array<callable>, path: string}>> The routes array
     *
     * @description Returns the internal routes array for inspection or testing.
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
