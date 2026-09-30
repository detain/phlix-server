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
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * L-1 + L-2 (security audit 2026-09-30) — posture guard: `GET /api/v1/servers`
 * and `GET /admin/health/jobs` are authenticated on the PRODUCTION router, and
 * the unenrolled `/api/v1/servers` fallback id is STABLE per process.
 *
 * ## The defects this pins shut
 *
 * - **L-2:** `/admin/health/jobs` lived under the `/admin` prefix yet was
 *   registered bare — every running scan/transcode job name, path, PID and
 *   worker id was world-readable. Estate sweep found ZERO anonymous consumers
 *   (infra probes target `/health`; the hub never proxies this path), so the
 *   fix is the gate itself — parity with the sibling `/api/v1/health/network`
 *   precedent (S437, {@see HealthRoutesAuthGuardTest}).
 * - **L-1:** `GET /api/v1/servers` leaked server identity (name, hub-derived
 *   public URL, enrollment id) to anonymous callers. The one real consumer —
 *   `phlix-ui` `AdminServersApi.listServers()` — always sends a Bearer token,
 *   and the hub-relay path sets a non-empty sentinel userId before dispatch,
 *   so gating breaks no live wire. The same closure also minted a FRESH
 *   `Uuid::v4()` on every unenrolled request: the "server id" reshuffled per
 *   poll, so any client keying state on it thrashed. The fallback id is now
 *   memoised for the process lifetime.
 *
 * ## Why the assertions are shaped this way
 *
 * - **The route table is the PRODUCTION one.** Reflected off an `Application`
 *   built from {@see ContainerFactory::defaultProviders()} with only MySQL
 *   doubled — a test that registers its own route cannot observe the
 *   production registration being ungated (the S31/S36 lesson).
 * - **The 401 has a readable control.** `GET /health` (the deliberately-open
 *   liveness route infra probes hit) returns 200 on the same chain, so
 *   "the whole app answers 401" can never explain these two 401s.
 * - **The id-stability leg calls the ROUTE HANDLER directly** (twice, same
 *   `Application`): the authenticated full-chain dispatch is unavailable in
 *   this harness because the global `AccessScheduleMiddleware` 403s an authed
 *   request whose DB profile cannot be resolved (same limit S437 records).
 *   The handler closure is exactly what the router invokes post-middleware,
 *   and the memo lives in its `use (&$resolvedServerId)` capture — the unit
 *   of behaviour the fix owns. Pre-fix, two calls red on inequality.
 *
 * ## Planted-drift proof
 *
 * Revert either route to a bare `$router->get()` (drop the group): the posture
 * test reds on the middleware reflection and the anonymous-dispatch tests red
 * on the 401; remove the memo (`$serverId = Uuid::v4()` inline) and the
 * stability leg reds on inequality. Restore → green.
 */
final class ServersAndJobsAuthGateTest extends TestCase
{
    private const SERVERS_PATH = '/api/v1/servers';
    private const JOBS_PATH = '/admin/health/jobs';
    private const LIVENESS_PATH = '/health';

    // Merge-lane canary (P-1). Lives in the comment-stripped PHP corpus via the
    // executing assertion below, never in a *.md file. Not a security assertion.
    private const LANE_SENTINEL = 'L1L2SERVERSJOBX4K7';

