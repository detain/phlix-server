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

    /** @var list<callable> Middleware for the current route group */
    private array $groupMiddleware = [];

    /** @var string|null Current route group prefix */
    private ?string $groupPrefix = null;

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
            $instance = is_string($class) ? new $class() : $class;
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
     * Registers the photo library API routes.
     *
     * GET /photo/albums              — list all albums (grouped by date)
     * GET /photo/albums/{id}        — get specific album with photos
     * GET /photo/photos              — list all photos
     * GET /photo/photos/{id}        — get photo with full EXIF data
     * GET /photo/photos/{id}/thumbnail — get resized thumbnail
     * GET /photo/photos/{id}/full   — get full-resolution photo
     * GET /photo/slideshow          — get slideshow data
     *
     * @param string $controllerClass The PhotoController class name
     * @return self
     *
     * @since 0.16.0
     */
    public function photo(string $controllerClass): self
    {
        $this->get('/photo/albums', [$controllerClass, 'listAlbums']);
        $this->get('/photo/albums/{id}', [$controllerClass, 'getAlbum']);
        $this->get('/photo/photos', [$controllerClass, 'listPhotos']);
        $this->get('/photo/photos/{id}', [$controllerClass, 'getPhoto']);
        $this->get('/photo/photos/{id}/thumbnail', [$controllerClass, 'getThumbnail']);
        $this->get('/photo/photos/{id}/full', [$controllerClass, 'getFull']);
        $this->get('/photo/slideshow', [$controllerClass, 'slideshow']);

        return $this;
    }

    /**
     * Registers the book library API routes.
     *
     * GET /books                  — list all books
     * GET /books/{id}            — get single book
     * GET /books/{id}/cover      — cover image
     * GET /books/{id}/read        — reader stub
     * GET /books/{id}/download   — download book file
     *
     * @param string $controllerClass The BookController class name
     * @return self
     *
     * @since 0.17.0
     */
    public function books(string $controllerClass): self
    {
        $this->get('/books', [$controllerClass, 'listBooks']);
        $this->get('/books/{id}', [$controllerClass, 'getBook']);
        $this->get('/books/{id}/cover', [$controllerClass, 'getCover']);
        $this->get('/books/{id}/read', [$controllerClass, 'readBook']);
        $this->get('/books/{id}/download', [$controllerClass, 'downloadBook']);

        return $this;
    }

    /**
     * Registers the audiobook library API routes.
     *
     * GET /audiobooks                      — list all audiobooks
     * GET /audiobooks/{id}                 — get single audiobook with chapters
     * GET /audiobooks/{id}/chapters        — chapter list
     * GET /audiobooks/{id}/progress        — user's progress
     * POST /audiobooks/{id}/progress       — save progress
     * GET /audiobooks/{id}/read            — HTML player stub
     * GET /audiobooks/{id}/stream          — stream with chapter resume
     *
     * @param string $controllerClass The AudiobookController class name
     * @return self
     *
     * @since 0.18.0
     */
    public function audiobooks(string $controllerClass): self
    {
        $this->get('/audiobooks', [$controllerClass, 'listAudiobooks']);
        $this->get('/audiobooks/{id}', [$controllerClass, 'getAudiobook']);
        $this->get('/audiobooks/{id}/chapters', [$controllerClass, 'getChapters']);
        $this->get('/audiobooks/{id}/progress', [$controllerClass, 'getProgress']);
        $this->post('/audiobooks/{id}/progress', [$controllerClass, 'saveProgress']);
        $this->get('/audiobooks/{id}/read', [$controllerClass, 'readAudiobook']);
        $this->get('/audiobooks/{id}/stream', [$controllerClass, 'streamAudiobook']);

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
     * @return array<string, array<string, RouteEntry>> Registered routes
     *
     * @description Returns the internal routes array for inspection or testing.
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
