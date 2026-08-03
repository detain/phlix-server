<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use DI\ContainerBuilder;
use Phlix\Auth\AuthManager;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Server\Http\Controllers\ThemesController;
use Phlix\Server\Http\Middleware\AuthMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Phlix\Theming\ThemeSourceInterface;
use Phlix\Theming\ThemeSourceRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * REACHABILITY guard for the S85 theme endpoints.
 *
 * ## Why a dedicated file
 *
 * A controller can be correct, tested and container-bound and still be dead.
 * That has already happened twice in this repository: S94's corrected music
 * query sat behind a `WebPortalRouter` method that `Application` shadowed, and
 * `tests/Unit/Server/Http/RouterMediaRoutesTest.php` asserted music routes
 * against {@see Router::music()} — a registrar with **zero** production callers.
 * Both suites were green the whole time. So this file asserts the registrar
 * production actually runs, asserts the HANDLER as well as the path, and drives
 * real requests through `dispatch()`.
 *
 * ## Why `WebPortalRouter` is the correct registrar for `/api/v1/themes`
 *
 * phlix-server has two HTTP entry points and they reach the two routers
 * differently:
 *
 *  - **CGI / FPM (`public/index.php`)** — `/api/v1/admin/*` goes to a local
 *    `Router` carrying {@see \Phlix\Server\Http\Routes\AdminRoutes}; **every
 *    other `/api/` path goes straight to {@see WebPortalRouter}**. `Application`'s
 *    router is never consulted on this path at all.
 *  - **Workerman daemon ({@see \Phlix\Server\Workerman\HttpHandler})** — tries
 *    `Application::dispatch()` first and falls through to
 *    {@see WebPortalRouter} for any `/api/` request that 404s.
 *
 * So `WebPortalRouter` is the one registrar BOTH entry points dispatch `/api/*`
 * to, and a registration there is served by both. A registration on
 * `Application` alone would work in the daemon and 404 under CGI/FPM.
 * {@see testBothEntryPointsRouteApiRequestsToTheWebPortalRouter()} pins that
 * premise so this reasoning cannot rot silently.
 *
 */
final class ThemeEndpointsReachabilityTest extends TestCase
{
    /** @var string Path to an isolated logger config that writes to a temp dir. */
    private string $loggerConfigPath = '';

    /** @var string Temp directory backing $loggerConfigPath. */
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        $this->tempDir = sys_get_temp_dir() . '/phlix_s85_reach_' . uniqid('', true);
        mkdir($this->tempDir, 0775, true);

        $this->loggerConfigPath = $this->tempDir . '/logger.php';
        file_put_contents(
            $this->loggerConfigPath,
            "<?php\nreturn [\n"
            . "    'default' => 'file',\n"
            . "    'handlers' => [\n"
            . "        'file' => [\n"
            . "            'type' => 'stream',\n"
            . "            'path' => " . var_export($this->tempDir . '/app.log', true) . ",\n"
            . "            'level' => 'debug',\n"
            . "        ],\n"
            . "    ],\n"
            . "];\n"
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        LoggerFactory::reset();

        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * A registry holding one plugin-contributed theme.
     */
    private function registryWithPluginTheme(): ThemeSourceRegistry
    {
        $registry = new ThemeSourceRegistry();
        $registry->register(new class implements ThemeSourceInterface {
            public function themeSourceName(): string
            {
                return 'acme-themes';
            }

            /**
             * @return list<array<array-key, mixed>>
             */
            public function providedThemes(): array
            {
                return [[
                    'id' => 'acme-noir',
                    'name' => 'Acme Noir',
                    'dark' => true,
                    'extends' => 'nocturne',
                    'tokens' => ['--bg' => '#08070a', '--accent' => '#78beff'],
                ]];
            }
        });

        return $registry;
    }

    /**
     * A `WebPortalRouter` built exactly the way the production factory builds
     * it as far as theming is concerned: real {@see ThemesController} over a
     * real {@see ThemeSourceRegistry}.
     */
    private function router(?ThemesController $themesController): WebPortalRouter
    {
        return new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $this->createMock(ItemRepository::class),
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $themesController,
        );
    }

    private function wiredRouter(): WebPortalRouter
    {
        return $this->router(new ThemesController($this->registryWithPluginTheme()));
    }

