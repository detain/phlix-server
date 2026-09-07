<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Plugins;

use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Plugins\Installer\HttpInstaller;
use Phlix\Plugins\PluginLoader;
use Phlix\Plugins\Repository\PluginRepository;
use Phlix\Plugins\Util\RecursiveDelete;
use Phlix\Server\Http\Controllers\ThemesController;
use Phlix\Server\Http\Request;
use Phlix\Theming\ThemeSourceRegistry;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

use function DI\factory;

// The in-memory `plugins` table this test needs is declared alongside the A.4
// lifecycle test. Required by path because it is a second class in a test FILE,
// so PSR-4 cannot autoload it and PHPUnit's own include order must not be
// relied on. `require_once` makes a double include a no-op.
require_once __DIR__ . '/InstallEnableDisableTest.php';

/**
 * S86 end-to-end: the SHIPPED sample theme plugin, installed and enabled through
 * the production {@see PluginLoader}, surfacing on `GET /api/v1/themes`.
 *
 * This is the test that makes "the sample plugin exercises the genuine
 * registration path" a fact rather than a claim. Nothing here is a double:
 *
 *  - the plugin is the real directory under `examples/plugins/`, copied and
 *    composer-installed by the real {@see HttpInstaller} / `ComposerRunner`;
 *  - the container is `ContainerFactory::defaultProviders()`, so the
 *    {@see ThemeSourceRegistry} the loader writes into is the same instance the
 *    {@see ThemesController} reads from — the exact wiring a second registry
 *    instance would break, silently, forever;
 *  - `enable()` and `disable()` are the production methods, so the S84
 *    `instanceof ThemeSourceInterface` arm is what registers the themes.
 *
 * Only the `plugins` TABLE is faked (in memory), so no live MySQL is needed;
 * that is the same compromise `InstallEnableDisableTest` makes.
 *
 * ## Why the test method is PROCESS-ISOLATED
 *
 * This test and `Phlix\Tests\Unit\Plugins\SampleThemePluginTest` both bring the
 * class `Phlix\PluginSampleTheme\SampleThemePlugin` into the process, but from
 * two DIFFERENT files: the unit test from the shipped
 * `examples/plugins/phlix-plugin-sample-theme/src/`, this one from the COPY the
 * installer wrote under a temp `plugins_base_dir`, via that copy's
 * composer-generated `vendor/autoload.php`. PHP's include guards key on the
 * resolved file PATH, so the second of the two to load fatals with
 * "Cannot declare class ... because the name is already in use" — and
 * `phpunit.xml` sets `executionOrder="random"`, so which one is second is not
 * fixed. (It only bites in a full-suite run; either suite alone loads it once.)
 *
 * Isolating this method is what keeps BOTH tests at full strength. Without it
 * the only non-fatal orderings are ones where the class is already declared
 * when the second test reaches it, and `class_exists()` then short-circuits —
 * so exactly one of the two loads would be silently skipped, at random. In its
 * own process this test really does exercise the installed copy's generated
 * autoloader every run, and the unit test really does load the shipped file
 * every run.
 */
final class SampleThemeLifecycleTest extends TestCase
{
    /** Ids the sample plugin contributes, in `providedThemes()` order. */
    private const SAMPLE_IDS = ['sample-dusk', 'sample-dusk-high-contrast'];

    /**
     * Guards the S166 autoload-chain invariant tests below: it reddens when a
     * composer ClassLoader registration survives disable()/uninstall().
     */
    private const AUTOLOAD_LEAK_TOKEN = 'S166LOADERX5K2';

