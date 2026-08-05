<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Server\Http;

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Router;
use Phlix\Server\Http\Routes\AdminRoutes;
use Phlix\Server\Updates\AsyncVersionMarkerFetcher;
use Phlix\Server\Updates\CoreUpdateCheckService;
use Phlix\Server\Updates\VersionMarkerFetcherInterface;
use Phlix\Tests\Support\Database\InMemoryServerSettingsConnection;
use Phlix\Tests\Support\Updates\RecordingVersionMarkerFetcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * S74 — AC1 end-to-end: **the status endpoint correctly reports "update
 * available" against a seeded newer `VERSION` marker.**
 *
 * ## Why this file drives the real registrar through the real container
 *
 * A hand-built router asserted against a list of route literals can only
 * disagree with the registrar it never consults. So this builds the production
 * DI container ({@see ContainerFactory::create()} over
 * {@see ContainerFactory::defaultProviders()}, with the MySQL {@see Connection}
 * and the marker fetcher replaced), hands it to the production registrar
 * {@see AdminRoutes::register()} — the same call `Application::loadRoutes()`
 * makes — and dispatches real {@see Request}s.
 *
 * Building the container for real is load-bearing twice over: it proves
 * `AdminUpdatesController` and `CoreUpdateCheckService` are actually resolvable
 * (they are eagerly resolved at route-bind time, so a missing binding takes the
 * whole `/api/v1/admin/*` router down), and it proves the controller and the
 * background check share ONE service instance and therefore one settings store.
 *
 * ⚠ "Not a 404" is not enough on its own: the admin group answers 401 and 403
 * without ever reaching a handler, so each case asserts a payload only the
 * intended handler produces.
 *
 * @package Phlix\Tests\Integration\Server\Http
 */
final class AdminUpdatesRoutesTest extends TestCase
{
    private const ADMIN_ID = 'cccccccc-3333-4333-8333-cccccccccccc';
    private const PLAIN_ID = 'dddddddd-4444-4444-8444-dddddddddddd';

    private string $tempDir = '';
    private string $loggerConfigPath = '';
    private Router $router;
    private ContainerInterface $container;
    private RecordingVersionMarkerFetcher $fetcher;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        $this->fetcher = new RecordingVersionMarkerFetcher('9.9.9');

        $this->tempDir = sys_get_temp_dir() . '/phlix_s74_routes_' . uniqid('', true);
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

        $this->container = $this->buildContainer();
        $this->router = new Router();
        AdminRoutes::register($this->router, $this->container);
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();

        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // AC1
    // ------------------------------------------------------------------

    /**
     * AC1. Seed a NEWER marker, run the real check through the container's own
     * service, then read the endpoint.
     */
    public function testTheStatusEndpointReportsAnUpdateAgainstASeededNewerMarker(): void
    {
        $this->fetcher->willReturn("99.0.0\n");

        $this->service()->check();

        $data = $this->statusData(self::ADMIN_ID);

        self::assertTrue(
            $data['updateAvailable'],
            'A seeded marker strictly newer than Version::STRING must surface as updateAvailable=true.',
        );
        self::assertSame('99.0.0', $data['latestVersion']);
        self::assertSame(\Phlix\Common\Version::STRING, $data['currentVersion']);
        self::assertNull($data['lastError']);
        self::assertIsInt($data['lastCheckedAt']);
    }

    /**
     * CONTROL for AC1. Same path, marker equal to the compiled version — the
     * endpoint must report NO update. Without this, `updateAvailable` could be a
     * constant `true` and the test above would still pass.
     */
    public function testTheStatusEndpointReportsNoUpdateForAnIdenticalMarker(): void
    {
        $this->fetcher->willReturn(\Phlix\Common\Version::STRING);

        $this->service()->check();

        $data = $this->statusData(self::ADMIN_ID);

        self::assertFalse($data['updateAvailable']);
        self::assertSame(\Phlix\Common\Version::STRING, $data['latestVersion']);
    }

    /**
     * SECOND CONTROL. Before any check has ever run there is no marker at all,
     * so the endpoint must answer "no update" rather than guessing.
     */
    public function testTheStatusEndpointReportsNoUpdateBeforeAnyCheckHasRun(): void
    {
        $data = $this->statusData(self::ADMIN_ID);

        self::assertFalse($data['updateAvailable']);
        self::assertNull($data['latestVersion']);
        self::assertNull($data['lastCheckedAt']);
    }

    /**
     * The status read must never reach the network — it answers an HTTP request
     * inside a resident-memory worker.
     */
    public function testTheStatusEndpointPerformsNoOutboundFetch(): void
    {
        $before = $this->fetchCount();
        $this->statusData(self::ADMIN_ID);

        self::assertSame($before, $this->fetchCount(), 'GET /updates/status must not fetch anything.');
    }

    // ------------------------------------------------------------------
    // Reachability + gating through the real registrar
    // ------------------------------------------------------------------

    public function testTheStatusRouteIsRegisteredUnderTheAdminPrefix(): void
    {
        $response = $this->dispatch('GET', '/api/v1/admin/updates/status', self::ADMIN_ID);

        self::assertSame(200, $response->statusCode);
        self::assertStringNotContainsString('The requested resource was not found', (string) $response->body);
        self::assertStringContainsString('updateAvailable', (string) $response->body);
    }

