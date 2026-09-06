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
 * `GET /api/v1/media/{id}/playback-info`, `POST /api/v1/media/{id}/transcode`,
 * `GET /api/v1/transcode/{jobId}/status` and — since S423 —
 * `GET /api/v1/media/{id}/download` must not answer an ANONYMOUS caller.
 *
 * All of them were registered ungated on {@see Application}'s router
 * (`download` registered PUBLIC, the others overlooked).
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
        // S439: the container graph this test resolves constructs MediaAssetJobStore
        // and SimilarityJobStore through MediaServicesProvider's factories at the
        // production default queue paths, and their constructors mint the shared
        // /tmp directories. Sweep them so the suite leaves zero residue.
        foreach (['phlix_media_asset_jobs', 'phlix_similarity_jobs'] as $sharedQueue) {
            $sharedDir = sys_get_temp_dir() . '/' . $sharedQueue;
            if (is_dir($sharedDir)) {
                foreach (glob($sharedDir . '/*') ?: [] as $queued) {
                    @unlink($queued);
                }
                @rmdir($sharedDir);
            }
        }
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
     * The routes that must refuse an anonymous caller.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function gatedPlaybackRoutes(): array
    {
        return [
            'GET playback-info' => ['GET', '/api/v1/media/item-1/playback-info'],
            'POST transcode' => ['POST', '/api/v1/media/item-1/transcode'],
            'GET download' => ['GET', '/api/v1/media/item-1/download'],
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
            . 'playback plan / spawned an FFmpeg encode / minted a signed download URL for anyone who '
            . 'could reach the port.'
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
     * The deliberately-PUBLIC media routes stay public. Their comments in
     * `Application::loadApiRoutes()` mark them as such (trickplay + chapter
     * thumbnails are fetched by elements that cannot attach a Bearer header),
     * so over-gating them while closing the gated set would be its own
     * regression. `download` was the third member of this set until S423 moved
     * it behind `AuthMiddleware` — it now lives in {@see gatedRegisteredPaths()}
     * and {@see testAnonymousMediaDownloadIsRefusedByAuthMiddleware()}.
     *
     * @return array<string, array{0: string}>
     */
    public static function deliberatelyPublicMediaRoutes(): array
    {
        return [
            'trickplay' => ['/api/v1/media/{id}/trickplay'],
            'chapter thumbnail' => ['/api/v1/media/{id}/chapters/{index}/thumbnail'],
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
            'download' => ['GET', '/api/v1/media/{id}/download'],
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
     * S423 — `GET /api/v1/media/{id}/download` is refused by the ROUTER, before
     * the handler runs.
     *
     * The route was historically registered PUBLIC: since S235 an anonymous
     * refusal existed, but only as the handler's downstream fail-closed
     * RatingGate 404 ("Item not found") — a posture discoverable only by
     * reading the gate, and indistinguishable from a genuine item miss to any
     * caller who did not know the secret. The estate census of EVERY
     * /download consumer (ui / tizen / mobile / roku / console incl. the
     * shipped phar, plus hub, windows and contracts) found no anonymous
     * caller: ui routes through the shared authed ApiClient, console's
     * `downloadMedia()` is `authed()` in source and in the bundled phar, and
     * tizen/roku/windows/mobile never call it (mobile's offline path uses the
     * authenticated item-detail `stream_url`). So the deny moved to the
     * front. This is the planted-red proof: revert the route to the ungated
     * public registration and the middleware layer passes the request
     * through — this test turns red on the 401 assert (the caller then gets
     * the downstream gate's 404 instead, which is precisely the
     * filter-late posture this step replaced).
     */
    public function testAnonymousMediaDownloadIsRefusedByAuthMiddleware(): void
    {
        $router = $this->routerFromRealRegistration();

        $response = $router->dispatch($this->request('GET', '/api/v1/media/item-1/download'));

        $this->assertSame(
            401,
            $response->statusCode,
            'GET /api/v1/media/{id}/download must answer 401 from the auth middleware, not a '
            . 'downstream handler 404 — the deny is declared at the route, not hidden in the gate (S423).'
        );
        $this->assertStringContainsString('auth.required', $response->body);

        // Authenticated control: a request carrying a user id must pass the
        // gate (the handler 404s the fake item id on the mocked DB — out of
        // scope here; the point is the middleware did NOT reject it).
        $request = $this->request('GET', '/api/v1/media/item-1/download');
        $request->userId = 'owner-1';

        $this->assertNotSame(
            401,
            $router->dispatch($request)->statusCode,
            'The download gate must pass a request that carries a user id through to the handler.'
        );
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
