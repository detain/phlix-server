<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Theming;

use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Plugins\Installer\HttpInstaller;
use Phlix\Plugins\PluginLoader;
use Phlix\Plugins\Repository\PluginRepository;
use Phlix\Plugins\Util\RecursiveDelete;
use Phlix\Server\Http\Controllers\ThemesController;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Tests\Integration\Plugins\InMemoryPluginsTable;
use Phlix\Theming\ThemeSourceRegistry;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;

use function DI\factory;

// The in-memory `plugins` table lives beside the lifecycle tests that invented
// it. Required by path (second class in a test file — PSR-4 cannot autoload
// it); `require_once` makes a double include a no-op.
require_once __DIR__ . '/../Plugins/InstallEnableDisableTest.php';

/**
 * S498 — the W99 defect, pinned: a theme-plugin lifecycle mutation must be
 * visible across the WORKER POOL within a bounded window, with zero restarts.
 *
 * ## How the pool is simulated (house pattern, no new harness)
 *
 * In production the pool is 14 forked Workerman workers; each builds its own
 * PHP-DI container in `onWorkerStart`, so "worker" == "one container" is the
 * exact seam the existing suite already exploits
 * ({@see \Phlix\Tests\Integration\Plugins\SampleThemeLifecycleTest}: one
 * container, real loaders, faked `plugins` table only). Here the container is
 * built TWICE over ONE shared {@see InMemoryPluginsTable} — two resident
 * workers, two independent per-process `ThemeSourceRegistry` instances, one
 * durable truth. Mutations are driven through worker A's `PluginLoader`;
 * freshness is observed through worker B's `ThemesController` exactly as the
 * production provider wires it (resolved off B's `WebPortalRouter`, so the
 * S498 fleet sync in {@see \Phlix\Common\Container\Providers\WebPortalServicesProvider}
 * is the real object under test, not a re-constructed stand-in) — and back.
 *
 * The containers are built BEFORE any mutation and never rebuilt after: there
 * is no restart in this test by construction.
 *
 * ## RED without the fix
 *
 * Reverting only `src/` (keeping this file) makes worker B's served body stay
 * stale forever after a peer mutation: the "stale drift" assertions below pin
 * the raw peer-registry drift, and the served-body assertions demand its
 * repair within {@see CONVERGENCE_BUDGET_SECONDS}. On unpatched code the
 * served-body polling loop exhausts the budget red — the W99 live symptom
 * (≈6/30 stale `/api/v1/themes` after an app-flow uninstall) reproduced in a
 * fixture. Proof captured in steps/w99/s498srv.PROGRESS.md.
 *
 * @group integration
 */
final class CrossWorkerThemeRegistryFleetFlushTest extends TestCase
{
    /** AC1 freshness budget: every worker consistent within 5 s, no restarts. */
    private const CONVERGENCE_BUDGET_SECONDS = 5.0;

    /** Poll cadence while waiting for a peer worker's next served request. */
    private const POLL_INTERVAL_MICROSECONDS = 50_000;

    /** Ids the shipped sample theme plugin contributes, in providedThemes() order. */
    private const SAMPLE_IDS = ['sample-dusk', 'sample-dusk-high-contrast'];

    private string $pluginsBaseDir = '';

    private string $loggerConfigPath = '';

    /** One table, two mock connections — the shared durable truth. */
    private InMemoryPluginsTable $fakeDb;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        $this->pluginsBaseDir = sys_get_temp_dir() . '/phlix_s498_pool_' . uniqid('', true);
        mkdir($this->pluginsBaseDir, 0775, true);

        $logDir = $this->pluginsBaseDir . '/logs';
        mkdir($logDir, 0775, true);
        $loggerConfig = "<?php\nreturn [\n"
            . "    'default' => 'file',\n"
            . "    'handlers' => [\n"
            . "        'file' => ['type' => 'stream', 'path' => '" . $logDir . "/app.log', 'level' => 'debug'],\n"
            . "    ],\n"
            . "];\n";
        $this->loggerConfigPath = $this->pluginsBaseDir . '/logger.php';
        file_put_contents($this->loggerConfigPath, $loggerConfig);

