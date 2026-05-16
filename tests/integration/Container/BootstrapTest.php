<?php

declare(strict_types=1);

namespace Phlex\Tests\Integration\Container;

use DI\ContainerBuilder;
use Phlex\Common\Container\ContainerFactory;
use Phlex\Common\Container\ServiceProviderInterface;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Server\Core\Application;
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

        $this->tempDir = sys_get_temp_dir() . '/phlex_bootstrap_' . uniqid('', true);
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
        $this->assertNotNull($container->get(\Phlex\Auth\AuthManager::class));
        $this->assertNotNull($container->get(\Phlex\Media\Library\LibraryManager::class));
        $this->assertNotNull($container->get(\Phlex\Session\PlaybackController::class));
    }

    public function test_fromConfigPath_constructs_application(): void
    {
        // We can't supply a mock connection through fromConfigPath, but we
        // can verify it doesn't blow up before lazy-resolving the DB.
        $app = Application::fromConfigPath($this->serverConfigPath);
        $this->assertNotNull($app->getContainer());
    }
}