    private string $pluginsBaseDir = '';
    private string $loggerConfigPath = '';
    private InMemoryPluginsTable $fakeDb;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        $this->pluginsBaseDir = sys_get_temp_dir() . '/phlix_theme_int_' . uniqid('', true);
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
     * @group integration
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_sample_theme_plugin_reaches_the_themes_endpoint_and_leaves_cleanly(): void
    {
        $source = $this->requireComposerSource();

        $container = $this->buildContainer();

        /** @var PluginLoader $loader */
        $loader = $container->get(PluginLoader::class);
        // Resolved from the CONTAINER, not constructed here: if the loader and
        // the controller ever stopped sharing one registry, this test would
        // still pass against a private instance — so it must not build one.
        /** @var ThemeSourceRegistry $registry */
        $registry = $container->get(ThemeSourceRegistry::class);

        // 1. Install the shipped directory.
        $manifest = $loader->installFromDirectory($source);
        $this->assertSame('phlix-plugin-sample-theme', $manifest->name);
        $this->assertSame('ui-theme', $manifest->type);
        $this->assertDirectoryExists($this->pluginsBaseDir . '/phlix-plugin-sample-theme');

        // Nothing is registered by installing — only by enabling.
        $this->assertSame([], $registry->ids());

        // 2. Enable — the S84 capability arm fires here.
        $loader->enable('phlix-plugin-sample-theme');
        $this->assertSame(self::SAMPLE_IDS, $registry->ids());
        $this->assertSame(['sample-theme'], $registry->sourceNames());

        // The entry class must have come from the INSTALLED COPY's generated
        // autoloader, not from the shipped `examples/` tree. This is the
        // assertion that keeps #[RunInSeparateProcess] honest: drop the
        // attribute and, in the orderings where the unit test ran first, the
        // class is already declared from `examples/` and PluginLoader's
        // `class_exists()` short-circuits — this test would then be silently
        // exercising nothing of the install. Here that becomes a loud red
        // instead of an invisible downgrade (or, in the other ordering, a
        // whole-suite "cannot declare class" abort).
        $entryFile = (new \ReflectionClass($manifest->entry))->getFileName();
        $this->assertIsString($entryFile);
        $this->assertStringStartsWith(
            $this->pluginsBaseDir . '/phlix-plugin-sample-theme/',
            $entryFile,
            'The entry class was loaded from outside the installed copy — process isolation is not in effect, '
            . 'so this test is no longer proving that the installed plugin autoloads.',
        );

        // 3. The endpoint the SPA reads now serves them, behind the built-ins.
        $listed = $this->listedThemeIds($container);
        $this->assertSame(['nocturne', 'daylight', 'midnight', ...self::SAMPLE_IDS], $listed);

        // 4. Disable — a symmetric cycle must leave nothing behind (resident
        //    worker: a registry that grew across enable/disable is a leak).
        $loader->disable('phlix-plugin-sample-theme');
        $this->assertSame([], $registry->ids());
        $this->assertSame([], $registry->sourceNames());
        $this->assertSame(['nocturne', 'daylight', 'midnight'], $this->listedThemeIds($container));

        // 5. Uninstall.
        $loader->uninstall('phlix-plugin-sample-theme');
        $this->assertDirectoryDoesNotExist($this->pluginsBaseDir . '/phlix-plugin-sample-theme');
    }

    /**
     * S166 — the full install → enable → disable → uninstall cycle must return
     * the `spl_autoload` chain to its exact pre-install state.
     *
     * `wire()` requires the installed copy's composer-generated
     * `vendor/autoload.php`, which registers a `ClassLoader` on the chain. In a
     * resident Workerman/Swoole worker that registration OUTLIVES the plugin:
     * after `uninstall()` deletes the directory, the dead loader is still
     * consulted on every future class-resolution miss, for the life of the
     * worker. The invariant is asserted on chain COUNT and callable IDENTITY
     * (per-callable object ids) — never on a stringified name, because
     * `[$classLoader, 'loadClass']` stringifies identically across generations.
     *
     * @group integration
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_autoload_chain_returns_to_baseline_across_full_lifecycle_cycle(): void
    {
        $source = $this->requireComposerSource();

        $container = $this->buildContainer();
        /** @var PluginLoader $loader */
        $loader = $container->get(PluginLoader::class);

        $baseline = $this->autoloadChainState();

        $loader->installFromDirectory($source);
        $this->assertSame(
            $baseline,
            $this->autoloadChainState(),
            self::AUTOLOAD_LEAK_TOKEN . ': installing alone must not register anything on the spl_autoload chain.',
        );

        $loader->enable('phlix-plugin-sample-theme');
        $afterEnable = $this->autoloadChainState();
        $this->assertGreaterThan(
            count($baseline),
            count($afterEnable),
            self::AUTOLOAD_LEAK_TOKEN . ': precondition broken - the installed copy\'s composer ClassLoader was never'
            . ' registered while enabled, so the cycle assertions below would measure nothing.',
        );

        $loader->disable('phlix-plugin-sample-theme');
        $this->assertSame(
            $baseline,
            $this->autoloadChainState(),
            self::AUTOLOAD_LEAK_TOKEN . ': disable() left a plugin composer ClassLoader on the spl_autoload chain.',
        );

