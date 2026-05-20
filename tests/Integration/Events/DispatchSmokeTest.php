<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Events;

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Events\ListenerRegistry;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Session\PlaybackController;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * End-to-end smoke test: wire the real container, register a spy
 * listener via {@see ListenerRegistry}, drive
 * {@see PlaybackController::reportProgress()} (which the production code
 * uses to publish lifecycle events), and assert the spy was invoked.
 *
 * Satisfies the §0.4 integration-boundary requirement for the events
 * subsystem.
 *
 * @covers \Phlix\Common\Events\EventDispatcherFactory
 * @covers \Phlix\Common\Events\ListenerRegistry
 * @covers \Phlix\Common\Container\Providers\EventServicesProvider
 */
final class DispatchSmokeTest extends TestCase
{
    private string $tempDir = '';
    private string $loggerConfigPath = '';
    private string $serverConfigPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        $this->tempDir = sys_get_temp_dir() . '/phlix_events_smoke_' . uniqid('', true);
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

    public function test_playback_started_dispatch_reaches_listener(): void
    {
        // Mock the MySQL connection: first call (status lookup) returns
        // empty (no prior state), second call (the upsert) returns no
        // rows. Subsequent SessionManager::updateActivity / getSession
        // calls also return whatever is set up below.
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('query')->willReturnCallback(
            function (string $sql, array $params = []) {
                if (str_starts_with(trim($sql), 'SELECT * FROM playback_state')) {
                    return []; // no previous state -> "started"
                }
                if (str_starts_with(trim($sql), 'SELECT * FROM sessions')) {
                    return [['id' => $params[0], 'user_id' => 'u-1', 'device_id' => 'd-1']];
                }
                return [];
            }
        );

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

        /** @var ListenerRegistry $registry */
        $registry = $container->get(ListenerRegistry::class);
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get(EventDispatcherInterface::class);
        /** @var PlaybackController $controller */
        $controller = $container->get(PlaybackController::class);

        $this->assertNotNull($dispatcher);

        $captured = [];
        $registry->subscribe(PlaybackStarted::class, function (PlaybackStarted $e) use (&$captured): void {
            $captured[] = $e;
        });

        $controller->reportProgress(
            sessionId: 'sess-1',
            mediaItemId: 'media-1',
            positionTicks: 0,
            durationTicks: 1000,
            isPaused: false,
        );

        $this->assertCount(1, $captured, 'spy listener should have fired once');
        $this->assertSame('sess-1', $captured[0]->sessionId);
        $this->assertSame('media-1', $captured[0]->mediaItemId);
        $this->assertSame('u-1', $captured[0]->userId);
        $this->assertSame('d-1', $captured[0]->deviceId);
        $this->assertSame(0, $captured[0]->positionTicks);
    }
}
