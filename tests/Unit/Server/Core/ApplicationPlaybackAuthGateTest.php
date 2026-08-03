<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * `GET /api/v1/media/{id}/playback-info`, `POST /api/v1/media/{id}/transcode` and
 * `GET /api/v1/transcode/{jobId}/status` must not answer an ANONYMOUS caller.
 *
 * All three were registered ungated on {@see Application}'s router.
 * {@see \Phlix\Server\Workerman\HttpHandler} (the Workerman daemon) is the ONLY
 * entry point that dispatches this router: it runs this router first and falls
 * through to {@see \Phlix\Server\WebPortal\WebPortalRouter} only when this router
 * answers 404. `public/index.php` (the CGI entry point) never dispatches this
 * router at all — it builds its own Router with only the admin group and sends
 * every other `/api/` path straight to WebPortalRouter (public/index.php:212-243).
 *
 * The WebPortalRouter fall-through is NOT an equivalent gated duplicate:
 *
 *  - `POST /api/v1/media/{id}/transcode` (WebPortalRouter.php:314) and
 *    `GET /api/v1/transcode/{jobId}/status` (:315) DO exist there inside an
 *    `AuthMiddleware` group — so their gated copies were shadowed by the ungated
 *    registrations here;
 *  - `GET /api/v1/media/{id}/playback-info` does NOT. WebPortalRouter registers
 *    `/api/v1/media/{id}/playback` (:305), a different path. So on the CGI entry
 *    point that route 404s outright, and the two entry points are NOT equivalent
 *    for it — only the Workerman daemon ever served it, ungated.
 *
 * Ungated, an anonymous request got:
 *
 *  - the complete playback plan for any item (container/codec, direct-play URL,
 *    audio + subtitle track list),
 *  - the ability to SPAWN a detached FFmpeg transcode and receive the signed HLS
 *    URLs for it — a resource-exhaustion vector, and
 *  - for any REAL job id, the signed `master_url` / `variants[].url` / subtitle
 *    URLs from `status()` (an anonymous probe with a MADE-UP job id looked gated,
 *    but that 401 was the fall-through answering after this router 404'd the
 *    unknown job — never a gate).
 *
 * The route table is the REAL one: `Application::loadApiRoutes()` is invoked
 * through reflection against the canonical provider stack with only the MySQL
 * {@see Connection} doubled, so this cannot pass by re-declaring the routes the
 * way the test wishes they were written.
 *
 */
final class ApplicationPlaybackAuthGateTest extends TestCase
{
    private string $tempDir = '';
    private string $loggerConfigPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        $this->tempDir = sys_get_temp_dir() . '/phlix_playback_gate_' . uniqid('', true);
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
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
        LoggerFactory::reset();

        $entries = glob($this->tempDir . '/*') ?: [];
        foreach ($entries as $entry) {
            @unlink($entry);
        }
        @rmdir($this->tempDir);

