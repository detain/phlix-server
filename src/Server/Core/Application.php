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
use Phlix\Auth\RateLimitException;
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

        // S84: ThemeMiddleware used to be registered here as the FIRST global
        // middleware. It substituted the Smarty placeholders
        // `{$theme_css|raw}` / `{$theme_js|raw}` in an already-rendered HTML
        // body; no template has emitted either since the Smarty page renderer
        // was deleted (the `/app` SPA themes itself from @phlix/tokens), so
        // the substitution ran on every HTML response and never matched. It
        // was removed rather than left inert — see ThemeSourceRegistry for
        // the replacement, a validated token-map capability registry.

        // Register AccessScheduleMiddleware from container if available
        // Runs after auth to enforce time-based access restrictions
        if ($container->has(\Phlix\Server\Http\Middleware\AccessScheduleMiddleware::class)) {
            /** @var \Phlix\Server\Http\Middleware\AccessScheduleMiddleware */
            $accessScheduleMiddleware = $container->get(\Phlix\Server\Http\Middleware\AccessScheduleMiddleware::class);
            $this->middleware(function (Request $request, callable $next) use ($accessScheduleMiddleware): Response {
                $result = $accessScheduleMiddleware($request);
                if ($result !== null) {
                    // S295: a global middleware short-circuit returns BEFORE
                    // Router::markHeadOnly() runs, so a HEAD refused here used to
                    // ship the refusal body. Flag the reply exactly where this
                    // chain returns (see dispatch()'s docblock).
                    return self::flagHeadShortCircuitReply($request, $result);
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
        // Use the same config_dir source as HubServicesProvider so the fallback
        // HubClient in HealthController::getHubClient() finds enrollment files
        // in the same directory the container-wired HubClient uses.
        $hubConfig = is_array($this->config['hub'] ?? null) ? $this->config['hub'] : [];
        $configDir = is_string($hubConfig['config_dir'] ?? null) ? $hubConfig['config_dir'] : 'config';
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

        // S44/S45/S47: every external-auth-provider route is wired in ONE place —
        // {@see \Phlix\Server\Http\AuthProviderRouteRegistrar} — so a provider can
        // never ship with its routes silently unregistered (the S44 dead-OIDC
        // root cause). It registers the unauthenticated OIDC authorize/callback
        // login flow AND the authenticated identity-management group (list, link
        // OIDC/LDAP, and the S47 DELETE unlink), all behind AuthMiddleware where
        // required. Controllers are `[Class, method]` handlers resolved lazily per
        // request from the container so their injected deps (UserIdentityRepository,
        // provider registry, DB-backed OIDC state store) are built once the pool
        // is ready.
        if ($this->container !== null) {
            (new \Phlix\Server\Http\AuthProviderRouteRegistrar())->register($this->router);
        }

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

                        // Parental cap parity with WebPortalRouter's A-Z index: thread
                        // the ACTIVE profile's cap into the SAME bucket query so counts
                        // match the capped rows. No-op (null filter) for the owner
                        // and un-capped profiles; an unidentified request gets a
                        // deny-all cap (S235). This route is AuthMiddleware-gated,
                        // so that is a default, not a reachable state.
                        try {
                            /** @var \Phlix\Media\Library\RatingGate $ratingGate */
                            $ratingGate = $container->get(\Phlix\Media\Library\RatingGate::class);
                            $ratingFilter = $ratingGate->resolveFilterForUser($request->userId ?? '');
                            if ($ratingFilter !== null) {
                                $paramsArr['allowedRatings'] = $ratingFilter['allowedRatings'];
                                $paramsArr['allowUnrated'] = $ratingFilter['allowUnrated'];
                            }
                        } catch (\Throwable) {
                            // Gate unavailable → leave params uncapped (permissive).
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

        // Media item playback-info endpoint — REQUIRES a signed-in user.
        //
        // It was registered ungated. {@see \Phlix\Server\Workerman\HttpHandler} —
        // the Workerman daemon, and the ONLY entry point that dispatches THIS
        // router — runs it first and falls through to WebPortalRouter only when it
        // answers 404 (HttpHandler.php:241-269), so an anonymous caller got a 200
        // with the full playback plan (container/codec details, direct-play URL,
        // audio/subtitle track list). (`public/index.php`, the CGI entry point,
        // never dispatches this router at all: it builds its own Router, registers
        // only the admin group, and sends every other `/api/` path straight to
        // WebPortalRouter — public/index.php:212-243.) Registered inside an
        // AuthMiddleware group using the same nested `''`-prefix + full-path
        // pattern as the marker/extras group below.
        //
        // NOT the same thing as the parental RatingGate the handler applies: that
        // one narrows what an AUTHENTICATED user may see (and deliberately answers
        // 404, never 403, so a refusal cannot confirm the item exists). This group
        // only establishes that there IS a user.
        $mediaItemController = $this->getMediaItemController();
        $this->router->group(
            '',
            function (Router $r) use ($mediaItemController): void {
                $r->get('/api/v1/media/{id}/playback-info', [$mediaItemController, 'getPlaybackInfo']);
            },
            [new \Phlix\Server\Http\Middleware\AuthMiddleware()]
        );

        // Trickplay sprite and timeline URLs (public, no auth required).
        // These point to the existing /trickplay/{itemId}/ routes.
        $this->router->get('/api/v1/media/{id}/trickplay', [$mediaItemController, 'getTrickplay']);

        // Chapter thumbnail endpoint (public, no auth required).
        // Returns the thumbnail image for a specific chapter.
        $this->router->get('/api/v1/media/{id}/chapters/{index}/thumbnail', [$mediaItemController,
            'getChapterThumbnail']);

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
        //
        // BOTH routes REQUIRE a signed-in user.
        //
        // `start` ungated let an anonymous caller SPAWN a detached FFmpeg encode
        // (a resource-exhaustion vector) and receive the signed HLS
        // master/variant/subtitle URLs for the result.
        //
        // `status` ungated *appeared* safe because an anonymous probe with a
        // made-up job id gets a 401 — but that 401 is the HttpHandler fall-through
        // (this router 404s the unknown job, WebPortalRouter's copy of the path is
        // inside an AuthMiddleware group and answers the 401). A REAL job id never
        // 404s here, so it was answered 200 to an anonymous caller, handing back
        // the signed `master_url`, `variants[].url` and subtitle URLs
        // ({@see \Phlix\Server\Http\Controllers\TranscodeController::status()}).
        // The parental branch there is no defence either: `resolveRatingFilter()`
        // returns null for an anonymous caller, so the cap check is skipped
        // entirely.
        //
        // Same nested `''`-prefix group pattern as playback-info above; gating all
        // three is safe because the web SPA and the native clients send
        // `Authorization: Bearer`, and the only callers that ever hold a job id are
        // the ones that just called `start()` (now gated). hls.js/<video> never
        // touch these routes — they fetch the SIGNED `/hls/...` URLs the response
        // hands back, which stay unauthenticated.
        $transcodeController = $this->getTranscodeController();
        $this->router->group(
            '',
            function (Router $r) use ($transcodeController): void {
                $r->post('/api/v1/media/{id}/transcode', [$transcodeController, 'start']);
                $r->get('/api/v1/transcode/{jobId}/status', [$transcodeController, 'status']);
            },
            [new \Phlix\Server\Http\Middleware\AuthMiddleware()]
        );

        // Marker + extras endpoints — per-item metadata from the DB (intro/outro
        // markers for the "skip intro" UI, bulk per-show export, trailers and
        // other extras). Require a signed-in user (same as the media listings).
        $markerController = $this->getMarkerController();
        $extrasController = $this->getExtrasController();
        $subtitleController = $this->getSubtitleController();
        $remoteSubtitleController = $this->getRemoteSubtitleController();
        $this->router->group(
            '',
            function (Router $r) use (
                $markerController,
                $extrasController,
                $subtitleController,
                $remoteSubtitleController
            ): void {
                $r->get('/api/v1/media/{id}/markers', [$markerController, 'getMarkers']);
                $r->get('/api/v1/media/{id}/markers/intro', [$markerController, 'getIntroMarker']);
                $r->get('/api/v1/media/{id}/markers/outro', [$markerController, 'getOutroMarker']);
                $r->get('/api/v1/shows/{id}/markers/bulk', [$markerController, 'getShowMarkers']);
                $r->get('/api/v1/media/{id}/extras', [$extrasController, 'getExtras']);
                $r->get('/api/v1/media/{id}/trailers', [$extrasController, 'getTrailers']);
                $r->get('/api/v1/media/{id}/extras/other', [$extrasController, 'getOtherExtras']);
                // On-demand REMOTE (provider-plugin) subtitle search + download
                // (F3). Registered BEFORE the embedded-track `/subtitles/{index}`
                // route below so `/subtitles/search` (a literal segment) is not
                // shadowed by the `{index}` placeholder. Download attaches the
                // fetched subtitle as an external track and returns it.
                $r->get('/api/v1/media/{id}/subtitles/search', [$remoteSubtitleController, 'search']);
                $r->post('/api/v1/media/{id}/subtitles/download', [$remoteSubtitleController, 'download']);
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
            function (Router $r) use ($subtitleController, $remoteSubtitleController): void {
                // Downloaded external subtitle, served by row id as text/vtt.
                // Registered BEFORE `/subtitles/{index}` — its two-segment path
                // does not collide with the single-segment `{index}`, but keeping
                // it first is defensive and matches the search-route ordering.
                $r->get(
                    '/api/v1/media/{id}/subtitles/external/{streamId}',
                    [$remoteSubtitleController, 'serveExternal']
                );
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
        // Explicit "playback finished" signal (S30). Finalizes watch-time stats
        // and removes the item from Continue Watching via markAsWatched/clearProgress.
        $this->router->post('/api/v1/sessions/{id}/complete', [$sessionController, 'completePlayback']);
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

        // Most Watched rail (S31) — GLOBAL "trending" (most-watched across the
        // WHOLE server), fed by StatsCollector::getTopMedia(). Registered on THIS
        // shared router (the same one the /api/v1/media/* and session routes sit
        // on) so the Workerman HttpHandler path serves it exactly like its
        // siblings — and, being a literal segment tried before WebPortalRouter's
        // `/api/v1/media/{id}`, it is never swallowed as an item id. Auth-gated
        // with AuthMiddleware to match the audience of the other home-rail media
        // endpoints (GET /api/v1/media et al. all require a signed-in user).
        if ($this->container !== null) {
            try {
                /** @var \Phlix\Server\Http\Controllers\MostWatchedController $mostWatchedController */
                $mostWatchedController = $this->container->get(
                    \Phlix\Server\Http\Controllers\MostWatchedController::class
                );
                $this->router->group(
                    '',
                    function (Router $r) use ($mostWatchedController): void {
                        $r->get('/api/v1/media/most-watched', [$mostWatchedController, 'mostWatched']);
                    },
                    [new \Phlix\Server\Http\Middleware\AuthMiddleware()]
                );
            } catch (\Throwable) {
                // Controller unavailable — route not registered
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

        // Wire the settings store + restart controller so the Start/Stop toggle
        // can genuinely PERSIST `dlna.cds_enabled` and schedule a graceful
        // reload (the CDS routes are gated on that setting at worker start —
        // see loadCdsRoutes()). Best-effort, like the CdsServer wiring above:
        // a resolution failure must not drop the admin DLNA route group.
        try {
            if ($this->container->has(\Phlix\Admin\SettingsRepository::class)) {
                $settingsRepo = $this->container->get(\Phlix\Admin\SettingsRepository::class);
                if ($settingsRepo instanceof \Phlix\Admin\SettingsRepository) {
                    $controller->setSettingsRepository($settingsRepo);
                }
            }
        } catch (\Throwable) {
            // Settings store unavailable — start()/stop() report 503.
        }

        try {
            if ($this->container->has(\Phlix\Server\Http\Controllers\Admin\AdminRestartController::class)) {
                $restartController = $this->container->get(
                    \Phlix\Server\Http\Controllers\Admin\AdminRestartController::class,
                );
                if ($restartController instanceof \Phlix\Server\Http\Controllers\Admin\AdminRestartController) {
                    $controller->setRestartController($restartController);
                }
            }
        } catch (\Throwable) {
            // Restart controller unavailable — toggle still persists, reloadScheduled=false.
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
     * ## S208 decision: this NON-ADMIN registration is KEPT, not deleted
     *
     * The admin SPA calls the `/api/v1/admin/profiles/{id}/…` spelling, which
     * {@see \Phlix\Server\Http\Routes\AdminRoutes} now registers. Deleting the
     * un-prefixed set below would have been a breaking change for clients that
     * already ship against it — `phlix-console-client`
     * (`src/Api/Admin/AdminClient.php`), `phlix-mobile-client`
     * (`src/api/ParentalControlsManager.ts`) and `phlix-roku-client`
     * (`source/lib/ApiClient.brs`) all call the un-prefixed paths.
     *
     * Keeping them is only safe because the IDOR is closed in the HANDLERS
     * rather than by the route prefix: each handler runs
     * {@see \Phlix\Access\ProfileAccessPolicy::canManageProfile()} (own profile,
     * or server admin) and each by-id handler re-checks that the record belongs
     * to the `{profileId}` in the path. The group middleware below is plain
     * {@see \Phlix\Server\Http\Middleware\AuthMiddleware}, and that is
     * deliberately NOT the authorization gate.
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
     * Builds the parental-controls owner-or-admin gate for the no-container
     * fallback paths (S208).
     *
     * The container path resolves {@see \Phlix\Access\ProfileAccessPolicy} by
     * autowiring. This helper exists so the three hand-rolled fallbacks below
     * cannot accidentally construct a controller WITHOUT the gate — the
     * constructor parameter is required, so omitting it is a fatal error rather
     * than a silent fail-open.
     *
     * @param \Workerman\MySQL\Connection $db Connection the fallback already holds.
     */
    private function profileAccessPolicy(\Workerman\MySQL\Connection $db): \Phlix\Access\ProfileAccessPolicy
    {
        return new \Phlix\Access\ProfileAccessPolicy(
            new \Phlix\Auth\UserProfileManager($db),
            new \Phlix\Auth\UserRepository($db),
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
            return new \Phlix\Server\Http\Controllers\AccessScheduleController(
                $accessScheduleService,
                $this->profileAccessPolicy($db),
            );
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
            return new \Phlix\Server\Http\Controllers\ProfileTagController(
                $profileTagService,
                $this->profileAccessPolicy($db),
            );
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
            // Pass the settings store here too. This fallback path is only
            // taken when there is no container, but it serves the same admin
            // routes — leaving it unwired would make
            // `access.default_concurrent_streams` apply on some requests and
            // not others, which is harder to diagnose than not shipping it.
            $streamSessionService = new \Phlix\Access\StreamSessionService(
                $db,
                new \Phlix\Admin\SettingsRepository($db),
            );
            return new \Phlix\Server\Http\Controllers\StreamLimitController(
                $streamSessionService,
                $this->profileAccessPolicy($db),
            );
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
    /**
     * Overlay the `lastfm.*` admin settings onto the raw `config/lastfm.php`
     * array before anything is constructed from it.
     *
     * ## Why this exists
     *
     * `LastfmController` already applied these overrides — but only inside
     * `buildOverrideAwareConfig()`, whose result fed exactly one thing: the
     * `api_key_set` boolean returned by `status()`. Both objects that actually
     * talk to Last.fm were built from the override-BLIND raw include: the
     * `LastfmConfig` gating `apiAuthorize()`/`apiCallback()`, and the
     * `LastfmApi` that signs the token exchange.
     *
     * So an operator who saved `lastfm.api_key` in the admin Settings page saw
     * the UI report "configured" while the handshake kept sending the key from
     * `config/lastfm.php`. The control reported its own success.
     *
     * Applying the overlay HERE fixes both objects at once, which patching the
     * controller's call sites could not: `LastfmApi` takes its credentials as
     * constructor-promoted readonly properties, and its `$http` transport is a
     * constructor-injected test seam, so rebuilding it per request would break
     * that seam.
     *
     * Semantics: this runs at route-build time (once per worker), so a changed
     * credential applies on the next reload, not mid-request. That is why the
     * `lastfm.*` schema keys carry `"restart": true` — plan §4 rule 2 option (b).
     * Compare `TraktOAuthController::loadConfig()`, which re-reads per request
     * and is genuinely live.
     *
     * @param array<string, mixed>     $rawConfig Raw `config/lastfm.php` array.
     * @param \Phlix\Admin\SettingsRepository|null $settings Null when the
     *        container could not supply one; the file values then stand.
     *
     * @return array<string, mixed> `$rawConfig` with any saved overrides applied.
     *
     * @since 1.6.0
     */
    private function applyLastfmOverrides(array $rawConfig, ?\Phlix\Admin\SettingsRepository $settings): array
    {
        if ($settings === null) {
            return $rawConfig;
        }

        // Mirrors LastfmController::SETTING_KEY_MAP. A non-empty string DB value
        // wins over the env/file literal; a boolean always wins.
        $map = [
            'lastfm.api_key'       => 'api_key',
            'lastfm.shared_secret' => 'shared_secret',
            'lastfm.enabled'       => 'enabled',
        ];

        foreach ($map as $settingKey => $configKey) {
            try {
                $override = $settings->getOverride($settingKey);
            } catch (\Throwable) {
                // A settings-store failure must never stop Last.fm from loading
                // on its file config.
                continue;
            }

            $value = $override['value'] ?? null;
            if (is_string($value) && $value !== '') {
                $rawConfig[$configKey] = $value;
            } elseif (is_bool($value)) {
                $rawConfig[$configKey] = $value;
            }
        }

        return $rawConfig;
    }

    /**
     * Resolve the SettingsRepository, or null when the container cannot supply it.
     */
    private function optionalSettingsRepository(): ?\Phlix\Admin\SettingsRepository
    {
        if ($this->container === null) {
            return null;
        }

        try {
            /** @var \Phlix\Admin\SettingsRepository $settings */
            $settings = $this->container->get(\Phlix\Admin\SettingsRepository::class);

            return $settings;
        } catch (\Throwable) {
            // Settings repository not available — fall back to env/file config.
            return null;
        }
    }

    private function getLastfmController(): \Phlix\Server\Http\Controllers\Admin\LastfmController
    {
        $settings = $this->optionalSettingsRepository();

        $rawConfig = include __DIR__ . '/../../../config/lastfm.php';
        // Overlay BEFORE constructing, so $config and $api both see the override.
        $rawConfig = $this->applyLastfmOverrides(is_array($rawConfig) ? $rawConfig : [], $settings);

        $config = \Phlix\Server\Integrations\Lastfm\LastfmConfig::fromArray($rawConfig);
        $db = $this->connectionPool->getPooledConnection('mysql');
        $sessions = new \Phlix\Server\Integrations\Lastfm\LastfmSessionRepository($db);
        $api = new \Phlix\Server\Integrations\Lastfm\LastfmApi(
            $config->apiKey,
            $config->sharedSecret,
        );

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
        $settings = $this->optionalSettingsRepository();

        try {
            $rawConfig = include __DIR__ . '/../../../config/lastfm.php';
            // Overlay BEFORE constructing — see applyLastfmOverrides().
            $rawConfig = $this->applyLastfmOverrides(is_array($rawConfig) ? $rawConfig : [], $settings);

            $config = \Phlix\Server\Integrations\Lastfm\LastfmConfig::fromArray($rawConfig);
            $db = $this->connectionPool->getPooledConnection('mysql');
            $sessions = new \Phlix\Server\Integrations\Lastfm\LastfmSessionRepository($db);
            $api = new \Phlix\Server\Integrations\Lastfm\LastfmApi(
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
     *   matchMetadata, refreshMetadata, prune, clearMetadata, clearArtwork,
     *   deleteAll, scanStatus, scanHistory (15 routes)
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

        // Force metadata re-match (metadata_refresh job): re-fetches metadata even
        // for already-matched items, unlike match-metadata which skips them. Same
        // 3-segment literal shape, so no shadowing with the {id} routes.
        $this->router->post('/api/v1/libraries/{id}/refresh-metadata', [$libraryController, 'refreshMetadata']);

        // Fine-grained library maintenance ops (migration 084) — each enqueues a
        // new scan-job type drained off the HTTP path by LibraryScanWorker, so
        // the existing scan-status badge/polling shows progress unchanged. All
        // are 3-segment literal paths, so no shadowing with the {id} routes.
        //   prune         — drop items whose files are gone (non-destructive)
        //   clear-metadata— reset items to filesystem basics (re-fetchable)
        //   clear-artwork — delete locally cached artwork (frees disk)
        //   delete-all    — DESTRUCTIVE remove every item (requires confirm=true)
        $this->router->post('/api/v1/libraries/{id}/prune', [$libraryController, 'prune']);
        $this->router->post('/api/v1/libraries/{id}/clear-metadata', [$libraryController, 'clearMetadata']);
        $this->router->post('/api/v1/libraries/{id}/clear-artwork', [$libraryController, 'clearArtwork']);
        $this->router->post('/api/v1/libraries/{id}/delete-all', [$libraryController, 'deleteAll']);

        // S284: re-prime the FILE-based media-asset queue (chapter thumbnails,
        // trickplay sprite, Roku BIF) for a library's EXISTING rows. That queue's
        // only other producer is the scanner, so before this route an install
        // scanned before S275 fixed the trickplay producer could never acquire a
        // sprite/BIF short of a full rescan. Same 3-segment literal shape as the
        // maintenance ops above, so no shadowing with the `{id}` routes, and the
        // same in-controller admin gate (S272) rather than route-level middleware.
        $this->router->post('/api/v1/libraries/{id}/regenerate-assets', [$libraryController, 'regenerateAssets']);

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
     * - GET /trickplay/{jobId}/sprite.jpg    — Sprite sheet image
     * - GET /trickplay/{jobId}/thumbs.bif    — Roku BIF trick-mode archive
     * - GET /trickplay/{jobId}/timeline.json — Timeline mapping JSON
     *
     * S275 removed `thumb-{index}.jpg` and `index.xml`. Only the deleted
     * `TrickplayGenerator` wrote the `bif_NN.jpg`/`index.xml` files behind them,
     * and a runtime probe proved that class was never even autoloaded during a
     * media-asset run — they were routes over files nothing produces.
     *
     * @since 0.11.0
     */
    private function loadTrickplayRoutes(): void
    {
        $controller = $this->getTrickplayController();

        // Public read-only routes — no auth required, job ID provides scoping.
        // These are low-sensitivity preview thumbnails, not media content.
        //
        // Every path here ends in a distinct literal segment, so none can absorb
        // another; `thumbs.bif` in particular is NOT matched by any sibling.
        $this->router->get('/trickplay/{jobId}/sprite.jpg', [$controller, 'getSprite']);
        $this->router->get('/trickplay/{jobId}/thumbs.bif', [$controller, 'getBif']);
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
        // Load trickplay config (base_url only — see the storage note below).
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

        $baseUrl = is_string($trickplayConfig['base_url'] ?? null) ? $trickplayConfig['base_url'] : '';

        return new \Phlix\Media\Streaming\Trickplay\TrickplayController(
            $this->resolveTrickplayStorageDir(),
            $baseUrl
        );
    }

    /**
     * Resolves the directory trickplay artefacts are served from.
     *
     * ⚠ This MUST be ffmpeg's `transcode_dir`, because that is where the only
     * producer writes: `MediaAssetGenerationJob` builds its output path from
     * `FfmpegRunner::getTranscodeDir() . '/trickplay/' . $itemId`, and
     * `TrickplayController` appends the same `'/trickplay/' . $jobId` suffix.
     *
     * Until S275 this read `config/trickplay.php`'s `storage_dir` (`/var/trickplay`)
     * while the producer wrote under `/var/transcodes`, so the two halves pointed
     * at different trees and every artefact would have 404'd. Deriving both from
     * one key removes the possibility of that drift instead of re-syncing two.
     *
     * @return string Absolute base directory (the `/trickplay/{id}` suffix is
     *                appended by the controller).
     */
    private function resolveTrickplayStorageDir(): string
    {
        $configFile = dirname(__DIR__, 2) . '/config/ffmpeg.php';
        if (file_exists($configFile)) {
            /** @var mixed $ffmpegConfig */
            $ffmpegConfig = include $configFile;
            if (is_array($ffmpegConfig) && is_string($ffmpegConfig['transcode_dir'] ?? null)) {
                return $ffmpegConfig['transcode_dir'];
            }
        }

        return '/var/transcodes';
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
     * Flag a GLOBAL middleware short-circuit reply head-only on a `HEAD`.
     *
     * `Application::dispatch()` runs the global chain BEFORE the router, so a
     * global middleware that short-circuits (returns a {@see Response} instead
     * of calling `$next`) never reaches {@see Router::markHeadOnly()}. Before
     * S295, a `HEAD` refused by {@see \Phlix\Server\Http\Middleware\AccessScheduleMiddleware}
     * therefore shipped the 403 envelope as a body — the recoverable RFC 9110
     * §9.3.2 shape (one self-consistent `Content-Length`), but still a
     * keep-alive desync for a header-only client. This is the seam where the
     * global chain returns; flagging here sends every current AND future global
     * short-circuit through {@see Response::asHeadReply()}, with the router
     * never involved.
     *
     * A non-`HEAD` reply is returned untouched, so a `GET` still carries its
     * whole body — the discriminating control for this gate.
     *
     * @param Request  $request  The live request (only its method is read).
     * @param Response $response The short-circuit reply about to be returned.
     *
     * @return Response The same instance, for use in a `return` expression.
     */
    private static function flagHeadShortCircuitReply(Request $request, Response $response): Response
    {
        if ($request->method === 'HEAD') {
            return $response->asHeadReply();
        }

        return $response;
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
     * ## `HEAD` replies: this layer is OUTSIDE the router's guarantee, and the
     * return seam is now flagged
     *
     * {@see Router::markHeadOnly()} flags every `HEAD` reply the router returns from
     * a matched route, which is what keeps a single `Content-Length` on the wire
     * (RFC 9110 §8.6). The middleware chain built here runs *before* the router, so a
     * global middleware that SHORT-CIRCUITS (returns a {@see Response} instead of
     * calling `$next`) never reaches that flag. **S295 closed the seam where this
     * chain returns**: the constructor's AccessScheduleMiddleware wrapper routes
     * every global short-circuit reply through {@see self::flagHeadShortCircuitReply()}
     * — {@see Response::asHeadReply()} on a `HEAD`, the reply untouched otherwise — so
     * the refusal body no longer ships on a `HEAD` (RFC 9110 §9.3.2) and the
     * `Content-Length` is the entity the equivalent `GET` would have returned, never
     * two fields (RFC 9110 §8.6: a caller-set length is authoritative in
     * {@see Response::asHeadReply()} and {@see \Phlix\Server\Workerman\BodylessResponse}
     * renders that single field). Because the flag lives at the chain-return seam
     * rather than inside any one middleware, a FUTURE global middleware that
     * short-circuits gets the same gate by construction.
     *
     * The only global middleware that can short-circuit today is
     * {@see \Phlix\Server\Http\Middleware\AccessScheduleMiddleware}, whose three
     * refusals declare no `Content-Length` of their own — the recoverable "body on
     * a HEAD" shape that S295 just closed, never the unrecoverable two-length one
     * (a global middleware that declares its own `Content-Length` on a short-circuit
     * would still ship two on a GET, which is pre-existing and outside this HEAD
     * boundary).
     *
     * ⚠ **What `ApplicationHeadOnlyBoundaryTest` actually pins** — the earlier wording
     * here ("pins both halves so neither can drift silently") overstated it, and the
     * S105 AC audit reproduced the gap: an EXTRA global middleware short-circuiting with
     * its own `Content-Length` left the whole Unit suite green. Corrected, the alarm
     * now covers three concrete drifts: `AccessScheduleMiddleware` starting to declare
     * a `Content-Length`; the `$this->middleware(...)` registration COUNT below changing
     * at all (so it fires whatever the middleware is, and on a removal too); and a
     * registration smuggled in from another `src/` file via
     * {@see self::getInstance()}. Since S295 the two-length framing defect itself is
     * additionally unreachable on the global chain for a `HEAD` (the wrapper flags it
     * before the encoder runs), so the boundary test's alarm now pins that closure.
     *
     * S84 lowered that count from two to one: `ThemeMiddleware` — the pass-through half
     * of the original measurement — was retired along with the Smarty placeholders it
     * substituted, leaving `AccessScheduleMiddleware` as the only global middleware.
     * S295 re-measured the count at one; the wrapper gained the HEAD gate, the
     * registration count did not change.
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

        // Periodic background timers. Shared with the Workerman daemon, which
        // reaches them via {@see self::startBackgroundTimers()} because it never
        // calls this method — see that method's docblock.
        $this->startBackgroundTimers();

        $request = Request::fromGlobals();

        // Build the final handler that dispatches to the router
        $finalHandler = function (Request $request): Response {
            return $this->router->dispatch($request);
        };

        // Apply global middleware in reverse order (so first registered runs first).
        // Same HEAD boundary as {@see self::dispatch()} — a global middleware that
        // short-circuits returns before Router::markHeadOnly() can flag the reply.
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
        // SV-4.15(c): a rate-limit trip that bubbles out of dispatch must map to
        // HTTP 429 + Retry-After, NOT the generic 500 below. Mirrors the central
        // catch in the Workerman HttpHandler and public/index.php so all three
        // dispatch paths emit the identical envelope via rateLimitResponse().
        if ($e instanceof RateLimitException) {
            self::rateLimitResponse($e)->send();
            return;
        }

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
     * Build the canonical HTTP 429 envelope for a {@see RateLimitException}
     * that bubbles out of dispatch: `status(429)` + a `Retry-After` header
     * (seconds until the window resets, never negative) + a JSON body of
     * `{error: 'Too Many Requests', code: 'rate_limited'}`.
     *
     * SV-4.15(c): shared by every dispatch entrypoint — the Workerman
     * {@see \Phlix\Server\Workerman\HttpHandler} central catch, this class's
     * {@see self::handleException()} (used by {@see self::run()}), and the CGI
     * `public/index.php` path — so a limiter trip produces identical output no
     * matter which entrypoint served the request. Static + side-effect-free so
     * tests exercise it directly.
     */
    public static function rateLimitResponse(RateLimitException $e): Response
    {
        return (new Response())
            ->status(429)
            ->header('Retry-After', (string) $e->retryAfterSeconds())
            ->json(['error' => 'Too Many Requests', 'code' => 'rate_limited']);
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

            $musicScanner = new \Phlix\Media\Music\MusicLibraryScanner(
                $db,
                new \Phlix\Media\Transcoding\FfmpegRunner()
            );
            $musicLibraryService = new \Phlix\Media\Music\MusicLibraryService($db, $musicScanner);
            $generator = new \Phlix\Admin\NewsletterGenerator(
                new \Phlix\Stats\StatsCollector($db),
                new \Phlix\Media\Library\LibraryManager(
                    $db,
                    new \Phlix\Media\Library\MediaScanner(
                        $db,
                        new \Phlix\Media\Library\ItemRepository($db),
                    ),
                    new \Phlix\Media\Library\FolderWatcher(),
                    $musicLibraryService
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
    /**
     * Register the periodic background timers.
     *
     * ## Why this method exists
     *
     * These four timers were originally registered only inside {@see self::run()}.
     * `run()` is a **CGI-era entry point** — it ends in `Request::fromGlobals()` and
     * dispatches a single request — and it has **no caller anywhere in the tree**:
     * `start.php` constructs {@see Application} (`start.php:199`, `:760`) but never
     * runs it, and `public/index.php` dispatches through `Router` directly. The only
     * `->run()` occurrences are a docblock example on this class and an unrelated CLI
     * class in `bin/phlix`.
     *
     * `start.php:191-196` records that the daemon "does NOT call boot() or run() and
     * therefore does not start hub/relay/discovery/newsletter/backup timers", and
     * re-wires the hub heartbeat (`start.php:670`) and relay tunnel separately. The
     * remaining timers were missed, so on every Workerman install:
     *
     *  - **automatic backups never ran** (`backups` was empty on production),
     *  - **`stats_storage` was never written**, leaving the admin dashboard's Storage
     *    card permanently blank — this method's initial snapshot is its only writer,
     *  - wedged transcodes never had their concurrency slot reclaimed,
     *  - the newsletter never sent.
     *
     * Nothing detected this because a timer that is never registered throws nothing.
     *
     * Calling `run()` from the daemon would be wrong (it would dispatch a bogus
     * request from CLI globals into a resident worker), so the timer block lives here
     * and both entry points call it.
     *
     * ## Deliberately NOT included: `startDiscoveryIfEnabled()`
     *
     * {@see \Phlix\Discovery\DiscoveryServer} is registered in no DI provider, its
     * `config/discovery.php` is loaded by nothing, and it advertises over SSDP
     * alongside the DLNA `SsdpAdvertiser` the daemon already starts
     * (`start.php:937`). Activating it here would risk an SSDP port conflict to
     * enable a subsystem with no configuration path. It stays in `run()` only.
     *
     * ## Concurrency
     *
     * Must be called from exactly ONE worker (the daemon uses a dedicated `count=1`
     * worker). Running it in every HTTP worker would multiply the backup and snapshot
     * writes by the worker count.
     *
     * @return void
     *
     * @since 1.6.0
     */
    public function startBackgroundTimers(): void
    {
        // Start newsletter timer if enabled
        $this->startNewsletterTimerIfEnabled();

        // Start backup timer if enabled
        $this->startBackupTimerIfEnabled();

        // Start the periodic storage-snapshot timer so the admin dashboard's
        // Storage card has data (nothing else writes stats_storage).
        $this->startStorageSnapshotTimer();

        // Start the transcode stale-job reaper so wedged encodes free their
        // concurrency slot promptly (default: checks every 45 s, kills jobs
        // older than 120 s or with no segment within 60 s). Only 'running' rows
        // are reaped; on-demand seek jobs are inserted as 'completed' precisely
        // so this cannot tear them down mid-playback.
        $this->startTranscodeReaperTimer();

        // Start the core (server application) update check — S74. Two arms: a
        // one-shot catch-up a few minutes after boot, plus the steady-state
        // daily poll. Registered LAST so a failure here cannot perturb the
        // timers above; it is guarded internally as well.
        $this->startCoreUpdateCheckTimer();

        // Drain the `maintenance_jobs` queue — S77. This fork is `count = 1`,
        // which is what makes "one maintenance job at a time" true; the HTTP
        // worker only ENQUEUES, because these tasks shell out to `du -sb` and
        // scan `media_items` whole.
        $this->startMaintenanceQueueTimer();
    }

    /**
     * Arm the maintenance-task queue drainer (S77 / updates.md #49).
     *
     * Two of the five admin maintenance tasks cannot run in an HTTP request:
     * `storage-snapshot` shells out to `du -sb` per vault bucket and
     * `dedupe-paths` scans `media_items` whole and then opens a transaction per
     * duplicate group. Both would stall every concurrent connection on the
     * worker that served the click, so the controller enqueues and
     * {@see \Phlix\Admin\Maintenance\MaintenanceQueueWorker} drains here.
     *
     * Fully guarded, like every sibling above: this runs inside the forked
     * `phlix-background-timers` process, where an uncaught throwable takes the
     * process down and systemd restarts it. Losing maintenance must never cost
     * the backup and snapshot timers that share the fork.
     *
     * @return void
     *
     * @since S77 (admin maintenance tasks)
     */
    private function startMaintenanceQueueTimer(): void
    {
        $logger = \Phlix\Common\Logger\LoggerFactory::get(\Phlix\Common\Logger\LogChannels::APPLICATION);

        try {
            /** @var \Phlix\Admin\Maintenance\MaintenanceQueueWorker|null $worker */
            $worker = $this->container?->get(\Phlix\Admin\Maintenance\MaintenanceQueueWorker::class);

            if (!$worker instanceof \Phlix\Admin\Maintenance\MaintenanceQueueWorker) {
                $logger->debug('MaintenanceQueueWorker not available; skipping queue timer');

                return;
            }

            $worker->start();
            $logger->debug('Maintenance queue timer started');
        } catch (\Throwable $e) {
            $logger->error('Failed to start maintenance queue timer', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Arm the core update-check worker (S74 / updates.md #48).
     *
     * Reads `config/updates.php` for the poll interval — the same
     * `_config_dir`-relative `include` {@see self::startBackupTimerIfEnabled()}
     * uses — and hands it to
     * {@see \Phlix\Server\Updates\CoreUpdateCheckWorker::start()}, which arms
     * BOTH the boot catch-up and the steady-state poll. See that method for why
     * a bare `Timer::add(86400, …)` is not sufficient on a box that is deployed
     * to; it is the same defect that left `backups` empty on production.
     *
     * Fully guarded: this runs inside the forked `phlix-background-timers`
     * worker, where an uncaught throwable takes the process down. Losing the
     * update check must never cost availability.
     *
     * The `updates.check_enabled` toggle is deliberately NOT consulted here —
     * it is read as an EFFECTIVE value on every tick by
     * {@see \Phlix\Server\Updates\CoreUpdateCheckService::isCheckEnabled()}, so
     * an admin flipping it takes effect without a restart. Gating the ARMING on
     * it would silently require one.
     *
     * @return void
     *
     * @since S74 (core update check)
     */
    private function startCoreUpdateCheckTimer(): void
    {
        $logger = \Phlix\Common\Logger\LoggerFactory::get(\Phlix\Common\Logger\LogChannels::APPLICATION);

        try {
            $configDirRaw = $this->config['_config_dir'] ?? 'config';
            $configDir = is_string($configDirRaw) ? $configDirRaw : 'config';
            $updatesConfigFile = $configDir . '/updates.php';

            $pollSeconds = \Phlix\Server\Updates\CoreUpdateCheckWorker::DEFAULT_POLL_SECONDS;
            if (file_exists($updatesConfigFile)) {
                /** @var mixed $updatesConfig */
                $updatesConfig = include $updatesConfigFile;
                if (is_array($updatesConfig) && is_int($updatesConfig['poll_seconds'] ?? null)) {
                    /** @var int $configuredPoll */
                    $configuredPoll = $updatesConfig['poll_seconds'];
                    if ($configuredPoll > 0) {
                        $pollSeconds = $configuredPoll;
                    }
                }
            }

            /** @var \Phlix\Server\Updates\CoreUpdateCheckWorker|null $worker */
            $worker = $this->container?->get(\Phlix\Server\Updates\CoreUpdateCheckWorker::class);
            if ($worker === null) {
                $logger->debug('CoreUpdateCheckWorker not available; skipping update-check timer');

                return;
            }

            $worker->start($pollSeconds);
        } catch (\Throwable $e) {
            $logger->error('Failed to start core update check timer', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delay (seconds) before the post-boot catch-up backup check.
     *
     * Long enough to stay off the boot path, short enough that any install which
     * stays up a few minutes performs the check. {@see self::registerBackupTimer()}
     * explains why a daily-only timer never fires on a box that gets deployed to.
     */
    private const BACKUP_INITIAL_CHECK_DELAY = 300;

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

        $check = function () use ($backupManager, $intervalDays, $logger): void {
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
        };

        // Catch-up check shortly after boot.
        //
        // This is NOT belt-and-braces — without it the feature does not work on a
        // box that is deployed to. A bare Timer::add(86400) fires only after 24h of
        // UNINTERRUPTED uptime, and every restart or reload resets that countdown
        // to zero, so an install that restarts even once a day never backs up. That
        // is what production looked like: the timer had been armed since the
        // 2026-07-20 deploy, the worker was alive and its sibling storage-snapshot
        // timer was writing rows, and `backups` was still empty.
        //
        // Deciding at boot is safe because the decision is idempotent: $check
        // consults getNextScheduledBackup(), which returns last_backup + interval,
        // so restart churn cannot produce more than one backup per interval. The
        // delay only keeps the archive off the boot path — it is not a correctness
        // guard. One-shot: Workerman's Timer::add repeats unless passed [], false.
        \Workerman\Timer::add(self::BACKUP_INITIAL_CHECK_DELAY, $check, [], false);

        // Steady-state daily check thereafter.
        \Workerman\Timer::add(86400, $check);
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
     * Records one storage snapshot per bucket for the periodic daemon timer.
     *
     * The collection itself (vault scan + DB counts + `media_items.type` fold)
     * lives in {@see \Phlix\Stats\StorageSnapshotHelper::collectBuckets()} so
     * the daemon and the PHP-FPM bootstrap path cannot drift apart — they
     * previously carried byte-identical copies of the type map, and both were
     * missing the same four ENUM members.
     *
     * Failures are logged and swallowed so a snapshot run can never take down the worker.
     *
     * The whole run is written through ONE
     * {@see \Phlix\Stats\StatsCollector::recordStorageSnapshots()} call so a bucket
     * can only ever get a single row per run — the dashboard summary SUMS rows that
     * share a `recorded_at` second, so several rows for one bucket would inflate it
     * (S102 review r1, MED-2).
     *
     * The `$collector` is deliberately the SAME instance across the initial run and
     * every timer tick ({@see startStorageSnapshotTimer()}). That is safe because a
     * run's shared `recorded_at` stamp
     * ({@see \Phlix\Stats\StatsCollector::snapshotRunSecond()}) expires after
     * seconds, while ticks are {@see self::STORAGE_SNAPSHOT_INTERVAL} (6 h) apart —
     * so every tick is its own generation, as the reader requires.
     *
     * It is also deliberately CONSTRUCTED there (`new StatsCollector($db)`) rather than
     * resolved from the container: php-di hands out ONE `StatsCollector` per container,
     * so a `$container->get(StatsCollector::class)` shared by two coroutines would merge
     * their runs into a single `recorded_at` generation, which the dashboard reader then
     * SUMS (measured 2× — S102 review r3, LOW-1a). Anything that moves this collection
     * off the timer and into a task must keep building its own collector.
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
            $buckets = \Phlix\Stats\StorageSnapshotHelper::collectBuckets($db);

            $collector->recordStorageSnapshots($buckets);

            $context = [];
            foreach ($buckets as $mediaType => $totals) {
                $context[$mediaType] = $totals['count'];
                $context[$mediaType . '_bytes'] = $totals['bytes'];
            }
            $logger->info('Storage snapshot recorded', $context);
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

        // `dlna.cds_enabled` — OFF by default, and deliberately so: DLNA/UPnP
        // has no authentication, so serving these endpoints lets ANY device on
        // the local network browse and stream the whole library without logging
        // in. Read per worker start, so it applies on a graceful reload.
        // {@see config/dlna.php} for the full warning.
        if ((\Phlix\Config\EffectiveConfig::file('dlna')['cds_enabled'] ?? false) !== true) {
            return;
        }

        try {
            $cdsServer = $this->container->get(\Phlix\Dlna\CdsServer::class);
            if (!$cdsServer instanceof \Phlix\Dlna\CdsServer) {
                return;
            }

            // DLNA/UPnP carries no credentials, so the ONLY thing gating these
            // browse endpoints is the inbound IP allowlist. Wrap the whole group
            // with it, mirroring exactly how loadStreamingRoutes() wraps its
            // group with SignedUrlMiddleware — attaching it to the GROUP (not
            // checking here) makes it re-evaluated PER REQUEST (class (a) LIVE);
            // this Application is built once per worker, so a check at load time
            // would only apply after a reload.
            $allowlistMiddleware = new \Phlix\Server\Http\Middleware\DlnaAllowlistMiddleware(
                $this->optionalSettingsRepository(),
            );

            // Narrow $this->container to non-null for the closure (the null case
            // returned at the top of this method).
            $container = $this->container;

            $this->router->group('', function (Router $r) use ($cdsServer, $container): void {
                // Device description endpoint (legacy path)
                $deviceDescController = new \Phlix\Server\Http\Controllers\Dlna\DeviceDescriptionController($cdsServer);
                $r->get('/description.xml', [$deviceDescController, 'handle']);

                // P10-S1: DLNA routes with /dlna/ prefix
                $r->get(\Phlix\Dlna\DlnaRoutes::DESCRIPTION, [$deviceDescController, 'handle']);

                // SCPD XML endpoints - route pattern matches /scpd/{service}.xml
                $r->get('/scpd/{service}.xml', function (
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
                    if ($container->has(\Phlix\Dlna\ContentDirectory::class)) {
                        $contentDirectory = $container->get(\Phlix\Dlna\ContentDirectory::class);
                        if ($contentDirectory instanceof \Phlix\Dlna\ContentDirectory) {
                            $cdsController = new \Phlix\Server\Http\Controllers\Dlna\DlnaContentDirectoryController(
                                $contentDirectory
                            );
                            $r->post(
                                \Phlix\Dlna\DlnaRoutes::CONTENT_DIRECTORY_CONTROL,
                                [$cdsController, 'handle'],
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    // ContentDirectory not available - skip SOAP route
                }

                // CDS control endpoint (legacy path)
                $cdsControlController = new \Phlix\Server\Http\Controllers\Dlna\CdsControlController($cdsServer);
                $r->post(\Phlix\Dlna\DlnaRoutes::CDS_CONTROL, [$cdsControlController, 'handle']);

                // S52: the media byte stream a renderer actually plays.
                //
                // MUST live inside THIS group. A DLNA renderer cannot present a
                // Bearer token or a signed URL, so the route carries no auth —
                // which makes the group's DlnaAllowlistMiddleware the only gate
                // it has. Serving it from HttpHandler instead (as
                // /media/{id}/stream is, before the router runs) would give it
                // ZERO allowlist enforcement: an unauthenticated whole-library
                // read for anything that can reach the port. Registering it here
                // also means the `dlna.cds_enabled` guard above keeps the route
                // from existing at all while DLNA is off.
                //
                // HEAD is registered explicitly alongside GET: renderers HEAD
                // before opening a resource, and Router::dispatch()'s GET→HEAD
                // fallback suppresses the file-backed body (so it would report
                // Content-Length: 0).
                try {
                    $streamItems = $container->get(\Phlix\Media\Library\ItemRepository::class);
                    $streamJail = $container->get(\Phlix\Media\Library\LibraryRootJail::class);
                    if (
                        $streamItems instanceof \Phlix\Media\Library\ItemRepository
                        && $streamJail instanceof \Phlix\Media\Library\LibraryRootJail
                    ) {
                        $streamController = new \Phlix\Server\Http\Controllers\Dlna\DlnaStreamController(
                            $streamItems,
                            $streamJail,
                            \Phlix\Common\Logger\LoggerFactory::get(\Phlix\Common\Logger\LogChannels::DLNA),
                        );
                        $r->match(
                            ['GET', 'HEAD'],
                            \Phlix\Dlna\DlnaRoutes::STREAM_PATTERN,
                            [$streamController, 'handle'],
                        );
                    }
                } catch (\Throwable $streamError) {
                    // Browse still works without it; say so instead of leaving an
                    // operator to wonder why every <res> URL 404s.
                    //
                    // The log call is itself guarded: it runs INSIDE a
                    // Router::group() closure, and a throw escaping from here
                    // would abandon the group. Router::group() now restores its
                    // prefix/middleware in a `finally`, so the allowlist can no
                    // longer leak onto the ~15 loaders that follow — this catch is
                    // the second half of that belt-and-braces pair, keeping a
                    // logging failure from taking down route registration at all.
                    try {
                        \Phlix\Common\Logger\LoggerFactory::get(\Phlix\Common\Logger\LogChannels::DLNA)->error(
                            'DLNA stream route not registered; renderers will be unable to play any item',
                            ['error' => $streamError->getMessage(), 'exception' => $streamError::class],
                        );
                    } catch (\Throwable) {
                        // Nowhere left to report to.
                    }
                }
            }, [$allowlistMiddleware]);
        } catch (\Throwable $e) {
            // LOG, do not swallow. This bare catch previously said only
            // "CDS not configured - silent ignore", and it hid a permanent
            // DI failure for months: DlnaServer had no registration, so every
            // resolution threw and NO DLNA route was ever registered, on any
            // install, while the SSDP advertiser kept telling the network the
            // server was browsable. An admin who has explicitly switched
            // `dlna.cds_enabled` ON must be told when it does not come up.
            \Phlix\Common\Logger\LoggerFactory::get(\Phlix\Common\Logger\LogChannels::DLNA)->error(
                'DLNA ContentDirectory is enabled but failed to start; browse endpoints are NOT registered',
                ['error' => $e->getMessage(), 'exception' => $e::class],
            );
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
            // Backs `casting.chromecast.enabled`. Appended to the group rather
            // than checked here, so it is re-evaluated PER REQUEST (class (a)
            // LIVE) — this Application is built once per worker, so a check at
            // this point would only apply after a reload.
            $castingMiddleware = new \Phlix\Server\Http\Middleware\CastingEnabledMiddleware(
                'chromecast',
                $this->optionalSettingsRepository(),
            );

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
            }, [$authMiddleware, $castingMiddleware]);
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
            // Backs `casting.roku.enabled`. See loadChromecastRoutes() for why
            // this is group middleware rather than a check here.
            $castingMiddleware = new \Phlix\Server\Http\Middleware\CastingEnabledMiddleware(
                'roku',
                $this->optionalSettingsRepository(),
            );

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
            }, [$authMiddleware, $castingMiddleware]);
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
            // Backs `casting.airplay.enabled`. See loadChromecastRoutes() for
            // why this is group middleware rather than a check here.
            $castingMiddleware = new \Phlix\Server\Http\Middleware\CastingEnabledMiddleware(
                'airplay',
                $this->optionalSettingsRepository(),
            );

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
            }, [$authMiddleware, $castingMiddleware]);
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
            $gaplessManager = new \Phlix\Media\Playback\GaplessPlaybackManager(null);
            $trickplayController = $this->getTrickplayController();
            $chapterMarkerService = new \Phlix\Media\MarkerService($db);
            return new \Phlix\Server\Http\Controllers\MediaItemController(
                $itemRepository,
                $markerService,
                $gaplessManager,
                $trickplayController,
                $chapterMarkerService
            );
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
        $ratingGate = null;
        try {
            /** @var \Phlix\Media\Library\RatingGate $ratingGate */
            $ratingGate = $this->container->get(\Phlix\Media\Library\RatingGate::class);
        } catch (\Throwable) {
            $ratingGate = null;
        }
        // S97: shuffle needs the `music_*` read path to turn an album/artist id
        // into playable TRACK ids — `media_items.parent_id` is never written for
        // music, so findByParent() cannot. Optional: a null instance keeps the
        // pre-S97 404 rather than returning unplayable container ids.
        $musicLibrary = null;
        try {
            /** @var \Phlix\Media\Music\MusicLibraryService $musicLibrary */
            $musicLibrary = $this->container->get(\Phlix\Media\Music\MusicLibraryService::class);
        } catch (\Throwable) {
            $musicLibrary = null;
        }
        return new \Phlix\Server\Http\Controllers\MediaItemController(
            $itemRepository,
            $markerService,
            $gaplessManager,
            $trickplayController,
            $chapterMarkerService,
            null,
            $ratingGate,
            $musicLibrary
        );
    }

    /**
     * Returns a SubtitleController instance (item repo + ffmpeg + extractor).
     *
     * @return \Phlix\Server\Http\Controllers\SubtitleController The controller instance.
     */
    private function getSubtitleController(): \Phlix\Server\Http\Controllers\SubtitleController
    {
        /** @var \Phlix\Media\Transcoding\FfmpegRunner */
        $ffmpeg = $this->container !== null
            ? $this->container->get(\Phlix\Media\Transcoding\FfmpegRunner::class)
            : new \Phlix\Media\Transcoding\FfmpegRunner(
                $this->configString($this->loadFfmpegConfig(), 'ffmpeg_path', '/usr/bin/ffmpeg'),
                $this->configString($this->loadFfmpegConfig(), 'ffprobe_path', '/usr/bin/ffprobe')
            );
        // SV-0.1: when using the container singleton, probe is called by the factory.
        // When falling back to direct creation (no container), skip the explicit call
        // since setConfig() already merges the hwaccel settings. The probe is guarded
        // by $hwaccelProbed anyway, but we avoid the redundancy either way.
        if ($this->container === null) {
            $ffmpeg->setConfig(\Phlix\Config\HwAccelConfig::get());
        }
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
     * Returns a RemoteSubtitleController for on-demand provider-plugin subtitle
     * search / download / serving (F3).
     *
     * Resolved from the container (all deps — SubtitleFetchService, ItemRepository,
     * SubtitleStorage — are wired in MediaServicesProvider). Falls back to a
     * hand-built instance with the default storage root only when no container is
     * present (mirrors {@see getSubtitleController()}'s container-less fallback).
     *
     * @return \Phlix\Server\Http\Controllers\RemoteSubtitleController
     */
    private function getRemoteSubtitleController(): \Phlix\Server\Http\Controllers\RemoteSubtitleController
    {
        if ($this->container !== null) {
            /** @var \Phlix\Server\Http\Controllers\RemoteSubtitleController */
            return $this->container->get(\Phlix\Server\Http\Controllers\RemoteSubtitleController::class);
        }

        $db = new \Phlix\Common\Database\PhlixMySQLConnection(
            '127.0.0.1',
            3306,
            'phlix',
            'root',
            'password'
        );
        $itemRepository = new \Phlix\Media\Library\ItemRepository($db);
        $storage = new \Phlix\Media\Subtitles\SubtitleStorage();
        $fetchService = new \Phlix\Media\Subtitles\SubtitleFetchService(
            new \Phlix\Media\Subtitles\SubtitleSourceRegistry(),
            $storage,
            new \Phlix\Media\Subtitles\Quota\SubtitleProviderQuotaRepository($db),
            $itemRepository,
        );

        return new \Phlix\Server\Http\Controllers\RemoteSubtitleController(
            $fetchService,
            $itemRepository,
            $storage,
        );
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
     * autowired; the admin middleware is a REQUIRED constructor dependency so both
     * endpoints are admin-gated exactly like the whole-library match endpoint.
     *
     * ## S323 — the admin gate is now a construction-time requirement
     *
     * The middleware used to be wired behind
     * `if ($container->has(AdminMiddleware::class))`, so a container that could not
     * supply it still yielded a working — but ungated — controller whose
     * `apply()` overwrites an item's metadata subtree. The guard was live but
     * always true; it is now removed, matching the shape S282 established on
     * `getLibraryController()`: a container that cannot build the middleware throws
     * at route-registration time (loud, at boot) instead of silently degrading the
     * gate.
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
        // NOT conditional on has(): the controller cannot exist without its gate.
        /** @var \Phlix\Server\Http\Middleware\AdminMiddleware */
        $adminMiddleware = $container->get(\Phlix\Server\Http\Middleware\AdminMiddleware::class);

        return new \Phlix\Server\Http\Controllers\MediaMatchController(
            $itemRepository,
            $matcher,
            $adminMiddleware
        );
    }

    /**
     * Returns a MediaPosterController instance (Step 15.1/15.2).
     *
     * ## S323 — the admin gate is now a construction-time requirement
     *
     * The middleware used to be wired behind
     * `if ($container->has(AdminMiddleware::class))`, so a container that could not
     * supply it still yielded a working — but ungated — controller whose
     * `setPoster()` rewrites `metadata.poster_url`. The guard was live but always
     * true; it is now removed, matching the shape S282 established on
     * `getLibraryController()`.
     *
     * ⚠ This is one of TWO construction sites for this controller. The other is
     * {@see \Phlix\Server\WebPortal\WebPortalRouter::registerRoutes()}, which
     * hand-builds it for the CGI dispatch path. Both pass the gate as a required
     * constructor argument; neither may go back to a setter.
     *
     * @return \Phlix\Server\Http\Controllers\MediaPosterController The controller instance.
     */
    private function getMediaPosterController(): \Phlix\Server\Http\Controllers\MediaPosterController
    {
        $container = $this->container
            ?? throw new \RuntimeException('Container required for MediaPosterController');

        /** @var \Phlix\Media\Library\ItemRepository */
        $itemRepository = $container->get(\Phlix\Media\Library\ItemRepository::class);

        // Resolve TmdbProvider through the container. Hand-building it here
        // read only config/tmdb.php / TMDB_API_KEY and so ignored the
        // admin-managed `server_settings` row `tmdb.api_key` entirely — with
        // TMDB_API_KEY unexported that meant a permanently empty key on the
        // poster endpoints registered from this controller. The container's
        // factory (MediaServicesProvider) applies the settings override.
        /** @var \Phlix\Media\Metadata\TmdbProvider */
        $tmdb = $container->get(\Phlix\Media\Metadata\TmdbProvider::class);

        // NOT conditional on has(): the controller cannot exist without its gate.
        /** @var \Phlix\Server\Http\Middleware\AdminMiddleware */
        $adminMiddleware = $container->get(\Phlix\Server\Http\Middleware\AdminMiddleware::class);

        return new \Phlix\Server\Http\Controllers\MediaPosterController(
            $itemRepository,
            $tmdb,
            $adminMiddleware
        );
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
     * TmdbProvider factory (which prefers the admin-managed `server_settings`
     * row `tmdb.api_key` and falls back to $appConfig['tmdb'] / the
     * TMDB_API_KEY environment variable — see MediaServicesProvider).
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
            // No container on this legacy branch, so build the settings store
            // from the local connection rather than reading config/env only —
            // the admin-managed `server_settings` override must still win.
            $tmdbApiKey = \Phlix\Media\Metadata\TmdbApiKeyResolver::resolve(
                new \Phlix\Admin\SettingsRepository($db)
            );
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
     * ## S282 — the admin gate is now a construction-time requirement
     *
     * This factory used to have two escape hatches, either of which produced a
     * controller whose `requireAdmin()` failed OPEN:
     *
     *  1. a container-less fallback that built the controller from a hardcoded
     *     `127.0.0.1/root/password` connection and never wired the middleware; and
     *  2. an `if ($this->container->has(AdminMiddleware::class))` guard, so a
     *     container that could not supply the middleware still yielded a working —
     *     but ungated — controller.
     *
     * Branch 1 was dead: `$this->container` is assigned exactly once, in the
     * constructor, from a NON-nullable `ContainerInterface` parameter, and nothing
     * else ever writes it. Branch 2 was live but always true. Both are now removed:
     * a missing container or a container that cannot build the middleware throws at
     * route-registration time (loud, at boot) instead of silently returning a
     * controller that lets any logged-in user run `delete-all`. This matches the
     * `?? throw new \RuntimeException('Container required for ...')` shape the
     * sibling factories in this class already use.
     *
     * @return \Phlix\Server\Http\Controllers\LibraryController The controller instance.
     */
    private function getLibraryController(): \Phlix\Server\Http\Controllers\LibraryController
    {
        $container = $this->container
            ?? throw new \RuntimeException('Container required for LibraryController');

        /** @var \Phlix\Media\Library\LibraryManager */
        $libraryManager = $container->get(\Phlix\Media\Library\LibraryManager::class);
        /** @var \Phlix\Media\Library\ScanJobRepository */
        $scanJobs = $container->get(\Phlix\Media\Library\ScanJobRepository::class);
        /** @var \Phlix\Media\Library\ItemRepository */
        $itemRepository = $container->get(\Phlix\Media\Library\ItemRepository::class);
        // NOT conditional on has(): the controller cannot exist without its gate.
        /** @var \Phlix\Server\Http\Middleware\AdminMiddleware */
        $adminMiddleware = $container->get(\Phlix\Server\Http\Middleware\AdminMiddleware::class);

        return new \Phlix\Server\Http\Controllers\LibraryController(
            $libraryManager,
            $scanJobs,
            $adminMiddleware,
            $itemRepository
        );
    }

    /**
     * Returns a ThemeMediaController instance.
     *
     * ## S323 — the admin gate is now a construction-time requirement
     *
     * This factory used to have two escape hatches, either of which produced a
     * controller whose `scanThemeMedia()`/`deleteThemeMedia()` failed OPEN to an
     * ANONYMOUS caller (the gate there is inline, with no auth check in front of
     * it):
     *
     *  1. a container-less fallback that built the controller from a hardcoded
     *     `127.0.0.1/root/password` connection and never wired the middleware; and
     *  2. an `if ($this->container->has(AdminMiddleware::class))` guard, so a
     *     container that could not supply the middleware still yielded a working —
     *     but ungated — controller.
     *
     * Branch 1 was dead: `$this->container` is assigned exactly once, in the
     * constructor, from a NON-nullable `ContainerInterface` parameter, and nothing
     * else ever writes it. Branch 2 was live but always true. Both are now removed,
     * matching the shape S282 established on `getLibraryController()`: a missing
     * container, or one that cannot build the middleware, throws at
     * route-registration time (loud, at boot) instead of silently serving the two
     * theme-media mutations to the world.
     *
     * @return \Phlix\Server\Http\Controllers\ThemeMediaController The controller instance.
     */
    private function getThemeMediaController(): \Phlix\Server\Http\Controllers\ThemeMediaController
    {
        $container = $this->container
            ?? throw new \RuntimeException('Container required for ThemeMediaController');

        /** @var \Phlix\Theming\ThemeMediaRepository */
        $themeMediaRepository = $container->get(\Phlix\Theming\ThemeMediaRepository::class);
        /** @var \Phlix\Theming\ThemeMediaFinder */
        $themeMediaFinder = $container->get(\Phlix\Theming\ThemeMediaFinder::class);
        /** @var \Phlix\Media\Library\LibraryManager */
        $libraryManager = $container->get(\Phlix\Media\Library\LibraryManager::class);
        // NOT conditional on has(): the controller cannot exist without its gate.
        /** @var \Phlix\Server\Http\Middleware\AdminMiddleware */
        $adminMiddleware = $container->get(\Phlix\Server\Http\Middleware\AdminMiddleware::class);

        return new \Phlix\Server\Http\Controllers\ThemeMediaController(
            $themeMediaRepository,
            $themeMediaFinder,
            $libraryManager,
            $adminMiddleware
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
        return new \Phlix\Server\Http\Controllers\HlsController(
            $hlsStreamer,
            $transcodeManager,
            $this->optionalRatingGate()
        );
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
        return new \Phlix\Server\Http\Controllers\TranscodeController(
            $transcodeManager,
            $this->optionalRatingGate()
        );
    }

    /**
     * Resolve the shared {@see \Phlix\Media\Library\RatingGate} from the container,
     * or null when it isn't wired (legacy/no-container/test contexts) — every gate
     * consumer treats a null gate as a strict no-op (owner-safe), so a missing gate
     * never blocks playback. Used to thread the parental ACCESS gate into the
     * transcode/HLS/DASH/book controllers whose byte/URL-minting paths must honour
     * a profile's content-rating cap.
     *
     * @return \Phlix\Media\Library\RatingGate|null
     */
    private function optionalRatingGate(): ?\Phlix\Media\Library\RatingGate
    {
        if ($this->container === null) {
            return null;
        }
        try {
            /** @var \Phlix\Media\Library\RatingGate $gate */
            $gate = $this->container->get(\Phlix\Media\Library\RatingGate::class);
            return $gate;
        } catch (\Throwable) {
            return null;
        }
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

        // S59: the TranscodeManager is now LOAD-BEARING, not merely the resolver
        // for the parental re-check — DashController::serveFile() routes every
        // `.m4s` request through ensureSegment() to produce it on demand. So it
        // is resolved UNCONDITIONALLY, exactly as getHlsController() resolves it,
        // rather than through a `has()` guard that could hand the controller a
        // silent null and turn the whole on-demand path into a no-op 404.
        //
        // That guard was already unreachable: loadStreamingRoutes() calls
        // getHlsController() on the line ABOVE this factory, and that one does an
        // unconditional `get()` — so a container without a TranscodeManager can
        // never reach here. Only the container-less legacy path can, and it
        // returns early below.
        $transcodeManager = null;
        if ($this->container !== null) {
            /** @var \Phlix\Media\Transcoding\TranscodeManager $transcodeManager */
            $transcodeManager = $this->container->get(\Phlix\Media\Transcoding\TranscodeManager::class);
        }

        return new \Phlix\Server\Http\Controllers\DashController(
            $segmentDir,
            $transcodeManager,
            $this->optionalRatingGate()
        );
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
     * S99: the controller reads the normalized `music_*` tables through
     * {@see \Phlix\Media\Music\MusicLibraryService}, so it no longer needs the
     * `MusicLibraryManager` / `LibraryManager` / `AudioScanner` /
     * `MetadataManager` graph this factory used to build — those fed the
     * `media_items.metadata_json` read path, which the music scanner never
     * populates. This is the ONLY construction site for MusicController
     * (`public/index.php` dispatches WebPortalRouter, not this router, and no DI
     * provider registers the class), so the two-argument signature is mirrored
     * here and nowhere else.
     *
     * @return \Phlix\Server\Http\Controllers\MusicController The controller instance.
     */
    private function getMusicController(): \Phlix\Server\Http\Controllers\MusicController
    {
        $db = $this->createDatabaseConnection();
        $musicScanner = new \Phlix\Media\Music\MusicLibraryScanner($db, new \Phlix\Media\Transcoding\FfmpegRunner());
        $musicLibraryService = new \Phlix\Media\Music\MusicLibraryService($db, $musicScanner);
        $sessionManager = new \Phlix\Session\SessionManager($db);

        return new \Phlix\Server\Http\Controllers\MusicController(
            $musicLibraryService,
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
        $musicScanner = new \Phlix\Media\Music\MusicLibraryScanner($db, new \Phlix\Media\Transcoding\FfmpegRunner());
        $musicLibraryService = new \Phlix\Media\Music\MusicLibraryService($db, $musicScanner);
        $libraryManager = new \Phlix\Media\Library\LibraryManager(
            $db,
            new \Phlix\Media\Library\MediaScanner(
                $db,
                $itemRepo
            ),
            new \Phlix\Media\Library\FolderWatcher(),
            $musicLibraryService
        );
        $opdsBuilder = new \Phlix\Media\Metadata\OpdsFeedBuilder($itemRepo, 'http://localhost:8080');

        return new \Phlix\Server\Http\Controllers\BookController(
            $itemRepo,
            $libraryManager,
            $opdsBuilder,
            $this->optionalRatingGate()
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
     * ## S323 — the admin gate is now a construction-time requirement
     *
     * This factory used to wire the middleware behind
     * `if ($this->container !== null && $this->container->has(AdminMiddleware::class))`,
     * i.e. TWO escape hatches in one condition, either of which produced a
     * controller whose five handlers failed OPEN to any logged-in user:
     *
     *  1. a null-container branch — dead, because `$this->container` is assigned
     *     exactly once, in the constructor, from a NON-nullable
     *     `ContainerInterface` parameter, and nothing else ever writes it; and
     *  2. a `has(AdminMiddleware::class)` guard — live, but always true.
     *
     * Both are now removed, matching the shape S282 established on
     * `getLibraryController()` and S323 phase 1 on `getThemeMediaController()`: a
     * missing container, or one that cannot build the middleware, throws at
     * route-registration time (loud, at boot) instead of silently serving the
     * webhook admin surface to every authenticated user.
     *
     * @return \Phlix\Server\Http\Controllers\Webhooks\WebhookAdminController The controller instance.
     */
    private function getWebhookAdminController(): \Phlix\Server\Http\Controllers\Webhooks\WebhookAdminController
    {
        $container = $this->container
            ?? throw new \RuntimeException('Container required for WebhookAdminController');

        $db = $this->createDatabaseConnection();
        $dispatcher = new \Phlix\Webhooks\WebhookDispatcher($db);
        // NOT conditional on has(): the controller cannot exist without its gate.
        /** @var \Phlix\Server\Http\Middleware\AdminMiddleware */
        $adminMiddleware = $container->get(\Phlix\Server\Http\Middleware\AdminMiddleware::class);

        return new \Phlix\Server\Http\Controllers\Webhooks\WebhookAdminController(
            $dispatcher,
            $adminMiddleware
        );
    }

    /**
     * Returns an Arr\SyncController instance.
     *
     * ## S323 — the admin gate is now a construction-time requirement
     *
     * The middleware used to be wired behind
     * `if ($this->container !== null && $this->container->has(AdminMiddleware::class))`,
     * i.e. TWO escape hatches in one condition, either of which produced a
     * controller whose three `/api/v1/admin/sync/*` handlers failed OPEN to any
     * logged-in user:
     *
     *  1. a null-container branch — dead, because `$this->container` is assigned
     *     exactly once, in the constructor, from a NON-nullable
     *     `ContainerInterface` parameter, and nothing else ever writes it; and
     *  2. a `has(AdminMiddleware::class)` guard — live, but always true.
     *
     * Both are now removed, matching the shape S282 established on
     * `getLibraryController()`: a missing container, or one that cannot build the
     * middleware, throws at route-registration time (loud, at boot) instead of
     * silently serving the sync surface to every authenticated user.
     *
     * @return \Phlix\Server\Http\Controllers\Arr\SyncController The controller instance.
     */
    private function getArrSyncController(): \Phlix\Server\Http\Controllers\Arr\SyncController
    {
        $container = $this->container
            ?? throw new \RuntimeException('Container required for Arr\SyncController');

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

        // NOT conditional on has(): the controller cannot exist without its gate.
        /** @var \Phlix\Server\Http\Middleware\AdminMiddleware */
        $adminMiddleware = $container->get(\Phlix\Server\Http\Middleware\AdminMiddleware::class);

        return new \Phlix\Server\Http\Controllers\Arr\SyncController(
            $syncer,
            $adminMiddleware
        );
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
