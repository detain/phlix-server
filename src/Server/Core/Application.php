<?php

declare(strict_types=1);

namespace Phlix\Server\Core;

use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Hub\HubClient;
use Phlix\Hub\HubApplication;
use Phlix\Hub\RelayApplication;
use Phlix\Discovery\DiscoveryServer;
use Phlix\Server\Http\Controllers\HubJwksController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use Phlix\Theming\ThemeMiddleware;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Main application entry point for the Phlix Media Server.
 *
 * This class orchestrates HTTP request handling, middleware execution,
 * and route dispatching. It implements a singleton pattern to provide
 * global access to the application instance.
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @description Core application class that bootstraps the server, loads routes, and handles requests.
 * @see \Phlix\Server\Http\Router For route configuration
 * @see \Phlix\Server\Http\Request For request handling
 * @see \Phlix\Server\Http\Response For response generation
 */
class Application
{
    /** @var Router The router instance for handling request dispatching */
    private Router $router;

    /** @var array<callable> Stack of middleware to apply to requests */
    private array $middleware = [];

    /** @var array<string, mixed> Application configuration array */
    private array $config;

    /** @var ContainerInterface|null PSR-11 container backing this application. */
    private ?ContainerInterface $container = null;

    /** @var Application|null Singleton instance of the application */
    private static ?Application $instance = null;

    /**
     * Creates a new Application instance from an already-built PSR-11 container.
     *
     * This is the canonical entry point in Phase A onwards. The legacy
     * config-path constructor remains available through
     * {@see Application::fromConfigPath()} for backwards compatibility.
     *
     * @param ContainerInterface   $container PSR-11 container built by
     *                                         {@see ContainerFactory::create()}.
     * @param array<string, mixed> $config    Application config (the array
     *                                         returned by config/server.php
     *                                         plus any runtime additions).
     *
     * @since 0.10.0
     */
    public function __construct(ContainerInterface $container, array $config)
    {
        $this->container = $container;
        $this->config = $config;
        $this->router = new Router();
        $this->loadRoutes();

        // Register ThemeMiddleware from container if available
        if ($container->has(ThemeMiddleware::class)) {
            /** @var ThemeMiddleware */
            $themeMiddleware = $container->get(ThemeMiddleware::class);
            $this->middleware(function (Request $request, callable $next) use ($themeMiddleware): Response {
                return $themeMiddleware->onHttpRequest($request, $next);
            });
        }

        self::$instance = $this;
    }

    /**
     * Backwards-compatible factory that mirrors the pre-0.10.0 constructor
     * signature `new Application(string $configPath)`.
     *
     * @param string $configPath Absolute path to a PHP file returning the
     *                           server config array.
     *
     * @return self
     *
     * @throws \RuntimeException If the config file does not exist or does
     *                           not return an array.
     *
     * @since 0.10.0
     *
     * @example
     * ```php
     * $app = Application::fromConfigPath('/etc/phlix/server.php');
     * $app->run();
     * ```
     */
    public static function fromConfigPath(string $configPath): self
    {
        if (!file_exists($configPath)) {
            throw new \RuntimeException("Configuration file not found: {$configPath}");
        }

        /** @var mixed $config */
        $config = include $configPath;

        if (!is_array($config)) {
            throw new \RuntimeException('Configuration file must return an array');
        }

        $normalized = [];
        /** @var mixed $value */
        foreach ($config as $key => $value) {
            if (!is_string($key)) {
                throw new \RuntimeException('Configuration file must return a string-keyed array');
            }
            $normalized[$key] = $value;
        }

        $container = ContainerFactory::create($normalized);
        return new self($container, $normalized);
    }

    /**
     * Gets the singleton Application instance.
     *
     * @return Application|null The singleton instance, or null if not yet constructed
     *
     * @description Returns the global application instance for access throughout the application.
     *
     * @deprecated 0.10.0 Resolve services through the PSR-11 container
     *             ({@see ContainerInterface::get()}) instead of reaching for
     *             this singleton. Slated for removal in Phase B once all
     *             callers are migrated.
     */
    public static function getInstance(): ?Application
    {
        return self::$instance;
    }

    /**
     * Get the PSR-11 container that backs this application.
     *
     * @return ContainerInterface|null Null only when the application was
     *                                  built without a container (legacy
     *                                  test helpers).
     *
     * @since 0.10.0
     */
    public function getContainer(): ?ContainerInterface
    {
        return $this->container;
    }

    /**
     * Loads all application routes.
     *
     * Registers health check, system info, and API v1 routes.
     * Override this method in subclasses to add custom routes.
     *
     * @return void
     *
     * @see loadApiRoutes() For API route loading
     */
    private function loadRoutes(): void
    {
        // Health check endpoint - verifies server is responsive
        $this->router->get('/health', function (Request $request): Response {
            return (new Response())->json([
                'status' => 'ok',
                'timestamp' => time(),
                'version' => '1.0.0',
            ]);
        });

        // System info endpoint - returns server metadata
        $this->router->get('/system/info', function (Request $request): Response {
            $serverConfig = $this->config['server'] ?? [];
            $serverName = is_array($serverConfig) && isset($serverConfig['name']) && is_string($serverConfig['name'])
                ? $serverConfig['name']
                : 'Phlix Media Server';

            return (new Response())->json([
                'server' => $serverName,
                'version' => '1.0.0',
                'php_version' => PHP_VERSION,
                'workerman_version' => \Workerman\Worker::VERSION,
            ]);
        });

        // JWKS endpoint for hub-to-server JWT verification
        $this->router->get('/.well-known/jwks.json', function (Request $request, array $params): Response {
            $controller = $this->getHubJwksController();
            return $controller->handle($request, $params);
        });

        // API v1 routes
        $this->loadApiRoutes();
    }

    /**
     * Loads API v1 routes.
     *
     * Registers the user-facing JSON API surface: authentication, sessions,
     * media playback, WebAuthn, DLNA renderer control, Chromecast, AirPlay,
     * Roku, and admin integrations. Override in subclasses to add additional
     * API routes.
     *
     * @return void
     */
    private function loadApiRoutes(): void
    {
        // API routes. Wire new routes here following the existing pattern;
        // controller responsibilities live under src/Server/Http/Controllers/.
        $this->router->get('/api/v1', function (Request $request): Response {
            return (new Response())->json([
                'api' => 'Phlix Media Server',
                'version' => 'v1',
                'endpoints' => '/health, /system/info',
            ]);
        });

        // Username/password authentication endpoints. The controller validates
        // input and delegates to AuthManager; `me` enforces 401 internally by
        // checking $request->userId (set by upstream auth middleware), matching
        // the pattern used by /api/v1/me/continue-watching on SessionController.
        $authController = $this->getAuthController();
        $this->router->post('/api/v1/auth/register', [$authController, 'register']);
        $this->router->post('/api/v1/auth/login', [$authController, 'login']);
        $this->router->post('/api/v1/auth/refresh', [$authController, 'refresh']);
        $this->router->get('/api/v1/auth/me', [$authController, 'me']);

        // Hub JWT exchange endpoint
        $this->router->post('/api/v1/auth/hub-token', function (Request $request, array $params): Response {
            $controller = $this->getHubTokenController();
            return $controller->handle($request, $params);
        });

        // Media item playback-info endpoint
        $mediaItemController = $this->getMediaItemController();
        $this->router->get('/api/v1/media/{id}/playback-info', [$mediaItemController, 'getPlaybackInfo']);

        // Marker endpoints — intro/outro/chapter markers used by the player's
        // "skip intro" / "skip outro" UI and bulk per-show export.
        $markerController = $this->getMarkerController();
        $this->router->get('/api/v1/media/{id}/markers', [$markerController, 'getMarkers']);
        $this->router->get('/api/v1/media/{id}/markers/intro', [$markerController, 'getIntroMarker']);
        $this->router->get('/api/v1/media/{id}/markers/outro', [$markerController, 'getOutroMarker']);
        $this->router->get('/api/v1/shows/{id}/markers/bulk', [$markerController, 'getShowMarkers']);

        // Extras endpoints — trailers and other extras (featurettes,
        // behind-the-scenes, interviews) for a media item.
        $extrasController = $this->getExtrasController();
        $this->router->get('/api/v1/media/{id}/extras', [$extrasController, 'getExtras']);
        $this->router->get('/api/v1/media/{id}/trailers', [$extrasController, 'getTrailers']);
        $this->router->get('/api/v1/media/{id}/extras/other', [$extrasController, 'getOtherExtras']);

        // Session management endpoints
        $sessionController = $this->getSessionController();
        $this->router->get('/api/v1/sessions/{id}/progress', [$sessionController, 'getProgress']);
        $this->router->post('/api/v1/sessions/{id}/progress', [$sessionController, 'reportProgress']);
        $this->router->get('/api/v1/me/continue-watching', [$sessionController, 'getContinueWatching']);
        $this->router->get('/api/v1/me/sessions', [$sessionController, 'listSessions']);
        $this->router->delete('/api/v1/sessions/{id}', [$sessionController, 'endSession']);

        // WebAuthn / Passkey endpoints
        $webauthn = $this->getWebAuthnController();
        $this->router->post('/api/v1/auth/webauthn/register/options', [$webauthn, 'startRegistration']);
        $this->router->post('/api/v1/auth/webauthn/register/verify', [$webauthn, 'finishRegistration']);
        $this->router->post('/api/v1/auth/webauthn/login/options', [$webauthn, 'startAuthentication']);
        $this->router->post('/api/v1/auth/webauthn/login/verify', [$webauthn, 'finishAuthentication']);
        $this->router->get('/api/v1/me/webauthn/credentials', [$webauthn, 'listCredentials']);
        $this->router->delete('/api/v1/me/webauthn/credentials/{id}', [$webauthn, 'deleteCredential']);

        // DLNA Content Directory Service (CDS) HTTP endpoints
        $this->loadCdsRoutes();

        // DLNA renderer control API endpoints
        $this->loadDlnaRendererRoutes();

        // Chromecast API endpoints
        $this->loadChromecastRoutes();

        // AirPlay 2 API endpoints
        $this->loadAirPlayRoutes();

        // Roku API endpoints
        $this->loadRokuRoutes();

        // Last.fm admin connect routes (G.3).
        $this->loadLastfmRoutes();

        // Library management and theme media routes (1.6b).
        $this->loadLibraryRoutes();

        // Collection management routes (1.6d).
        $this->loadCollectionRoutes();

        // Streaming routes for HLS and DASH (1.6e).
        $this->loadStreamingRoutes();

        // Media-type routes: music, books, audiobooks, photos (1.6f).
        $this->loadMusicRoutes();
        $this->loadBookRoutes();
        $this->loadAudiobookRoutes();
        $this->loadPhotoRoutes();

        // Media request UI moved to phlix-hub in K.3 — no server routes here.

        // Webhook admin integration routes (1.6g).
        $this->loadWebhookAdminRoutes();

        // ARR/Sync integration routes (1.6g).
        $this->loadArrSyncRoutes();

        // Trakt.tv OAuth integration routes (1.6g).
        $this->loadTraktRoutes();
    }