        parent::tearDown();
    }

    /**
     * The two routes that must refuse an anonymous caller.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function gatedPlaybackRoutes(): array
    {
        return [
            'GET playback-info' => ['GET', '/api/v1/media/item-1/playback-info'],
            'POST transcode' => ['POST', '/api/v1/media/item-1/transcode'],
        ];
    }

    /**
     * @dataProvider gatedPlaybackRoutes
     */
    public function testAnonymousRequestIsRejected(string $method, string $path): void
    {
        $response = $this->routerFromRealRegistration()->dispatch($this->request($method, $path));

        $this->assertSame(
            401,
            $response->statusCode,
            "{$method} {$path} must answer 401 to a request with no user — ungated it disclosed the "
            . 'playback plan / spawned an FFmpeg encode for anyone who could reach the port.'
        );
        $this->assertStringContainsString('auth.required', $response->body);
    }

    /**
     * The gate is the middleware, not the handler: with a user id present the
     * request is NOT short-circuited with a 401 (whatever the handler then decides,
     * including the deliberate parental 404, is out of scope here).
     */
    public function testAnAuthenticatedRequestIsNotRejectedByTheGate(): void
    {
        $router = $this->routerFromRealRegistration();

        $request = $this->request('POST', '/api/v1/media/item-1/transcode');
        $request->userId = 'owner-1';

        $this->assertNotSame(
            401,
            $router->dispatch($request)->statusCode,
            'The auth gate must pass a request that carries a user id through to the handler.'
        );
    }

    /**
     * The deliberately-PUBLIC media routes registered beside `playback-info` stay
     * public. Their comments in `Application::loadApiRoutes()` mark them as such
     * (trickplay + chapter thumbnails are fetched by elements that cannot attach a
     * Bearer header; `download` returns a signed URL), so over-gating them while
     * closing the two above would be its own regression.
     *
     * @return array<string, array{0: string}>
     */
    public static function deliberatelyPublicMediaRoutes(): array
    {
        return [
            'trickplay' => ['/api/v1/media/{id}/trickplay'],
            'chapter thumbnail' => ['/api/v1/media/{id}/chapters/{index}/thumbnail'],
            'download' => ['/api/v1/media/{id}/download'],
        ];
    }

    /**
     * @dataProvider deliberatelyPublicMediaRoutes
     */
    public function testDeliberatelyPublicMediaRoutesCarryNoAuthMiddleware(string $registeredPath): void
    {
        $middleware = $this->middlewareFor($this->routerFromRealRegistration(), 'GET', $registeredPath);

        $this->assertSame(
            [],
            array_filter(
                $middleware,
                static fn (callable $m): bool => $m instanceof \Phlix\Server\Http\Middleware\AuthMiddleware,
            ),
            "{$registeredPath} is marked public in Application::loadApiRoutes(); gating it would break "
            . 'the <img>/<video>-driven fetches that cannot send an Authorization header.'
        );
    }

    /**
     * The closed routes really are closed BY MIDDLEWARE on the real route entries
     * (the complement to the dispatch assertions above: it pins WHERE the 401 comes
     * from, so a handler-level check could not be mistaken for the gate).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function gatedRegisteredPaths(): array
    {
        return [
            'playback-info' => ['GET', '/api/v1/media/{id}/playback-info'],
            'transcode start' => ['POST', '/api/v1/media/{id}/transcode'],
            'transcode status' => ['GET', '/api/v1/transcode/{jobId}/status'],
        ];
    }

    /**
     * @dataProvider gatedRegisteredPaths
     */
    public function testGatedRoutesCarryAuthMiddleware(string $method, string $registeredPath): void
    {
        $middleware = $this->middlewareFor($this->routerFromRealRegistration(), $method, $registeredPath);

        $this->assertNotSame(
            [],
            array_filter(
                $middleware,
                static fn (callable $m): bool => $m instanceof \Phlix\Server\Http\Middleware\AuthMiddleware,
            ),
            "{$method} {$registeredPath} must carry AuthMiddleware on its route entry."
        );
    }

    /**
     * `/api/v1/transcode/{jobId}/status` is gated HERE, at dispatch.
     *
     * This is the one route whose hole was invisible to a naive probe: an anonymous
     * request carrying a MADE-UP job id always got a 401, but that 401 came from
     * HttpHandler falling through to WebPortalRouter's gated copy after this router
     * 404'd the unknown job. A REAL job id never 404s here, so it was answered 200
     * — signed `master_url`, `variants[].url` and subtitle URLs included
     * ({@see \Phlix\Server\Http\Controllers\TranscodeController::status()}, whose
     * parental branch is skipped for an anonymous caller because
     * `resolveRatingFilter()` returns null).
     *
     * Asserting the DISPATCH outcome (not just the route entry, which
     * `gatedRegisteredPaths()` covers) is what distinguishes a real gate from the
     * fall-through artefact: the 401 has to be produced by this router.
     */
    public function testTranscodeStatusIsGatedOnThisRouter(): void
    {
        $response = $this->routerFromRealRegistration()
            ->dispatch($this->request('GET', '/api/v1/transcode/job-1/status'));

        $this->assertSame(
            401,
            $response->statusCode,
            'GET /api/v1/transcode/{jobId}/status must answer 401 to a request with no user — ungated it '
            . 'handed a real job id\'s signed HLS master/variant/subtitle URLs to anyone who could reach the port.'
        );
        $this->assertStringContainsString('auth.required', $response->body);
    }

    /**
     * The middleware stack the router recorded for a registered route.
     *
     * Parametric routes are keyed by compiled regex, so the entry is located by its
     * stored literal `path` rather than by key.
     *
     * @return list<callable>
     */
    private function middlewareFor(Router $router, string $method, string $registeredPath): array
    {
        foreach ($router->getRoutes()[$method] ?? [] as $entry) {
            if (($entry['path'] ?? null) === $registeredPath) {
                return $entry['middleware'];
            }
        }

        self::fail("Route {$method} {$registeredPath} is not registered at all.");
    }

    /**
     * Run the REAL `Application::loadApiRoutes()` against a container built from the
     * canonical provider stack (only the MySQL connection is a double) and return
     * the router it populated.
     *
     * `Application::__construct()` is bypassed because it loads every other route
     * group too; only the four properties `loadApiRoutes()` reads are set.
     */
    private function routerFromRealRegistration(): Router
    {
        $connection = $this->createMock(Connection::class);
        $container = $this->containerWithMockedDb($connection);
        $router = new Router($container);

        // A few controller factories reached by loadApiRoutes() (e.g.
        // getTraktOAuthController) call $this->connectionPool->getPooledConnection()
        // DIRECTLY instead of going through the container, so the pool is doubled
        // too — otherwise the real one throws "no database config path" (or opens a
        // socket) during route registration.
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getPooledConnection')->willReturn($connection);

        $reflection = new ReflectionClass(Application::class);
        /** @var Application $app */
        $app = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('container')->setValue($app, $container);
        $reflection->getProperty('router')->setValue($app, $router);
        $reflection->getProperty('config')->setValue($app, []);
        $reflection->getProperty('connectionPool')->setValue($app, $pool);

        $reflection->getMethod('loadApiRoutes')->invoke($app);

        return $router;
    }

    /**
     * The canonical provider stack with the MySQL {@see Connection} rebound to a
     * double, so nothing here opens a socket (`createDatabaseConnection()` prefers
     * the container binding, which is this mock).
     */
    private function containerWithMockedDb(Connection $mockConnection): ContainerInterface
    {
        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($mockConnection) implements ServiceProviderInterface {
            public function __construct(private Connection $connection)
            {
            }

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

    private function request(string $method, string $path): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = $path;

        return $request;
    }
}
