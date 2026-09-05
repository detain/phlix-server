<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Hub\RelayStateStore;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\Admin\HealthController;
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
 * S437 — posture guard: `/api/v1/health/relay` and `/api/v1/health/network` are
 * authenticated on the PRODUCTION router, and the anonymous caller can obtain
 * none of the internal connection detail those endpoints carry.
 *
 * ## The defect this pins shut
 *
 * `HealthController::relayHealth()` returns `relay.lastConnectError` /
 * `lastConnectErrorAt`. Their raw text is recorded by
 * {@see \Phlix\Hub\RelayConsumer::recordConnectError()} as `': ' . $e->getMessage()`
 * — a socket error string that embeds the hub host:port. Both routes were
 * registered BARE (`$router->get(path, closure)` with no middleware), while every
 * sibling `/api/v1/*` telemetry route (e.g. the media/collections groups) is
 * `AuthMiddleware`-gated. That published internal network topology to anyone who
 * could reach the port. S40 widened the disclosure by adding `networkHealth`.
 *
 * ## Why the assertions are shaped this way
 *
 * - **The route table is the PRODUCTION one.** Reflected off an `Application`
 *   built from {@see ContainerFactory::defaultProviders()} — the same stack
 *   `public/index.php` and the Workerman daemon build — with only the MySQL
 *   {@see Connection} doubled. A test that registers the route it tests cannot
 *   observe the production registration being ungated (the exact S31/S36 lesson
 *   recorded in {@see ApplicationRouterWirePathGuardTest}). So the posture
 *   assertions read `Application`'s own router, never a hand-built one.
 * - **The 401 has a readable control.** `GET /health` (the deliberately-open
 *   liveness route the infra probes hit) returns **200** on the same dispatch
 *   chain, so "the whole app answers 401" can never explain these two 401s.
 * - **Disclosed-field absence is structural.** The 401 body's key set is pinned
 *   to exactly `{error, code}` and its raw text is checked for the planted hub
 *   detail. Drop the middleware and the anonymous call reaches the handler, which
 *   ALWAYS emits the `relay`/`lastConnectError` key set — so the guard reds on
 *   the drift regardless of whether a secret value happens to be populated.
 * - **Parity is proven at the controller.** The gate wraps the handler, it does
 *   not modify it; the response an authenticated caller receives is exactly what
 *   `relayHealth()`/`networkHealth()` return when reached. Asserted directly (as
 *   {@see \Phlix\Tests\Unit\Server\Http\Controllers\Admin\HealthControllerTest}
 *   does) because the global `AccessScheduleMiddleware` answers 403 for an authed
 *   request with no resolvable DB profile — the same dispatch limit that keeps
 *   {@see ApplicationRouterWirePathGuardTest} from taking authed rails to a 200.
 *
 * ## Planted-drift proof
 *
 * Revert either route to a bare `$router->get()` (drop the `AuthMiddleware`
 * group): {@see self::testBothHealthRoutesAreAuthGatedInProductionRouter()} reds
 * on the middleware reflection and {@see self::testAnonymousRelayDispatchIsRejectedWithZeroDisclosure()}
 * reds on the 401/absence assertions, while the `/health` liveness control stays
 * green. Restore the group → green.
 */
final class HealthRoutesAuthGuardTest extends TestCase
{
    private const RELAY_PATH = '/api/v1/health/relay';
    private const NETWORK_PATH = '/api/v1/health/network';
    private const LIVENESS_PATH = '/health';

    /**
     * A realistic `lastConnectError` body: the hub host:port plus socket error
     * text RelayConsumer embeds via `': ' . $e->getMessage()`. None of these
     * substrings may appear in an unauthenticated response.
     */
    private const PLANTED_HUB_HOST = 'hub.example.com';
    private const PLANTED_HUB_PORT = '8443';
    private const PLANTED_CONNECT_ERROR = 'stream socket to hub.example.com:8443 failed: Connection refused';