    private function request(string $path, ?string $userId = 'u1'): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = $path;
        $request->userId = $userId;

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The route table of the `Router` instance `WebPortalRouter::dispatch()`
     * delegates to — the production one, not a fresh one built by the test.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function routeTable(WebPortalRouter $webPortalRouter): array
    {
        $property = (new ReflectionClass($webPortalRouter))->getProperty('router');
        $property->setAccessible(true);
        $router = $property->getValue($webPortalRouter);
        $this->assertInstanceOf(Router::class, $router);

        /** @var array<string, array<string, array<string, mixed>>> $routes */
        $routes = $router->getRoutes();

        return $routes;
    }

    // -----------------------------------------------------------------
    // 1. The registration exists, on the registrar production runs
    // -----------------------------------------------------------------

    public function testTheListRouteIsRegisteredAndResolvesToTheThemesHandler(): void
    {
        $routes = $this->routeTable($this->wiredRouter());

        $this->assertArrayHasKey('GET', $routes);
        $this->assertArrayHasKey(
            '/api/v1/themes',
            $routes['GET'],
            'WebPortalRouter::registerRoutes() must register GET /api/v1/themes — without it the '
            . 'endpoint 404s under BOTH entry points',
        );

        $handler = $routes['GET']['/api/v1/themes']['handler'];
        $this->assertIsArray($handler, 'the route must be a [target, method] pair, not a closure');
        $this->assertInstanceOf(WebPortalRouter::class, $handler[0]);
        $this->assertSame(
            'listThemes',
            $handler[1],
            'GET /api/v1/themes must be served by WebPortalRouter::listThemes, which is the only '
            . 'method that delegates to ThemesController::index',
        );
    }

    public function testTheDetailRouteIsRegisteredAndResolvesToTheThemesHandler(): void
    {
        $routes = $this->routeTable($this->wiredRouter());

        $pattern = '#^/api/v1/themes/(?P<id>[^/]+)$#';
        $this->assertArrayHasKey('GET', $routes);
        $this->assertArrayHasKey(
            $pattern,
            $routes['GET'],
            'WebPortalRouter::registerRoutes() must register GET /api/v1/themes/{id}',
        );

        $handler = $routes['GET'][$pattern]['handler'];
        $this->assertIsArray($handler);
        $this->assertInstanceOf(WebPortalRouter::class, $handler[0]);
        $this->assertSame(
            'getTheme',
            $handler[1],
            'GET /api/v1/themes/{id} must be served by WebPortalRouter::getTheme, which is the only '
            . 'method that delegates to ThemesController::show',
        );
    }

    /**
     * Both routes must stay inside the `AuthMiddleware` group. Moving either out
     * would publish the installed-theme-plugin list to anonymous callers, which
     * is a plugin-fingerprinting aid.
     */
    public function testBothThemeRoutesCarryTheAuthMiddleware(): void
    {
        $routes = $this->routeTable($this->wiredRouter());

        foreach (['/api/v1/themes', '#^/api/v1/themes/(?P<id>[^/]+)$#'] as $key) {
            $middleware = $routes['GET'][$key]['middleware'];
            $this->assertIsArray($middleware);

            $classes = array_map(
                static fn (object $m): string => $m::class,
                array_filter($middleware, static fn (mixed $m): bool => is_object($m)),
            );
            $this->assertContains(
                AuthMiddleware::class,
                $classes,
                "route {$key} must stay inside the AuthMiddleware group",
            );
        }
    }

    // -----------------------------------------------------------------
    // 2. Real dispatch through the method both entry points call
    // -----------------------------------------------------------------

    public function testDispatchingTheListPathServesBuiltInAndPluginThemes(): void
    {
        $response = $this->wiredRouter()->dispatch($this->request('/api/v1/themes'));

        $this->assertSame(200, $response->statusCode);

        $themes = $this->body($response)['themes'] ?? null;
        $this->assertIsArray($themes);

        $ids = array_column($themes, 'id');
        $this->assertSame(
            ['nocturne', 'daylight', 'midnight', 'acme-noir'],
            $ids,
            'the served list must carry the built-ins AND the plugin-registered theme',
        );

        // ...with their token maps, which is the acceptance criterion.
        $this->assertIsArray($themes[0]['tokens']);
        $this->assertCount(53, $themes[0]['tokens']);
        $this->assertSame('#0b0a08', $themes[0]['tokens']['--bg']);
        $this->assertTrue($themes[0]['builtIn']);

        $this->assertSame(['--bg' => '#08070a', '--accent' => '#78beff'], $themes[3]['tokens']);
        $this->assertSame('acme-themes', $themes[3]['source']);
        $this->assertFalse($themes[3]['builtIn']);
    }