        $loader->uninstall('phlix-plugin-sample-theme');
        $this->assertSame(
            $baseline,
            $this->autoloadChainState(),
            self::AUTOLOAD_LEAK_TOKEN . ': uninstall() left a dead composer ClassLoader on the spl_autoload chain'
            . ' for the life of the worker - the exact S166 leak.',
        );
    }

    /**
     * S166 — the lazy admin-API path registers the plugin autoloader WITHOUT
     * any enable cycle, so `uninstall()` must clean it up on its own, not only
     * via `disable()`.
     *
     * @group integration
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_lazy_get_entry_instance_registration_is_removed_by_uninstall(): void
    {
        $source = $this->requireComposerSource();

        $container = $this->buildContainer();
        /** @var PluginLoader $loader */
        $loader = $container->get(PluginLoader::class);

        $baseline = $this->autoloadChainState();

        $loader->installFromDirectory($source);
        $instance = $loader->getEntryInstance('phlix-plugin-sample-theme');
        $this->assertNotNull(
            $instance,
            self::AUTOLOAD_LEAK_TOKEN . ': getEntryInstance() must resolve the entry class through the installed'
            . ' copy\'s autoloader for this test to exercise the lazy path.',
        );
        $this->assertGreaterThan(
            count($baseline),
            count($this->autoloadChainState()),
            self::AUTOLOAD_LEAK_TOKEN . ': precondition broken - the lazy getEntryInstance() path registered no'
            . ' autoloader, so the uninstall assertion below would measure nothing.',
        );

        $loader->uninstall('phlix-plugin-sample-theme');
        $this->assertSame(
            $baseline,
            $this->autoloadChainState(),
            self::AUTOLOAD_LEAK_TOKEN . ': uninstall() after a lazy getEntryInstance() (no enable/disable cycle)'
            . ' left the plugin ClassLoader on the chain.',
        );
    }

    /**
     * S166 — re-enable in the same process must put the IDENTICAL captured
     * callables back on the chain.
     *
     * `require_once` never re-executes an already-included file, so after a
     * disable() detached the handles, enable() cannot rely on the require to
     * re-register them; only re-attaching the captured callables keeps plugin
     * classes that have not yet been autoloaded resolvable.
     *
     * @group integration
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_re_enable_reattaches_captured_autoloader_and_uninstall_leaves_chain_clean(): void
    {
        $source = $this->requireComposerSource();

        $container = $this->buildContainer();
        /** @var PluginLoader $loader */
        $loader = $container->get(PluginLoader::class);

        $baseline = $this->autoloadChainState();

        $loader->installFromDirectory($source);
        $loader->enable('phlix-plugin-sample-theme');
        $firstEnable = $this->autoloadChainState();
        $this->assertGreaterThan(
            count($baseline),
            count($firstEnable),
            self::AUTOLOAD_LEAK_TOKEN . ': enable() registered nothing.',
        );

        $loader->disable('phlix-plugin-sample-theme');
        $this->assertSame(
            $baseline,
            $this->autoloadChainState(),
            self::AUTOLOAD_LEAK_TOKEN . ': disable() leaked a loader.',
        );

        $loader->enable('phlix-plugin-sample-theme');
        $this->assertSame(
            $firstEnable,
            $this->autoloadChainState(),
            self::AUTOLOAD_LEAK_TOKEN . ': re-enable did not put the identical composer ClassLoader back on the'
            . ' chain - plugin classes not yet loaded would be unresolvable in a resident worker.',
        );
        $this->assertTrue(
            class_exists('Phlix\\PluginSampleTheme\\SampleThemePlugin'),
            self::AUTOLOAD_LEAK_TOKEN . ': the entry class must resolve after re-enable.',
        );

        $loader->uninstall('phlix-plugin-sample-theme');
        $this->assertSame(
            $baseline,
            $this->autoloadChainState(),
            self::AUTOLOAD_LEAK_TOKEN . ': uninstall() after a re-enable cycle left a ClassLoader on the chain.',
        );
    }

    /**
     * Skip-with-rationale when composer is absent, then return the absolute
     * path of the shipped sample theme plugin source directory.
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

    /**
     * Snapshot of the `spl_autoload` chain as identity keys, order-normalised.
     *
     * Each callable is keyed by object identity (`spl_object_id` of the loader
     * instance + method), never by a string name that two ClassLoader
     * generations would share.
     *
     * @return list<string>
     */
    private function autoloadChainState(): array
    {
        $state = [];
        foreach (spl_autoload_functions() ?: [] as $callable) {
            if (is_object($callable)) {
                $state[] = 'object:' . spl_object_id($callable);
                continue;
            }
            if (is_string($callable)) {
                $state[] = 'function:' . $callable;
                continue;
            }
            $target = $callable[0];
            $state[] = (is_object($target) ? 'object:' . spl_object_id($target) : 'class:' . $target)
                . '::' . (string) $callable[1];
        }
        sort($state);

        return $state;
    }

    /**
     * Ids served by `GET /api/v1/themes`, in response order.
     *
     * @return list<mixed>
     */
    private function listedThemeIds(\Psr\Container\ContainerInterface $container): array
    {
        /** @var ThemeSourceRegistry $registry */
        $registry = $container->get(ThemeSourceRegistry::class);
        $body = (new ThemesController($registry))->index(new Request(), [])->body;

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['themes'] ?? null);

        /** @var list<array<string, mixed>> $themes */
        $themes = $decoded['themes'];
        return array_map(static fn (array $t): mixed => $t['id'] ?? null, $themes);
    }

    private function buildContainer(): \Psr\Container\ContainerInterface
    {
        $fakeConn = $this->createMock(Connection::class);
        $table = $this->fakeDb;
        $fakeConn->method('query')
            ->willReturnCallback(static function ($sql, $params = null) use ($table) {
                return $table->handle((string) $sql, is_array($params) ? $params : []);
            });

        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($fakeConn, $this->pluginsBaseDir) implements ServiceProviderInterface {
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

        return ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'plugins_base_dir' => $this->pluginsBaseDir,
        ], $providers);
    }
}
