<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * S64 (RESPECIFIED) — CI-pinned composed-route inventory (phlix-server, server only).
 *
 * ## What this pins
 *
 * The single route table the server actually serves is the one `Application::loadRoutes()`
 * composes at boot. This harness composes that table **in-process against the real PHP-DI
 * container** and pins it, method+path for method+path, to a committed artifact
 * ({@see tests/Fixtures/Routes/composed-route-inventory.json}). Any legitimate change to a
 * route must be mirrored in the artifact in the same commit or this gate reddens; an
 * illegitimate (era-2 / accidental) change reddens too. It is a whole-table tripwire, not
 * per-route spot coverage (cf. `MusicTracksRouteReachabilityTest`) and not a single-registrar
 * pin (cf. `RouterMediaRoutesTest`).
 *
 * ## Why a REAL container and not a stub
 *
 * A hand-built `Router` (or a literal-null / unbound container) is a hub-style shortcut that
 * does not describe production: with a null container `loadRoutes()` falls through to legacy
 * hand-wire paths that reach a real MySQL socket (`PDOException 1045`), so it silently proves
 * nothing about the DI-composed table. Only the real `ContainerFactory` graph yields the served
 * set. The `HealthRoutesAuthGuardTest` pattern is followed exactly — the MySQL `Connection` is
 * the ONLY doubled collaborator (its constructor opens a socket, so it is created with
 * `createMock`, which skips the constructor); every other service is the production definition.
 * The container is real enough that an UNBOUND stub demonstrably cannot compose the table —
 * see {@see self::testUnboundStubContainerCannotComposeProductionRoutes()}, which is the control
 * that proves this harness is doing real DI work and not echoing an empty table.
 *
 * ## Era-2 content invariance
 *
 * This step adds NO route and touches NO `src/`. It observes the composed table only. The
 * planted-drift test mutates a throwaway, in-memory `Router` that is never persisted and never
 * reaches a committed file, so it cannot alter the route content the hub fixtures pin.
 */
final class ComposedRouteInventoryTest extends TestCase
{
    /**
     * Merge-lane canary (P-1): code-resident via the executing assertion in
     * {@see self::testInventoryTokenIsResidentInCode()} — survives php_strip_whitespace, never
     * lives in a *.md. Also written into the artifact so the pin and the code agree.
     */
    private const INVENTORY_TOKEN = 'S64INVENTORYX7D3';

    private const INVENTORY_PATH = __DIR__ . '/../../../Fixtures/Routes/composed-route-inventory.json';

    private const PLANTED_METHOD = 'GET';
    private const PLANTED_PATH = '/__s64_planted_drift_route__';

    private string $tempDir = '';
    private string $loggerConfigPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        $this->tempDir = sys_get_temp_dir() . '/phlix_s64_' . uniqid('', true);
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
        // The container graph resolves MediaAssetJobStore/SimilarityJobStore through
        // MediaServicesProvider factories that mint these shared /tmp dirs at construction.
        // Sweep them so the suite leaves zero residue (same obligation as HealthRoutesAuthGuardTest).
        foreach (['phlix_media_asset_jobs', 'phlix_similarity_jobs'] as $sharedQueue) {
            $sharedDir = sys_get_temp_dir() . '/' . $sharedQueue;
            if (is_dir($sharedDir)) {
                foreach (glob($sharedDir . '/*') ?: [] as $queued) {
                    @unlink($queued);
                }
                @rmdir($sharedDir);
            }
        }
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
    // 1. The both-directions pin
    // -----------------------------------------------------------------

    public function testComposedInventoryMatchesCommittedInBothDirections(): void
    {
        $composed = $this->composedRows();
        $committed = $this->committedRows();

        $this->assertGreaterThanOrEqual(
            300,
            $composed,
            'ANTI-VACUITY: loadRoutes() composed fewer than 300 routes — the harness likely hit an '
            . 'early return or an unbuilt container and would otherwise green-light an empty table.'
        );

        // Direction A: something committed is no longer composed (a route was removed/renamed).
        $missing = array_values(array_diff($committed, $composed));
        // Direction B: something composed is not committed (a route was added/renamed).
        $extra = array_values(array_diff($composed, $committed));

        $this->assertSame(
            [],
            $missing,
            'Committed inventory routes MISSING from loadRoutes(): ' . implode(', ', $missing)
            . ' — a route was deleted or renamed without updating ' . self::INVENTORY_PATH . '.'
        );
        $this->assertSame(
            [],
            $extra,
            'Routes composed by loadRoutes() NOT in the committed inventory: ' . implode(', ', $extra)
            . ' — a route was added or renamed without updating ' . self::INVENTORY_PATH . '.'
        );
        $this->assertSame($committed, $composed, 'composed set must equal the committed set');
    }

    // -----------------------------------------------------------------
    // 2. The artifact's human-readable summary is honest
    // -----------------------------------------------------------------

    public function testArtifactSummaryIsConsistentWithComposedTable(): void
    {
        $decoded = $this->decodedArtifact();

        $this->assertSame(self::INVENTORY_TOKEN, $decoded['token'] ?? null, 'artifact token must equal the code const');

        $total = $decoded['total'] ?? null;
        $this->assertIsInt($total);

        $routes = $decoded['routes'] ?? null;
        $this->assertIsArray($routes);
        $this->assertSame($total, count($routes), 'artifact total must equal count(routes)');

        $byMethod = $decoded['byMethod'] ?? null;
        $this->assertIsArray($byMethod);
        $expected = $byMethod;
        ksort($expected);

        // The summary must describe the LIVE composed table, not just the stored list.
        $composedCounts = $this->methodCounts($this->composedRows());
        $this->assertSame(
            $expected,
            $composedCounts,
            'artifact byMethod must match the method histogram composed by loadRoutes() right now.'
        );
    }