    private string $tempDir = '';
    private string $loggerConfigPath = '';
    private ?ContainerInterface $sharedContainer = null;
    private ?Application $sharedApplication = null;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        // Global AccessScheduleMiddleware reads process-static RequestContext; a
        // leaked user id would flip every dispatch below. Cleared both ends.
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        $this->tempDir = sys_get_temp_dir() . '/phlix_l1l2_' . uniqid('', true);
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
        // NOTE: deliberately NO hub-enrollment.json in $tempDir — the servers
        // handler must take the UNENROLLED fallback-id branch the memo fixes.
    }

    protected function tearDown(): void
    {
        // Container graph resolution constructs MediaAssetJobStore /
        // SimilarityJobStore through MediaServicesProvider factories, which mint
        // the shared /tmp queue dirs. Sweep so the suite leaves zero residue.
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

        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Production harness (mirrors HealthRoutesAuthGuardTest / S437)
    // -----------------------------------------------------------------

    private function container(): ContainerInterface
    {
        if ($this->sharedContainer !== null) {
            return $this->sharedContainer;
        }

        $connection = $this->createMock(Connection::class);

        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($connection) implements ServiceProviderInterface {
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

        return $this->sharedContainer = ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ], $providers);
    }

    private function application(): Application
    {
        if ($this->sharedApplication !== null) {
            return $this->sharedApplication;
        }

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getPooledConnection')->willReturn($this->createMock(Connection::class));

        $config = ['hub' => ['config_dir' => $this->tempDir]];

        return $this->sharedApplication = new Application($this->container(), $config, $pool);
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function pathIndex(): array
    {
        $property = (new ReflectionClass(Application::class))->getProperty('router');
        $property->setAccessible(true);
        $router = $property->getValue($this->application());

        $this->assertInstanceOf(
            Router::class,
            $router,
            'ANTI-VACUITY: Application::$router is not a Router; the posture assertions '
            . 'would silently read an empty table.'
        );

        $index = [];
        /** @var array<string, array<string, array<string, mixed>>> $routes */
        $routes = $router->getRoutes();
        foreach ($routes as $method => $entries) {
            foreach ($entries as $entry) {
                $path = $entry['path'] ?? null;
                $this->assertIsString($path);
                $index[$method][$path] = $entry;
            }
        }

        return $index;
    }

    /**
     * @return list<string>
     */
    private function middlewareNames(string $method, string $path): array
    {
        $entry = $this->pathIndex()[$method][$path] ?? null;
        $this->assertIsArray($entry, "{$method} {$path} is not registered on the production router.");

        $middleware = $entry['middleware'] ?? null;
        $this->assertIsArray($middleware, "{$method} {$path} carries no middleware array.");

        $names = [];
        foreach ($middleware as $item) {
            $this->assertIsObject($item);
            $position = strrpos($item::class, '\\');
            $names[] = $position === false ? $item::class : substr($item::class, $position + 1);
        }

        return $names;
    }

    private function request(string $path): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = $path;
        $request->remoteIp = '127.0.0.1';

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $decoded = json_decode((string) $response->body, true);
        $this->assertIsArray($decoded, 'response body must be a JSON object');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    // -----------------------------------------------------------------
    // 1. Posture: the PRODUCTION registrations carry AuthMiddleware
    // -----------------------------------------------------------------

    public function testServersRouteIsAuthGatedInProductionRouter(): void
    {
        $this->assertSame(
            ['AuthMiddleware'],
            $this->middlewareNames('GET', self::SERVERS_PATH),
            'GET ' . self::SERVERS_PATH . ' must stay gated by exactly AuthMiddleware (L-1). '
            . 'An empty stack here means the route was re-registered bare.'
        );
    }

    public function testJobsRouteIsAuthGatedInProductionRouter(): void
    {
        $this->assertSame(
            ['AuthMiddleware'],
            $this->middlewareNames('GET', self::JOBS_PATH),
            'GET ' . self::JOBS_PATH . ' must stay gated by exactly AuthMiddleware (L-2): it sits '
            . 'under the /admin prefix and publishes job names/paths/PIDs. Zero anonymous consumers '
            . 'exist; re-gating it bare reopens that disclosure.'
        );
    }

    // -----------------------------------------------------------------
    // 2. Anonymous full-chain dispatch → 401 {error, code}, nothing else
    // -----------------------------------------------------------------

    public function testAnonymousServersDispatchIsRejectedWithZeroDisclosure(): void
    {
        $served = $this->application()->dispatch($this->request(self::SERVERS_PATH));

        $this->assertSame(401, $served->statusCode, 'Anonymous GET ' . self::SERVERS_PATH . ' must be refused (L-1).');

        $body = $this->body($served);
        $this->assertSame(['error', 'code'], array_keys($body));
        $this->assertSame('auth.required', $body['code'] ?? null);
        // No identity fields of the servers payload leak pre-auth.
        $this->assertStringNotContainsString('hostname_candidates', (string) $served->body);
        $this->assertStringNotContainsString('Phlix Media Server', (string) $served->body);
    }

    public function testAnonymousJobsDispatchIsRejectedWithZeroDisclosure(): void
    {
        $served = $this->application()->dispatch($this->request(self::JOBS_PATH));

        $this->assertSame(401, $served->statusCode, 'Anonymous GET ' . self::JOBS_PATH . ' must be refused (L-2).');

        $body = $this->body($served);
        $this->assertSame(['error', 'code'], array_keys($body));
        $this->assertSame('auth.required', $body['code'] ?? null);
        $this->assertStringNotContainsString('transcode_jobs', (string) $served->body);
        $this->assertStringNotContainsString('oldest_started_at', (string) $served->body);
    }

    // -----------------------------------------------------------------
    // 3. The readable control: the deliberately-open liveness route stays 200.
    // -----------------------------------------------------------------

    public function testUnauthenticatedLivenessRouteStaysOpen(): void
    {
        $served = $this->application()->dispatch($this->request(self::LIVENESS_PATH));

        $this->assertSame(
            200,
            $served->statusCode,
            'GET ' . self::LIVENESS_PATH . ' is the non-revealing infra liveness probe and MUST stay '
            . 'unauthenticated; gating it would break docker/k8s healthchecks.'
        );
        $this->assertSame('ok', $this->body($served)['status'] ?? null);
    }

    // -----------------------------------------------------------------
    // 4. L-1 id-stability: unenrolled fallback id is memoised per process.
    //    Handler invoked directly (see class docblock for why not dispatch).
    // -----------------------------------------------------------------

    public function testUnenrolledServersIdIsStableAcrossRequests(): void
    {
        $handler = $this->pathIndex()['GET'][self::SERVERS_PATH]['handler'] ?? null;
        $this->assertIsCallable($handler, 'route handler missing from the production table');

        $first = $this->body($handler($this->request(self::SERVERS_PATH)));
        $second = $this->body($handler($this->request(self::SERVERS_PATH)));

        $idFirst = $first['data'][0]['id'] ?? null;
        $idSecond = $second['data'][0]['id'] ?? null;

        $this->assertIsString($idFirst);
        $this->assertNotSame('', $idFirst);
        $this->assertSame(
            $idFirst,
            $idSecond,
            'The unenrolled fallback id must be stable for the process lifetime — a fresh '
            . 'Uuid::v4() per request was the L-1 oddity (clients key dropdown state on it).'
        );
    }

    public function testLaneSentinelIsResidentInCode(): void
    {
        // P-1: the merge ritual requires the sentinel to be present in this file's
        // comment-stripped code (not a docblock echo, not a *.md). This assertion
        // executes the constant so the literal survives php_strip_whitespace.
        $this->assertMatchesRegularExpression('/^[A-Z0-9_]{16,32}$/', self::LANE_SENTINEL);
    }
}
