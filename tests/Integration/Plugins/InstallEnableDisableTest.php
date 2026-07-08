<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Plugins;

use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Plugins\Installer\HttpInstaller;
use Phlix\Plugins\PluginLoader;
use Phlix\Plugins\Repository\PluginRepository;
use Phlix\Plugins\Util\RecursiveDelete;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * End-to-end exercise of the plugin lifecycle against the fixture plugin
 * at `tests/Fixtures/Plugins/fixture-plugin/`.
 *
 * The test stages the fixture into a temp `var/plugins/` (so production
 * data is untouched), runs `installFromDirectory()` → `enable()`,
 * dispatches a real PSR-14 {@see PlaybackStarted}, asserts the fixture
 * plugin's listener counter incremented, then disables and asserts the
 * counter stops moving, then uninstalls and asserts the install dir is
 * removed.
 *
 * The DB layer is faked in-memory by {@see InMemoryPluginsTable}; we
 * don't require a live MySQL instance.
 */
final class InstallEnableDisableTest extends TestCase
{
    private string $pluginsBaseDir = '';
    private string $loggerConfigPath = '';
    private InMemoryPluginsTable $fakeDb;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        $this->pluginsBaseDir = sys_get_temp_dir() . '/phlix_pl_int_' . uniqid('', true);
        mkdir($this->pluginsBaseDir, 0775, true);

        // A throwaway logger config that points at the tmp dir.
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
    public function test_full_lifecycle_with_fixture_plugin(): void
    {
        if (trim((string) shell_exec('which composer 2>/dev/null')) === '') {
            $this->markTestSkipped('composer binary not available on PATH - run in docker-compose for integration testing');
        }

        $fixtureSource = realpath(__DIR__ . '/../../Fixtures/Plugins/fixture-plugin');
        $this->assertIsString($fixtureSource);

        $fakeConn = $this->buildFakeConnection();

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

        $appConfig = [
            'logger_config_path' => $this->loggerConfigPath,
            'plugins_base_dir' => $this->pluginsBaseDir,
        ];
        $container = ContainerFactory::create($appConfig, $providers);

        /** @var PluginLoader $loader */
        $loader = $container->get(PluginLoader::class);
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get(EventDispatcherInterface::class);

        // 1. Install
        $manifest = $loader->installFromDirectory($fixtureSource);
        $this->assertSame('phlix-plugin-fixture', $manifest->name);

        $installedDir = $this->pluginsBaseDir . '/phlix-plugin-fixture';
        $this->assertDirectoryExists($installedDir);
        $this->assertFileExists($installedDir . '/vendor/autoload.php');

        // 2. Enable
        $loader->enable('phlix-plugin-fixture');

        // 3. Dispatch one event — listener should fire.
        $entryClass = 'Phlix\\Tests\\Fixtures\\Plugins\\FixturePlugin\\FixturePlugin';
        $this->assertTrue(class_exists($entryClass), 'Fixture plugin entry class must load.');
        $instance = $container->get($entryClass);
        // The concrete fixture class is excluded from the classmap, so narrow to
        // object and read its public properties via get_object_vars() for analysis.
        $this->assertIsObject($instance);
        $this->assertObjectHasProperty('playbackStartedCount', $instance);

        $dispatcher->dispatch(new PlaybackStarted('sess', 'user', 'item', 'dev', 0));
        $this->assertSame(1, get_object_vars($instance)['playbackStartedCount']);

        // 4. Disable — listener should stop firing.
        $loader->disable('phlix-plugin-fixture');
        $dispatcher->dispatch(new PlaybackStarted('sess', 'user', 'item', 'dev', 0));
        $this->assertSame(1, get_object_vars($instance)['playbackStartedCount'], 'Listener should be removed after disable.');
        $this->assertSame(true, get_object_vars($instance)['onDisableCalled']);

        // 5. Uninstall — directory disappears, DB row gone.
        $loader->uninstall('phlix-plugin-fixture');
        $this->assertDirectoryDoesNotExist($installedDir);
        $this->assertCount(0, $loader->listInstalled());
    }

    private function buildFakeConnection(): Connection
    {
        $fakeConn = $this->createMock(Connection::class);
        $table = $this->fakeDb;
        $fakeConn->method('query')
            ->willReturnCallback(static function ($sql, $params = null) use ($table) {
                return $table->handle((string) $sql, is_array($params) ? $params : []);
            });
        return $fakeConn;
    }
}

/**
 * Stupid-simple in-memory `plugins` table used by the integration test.
 * Mirrors only the SQL shapes that {@see PluginRepository} uses.
 *
 * @internal
 */
final class InMemoryPluginsTable
{
    /** @var array<string, array<string, mixed>> Indexed by name. */
    private array $rows = [];

    /**
     * Coerce a bound SQL parameter (always a scalar or null at runtime) to a
     * string, so it can be used as an array key without casting `mixed`.
     */
    private static function toStr(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<int, mixed> $params
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function handle(string $sql, array $params)
    {
        $normalized = strtoupper(trim(preg_replace('/\s+/', ' ', $sql) ?? $sql));

        if (str_starts_with($normalized, 'INSERT INTO PLUGINS')) {
            [$id, $name, $version, $type, $entry, $enabled, $installedAt, $settingsJson, $manifestJson] = $params;
            $this->rows[self::toStr($name)] = [
                'id' => $id,
                'name' => $name,
                'version' => $version,
                'type' => $type,
                'entry' => $entry,
                'enabled' => self::toInt($enabled),
                'installed_at' => $installedAt,
                'settings_json' => $settingsJson,
                'manifest_json' => $manifestJson,
            ];
            return null;
        }

        if (str_starts_with($normalized, 'SELECT 1 FROM PLUGINS WHERE NAME')) {
            return isset($this->rows[self::toStr($params[0])]) ? [[1]] : [];
        }

        if (
            str_starts_with($normalized, 'SELECT ID, NAME, VERSION, TYPE, ENTRY, ENABLED, INSTALLED_AT, SETTINGS_JSON, MANIFEST_JSON FROM PLUGINS WHERE NAME')
        ) {
            $name = self::toStr($params[0]);
            return isset($this->rows[$name]) ? [$this->rows[$name]] : [];
        }

        if (str_starts_with($normalized, 'SELECT ID, NAME, VERSION, TYPE, ENTRY, ENABLED, INSTALLED_AT, SETTINGS_JSON, MANIFEST_JSON FROM PLUGINS WHERE ENABLED')) {
            return array_values(array_filter($this->rows, static fn ($row) => $row['enabled'] === 1));
        }

        if (str_starts_with($normalized, 'SELECT ID, NAME, VERSION, TYPE, ENTRY, ENABLED, INSTALLED_AT, SETTINGS_JSON, MANIFEST_JSON FROM PLUGINS')) {
            return array_values($this->rows);
        }

        if (str_starts_with($normalized, 'UPDATE PLUGINS SET ENABLED')) {
            $name = self::toStr($params[1]);
            if (isset($this->rows[$name])) {
                $this->rows[$name]['enabled'] = self::toInt($params[0]);
            }
            return null;
        }

        if (str_starts_with($normalized, 'UPDATE PLUGINS SET SETTINGS_JSON')) {
            $name = self::toStr($params[1]);
            if (isset($this->rows[$name])) {
                $this->rows[$name]['settings_json'] = self::toStr($params[0]);
            }
            return null;
        }

        if (str_starts_with($normalized, 'DELETE FROM PLUGINS')) {
            unset($this->rows[self::toStr($params[0])]);
            return null;
        }

        throw new \LogicException('InMemoryPluginsTable does not handle: ' . $sql);
    }
}