    // -----------------------------------------------------------------
    // 3. Planted-drift proof: the gate actually bites
    // -----------------------------------------------------------------

    public function testPlantedDriftTurnsTheGateRedAndRevertTurnsItGreen(): void
    {
        $committed = $this->committedRows();

        // Baseline: a freshly composed router already matches the pin (green before we touch anything).
        $baseline = $this->composedRows();
        $this->assertSame(
            $committed,
            $baseline,
            'precondition: baseline composition must equal the committed inventory'
        );

        $plantedRow = self::PLANTED_METHOD . ' ' . self::PLANTED_PATH;
        $this->assertNotContains($plantedRow, $committed, 'the throwaway route must not already be a real route');

        // Plant on a distinct, in-memory throwaway router. Never persisted, never a src/ edit.
        $plantRouter = $this->composeRouter();
        $plantRouter->get(self::PLANTED_PATH, static fn (): string => 's64-planted-drift-control');
        $plantedRows = $this->rowsFrom($plantRouter);

        // RED: the both-directions gate must now see an EXTRA route.
        $this->assertContains($plantedRow, $plantedRows, 'the planted route must be live in the mutated router');
        $this->assertNotEmpty(
            array_diff($plantedRows, $committed),
            'PLANTED-DRIFT PROOF: adding a route must turn the extra-direction gate RED.'
        );
        $this->assertSame(
            [],
            array_diff($committed, $plantedRows),
            'a single add produces an EXTRA, never a MISSING (sanity of the diff pair)'
        );

        // GREEN: the plant lived only in the throwaway instance — a fresh composition no longer carries it.
        $revertedRows = $this->composedRows();
        $this->assertNotContains(
            $plantedRow,
            $revertedRows,
            'the throwaway plant must not leak across router instances'
        );
        $this->assertSame(
            [],
            array_merge(array_diff($committed, $revertedRows), array_diff($revertedRows, $committed)),
            'REVERT PROOF: a freshly composed router matches the pin again — the drift was in-memory only.'
        );
    }

    // -----------------------------------------------------------------
    // 4. Unbound control: real DI is doing the work
    // -----------------------------------------------------------------

    public function testUnboundStubContainerCannotComposeProductionRoutes(): void
    {
        // A container that answers nothing proves the harness depends on the real container:
        // the FIRST thing loadRoutes() reaches is the AuthController, so that is the id that
        // surfaces. The route table is never built, so an unbound stub can never echo a full pin.
        $unbound = new class () implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new RuntimeException('unbound: ' . $id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $pool = $this->createMock(ConnectionPool::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unbound: ' . AuthController::class);

        new Application($unbound, ['hub' => ['config_dir' => $this->tempDir]], $pool);
    }

    // -----------------------------------------------------------------
    // 5. Token survival (P-1)
    // -----------------------------------------------------------------

    public function testInventoryTokenIsResidentInCode(): void
    {
        // Executes the constant so the literal survives comment-stripping, and binds it to the artifact.
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{16}$/', self::INVENTORY_TOKEN);
        $decoded = $this->decodedArtifact();
        $this->assertSame(self::INVENTORY_TOKEN, $decoded['token'] ?? null);
    }

    // -----------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------

    /**
     * Compose the production route table in-process: real `ContainerFactory` graph, only the
     * socket-opening MySQL `Connection` doubled (see class docblock for why).
     */
    private function composeRouter(): Router
    {
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

        $container = ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ], $providers);

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getPooledConnection')->willReturn($this->createMock(Connection::class));

        $config = ['hub' => ['config_dir' => $this->tempDir]];
        $application = new Application($container, $config, $pool);

        $property = (new ReflectionClass(Application::class))->getProperty('router');
        $property->setAccessible(true);
        $router = $property->getValue($application);

        $this->assertInstanceOf(
            Router::class,
            $router,
            'ANTI-VACUITY: Application::$router is not a Router; the inventory assertions '
            . 'would silently read an empty table.'
        );

        return $router;
    }

    /**
     * Flatten a router's composed table to a sorted, de-duplicated `["METHOD path", ...]` list.
     *
     * @return list<string>
     */
    private function rowsFrom(Router $router): array
    {
        $rows = [];
        foreach ($router->getRoutes() as $method => $entries) {
            foreach ($entries as $entry) {
                $path = $entry['path'];
                $this->assertIsString($path);
                $rows[] = $method . ' ' . $path;
            }
        }

        sort($rows);

        return array_values(array_unique($rows));
    }

    /**
     * @return list<string>
     */
    private function composedRows(): array
    {
        return $this->rowsFrom($this->composeRouter());
    }

    /**
     * @return array<string, int>
     */
    private function methodCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $method = strtok($row, ' ');
            $this->assertIsString($method);
            $counts[$method] = ($counts[$method] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedArtifact(): array
    {
        $raw = @file_get_contents(self::INVENTORY_PATH);
        $this->assertIsString($raw, 'The committed inventory artifact must be readable at ' . self::INVENTORY_PATH);

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'The inventory artifact must decode to a JSON object.');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function committedRows(): array
    {
        $decoded = $this->decodedArtifact();
        $routes = $decoded['routes'] ?? null;
        $this->assertIsArray($routes, 'The inventory artifact must carry a "routes" array.');

        $rows = [];
        foreach ($routes as $row) {
            $this->assertIsString($row, 'Every committed route must be a "METHOD path" string.');
            $rows[] = $row;
        }

        sort($rows);

        return array_values(array_unique($rows));
    }
}