    public function testDispatchingTheDetailPathServesABuiltInById(): void
    {
        $response = $this->wiredRouter()->dispatch($this->request('/api/v1/themes/midnight'));

        $this->assertSame(200, $response->statusCode);

        $theme = $this->body($response)['theme'] ?? null;
        $this->assertIsArray($theme);
        $this->assertSame('midnight', $theme['id']);
        $this->assertTrue($theme['builtIn']);
        $this->assertIsArray($theme['tokens']);
        $this->assertSame('#000000', $theme['tokens']['--bg']);
    }

    public function testDispatchingTheDetailPathServesAPluginThemeById(): void
    {
        $response = $this->wiredRouter()->dispatch($this->request('/api/v1/themes/acme-noir'));

        $this->assertSame(200, $response->statusCode);

        $theme = $this->body($response)['theme'] ?? null;
        $this->assertIsArray($theme);
        $this->assertSame('acme-noir', $theme['id']);
        $this->assertSame('acme-themes', $theme['source']);
        $this->assertFalse($theme['builtIn']);
        $this->assertSame(['--bg' => '#08070a', '--accent' => '#78beff'], $theme['tokens']);
    }

    public function testDispatchingAnUnknownThemeIdReturnsTheStandard404Envelope(): void
    {
        $response = $this->wiredRouter()->dispatch($this->request('/api/v1/themes/does-not-exist'));

        $this->assertSame(404, $response->statusCode);
        $this->assertSame(['error' => 'Theme not found'], $this->body($response));
    }

    /**
     * The auth gate is live on the dispatch path, not merely present in the
     * route table.
     */
    public function testAnAnonymousRequestIsRejectedBeforeTheHandlerRuns(): void
    {
        foreach (['/api/v1/themes', '/api/v1/themes/nocturne'] as $path) {
            $response = $this->wiredRouter()->dispatch($this->request($path, null));

            $this->assertSame(401, $response->statusCode, "{$path} must require a signed-in user");
            $this->assertSame('auth.required', $this->body($response)['code'] ?? null);
        }
    }

    /**
     * An unwired controller answers 503, loudly. An empty `themes` list would
     * read as "no plugin themes installed" and could hide a broken container
     * wiring for the life of a release.
     */
    public function testAnUnwiredThemingControllerAnswers503RatherThanAnEmptyCatalogue(): void
    {
        $router = $this->router(null);

        foreach (['/api/v1/themes', '/api/v1/themes/nocturne'] as $path) {
            $response = $router->dispatch($this->request($path));

            $this->assertSame(503, $response->statusCode);
            $this->assertSame(['error' => 'Theming is not configured'], $this->body($response));
        }
    }

    /**
     * `/api/v1/themes` is a literal segment and must never be swallowed by a
     * `{id}`-shaped sibling, nor swallow one.
     */
    public function testTheListPathIsNotSwallowedByASiblingParametricRoute(): void
    {
        $router = $this->wiredRouter();

        $list = $router->dispatch($this->request('/api/v1/themes'));
        $this->assertArrayHasKey('themes', $this->body($list));
        $this->assertArrayNotHasKey('theme', $this->body($list));

        $detail = $router->dispatch($this->request('/api/v1/themes/nocturne'));
        $this->assertArrayHasKey('theme', $this->body($detail));
        $this->assertArrayNotHasKey('themes', $this->body($detail));
    }

    // -----------------------------------------------------------------
    // 3. The premise: both entry points reach this registrar
    // -----------------------------------------------------------------