    // Merge-lane canary (P-1). Lives in the comment-stripped PHP corpus via this
    // executing assertion below, never in a *.md file. Not a security assertion.
    private const LANE_SENTINEL = 'S437HEALTHAUTHX3V9';

    private string $tempDir = '';
    private string $loggerConfigPath = '';
    private ?ContainerInterface $sharedContainer = null;
    private ?Application $sharedApplication = null;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        // Global AccessScheduleMiddleware reads process-static RequestContext; a
        // leaked user id would flip every dispatch below to a 403. Cleared both ends.
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        $this->tempDir = sys_get_temp_dir() . '/phlix_s437_' . uniqid('', true);
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

        // Seed cross-process state the controller reads, carrying the planted hub
        // detail, so an UNGATED handler would disclose it and we can prove the gate
        // keeps it out of reach pre-auth.
        $this->seedRelayState();
        $this->seedHeartbeatState();
        $this->seedEnrollment();
    }

    protected function tearDown(): void
    {
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
    // Fixtures
    // -----------------------------------------------------------------

    private function fresh(int $secondsAgo = 0): string
    {
        return date('c', time() - $secondsAgo);
    }

    private function seedRelayState(): void
    {
        file_put_contents($this->tempDir . '/' . RelayStateStore::RELAY_STATE_FILE, json_encode([
            'connected' => false,
            'active' => false,
            'reconnectAttempts' => 3,
            'lastConnectError' => self::PLANTED_CONNECT_ERROR,
            'lastConnectErrorAt' => '2026-09-05T09:59:00+00:00',
            'activeSessions' => 0,
            'updatedAt' => $this->fresh(),
        ], JSON_THROW_ON_ERROR));
    }

    private function seedHeartbeatState(): void
    {
        file_put_contents($this->tempDir . '/' . RelayStateStore::HEARTBEAT_STATE_FILE, json_encode([
            'lastSuccessfulHeartbeat' => $this->fresh(5),
            'consecutiveFailures' => 0,
            'lastLatencyMs' => 42,
            'updatedAt' => $this->fresh(),
        ], JSON_THROW_ON_ERROR));
    }

    private function seedEnrollment(): void
    {
        file_put_contents($this->tempDir . '/hub-enrollment.json', json_encode([
            'enrollment_jwt' => 'jwt-token',
            'hub_jwks_url' => 'https://' . self::PLANTED_HUB_HOST . '/.well-known/jwks.json',
            'server_id' => 'srv-1',
            'hub_base_url' => 'https://' . self::PLANTED_HUB_HOST,
            'enrolled_at' => time(),
        ], JSON_THROW_ON_ERROR));
    }

    // -----------------------------------------------------------------
    // Production harness (mirrors ApplicationRouterWirePathGuardTest)
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

    /**
     * The production `Application`, configured so the inline `HealthController`
     * that `loadRoutes()` builds reads the seeded state from this test's temp dir.
     */
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

    public function testBothHealthRoutesAreAuthGatedInProductionRouter(): void
    {
        foreach ([self::RELAY_PATH, self::NETWORK_PATH] as $path) {
            $this->assertSame(
                ['AuthMiddleware'],
                $this->middlewareNames('GET', $path),
                "GET {$path} must stay gated by exactly AuthMiddleware. An empty stack here means "
                . 'the route was re-registered bare and reopens the anonymous hub-topology leak (S437).'
            );
        }
    }

    // -----------------------------------------------------------------
    // 2. Anonymous dispatch → 401 with zero disclosure of hub internals
    // -----------------------------------------------------------------

    public function testAnonymousRelayDispatchIsRejectedWithZeroDisclosure(): void
    {
        $served = $this->application()->dispatch($this->request(self::RELAY_PATH));

        $this->assertSame(
            401,
            $served->statusCode,
            'Anonymous GET ' . self::RELAY_PATH . ' must be refused by AuthMiddleware. A 200 here means '
            . 'the gate was dropped; a 404 means the METHOD+PATH registration changed.'
        );

        $body = $this->body($served);
        // Exactly the auth error — nothing of the health payload leaks through.
        $this->assertSame(['error', 'code'], array_keys($body));
        $this->assertSame('auth.required', $body['code'] ?? null);

        $this->assertStringNotContainsString('lastConnectError', (string) $served->body);
        $this->assertStringNotContainsString(self::PLANTED_HUB_HOST, (string) $served->body);
        $this->assertStringNotContainsString(self::PLANTED_HUB_PORT, (string) $served->body);
        $this->assertStringNotContainsString('Connection refused', (string) $served->body);
    }

    public function testAnonymousNetworkDispatchIsRejectedWithZeroDisclosure(): void
    {
        $served = $this->application()->dispatch($this->request(self::NETWORK_PATH));

        $this->assertSame(
            401,
            $served->statusCode,
            'Anonymous GET ' . self::NETWORK_PATH . ' must be refused by AuthMiddleware (S437).'
        );

        $body = $this->body($served);
        $this->assertSame(['error', 'code'], array_keys($body));
        $this->assertSame('auth.required', $body['code'] ?? null);

        // The latency snapshot fields must be absent for an anonymous caller.
        $this->assertStringNotContainsString('latencyMs', (string) $served->body);
        $this->assertStringNotContainsString('measuredAt', (string) $served->body);
    }

    // -----------------------------------------------------------------
    // 3. The readable control: the deliberately-open liveness route stays 200.
    //    This is the ruling that infra probes (docker HEALTHCHECK, k8s, boot-smoke)
    //    target `/health`, never the two gated routes above — so gating them is safe.
    // -----------------------------------------------------------------

    public function testUnauthenticatedLivenessRouteStaysOpen(): void
    {
        $served = $this->application()->dispatch($this->request(self::LIVENESS_PATH));

        $this->assertSame(
            200,
            $served->statusCode,
            'GET ' . self::LIVENESS_PATH . ' is the non-revealing liveness probe and MUST stay '
            . 'unauthenticated (status/version only); gating it would break infra healthchecks.'
        );
        $this->assertSame('ok', $this->body($served)['status'] ?? null);
    }

    // -----------------------------------------------------------------
    // 4. Authenticated parity: the gated handler returns the documented shape.
    // -----------------------------------------------------------------

    public function testAuthenticatedRelayShapeIsUnchanged(): void
    {
        $controller = new HealthController(null, $this->tempDir);
        $served = $controller->relayHealth(new Request(), []);

        $this->assertSame(200, $served->statusCode);

        $body = $this->body($served);
        $this->assertSame(['relay', 'hub'], array_keys($body));
        $this->assertSame(
            ['connected', 'active', 'reconnectAttempts', 'lastDisconnectTime', 'activeSessions',
                'lastConnectError', 'lastConnectErrorAt', 'stale'],
            array_keys($body['relay']),
            'relay payload shape must be unchanged by the auth gate (parity).'
        );
        // When reached, the handler genuinely discloses the hub detail — the whole
        // point of the gate. Parity holds: the same bytes, only now behind auth.
        $this->assertSame(self::PLANTED_CONNECT_ERROR, $body['relay']['lastConnectError']);
    }

    public function testAuthenticatedNetworkShapeIsUnchanged(): void
    {
        $controller = new HealthController(null, $this->tempDir);
        $served = $controller->networkHealth(new Request(), []);

        $this->assertSame(200, $served->statusCode);

        $body = $this->body($served);
        $this->assertSame(['latencyMs', 'status', 'measuredAt'], array_keys($body));
        $this->assertSame(42, $body['latencyMs']);
        $this->assertSame('healthy', $body['status']);
    }

    public function testLaneSentinelIsResidentInCode(): void
    {
        // P-1: the merge ritual requires the sentinel to be present in this file's
        // comment-stripped code (not a docblock echo, not a *.md). This assertion
        // executes the constant so the literal survives php_strip_whitespace.
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{18}$/', self::LANE_SENTINEL);
    }
}
