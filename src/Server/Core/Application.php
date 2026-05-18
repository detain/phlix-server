<?php

declare(strict_types=1);

namespace Phlex\Server\Core;

use Phlex\Common\Container\ContainerFactory;
use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Hub\HubClient;
use Phlex\Hub\HubApplication;
use Phlex\Hub\RelayApplication;
use Phlex\Discovery\DiscoveryServer;
use Phlex\Server\Http\Controllers\HubJwksController;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Server\Http\Router;
use Phlex\Theming\ThemeMiddleware;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Main application entry point for the Phlex Media Server.
 *
 * This class orchestrates HTTP request handling, middleware execution,
 * and route dispatching. It implements a singleton pattern to provide
 * global access to the application instance.
 *
 * @author Phlex Media Server Team
 * @version 1.0.0
 * @description Core application class that bootstraps the server, loads routes, and handles requests.
 * @see \Phlex\Server\Http\Router For route configuration
 * @see \Phlex\Server\Http\Request For request handling
 * @see \Phlex\Server\Http\Response For response generation
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
     * $app = Application::fromConfigPath('/etc/phlex/server.php');
     * $app->run();
     * ```
     */
    public static function fromConfigPath(string $configPath): self
    {
        if (!file_exists($configPath)) {
            throw new \RuntimeException("Configuration file not found: {$configPath}");
        }

        $config = include $configPath;

        if (!is_array($config)) {
            throw new \RuntimeException('Configuration file must return an array');
        }

        $container = ContainerFactory::create($config);
        return new self($container, $config);
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
            return (new Response())->json([
                'server' => $this->config['server']['name'] ?? 'Phlex Media Server',
                'version' => '1.0.0',
                'php_version' => PHP_VERSION,
                'workerman_version' => Workerman\Worker::VERSION,
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
     * Placeholder method for future API endpoint registration.
     * Override in subclasses to add additional API routes.
     *
     * @return void
     */
    private function loadApiRoutes(): void
    {
        // Placeholder for API routes - will be populated in later phases
        $this->router->get('/api/v1', function (Request $request): Response {
            return (new Response())->json([
                'api' => 'Phlex Media Server',
                'version' => 'v1',
                'endpoints' => '/health, /system/info',
            ]);
        });

        // Hub JWT exchange endpoint
        $this->router->post('/api/v1/auth/hub-token', function (Request $request, array $params): Response {
            $controller = $this->getHubTokenController();
            return $controller->handle($request, $params);
        });

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

        // Requests API endpoints
        $this->loadRequestsRoutes();
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

        $request = Request::fromGlobals();

        // Build the final handler that dispatches to the router
        $finalHandler = function (Request $request): Response {
            return $this->router->dispatch($request);
        };

        // Apply global middleware in reverse order (so first registered runs first)
        $handler = $finalHandler;
        for (end($this->middleware); key($this->middleware) !== null; prev($this->middleware)) {
            $currentHandler = $this->middleware[current($this->middleware)];
            $nextHandler = $handler;
            $handler = function (Request $request) use ($currentHandler, $nextHandler) {
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
     * Returns a HubJwksController instance from the container.
     *
     * @return HubJwksController The controller instance.
     */
    private function getHubJwksController(): HubJwksController
    {
        if ($this->container === null) {
            return new HubJwksController(
                new HubClient(
                    new \Phlex\Hub\Ed25519KeyManager('config/hub-server-key.pem'),
                    new \Phlex\Hub\HttpClient('https://hub.example.com'),
                    new \Phlex\Common\Logger\StructuredLogger('hub', []),
                    'config',
                ),
            );
        }

        /** @var HubJwksController */
        $controller = $this->container->get(HubJwksController::class);
        return $controller;
    }

    /**
     * Returns a HubTokenController instance from the container.
     *
     * @return \Phlex\Server\Http\Controllers\HubTokenController The controller instance.
     */
    private function getHubTokenController(): \Phlex\Server\Http\Controllers\HubTokenController
    {
        if ($this->container === null) {
            return new \Phlex\Server\Http\Controllers\HubTokenController(
                new \Phlex\Hub\HubJwtValidator(
                    'https://hub.example.com/.well-known/jwks.json',
                    new \Phlex\Hub\HttpClientFactory(),
                    new \Psr\Log\NullLogger(),
                    'test-server-id',
                ),
                new \Phlex\Auth\JwtHandler('fallback-secret-for-tests'),
            );
        }

        /** @var \Phlex\Server\Http\Controllers\HubTokenController */
        $controller = $this->container->get(\Phlex\Server\Http\Controllers\HubTokenController::class);
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
            $cdsServer = $this->container->get(\Phlex\Dlna\CdsServer::class);

            // Device description endpoint
            $deviceDescController = new \Phlex\Server\Http\Controllers\Dlna\DeviceDescriptionController($cdsServer);
            $this->router->get('/description.xml', [$deviceDescController, 'handle']);

            // CDS control endpoint
            $cdsControlController = new \Phlex\Server\Http\Controllers\Dlna\CdsControlController($cdsServer);
            $this->router->post('/cds/control', [$cdsControlController, 'handle']);

            // SCPD XML endpoints - route pattern matches /scpd/{service}.xml
            $this->router->get('/scpd/{service}.xml', function (\Phlex\Server\Http\Request $request, array $params) use ($cdsServer): \Phlex\Server\Http\Response {
                $service = $params['service'] ?? '';
                $scpdXml = $cdsServer->getScpdXml($service);

                if ($scpdXml === null) {
                    return (new \Phlex\Server\Http\Response())->status(404)->text('Service not found');
                }

                return (new \Phlex\Server\Http\Response())
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
            $playToManager = $this->container->get(\Phlex\Dlna\PlayToManager::class);
            $controller = new \Phlex\Server\Http\Controllers\Dlna\RendererListController($playToManager);

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
            $castManager = $this->container->get(\Phlex\Chromecast\CastManager::class);
            $controller = new \Phlex\Server\Http\Controllers\Chromecast\ChromecastController($castManager);

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
            $rokuManager = $this->container->get(\Phlex\Roku\RokuManager::class);
            $controller = new \Phlex\Server\Http\Controllers\Roku\RokuController($rokuManager);

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
            $airPlayManager = $this->container->get(\Phlex\AirPlay\AirPlayManager::class);
            $controller = new \Phlex\Server\Http\Controllers\AirPlay\AirPlayController($airPlayManager);

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
     * Loads Requests API routes.
     *
     * Registers endpoints for:
     * - GET /api/v1/requests — list user's requests
     * - POST /api/v1/requests — create a new request
     * - GET /api/v1/requests/{id} — get a single request
     * - PUT /api/v1/requests/{id}/approve — admin approve
     * - PUT /api/v1/requests/{id}/reject — admin reject
     * - DELETE /api/v1/requests/{id} — delete a request
     * - GET /api/v1/requests/pending — list all pending requests (admin)
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function loadRequestsRoutes(): void
    {
        if ($this->container === null) {
            return;
        }

        try {
            $db = \Phlex\Common\Database\ConnectionPool::getConnection('mysql');
            $arrClientFactory = new \Phlex\Arr\ArrClientFactory([]);
            $requestManager = new \Phlex\Requests\RequestManager($db, $arrClientFactory);
            $notification = new \Phlex\Requests\RequestNotification();
            $controller = new \Phlex\Server\Http\Controllers\Requests\RequestController(
                $requestManager,
                $notification
            );

            // List user's requests
            $this->router->get('/api/v1/requests', [$controller, 'listRequests']);

            // Create a new request
            $this->router->post('/api/v1/requests', [$controller, 'createRequest']);

            // Get a single request
            $this->router->get('/api/v1/requests/{id}', [$controller, 'getRequest']);

            // Admin approve request
            $this->router->put('/api/v1/requests/{id}/approve', [$controller, 'approveRequest']);

            // Admin reject request
            $this->router->put('/api/v1/requests/{id}/reject', [$controller, 'rejectRequest']);

            // Delete request
            $this->router->delete('/api/v1/requests/{id}', [$controller, 'deleteRequest']);

            // List all pending requests (admin)
            $this->router->get('/api/v1/requests/pending', [$controller, 'listPendingRequests']);
        } catch (\Throwable $e) {
            // Requests not configured - silent ignore
        }
    }

    /**
     * Returns a WebAuthnController instance.
     *
     * @return \Phlex\Server\Http\Controllers\WebAuthnController The controller instance.
     */
    private function getWebAuthnController(): \Phlex\Server\Http\Controllers\WebAuthnController
    {
        if ($this->container === null) {
            $db = new \Workerman\MySQL\Connection(
                '127.0.0.1',
                3306,
                'phlex',
                'root',
                'password'
            );
            $userRepo = new \Phlex\Auth\UserRepository($db);
            $credentialRepo = new \Phlex\Auth\WebAuthn\WebAuthnCredentialRepository($db);
            $settings = new \Phlex\Auth\WebAuthn\WebAuthnSettings(
                rpId: 'localhost',
                rpName: 'Phlex Media Server',
                rpOrigin: 'http://localhost:8080'
            );
            $webauthnManager = new \Phlex\Auth\WebAuthn\WebAuthnManager(
                $userRepo,
                $db,
                $credentialRepo,
                $settings
            );
            $authManager = new \Phlex\Auth\AuthManager($userRepo, new \Phlex\Auth\JwtHandler('test-secret'));
            return new \Phlex\Server\Http\Controllers\WebAuthnController($webauthnManager, $authManager);
        }

        /** @var \Phlex\Server\Http\Controllers\WebAuthnController */
        $controller = $this->container->get(\Phlex\Server\Http\Controllers\WebAuthnController::class);
        return $controller;
    }
}
