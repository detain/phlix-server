<?php

/**
 * Phlix media server component: Core.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Core;

use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Version;
use Phlix\Hub\HubClient;
use Phlix\Hub\HubApplication;
use Phlix\Hub\RelayApplication;
use Phlix\Discovery\DiscoveryServer;
use Phlix\Server\Http\Controllers\HubJwksController;
use Phlix\Server\Http\Controllers\MediaUserDataController;
use Phlix\Server\Http\Controllers\SyncPlayController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use Phlix\Server\Http\Routes\AdminRoutes;
use Phlix\Session\SyncPlay\SyncPlayManager;
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

    /** @var ConnectionPool Database connection pool (R5: injected, replacing static calls) */
    private ConnectionPool $connectionPool;

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
     * @param ConnectionPool       $connectionPool Database connection pool (R5).
     *
     * @since 0.10.0
     */
    public function __construct(ContainerInterface $container, array $config, ConnectionPool $connectionPool)
    {
        $this->container = $container;
        $this->config = $config;
        $this->connectionPool = $connectionPool;
        $this->router = new Router($container);
        $this->loadRoutes();

        // Register ThemeMiddleware from container if available
        if ($container->has(ThemeMiddleware::class)) {
            /** @var ThemeMiddleware */
            $themeMiddleware = $container->get(ThemeMiddleware::class);
            $this->middleware(function (Request $request, callable $next) use ($themeMiddleware): Response {
                return $themeMiddleware->onHttpRequest($request, $next);
            });
        }

        // Register AccessScheduleMiddleware from container if available
        // Runs after auth to enforce time-based access restrictions
        if ($container->has(\Phlix\Server\Http\Middleware\AccessScheduleMiddleware::class)) {
            /** @var \Phlix\Server\Http\Middleware\AccessScheduleMiddleware */
            $accessScheduleMiddleware = $container->get(\Phlix\Server\Http\Middleware\AccessScheduleMiddleware::class);
            $this->middleware(function (Request $request, callable $next) use ($accessScheduleMiddleware): Response {
                $result = $accessScheduleMiddleware($request);
                if ($result !== null) {
                    return $result;
                }
                return $next($request);
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
        /** @var ConnectionPool $connectionPool */
        $connectionPool = $container->get(ConnectionPool::class);
        return new self($container, $normalized, $connectionPool);
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
                'version' => Version::STRING,
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
                'version' => Version::STRING,
                'php_version' => PHP_VERSION,
                'workerman_version' => \Workerman\Worker::VERSION,
            ]);
        });

        // F6: Job health endpoint - reports stuck/running transcode + scan job
        // counts, oldest age, and the last reaper run time.
        $this->router->get('/admin/health/jobs', function (Request $request): Response {
            // The container is always set when loadRoutes() is called (from the
            // constructor), but PHPStan sees the property as ?ContainerInterface.
            // Guard against null to satisfy the type checker.
            if ($this->container === null) {
                return (new Response())->status(500)->json(['error' => 'Server misconfiguration']);
            }

            /** @var \Phlix\Media\Transcoding\TranscodeManager $transcodeManager */
            $transcodeManager = $this->container->get(\Phlix\Media\Transcoding\TranscodeManager::class);
            /** @var \Phlix\Media\Library\ScanJobRepository $scanRepo */
            $scanRepo = $this->container->get(\Phlix\Media\Library\ScanJobRepository::class);

            $transcodeStats = $transcodeManager->getTranscodeJobStats();
            $scanStats = $scanRepo->getRunningJobStats();
            $lastReaperRun = $transcodeManager->getLastReaperRun();

            return (new Response())->json([
                'transcode_jobs' => [
                    'running' => $transcodeStats['running'],
                    'oldest_age_seconds' => $transcodeStats['oldest_age_seconds'],
                    'oldest_started_at' => $transcodeStats['oldest_started_at'],
                ],
                'scan_jobs' => [
                    'running' => $scanStats['running'],
                    'oldest_age_seconds' => $scanStats['oldest_age_seconds'],
                    'oldest_started_at' => $scanStats['oldest_started_at'],
                ],
                'reaper' => [
                    'last_run_at' => $lastReaperRun !== null ? date('c', $lastReaperRun) : null,
                ],
            ]);
        });

        // P3B-S7: Network health monitoring endpoints
        $configDirRaw = $this->config['_config_dir'] ?? 'config';
        $configDir = is_string($configDirRaw) ? $configDirRaw : 'config';
        $healthController = new \Phlix\Server\Http\Controllers\Admin\HealthController(
            $this->container,
            $configDir,
        );

        // Relay health: returns tunnel status, hub heartbeat status, and active sessions
        $this->router->get(
            '/api/v1/health/relay',
            fn(Request $request, array $params): Response => $healthController->relayHealth($request, $params)
        );

        // Network health: measures hub heartbeat round-trip latency
        $this->router->get(
            '/api/v1/health/network',
            fn(Request $request, array $params): Response => $healthController->networkHealth($request, $params)
        );

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

        // Servers list endpoint — returns the local server info for the admin
        // "all servers" dropdown (Issue 1 fix)
        $this->router->get('/api/v1/servers', function (Request $request): Response {
            // Get server name from config
            $serverConfig = $this->config['server'] ?? [];
            $serverName = is_array($serverConfig) && isset($serverConfig['name']) && is_string($serverConfig['name'])
                ? $serverConfig['name']
                : 'Phlix Media Server';

            // Get server ID: try hub enrollment first, then config, then generate
            $serverId = null;
            $enrolledAt = null;

            $configDirRaw = $this->config['_config_dir'] ?? 'config';
            $configDir = is_string($configDirRaw) ? $configDirRaw : 'config';
            $enrollmentPath = rtrim($configDir, '/') . '/hub-enrollment.json';

            if (file_exists($enrollmentPath)) {
                $content = @file_get_contents($enrollmentPath);
                if ($content !== false) {
                    $data = json_decode($content, true);
                    if (is_array($data)) {
                        if (isset($data['server_id']) && is_string($data['server_id'])) {
                            $serverId = $data['server_id'];
                        }
                        if (isset($data['enrolled_at']) && is_int($data['enrolled_at'])) {
                            $enrolledAt = $data['enrolled_at'];
                        }
                    }
                }
            }

            // Fall back to config server.id if not enrolled
            if ($serverId === null || $serverId === '') {
                if (is_array($serverConfig) && isset($serverConfig['id']) && is_string($serverConfig['id'])) {
                    $serverId = $serverConfig['id'];
                }
            }

            // Generate a persistent ID if still missing (first run)
            if ($serverId === null || $serverId === '') {
                $serverId = \Phlix\Common\Uuid::v4();
            }

            // Build hostname from hub config
            $hubConfig = $this->config['hub'] ?? [];
            $publicUrl = is_array($hubConfig) && isset($hubConfig['public_url']) && is_string($hubConfig['public_url'])
                ? $hubConfig['public_url'] : '';

            if (
                $publicUrl === ''
                && is_array($hubConfig)
                && isset($hubConfig['domain'])
                && is_string($hubConfig['domain'])
            ) {
                $tlsEnabled = is_bool($hubConfig['tls_enabled'] ?? null)
                    ? $hubConfig['tls_enabled']
                    : true;
                $publicUrl = ($tlsEnabled ? 'https://' : 'http://') . $hubConfig['domain'];
            }

            $hostnameCandidates = [];
            if ($publicUrl !== '') {
                $hostnameCandidates[] = $publicUrl;
            }
            // Always include localhost as a fallback candidate
            $hostnameCandidates[] = 'http://localhost:8096';

            return (new Response())->json([
                'success' => true,
                'data' => [
                    [
                        'id' => $serverId,
                        'name' => $serverName,
                        'hostname' => $publicUrl ?: 'http://localhost:8096',
                        'online' => true,
                        'last_seen_at' => $enrolledAt ?? time(),
                        'hostname_candidates' => array_values(array_unique($hostnameCandidates)),
                    ],
                ],
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

        // Browser-form aliases for the login / register / refresh
        // endpoints. The Smarty templates under public/templates/auth/
        // post form-encoded data to `/auth/login` and `/auth/register`
        // without the `/api/v1` prefix — without these aliases the
        // browser flow 404s. AuthController detects these path-prefixed
        // hits via isBrowserRequest() and replies with a 302 + session
        // cookies instead of a JSON token blob.
        $this->router->post('/auth/register', [$authController, 'register']);
        $this->router->post('/auth/login', [$authController, 'login']);
        $this->router->post('/auth/refresh', [$authController, 'refresh']);
        // GET so a plain `<a href="/auth/logout">` works without JS.
        $this->router->get('/auth/logout', [$authController, 'logout']);
        $this->router->post('/auth/logout', [$authController, 'logout']);

        // Hub JWT exchange endpoint
        $this->router->post('/api/v1/auth/hub-token', function (Request $request, array $params): Response {
            $controller = $this->getHubTokenController();
            return $controller->handle($request, $params);
        });

        // Media index endpoint (Step 8.3) — auth-gated, mirrors WebPortalRouter.
        // Returns bucket metadata for any indexable field (name, year, rating,
        // runtime, date_added), scoped to the same filters as GET /api/v1/media.
        if ($this->container !== null) {
            $container = $this->container;
            $this->router->group(
                '',
                function (Router $r) use ($container): void {
                    $r->get('/api/v1/media/index', function (
                        Request $request,
                        array $params
                    ) use ($container): Response {
                        // Extract media query params (mirrors WebPortalRouter::extractMediaQueryParams)
                        $paramsArr = [];
                        $search = $request->queryString('search');
                        if ($search !== null && $search !== '') {
                            $paramsArr['search'] = $search;
                        }
                        $genres = $request->query['genres'] ?? null;
                        if (is_array($genres) && count($genres) > 0) {
                            $paramsArr['genres'] = array_filter($genres, 'is_string');
                        }
                        $yearFrom = $request->queryString('yearFrom');
                        if ($yearFrom !== null && is_numeric($yearFrom)) {
                            $paramsArr['yearFrom'] = (int) $yearFrom;
                        }
                        $yearTo = $request->queryString('yearTo');
                        if ($yearTo !== null && is_numeric($yearTo)) {
                            $paramsArr['yearTo'] = (int) $yearTo;
                        }
                        $ratings = $request->query['ratings'] ?? null;
                        if (is_array($ratings) && count($ratings) > 0) {
                            $paramsArr['ratings'] = array_filter($ratings, 'is_string');
                        }
                        $actors = $request->query['actors'] ?? null;
                        if (is_array($actors) && count($actors) > 0) {
                            $paramsArr['actors'] = array_filter($actors, 'is_string');
                        }
                        $companies = $request->query['companies'] ?? null;
                        if (is_array($companies) && count($companies) > 0) {
                            $paramsArr['companies'] = array_filter($companies, 'is_string');
                        }

                        $libraryIdRaw = $request->queryString('libraryId');
                        $libraryId = ($libraryIdRaw !== null && $libraryIdRaw !== '') ? $libraryIdRaw : null;

                        $field = $request->queryString('field') ?? 'name';
                        $order = strtolower($request->queryString('order') ?? 'asc');

                        try {
                            /** @var \Phlix\Media\Library\ItemRepository $itemRepository */
                            $itemRepository = $container->get(\Phlix\Media\Library\ItemRepository::class);
                        } catch (\Throwable) {
                            return (new Response())->status(503)->json(['error' => 'Service unavailable']);
                        }
                        $rawBuckets = $itemRepository->valueBuckets($field, $paramsArr, $libraryId);

                        $indexBuckets = new \Phlix\Media\Library\IndexBuckets();
                        $buckets = $indexBuckets->build($field, $rawBuckets, $order);
                        $buckets = $indexBuckets->withOffsets($buckets);

                        $total = array_sum(array_column($buckets, 'count'));

                        return (new Response())->json([
                            'field' => $field,
                            'buckets' => $buckets,
                            'total' => $total,
                        ]);
                    });
                },
                [new \Phlix\Server\Http\Middleware\AuthMiddleware()]
            );
        }

        // Media item playback-info endpoint
        $mediaItemController = $this->getMediaItemController();
        $this->router->get('/api/v1/media/{id}/playback-info', [$mediaItemController, 'getPlaybackInfo']);

        // Trickplay sprite and timeline URLs (public, no auth required).
        // These point to the existing /trickplay/{itemId}/ routes.
        $this->router->get('/api/v1/media/{id}/trickplay', [$mediaItemController, 'getTrickplay']);

        // Chapter thumbnail endpoint (public, no auth required).
        // Returns the thumbnail image for a specific chapter.
        $this->router->get('/api/v1/media/{id}/chapters/{index}/thumbnail', [$mediaItemController, 'getChapterThumbnail']);

        // Download URL/info endpoint (public, returns a signed URL).
        $this->router->get('/api/v1/media/{id}/download', [$mediaItemController, 'getDownload']);

        // Interactive per-item metadata match (S5). Admin-gated inside the
        // controller (same protection as the whole-library match endpoint).
        $mediaMatchController = $this->getMediaMatchController();
        $this->router->get('/api/v1/media/{id}/match/search', [$mediaMatchController, 'search']);
        $this->router->post('/api/v1/media/{id}/match/apply', [$mediaMatchController, 'apply']);

        // Candidate poster listing and poster selection (Step 15.1/15.2).
        // Admin-gated inside the controller.
        $mediaPosterController = $this->getMediaPosterController();
        $this->router->get('/api/v1/media/{id}/posters', [$mediaPosterController, 'listPosters']);
        $this->router->put('/api/v1/media/{id}/poster', [$mediaPosterController, 'setPoster']);

        // On-demand transcode: start (or reuse) an HLS job for a media item, and
        // poll its readiness. The web player calls these when a file can't be
        // direct-played; the master playlist URL is served by HlsController.
        $transcodeController = $this->getTranscodeController();
        $this->router->post('/api/v1/media/{id}/transcode', [$transcodeController, 'start']);
        $this->router->get('/api/v1/transcode/{jobId}/status', [$transcodeController, 'status']);

        // Marker + extras endpoints — per-item metadata from the DB (intro/outro
        // markers for the "skip intro" UI, bulk per-show export, trailers and
        // other extras). Require a signed-in user (same as the media listings).
        $markerController = $this->getMarkerController();
        $extrasController = $this->getExtrasController();
        $subtitleController = $this->getSubtitleController();
        $this->router->group(
            '',
            function (Router $r) use ($markerController, $extrasController, $subtitleController): void {
                $r->get('/api/v1/media/{id}/markers', [$markerController, 'getMarkers']);
                $r->get('/api/v1/media/{id}/markers/intro', [$markerController, 'getIntroMarker']);
                $r->get('/api/v1/media/{id}/markers/outro', [$markerController, 'getOutroMarker']);
                $r->get('/api/v1/shows/{id}/markers/bulk', [$markerController, 'getShowMarkers']);
                $r->get('/api/v1/media/{id}/extras', [$extrasController, 'getExtras']);
                $r->get('/api/v1/media/{id}/trailers', [$extrasController, 'getTrailers']);
                $r->get('/api/v1/media/{id}/extras/other', [$extrasController, 'getOtherExtras']);
                // On-demand subtitle tracks for a direct-play client (no HLS
                // sidecars): list the embedded text tracks. (The per-track
                // extraction endpoint is registered below under the signed-URL
                // gate — a <track> element can't attach a Bearer header.)
                $r->get('/api/v1/media/{id}/subtitles', [$subtitleController, 'listTracks']);
            },
            [new \Phlix\Server\Http\Middleware\AuthMiddleware()]
        );

        // Subtitle extraction is fetched by a <track> element, which cannot
        // attach an Authorization header — so like /media/{id}/stream it
        // accepts an existing session OR the signed `?exp&sig` token that
        // playback-info's subtitle_tracks[].url carries (see StreamTrackShaper).
        $this->router->group(
            '',
            function (Router $r) use ($subtitleController): void {
                $r->get('/api/v1/media/{id}/subtitles/{index}', [$subtitleController, 'getTrack']);
            },
            [new \Phlix\Server\Http\Middleware\SignedUrlMiddleware()]
        );

        // Skip/intros marker CRUD (P3-S1) — user-editable markers stored in media_markers table.
        // Note: GET /api/v1/media/{id}/markers is handled by MarkerController (skip marker set).
        // MediaMarkerController handles user marker CREATE and DELETE only.
        $mediaMarkerController = $this->getMediaMarkerController();
        $this->router->group(
            '',
            function (Router $r) use ($mediaMarkerController): void {
                // Note: GET /api/v1/media/{id}/markers is NOT registered here to avoid
                // conflicting with MarkerController's skip marker set endpoint.
                // User marker creation and deletion:
                $r->post('/api/v1/media/{id}/markers', [$mediaMarkerController, 'createMarker']);
                $r->delete('/api/v1/media/{id}/markers/{markerId}', [$mediaMarkerController, 'deleteMarker']);
            },
            [new \Phlix\Server\Http\Middleware\AuthMiddleware()]
        );

        // Session management endpoints
        $sessionController = $this->getSessionController();
        $this->router->post('/api/v1/sessions', [$sessionController, 'createSession']);
        $this->router->get('/api/v1/sessions/{id}/progress', [$sessionController, 'getProgress']);
        $this->router->post('/api/v1/sessions/{id}/progress', [$sessionController, 'reportProgress']);
        $this->router->get('/api/v1/me/continue-watching', [$sessionController, 'getContinueWatching']);
        $this->router->get('/api/v1/me/sessions', [$sessionController, 'listSessions']);
        $this->router->delete('/api/v1/sessions/{id}', [$sessionController, 'endSession']);

        // Watch state endpoints (Step 11.6) — auth-gated, same as session routes.
        if ($this->container !== null) {
            try {
                /** @var MediaUserDataController $mediaUserDataController */
                $mediaUserDataController = $this->container->get(MediaUserDataController::class);
                $this->router->group(
                    '',
                    function (Router $r) use ($mediaUserDataController): void {
                        $r->post('/api/v1/media/{id}/watched', [$mediaUserDataController, 'markWatched']);
                        $r->post('/api/v1/media/{id}/unwatched', [$mediaUserDataController, 'markUnwatched']);
                    },
                    [new \Phlix\Server\Http\Middleware\AuthMiddleware()]
                );
            } catch (\Throwable) {
                // MediaUserDataController not available — routes not registered
            }
        }

        // Media item action endpoints — auth-gated.
        if ($this->container !== null) {
            try {
                $mediaItemController = $this->getMediaItemController();
                $this->router->group(
                    '',
                    function (Router $r) use ($mediaItemController): void {
                        $r->get('/api/v1/media/{id}/missing-episodes', [$mediaItemController, 'getMissingEpisodes']);
                        $r->patch('/api/v1/media/{id}/metadata', [$mediaItemController, 'updateMetadata']);
                        $r->post('/api/v1/shuffle', [$mediaItemController, 'shufflePlay']);
                    },
                    [new \Phlix\Server\Http\Middleware\AuthMiddleware()]
                );
            } catch (\Throwable) {
                // Controller unavailable — routes not registered
            }
        }

        // DELETE /api/v1/media/{id} — admin only (Step 11.6)
        if ($this->container !== null) {
            try {
                /** @var \Phlix\Server\Http\Middleware\AdminMiddleware $adminMiddleware */
                $adminMiddleware = $this->container->get(\Phlix\Server\Http\Middleware\AdminMiddleware::class);
                $mediaItemController = $this->getMediaItemController();
                $this->router->group(
                    '',
                    function (Router $r) use ($mediaItemController): void {
                        $r->delete('/api/v1/media/{id}', [$mediaItemController, 'delete']);
                    },
                    [$adminMiddleware]
                );
            } catch (\Throwable) {
                // AdminMiddleware or controller unavailable — route not registered
            }
        }

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

        // Trickplay thumbnail seek routes (P2-S2) — must be registered before
        // the media-type routes so the /trickplay/ prefix doesn't conflict.
        $this->loadTrickplayRoutes();

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

        // Services admin routes (Trakt/Last.fm status + disconnect) (1.4c).
        $this->loadServicesRoutes();

        // Backup admin routes (1.5).
        $this->loadBackupRoutes();

        // DLNA admin routes — server status/start/stop (2.2).
        $this->loadDlnaAdminRoutes();

        // Remote access admin routes — hub pairing/subdomain/relay/portforward (2.3).
        $this->loadHubAdminRoutes();

        // Live TV / DVR admin routes — tuners, channels, guide, recordings, series rules (2.4).
        $this->loadLiveTvAdminRoutes();

        // SyncPlay group watching routes (3.5).
        $this->loadSyncPlayRoutes();

        // Access schedule management routes (P5-S1)
        $this->loadAccessScheduleRoutes();

        // Profile tag management routes (P5-S2)
        $this->loadProfileTagRoutes();

        // Stream limit management routes (P5-S3)
        $this->loadStreamLimitRoutes();

        // Typed admin router (plugin admin, auth providers, OIDC/LDAP
        // config, stats). These were previously only wired in
        // public/index.php as a separate `Router` instance gated by
        // AdminMiddleware; registering them on the main router unifies
        // the dispatch path so HttpHandler doesn't need a second router.
        if ($this->container !== null) {
            AdminRoutes::register($this->router, $this->container);
        }
    }

    /**
     * Registers webhook admin integration API routes.
     *
     * Wires endpoints for:
     * - WebhookAdminController: index, create, update, delete, test (5 routes)
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
        // PUT /api/v1/admin/webhooks/{id} — update a webhook
        $this->router->put('/api/v1/admin/webhooks/{id}', [$controller, 'update']);
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
     * Registers the admin-side services JSON API routes (1.4c).
     *
     * Wires endpoints for Trakt and Last.fm status/disconnect.
     * These routes are gated by AdminMiddleware.
     *
     * @since 1.4c
     */
    private function loadServicesRoutes(): void
    {
        if ($this->container === null) {
            return;
        }

        try {
            /** @var \Phlix\Server\Http\Middleware\AdminMiddleware $adminMiddleware */
            $adminMiddleware = $this->container->get(\Phlix\Server\Http\Middleware\AdminMiddleware::class);

            $this->router->group(
                '/api/v1/admin/services',
                function (Router $r): void {
                    // Trakt status + disconnect
                    $traktController = $this->getTraktOAuthController();
                    $r->get('/trakt/status', [$traktController, 'status']);
                    $r->post('/trakt/disconnect', [$traktController, 'disconnect']);

                    // Last.fm status + disconnect
                    $lastfmController = $this->getLastfmController();
                    $r->get('/lastfm/status', [$lastfmController, 'status']);
                    $r->post('/lastfm/disconnect', [$lastfmController, 'apiDisconnect']);
                },
                [$adminMiddleware],
            );
        } catch (\Throwable) {
            // Services not configured — silent ignore
        }
    }

    /**
     * Registers backup administration JSON API routes (1.5).
     *
     * Wires endpoints for create, list, delete, restore, S3 upload, and
     * schedule management. These routes are gated by AdminMiddleware.
     *
     * @since 1.5
     */
    private function loadBackupRoutes(): void
    {
        if ($this->container === null) {
            return;
        }

        try {
            /** @var \Phlix\Server\Http\Middleware\AdminMiddleware $adminMiddleware */
            $adminMiddleware = $this->container->get(\Phlix\Server\Http\Middleware\AdminMiddleware::class);

            $this->router->group(
                '/api/v1/admin/backup',
                function (Router $r): void {
                    $controller = $this->getBackupController();

                    // GET /api/v1/admin/backup/list — list all backups
                    $r->get('/list', [$controller, 'list']);
                    // POST /api/v1/admin/backup/create — create a new backup
                    $r->post('/create', [$controller, 'create']);
                    // DELETE /api/v1/admin/backup/{id} — delete a backup
                    $r->delete('/{id}', [$controller, 'delete']);
                    // POST /api/v1/admin/backup/{id}/restore — restore from backup
                    $r->post('/{id}/restore', [$controller, 'restore']);
                    // POST /api/v1/admin/backup/{id}/upload-s3 — upload backup to S3
                    $r->post('/{id}/upload-s3', [$controller, 'uploadS3']);
                    // GET /api/v1/admin/backup/schedule — get schedule settings
                    $r->get('/schedule', [$controller, 'getSchedule']);
                    // PUT /api/v1/admin/backup/schedule — update schedule settings
                    $r->put('/schedule', [$controller, 'updateSchedule']);
                },
                [$adminMiddleware],
            );
        } catch (\Throwable) {
            // Backup not configured — silent ignore
        }
    }

    /**
     * Registers DLNA CDS server admin JSON API routes (2.2).
     *
     * Wires endpoints for:
     * - GET /api/v1/admin/dlna/status — current server state
     * - POST /api/v1/admin/dlna/start — start the CDS server
     * - POST /api/v1/admin/dlna/stop — stop the CDS server
     *
     * These routes are gated by AdminMiddleware.
     *
     * @since 2.2
     */
    private function loadDlnaAdminRoutes(): void
    {
        if ($this->container === null) {
            return;
        }

        /** @var \Phlix\Server\Http\Middleware\AdminMiddleware $adminMiddleware */
        $adminMiddleware = $this->container->get(\Phlix\Server\Http\Middleware\AdminMiddleware::class);

        $controller = new \Phlix\Server\Http\Controllers\Dlna\AdminDlnaServerController();

        // Wiring the optional CdsServer is best-effort: if it is unbound or
        // fails to construct, the controller keeps a null server and its
        // status() reports {enabled:false} with a 200. A CdsServer failure
        // must NOT drop the route group (which would 404 the admin DLNA page),
        // so guard ONLY this wiring — never the group registration below.
        try {
            if ($this->container->has(\Phlix\Dlna\CdsServer::class)) {
                $cdsServer = $this->container->get(\Phlix\Dlna\CdsServer::class);
                if ($cdsServer instanceof \Phlix\Dlna\CdsServer) {
                    $controller->setCdsServer($cdsServer);
                }
            }
        } catch (\Throwable) {
            // CdsServer unavailable — controller stays null; status() reports disabled.
        }

        $this->router->group(
            '/api/v1/admin/dlna',
            function (Router $r) use ($controller): void {
                $r->get('/status', [$controller, 'status']);
                $r->post('/start', [$controller, 'start']);
                $r->post('/stop', [$controller, 'stop']);
            },
            [$adminMiddleware],
        );
    }

    /**
     * Registers remote access admin JSON API routes (2.3).
     *
     * Wires endpoints for hub pairing, subdomain management, relay tunnel
     * control, and port-forward configuration. These routes are gated by
     * AdminMiddleware.
     *
     * @since 2.3
     */
    private function loadHubAdminRoutes(): void
    {
        if ($this->container === null) {
            return;
        }

        try {
            /** @var \Phlix\Server\Http\Middleware\AdminMiddleware $adminMiddleware */
            $adminMiddleware = $this->container->get(\Phlix\Server\Http\Middleware\AdminMiddleware::class);

            $configDirRaw = $this->config['_config_dir'] ?? 'config';
            $configDir = is_string($configDirRaw) ? $configDirRaw : 'config';
            $controller = new \Phlix\Server\Http\Controllers\Admin\AdminHubController(
                $this->container,
                $configDir,
            );

            $this->router->group(
                '/api/v1/admin/remote',
                function (Router $r) use ($controller): void {
                    // Hub pairing (6 endpoints)
                    $r->get('/hub/status', [$controller, 'hubStatus']);
                    $r->post('/hub/pair', [$controller, 'hubPair']);
                    $r->post('/hub/poll', [$controller, 'hubPoll']);
                    $r->post('/hub/complete', [$controller, 'hubComplete']);
                    $r->post('/hub/unenroll', [$controller, 'hubUnenroll']);
                    $r->post('/hub/heartbeat', [$controller, 'hubHeartbeat']);

                    // Subdomain (3 endpoints)
                    $r->get('/subdomain/status', [$controller, 'subdomainStatus']);
                    $r->post('/subdomain/claim', [$controller, 'subdomainClaim']);
                    $r->post('/subdomain/release', [$controller, 'subdomainRelease']);

                    // Relay tunnel (4 endpoints)
                    $r->get('/relay/status', [$controller, 'relayStatus']);
                    $r->post('/relay/enable', [$controller, 'relayEnable']);
                    $r->post('/relay/disable', [$controller, 'relayDisable']);
                    $r->post('/relay/ping', [$controller, 'relayPing']);

                    // Port forward (4 endpoints)
                    $r->get('/portforward/status', [$controller, 'portForwardStatus']);
                    $r->post('/portforward/enable', [$controller, 'portForwardEnable']);
                    $r->post('/portforward/disable', [$controller, 'portForwardDisable']);
                    $r->get('/portforward/candidates', [$controller, 'portForwardCandidates']);
                },
                [$adminMiddleware],
            );
        } catch (\Throwable) {
            // Remote access not configured — silent ignore
        }
    }

    /**
     * Registers Live TV / DVR admin JSON API routes (2.4).
     *
     * Wires 20 endpoints for tuners, channels, EPG/guide, recordings, and
     * series rules. These routes are gated by AdminMiddleware.
     *
     * @since 2.4
     */
    private function loadLiveTvAdminRoutes(): void
    {
        if ($this->container === null) {
            return;
        }

        try {
            /** @var \Phlix\Server\Http\Middleware\AdminMiddleware $adminMiddleware */
            $adminMiddleware = $this->container->get(\Phlix\Server\Http\Middleware\AdminMiddleware::class);

            /** @var \Phlix\Server\Http\Controllers\Admin\AdminLiveTvController $controller */
            $controller = $this->container->get(\Phlix\Server\Http\Controllers\Admin\AdminLiveTvController::class);

            $this->router->group(
                '/api/v1/admin/livetv',
                function (Router $r) use ($controller): void {
                    // Tuners (5 endpoints)
                    $r->get('/tuners', [$controller, 'listTuners']);
                    $r->get('/tuners/scan', [$controller, 'scanTuners']);
                    $r->get('/tuners/{id}', [$controller, 'getTuner']);
                    $r->put('/tuners/{id}', [$controller, 'updateTuner']);
                    $r->delete('/tuners/{id}', [$controller, 'deleteTuner']);

                    // Channels (4 endpoints)
                    $r->get('/channels', [$controller, 'listChannels']);
                    $r->get('/channels/{id}', [$controller, 'getChannel']);
                    $r->put('/channels/{id}', [$controller, 'updateChannel']);
                    $r->get('/channels/{id}/stream', [$controller, 'streamChannel']);

                    // Guide / EPG (3 endpoints)
                    $r->get('/guide', [$controller, 'listGuide']);
                    $r->get('/guide/programs/{id}', [$controller, 'getProgram']);
                    $r->post('/guide/refresh', [$controller, 'refreshGuide']);

                    // Recordings (6 endpoints)
                    $r->get('/recordings', [$controller, 'listRecordings']);
                    $r->get('/recordings/upcoming', [$controller, 'listUpcomingRecordings']);
                    $r->get('/recordings/series/{seriesId}', [$controller, 'listBySeries']);
                    $r->get('/recordings/{id}', [$controller, 'getRecording']);
                    $r->post('/recordings', [$controller, 'createRecording']);
                    $r->delete('/recordings/{id}', [$controller, 'deleteRecording']);

                    // Series Rules (5 endpoints)
                    $r->get('/series-rules', [$controller, 'listSeriesRules']);
                    $r->get('/series-rules/{id}', [$controller, 'getSeriesRule']);
                    $r->post('/series-rules', [$controller, 'createSeriesRule']);
                    $r->put('/series-rules/{id}', [$controller, 'updateSeriesRule']);
                    $r->delete('/series-rules/{id}', [$controller, 'deleteSeriesRule']);
                },
                [$adminMiddleware],
            );
        } catch (\Throwable) {
            // LiveTV admin not configured — silent ignore
        }
    }

    /**
     * Registers SyncPlay group watching REST API routes (3.5).
     *
     * Wires endpoints for:
     * - GET /api/v1/syncplay/groups — list all groups
     * - POST /api/v1/syncplay/groups — create a new group
     * - GET /api/v1/syncplay/groups/{id} — get group state
     * - POST /api/v1/syncplay/groups/{id}/join — join a group
     * - POST /api/v1/syncplay/groups/{id}/leave — leave a group
     *
     * @since 3.5
     */
    private function loadSyncPlayRoutes(): void
    {
        $controller = $this->getSyncPlayController();
        $authMiddleware = new \Phlix\Server\Http\Middleware\AuthMiddleware();

        // All SyncPlay group operations require authentication
        $this->router->group('', function (Router $r) use ($controller): void {
            $r->get('/api/v1/syncplay/groups', [$controller, 'listGroups']);
            $r->post('/api/v1/syncplay/groups', [$controller, 'createGroup']);
            $r->get('/api/v1/syncplay/groups/{id}', [$controller, 'getGroup']);
            $r->post('/api/v1/syncplay/groups/{id}/join', [$controller, 'joinGroup']);
            $r->post('/api/v1/syncplay/groups/{id}/leave', [$controller, 'leaveGroup']);
        }, [$authMiddleware]);
    }

    /**
     * Returns a SyncPlayController instance.
     *
     * SP5: Both SyncPlayManager and SyncPlaySnapshotService are now resolved from
     * the container (singleton within this worker process). The controller reads
     * from the snapshot service (WS-published state) and writes through the
     * manager (mutations delegated to WS worker in SP6).
     *
     * @return SyncPlayController The controller instance.
     *
     * @since 3.5
     */
    private function getSyncPlayController(): SyncPlayController
    {
        // SP5: Both services are now obtained from the container singleton,
        // rather than instantiating a new SyncPlayManager per request.
        if ($this->container === null) {
            // Fallback: create with null logger (for legacy/test scenarios)
            $syncPlayManager = new \Phlix\Session\SyncPlay\SyncPlayManager(null);
            $snapshotService = new \Phlix\Session\SyncPlay\SyncPlaySnapshotService();
            return new \Phlix\Server\Http\Controllers\SyncPlayController($syncPlayManager, $snapshotService);
        }

        /** @var \Phlix\Session\SyncPlay\SyncPlaySnapshotService */
        $snapshotService = $this->container->get(\Phlix\Session\SyncPlay\SyncPlaySnapshotService::class);
        /** @var \Phlix\Session\SyncPlay\SyncPlayManager */
        $syncPlayManager = $this->container->get(\Phlix\Session\SyncPlay\SyncPlayManager::class);
        return new \Phlix\Server\Http\Controllers\SyncPlayController($syncPlayManager, $snapshotService);
    }

    /**
     * Registers access schedule management API routes (P5-S1).
     *
     * Wires endpoints for profile access schedules that define time-based
     * access control windows. These routes are auth-gated and enforce
     * schedule restrictions via AccessScheduleMiddleware.
     *
     * Endpoints:
     * - GET    /api/v1/profiles/{profileId}/schedules       — list all schedules
     * - POST   /api/v1/profiles/{profileId}/schedules       — create a new schedule
     * - GET    /api/v1/profiles/{profileId}/schedules/{id} — get a specific schedule
     * - PUT    /api/v1/profiles/{profileId}/schedules/{id} — update a schedule
     * - DELETE /api/v1/profiles/{profileId}/schedules/{id} — delete a schedule
     *
     * @since 0.15.0
     */
    private function loadAccessScheduleRoutes(): void
    {
        $controller = $this->getAccessScheduleController();

        // AuthMiddleware gates these routes; AccessScheduleMiddleware
        // runs globally after auth to enforce schedule restrictions.
        $this->router->group(
            '',
            function (Router $r) use ($controller): void {
                $r->get('/api/v1/profiles/{profileId}/schedules', [$controller, 'listForProfile']);
                $r->post('/api/v1/profiles/{profileId}/schedules', [$controller, 'createForProfile']);
                $r->get('/api/v1/profiles/{profileId}/schedules/{scheduleId}', [$controller, 'getSchedule']);
                $r->put('/api/v1/profiles/{profileId}/schedules/{scheduleId}', [$controller, 'updateSchedule']);
                $r->delete('/api/v1/profiles/{profileId}/schedules/{scheduleId}', [$controller, 'deleteSchedule']);
            },
            [new \Phlix\Server\Http\Middleware\AuthMiddleware()]
        );
    }

    /**
     * Wires endpoints for profile tag management that define content
     * filtering restrictions (blocked/allowed tags) per profile.
     *
     * Endpoints:
     * - GET    /api/v1/profiles/{profileId}/tags       — list all tags for a profile
     * - POST   /api/v1/profiles/{profileId}/tags       — add a tag
     * - DELETE /api/v1/profiles/{profileId}/tags/{id}  — remove a tag
     *
     * @since 0.15.0
     */
    private function loadProfileTagRoutes(): void
    {
        $controller = $this->getProfileTagController();

        // AuthMiddleware gates these routes; tag filtering is applied
        // in ItemRepository::query() based on RequestContext profileId.
        $this->router->group(
            '',
            function (Router $r) use ($controller): void {
                $r->get('/api/v1/profiles/{profileId}/tags', [$controller, 'listForProfile']);
                $r->post('/api/v1/profiles/{profileId}/tags', [$controller, 'createForProfile']);
                $r->delete('/api/v1/profiles/{profileId}/tags/{tagId}', [$controller, 'deleteTag']);
            },
            [new \Phlix\Server\Http\Middleware\AuthMiddleware()]
        );
    }

    /**
     * Returns an AccessScheduleController instance.
     *
     * @return \Phlix\Server\Http\Controllers\AccessScheduleController The controller instance.
     *
     * @since 0.15.0
     */
    private function getAccessScheduleController(): \Phlix\Server\Http\Controllers\AccessScheduleController
    {
        if ($this->container === null) {
            // Fallback: create with direct DB connection
            $db = $this->connectionPool->getPooledConnection('mysql');
            $accessScheduleService = new \Phlix\Access\AccessScheduleService($db);
            return new \Phlix\Server\Http\Controllers\AccessScheduleController($accessScheduleService);
        }

        /** @var \Phlix\Server\Http\Controllers\AccessScheduleController */
        return $this->container->get(\Phlix\Server\Http\Controllers\AccessScheduleController::class);
    }

    /**
     * Returns a ProfileTagController instance.
     *
     * @return \Phlix\Server\Http\Controllers\ProfileTagController The controller instance.
     *
     * @since 0.15.0
     */
    private function getProfileTagController(): \Phlix\Server\Http\Controllers\ProfileTagController
    {
        if ($this->container === null) {
            // Fallback: create with direct DB connection
            $db = $this->connectionPool->getPooledConnection('mysql');
            $profileTagService = new \Phlix\Access\ProfileTagService($db);
            return new \Phlix\Server\Http\Controllers\ProfileTagController($profileTagService);
        }

        /** @var \Phlix\Server\Http\Controllers\ProfileTagController */
        return $this->container->get(\Phlix\Server\Http\Controllers\ProfileTagController::class);
    }

    /**
     * Registers stream limit management API routes (P5-S3).
     *
     * Wires endpoints for per-profile concurrent stream limits:
     * - GET    /api/v1/profiles/{profileId}/stream-limits  — get stream limits
     * - PUT    /api/v1/profiles/{profileId}/stream-limits  — update stream limits
     * - GET    /api/v1/profiles/{profileId}/active-streams — list active streams
     *
     * @since 0.15.0
     */
    private function loadStreamLimitRoutes(): void
    {
        $controller = $this->getStreamLimitController();

        // AuthMiddleware gates these routes; StreamLimitMiddleware
        // runs globally after auth to enforce stream limits.
        $this->router->group(
            '',
            function (Router $r) use ($controller): void {
                $r->get('/api/v1/profiles/{profileId}/stream-limits', [$controller, 'getStreamLimits']);
                $r->put('/api/v1/profiles/{profileId}/stream-limits', [$controller, 'updateStreamLimits']);
                $r->get('/api/v1/profiles/{profileId}/active-streams', [$controller, 'getActiveStreams']);
            },
            [new \Phlix\Server\Http\Middleware\AuthMiddleware()]
        );
    }

    /**
     * Returns a StreamLimitController instance.
     *
     * @return \Phlix\Server\Http\Controllers\StreamLimitController The controller instance.
     *
     * @since 0.15.0
     */
    private function getStreamLimitController(): \Phlix\Server\Http\Controllers\StreamLimitController
    {
        if ($this->container === null) {
            // Fallback: create with direct DB connection
            $db = $this->connectionPool->getPooledConnection('mysql');
            $streamSessionService = new \Phlix\Access\StreamSessionService($db);
            return new \Phlix\Server\Http\Controllers\StreamLimitController($streamSessionService);
        }

        /** @var \Phlix\Server\Http\Controllers\StreamLimitController */
        return $this->container->get(\Phlix\Server\Http\Controllers\StreamLimitController::class);
    }

    /**
     * Returns a BackupController instance.
     *
     * @return \Phlix\Server\Http\Controllers\Admin\BackupController The controller instance.
     */
    private function getBackupController(): \Phlix\Server\Http\Controllers\Admin\BackupController
    {
        if ($this->container === null) {
            return \Phlix\Server\Http\Controllers\Admin\BackupController::createDefault();
        }

        try {
            /** @var \Phlix\Server\Http\Controllers\Admin\BackupController */
            $controller = $this->container->get(\Phlix\Server\Http\Controllers\Admin\BackupController::class);
            return $controller;
        } catch (\Throwable) {
            // Fall back to manual construction
            return \Phlix\Server\Http\Controllers\Admin\BackupController::createDefault();
        }
    }

    /**
     * Returns the LastfmController instance (manually wired).
     *
     * @return \Phlix\Server\Http\Controllers\Admin\LastfmController
     */
    private function getLastfmController(): \Phlix\Server\Http\Controllers\Admin\LastfmController
    {
        $rawConfig = include __DIR__ . '/../../../config/lastfm.php';
        $config = \Phlix\Plugins\Scrobbler\Lastfm\LastfmConfig::fromArray(
            is_array($rawConfig) ? $rawConfig : []
        );
        $db = $this->connectionPool->getPooledConnection('mysql');
        $sessions = new \Phlix\Plugins\Scrobbler\Lastfm\LastfmSessionRepository($db);
        $api = new \Phlix\Plugins\Scrobbler\Lastfm\LastfmApi(
            $config->apiKey,
            $config->sharedSecret,
        );

        $settings = null;
        if ($this->container !== null) {
            try {
                /** @var \Phlix\Admin\SettingsRepository $settings */
                $settings = $this->container->get(\Phlix\Admin\SettingsRepository::class);
            } catch (\Throwable) {
                // Settings repository not available — fall back to env/file config.
            }
        }

        return new \Phlix\Server\Http\Controllers\Admin\LastfmController(
            $config,
            $sessions,
            $api,
            db: $db,
            settings: $settings,
        );
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
        $settings = null;
        if ($this->container !== null) {
            try {
                /** @var \Phlix\Admin\SettingsRepository $settings */
                $settings = $this->container->get(\Phlix\Admin\SettingsRepository::class);
            } catch (\Throwable) {
                // Settings repository not available — fall back to env/file config.
            }
        }

        try {
            $rawConfig = include __DIR__ . '/../../../config/lastfm.php';
            $config = \Phlix\Plugins\Scrobbler\Lastfm\LastfmConfig::fromArray(
                is_array($rawConfig) ? $rawConfig : []
            );
            $db = $this->connectionPool->getPooledConnection('mysql');
            $sessions = new \Phlix\Plugins\Scrobbler\Lastfm\LastfmSessionRepository($db);
            $api = new \Phlix\Plugins\Scrobbler\Lastfm\LastfmApi(
                $config->apiKey,
                $config->sharedSecret,
            );
            $controller = new \Phlix\Server\Http\Controllers\Admin\LastfmController(
                $config,
                $sessions,
                $api,
                db: $db,
                settings: $settings,
            );

            $this->router->get('/admin/lastfm', [$controller, 'index']);
            $this->router->get('/admin/lastfm/callback', [$controller, 'callback']);
            $this->router->post('/admin/lastfm/disconnect', [$controller, 'disconnect']);

            // SPA-friendly "Connect Last.fm" flow (mirrors the Trakt OAuth
            // routes at /api/v1/oauth/trakt[/callback]). These issue top-level
            // browser redirects instead of rendering the legacy Smarty page:
            //  - authorize 302s straight to last.fm/api/auth (or back to the
            //    SPA Services page with ?lastfm=not_configured when unusable),
            //  - callback exchanges ?token= and 302s back to the SPA Services
            //    page with ?lastfm=connected|error.
            //
            // SECURITY (account-linking): these are admin-only operations, so
            // they MUST be gated by AdminMiddleware exactly like the sibling
            // /api/v1/admin/services/* routes. The session cookie is
            // SameSite=Lax, so it IS sent on the top-level GET navigation to
            // /api/v1/oauth/lastfm AND on the cross-site redirect back from
            // last.fm to /api/v1/oauth/lastfm/callback (Lax sends cookies on
            // top-level GET navigations), so AdminMiddleware can resolve
            // $request->userId for both. The container may be absent (e.g. some
            // bootstrap paths) — only then do we fall back to ungated
            // registration so the routes still exist.
            if ($this->container !== null) {
                /** @var \Phlix\Server\Http\Middleware\AdminMiddleware $adminMiddleware */
                $adminMiddleware = $this->container->get(
                    \Phlix\Server\Http\Middleware\AdminMiddleware::class
                );
                $this->router->group(
                    '/api/v1/oauth/lastfm',
                    function (Router $r) use ($controller): void {
                        $r->get('', [$controller, 'apiAuthorize']);
                        $r->get('/callback', [$controller, 'apiCallback']);
                    },
                    [$adminMiddleware],
                );
            } else {
                $this->router->get('/api/v1/oauth/lastfm', [$controller, 'apiAuthorize']);
                $this->router->get('/api/v1/oauth/lastfm/callback', [$controller, 'apiCallback']);
            }
        } catch (\Throwable) {
            // Last.fm not configured — silent ignore (e.g. DB not ready).
        }
    }

    /**
     * Registers library management and theme media API routes.
     *
     * Wires endpoints for:
     * - LibraryController: index, show, create, update, delete, scan, rescan,
     *   scanStatus, scanHistory (9 routes)
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

        // Async scan progress routes (1.1b). The Router compiles {id} to the
        // `[^/]+` capture group and anchors the pattern with `#^...$#`, so the
        // 2-segment `{id}` (show) route cannot match these 3-segment literal
        // paths and vice-versa — no shadowing in either direction regardless of
        // registration order.
        $this->router->get('/api/v1/libraries/{id}/scan-status', [$libraryController, 'scanStatus']);
        $this->router->get('/api/v1/libraries/{id}/scan-history', [$libraryController, 'scanHistory']);

        // Background metadata match (reuses the scan-job queue + status, so the
        // existing scan-status badge/polling shows its progress). 3-segment
        // literal path, so it cannot shadow / be shadowed by the {id} routes.
        $this->router->post('/api/v1/libraries/{id}/match-metadata', [$libraryController, 'matchMetadata']);

        // Theme media routes
        $this->router->get('/api/v1/libraries/{id}/theme-media', [$themeMediaController, 'getThemeMedia']);
        $this->router->post('/api/v1/libraries/{id}/theme-media/scan', [$themeMediaController, 'scanThemeMedia']);
        $this->router->delete('/api/v1/libraries/{id}/theme-media', [$themeMediaController, 'deleteThemeMedia']);

        // Item-level theme-audio stream (M3). Registered BEFORE the library-level
        // `{libraryId}/audio|video` routes so the literal `item` segment wins
        // (routes match in registration order). Serves the per-item theme
        // resolved at match time into `metadata_json.theme_audio_url`.
        $themeMusicStreamController = $this->getThemeMusicStreamController();
        $this->router->get('/stream/theme-media/item/{mediaItemId}', [$themeMusicStreamController, 'streamItemTheme']);

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
     * All collection routes require authentication as collections are
     * per-user private data.
     *
     * @since 0.14.0
     */
    private function loadCollectionRoutes(): void
    {
        $controller = $this->getCollectionController();
        $authMiddleware = new \Phlix\Server\Http\Middleware\AuthMiddleware();

        // All collection routes require authentication
        $this->router->group('', function (Router $r) use ($controller): void {
            // Collection CRUD routes
            $r->get('/api/v1/collections', [$controller, 'index']);
            $r->post('/api/v1/collections', [$controller, 'create']);
            $r->get('/api/v1/collections/{id}', [$controller, 'show']);
            $r->put('/api/v1/collections/{id}', [$controller, 'update']);
            $r->delete('/api/v1/collections/{id}', [$controller, 'delete']);

            // Playlist alias (UI-3.8) — creates a collection
            $r->post('/api/v1/playlists', [$controller, 'create']);

            // Collection item management
            $r->post('/api/v1/collections/{id}/items/{mediaItemId}', [$controller, 'addItem']);
            $r->delete('/api/v1/collections/{id}/items/{mediaItemId}', [$controller, 'removeItem']);

            // Bulk operations and smart collection refresh
            $r->post('/api/v1/collections/{id}/bulk-add', [$controller, 'bulkAdd']);
            $r->post('/api/v1/collections/{id}/refresh', [$controller, 'refresh']);

            // Library-scoped collections
            $r->get('/api/v1/libraries/{libraryId}/collections', [$controller, 'forLibrary']);
        }, [$authMiddleware]);
    }

    /**
     * Registers HLS and DASH streaming routes.
     *
     * The CMAF transcode pipeline writes one job directory per transcode holding
     * the DASH manifest, the HLS playlists and the shared fMP4 segments, all
     * cross-referenced by relative filename. Serving is therefore a generic
     * per-job file handler per protocol (plus a JSON info endpoint). The JSON
     * `playlist` / `manifest` routes are registered BEFORE the catch-all
     * `{file}` route so first-match dispatch resolves them.
     *
     * @since 0.14.0
     */
    private function loadStreamingRoutes(): void
    {
        $hlsController = $this->getHlsController();
        $dashController = $this->getDashController();

        // Streaming bytes require proof of an authenticated session. The player
        // can't attach a Bearer header to a bare manifest URL, so the gate
        // accepts a session (hls.js sends the Bearer token on every segment XHR
        // via xhrSetup; same-origin requests carry the session cookie) OR a
        // signed-URL token. The token is prefix-scoped to the per-job directory
        // (see SignedUrl::canonicalResource), so a single signature on the master
        // playlist URL authorises every variant playlist and segment under it.
        // StreamLimitMiddleware enforces per-profile concurrent stream limits (P5-S3).
        $middleware = [new \Phlix\Server\Http\Middleware\SignedUrlMiddleware()];
        $hasStreamLimit = $this->container !== null
            && $this->container->has(\Phlix\Server\Http\Middleware\StreamLimitMiddleware::class);
        if ($hasStreamLimit) {
            /** @var \Phlix\Server\Http\Middleware\StreamLimitMiddleware */
            $streamLimitMiddleware = $this->container->get(\Phlix\Server\Http\Middleware\StreamLimitMiddleware::class);
            $middleware[] = $streamLimitMiddleware;
        }
        $this->router->group('', function (Router $r) use ($hlsController, $dashController): void {
            // HLS: JSON info, then the generic file server (master.m3u8, media_N.m3u8,
            // init-N.m4s, chunk-*.m4s).
            $r->get('/hls/{job_id}/playlist', [$hlsController, 'getPlaylist']);
            $r->get('/hls/{job_id}/{file}', [$hlsController, 'serveFile']);

            // DASH: JSON info, then the generic file server (manifest.mpd + .m4s).
            $r->get('/dash/{job_id}/manifest', [$dashController, 'getManifest']);
            $r->get('/dash/{job_id}/{file}', [$dashController, 'serveFile']);

            // DVR recording stream: serves the .ts file via withFile().
            // Same auth middleware (SignedUrl + optional StreamLimit) as HLS/DASH.
            $liveTvStreamController = $this->getLiveTvStreamController();
            $r->get('/livetv/recording/{id}/stream', [$liveTvStreamController, 'streamRecording']);
            // Timeshift rolling-HLS buffer: playlist first, then the segment route.
            // BOTH routes are parametric (they carry {sessionId}), so neither lives
            // in the Router's O(1) static-route map — they are matched by regex in
            // REGISTRATION ORDER (first preg_match wins). The `.../stream` route MUST
            // therefore be registered before the `.../{segment}` route so a
            // `.../stream` request matches the playlist handler rather than being
            // captured as a segment name (same ordering the /hls/{job}/playlist ->
            // /hls/{job}/{file} pair relies on). Guarded by the route-ordering test.
            $r->get('/livetv/timeshift/{sessionId}/stream', [$liveTvStreamController, 'streamTimeShift']);
            $r->get('/livetv/timeshift/{sessionId}/{segment}', [$liveTvStreamController, 'streamTimeShiftSegment']);
        }, $middleware);
    }

    /**
     * Registers trickplay (thumbnail seek preview) HTTP routes.
     *
     * Serves pre-generated thumbnail grid images and BIF index XML files
     * for the trickplay scrubber UI. These are public read-only routes
     * (the signed URL middleware on streaming routes is intentionally NOT
     * applied here because trickplay thumbnails are low-sensitivity assets
     * and the job ID provides implicit scoping).
     *
     * Endpoints:
     * - GET /trickplay/{jobId}/thumb-{index}.jpg — BIF thumbnail grid image
     * - GET /trickplay/{jobId}/index.xml          — BIF index XML
     * - GET /trickplay/{jobId}/sprite.jpg         — Sprite sheet image
     * - GET /trickplay/{jobId}/timeline.json      — Timeline mapping JSON
     *
     * @since 0.11.0
     */
    private function loadTrickplayRoutes(): void
    {
        $controller = $this->getTrickplayController();

        // Public read-only routes — no auth required, job ID provides scoping.
        // These are low-sensitivity placeholder thumbnails, not media content.
        $this->router->get('/trickplay/{jobId}/thumb-{index}.jpg', [$controller, 'getThumbnail']);
        $this->router->get('/trickplay/{jobId}/index.xml', [$controller, 'getIndex']);
        $this->router->get('/trickplay/{jobId}/sprite.jpg', [$controller, 'getSprite']);
        $this->router->get('/trickplay/{jobId}/timeline.json', [$controller, 'getTimeline']);
    }

    /**
     * Returns a TrickplayController instance.
     *
     * @return \Phlix\Media\Streaming\Trickplay\TrickplayController
     *
     * @since 0.11.0
     */
    private function getTrickplayController(): \Phlix\Media\Streaming\Trickplay\TrickplayController
    {
        // Load trickplay config (storage_dir and base_url).
        /** @var array<string, mixed> $trickplayConfig */
        $trickplayConfig = [];
        $configFile = dirname(__DIR__, 2) . '/config/trickplay.php';
        if (file_exists($configFile)) {
            /** @var mixed $config */
            $config = include $configFile;
            if (is_array($config)) {
                /** @var array<string, mixed> $trickplayConfig */
                $trickplayConfig = $config;
            }
        }

        $storageDir = is_string($trickplayConfig['storage_dir'] ?? null) ? $trickplayConfig['storage_dir'] : '/var/trickplay';
        $baseUrl = is_string($trickplayConfig['base_url'] ?? null) ? $trickplayConfig['base_url'] : '';

        return new \Phlix\Media\Streaming\Trickplay\TrickplayController($storageDir, $baseUrl);
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

        // All music endpoints expose library/track data (or the user's
        // now-playing), so require a signed-in user.
        $this->router->group('', function (Router $r) use ($controller): void {
            // Music library browsing routes
            $r->get('/api/v1/music/artists', [$controller, 'listArtists']);
            $r->get('/api/v1/music/artists/{mbid}', [$controller, 'getArtist']);
            $r->get('/api/v1/music/albums', [$controller, 'listAlbums']);
            $r->get('/api/v1/music/albums/{mbid}', [$controller, 'getAlbum']);
            $r->get('/api/v1/music/tracks', [$controller, 'listTracks']);
            $r->get('/api/v1/music/tracks/{id}', [$controller, 'getTrack']);

            // Now playing for the current session
            $r->get('/api/v1/music/now-playing', [$controller, 'nowPlaying']);
        }, [new \Phlix\Server\Http\Middleware\AuthMiddleware()]);
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

        // OPDS 1.2 feed endpoints. E-reader clients authenticate with HTTP Basic
        // (not a Bearer token) and re-send it on every feed/cover/download
        // request, so this group accepts Basic — as well as an existing session
        // or a signed-URL token — and challenges with `WWW-Authenticate: Basic`
        // on failure. The acquisition `download` link the feed emits
        // (`/opds/v1.2/books/{id}/download`) is registered here too so the whole
        // OPDS flow is both authenticated and functional.
        $this->router->group('', function (Router $r) use ($controller): void {
            $r->get('/opds/v1.2', [$controller, 'opdsRoot']);
            $r->get('/opds/v1.2/libraries', [$controller, 'opdsLibraries']);
            $r->get('/opds/v1.2/libraries/{id}', [$controller, 'opdsLibraryBooks']);
            $r->get('/opds/v1.2/books/{id}/cover', [$controller, 'opdsBookCover']);
            $r->get('/opds/v1.2/books/{id}/download', [$controller, 'downloadBook']);
        }, [\Phlix\Server\Http\Middleware\SignedUrlMiddleware::forOpds($this->opdsBasicValidator())]);

        // Book browsing JSON requires a signed-in user.
        $this->router->group('', function (Router $r) use ($controller): void {
            $r->get('/api/v1/books', [$controller, 'listBooks']);
            $r->get('/api/v1/books/{id}', [$controller, 'getBook']);
        }, [new \Phlix\Server\Http\Middleware\AuthMiddleware()]);

        // Binary serving (read/cover/download) can't attach a Bearer header from
        // an <img>/<a download>/reader, so it accepts an existing session or a
        // signed-URL token minted by the gated getBook detail endpoint above.
        $this->router->group('', function (Router $r) use ($controller): void {
            $r->get('/api/v1/books/{id}/read', [$controller, 'readBook']);
            $r->get('/api/v1/books/{id}/cover', [$controller, 'getCover']);
            $r->get('/api/v1/books/{id}/download', [$controller, 'downloadBook']);
        }, [new \Phlix\Server\Http\Middleware\SignedUrlMiddleware()]);
    }

    /**
     * Builds the HTTP Basic credential validator used by the OPDS feed group.
     *
     * Returns a closure that resolves a username/email + password to a user id
     * via {@see \Phlix\Auth\AuthManager::verifyCredentials()} (no session is
     * created). When the container is unavailable (e.g. a bare test harness),
     * Basic auth is effectively disabled — the closure always rejects — leaving
     * the session/signed-URL paths intact.
     */
    private function opdsBasicValidator(): \Closure
    {
        $container = $this->container;
        if ($container === null) {
            return static fn (string $username, string $password): ?string => null;
        }

        return static function (string $username, string $password) use ($container): ?string {
            /** @var \Phlix\Auth\AuthManager $authManager */
            $authManager = $container->get(\Phlix\Auth\AuthManager::class);

            return $authManager->verifyCredentials($username, $password);
        };
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

        // Browsing + per-user progress require a signed-in user.
        $this->router->group('', function (Router $r) use ($controller): void {
            $r->get('/api/v1/audiobooks', [$controller, 'listAudiobooks']);
            $r->get('/api/v1/audiobooks/{id}', [$controller, 'getAudiobook']);
            $r->get('/api/v1/audiobooks/{id}/chapters', [$controller, 'getChapters']);
            $r->get('/api/v1/audiobooks/{id}/progress', [$controller, 'getProgress']);
            $r->post('/api/v1/audiobooks/{id}/progress', [$controller, 'saveProgress']);
        }, [new \Phlix\Server\Http\Middleware\AuthMiddleware()]);

        // Audio byte serving can't attach a Bearer header from a Range/<audio>
        // request, so it accepts an existing session or a signed-URL token minted
        // by the gated getAudiobook detail endpoint above.
        $this->router->group('', function (Router $r) use ($controller): void {
            $r->get('/api/v1/audiobooks/{id}/read', [$controller, 'readAudiobook']);
            $r->get('/api/v1/audiobooks/{id}/stream', [$controller, 'streamAudiobook']);
        }, [new \Phlix\Server\Http\Middleware\SignedUrlMiddleware()]);
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

        // Album/photo browsing JSON + slideshow listing require a signed-in user.
        $this->router->group('', function (Router $r) use ($controller): void {
            $r->get('/api/v1/photo/albums', [$controller, 'listAlbums']);
            $r->get('/api/v1/photo/albums/{id}', [$controller, 'getAlbum']);
            $r->get('/api/v1/photo/photos', [$controller, 'listPhotos']);
            $r->get('/api/v1/photo/photos/{id}', [$controller, 'getPhoto']);
            $r->get('/api/v1/photo/slideshow', [$controller, 'slideshow']);
        }, [new \Phlix\Server\Http\Middleware\AuthMiddleware()]);

        // Image byte serving can't attach a Bearer header from an <img>, so it
        // accepts an existing session or a signed-URL token minted by the gated
        // listing/detail endpoints above (which emit signed thumbnail_url/full_url).
        $this->router->group('', function (Router $r) use ($controller): void {
            $r->get('/api/v1/photo/photos/{id}/thumbnail', [$controller, 'getThumbnail']);
            $r->get('/api/v1/photo/photos/{id}/full', [$controller, 'getFull']);
        }, [new \Phlix\Server\Http\Middleware\SignedUrlMiddleware()]);
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
     * Dispatch a Request through the registered middleware chain and
     * router, returning the resulting Response.
     *
     * Unlike {@see run()} this method does not read from PHP globals,
     * does not start the hub/relay/discovery/newsletter/backup timers,
     * and does not call `$response->send()`. It exists so the Workerman
     * entrypoint ({@see \Phlix\Server\Workerman\HttpHandler}) can reuse
     * the fully-populated router + middleware stack this class builds
     * during construction, instead of duplicating every route
     * registration.
     *
     * The caller is responsible for converting the Workerman request
     * into a {@see Request} (via {@see Request::fromWorkerman()}) and
     * sending the response back over the connection.
     *
     * @param Request $request The HTTP request to dispatch.
     *
     * @return Response The response produced by the matching route's
     *                   handler (or the middleware chain).
     *
     * @since 0.15.0
     */
    public function dispatch(Request $request): Response
    {
        $finalHandler = function (Request $request): Response {
            return $this->router->dispatch($request);
        };

        $handler = $finalHandler;
        foreach (array_reverse($this->middleware) as $currentHandler) {
            $nextHandler = $handler;
            $handler = static function (Request $request) use ($currentHandler, $nextHandler): Response {
                return $currentHandler($request, $nextHandler);
            };
        }

        return $handler($request);
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

        // Start the periodic storage-snapshot timer so the admin dashboard's
        // Storage card has data (nothing else writes stats_storage).
        $this->startStorageSnapshotTimer();

        // Start the transcode stale-job reaper so wedged encodes free their
        // concurrency slot promptly (default: checks every 45 s, kills jobs
        // older than 120 s or with no segment within 60 s).
        $this->startTranscodeReaperTimer();

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

            $db = $this->connectionPool->getPooledConnection('mysql');

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
            $db = $this->connectionPool->getPooledConnection('mysql');
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
     * Interval (seconds) between automatic storage snapshots.
     *
     * Storage totals change slowly (only as the library grows), so a 6-hour
     * cadence keeps the dashboard's Storage card fresh without churn.
     */
    private const STORAGE_SNAPSHOT_INTERVAL = 21600;

    /**
     * Start the periodic storage-snapshot timer.
     *
     * The admin dashboard's Storage card reads the latest row per media type
     * from `stats_storage`; nothing else populates that table, so without this
     * timer the card is permanently empty. Records one snapshot immediately at
     * worker start and then every {@see self::STORAGE_SNAPSHOT_INTERVAL} seconds.
     *
     * @return void
     *
     * @since 1.8
     */
    private function startStorageSnapshotTimer(): void
    {
        $logger = \Phlix\Common\Logger\LoggerFactory::get(\Phlix\Common\Logger\LogChannels::APPLICATION);

        try {
            $db = $this->connectionPool->getPooledConnection('mysql');
            $collector = new \Phlix\Stats\StatsCollector($db);

            // Initial snapshot so the dashboard has data without waiting a cycle.
            $this->recordStorageSnapshots($collector, $db, $logger);

            \Workerman\Timer::add(
                self::STORAGE_SNAPSHOT_INTERVAL,
                function () use ($collector, $db, $logger): void {
                    $this->recordStorageSnapshots($collector, $db, $logger);
                },
            );
        } catch (\Throwable $e) {
            $logger->error('Failed to start storage-snapshot timer', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Starts the periodic transcode stale-job reaper.
     *
     * Marks 'running' jobs as 'failed' when they exceed the stale age threshold
     * or fail to produce a segment within the startup window. Prevents ghost
     * rows from permanently occupying concurrency slots after a worker restart
     * or a wedged FFmpeg process.
     *
     * @return void
     *
     * @since 0.26.0
     */
    private function startTranscodeReaperTimer(): void
    {
        $logger = \Phlix\Common\Logger\LoggerFactory::get(\Phlix\Common\Logger\LogChannels::MEDIA);

        try {
            /** @var \Phlix\Media\Transcoding\TranscodeManager $transcodeManager */
            $transcodeManager = $this->container?->get(\Phlix\Media\Transcoding\TranscodeManager::class);

            if ($transcodeManager === null) {
                $logger->debug('TranscodeManager not available; skipping reaper timer');

                return;
            }

            $transcodeManager->startReaperTimer();
            $logger->debug('Transcode reaper timer started');
        } catch (\Throwable $e) {
            $logger->error('Failed to start transcode reaper timer', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Scans the actual filesystem at /vault1 and /vault2 to get real storage sizes,
     * since the file_size field in media_items.metadata_json is never populated.
     * Folders are mapped to media types: anime/movies->movie, tv->series, music->music.
     * Item counts still come from the database to reflect indexed media.
     * Failures are logged and swallowed so a snapshot run can never take down the worker.
     *
     * @param \Phlix\Stats\StatsCollector $collector Collector to write through.
     * @param \Workerman\MySQL\Connection $db        Live MySQL connection.
     * @param \Phlix\Common\Logger\StructuredLogger $logger Application logger.
     *
     * @return void
     */
    private function recordStorageSnapshots(
        \Phlix\Stats\StatsCollector $collector,
        \Workerman\MySQL\Connection $db,
        \Phlix\Common\Logger\StructuredLogger $logger
    ): void {
        try {
            // Map storage folders to media type buckets
            // anime/ and movies/ -> movie, tv/ -> series, music/ -> music
            $folderToBucket = [
                'anime' => 'movie',
                'movies' => 'movie',
                'tv' => 'series',
                'music' => 'music',
            ];

            // Initialize buckets with filesystem-sourced sizes and DB-sourced counts
            $buckets = [
                'movie' => ['count' => 0, 'bytes' => 0],
                'series' => ['count' => 0, 'bytes' => 0],
                'music' => ['count' => 0, 'bytes' => 0],
                'photo' => ['count' => 0, 'bytes' => 0],
            ];

            // Scan filesystem for actual storage sizes
            $vaultRoots = ['/vault1', '/vault2'];
            foreach ($vaultRoots as $vaultRoot) {
                if (!is_dir($vaultRoot)) {
                    continue;
                }

                $entries = @scandir($vaultRoot) ?: [];
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $bucket = $folderToBucket[$entry] ?? null;
                    if ($bucket === null) {
                        continue;
                    }
                    $dirPath = $vaultRoot . '/' . $entry;
                    if (!is_dir($dirPath)) {
                        continue;
                    }
                    // Use du -sb to get apparent size in bytes (follows symlinks)
                    $output = @shell_exec('du -sb ' . escapeshellarg($dirPath));
                    if (!is_string($output)) {
                        continue;
                    }
                    $matches = [];
                    if (preg_match('/^(\d+)/', $output, $matches) === 1) {
                        $buckets[$bucket]['bytes'] += (int) $matches[1];
                    }
                }
            }

            // Get item counts from database
            /** @var array<array<string, mixed>> $rows */
            $rows = $db->query(
                "SELECT type, COUNT(*) AS item_count
                 FROM media_items
                 GROUP BY type"
            );

            // Fold the granular media_items.type ENUM into the four buckets the
            // dashboard / stats_storage ENUM supports. Types with no bucket
            // (e.g. book, video) are intentionally dropped.
            $typeToBucket = [
                'movie' => 'movie',
                'series' => 'series', 'season' => 'series', 'episode' => 'series',
                'music' => 'music', 'album' => 'music', 'artist' => 'music', 'audio' => 'music',
                'photo' => 'photo',
            ];

            foreach ($rows as $row) {
                $type = is_string($row['type'] ?? null) ? $row['type'] : '';
                $bucket = $typeToBucket[$type] ?? null;
                if ($bucket === null) {
                    continue;
                }
                $count = is_numeric($row['item_count'] ?? null) ? (int) $row['item_count'] : 0;
                $buckets[$bucket]['count'] += $count;
            }

            foreach ($buckets as $mediaType => $totals) {
                $collector->recordStorageSnapshot($mediaType, $totals['count'], $totals['bytes']);
            }

            $logger->info('Storage snapshot recorded', [
                'movie' => $buckets['movie']['count'],
                'movie_bytes' => $buckets['movie']['bytes'],
                'series' => $buckets['series']['count'],
                'series_bytes' => $buckets['series']['bytes'],
                'music' => $buckets['music']['count'],
                'music_bytes' => $buckets['music']['bytes'],
                'photo' => $buckets['photo']['count'],
                'photo_bytes' => $buckets['photo']['bytes'],
            ]);
        } catch (\Throwable $e) {
            $logger->error('Failed to record storage snapshot', [
                'error' => $e->getMessage(),
            ]);
        }
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

            // Device description endpoint (legacy path)
            $deviceDescController = new \Phlix\Server\Http\Controllers\Dlna\DeviceDescriptionController($cdsServer);
            $this->router->get('/description.xml', [$deviceDescController, 'handle']);

            // P10-S1: DLNA routes with /dlna/ prefix
            $this->router->get('/dlna/description.xml', [$deviceDescController, 'handle']);

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

            // P10-S1: DLNA ContentDirectory SOAP endpoint
            // Try to get ContentDirectory from container for the SOAP controller
            try {
                if ($this->container->has(\Phlix\Dlna\ContentDirectory::class)) {
                    $contentDirectory = $this->container->get(\Phlix\Dlna\ContentDirectory::class);
                    if ($contentDirectory instanceof \Phlix\Dlna\ContentDirectory) {
                        $cdsController = new \Phlix\Server\Http\Controllers\Dlna\DlnaContentDirectoryController(
                            $contentDirectory
                        );
                        $this->router->post('/dlna/content_directory', [$cdsController, 'handle']);
                    }
                }
            } catch (\Throwable $e) {
                // ContentDirectory not available - skip SOAP route
            }

            // CDS control endpoint (legacy path)
            $cdsControlController = new \Phlix\Server\Http\Controllers\Dlna\CdsControlController($cdsServer);
            $this->router->post('/cds/control', [$cdsControlController, 'handle']);
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
            $authMiddleware = new \Phlix\Server\Http\Middleware\AuthMiddleware();

            // All DLNA renderer routes require authentication
            $this->router->group('', function (Router $r) use ($controller): void {
                // List renderers
                $r->get('/api/v1/dlna/renderers', [$controller, 'listRenderers']);

                // Get renderer status
                $r->get('/api/v1/dlna/renderers/{id}/status', [$controller, 'getStatus']);

                // Start play-to session
                $r->post('/api/v1/dlna/renderers/{id}/play', [$controller, 'playTo']);

                // Pause playback
                $r->post('/api/v1/dlna/renderers/{id}/pause', [$controller, 'pause']);

                // Stop playback
                $r->post('/api/v1/dlna/renderers/{id}/stop', [$controller, 'stop']);

                // Seek to position
                $r->post('/api/v1/dlna/renderers/{id}/seek', [$controller, 'seek']);
            }, [$authMiddleware]);
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
            $authMiddleware = new \Phlix\Server\Http\Middleware\AuthMiddleware();

            // All Chromecast routes require authentication
            $this->router->group('', function (Router $r) use ($controller): void {
                // List discovered devices
                $r->get('/api/v1/cast/devices', [$controller, 'listDevices']);

                // Start casting
                $r->post('/api/v1/cast/devices/{id}/cast', [$controller, 'cast']);

                // Playback controls
                $r->post('/api/v1/cast/devices/{id}/play', [$controller, 'play']);
                $r->post('/api/v1/cast/devices/{id}/pause', [$controller, 'pause']);
                $r->post('/api/v1/cast/devices/{id}/stop', [$controller, 'stop']);
                $r->post('/api/v1/cast/devices/{id}/seek', [$controller, 'seek']);

                // Get session status
                $r->get('/api/v1/cast/devices/{id}/status', [$controller, 'getStatus']);
            }, [$authMiddleware]);
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
            $authMiddleware = new \Phlix\Server\Http\Middleware\AuthMiddleware();

            // All Roku routes require authentication
            $this->router->group('', function (Router $r) use ($controller): void {
                // List discovered devices
                $r->get('/api/v1/roku/devices', [$controller, 'listDevices']);

                // Send media to device
                $r->post('/api/v1/roku/devices/{id}/send', [$controller, 'sendMedia']);

                // Launch channel
                $r->post('/api/v1/roku/devices/{id}/launch/{channelId}', [$controller, 'launchChannel']);

                // Send keypress
                $r->post('/api/v1/roku/devices/{id}/key/{keyName}', [$controller, 'sendKey']);

                // Get session status
                $r->get('/api/v1/roku/devices/{id}/status', [$controller, 'getStatus']);
            }, [$authMiddleware]);
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
            $authMiddleware = new \Phlix\Server\Http\Middleware\AuthMiddleware();

            // All AirPlay routes require authentication
            $this->router->group('', function (Router $r) use ($controller): void {
                // List discovered devices
                $r->get('/api/v1/airplay/devices', [$controller, 'listDevices']);

                // Start streaming
                $r->post('/api/v1/airplay/devices/{id}/stream', [$controller, 'stream']);

                // Playback controls
                $r->post('/api/v1/airplay/devices/{id}/pause', [$controller, 'pause']);
                $r->post('/api/v1/airplay/devices/{id}/resume', [$controller, 'resume']);
                $r->post('/api/v1/airplay/devices/{id}/stop', [$controller, 'stop']);

                // Get session status
                $r->get('/api/v1/airplay/devices/{id}/status', [$controller, 'getStatus']);
            }, [$authMiddleware]);
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
            $ffmpegConfig = $this->loadFfmpegConfig();
            $ffmpegRunner = new \Phlix\Media\Transcoding\FfmpegRunner(
                $this->configString($ffmpegConfig, 'ffmpeg_path', '/usr/bin/ffmpeg'),
                $this->configString($ffmpegConfig, 'ffprobe_path', '/usr/bin/ffprobe'),
            );
            // SV-0.1: share the single merged hwaccel config source with all runners.
            $ffmpegRunner->setConfig(\Phlix\Config\HwAccelConfig::get());
            $gaplessManager = new \Phlix\Media\Playback\GaplessPlaybackManager(null, $ffmpegRunner);
            $trickplayController = $this->getTrickplayController();
            $chapterMarkerService = new \Phlix\Media\MarkerService($db);
            return new \Phlix\Server\Http\Controllers\MediaItemController($itemRepository, $markerService, $gaplessManager, $trickplayController, $chapterMarkerService);
        }

        /** @var \Phlix\Media\Library\ItemRepository */
        $itemRepository = $this->container->get(\Phlix\Media\Library\ItemRepository::class);
        $markerCandidateRepository = new \Phlix\Media\Markers\Detection\MarkerCandidateRepository($itemRepository);
        $markerService = new \Phlix\Media\Markers\MarkerService($itemRepository, $markerCandidateRepository);
        /** @var \Phlix\Media\Playback\GaplessPlaybackManager */
        $gaplessManager = $this->container->get(\Phlix\Media\Playback\GaplessPlaybackManager::class);
        $trickplayController = $this->getTrickplayController();
        $db = $this->createDatabaseConnection();
        $chapterMarkerService = new \Phlix\Media\MarkerService($db);
        return new \Phlix\Server\Http\Controllers\MediaItemController($itemRepository, $markerService, $gaplessManager, $trickplayController, $chapterMarkerService);
    }

    /**
     * Returns a SubtitleController instance (item repo + ffmpeg + extractor).
     *
     * @return \Phlix\Server\Http\Controllers\SubtitleController The controller instance.
     */
    private function getSubtitleController(): \Phlix\Server\Http\Controllers\SubtitleController
    {
        $ffmpegConfig = $this->loadFfmpegConfig();
        $ffmpeg = new \Phlix\Media\Transcoding\FfmpegRunner(
            $this->configString($ffmpegConfig, 'ffmpeg_path', '/usr/bin/ffmpeg'),
            $this->configString($ffmpegConfig, 'ffprobe_path', '/usr/bin/ffprobe'),
        );
        // SV-0.1: share the single merged hwaccel config source with all runners.
        $ffmpeg->setConfig(\Phlix\Config\HwAccelConfig::get());
        $extractor = new \Phlix\Media\Transcoding\Subtitles\SubtitleExtractor();

        if ($this->container === null) {
            $db = new \Phlix\Common\Database\PhlixMySQLConnection(
                '127.0.0.1',
                3306,
                'phlix',
                'root',
                'password'
            );
            $itemRepository = new \Phlix\Media\Library\ItemRepository($db);
        } else {
            /** @var \Phlix\Media\Library\ItemRepository */
            $itemRepository = $this->container->get(\Phlix\Media\Library\ItemRepository::class);
        }

        return new \Phlix\Server\Http\Controllers\SubtitleController($itemRepository, $ffmpeg, $extractor);
    }

    /**
     * Reads a string value from a config array, or a default when absent / not
     * a string.
     *
     * @param array<mixed, mixed> $config
     */
    private function configString(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * Loads the ffmpeg config array (binary paths), or [] when unavailable.
     * Values are read defensively by {@see configString()}.
     *
     * @return array<mixed, mixed>
     */
    private function loadFfmpegConfig(): array
    {
        $path = \dirname(__DIR__, 3) . '/config/ffmpeg.php';
        if (is_file($path)) {
            $config = include $path;
            if (is_array($config)) {
                return $config;
            }
        }

        return [];
    }

    /**
     * Returns a MediaMatchController instance (interactive per-item metadata match).
     *
     * Always resolved through the DI container in production so the
     * LibraryMetadataMatcher (and its admin-keyed TmdbProvider + resolvers) is
     * autowired; the admin middleware is wired when available so both endpoints
     * are admin-gated exactly like the whole-library match endpoint.
     *
     * @return \Phlix\Server\Http\Controllers\MediaMatchController The controller instance.
     */
    private function getMediaMatchController(): \Phlix\Server\Http\Controllers\MediaMatchController
    {
        $container = $this->container
            ?? throw new \RuntimeException('Container required for MediaMatchController');

        /** @var \Phlix\Media\Library\ItemRepository */
        $itemRepository = $container->get(\Phlix\Media\Library\ItemRepository::class);
        /** @var \Phlix\Media\Metadata\LibraryMetadataMatcher */
        $matcher = $container->get(\Phlix\Media\Metadata\LibraryMetadataMatcher::class);
        $controller = new \Phlix\Server\Http\Controllers\MediaMatchController($itemRepository, $matcher);

        if ($container->has(\Phlix\Server\Http\Middleware\AdminMiddleware::class)) {
            /** @var \Phlix\Server\Http\Middleware\AdminMiddleware */
            $adminMiddleware = $container->get(\Phlix\Server\Http\Middleware\AdminMiddleware::class);
            $controller->setAdminMiddleware($adminMiddleware);
        }

        return $controller;
    }

    /**
     * Returns a MediaPosterController instance (Step 15.1/15.2).
     *
     * @return \Phlix\Server\Http\Controllers\MediaPosterController The controller instance.
     */
    private function getMediaPosterController(): \Phlix\Server\Http\Controllers\MediaPosterController
    {
        $container = $this->container
            ?? throw new \RuntimeException('Container required for MediaPosterController');

        /** @var \Phlix\Media\Library\ItemRepository */
        $itemRepository = $container->get(\Phlix\Media\Library\ItemRepository::class);

        $tmdbConfigRaw = @include __DIR__ . '/../../../config/tmdb.php';
        $tmdbApiKey = is_array($tmdbConfigRaw)
            && isset($tmdbConfigRaw['api_key'])
            && is_string($tmdbConfigRaw['api_key'])
            ? $tmdbConfigRaw['api_key']
            : (getenv('TMDB_API_KEY') ?: '');
        $tmdb = new \Phlix\Media\Metadata\TmdbProvider($tmdbApiKey);

        $controller = new \Phlix\Server\Http\Controllers\MediaPosterController($itemRepository, $tmdb);

        if ($container->has(\Phlix\Server\Http\Middleware\AdminMiddleware::class)) {
            /** @var \Phlix\Server\Http\Middleware\AdminMiddleware */
            $adminMiddleware = $container->get(\Phlix\Server\Http\Middleware\AdminMiddleware::class);
            $controller->setAdminMiddleware($adminMiddleware);
        }

        return $controller;
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
     * Returns a MediaMarkerController instance for the P3-S1 marker API.
     *
     * Provides CRUD operations on the media_markers table for user-editable
     * skip intro/outro/credits/ad markers.
     *
     * @return \Phlix\Server\Http\Controllers\MediaMarkerController The controller instance.
     */
    private function getMediaMarkerController(): \Phlix\Server\Http\Controllers\MediaMarkerController
    {
        if ($this->container === null) {
            $db = new \Phlix\Common\Database\PhlixMySQLConnection(
                '127.0.0.1',
                3306,
                'phlix',
                'root',
                'password'
            );
            $markerService = new \Phlix\Media\MarkerService($db);
            $itemRepository = new \Phlix\Media\Library\ItemRepository($db);
            return new \Phlix\Server\Http\Controllers\MediaMarkerController($markerService, $itemRepository);
        }

        /** @var \Phlix\Server\Http\Controllers\MediaMarkerController */
        $controller = $this->container->get(\Phlix\Server\Http\Controllers\MediaMarkerController::class);
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
            $itemRepository = new \Phlix\Media\Library\ItemRepository($db);
            $libraryManager = new \Phlix\Media\Library\LibraryManager(
                $db,
                new \Phlix\Media\Library\MediaScanner(
                    $db,
                    $itemRepository
                ),
                new \Phlix\Media\Library\FolderWatcher()
            );
            $scanJobs = new \Phlix\Media\Library\ScanJobRepository($db);
            return new \Phlix\Server\Http\Controllers\LibraryController($libraryManager, $scanJobs, $itemRepository);
        }

        /** @var \Phlix\Media\Library\LibraryManager */
        $libraryManager = $this->container->get(\Phlix\Media\Library\LibraryManager::class);
        /** @var \Phlix\Media\Library\ScanJobRepository */
        $scanJobs = $this->container->get(\Phlix\Media\Library\ScanJobRepository::class);
        /** @var \Phlix\Media\Library\ItemRepository */
        $itemRepository = $this->container->get(\Phlix\Media\Library\ItemRepository::class);
        $controller = new \Phlix\Server\Http\Controllers\LibraryController($libraryManager, $scanJobs, $itemRepository);

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
        $controller = new \Phlix\Server\Http\Controllers\ThemeMediaController(
            $themeMediaRepository,
            $themeMediaFinder,
            $libraryManager
        );

        // Wire admin middleware if available
        if ($this->container->has(\Phlix\Server\Http\Middleware\AdminMiddleware::class)) {
            /** @var \Phlix\Server\Http\Middleware\AdminMiddleware */
            $adminMiddleware = $this->container->get(\Phlix\Server\Http\Middleware\AdminMiddleware::class);
            $controller->setAdminMiddleware($adminMiddleware);
        }

        return $controller;
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
     * Returns the item-level theme-music stream controller (M3).
     *
     * Serves `GET /stream/theme-media/item/{mediaItemId}` — the route the
     * `metadata_json.theme_audio_url` slot points at. Falls back to a
     * default-configured instance (config/theme_music.php) when no container is
     * present (parity with the other controller factories here).
     *
     * @return \Phlix\Server\Http\Controllers\ThemeMusicStreamController
     */
    private function getThemeMusicStreamController(): \Phlix\Server\Http\Controllers\ThemeMusicStreamController
    {
        if ($this->container === null) {
            $db = new \Phlix\Common\Database\PhlixMySQLConnection(
                '127.0.0.1',
                3306,
                'phlix',
                'root',
                'password'
            );
            $items = new \Phlix\Media\Library\ItemRepository($db);
            $finder = new \Phlix\Theming\ThemeMediaFinder();
            $config = \Phlix\Media\Metadata\ThemeMusic\ThemeMusicConfig::fromArray(
                self::loadThemeMusicConfig()
            );
            return new \Phlix\Server\Http\Controllers\ThemeMusicStreamController($items, $finder, $config);
        }

        /** @var \Phlix\Media\Library\ItemRepository */
        $items = $this->container->get(\Phlix\Media\Library\ItemRepository::class);
        /** @var \Phlix\Theming\ThemeMediaFinder */
        $finder = $this->container->get(\Phlix\Theming\ThemeMediaFinder::class);
        /** @var \Phlix\Media\Metadata\ThemeMusic\ThemeMusicConfig */
        $config = $this->container->get(\Phlix\Media\Metadata\ThemeMusic\ThemeMusicConfig::class);
        return new \Phlix\Server\Http\Controllers\ThemeMusicStreamController($items, $finder, $config);
    }

    /**
     * Load the raw `config/theme_music.php` array (M3), or an empty array when the
     * file is absent/invalid — {@see \Phlix\Media\Metadata\ThemeMusic\ThemeMusicConfig::fromArray()}
     * then applies its built-in defaults.
     *
     * @return array<string, mixed>
     */
    private static function loadThemeMusicConfig(): array
    {
        /** @var mixed $raw */
        $raw = @include __DIR__ . '/../../../config/theme_music.php';
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        /** @var mixed $value */
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }
        return $out;
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
        /** @var \Phlix\Media\Transcoding\TranscodeManager */
        $transcodeManager = $this->container->get(\Phlix\Media\Transcoding\TranscodeManager::class);
        return new \Phlix\Server\Http\Controllers\HlsController($hlsStreamer, $transcodeManager);
    }

    /**
     * Returns a TranscodeController instance.
     *
     * @return \Phlix\Server\Http\Controllers\TranscodeController The controller instance.
     */
    private function getTranscodeController(): \Phlix\Server\Http\Controllers\TranscodeController
    {
        /** @var \Phlix\Media\Transcoding\TranscodeManager $transcodeManager */
        $transcodeManager = $this->container?->get(\Phlix\Media\Transcoding\TranscodeManager::class)
            ?? throw new \RuntimeException('Container required for TranscodeController');
        return new \Phlix\Server\Http\Controllers\TranscodeController($transcodeManager);
    }

    /**
     * Returns a DashController instance.
     *
     * @return \Phlix\Server\Http\Controllers\DashController The controller instance.
     */
    private function getDashController(): \Phlix\Server\Http\Controllers\DashController
    {
        // DASH segments are the SAME CMAF fMP4 files the HLS pipeline writes, so
        // DashController serves from the shared HLS segment dir (config['hls']).
        $hlsConfigRaw = $this->config['hls'] ?? null;
        /** @var array<string, mixed> $hlsConfig */
        $hlsConfig = is_array($hlsConfigRaw) ? $hlsConfigRaw : [];
        $segmentDirRaw = $hlsConfig['segment_dir'] ?? null;
        $segmentDir = is_string($segmentDirRaw) ? $segmentDirRaw : sys_get_temp_dir() . '/phlix_hls';

        return new \Phlix\Server\Http\Controllers\DashController($segmentDir);
    }

    /**
     * Returns a LiveTvStreamController instance.
     *
     * @return \Phlix\Server\Http\Controllers\LiveTvStreamController The controller instance.
     *
     * @since SV-3.1
     */
    private function getLiveTvStreamController(): \Phlix\Server\Http\Controllers\LiveTvStreamController
    {
        // Get the storage path from livetv config or fall back to default.
        $livetvConfigRaw = $this->config['livetv'] ?? null;
        /** @var array<string, mixed> $livetvConfig */
        $livetvConfig = is_array($livetvConfigRaw) ? $livetvConfigRaw : [];
        $dvrConfigRaw = $livetvConfig['dvr'] ?? null;
        /** @var array<string, mixed> $dvrConfig */
        $dvrConfig = is_array($dvrConfigRaw) ? $dvrConfigRaw : [];
        $dvrStoragePath = $dvrConfig['storage_path'] ?? null;
        $livetvStoragePath = $livetvConfig['storage_path'] ?? null;
        $storagePath = is_string($dvrStoragePath)
            ? $dvrStoragePath
            : (is_string($livetvStoragePath) ? $livetvStoragePath : '/var/recordings');

        // SV-3.1b0: use the fully-wired Recorder from the shared container
        // instead of a bare path-lookup-only instance. Resolving LiveTvManager
        // links the shared Recorder singleton to its manager (so
        // resolveTunerStreamUrl() is reachable) and hands back that same wired
        // Recorder — the one the DVR capture pipeline uses. This keeps path
        // lookups here and future stream work on one instance per worker.
        if ($this->container === null) {
            throw new \RuntimeException('Container not available for LiveTv stream controller');
        }
        /** @var \Phlix\LiveTv\LiveTvManager $liveTvManager */
        $liveTvManager = $this->container->get(\Phlix\LiveTv\LiveTvManager::class);
        $recorder = $liveTvManager->getRecorder();

        return new \Phlix\Server\Http\Controllers\LiveTvStreamController($recorder, $storagePath);
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

        $provider = new \Phlix\Shared\Arr\TrashGuidesProvider();
        $logger = new \Phlix\Common\Logger\StructuredLogger('arr-sync', []);

        // An unconfigured Arr (Radarr) integration must be INACTIVE, never fatal.
        // phlix-shared >=0.12.0 RadarrClient strictly validates the baseUrl scheme
        // and throws on an empty/scheme-less URL. Since this controller is built
        // eagerly during route registration (loadArrSyncRoutes() -> here), a fresh
        // install with no Radarr URL configured would otherwise crash bootstrap.
        // When no valid http/https URL is present we construct the client against a
        // harmless localhost placeholder and disable the syncer so it never reaches
        // out — the legitimate scheme validation for *configured* URLs is untouched.
        $radarrScheme = $radarrUrl !== ''
            ? parse_url($radarrUrl, PHP_URL_SCHEME)
            : null;
        $radarrConfigured = $radarrScheme === 'http' || $radarrScheme === 'https';

        // Inject the non-blocking Swoole-coroutine transport so the *arr client
        // does NOT fall back to phlix-shared's blocking CurlArrTransport. The
        // server runs on Swoole's event loop with native-curl hooks deliberately
        // OFF ({@see \Phlix\Server\Runtime\SwooleRuntime}), so a blocking
        // curl_exec() would stall every coroutine on the worker until the (possibly
        // slow/unreachable) Radarr instance responds. WorkermanArrTransport yields
        // the coroutine instead. {@see \Phlix\Server\Arr\WorkermanArrTransport}
        $arrTransport = new \Phlix\Server\Arr\WorkermanArrTransport();
        $radarrClient = new \Phlix\Shared\Arr\RadarrClient(
            $radarrConfigured ? $radarrUrl : 'http://localhost',
            $radarrApiKey,
            null,
            30,
            $arrTransport
        );

        $syncer = new \Phlix\Server\Arr\CustomFormatSyncer(
            $radarrClient,
            $provider,
            $db,
            $logger
        );

        // Keep the integration dormant until an operator configures a real
        // Radarr URL; setEnabled(true) is the explicit opt-in path (admin
        // PUT /api/v1/admin/sync/enable).
        if (!$radarrConfigured) {
            $syncer->setEnabled(false);
        }

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
        $settings = null;
        $plugins = null;
        if ($this->container !== null) {
            try {
                /** @var \Psr\Log\LoggerInterface */
                $logger = $this->container->get(\Psr\Log\LoggerInterface::class);
            } catch (\Throwable) {
                // Logger not available — use null
            }
            try {
                /** @var \Phlix\Admin\SettingsRepository $settings */
                $settings = $this->container->get(\Phlix\Admin\SettingsRepository::class);
            } catch (\Throwable) {
                // Settings repository not available — fall back to env/file config.
                $settings = null;
            }
            try {
                /** @var \Phlix\Plugins\Repository\PluginRepository */
                $plugins = $this->container->get(\Phlix\Plugins\Repository\PluginRepository::class);
            } catch (\Throwable) {
                // Plugin repository not available
            }
        }

        $db = $this->connectionPool->getPooledConnection('mysql');

        return new \Phlix\Server\Http\Controllers\TraktOAuthController(
            logger: $logger,
            stateStore: null,
            configFile: null,
            settings: $settings,
            plugins: $plugins,
            db: $db,
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
                return $this->connectionPool->getPooledConnection('mysql');
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