    public function testTheSettingsRouteIsRegisteredAndPersistsTheToggle(): void
    {
        $response = $this->dispatch(
            'PUT',
            '/api/v1/admin/updates/settings',
            self::ADMIN_ID,
            ['checkEnabled' => false],
        );

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Settings updated.', (string) $response->body);
        self::assertFalse($this->service()->isCheckEnabled());
        self::assertFalse($this->statusData(self::ADMIN_ID)['checkEnabled']);
    }

    public function testAPlainUserIsRefusedByTheAdminGroupGate(): void
    {
        $response = $this->dispatch('GET', '/api/v1/admin/updates/status', self::PLAIN_ID);

        self::assertSame(403, $response->statusCode);
        self::assertStringNotContainsString('updateAvailable', (string) $response->body);
    }

    public function testAnAnonymousCallerIsRefusedByTheAdminGroupGate(): void
    {
        $response = $this->dispatch('GET', '/api/v1/admin/updates/status', null);

        self::assertSame(401, $response->statusCode);
        self::assertStringNotContainsString('updateAvailable', (string) $response->body);
    }

    /**
     * Out of scope, and pinned closed: S74 must NOT ship an apply action.
     */
    public function testThereIsNoInlineApplyRoute(): void
    {
        foreach (
            [
            ['POST', '/api/v1/admin/updates/apply'],
            ['POST', '/api/v1/admin/updates/install'],
            ['POST', '/api/v1/admin/updates'],
            ] as [$method, $path]
        ) {
            $response = $this->dispatch($method, $path, self::ADMIN_ID);
            self::assertSame(
                404,
                $response->statusCode,
                "{$method} {$path} must not exist — the server never applies an update itself.",
            );
        }
    }

    /**
     * The copy-to-clipboard command is the ONLY update affordance, so it must
     * actually be present in the payload.
     */
    public function testTheStatusPayloadCarriesTheCopyToClipboardCommand(): void
    {
        $data = $this->statusData(self::ADMIN_ID);

        self::assertIsString($data['updateCommand']);
        self::assertStringContainsString('--update', $data['updateCommand']);
        self::assertStringContainsString('install.sh', $data['updateCommand']);
    }

    /**
     * The production binding must be the callback-mode fetcher, not something
     * that blocks the worker. Resolved from the REAL container, so a factory
     * that silently returns a blocking implementation fails here.
     */
    public function testTheProductionFetcherBindingIsTheAsyncOne(): void
    {
        $container = ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ], $this->providers($this->connection(), null));

        self::assertInstanceOf(
            AsyncVersionMarkerFetcher::class,
            $container->get(VersionMarkerFetcherInterface::class),
        );
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function service(): CoreUpdateCheckService
    {
        /** @var CoreUpdateCheckService $service */
        $service = $this->container->get(CoreUpdateCheckService::class);

        return $service;
    }

    private function fetchCount(): int
    {
        return count($this->fetcher->urls);
    }

    /**
     * @return array<string, mixed>
     */
    private function statusData(?string $userId): array
    {
        $response = $this->dispatch('GET', '/api/v1/admin/updates/status', $userId);
        self::assertSame(200, $response->statusCode, (string) $response->body);

        /** @var array{data: array<string, mixed>} $payload */
        $payload = json_decode((string) $response->body, true);

        return $payload['data'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function dispatch(
        string $method,
        string $path,
        ?string $userId,
        array $body = [],
    ): \Phlix\Server\Http\Response {
        $request = new Request();
        $request->method = $method;
        $request->path = $path;
        $request->userId = $userId;
        $request->body = $body;

        return $this->router->dispatch($request);
    }

    private function buildContainer(): ContainerInterface
    {
        return ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ], $this->providers($this->connection(), $this->fetcher));
    }

    /**
     * @return list<ServiceProviderInterface>
     */
    private function providers(Connection $connection, ?VersionMarkerFetcherInterface $fetcher): array
    {
        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($connection, $fetcher) implements ServiceProviderInterface {
            public function __construct(
                private Connection $connection,
                private ?VersionMarkerFetcherInterface $fetcher,
            ) {
            }

            /**
             * @param ContainerBuilder<\DI\Container> $builder
             * @param array<string, mixed>            $appConfig
             */
            public function register(ContainerBuilder $builder, array $appConfig): void
            {
                $connection = $this->connection;
                $definitions = [
                    Connection::class => factory(static fn (): Connection => $connection),
                ];

                if ($this->fetcher !== null) {
                    $fetcher = $this->fetcher;
                    $definitions[VersionMarkerFetcherInterface::class] =
                        factory(static fn (): VersionMarkerFetcherInterface => $fetcher);
                }

                $builder->addDefinitions($definitions);
            }
        };

        return $providers;
    }

    /**
     * One connection answering both `server_settings` (statefully) and the admin
     * gate's `users` lookup.
     */
    private function connection(): Connection
    {
        return new class extends InMemoryServerSettingsConnection {
            /**
             * @param string                        $query
             * @param array<int|string, mixed>|null $params
             * @param int                           $fetchmode
             *
             * @return mixed
             */
            public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
            {
                $sql = trim((string) $query);
                $args = is_array($params) ? array_values($params) : [];

                if (str_contains($sql, 'FROM users')) {
                    $id = is_scalar($args[0] ?? null) ? (string) $args[0] : '';

                    return $id === AdminUpdatesRoutesTest::adminId()
                        ? [['id' => $id, 'is_admin' => 1, 'status' => 'active']]
                        : [];
                }

                return parent::query($query, $params, $fetchmode);
            }
        };
    }

    public static function adminId(): string
    {
        return self::ADMIN_ID;
    }
}
