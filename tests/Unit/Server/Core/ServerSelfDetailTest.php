<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Version;
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
 * xrepo__server-ui Major #3 — `GET /api/v1/servers/{id}` self-detail route.
 *
 * The admin MetricsPage (served by the shared ui router on the SERVER host)
 * fetches `GET /api/v1/servers/{id}` for the selected server's detail card.
 * The route was never registered server-side: the request 404s and the card
 * renders empty forever. This file pins the cure: the server answers its OWN
 * id with the truthful self-entry (the list closure's identity ladder plus
 * `version`), and 404s every other id — it must never pretend to be another
 * fleet member.
 *
 * ## Why the assertions are shaped this way
 *
 * - **Production router, real handlers.** Mirrors {@see ServersAndJobsAuthGateTest}:
 *   the table is reflected off an `Application` built from
 *   `ContainerFactory::defaultProviders()` with only MySQL doubled, so a test
 *   that registers its own route cannot observe the production registration
 *   (the S31 lesson). The harness ships NO hub-enrollment.json, so the id
 *   taken from the list response is exactly the memoised unenrolled fallback
 *   — one round-trip yields the canonical own-id for the detail legs.
 * - **Exact-match refusal, not prefix.** A detail route built on
 *   `str_contains`/`strpos` would pass the "foreign id" leg but leak through
 *   with `selfId . 'x'`; that leg is asserted separately.
 * - **404 is the flat `{error}` envelope** (CollectionController precedent) —
 *   key-set equality, and no fleet fields in the body.
 * - **Posture parity with the list.** Exactly `[AuthMiddleware]` on the
 *   production registration plus an anonymous full-chain 401 with zero
 *   disclosure (L-1 family): the detail rail must not reopen what L-1 closed.
 * - **Metrics fields are deliberately ABSENT.** ui `toServerInfo` degrades
 *   missing numbers to 0 by design; the server has no cheap request-time
 *   source for uptime/session/library/storage counts, and emitting zeros
 *   under real key names would be worse than omitting them.
 */
final class ServerSelfDetailTest extends TestCase
{
    private const LIST_PATH = '/api/v1/servers';
    private const DETAIL_PATH = '/api/v1/servers/{id}';

