<?php

/**
 * Phlix media server component: Http.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http;

use Psr\Container\ContainerInterface;

/**
 * HTTP Router for the Phlix Media Server.
 *
 * This class handles route registration and request dispatching.
 * It supports path parameters, middleware, and route groups.
 *
 * @author Phlix Media Server Team
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
 *
 * @phpstan-type RouteHandlerArray array{0: string|object, 1: string}
 * @phpstan-type RouteHandler callable|RouteHandlerArray
 * @phpstan-type RouteEntry array{handler: RouteHandler, middleware: list<callable>, path: string}
 */
class Router
{
    /**
     * Registered routes by method and pattern.
     *
     * @var array<string, array<string, RouteEntry>>
     */
    private array $routes = [];

    /**
     * Static-path routes (no {param} placeholders) for O(1) lookup.
     * Key: "$method" => "$path" => RouteEntry.
     *
     * @var array<string, array<string, RouteEntry>>
     */
    private array $staticRoutes = [];

    /** @var list<callable> Middleware for the current route group */
    private array $groupMiddleware = [];

    /** @var string|null Current route group prefix */
    private ?string $groupPrefix = null;

    /** @var ContainerInterface|null Optional DI container for resolving string handlers */
    private ?ContainerInterface $container = null;

    /**
     * @param ContainerInterface|null $container Optional DI container for resolving
     *        controller string handlers (e.g. `[Controller::class, 'method']`).
     *        When provided, string class names are resolved via `$container->get()`
     *        instead of direct instantiation, enabling constructor injection.
     */
    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * Registers a GET route.
     *
     * @param string $path The route path (supports {param} placeholders)
     * @param RouteHandler $handler The handler callback or [Controller::class, 'method']
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
     * @param RouteHandler $handler The handler callback or [Controller::class, 'method']
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
     * @param RouteHandler $handler The handler callback or [Controller::class, 'method']
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
     * @param RouteHandler $handler The handler callback or [Controller::class, 'method']
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
     * @param RouteHandler $handler The handler callback or [Controller::class, 'method']
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
     * @param RouteHandler $handler The handler callback or [Controller::class, 'method']
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
     * @param RouteHandler $handler The handler callback or [Controller::class, 'method']
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
     * @param list<string> $methods Array of HTTP method names
     * @param string $path The route path (supports {param} placeholders)
     * @param RouteHandler $handler The handler callback or [Controller::class, 'method']
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
     * @param RouteHandler $handler The handler
     * @return self For method chaining
     */
    private function addRoute(string $method, string $path, callable|array $handler): self
    {
        $fullPath = $this->groupPrefix ? $this->groupPrefix . $path : $path;

        // SV-4.8: detect static paths (no {param} placeholders) for O(1) lookup.
        $isStatic = strpos($fullPath, '{') === false;
        $routeEntry = [
            'handler' => $handler,
            'middleware' => $this->groupMiddleware,
            'path' => $fullPath,
        ];

        if ($isStatic) {
            // O(1) map: $staticRoutes[$method][$path]
            $this->staticRoutes[strtoupper($method)][$fullPath] = $routeEntry;
        } else {
            // Parametric path: convert to named regex capture groups
            $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $fullPath);
            $pattern = '#^' . $pattern . '$#';
            $this->routes[$method][$pattern] = $routeEntry;
        }

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
     * The prefix/middleware restore runs in a `finally`, so a throw from inside
     * `$callback` cannot leave this router mid-group. It used to be able to, and
     * the consequence was a security one rather than a cosmetic one: the leaked
     * `groupMiddleware` is copied by {@see self::addRoute()} onto **every** route
     * registered afterwards, so one throw inside (say)
     * {@see \Phlix\Server\Core\Application::loadCdsRoutes()} would silently attach
     * the DLNA IP allowlist to the ~15 route loaders that run after it — and those
     * endpoints would start refusing every non-LAN caller. The exception itself
     * still propagates; only the router's own bookkeeping is made unconditional.
     *
     * @param string $prefix Common path prefix for all routes in the group
     * @param callable $callback Callback that registers routes in the group
     * @param list<callable> $middleware Optional middleware for all routes in the group
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