    /**
     * Pins the dual-entry-point premise this whole design rests on.
     *
     * Asserted against source, deliberately: neither entry point can be booted
     * inside the unit suite (one is a CGI script that calls `send()`/`exit`, the
     * other needs a live Workerman connection), and the failure being guarded is
     * "someone rerouted `/api/` away from WebPortalRouter", which no runtime
     * test in this suite can observe. Both halves are checked — a gate that
     * covered only one of the two entry points would be exactly the
     * "green build is not a link check" trap.
     */
    public function testBothEntryPointsRouteApiRequestsToTheWebPortalRouter(): void
    {
        $root = dirname(__DIR__, 4);

        $cgi = file_get_contents($root . '/public/index.php');
        $this->assertIsString($cgi);
        $this->assertMatchesRegularExpression(
            "/str_starts_with\(\\\$path,\s*'\/api\/'\)/",
            $cgi,
            'public/index.php must still branch on the /api/ prefix',
        );
        $this->assertMatchesRegularExpression(
            '/\$webPortalRouter->dispatch\(\$request\)/',
            $cgi,
            'public/index.php must dispatch /api/ requests to WebPortalRouter — a theme route '
            . 'registered there would otherwise 404 under CGI/FPM',
        );

        $daemon = file_get_contents($root . '/src/Server/Workerman/HttpHandler.php');
        $this->assertIsString($daemon);
        $this->assertMatchesRegularExpression(
            "/str_starts_with\(\\\$request->path,\s*'\/api\/'\)/",
            $daemon,
            'HttpHandler must still branch on the /api/ prefix',
        );
        $this->assertMatchesRegularExpression(
            '/\$webPortalRouter->dispatch\(\$request\)/',
            $daemon,
            'HttpHandler must fall through /api/ requests to WebPortalRouter — a theme route '
            . 'registered there would otherwise 404 in the Workerman daemon',
        );
    }

    // -----------------------------------------------------------------
    // 4. The production container really wires it
    // -----------------------------------------------------------------

    /**
     * Resolving `WebPortalRouter` from the canonical production container must
     * yield a router whose `ThemesController` reads the container's OWN
     * `ThemeSourceRegistry` — the very instance
     * {@see \Phlix\Plugins\PluginLoader} registers plugin themes into.
     *
     * Two separate silent-degradation modes are closed here. A `null`
     * controller would answer 503 forever (loud, but only in production), and a
     * controller holding a freshly-constructed registry would answer "no plugin
     * themes" forever while looking perfectly healthy — that second one is the
     * dangerous shape, and identity is the only assertion that catches it.
     */
    public function testTheProductionContainerWiresTheThemesControllerToTheSharedRegistry(): void
    {
        $container = $this->containerWithMockedDb();

        $router = $container->get(WebPortalRouter::class);
        $this->assertInstanceOf(WebPortalRouter::class, $router);

        $controller = $this->readPrivate($router, 'themesController');
        $this->assertInstanceOf(
            ThemesController::class,
            $controller,
            'WebPortalServicesProvider must pass a ThemesController; a null one makes both theme '
            . 'endpoints answer 503 in production.',
        );

        $registry = $this->readPrivate($controller, 'registry');
        $this->assertSame(
            $container->get(ThemeSourceRegistry::class),
            $registry,
            'The controller must hold the CONTAINER-SCOPED ThemeSourceRegistry. A separate instance '
            . 'would silently serve an empty plugin-theme list for the life of the worker.',
        );
    }

    /**
     * Build the canonical production container with the MySQL connection
     * rebound to a mock, mirroring
     * {@see \Phlix\Tests\Unit\Playlists\SmartPlaylistRefreshWiringTest}.
     */
    private function containerWithMockedDb(): ContainerInterface
    {
        $mockConnection = $this->createMock(Connection::class);

        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($mockConnection) implements ServiceProviderInterface {
            public function __construct(private Connection $connection)
            {
            }

            /**
             * @param ContainerBuilder<\DI\Container> $builder
             * @param array<string, mixed>            $appConfig
             */
            public function register(ContainerBuilder $builder, array $appConfig): void
            {
                $connection = $this->connection;
                $builder->addDefinitions([
                    Connection::class => factory(static fn (): Connection => $connection),
                ]);
            }
        };

        return ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ], $providers);
    }

    private function readPrivate(object $target, string $property): mixed
    {
        $prop = (new ReflectionClass($target))->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($target);
    }
}