    private string $tempDir = '';
    private string $loggerConfigPath = '';
    private ?ContainerInterface $sharedContainer = null;
    private ?Application $sharedApplication = null;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        $this->tempDir = sys_get_temp_dir() . '/phlix_selfdetail_' . uniqid('', true);
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
        // No hub-enrollment.json: the list/detail handlers take the memoised
        // unenrolled fallback branch, exactly like the L-1 harness.
    }

    protected function tearDown(): void
    {
        foreach (['media_asset', 'similarity'] as $sharedQueueName) {
            $sharedDir = \Phlix\Config\JobQueuePaths::dir($sharedQueueName);
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
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $decoded = json_decode((string) $response->body, true);
        $this->assertIsArray($decoded, 'response body must be a JSON object');

        /** @var array<string, mixed> $decoded */
        return $decoded;
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
     * The canonical own-id: whatever the LIST rail reports under this harness.
     */
    private function ownId(): string
    {
        $listHandler = $this->pathIndex()['GET'][self::LIST_PATH]['handler'] ?? null;
        $this->assertIsCallable($listHandler, 'list handler missing from the production table');

        $first = $this->body($listHandler($this->request(self::LIST_PATH)));
        $id = $first['data'][0]['id'] ?? null;
        $this->assertIsString($id);
        $this->assertNotSame('', $id);

        return $id;
    }

    /**
     * @param array<string, string> $params
     */
    private function serveDetail(array $params): Response
    {
        $handler = $this->pathIndex()['GET'][self::DETAIL_PATH]['handler'] ?? null;
        $this->assertIsCallable(
            $handler,
            'GET ' . self::DETAIL_PATH . ' is not registered on the PRODUCTION router '
            . '(xrepo__server-ui Major #3: the admin MetricsPage detail card depends on it).'
        );

        return $handler($this->request(str_replace('{id}', $params['id'], self::DETAIL_PATH)), $params);
    }

    // -----------------------------------------------------------------
    // 1. Own id → 200, truthful self-entry plus version
    // -----------------------------------------------------------------

    public function testOwnIdServesSelfEntryWithVersion(): void
    {
        $ownId = $this->ownId();
        $served = $this->serveDetail(['id' => $ownId]);

        $this->assertSame(200, $served->statusCode);

        $body = $this->body($served);
        $this->assertTrue($body['success'] ?? false, 'detail envelope must carry success:true');

        $data = $body['data'] ?? null;
        $this->assertIsArray($data);

        $this->assertSame($ownId, $data['id'] ?? null);
        $this->assertSame('Phlix Media Server', $data['name'] ?? null);
        $this->assertSame('http://localhost:8096', $data['hostname'] ?? null);
        $this->assertTrue($data['online'] ?? false);
        $this->assertIsInt($data['last_seen_at'] ?? null);
        $this->assertSame(Version::STRING, $data['version'] ?? null, 'version must be the server build constant');

        // Deliberately NOT fabricating fleet metrics it cannot price at request time.
        foreach (['uptime_seconds', 'active_session_count', 'library_count', 'total_item_count', 'total_storage_bytes'] as $absent) {
            $this->assertArrayNotHasKey($absent, $data, "$absent has no truthful request-time source; omit it and let the ui degrade to 0");
        }
    }

    public function testListAndDetailAgreeOnIdentityAndShareOneMemo(): void
    {
        $ownId = $this->ownId();

        $first = $this->body($this->serveDetail(['id' => $ownId]));
        $second = $this->body($this->serveDetail(['id' => $this->ownId()]));

        $this->assertSame($ownId, $first['data']['id'] ?? null);
        $this->assertSame($ownId, $second['data']['id'] ?? null, 'detail must resolve the SAME memoised fallback id the list hands out — a second generator would make every detail lookup miss.');
    }

    // -----------------------------------------------------------------
    // 2. Any other id → flat 404, no fleet disclosure
    // -----------------------------------------------------------------

    public function testForeignIdIsNotFoundWithFlatErrorEnvelope(): void
    {
        $served = $this->serveDetail(['id' => '11111111-2222-4333-8444-555555555555']);

        $this->assertSame(404, $served->statusCode, 'the server answers ONLY its own id');
        $this->assertSame(['error'], array_keys($this->body($served)));
        $this->assertSame('Server not found', $this->body($served)['error'] ?? null);
        $this->assertStringNotContainsString('hostname', (string) $served->body);
    }

    public function testIdComparisonIsExactNotPrefix(): void
    {
        $ownId = $this->ownId();
        $served = $this->serveDetail(['id' => $ownId . 'x']);

        $this->assertSame(404, $served->statusCode, 'ownId + suffix must NOT match (str_contains-style comparison would pass the foreign-id leg and fail here).');
    }

    // -----------------------------------------------------------------
    // 3. Posture parity with the list (L-1 family)
    // -----------------------------------------------------------------

    public function testDetailRouteCarriesExactlyAuthMiddleware(): void
    {
        $entry = $this->pathIndex()['GET'][self::DETAIL_PATH] ?? null;
        $this->assertIsArray($entry, 'GET ' . self::DETAIL_PATH . ' must exist on the production router.');

        $middleware = $entry['middleware'] ?? null;
        $this->assertIsArray($middleware);

        $names = [];
        foreach ($middleware as $item) {
            $this->assertIsObject($item);
            $position = strrpos($item::class, '\\');
            $names[] = $position === false ? $item::class : substr($item::class, $position + 1);
        }

        $this->assertSame(
            ['AuthMiddleware'],
            $names,
            'GET ' . self::DETAIL_PATH . ' must be gated by exactly AuthMiddleware, matching the list it mirrors (L-1 posture).'
        );
    }

    public function testAnonymousDetailDispatchIsRejectedWithZeroDisclosure(): void
    {
        $served = $this->application()->dispatch($this->request(self::LIST_PATH . '/some-foreign-id'));

        $this->assertSame(401, $served->statusCode, 'Anonymous GET ' . self::DETAIL_PATH . ' must be refused exactly like the list (L-1).');

        $body = $this->body($served);
        $this->assertSame(['error', 'code'], array_keys($body));
        $this->assertSame('auth.required', $body['code'] ?? null);
        $this->assertStringNotContainsString('Phlix Media Server', (string) $served->body);
    }
}