        $this->fakeDb = new InMemoryPluginsTable();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        LoggerFactory::reset();
        if (is_dir($this->pluginsBaseDir)) {
            RecursiveDelete::remove($this->pluginsBaseDir);
        }
    }

    /**
     * The AC1 walk: install → enable → disable → re-enable → uninstall driven
     * from alternating workers; after EVERY mutation the peer worker's served
     * `GET /api/v1/themes` body must converge — all workers consistent inside
     * the budget, zero restarts, and the pooled bodies byte-identical.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_theme_registry_mutations_converge_across_two_resident_workers_without_restart(): void
    {
        $source = $this->requireComposerSource();

        // Two resident workers booted on an empty table (their onWorkerStart
        // bootstrapEnabled() has nothing to wire — like prod before the flow).
        $workerA = $this->worker();
        $workerB = $this->worker();

        // The pool simulation is only meaningful if the two workers really do
        // hold independent per-process registries — that IS the defect medium.
        $registryA = $workerA->get(ThemeSourceRegistry::class);
        $registryB = $workerB->get(ThemeSourceRegistry::class);
        $this->assertInstanceOf(ThemeSourceRegistry::class, $registryA);
        $this->assertNotSame(
            $registryA,
            $registryB,
            'two containers must own two registries, or this test simulates one worker, not the pool',
        );

        // Baseline: both workers serve the three built-ins, byte-identically.
        $this->assertSame(
            ['nocturne', 'daylight', 'midnight'],
            $this->servedThemeIds($workerA),
        );
        $this->assertSame(
            ['nocturne', 'daylight', 'midnight'],
            $this->servedThemeIds($workerB),
        );
        $baselineBody = $this->servedBody($workerA);
        $this->assertSame($baselineBody, $this->servedBody($workerB), 'baseline pool bodies byte-identical');

        // -- 1. INSTALL (worker A). Durable-only: enabled=false registers nothing.
        $loaderA = $workerA->get(PluginLoader::class);
        $loaderA->installFromDirectory($source);
        $this->converged([$workerA, $workerB], ['nocturne', 'daylight', 'midnight'], 'install');

        // -- 2. ENABLE (worker A). The W99 case: only A's process mutates in memory.
        $loaderA->enable('phlix-plugin-sample-theme');
        $expectedEnabled = ['nocturne', 'daylight', 'midnight', ...self::SAMPLE_IDS];

        // Drift planted, and pinned: worker B's RAW registry never saw the
        // enable. Without S498's per-request fleet check the SERVED body
        // would stay exactly this stale until B's process recycled.
        $this->assertSame(
            [],
            $registryB->ids(),
            'the per-process drift this step fixes must be present in the fixture — B must not have magically '
            . 'shared A\'s memory, or the test proves nothing',
        );

        $this->converged([$workerA, $workerB], $expectedEnabled, 'enable');
        $this->assertStringContainsString('#05060a', $this->servedBody($workerB), 'plugin tokens reach B');
        $this->assertSame(
            $this->servedBody($workerA),
            $this->servedBody($workerB),
            'enable: pooled bodies byte-identical',
        );

        // -- 3. DISABLE (worker B this time — the reverse fan-out).
        $workerB->get(PluginLoader::class)->disable('phlix-plugin-sample-theme');
        $this->assertSame(
            self::SAMPLE_IDS,
            $registryA->ids(),
            'drift planted again: B disabled locally but A\'s raw registry still holds the stale themes',
        );
        $this->converged([$workerA, $workerB], ['nocturne', 'daylight', 'midnight'], 'disable');
        $this->assertStringNotContainsString('#05060a', $this->servedBody($workerA));

        // -- 4. RE-ENABLE (worker B — whose loader ran the disable; a stale
        //      entryInstances cache makes PluginLoader::enable() early-return in
        //      A, pre-existing loader semantics untouched by S498).
        $workerB->get(PluginLoader::class)->enable('phlix-plugin-sample-theme');
        $this->assertSame(
            [],
            $registryA->ids(),
            'drift planted: A was flushed to empty by the disable and re-armed nothing while B enabled',
        );
        $this->converged([$workerA, $workerB], $expectedEnabled, 're-enable');

        // -- 5. UNINSTALL (worker A).
        $loaderA->uninstall('phlix-plugin-sample-theme');
        $this->assertSame(
            self::SAMPLE_IDS,
            $registryB->ids(),
            'drift planted: B still serves stale themes from memory after A uninstalled the plugin',
        );
        $this->converged([$workerA, $workerB], ['nocturne', 'daylight', 'midnight'], 'uninstall');

        // End state byte-identical to the pre-install baseline — the exact
        // W99 post-flush uniformity check (body sha equality), now reached
        // without the restart.
        $this->assertSame(
            $baselineBody,
            $this->servedBody($workerA),
            'post-uninstall A body must equal baseline byte-for-byte (no contract drift)',
        );
        $this->assertSame($baselineBody, $this->servedBody($workerB), 'post-uninstall B body must equal baseline');
    }

    /**
     * `GET /api/v1/themes` after each mutation: every worker's NEXT served
     * response agrees, inside the AC1 budget, with zero container rebuilds.
     *
     * @param list<ContainerInterface> $workers
     * @param list<string>             $expectedIds
     */
    private function converged(array $workers, array $expectedIds, string $phase): void
    {
        $deadline = hrtime(true) + (int) (self::CONVERGENCE_BUDGET_SECONDS * 1_000_000_000);
        $startedAt = hrtime(true);

        foreach ($workers as $index => $worker) {
            $observed = null;
            while (true) {
                $observed = $this->servedThemeIds($worker);
                if ($observed === $expectedIds) {
                    break;
                }
                if (hrtime(true) >= $deadline) {
                    $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000.0;
                    $this->fail(sprintf(
                        '[%s] worker %d still serves %s after %.1f ms (budget %d s, zero restarts) — %s',
                        $phase,
                        $index,
                        json_encode($observed),
                        $elapsedMs,
                        (int) self::CONVERGENCE_BUDGET_SECONDS,
                        'cross-worker registry flush is not taking effect',
                    ));
                }
                usleep(self::POLL_INTERVAL_MICROSECONDS);
            }
            $this->assertLessThan(
                self::CONVERGENCE_BUDGET_SECONDS * 1_000_000_000,
                hrtime(true) - $startedAt,
                sprintf(
                    '[%s] convergence must land inside the %d s budget',
                    $phase,
                    (int) self::CONVERGENCE_BUDGET_SECONDS,
                ),
            );
        }

        // Every worker agrees with every other worker — pooled byte equality.
        $first = $this->servedBody($workers[0]);
        foreach ($workers as $index => $worker) {
            $this->assertSame(
                $first,
                $this->servedBody($worker),
                sprintf('[%s] worker %d body diverges from worker 0', $phase, $index),
            );
        }
    }

    /**
     * The worker's OWN production-wired ThemesController (private prop on its
     * container-scoped WebPortalRouter) serving `GET /api/v1/themes`.
     */
    private function servedBody(ContainerInterface $container): string
    {
        $router = $container->get(WebPortalRouter::class);
        $this->assertInstanceOf(WebPortalRouter::class, $router);

        $property = new \ReflectionProperty($router, 'themesController');
        $controller = $property->getValue($router);
        $this->assertInstanceOf(
            ThemesController::class,
            $controller,
            'the production provider must wire a ThemesController for the theme endpoints',
        );

        return (string) $controller->index(new Request(), [])->body;
    }

    /**
     * @return list<string>
     */
    private function servedThemeIds(ContainerInterface $container): array
    {
        $decoded = json_decode($this->servedBody($container), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['themes'] ?? null);

        /** @var list<array<string, mixed>> $themes */
        $themes = $decoded['themes'];

        return array_map(static fn (array $t): string => (string) ($t['id'] ?? ''), $themes);
    }

    /**
     * Boot one resident "worker": its own container, its own mock Connection,
     * BOTH delegating to the single shared $fakeDb — the fork-sharing-a-schema
     * shape the real pool has. Unknown SQL answers as an empty set (workers
     * share more tables than `plugins` in prod; only the plugins table needs
     * real rows here).
     *
     */
    private function worker(): ContainerInterface
    {
        $table = $this->fakeDb;
        $connection = $this->createMock(Connection::class);
        $connection->method('query')->willReturnCallback(
            static function ($sql, $params = []) use ($table) {
                try {
                    return $table->handle((string) $sql, is_array($params) ? $params : []);
                } catch (\LogicException) {
                    return [];
                }
            },
        );

        $baseDir = $this->pluginsBaseDir;
        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($connection, $baseDir) implements ServiceProviderInterface {
            public function __construct(
                private readonly Connection $connection,
                private readonly string $pluginsBaseDir,
            ) {
            }

            public function register(\DI\ContainerBuilder $builder, array $appConfig): void
            {
                $conn = $this->connection;
                $base = $this->pluginsBaseDir;
                $builder->addDefinitions([
                    Connection::class => factory(static fn (): Connection => $conn),
                    PluginRepository::class => factory(
                        static fn (): PluginRepository => new PluginRepository($conn, $base),
                    ),
                    HttpInstaller::class => factory(
                        static fn (): HttpInstaller => new HttpInstaller($base),
                    ),
                ]);
            }
        };

        $container = ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'plugins_base_dir' => $this->pluginsBaseDir,
        ], $providers);

        // Resident-worker boot, faithfully: bootstrapEnabled() ran at
        // onWorkerStart — here on the empty/pre-state table, exactly once,
        // BEFORE the mutation flow.
        $container->get(PluginLoader::class)->bootstrapEnabled();

        return $container;
    }

    /**
     * Skip-with-rationale when composer is absent, then return the shipped
     * sample theme plugin source directory ({@see SampleThemeLifecycleTest}).
     */
    private function requireComposerSource(): string
    {
        if (trim((string) shell_exec('which composer 2>/dev/null')) === '') {
            $this->markTestSkipped(
                'composer binary not available on PATH - run in docker-compose for integration testing'
            );
        }

        $source = realpath(__DIR__ . '/../../../examples/plugins/phlix-plugin-sample-theme');
        $this->assertIsString($source, 'The shipped sample theme plugin must exist.');

        return $source;
    }
}