    /**
     * Registers webhook admin integration API routes.
     *
     * Wires endpoints for:
     * - WebhookAdminController: index, create, delete, test (4 routes)
     *
     * @since 0.14.0
     */
    private function loadWebhookAdminRoutes(): void
    {
        $controller = $this->getWebhookAdminController();

        // Webhook admin CRUD routes
        // GET /api/v1/admin/webhooks — list all webhooks
        $this->router->get('/api/v1/admin/webhooks', [$controller, 'index']);
        // POST /api/v1/admin/webhooks — create a new webhook
        $this->router->post('/api/v1/admin/webhooks', [$controller, 'create']);
        // DELETE /api/v1/admin/webhooks/{id} — delete a webhook
        $this->router->delete('/api/v1/admin/webhooks/{id}', [$controller, 'delete']);
        // POST /api/v1/admin/webhooks/{id}/test — test a webhook
        $this->router->post('/api/v1/admin/webhooks/{id}/test', [$controller, 'test']);
    }

    /**
     * Registers TRaSH-Guides ARR sync API routes.
     *
     * Wires endpoints for:
     * - SyncController: triggerSync, getSyncStatus, setEnabled (3 routes)
     *
     * @since 0.12.0
     */
    private function loadArrSyncRoutes(): void
    {
        $controller = $this->getArrSyncController();

        // TRaSH-Guides sync endpoints
        // POST /api/v1/admin/sync/trash-guides — trigger a full sync
        $this->router->post('/api/v1/admin/sync/trash-guides', [$controller, 'triggerSync']);
        // GET /api/v1/admin/sync/status — get sync status
        $this->router->get('/api/v1/admin/sync/status', [$controller, 'getSyncStatus']);
        // PUT /api/v1/admin/sync/enable — enable/disable auto-sync
        $this->router->put('/api/v1/admin/sync/enable', [$controller, 'setEnabled']);
    }

    /**
     * Registers Trakt.tv OAuth integration routes.
     *
     * Wires endpoints for:
     * - TraktOAuthController: authorize, callback (2 routes)
     *
     * @since 0.14.0
     */
    private function loadTraktRoutes(): void
    {
        $controller = $this->getTraktOAuthController();

        // Trakt OAuth flow endpoints
        // GET /api/v1/oauth/trakt — initiate OAuth flow (redirect to Trakt)
        $this->router->get('/api/v1/oauth/trakt', [$controller, 'authorize']);
        // GET /api/v1/oauth/trakt/callback — OAuth callback handler
        $this->router->get('/api/v1/oauth/trakt/callback', [$controller, 'callback']);
    }

    /**
     * Registers the admin-side "Connect Last.fm" flow routes.
     *
     * Wires the GET landing page, the OAuth-like token callback, and the
     * disconnect form post. The admin/auth middleware is configured at
     * the router level elsewhere; these routes only register the handlers.
     *
     * @since 0.15.0
     */
    private function loadLastfmRoutes(): void
    {
        try {
            $rawConfig = include __DIR__ . '/../../../config/lastfm.php';
            $config = \Phlix\Plugins\Scrobbler\Lastfm\LastfmConfig::fromArray(
                is_array($rawConfig) ? $rawConfig : []
            );
            $db = \Phlix\Common\Database\ConnectionPool::getConnection('mysql');
            $sessions = new \Phlix\Plugins\Scrobbler\Lastfm\LastfmSessionRepository($db);
            $api = new \Phlix\Plugins\Scrobbler\Lastfm\LastfmApi(
                $config->apiKey,
                $config->sharedSecret,
            );
            $controller = new \Phlix\Server\Http\Controllers\Admin\LastfmController(
                $config,
                $sessions,
                $api,
            );

            $this->router->get('/admin/lastfm', [$controller, 'index']);
            $this->router->get('/admin/lastfm/callback', [$controller, 'callback']);
            $this->router->post('/admin/lastfm/disconnect', [$controller, 'disconnect']);
        } catch (\Throwable) {
            // Last.fm not configured — silent ignore (e.g. DB not ready).
        }
    }

    /**
     * Registers library management and theme media API routes.
     *
     * Wires endpoints for:
     * - LibraryController: index, show, create, update, delete, scan, rescan (7 routes)
     * - ThemeMediaController: getThemeMedia, scanThemeMedia, deleteThemeMedia (3 routes)
     * - ThemeMediaStreamController: streamAudio, streamVideo (2 routes)
     *
     * @since 0.14.0
     */
    private function loadLibraryRoutes(): void
    {
        $libraryController = $this->getLibraryController();
        $themeMediaController = $this->getThemeMediaController();
        $themeMediaStreamController = $this->getThemeMediaStreamController();

        // Library CRUD routes
        $this->router->get('/api/v1/libraries', [$libraryController, 'index']);
        $this->router->get('/api/v1/libraries/{id}', [$libraryController, 'show']);
        $this->router->post('/api/v1/libraries', [$libraryController, 'create']);
        $this->router->put('/api/v1/libraries/{id}', [$libraryController, 'update']);
        $this->router->delete('/api/v1/libraries/{id}', [$libraryController, 'delete']);
        $this->router->post('/api/v1/libraries/{id}/scan', [$libraryController, 'scan']);
        $this->router->post('/api/v1/libraries/{id}/rescan', [$libraryController, 'rescan']);

        // Theme media routes
        $this->router->get('/api/v1/libraries/{id}/theme-media', [$themeMediaController, 'getThemeMedia']);
        $this->router->post('/api/v1/libraries/{id}/theme-media/scan', [$themeMediaController, 'scanThemeMedia']);
        $this->router->delete('/api/v1/libraries/{id}/theme-media', [$themeMediaController, 'deleteThemeMedia']);

        // Theme media streaming routes
        $this->router->get('/stream/theme-media/{libraryId}/audio', [$themeMediaStreamController, 'streamAudio']);
        $this->router->get('/stream/theme-media/{libraryId}/video', [$themeMediaStreamController, 'streamVideo']);
    }

    /**
     * Registers collection management API routes.
     *
     * Wires endpoints for:
     * - CollectionController: index, create, show, update, delete,
     *   addItem, removeItem, bulkAdd, refresh, forLibrary (10 routes)
     *
     * @since 0.14.0
     */
    private function loadCollectionRoutes(): void
    {
        $controller = $this->getCollectionController();

        // Collection CRUD routes
        $this->router->get('/api/v1/collections', [$controller, 'index']);
        $this->router->post('/api/v1/collections', [$controller, 'create']);
        $this->router->get('/api/v1/collections/{id}', [$controller, 'show']);
        $this->router->put('/api/v1/collections/{id}', [$controller, 'update']);
        $this->router->delete('/api/v1/collections/{id}', [$controller, 'delete']);

        // Collection item management
        $this->router->post('/api/v1/collections/{id}/items/{mediaItemId}', [$controller, 'addItem']);
        $this->router->delete('/api/v1/collections/{id}/items/{mediaItemId}', [$controller, 'removeItem']);

        // Bulk operations and smart collection refresh
        $this->router->post('/api/v1/collections/{id}/bulk-add', [$controller, 'bulkAdd']);
        $this->router->post('/api/v1/collections/{id}/refresh', [$controller, 'refresh']);

        // Library-scoped collections
        $this->router->get('/api/v1/libraries/{libraryId}/collections', [$controller, 'forLibrary']);
    }

