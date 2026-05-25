<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Container;

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Core\Application;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * Bootstrap integration: exercise the public/index.php replacement logic
 * against an in-memory configuration to confirm Application can stand up
 * without throwing.
 *
 * The test does NOT depend on a live database; the Workerman Connection is
 * rebound to a PHPUnit mock through an extra provider, matching the
 * pattern used by the unit tests.
 */
final class BootstrapTest extends TestCase
{
    private string $tempDir = '';
    private string $loggerConfigPath = '';
    private string $serverConfigPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        $this->tempDir = sys_get_temp_dir() . '/phlix_bootstrap_' . uniqid('', true);
        mkdir($this->tempDir, 0775, true);

        $loggerConfig = "<?php\nreturn [\n"
            . "    'default' => 'file',\n"
            . "    'handlers' => [\n"
            . "        'file' => [\n"
            . "            'type' => 'stream',\n"
            . "            'path' => " . var_export($this->tempDir . '/app.log', true) . ",\n"
            . "            'level' => 'debug',\n"
            . "        ],\n"
            . "    ],\n"
            . "];\n";
        $this->loggerConfigPath = $this->tempDir . '/logger.php';
        file_put_contents($this->loggerConfigPath, $loggerConfig);

        $serverConfig = "<?php\nreturn [\n"
            . "    'server' => ['name' => 'Test Server'],\n"
            . "    'logger_config_path' => " . var_export($this->loggerConfigPath, true) . ",\n"
            . "    'db_config_path' => null,\n"
            . "];\n";
        $this->serverConfigPath = $this->tempDir . '/server.php';
        file_put_contents($this->serverConfigPath, $serverConfig);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        LoggerFactory::reset();
        $entries = glob($this->tempDir . '/*') ?: [];
        foreach ($entries as $entry) {
            @unlink($entry);
        }
        @rmdir($this->tempDir);
    }

    public function test_container_resolves_application_with_mocked_connection(): void
    {
        // The Application constructor eagerly resolves controller factories
        // (e.g. getMusicController -> createDatabaseConnection), which build a
        // real Workerman\MySQL\Connection that connects in its constructor —
        // bypassing the container-bound mock below. So this smoke test still
        // needs a reachable MySQL. CI provides one as a service container;
        // skip locally when the host doesn't.
        if (!$this->isMysqlReachable('127.0.0.1', 3306)) {
            $this->markTestSkipped('No MySQL on 127.0.0.1:3306 — skipping Application bootstrap test. Run in docker-compose for integration testing.');
        }

        $mockConnection = $this->createMock(Connection::class);

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

        $config = include $this->serverConfigPath;
        $container = ContainerFactory::create($config, $providers);

        $this->assertInstanceOf(ContainerInterface::class, $container);

        $application = new Application($container, $config);
        $this->assertNotNull($application->getRouter());
        $this->assertSame($container, $application->getContainer());

        // Resolving a few canonical bindings end-to-end should not throw.
        $this->assertSame($mockConnection, $container->get(Connection::class));
        $this->assertNotNull($container->get(\Phlix\Auth\AuthManager::class));
        $this->assertNotNull($container->get(\Phlix\Media\Library\LibraryManager::class));
        $this->assertNotNull($container->get(\Phlix\Session\PlaybackController::class));
    }

    public function test_fromConfigPath_constructs_application(): void
    {
        // fromConfigPath wires the real DB stack; the Application constructor
        // eagerly resolves controller factories that open a live connection.
        // Needs a reachable MySQL (CI service container); skip otherwise.
        if (!$this->isMysqlReachable('127.0.0.1', 3306)) {
            $this->markTestSkipped('No MySQL on 127.0.0.1:3306 — skipping Application bootstrap test. Run in docker-compose for integration testing.');
        }

        // We can't supply a mock connection through fromConfigPath, but we
        // can verify it doesn't blow up before lazy-resolving the DB.
        $app = Application::fromConfigPath($this->serverConfigPath);
        $this->assertNotNull($app->getContainer());
    }

    private function isMysqlReachable(string $host, int $port): bool
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if ($sock === false) {
            return false;
        }
        fclose($sock);
        return true;
    }
}