        try {
            $callback($this);
        } finally {
            $this->groupPrefix = $previousPrefix;
            $this->groupMiddleware = $previousMiddleware;
        }

        return $this;
    }

    /**
     * Dispatches a request to the appropriate route handler.
     *
     * ## `HEAD` replies are flagged here, not by the handlers
     *
     * Every response this method returns for a `HEAD` request passes through
     * {@see self::markHeadOnly()}, whichever map matched it and however the route
     * was registered. That is deliberately a *structural* guarantee rather than a
     * convention each controller has to remember: the flag is what makes
     * {@see Response::toWorkermanResponse()} render the reply through
     * {@see \Phlix\Server\Workerman\BodylessResponse} (so a `Content-Length` the
     * handler set for a bodyless `HEAD` is not followed by Workerman's own
     * contradictory `Content-Length: 0`, invalid per RFC 9110 §8.6) and it is also
     * what the CGI/FPM entrypoint reads to suppress the body
     * ({@see Response::send()}), so setting it keeps both entrypoints in agreement.
     *
     * Before this, only {@see self::dispatchAsHead()} — the GET→HEAD *fallback* —
     * set it, so a route registered for `HEAD` in its own right
     * (`match(['GET', 'HEAD'], …)`, as the DLNA stream route is) got nothing and
     * shipped the two-`Content-Length` defect unless its handler happened to set
     * the flag by hand. Pinned by
     * `RouterTest::testAHeadRegisteredRouteThatForgetsTheFlagStillPutsOneContentLengthOnTheWire()`.
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

        // SV-4.8: O(1) static-path lookup first (exact match, no regex).
        if (isset($this->staticRoutes[$method][$path])) {
            $route = $this->staticRoutes[$method][$path];
            $request->pathParams = [];

            $middlewareResponse = $this->runMiddleware($route['middleware'], $request);
            if ($middlewareResponse instanceof Response) {
                return $this->markHeadOnly($request, $middlewareResponse);
            }

            error_log('[DEBUG] ' . date('Y-m-d H:i:s.v') . ' Router::dispatch static route [method=' . $method .
                '] [path=' . $path . ']');
            $startTime = hrtime(true);
            $response = $this->callHandler($route['handler'], $request, []);
            $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
            error_log('[DEBUG] ' . date('Y-m-d H:i:s.v') . ' Router::dispatch completed [method=' . $method .
                '] [path=' . $path . '] [duration=' . round($durationMs, 2) . 'ms]');
            return $this->markHeadOnly($request, $response);
        }

        if (!isset($this->routes[$method])) {
            // HEAD fallback: if no explicit HEAD route registered, fall back to
            // matching GET handler to get headers (Content-Type, Content-Length,
            // etc.) without body (RFC 7231). A matching GET route may live in
            // EITHER the parametric map or the static map — check both, otherwise
            // a GET path registered only as a static route (zero parametric GET
            // routes) would 404 a HEAD instead of reaching dispatchAsHead().
            if ($method === 'HEAD' && (isset($this->routes['GET']) || isset($this->staticRoutes['GET']))) {
                return $this->dispatchAsHead($request, $path);
            }
            error_log('[DEBUG] ' . date('Y-m-d H:i:s.v') . ' Router::dispatch 404 [method=' . $method . '] [path=' .
                $path . ']');
            return $this->notFound($request);
        }

        foreach ($this->routes[$method] as $pattern => $route) {
            if (preg_match($pattern, $path, $matches)) {
                // Extract path parameters (named capture groups only)
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $request->pathParams = $params;

                // Apply route middleware
                $middlewareResponse = $this->runMiddleware($route['middleware'], $request);
                if ($middlewareResponse instanceof Response) {
                    return $this->markHeadOnly($request, $middlewareResponse);
                }

                // Call the route handler
                error_log('[DEBUG] ' . date('Y-m-d H:i:s.v') . ' Router::dispatch parametric route [method=' .
                    $method . '] [path=' . $path . ']');
                $startTime = hrtime(true);
                $response = $this->callHandler($route['handler'], $request, $params);
                $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
                error_log('[DEBUG] ' . date('Y-m-d H:i:s.v') . ' Router::dispatch completed [method=' . $method .
                    '] [path=' . $path . '] [duration=' . round($durationMs, 2) . 'ms]');
                return $this->markHeadOnly($request, $response);
            }
        }

        // HEAD fallback: route registered but no pattern matched — try GET in
        // either the parametric or the static map (see note above).
        if ($method === 'HEAD' && (isset($this->routes['GET']) || isset($this->staticRoutes['GET']))) {
            return $this->dispatchAsHead($request, $path);
        }

        error_log('[DEBUG] ' . date('Y-m-d H:i:s.v') . ' Router::dispatch 404 [method=' . $method . '] [path=' .
            $path . ']');
        return $this->notFound($request);
    }

    /**
     * Dispatches a HEAD request by finding a matching GET route and
     * invoking its handler with body suppression (RFC 7231).
     *
     * Reached only from the two `$method === 'HEAD'` guards in
     * {@see self::dispatch()}, so {@see self::markHeadOnly()}'s method test is
     * always satisfied here — it is used rather than a bare assignment so the flag
     * has exactly ONE writer in this class.
     */
    private function dispatchAsHead(Request $request, string $path): Response
    {
        // SV-4.8: O(1) static-path lookup for HEAD → GET fallback.
        if (isset($this->staticRoutes['GET'][$path])) {
            $route = $this->staticRoutes['GET'][$path];
            $request->pathParams = [];

            $middlewareResponse = $this->runMiddleware($route['middleware'], $request);
            if ($middlewareResponse instanceof Response) {
                return $this->markHeadOnly($request, $middlewareResponse);
            }

            return $this->markHeadOnly($request, $this->callHandler($route['handler'], $request, []));
        }

        // May be reached via the static-only GET fallback, where the parametric
        // GET map is unset — coalesce so the loop is a no-op rather than a warning.
        foreach (($this->routes['GET'] ?? []) as $pattern => $route) {
            if (preg_match($pattern, $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $request->pathParams = $params;

                $middlewareResponse = $this->runMiddleware($route['middleware'], $request);
                if ($middlewareResponse instanceof Response) {
                    return $this->markHeadOnly($request, $middlewareResponse);
                }

                return $this->markHeadOnly($request, $this->callHandler($route['handler'], $request, $params));
            }
        }

        return $this->notFound($request);
    }

    /**
     * Flags a response head-only when — and only when — the request was a `HEAD`.
     *
     * The single writer of {@see Response::$headOnly} in this class, applied to
     * every response {@see self::dispatch()} returns from a matched route: both
     * route maps, both middleware short-circuits, and (via
     * {@see self::dispatchAsHead()}) the GET→HEAD fallback. A route registered for
     * `HEAD` in its own right therefore gets the flag from the router instead of
     * having to remember it, which is what makes the correct single-`Content-Length`
     * framing a property of the framework rather than of each handler.
     *
     * Two invariants this method exists to hold:
     *
     *  - **It can never flag a GET.** The `HEAD` test is the whole body; a response
     *    to any other method is returned untouched. Treating a stale non-zero
     *    `Content-Length` as authoritative on a GET that came out empty would be a
     *    keep-alive framing desync rather than a fix — see
     *    {@see Response::toWorkermanResponse()}.
     *  - **It is idempotent.** A handler that also sets the flag itself (the DLNA
     *    stream route does, so it stays correct when invoked by any other
     *    dispatcher) is unaffected: this only ever assigns `true`.
     *
     * 404s are flagged too, since S113: {@see self::notFound()} routes its response
     * through here, so a `HEAD` to an unregistered path answers with the 404's
     * `Content-Length` and **no** body instead of shipping the 83-byte JSON envelope
     * a `HEAD` must not carry (RFC 9110 §9.3.2). Until S113 they were deliberately
     * left unflagged and that body reached the wire.
     *
     * The flag is set via {@see Response::asHeadReply()} rather than by assignment,
     * because suppressing the body is only half of the RFC's requirement: the reply
     * must still declare the length the equivalent `GET` would have returned, and a
     * bare `headOnly = true` on a response that never set `Content-Length` makes the
     * encoder derive one from the suppressed body — i.e. answer `Content-Length: 0`
     * for an entity that is not empty. See that method for the derivation and for
     * why a caller-set length always wins.
     *
     * ## The BOUNDARY of the guarantee — read this before trusting it
     *
     * The guarantee covers exactly what this router returns from a matched route.
     * A **global** middleware registered on {@see \Phlix\Server\Core\Application}
     * (`Application::middleware()`) runs *outside* the router: when it
     * short-circuits, its response is returned from `Application::dispatch()` /
     * `Application::run()` without this method ever being reached, so it is NOT
     * flagged HERE. **S295 closed that hole where the global chain returns instead**:
     * `Application::flagHeadShortCircuitReply()` sends every global short-circuit
     * reply through {@see Response::asHeadReply()} on a `HEAD` — the same
     * {@see Response::headOnly} flag this method sets, reached through a different
     * seam — so a `HEAD` refused by the only global middleware
     * ({@see \Phlix\Server\Http\Middleware\AccessScheduleMiddleware}, whose three
     * refusals are `->status(403)->json([...])` and therefore declare **no**
     * `Content-Length` of their own) now ships head-only with the real entity size,
     * never the RFC 9110 §9.3.2 body-on-a-HEAD shape and never the RFC 9110 §8.6
     * two-`Content-Length` framing defect this method exists to prevent.
     *
     * ⚠ **This sentence used to say that shape "is fixed in the same follow-up
     * change". S113 was that change, and it did NOT close this one — the claim was
     * struck rather than left standing, because a stale promise reads as a
     * guarantee.** S113 fixed the six sites it enumerated: `notFound()` here and
     * five in {@see \Phlix\Server\Workerman\HttpHandler} (`serveStatic()`, the
     * page-rendering send, the 429, the 500 and the `404 - Page not found` page).
     * A global short-circuit is none of those: it is returned by
     * `Application::dispatch()` and sent by HttpHandler's *matched-route* branch,
     * which is correct precisely because the router has already flagged everything
     * that reaches it. **S295 then closed the global seam itself** — see the
     * paragraph above — so the sentence's promise is now kept, at the seam S113
     * deliberately did not widen into: the constructor's AccessScheduleMiddleware
     * wrapper flags every global short-circuit reply head-only on a `HEAD` before
     * it is returned from the chain, exactly where the struck sentence said the
     * flag had to be set.
     *
     * ⚠ Pinned by `ApplicationHeadOnlyBoundaryTest` — but read what it pins, because
     * this sentence used to claim more than was true and the S105 AC audit proved it:
     * adding a THIRD global middleware that short-circuits with its own
     * `Content-Length` left the whole Unit suite green. The alarm asserts (a) that
     * `AccessScheduleMiddleware`'s refusals declare no `Content-Length`, (b) that the
     * global stack is still exactly the one registration this boundary was measured
     * against — asserted on the COUNT, so a second fires it whatever it is — and (c)
     * that nothing registers one from outside via `Application::getInstance()`. Any of
     * those firing means re-doing this analysis, and a short-circuit that DOES declare
     * a `Content-Length` must be fixed at once rather than deferred.
     *
     * @param Request  $request  The dispatched request (only `method` is read).
     * @param Response $response The response about to be returned.
     * @return Response The same instance, for use in a `return` expression.
     */
    private function markHeadOnly(Request $request, Response $response): Response
    {
        if ($request->method === 'HEAD') {
            return $response->asHeadReply();
        }

        return $response;
    }

    /**
     * Runs middleware stack and returns early if a response is produced.
     *
     * @param list<callable> $middlewareStack Array of middleware to run
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
     * @param RouteHandler $handler The handler callback or [Controller, method]
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
            // SV-4.8: resolve string class names via DI container when available,
            // enabling constructor injection (db, logger, config, etc.).
            if (is_string($class)) {
                $instance = $this->container !== null
                    ? $this->container->get($class)
                    : new $class();
            } else {
                $instance = $class;
            }
            $result = $instance->$method($request, $params);
        } else {
            $result = $handler($request, $params);
        }

        if (!$result instanceof Response) {
            throw new \BadMethodCallException('Route handler must return a Response');
        }
        return $result;
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
    }

    /**
     * Registers the GitHub OAuth2 authentication routes (S48).
     *
     * GET /auth/github/authorize  — redirect to GitHub's authorize endpoint
     * GET /auth/github/callback   — handle GitHub's OAuth callback
     *
     * @param string $controllerClass The GithubCallbackController class name
     * @param string $authorizeMethod The authorize method name
     * @param string $callbackMethod The callback method name
     * @return self
     *
     * @since 0.102.0
     */
    public function githubAuth(
        string $controllerClass,
        string $authorizeMethod = 'authorize',
        string $callbackMethod = 'callback'
    ): self {
        $this->get('/auth/github/authorize', [$controllerClass, $authorizeMethod]);
        $this->get('/auth/github/callback', [$controllerClass, $callbackMethod]);

        return $this;
    }

    /**
     * Registers the Trakt.tv OAuth authentication routes.
     *
     * GET /api/v1/oauth/trakt           — redirect to Trakt authorization
     * GET /api/v1/oauth/trakt/callback  — handle Trakt OAuth callback
     *
     * @param string $controllerClass The TraktOAuthController class name
     * @param string $authorizeMethod The authorize method name
     * @param string $callbackMethod The callback method name
     * @return self
     *
     * @since 0.14.0
     */
    public function traktAuth(
        string $controllerClass,
        string $authorizeMethod = 'authorize',
        string $callbackMethod = 'callback'
    ): self {
        $this->get('/api/v1/oauth/trakt', [$controllerClass, $authorizeMethod]);
        $this->get('/api/v1/oauth/trakt/callback', [$controllerClass, $callbackMethod]);

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
     * GET /api/v1/music/artists              — list all artists
     * GET /api/v1/music/artists/{mbid}        — get artist detail with albums
     * GET /api/v1/music/albums               — list all albums
     * GET /api/v1/music/albums/{mbid}        — get album detail with tracks
     * GET /api/v1/music/tracks               — list all tracks (paginated)
     * GET /api/v1/music/tracks/{id}          — get single track
     * GET /api/v1/music/now-playing          — get current playback state
     *
     * ⚠ NOT the live registrar. The served music routes are registered by
     * {@see \Phlix\Server\Core\Application::loadMusicRoutes()} (bound to a real
     * MusicController instance behind AuthMiddleware). This helper — like its
     * `books()` / `audiobooks()` / `photo()` / `opds()` siblings — has no caller
     * in `src/`; its only caller is `RouterMediaRoutesTest`, which uses the whole
     * family to pin the canonical `/api/v1` URL shapes. S99 left all five in
     * place rather than deleting one member of a symmetric set.
     *
     * @param string $controllerClass The MusicController class name
     * @return self
     *
     * @since 0.14.0
     */
    public function music(string $controllerClass): self
    {
        $this->get('/api/v1/music/artists', [$controllerClass, 'listArtists']);
        $this->get('/api/v1/music/artists/{mbid}', [$controllerClass, 'getArtist']);
        $this->get('/api/v1/music/albums', [$controllerClass, 'listAlbums']);
        $this->get('/api/v1/music/albums/{mbid}', [$controllerClass, 'getAlbum']);
        $this->get('/api/v1/music/tracks', [$controllerClass, 'listTracks']);
        $this->get('/api/v1/music/tracks/{id}', [$controllerClass, 'getTrack']);
        $this->get('/api/v1/music/now-playing', [$controllerClass, 'nowPlaying']);

        return $this;
    }

    /**
     * Registers the photo library API routes.
     *
     * GET /api/v1/photo/albums              — list all albums (grouped by date)
     * GET /api/v1/photo/albums/{id}        — get specific album with photos
     * GET /api/v1/photo/photos              — list all photos
     * GET /api/v1/photo/photos/{id}        — get photo with full EXIF data
     * GET /api/v1/photo/photos/{id}/thumbnail — get resized thumbnail
     * GET /api/v1/photo/photos/{id}/full   — get full-resolution photo
     * GET /api/v1/photo/slideshow          — get slideshow data
     *
     * @param string $controllerClass The PhotoController class name
     * @return self
     *
     * @since 0.16.0
     */
    public function photo(string $controllerClass): self
    {
        $this->get('/api/v1/photo/albums', [$controllerClass, 'listAlbums']);
        $this->get('/api/v1/photo/albums/{id}', [$controllerClass, 'getAlbum']);
        $this->get('/api/v1/photo/photos', [$controllerClass, 'listPhotos']);
        $this->get('/api/v1/photo/photos/{id}', [$controllerClass, 'getPhoto']);
        $this->get('/api/v1/photo/photos/{id}/thumbnail', [$controllerClass, 'getThumbnail']);
        $this->get('/api/v1/photo/photos/{id}/full', [$controllerClass, 'getFull']);
        $this->get('/api/v1/photo/slideshow', [$controllerClass, 'slideshow']);

        return $this;
    }

    /**
     * Registers the book library API routes.
     *
     * GET /api/v1/books                    — list all books
     * GET /api/v1/books/{id}              — get single book
     * GET /api/v1/books/{id}/cover        — cover image
     * GET /api/v1/books/{id}/read         — book reader with progress
     * GET /api/v1/books/{id}/download     — download book file
     * GET /api/v1/books/{id}/progress     — user's reading progress
     * POST /api/v1/books/{id}/progress    — save reading progress
     *
     * @param string $controllerClass The BookController class name
     * @return self
     *
     * @since 0.17.0
     */
    public function books(string $controllerClass): self
    {
        $this->get('/api/v1/books', [$controllerClass, 'listBooks']);
        $this->get('/api/v1/books/{id}', [$controllerClass, 'getBook']);
        $this->get('/api/v1/books/{id}/cover', [$controllerClass, 'getCover']);
        $this->get('/api/v1/books/{id}/read', [$controllerClass, 'readBook']);
        $this->get('/api/v1/books/{id}/download', [$controllerClass, 'downloadBook']);
        $this->get('/api/v1/books/{id}/progress', [$controllerClass, 'getBookProgress']);
        $this->post('/api/v1/books/{id}/progress', [$controllerClass, 'saveBookProgress']);

        return $this;
    }

    /**
     * Registers the audiobook library API routes.
     *
     * GET /api/v1/audiobooks                      — list all audiobooks
     * GET /api/v1/audiobooks/{id}                 — get single audiobook with chapters
     * GET /api/v1/audiobooks/{id}/chapters        — chapter list
     * GET /api/v1/audiobooks/{id}/progress        — user's progress
     * POST /api/v1/audiobooks/{id}/progress       — save progress
     * GET /api/v1/audiobooks/{id}/read            — HTML player stub
     * GET /api/v1/audiobooks/{id}/stream          — stream with chapter resume
     *
     * @param string $controllerClass The AudiobookController class name
     * @return self
     *
     * @since 0.18.0
     */
    public function audiobooks(string $controllerClass): self
    {
        $this->get('/api/v1/audiobooks', [$controllerClass, 'listAudiobooks']);
        $this->get('/api/v1/audiobooks/{id}', [$controllerClass, 'getAudiobook']);
        $this->get('/api/v1/audiobooks/{id}/chapters', [$controllerClass, 'getChapters']);
        $this->get('/api/v1/audiobooks/{id}/progress', [$controllerClass, 'getProgress']);
        $this->post('/api/v1/audiobooks/{id}/progress', [$controllerClass, 'saveProgress']);
        $this->get('/api/v1/audiobooks/{id}/read', [$controllerClass, 'readAudiobook']);
        $this->get('/api/v1/audiobooks/{id}/stream', [$controllerClass, 'streamAudiobook']);

        return $this;
    }

    /**
     * Registers the OPDS 1.2 feed routes.
     *
     * GET /opds/v1.2                 — root OPDS feed
     * GET /opds/v1.2/libraries       — navigation: list book libraries
     * GET /opds/v1.2/libraries/{id}   — acquisition: list books in library
     * GET /opds/v1.2/books/{id}/cover — cover image
     *
     * @param string $controllerClass The BookController class name
     * @return self
     *
     * @since 0.17.0
     */
    public function opds(string $controllerClass): self
    {
        $this->get('/opds/v1.2', [$controllerClass, 'opdsRoot']);
        $this->get('/opds/v1.2/libraries', [$controllerClass, 'opdsLibraries']);
        $this->get('/opds/v1.2/libraries/{id}', [$controllerClass, 'opdsLibraryBooks']);
        $this->get('/opds/v1.2/books/{id}/cover', [$controllerClass, 'opdsBookCover']);

        return $this;
    }

    /**
     * Registers the smart playlist API routes.
     *
     * GET    /api/v1/smart-playlists           — list all smart playlists
     * POST   /api/v1/smart-playlists           — create smart playlist
     * GET    /api/v1/smart-playlists/{id}       — get single smart playlist
     * PUT    /api/v1/smart-playlists/{id}       — update smart playlist
     * DELETE /api/v1/smart-playlists/{id}      — delete smart playlist
     * POST   /api/v1/smart-playlists/{id}/preview — preview rules against library
     *
     * @param string $controllerClass The SmartPlaylistController class name
     * @return self
     *
     * @since 0.14.0
     */
    public function smartPlaylists(string $controllerClass): self
    {
        $this->get('/api/v1/smart-playlists', [$controllerClass, 'index']);
        $this->post('/api/v1/smart-playlists', [$controllerClass, 'create']);
        $this->get('/api/v1/smart-playlists/{id}', [$controllerClass, 'show']);
        $this->put('/api/v1/smart-playlists/{id}', [$controllerClass, 'update']);
        $this->delete('/api/v1/smart-playlists/{id}', [$controllerClass, 'delete']);
        $this->post('/api/v1/smart-playlists/{id}/preview', [$controllerClass, 'preview']);

        return $this;
    }

    /**
     * Registers the collection API routes.
     *
     * GET    /api/v1/collections                           — list all collections
     * POST   /api/v1/collections                           — create collection
     * GET    /api/v1/collections/{id}                     — get one with items
     * PUT    /api/v1/collections/{id}                     — update collection
     * DELETE /api/v1/collections/{id}                    — delete collection
     * POST   /api/v1/collections/{id}/items/{mediaItemId}  — add item to collection
     * DELETE /api/v1/collections/{id}/items/{mediaItemId}  — remove item from collection
     * POST   /api/v1/collections/{id}/bulk-add            — bulk-add from search
     * POST   /api/v1/collections/{id}/refresh             — re-evaluate smart collection
     * GET    /api/v1/libraries/{libraryId}/collections     — collections for library
     *
     * @param string $controllerClass The CollectionController class name
     * @return self
     *
     * @since 0.14.0
     */
    public function collections(string $controllerClass): self
    {
        $this->get('/api/v1/collections', [$controllerClass, 'index']);
        $this->post('/api/v1/collections', [$controllerClass, 'create']);
        $this->get('/api/v1/collections/{id}', [$controllerClass, 'show']);
        $this->put('/api/v1/collections/{id}', [$controllerClass, 'update']);
        $this->delete('/api/v1/collections/{id}', [$controllerClass, 'delete']);
        $this->post('/api/v1/collections/{id}/items/{mediaItemId}', [$controllerClass, 'addItem']);
        $this->delete('/api/v1/collections/{id}/items/{mediaItemId}', [$controllerClass, 'removeItem']);
        $this->post('/api/v1/collections/{id}/bulk-add', [$controllerClass, 'bulkAdd']);
        $this->post('/api/v1/collections/{id}/refresh', [$controllerClass, 'refresh']);
        $this->get('/api/v1/libraries/{libraryId}/collections', [$controllerClass, 'forLibrary']);

        return $this;
    }

    /**
     * Registers the extras (trailers) API routes.
     *
     * GET /api/v1/media/{id}/extras      — full merged list (trailers + extras)
     * GET /api/v1/media/{id}/trailers      — trailers only
     * GET /api/v1/media/{id}/extras/other  — non-trailer extras only
     *
     * @param string $controllerClass The ExtrasController class name
     * @return self
     *
     * @since 0.14.0
     */
    public function extras(string $controllerClass): self
    {
        $this->get('/api/v1/media/{id}/extras', [$controllerClass, 'getExtras']);
        $this->get('/api/v1/media/{id}/trailers', [$controllerClass, 'getTrailers']);
        $this->get('/api/v1/media/{id}/extras/other', [$controllerClass, 'getOtherExtras']);

        return $this;
    }

    /**
     * Registers the media item API routes.
     *
     * GET /api/v1/media/{id}/playback-info — playback info with markers and skip-spec
     *
     * @param string $controllerClass The MediaItemController class name
     * @return self
     *
     * @since 0.19.0
     */
    public function mediaItems(string $controllerClass): self
    {
        $this->get('/api/v1/media/{id}/playback-info', [$controllerClass, 'getPlaybackInfo']);

        return $this;
    }

    /**
     * Registers the theme media API and streaming routes.
     *
     * GET    /api/v1/libraries/{id}/theme-media         — get theme media for a library
     * POST   /api/v1/libraries/{id}/theme-media/scan    — trigger rescan
     * DELETE /api/v1/libraries/{id}/theme-media         — clear cached entry
     * GET    /stream/theme-media/{libraryId}/audio      — stream theme audio file
     * GET    /stream/theme-media/{libraryId}/video      — stream theme video file
     *
     * @param string $controllerClass The ThemeMediaController class name
     * @param string $streamControllerClass The ThemeMediaStreamController class name
     * @return self
     *
     * @since 0.14.0
     */
    public function themeMedia(string $controllerClass, string $streamControllerClass): self
    {
        // API endpoints
        $this->get('/api/v1/libraries/{id}/theme-media', [$controllerClass, 'getThemeMedia']);
        $this->post('/api/v1/libraries/{id}/theme-media/scan', [$controllerClass, 'scanThemeMedia']);
        $this->delete('/api/v1/libraries/{id}/theme-media', [$controllerClass, 'deleteThemeMedia']);

        // Streaming endpoints
        $this->get('/stream/theme-media/{libraryId}/audio', [$streamControllerClass, 'streamAudio']);
        $this->get('/stream/theme-media/{libraryId}/video', [$streamControllerClass, 'streamVideo']);

        return $this;
    }

    /**
     * Creates a 404 Not Found response.
     *
     * ## Why this takes the request (S113)
     *
     * {@see Response::json()} encodes with `JSON_PRETTY_PRINT`, so this envelope is
     * an **83-byte** body — and until S113 every one of them reached the wire on a
     * `HEAD` to an unregistered path, because the three call sites returned the
     * response directly instead of through {@see self::markHeadOnly()}. RFC 9110
     * §9.3.2 forbids a body on a `HEAD`: a header-only client leaves those 83 bytes
     * buffered in the socket, so the NEXT response on a keep-alive connection is
     * read starting 83 bytes late. Passing the request lets the one flag-writer in
     * this class suppress the body while keeping `Content-Length: 83` — the length
     * the equivalent `GET` really would have returned.
     *
     * @param Request $request The request being answered (only `method` is read).
     * @return Response The 404 response, head-only when the request was a `HEAD`.
     */
    private function notFound(Request $request): Response
    {
        return $this->markHeadOnly($request, (new Response())
            ->status(404)
            ->json([
                'error' => 'Not Found',
                'message' => 'The requested resource was not found',
            ]));
    }

    /**
     * Gets all registered routes.
     *
     * @return array<string, array<string, RouteEntry>> Registered routes
     *
     * @description Returns the internal routes array for inspection or testing.
     */
    public function getRoutes(): array
    {
        // SV-4.8: include both static (O(1) map) and regex (parametric) routes
        // so inspection/testing sees a complete picture. Static routes are keyed
        // by their literal path; regex routes by their compiled pattern.
        $merged = $this->routes;
        foreach ($this->staticRoutes as $method => $paths) {
            foreach ($paths as $path => $entry) {
                $merged[$method][$path] = $entry;
            }
        }
        return $merged;
    }
}