    /**
     * Registers HLS and DASH streaming API routes.
     *
     * Wires endpoints for:
     * - HlsController: getMasterPlaylist, getVariantPlaylist, getSegment, getPlaylist (4 routes)
     * - DashController: getMasterManifest, getAdaptationSetManifest, getSegment, getManifest (4 routes)
     *
     * @since 0.14.0
     */
    private function loadStreamingRoutes(): void
    {
        $hlsController = $this->getHlsController();
        $dashController = $this->getDashController();

        // HLS streaming routes
        // GET /hls/{job_id}/master.m3u8 — master playlist with variant streams
        $this->router->get('/hls/{job_id}/master.m3u8', [$hlsController, 'getMasterPlaylist']);
        // GET /hls/{job_id}/{variant_index}/playlist.m3u8 — variant playlist for specific quality
        $this->router->get('/hls/{job_id}/{variant_index}/playlist.m3u8', [$hlsController, 'getVariantPlaylist']);
        // GET /hls/{job_id}/{variant_index}/{segment_number}.ts — individual segment file
        $this->router->get('/hls/{job_id}/{variant_index}/{segment_number}.ts', [$hlsController, 'getSegment']);
        // GET /hls/{job_id}/playlist — JSON with playlist URL info
        $this->router->get('/hls/{job_id}/playlist', [$hlsController, 'getPlaylist']);

        // DASH streaming routes
        // GET /dash/{job_id}/manifest.mpd — master manifest (MPD format)
        $this->router->get('/dash/{job_id}/manifest.mpd', [$dashController, 'getMasterManifest']);
        // GET /dash/{job_id}/{set_id}/manifest.mpd — adaptation set manifest
        $this->router->get('/dash/{job_id}/{set_id}/manifest.mpd', [$dashController, 'getAdaptationSetManifest']);
        // GET /dash/{job_id}/{set_id}/segment_{segment_number}.m4s — segment file (M4S container)
        $this->router->get('/dash/{job_id}/{set_id}/segment_{segment_number}.m4s', [$dashController, 'getSegment']);
        // GET /dash/{job_id}/manifest — JSON with manifest URL info
        $this->router->get('/dash/{job_id}/manifest', [$dashController, 'getManifest']);
    }

    /**
     * Registers music library API routes.
     *
     * Wires endpoints for:
     * - MusicController: listArtists, getArtist, listAlbums, getAlbum,
     *   listTracks, getTrack, nowPlaying (7 routes)
     *
     * @since 0.14.0
     */
    private function loadMusicRoutes(): void
    {
        $controller = $this->getMusicController();

        // Music library browsing routes
        $this->router->get('/api/v1/music/artists', [$controller, 'listArtists']);
        $this->router->get('/api/v1/music/artists/{mbid}', [$controller, 'getArtist']);
        $this->router->get('/api/v1/music/albums', [$controller, 'listAlbums']);
        $this->router->get('/api/v1/music/albums/{mbid}', [$controller, 'getAlbum']);
        $this->router->get('/api/v1/music/tracks', [$controller, 'listTracks']);
        $this->router->get('/api/v1/music/tracks/{id}', [$controller, 'getTrack']);

        // Now playing for the current session
        $this->router->get('/api/v1/music/now-playing', [$controller, 'nowPlaying']);
    }

    /**
     * Registers book library and OPDS feed API routes.
     *
     * Wires endpoints for:
     * - BookController: opdsRoot, opdsLibraries, opdsLibraryBooks,
     *   opdsBookCover, listBooks, getBook, readBook, getCover,
     *   downloadBook (9 routes)
     *
     * @since 0.17.0
     */
    private function loadBookRoutes(): void
    {
        $controller = $this->getBookController();

        // OPDS 1.2 feed endpoints
        $this->router->get('/opds/v1.2', [$controller, 'opdsRoot']);
        $this->router->get('/opds/v1.2/libraries', [$controller, 'opdsLibraries']);
        $this->router->get('/opds/v1.2/libraries/{id}', [$controller, 'opdsLibraryBooks']);
        $this->router->get('/opds/v1.2/books/{id}/cover', [$controller, 'opdsBookCover']);

        // Book web portal endpoints
        $this->router->get('/api/v1/books', [$controller, 'listBooks']);
        $this->router->get('/api/v1/books/{id}', [$controller, 'getBook']);
        $this->router->get('/api/v1/books/{id}/read', [$controller, 'readBook']);
        $this->router->get('/api/v1/books/{id}/cover', [$controller, 'getCover']);
        $this->router->get('/api/v1/books/{id}/download', [$controller, 'downloadBook']);
    }

    /**
     * Registers audiobook library API routes.
     *
     * Wires endpoints for:
     * - AudiobookController: listAudiobooks, getAudiobook, getChapters,
     *   getProgress, saveProgress, readAudiobook, streamAudiobook (7 routes)
     *
     * @since 0.18.0
     */
    private function loadAudiobookRoutes(): void
    {
        $controller = $this->getAudiobookController();

        // Audiobook library browsing and playback routes
        $this->router->get('/api/v1/audiobooks', [$controller, 'listAudiobooks']);
        $this->router->get('/api/v1/audiobooks/{id}', [$controller, 'getAudiobook']);
        $this->router->get('/api/v1/audiobooks/{id}/chapters', [$controller, 'getChapters']);
        $this->router->get('/api/v1/audiobooks/{id}/progress', [$controller, 'getProgress']);
        $this->router->post('/api/v1/audiobooks/{id}/progress', [$controller, 'saveProgress']);
        $this->router->get('/api/v1/audiobooks/{id}/read', [$controller, 'readAudiobook']);
        $this->router->get('/api/v1/audiobooks/{id}/stream', [$controller, 'streamAudiobook']);
    }

    /**
     * Registers photo library API routes.
     *
     * Wires endpoints for:
     * - PhotoController: listAlbums, getAlbum, listPhotos, getPhoto,
     *   getThumbnail, getFull, slideshow (7 routes)
     *
     * @since 0.16.0
     */
    private function loadPhotoRoutes(): void
    {
        $controller = $this->getPhotoController();

        // Photo album and photo browsing routes
        $this->router->get('/api/v1/photo/albums', [$controller, 'listAlbums']);
        $this->router->get('/api/v1/photo/albums/{id}', [$controller, 'getAlbum']);
        $this->router->get('/api/v1/photo/photos', [$controller, 'listPhotos']);
        $this->router->get('/api/v1/photo/photos/{id}', [$controller, 'getPhoto']);
        $this->router->get('/api/v1/photo/photos/{id}/thumbnail', [$controller, 'getThumbnail']);
        $this->router->get('/api/v1/photo/photos/{id}/full', [$controller, 'getFull']);

        // Slideshow endpoint
        $this->router->get('/api/v1/photo/slideshow', [$controller, 'slideshow']);
    }

    /**
     * Registers a global middleware handler.
     *
     * Middleware are executed in registration order before the request
     * is dispatched to the route handler.
     *
     * @param callable $middleware The middleware callback function
     * @return self For method chaining
     *
     * @example
     * ```php
     * $app->middleware(function($request) {
     *     // Authentication check
     *     if (!$request->bearerToken) {
     *         return (new Response())->status(401)->json(['error' => 'Unauthorized']);
     *     }
     *     // Continue to next handler
     * });
     * ```
     */
    public function middleware(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Runs the application, processing incoming HTTP requests.
     *
     * Creates a request from globals, applies middleware, dispatches
     * to the appropriate handler, and sends the response.
     *
     * @return void
     *
     * @throws Throwable Any unhandled exception during request processing
     *
     * @see Request::fromGlobals() For request creation
     * @see Router::dispatch() For route dispatching
     */
    public function run(): void
    {
        // Start hub heartbeat loop if already enrolled
        $this->startHubHeartbeatIfEnrolled();

        // Start relay tunnel if enrolled and relay is enabled
        $this->startRelayIfEnabled();

        // Start discovery server for SSDP/mDNS device discovery
        $this->startDiscoveryIfEnabled();

        // Start newsletter timer if enabled
        $this->startNewsletterTimerIfEnabled();

        // Start backup timer if enabled
        $this->startBackupTimerIfEnabled();

        $request = Request::fromGlobals();

        // Build the final handler that dispatches to the router
        $finalHandler = function (Request $request): Response {
            return $this->router->dispatch($request);
        };

        // Apply global middleware in reverse order (so first registered runs first)
        $handler = $finalHandler;
        foreach (array_reverse($this->middleware) as $currentHandler) {
            $nextHandler = $handler;
            $handler = static function (Request $request) use ($currentHandler, $nextHandler) {
                return $currentHandler($request, $nextHandler);
            };
        }

        // Execute the middleware chain
        try {
            $response = $handler($request);
            $response->send();
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Handles uncaught exceptions.
     *
     * Logs the exception details and sends an appropriate error response
     * to the client. In debug mode, includes additional error information.
     *
     * @param Throwable $e The uncaught exception
     * @return void
     *
     * @see LoggerFactory::get() For logging setup
     */
    private function handleException(Throwable $e): void
    {
        $logger = LoggerFactory::get(LogChannels::HTTP);
        $logger->error('Unhandled exception: ' . $e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        $response = (new Response())
            ->status(500)
            ->json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage(),
            ]);

        if ($this->config['debug'] ?? false) {
            $response->json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        $response->send();
    }

    /**
     * Gets the application router.
     *
     * @return Router The router instance for route management
     *
     * @description Provides access to the router for testing or custom route manipulation.
     */
    public function getRouter(): Router
    {
        return $this->router;
    }

    /**
     * Starts the hub heartbeat background worker if the server is enrolled.
     *
     * @return void
     */
    /**
     * Starts the relay tunnel worker if the server is enrolled and relay is enabled.
     *
     * @return void
     */
    private function startRelayIfEnabled(): void
    {
        if ($this->container === null) {
            return;
        }

        try {
            $relayApp = $this->container->get(RelayApplication::class);
            if ($relayApp instanceof RelayApplication) {
                $relayApp->start();
            }
        } catch (\Throwable) {
            // Relay not configured — silent ignore
        }
    }

    private function startHubHeartbeatIfEnrolled(): void
    {
        if ($this->container === null) {
            return;
        }

        try {
            $hubApp = $this->container->get(HubApplication::class);
            if ($hubApp instanceof HubApplication) {
                $hubApp->start();
            }
        } catch (\Throwable) {
            // Hub is not configured or not enrolled — silent ignore
        }
    }

    /**
     * Start the discovery server for SSDP/mDNS device discovery.
     *
     * @return void
     */
    private function startDiscoveryIfEnabled(): void
    {
        if ($this->container === null) {
            return;
        }

        try {
            $discoveryServer = $this->container->get(DiscoveryServer::class);
            if ($discoveryServer instanceof DiscoveryServer) {
                $discoveryServer->start();
            }
        } catch (\Throwable) {
            // Discovery not configured — silent ignore
        }
    }

    /**
     * Start the newsletter timer for weekly email delivery.
     *
     * If newsletter is enabled in config, registers a periodic timer to process
     * the newsletter queue and send emails to eligible users.
     *
     * @return void
     *
     * @since 0.19.0
     */
    private function startNewsletterTimerIfEnabled(): void
    {
        $newsletterRaw = $this->config['newsletter'] ?? [];
        if (!is_array($newsletterRaw)) {
            return;
        }
        /** @var array<string, mixed> $newsletterConfig */
        $newsletterConfig = $newsletterRaw;

        if (empty($newsletterConfig['enabled'])) {
            return;
        }

        if ($this->container === null) {
            return;
        }

        try {
            $sendDay = self::intConfig($newsletterConfig, 'send_day', 0);
            $sendHour = self::intConfig($newsletterConfig, 'send_hour', 9);
            $batchSize = self::intConfig($newsletterConfig, 'batch_size', 50);
            $templateDir = self::stringConfig($newsletterConfig, 'template_dir', 'public/templates');

            $db = \Phlix\Common\Database\ConnectionPool::getConnection('mysql');

            $sender = new \Phlix\Admin\NewsletterSender(
                $db,
                \Phlix\Common\Logger\LoggerFactory::get(\Phlix\Common\Logger\LogChannels::MEDIA),
                array_merge($newsletterConfig, ['template_dir' => $templateDir])
            );

            $generator = new \Phlix\Admin\NewsletterGenerator(
                new \Phlix\Stats\StatsCollector($db),
                new \Phlix\Media\Library\LibraryManager(
                    $db,
                    new \Phlix\Media\Library\MediaScanner(
                        $db,
                        new \Phlix\Media\Library\ItemRepository($db),
                    ),
                    new \Phlix\Media\Library\FolderWatcher()
                ),
                $db,
                $templateDir,
                $newsletterConfig
            );

            $this->registerNewsletterTimer($sender, $generator, $sendDay, $sendHour, $batchSize);
        } catch (\Throwable $e) {
            $logger = \Phlix\Common\Logger\LoggerFactory::get(\Phlix\Common\Logger\LogChannels::MEDIA);
            $logger->error('Failed to start newsletter timer', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Read a string value out of an untyped config sub-array, with a
     * fallback when the key is missing or the value is the wrong type.
     *
     * @param array<string, mixed> $config
     */
    private static function stringConfig(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    /**
     * Read an int value out of an untyped config sub-array, with a
     * fallback when the key is missing or the value is the wrong type.
     *
     * @param array<string, mixed> $config
     */
    private static function intConfig(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        return $default;
    }

    /**
     * Register the newsletter timer with Workerman.
     *
     * @param \Phlix\Admin\NewsletterSender $sender Newsletter sender instance
     * @param \Phlix\Admin\NewsletterGenerator $generator Newsletter generator instance
     * @param int $sendDay Day of week to send (0=Sunday)
     * @param int $sendHour Hour of day to send (0-23)
     * @param int $batchSize Number of emails per batch
     *
     * @return void
     */
    private function registerNewsletterTimer(
        \Phlix\Admin\NewsletterSender $sender,
        \Phlix\Admin\NewsletterGenerator $generator,
        int $sendDay,
        int $sendHour,
        int $batchSize
    ): void {
        $logger = \Phlix\Common\Logger\LoggerFactory::get(\Phlix\Common\Logger\LogChannels::MEDIA);

        \Workerman\Timer::add(1, function () use ($sender, $generator, $sendDay, $sendHour, $batchSize, $logger): void {
            $now = new \DateTime();

            if ((int) $now->format('w') !== $sendDay) {
                return;
            }

            if ((int) $now->format('G') !== $sendHour) {
                return;
            }

            $logger->info('Newsletter timer triggered', [
                'day' => $sendDay,
                'hour' => $sendHour,
            ]);

            $weekStart = clone $now;
            $weekStart->modify('-7 days');

            $userIds = $generator->getRecipientUserIds();
            $queued = $sender->queueAll($userIds, $weekStart);

            $logger->info('Newsletter queue created', ['count' => $queued]);

            $processed = 0;
            while ($sender->getPendingCount() > 0) {
                $processed += $sender->processQueue($batchSize);
            }

            $logger->info('Newsletter batch processed', ['processed' => $processed]);

            $stats = $sender->getDeliveryStats();
            $logger->info('Newsletter delivery stats', $stats);
        });
    }

    /**
     * Start the backup timer for automatic scheduled backups.
     *
     * If backup is enabled in config, registers a periodic timer to create
     * automatic backups at the configured interval.
     *
     * @return void
     *
     * @since 0.19.0
     */
    private function startBackupTimerIfEnabled(): void
    {
        $configDirRaw = $this->config['_config_dir'] ?? 'config';
        $backupConfigPath = is_string($configDirRaw) ? $configDirRaw : 'config';
        $backupConfigFile = $backupConfigPath . '/backup.php';

        if (!file_exists($backupConfigFile)) {
            return;
        }

        /** @var mixed $backupConfig */
        $backupConfig = include $backupConfigFile;
        if (!is_array($backupConfig)) {
            return;
        }

        if (empty($backupConfig['enabled'])) {
            return;
        }

        $intervalDaysRaw = $backupConfig['auto_backup_interval_days'] ?? 7;
        $intervalDays = is_int($intervalDaysRaw)
            ? $intervalDaysRaw
            : (is_string($intervalDaysRaw) && is_numeric($intervalDaysRaw) ? (int) $intervalDaysRaw : 0);

        if ($intervalDays <= 0) {
            return;
        }

        try {
            $db = \Phlix\Common\Database\ConnectionPool::getConnection('mysql');
            $backupManager = new \Phlix\Admin\BackupManager(
                $db,
                \Phlix\Common\Logger\LoggerFactory::get(\Phlix\Common\Logger\LogChannels::APPLICATION)
            );

            $this->registerBackupTimer($backupManager, $intervalDays);
        } catch (\Throwable $e) {
            $logger = \Phlix\Common\Logger\LoggerFactory::get(\Phlix\Common\Logger\LogChannels::APPLICATION);
            $logger->error('Failed to start backup timer', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Register the backup timer with Workerman.
     *
     * @param \Phlix\Admin\BackupManager $backupManager Backup manager instance
     * @param int $intervalDays Backup interval in days
     *
     * @return void
     */
    private function registerBackupTimer(\Phlix\Admin\BackupManager $backupManager, int $intervalDays): void
    {
        $logger = \Phlix\Common\Logger\LoggerFactory::get(\Phlix\Common\Logger\LogChannels::APPLICATION);

        // Run daily to check if it's time for a backup
        \Workerman\Timer::add(86400, function () use ($backupManager, $intervalDays, $logger): void {
            $nextBackup = $backupManager->getNextScheduledBackup();

            if ($nextBackup === null) {
                return;
            }

            $now = time();

            // If we're past the scheduled time, create a backup
            if ($now >= $nextBackup) {
                $logger->info('Scheduled backup timer triggered', [
                    'interval_days' => $intervalDays,
                ]);

                try {
                    $result = $backupManager->createBackup('auto');
                    $logger->info('Scheduled backup created', [
                        'backup_id' => $result['backup_id'],
                        'size_bytes' => $result['size_bytes'],
                    ]);
                } catch (\Throwable $e) {
                    $logger->error('Scheduled backup failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * Returns a HubJwksController instance from the container.
     *
     * @return HubJwksController The controller instance.
     */
    private function getHubJwksController(): HubJwksController
    {
        if ($this->container === null) {
            return new HubJwksController(
                new HubClient(
                    new \Phlix\Hub\Ed25519KeyManager('config/hub-server-key.pem'),
                    new \Phlix\Hub\HttpClient('https://hub.example.com'),
                    new \Phlix\Common\Logger\StructuredLogger('hub', []),
                    'config',
                ),
            );
        }

        /** @var HubJwksController */
        $controller = $this->container->get(HubJwksController::class);
        return $controller;
    }

    /**
     * Returns an AuthController instance from the container.
     *
     * Falls back to a hand-wired instance only when no PSR-11 container is
     * present (legacy test helpers); production always resolves through DI.
     *
     * @return \Phlix\Server\Http\Controllers\AuthController The controller instance.
     */
    private function getAuthController(): \Phlix\Server\Http\Controllers\AuthController
    {
        if ($this->container === null) {
            $db = new \Phlix\Common\Database\PhlixMySQLConnection(
                '127.0.0.1',
                3306,
                'phlix',
                'root',
                'password'
            );
            $userRepo = new \Phlix\Auth\UserRepository($db);
            $auditLogger = new \Phlix\Common\Logger\AuditLogger(
                new \Phlix\Common\Logger\StructuredLogger('audit', [])
            );
            $authManager = new \Phlix\Auth\AuthManager(
                $userRepo,
                new \Phlix\Auth\JwtHandler('fallback-secret-for-tests'),
                $auditLogger
            );
            return new \Phlix\Server\Http\Controllers\AuthController($authManager);
        }

        /** @var \Phlix\Server\Http\Controllers\AuthController */
        $controller = $this->container->get(\Phlix\Server\Http\Controllers\AuthController::class);
        return $controller;
    }

    /**
     * Returns a HubTokenController instance from the container.
     *
     * @return \Phlix\Server\Http\Controllers\HubTokenController The controller instance.
     */
    private function getHubTokenController(): \Phlix\Server\Http\Controllers\HubTokenController
    {
        if ($this->container === null) {
            return new \Phlix\Server\Http\Controllers\HubTokenController(
                new \Phlix\Hub\HubJwtValidator(
                    'https://hub.example.com/.well-known/jwks.json',
                    new \Phlix\Hub\HttpClientFactory(),
                    new \Psr\Log\NullLogger(),
                    'test-server-id',
                ),
                new \Phlix\Auth\JwtHandler('fallback-secret-for-tests'),
            );
        }

        /** @var \Phlix\Server\Http\Controllers\HubTokenController */
        $controller = $this->container->get(\Phlix\Server\Http\Controllers\HubTokenController::class);
        return $controller;
    }

    /**
     * Loads DLNA Content Directory Service (CDS) HTTP routes.
     *
     * Registers endpoints for:
     * - GET /description.xml - Device description XML
     * - POST /cds/control - CDS SOAP control endpoint
     * - GET /scpd/{service}.xml - SCPD XML for services
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function loadCdsRoutes(): void
    {
        if ($this->container === null) {
            return;
        }

        try {
            $cdsServer = $this->container->get(\Phlix\Dlna\CdsServer::class);
            if (!$cdsServer instanceof \Phlix\Dlna\CdsServer) {
                return;
            }

            // Device description endpoint
            $deviceDescController = new \Phlix\Server\Http\Controllers\Dlna\DeviceDescriptionController($cdsServer);
            $this->router->get('/description.xml', [$deviceDescController, 'handle']);

            // CDS control endpoint
            $cdsControlController = new \Phlix\Server\Http\Controllers\Dlna\CdsControlController($cdsServer);
            $this->router->post('/cds/control', [$cdsControlController, 'handle']);

            // SCPD XML endpoints - route pattern matches /scpd/{service}.xml
            $this->router->get('/scpd/{service}.xml', function (
                \Phlix\Server\Http\Request $request,
                array $params
            ) use ($cdsServer): \Phlix\Server\Http\Response {
                $serviceRaw = $params['service'] ?? '';
                $service = is_string($serviceRaw) ? $serviceRaw : '';
                $scpdXml = $cdsServer->getScpdXml($service);

                if ($scpdXml === null) {
                    return (new \Phlix\Server\Http\Response())->status(404)->text('Service not found');
                }

                return (new \Phlix\Server\Http\Response())
                    ->header('Content-Type', 'application/xml; charset=utf-8')
                    ->header('Cache-Control', 'no-cache, must-revalidate')
                    ->text($scpdXml);
            });
        } catch (\Throwable $e) {
            // CDS not configured - silent ignore
        }
    }

    /**
     * Loads DLNA renderer control API routes.
     *
     * Registers endpoints for:
     * - GET /api/v1/dlna/renderers — list discovered renderers
     * - POST /api/v1/dlna/renderers/{id}/play — start "play to" session
     * - POST /api/v1/dlna/renderers/{id}/pause — pause playback
     * - POST /api/v1/dlna/renderers/{id}/stop — stop playback
     * - POST /api/v1/dlna/renderers/{id}/seek — seek to position
     * - GET /api/v1/dlna/renderers/{id}/status — get renderer state
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function loadDlnaRendererRoutes(): void
    {
        if ($this->container === null) {
            return;
        }

        try {
            $playToManager = $this->container->get(\Phlix\Dlna\PlayToManager::class);
            if (!$playToManager instanceof \Phlix\Dlna\PlayToManager) {
                return;
            }
            $controller = new \Phlix\Server\Http\Controllers\Dlna\RendererListController($playToManager);

            // List renderers
            $this->router->get('/api/v1/dlna/renderers', [$controller, 'listRenderers']);

            // Get renderer status
            $this->router->get('/api/v1/dlna/renderers/{id}/status', [$controller, 'getStatus']);

            // Start play-to session
            $this->router->post('/api/v1/dlna/renderers/{id}/play', [$controller, 'playTo']);

            // Pause playback
            $this->router->post('/api/v1/dlna/renderers/{id}/pause', [$controller, 'pause']);

            // Stop playback
            $this->router->post('/api/v1/dlna/renderers/{id}/stop', [$controller, 'stop']);

            // Seek to position
            $this->router->post('/api/v1/dlna/renderers/{id}/seek', [$controller, 'seek']);
        } catch (\Throwable $e) {
            // PlayToManager not configured - silent ignore
        }
    }

    /**
     * Loads Chromecast API routes.
     *
     * Registers endpoints for:
     * - GET /api/v1/cast/devices — list discovered Chromecast devices
     * - POST /api/v1/cast/devices/{id}/cast — start casting
     * - POST /api/v1/cast/devices/{id}/play — resume playback
     * - POST /api/v1/cast/devices/{id}/pause — pause playback
     * - POST /api/v1/cast/devices/{id}/stop — stop casting
     * - POST /api/v1/cast/devices/{id}/seek — seek to position
     * - GET /api/v1/cast/devices/{id}/status — get session status
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function loadChromecastRoutes(): void
    {
        if ($this->container === null) {
            return;
        }

        try {
            $castManager = $this->container->get(\Phlix\Chromecast\CastManager::class);
            if (!$castManager instanceof \Phlix\Chromecast\CastManager) {
                return;
            }
            $controller = new \Phlix\Server\Http\Controllers\Chromecast\ChromecastController($castManager);

            // List discovered devices
            $this->router->get('/api/v1/cast/devices', [$controller, 'listDevices']);

            // Start casting
            $this->router->post('/api/v1/cast/devices/{id}/cast', [$controller, 'cast']);

            // Playback controls
            $this->router->post('/api/v1/cast/devices/{id}/play', [$controller, 'play']);
            $this->router->post('/api/v1/cast/devices/{id}/pause', [$controller, 'pause']);
            $this->router->post('/api/v1/cast/devices/{id}/stop', [$controller, 'stop']);
            $this->router->post('/api/v1/cast/devices/{id}/seek', [$controller, 'seek']);

            // Get session status
            $this->router->get('/api/v1/cast/devices/{id}/status', [$controller, 'getStatus']);
        } catch (\Throwable $e) {
            // CastManager not configured - silent ignore
        }
    }

    /**
     * Loads Roku API routes.
     *
     * Registers endpoints for:
     * - GET /api/v1/roku/devices — list discovered Roku devices
     * - POST /api/v1/roku/devices/{id}/send — send media to Roku
     * - POST /api/v1/roku/devices/{id}/launch/{channelId} — launch a channel
     * - POST /api/v1/roku/devices/{id}/key/{keyName} — send keypress
     * - GET /api/v1/roku/devices/{id}/status — get session status
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function loadRokuRoutes(): void
    {
        if ($this->container === null) {
            return;
        }

        try {
            $rokuManager = $this->container->get(\Phlix\Roku\RokuManager::class);
            if (!$rokuManager instanceof \Phlix\Roku\RokuManager) {
                return;
            }
            $controller = new \Phlix\Server\Http\Controllers\Roku\RokuController($rokuManager);

            // List discovered devices
            $this->router->get('/api/v1/roku/devices', [$controller, 'listDevices']);

            // Send media to device
            $this->router->post('/api/v1/roku/devices/{id}/send', [$controller, 'sendMedia']);

            // Launch channel
            $this->router->post('/api/v1/roku/devices/{id}/launch/{channelId}', [$controller, 'launchChannel']);

            // Send keypress
            $this->router->post('/api/v1/roku/devices/{id}/key/{keyName}', [$controller, 'sendKey']);

            // Get session status
            $this->router->get('/api/v1/roku/devices/{id}/status', [$controller, 'getStatus']);
        } catch (\Throwable $e) {
            // RokuManager not configured - silent ignore
        }
    }

    /**
     * Loads AirPlay 2 API routes.
     *
     * Registers endpoints for:
     * - GET /api/v1/airplay/devices — list discovered AirPlay devices
     * - POST /api/v1/airplay/devices/{id}/stream — start streaming
     * - POST /api/v1/airplay/devices/{id}/pause — pause playback
     * - POST /api/v1/airplay/devices/{id}/resume — resume playback
     * - POST /api/v1/airplay/devices/{id}/stop — stop playback
     * - GET /api/v1/airplay/devices/{id}/status — get session status
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function loadAirPlayRoutes(): void
    {
        if ($this->container === null) {
            return;
        }

        try {
            $airPlayManager = $this->container->get(\Phlix\AirPlay\AirPlayManager::class);
            if (!$airPlayManager instanceof \Phlix\AirPlay\AirPlayManager) {
                return;
            }
            $controller = new \Phlix\Server\Http\Controllers\AirPlay\AirPlayController($airPlayManager);

            // List discovered devices
            $this->router->get('/api/v1/airplay/devices', [$controller, 'listDevices']);

            // Start streaming
            $this->router->post('/api/v1/airplay/devices/{id}/stream', [$controller, 'stream']);

            // Playback controls
            $this->router->post('/api/v1/airplay/devices/{id}/pause', [$controller, 'pause']);
            $this->router->post('/api/v1/airplay/devices/{id}/resume', [$controller, 'resume']);
            $this->router->post('/api/v1/airplay/devices/{id}/stop', [$controller, 'stop']);

            // Get session status
            $this->router->get('/api/v1/airplay/devices/{id}/status', [$controller, 'getStatus']);
        } catch (\Throwable $e) {
            // AirPlayManager not configured - silent ignore
        }
    }

    /**
     * Returns a WebAuthnController instance.
     *
     * @return \Phlix\Server\Http\Controllers\WebAuthnController The controller instance.
     */
    private function getWebAuthnController(): \Phlix\Server\Http\Controllers\WebAuthnController
    {
        if ($this->container === null) {
            $db = new \Phlix\Common\Database\PhlixMySQLConnection(
                '127.0.0.1',
                3306,
                'phlix',
                'root',
                'password'
            );
            $userRepo = new \Phlix\Auth\UserRepository($db);
            $credentialRepo = new \Phlix\Auth\WebAuthn\WebAuthnCredentialRepository($db);
            $settings = new \Phlix\Auth\WebAuthn\WebAuthnSettings(
                rpId: 'localhost',
                rpName: 'Phlix Media Server',
                rpOrigin: 'http://localhost:8080'
            );
            $webauthnManager = new \Phlix\Auth\WebAuthn\WebAuthnManager(
                $userRepo,
                $db,
                $credentialRepo,
                $settings
            );
            $auditLogger = new \Phlix\Common\Logger\AuditLogger(
                new \Phlix\Common\Logger\StructuredLogger('audit', [])
            );
            $authManager = new \Phlix\Auth\AuthManager(
                $userRepo,
                new \Phlix\Auth\JwtHandler('test-secret'),
                $auditLogger
            );
            return new \Phlix\Server\Http\Controllers\WebAuthnController($webauthnManager, $authManager);
        }

        /** @var \Phlix\Server\Http\Controllers\WebAuthnController */
        $controller = $this->container->get(\Phlix\Server\Http\Controllers\WebAuthnController::class);
        return $controller;
    }

    /**
     * Returns a MediaItemController instance.
     *
     * @return \Phlix\Server\Http\Controllers\MediaItemController The controller instance.
     */
    private function getMediaItemController(): \Phlix\Server\Http\Controllers\MediaItemController
    {
        if ($this->container === null) {
            $db = new \Phlix\Common\Database\PhlixMySQLConnection(
                '127.0.0.1',
                3306,
                'phlix',
                'root',
                'password'
            );
            $itemRepository = new \Phlix\Media\Library\ItemRepository($db);
            $markerCandidateRepository = new \Phlix\Media\Markers\Detection\MarkerCandidateRepository($itemRepository);
            $markerService = new \Phlix\Media\Markers\MarkerService($itemRepository, $markerCandidateRepository);
            return new \Phlix\Server\Http\Controllers\MediaItemController($itemRepository, $markerService);
        }

        /** @var \Phlix\Media\Library\ItemRepository */
        $itemRepository = $this->container->get(\Phlix\Media\Library\ItemRepository::class);
        $markerCandidateRepository = new \Phlix\Media\Markers\Detection\MarkerCandidateRepository($itemRepository);
        $markerService = new \Phlix\Media\Markers\MarkerService($itemRepository, $markerCandidateRepository);
        return new \Phlix\Server\Http\Controllers\MediaItemController($itemRepository, $markerService);
    }

    /**
     * Returns a MarkerController instance.
     *
     * Falls back to a hand-wired instance only when no PSR-11 container is
     * present (legacy test helpers); production always resolves through DI
     * so PHP-DI can autowire the controller and its dependencies
     * (ItemRepository, MarkerCandidateRepository, MarkerService).
     *
     * @return \Phlix\Server\Http\Controllers\MarkerController The controller instance.
     */
    private function getMarkerController(): \Phlix\Server\Http\Controllers\MarkerController
    {
        if ($this->container === null) {
            $db = new \Phlix\Common\Database\PhlixMySQLConnection(
                '127.0.0.1',
                3306,
                'phlix',
                'root',
                'password'
            );
            $itemRepository = new \Phlix\Media\Library\ItemRepository($db);
            $markerCandidateRepository = new \Phlix\Media\Markers\Detection\MarkerCandidateRepository($itemRepository);
            $markerService = new \Phlix\Media\Markers\MarkerService($itemRepository, $markerCandidateRepository);
            return new \Phlix\Server\Http\Controllers\MarkerController($markerService);
        }

        /** @var \Phlix\Server\Http\Controllers\MarkerController */
        $controller = $this->container->get(\Phlix\Server\Http\Controllers\MarkerController::class);
        return $controller;
    }

    /**
     * Returns an ExtrasController instance.
     *
     * Falls back to a hand-wired instance only when no PSR-11 container is
     * present (legacy test helpers); production always resolves through DI
     * so PHP-DI can autowire the controller, TrailerResolver, and the
     * TmdbProvider factory (which reads the API key from $appConfig['tmdb']
     * or the TMDB_API_KEY environment variable — see MediaServicesProvider).
     *
     * @return \Phlix\Server\Http\Controllers\ExtrasController The controller instance.
     */
    private function getExtrasController(): \Phlix\Server\Http\Controllers\ExtrasController
    {
        if ($this->container === null) {
            $db = new \Phlix\Common\Database\PhlixMySQLConnection(
                '127.0.0.1',
                3306,
                'phlix',
                'root',
                'password'
            );
            $itemRepository = new \Phlix\Media\Library\ItemRepository($db);
            $tmdbConfigRaw = @include __DIR__ . '/../../../config/tmdb.php';
            $tmdbApiKey = is_array($tmdbConfigRaw)
                && isset($tmdbConfigRaw['api_key'])
                && is_string($tmdbConfigRaw['api_key'])
                ? $tmdbConfigRaw['api_key']
                : (getenv('TMDB_API_KEY') ?: '');
            $tmdb = new \Phlix\Media\Metadata\TmdbProvider($tmdbApiKey);
            $extrasRepo = new \Phlix\Media\Extras\ExtrasRepository($db);
            $trailerFinder = new \Phlix\Media\Extras\TrailerFinder();
            $trailerResolver = new \Phlix\Media\Extras\TrailerResolver(
                $itemRepository,
                $tmdb,
                $extrasRepo,
                $trailerFinder
            );
            return new \Phlix\Server\Http\Controllers\ExtrasController($trailerResolver);
        }

        /** @var \Phlix\Server\Http\Controllers\ExtrasController */
        $controller = $this->container->get(\Phlix\Server\Http\Controllers\ExtrasController::class);
        return $controller;
    }

    /**
     * Returns a SessionController instance.
     *
     * @return \Phlix\Server\Http\Controllers\SessionController The controller instance.
     */
    private function getSessionController(): \Phlix\Server\Http\Controllers\SessionController
    {
        if ($this->container === null) {
            $db = new \Phlix\Common\Database\PhlixMySQLConnection(
                '127.0.0.1',
                3306,
                'phlix',
                'root',
                'password'
            );
            $sessionManager = new \Phlix\Session\SessionManager($db);
            $playbackController = new \Phlix\Session\PlaybackController($db, $sessionManager);
            $itemRepository = new \Phlix\Media\Library\ItemRepository($db);
            $markerCandidateRepository = new \Phlix\Media\Markers\Detection\MarkerCandidateRepository($itemRepository);
            $markerService = new \Phlix\Media\Markers\MarkerService($itemRepository, $markerCandidateRepository);
            return new \Phlix\Server\Http\Controllers\SessionController(
                $sessionManager,
                $playbackController,
                $markerService
            );
        }

        /** @var \Phlix\Session\SessionManager */
        $sessionManager = $this->container->get(\Phlix\Session\SessionManager::class);
        /** @var \Phlix\Session\PlaybackController */
        $playbackController = $this->container->get(\Phlix\Session\PlaybackController::class);
        /** @var \Phlix\Media\Library\ItemRepository */
        $itemRepository = $this->container->get(\Phlix\Media\Library\ItemRepository::class);
        $markerCandidateRepository = new \Phlix\Media\Markers\Detection\MarkerCandidateRepository($itemRepository);
        $markerService = new \Phlix\Media\Markers\MarkerService($itemRepository, $markerCandidateRepository);
        return new \Phlix\Server\Http\Controllers\SessionController(
            $sessionManager,
            $playbackController,
            $markerService
        );
    }

    /**
     * Returns a LibraryController instance.
     *
     * @return \Phlix\Server\Http\Controllers\LibraryController The controller instance.
     */
    private function getLibraryController(): \Phlix\Server\Http\Controllers\LibraryController
    {
        if ($this->container === null) {
            $db = new \Phlix\Common\Database\PhlixMySQLConnection(
                '127.0.0.1',
                3306,
                'phlix',
                'root',
                'password'
            );
            $libraryManager = new \Phlix\Media\Library\LibraryManager(
                $db,
                new \Phlix\Media\Library\MediaScanner(
                    $db,
                    new \Phlix\Media\Library\ItemRepository($db)
                ),
                new \Phlix\Media\Library\FolderWatcher()
            );
            return new \Phlix\Server\Http\Controllers\LibraryController($libraryManager);
        }

        /** @var \Phlix\Media\Library\LibraryManager */
        $libraryManager = $this->container->get(\Phlix\Media\Library\LibraryManager::class);
        $controller = new \Phlix\Server\Http\Controllers\LibraryController($libraryManager);

        // Wire admin middleware if available
        if ($this->container->has(\Phlix\Server\Http\Middleware\AdminMiddleware::class)) {
            /** @var \Phlix\Server\Http\Middleware\AdminMiddleware */
            $adminMiddleware = $this->container->get(\Phlix\Server\Http\Middleware\AdminMiddleware::class);
            $controller->setAdminMiddleware($adminMiddleware);
        }

        return $controller;
    }

    /**
     * Returns a ThemeMediaController instance.
     *
     * @return \Phlix\Server\Http\Controllers\ThemeMediaController The controller instance.
     */
    private function getThemeMediaController(): \Phlix\Server\Http\Controllers\ThemeMediaController
    {
        if ($this->container === null) {
            $db = new \Phlix\Common\Database\PhlixMySQLConnection(
                '127.0.0.1',
                3306,
                'phlix',
                'root',
                'password'
            );
            $themeMediaRepository = new \Phlix\Theming\ThemeMediaRepository($db);
            $themeMediaFinder = new \Phlix\Theming\ThemeMediaFinder();
            $libraryManager = new \Phlix\Media\Library\LibraryManager(
                $db,
                new \Phlix\Media\Library\MediaScanner(
                    $db,
                    new \Phlix\Media\Library\ItemRepository($db)
                ),
                new \Phlix\Media\Library\FolderWatcher()
            );
            return new \Phlix\Server\Http\Controllers\ThemeMediaController(
                $themeMediaRepository,
                $themeMediaFinder,
                $libraryManager
            );
        }

        /** @var \Phlix\Theming\ThemeMediaRepository */
        $themeMediaRepository = $this->container->get(\Phlix\Theming\ThemeMediaRepository::class);
        /** @var \Phlix\Theming\ThemeMediaFinder */
        $themeMediaFinder = $this->container->get(\Phlix\Theming\ThemeMediaFinder::class);
        /** @var \Phlix\Media\Library\LibraryManager */
        $libraryManager = $this->container->get(\Phlix\Media\Library\LibraryManager::class);
        return new \Phlix\Server\Http\Controllers\ThemeMediaController(
            $themeMediaRepository,
            $themeMediaFinder,
            $libraryManager
        );
    }

    /**
     * Returns a ThemeMediaStreamController instance.
     *
     * @return \Phlix\Server\Http\Controllers\ThemeMediaStreamController The controller instance.
     */
    private function getThemeMediaStreamController(): \Phlix\Server\Http\Controllers\ThemeMediaStreamController
    {
        if ($this->container === null) {
            $db = new \Phlix\Common\Database\PhlixMySQLConnection(
                '127.0.0.1',
                3306,
                'phlix',
                'root',
                'password'
            );
            $themeMediaRepository = new \Phlix\Theming\ThemeMediaRepository($db);
            return new \Phlix\Server\Http\Controllers\ThemeMediaStreamController($themeMediaRepository);
        }

        /** @var \Phlix\Theming\ThemeMediaRepository */
        $themeMediaRepository = $this->container->get(\Phlix\Theming\ThemeMediaRepository::class);
        return new \Phlix\Server\Http\Controllers\ThemeMediaStreamController($themeMediaRepository);
    }

    /**
     * Returns a CollectionController instance.
     *
     * @return \Phlix\Server\Http\Controllers\CollectionController The controller instance.
     */
    private function getCollectionController(): \Phlix\Server\Http\Controllers\CollectionController
    {
        if ($this->container === null) {
            $db = new \Phlix\Common\Database\PhlixMySQLConnection(
                '127.0.0.1',
                3306,
                'phlix',
                'root',
                'password'
            );
            $collectionRepo = new \Phlix\Collections\CollectionRepository($db);
            $collectionItemRepo = new \Phlix\Collections\CollectionItemRepository($db);
            $itemRepository = new \Phlix\Media\Library\ItemRepository($db);
            $smartPlaylistRepo = new \Phlix\Playlists\SmartPlaylistRepository($db);
            $smartPlaylistEngine = new \Phlix\Playlists\SmartPlaylistEngine($itemRepository);
            $collectionManager = new \Phlix\Collections\CollectionManager(
                $collectionRepo,
                $collectionItemRepo,
                $smartPlaylistEngine,
                $smartPlaylistRepo,
                $itemRepository
            );
            return new \Phlix\Server\Http\Controllers\CollectionController($collectionManager);
        }

        /** @var \Phlix\Collections\CollectionManager */
        $collectionManager = $this->container->get(\Phlix\Collections\CollectionManager::class);
        return new \Phlix\Server\Http\Controllers\CollectionController($collectionManager);
    }

    /**
     * Returns an HlsController instance.
     *
     * @return \Phlix\Server\Http\Controllers\HlsController The controller instance.
     */
    private function getHlsController(): \Phlix\Server\Http\Controllers\HlsController
    {
        if ($this->container === null) {
            $segmentDir = sys_get_temp_dir() . '/phlix_hls';
            $baseUrl = 'http://localhost:8096';
            $hlsStreamer = new \Phlix\Media\Streaming\HlsStreamer(
                $segmentDir,
                $baseUrl,
                new \Phlix\Media\Streaming\QualitySelector()
            );
            return new \Phlix\Server\Http\Controllers\HlsController($hlsStreamer);
        }

        /** @var \Phlix\Media\Streaming\HlsStreamer */
        $hlsStreamer = $this->container->get(\Phlix\Media\Streaming\HlsStreamer::class);
        return new \Phlix\Server\Http\Controllers\HlsController($hlsStreamer);
    }

    /**
     * Returns a DashController instance.
     *
     * @return \Phlix\Server\Http\Controllers\DashController The controller instance.
     */
    private function getDashController(): \Phlix\Server\Http\Controllers\DashController
    {
        if ($this->container === null) {
            $segmentDir = sys_get_temp_dir() . '/phlix_dash';
            $baseUrl = 'http://localhost:8096';
            $dashStreamer = new \Phlix\Media\Streaming\Dash\DashStreamer($segmentDir, $baseUrl);
            return new \Phlix\Server\Http\Controllers\DashController($dashStreamer);
        }

        // DashStreamer is not registered in the container, so we instantiate manually
        // Use same config pattern as HlsStreamer (segment_dir, base_url from $appConfig['hls'])
        $hlsConfigRaw = $this->config['hls'] ?? null;
        /** @var array<string, mixed> $hlsConfig */
        $hlsConfig = is_array($hlsConfigRaw) ? $hlsConfigRaw : [];
        $segmentDirRaw = $hlsConfig['segment_dir'] ?? null;
        $segmentDir = is_string($segmentDirRaw) ? $segmentDirRaw : sys_get_temp_dir() . '/phlix_dash';
        $baseUrlRaw = $hlsConfig['base_url'] ?? null;
        $baseUrl = is_string($baseUrlRaw) ? $baseUrlRaw : 'http://localhost:8096';

        $dashStreamer = new \Phlix\Media\Streaming\Dash\DashStreamer($segmentDir, $baseUrl);
        return new \Phlix\Server\Http\Controllers\DashController($dashStreamer);
    }

    /**
     * Returns a MusicController instance.
     *
     * @return \Phlix\Server\Http\Controllers\MusicController The controller instance.
     */
    private function getMusicController(): \Phlix\Server\Http\Controllers\MusicController
    {
        $db = $this->createDatabaseConnection();
        $itemRepo = new \Phlix\Media\Library\ItemRepository($db);
        $libraryManager = new \Phlix\Media\Library\LibraryManager(
            $db,
            new \Phlix\Media\Library\MediaScanner(
                $db,
                $itemRepo
            ),
            new \Phlix\Media\Library\FolderWatcher()
        );
        $sessionManager = new \Phlix\Session\SessionManager($db);
        $audioScanner = new \Phlix\Media\Library\AudioScanner($db, $itemRepo);
        $metadataManager = new \Phlix\Media\Metadata\MetadataManager(
            $db,
            $itemRepo
        );
        $musicManager = new \Phlix\Media\Library\MusicLibraryManager(
            $audioScanner,
            $metadataManager,
            $itemRepo,
            $db
        );

        return new \Phlix\Server\Http\Controllers\MusicController(
            $musicManager,
            $libraryManager,
            $sessionManager
        );
    }

    /**
     * Returns a BookController instance.
     *
     * @return \Phlix\Server\Http\Controllers\BookController The controller instance.
     */
    private function getBookController(): \Phlix\Server\Http\Controllers\BookController
    {
        $db = $this->createDatabaseConnection();
        $itemRepo = new \Phlix\Media\Library\ItemRepository($db);
        $libraryManager = new \Phlix\Media\Library\LibraryManager(
            $db,
            new \Phlix\Media\Library\MediaScanner(
                $db,
                $itemRepo
            ),
            new \Phlix\Media\Library\FolderWatcher()
        );
        $opdsBuilder = new \Phlix\Media\Metadata\OpdsFeedBuilder($itemRepo, 'http://localhost:8080');

        return new \Phlix\Server\Http\Controllers\BookController(
            $itemRepo,
            $libraryManager,
            $opdsBuilder
        );
    }

    /**
     * Returns an AudiobookController instance.
     *
     * @return \Phlix\Server\Http\Controllers\AudiobookController The controller instance.
     */
    private function getAudiobookController(): \Phlix\Server\Http\Controllers\AudiobookController
    {
        $db = $this->createDatabaseConnection();
        $itemRepo = new \Phlix\Media\Library\ItemRepository($db);
        $audioScanner = new \Phlix\Media\Library\AudiobookScanner($db, $itemRepo);
        $progressStore = new \Phlix\Media\Library\AudiobookProgressStore($db);
        $libraryManager = new \Phlix\Media\Library\AudiobookLibraryManager(
            $audioScanner,
            $itemRepo,
            $progressStore
        );

        return new \Phlix\Server\Http\Controllers\AudiobookController(
            $itemRepo,
            $libraryManager
        );
    }

    /**
     * Returns a PhotoController instance.
     *
     * @return \Phlix\Server\Http\Controllers\PhotoController The controller instance.
     */
    private function getPhotoController(): \Phlix\Server\Http\Controllers\PhotoController
    {
        $db = $this->createDatabaseConnection();
        $itemRepo = new \Phlix\Media\Library\ItemRepository($db);
        $photoScanner = new \Phlix\Media\Library\PhotoScanner($db, $itemRepo);
        $photoManager = new \Phlix\Media\Library\PhotoLibraryManager(
            $photoScanner,
            $itemRepo
        );
        $exifProvider = new \Phlix\Media\Metadata\ExifProvider($itemRepo);

        return new \Phlix\Server\Http\Controllers\PhotoController(
            $itemRepo,
            $photoManager,
            $exifProvider
        );
    }

    /**
     * Returns a WebhookAdminController instance.
     *
     * @return \Phlix\Server\Http\Controllers\Webhooks\WebhookAdminController The controller instance.
     */
    private function getWebhookAdminController(): \Phlix\Server\Http\Controllers\Webhooks\WebhookAdminController
    {
        $db = $this->createDatabaseConnection();
        $dispatcher = new \Phlix\Webhooks\WebhookDispatcher($db);
        $controller = new \Phlix\Server\Http\Controllers\Webhooks\WebhookAdminController($dispatcher);

        // Wire admin middleware if available
        if ($this->container !== null && $this->container->has(\Phlix\Server\Http\Middleware\AdminMiddleware::class)) {
            /** @var \Phlix\Server\Http\Middleware\AdminMiddleware */
            $adminMiddleware = $this->container->get(\Phlix\Server\Http\Middleware\AdminMiddleware::class);
            $controller->setAdminMiddleware($adminMiddleware);
        }

        return $controller;
    }

    /**
     * Returns an Arr\SyncController instance.
     *
     * @return \Phlix\Server\Http\Controllers\Arr\SyncController The controller instance.
     */
    private function getArrSyncController(): \Phlix\Server\Http\Controllers\Arr\SyncController
    {
        $db = $this->createDatabaseConnection();

        // Load ARR/Radarr configuration
        $arrConfigRaw = [];
        $configDirRaw = $this->config['_config_dir'] ?? 'config';
        $arrConfigFile = is_string($configDirRaw) ? $configDirRaw : 'config';
        $arrConfigFile .= '/arr.php';
        if (file_exists($arrConfigFile)) {
            /** @var mixed $arrConfigRaw */
            $arrConfigRaw = include $arrConfigFile;
        }
        /** @var array<string, mixed> $arrConfig */
        $arrConfig = is_array($arrConfigRaw) ? $arrConfigRaw : [];

        $radarrUrl = is_string($arrConfig['radarr_url'] ?? null) ? $arrConfig['radarr_url'] : '';
        $radarrApiKey = is_string($arrConfig['radarr_api_key'] ?? null) ? $arrConfig['radarr_api_key'] : '';

        $radarrClient = new \Phlix\Shared\Arr\RadarrClient($radarrUrl, $radarrApiKey);
        $provider = new \Phlix\Shared\Arr\TrashGuidesProvider();
        $logger = new \Phlix\Common\Logger\StructuredLogger('arr-sync', []);

        $syncer = new \Phlix\Server\Arr\CustomFormatSyncer(
            $radarrClient,
            $provider,
            $db,
            $logger
        );

        $controller = new \Phlix\Server\Http\Controllers\Arr\SyncController($syncer);

        // Wire admin middleware if available
        if ($this->container !== null && $this->container->has(\Phlix\Server\Http\Middleware\AdminMiddleware::class)) {
            /** @var \Phlix\Server\Http\Middleware\AdminMiddleware */
            $adminMiddleware = $this->container->get(\Phlix\Server\Http\Middleware\AdminMiddleware::class);
            $controller->setAdminMiddleware($adminMiddleware);
        }

        return $controller;
    }

    /**
     * Returns a TraktOAuthController instance.
     *
     * @return \Phlix\Server\Http\Controllers\TraktOAuthController The controller instance.
     */
    private function getTraktOAuthController(): \Phlix\Server\Http\Controllers\TraktOAuthController
    {
        $logger = null;
        if ($this->container !== null) {
            try {
                /** @var \Psr\Log\LoggerInterface */
                $logger = $this->container->get(\Psr\Log\LoggerInterface::class);
            } catch (\Throwable) {
                // Logger not available — use null
            }
        }

        return new \Phlix\Server\Http\Controllers\TraktOAuthController(
            logger: $logger,
            stateStore: null
        );
    }

    /**
     * Creates a database connection using config from the application.
     *
     * When a container is present, uses the connection pool which respects
     * the database configuration. Falls back to Workerman\MySQL\Connection
     * with default credentials when no container is available.
     *
     * @return \Workerman\MySQL\Connection The database connection
     */
    private function createDatabaseConnection(): \Workerman\MySQL\Connection
    {
        if ($this->container !== null) {
            // Prefer an explicit container binding (tests bind a mock here;
            // production code paths reach the same Connection via the
            // CoreServicesProvider factory). Only fall back to the
            // ConnectionPool / hardcoded defaults if the container has no
            // such binding configured.
            try {
                $bound = $this->container->get(\Workerman\MySQL\Connection::class);
                if ($bound instanceof \Workerman\MySQL\Connection) {
                    return $bound;
                }
            } catch (\Throwable) {
                // Container has no Connection binding; continue to pool.
            }
            try {
                return \Phlix\Common\Database\ConnectionPool::getConnection('mysql');
            } catch (\Throwable) {
                // Fall back to direct connection if pool not initialized
            }
        }

        // Fallback for when container is not available (legacy test helpers)
        $host = '127.0.0.1';
        $port = 3306;
        $user = 'root';
        $password = '';
        $database = 'phlix';

        // Try to read from app config if available
        if (isset($this->config['database'])) {
            $dbConfig = $this->config['database'];
            if (is_array($dbConfig)) {
                $host = is_string($dbConfig['host'] ?? null) ? $dbConfig['host'] : $host;
                $port = is_int($dbConfig['port'] ?? null) ? $dbConfig['port'] : $port;
                $user = is_string($dbConfig['username'] ?? null) ? $dbConfig['username'] : $user;
                $password = is_string($dbConfig['password'] ?? null) ? $dbConfig['password'] : $password;
                $database = is_string($dbConfig['database'] ?? null) ? $dbConfig['database'] : $database;
            }
        }

        return new \Phlix\Common\Database\PhlixMySQLConnection($host, $port, $user, $password, $database);
    }
}
