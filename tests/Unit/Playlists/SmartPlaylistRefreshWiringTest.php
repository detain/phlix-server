<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Playlists;

use DI\ContainerBuilder;
use Phlix\Collections\CollectionManager;
use Phlix\Collections\CollectionRepository;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Playlists\SmartPlaylistRefreshHandler;
use Phlix\Playlists\SmartPlaylistRefreshSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * CONSEQUENCE guard: the subscriber being correct is worthless if nothing
 * registers it.
 *
 * That is exactly how this feature died the first time —
 * {@see SmartPlaylistRefreshHandler} was complete, tested, and container-bound,
 * but `register()` had no caller anywhere in the repo, so a smart COLLECTION
 * never refreshed its stored membership after a scan. (A smart PLAYLIST has no
 * stored membership; it is evaluated on request. The collection refresh is the
 * only production behaviour this wiring changes.) `start.php` cannot be
 * exercised without booting Workerman, so this asserts against its source,
 * deliberately: the failure being guarded is "the call site was never added",
 * which no runtime test in this suite can observe.
 *
 * @covers \Phlix\Common\Container\Providers\MediaServicesProvider
 */
final class SmartPlaylistRefreshWiringTest extends TestCase
{
    /** @var string Path to an isolated logger config that writes to a temp dir. */
    private string $loggerConfigPath = '';

    /** @var string Temp directory backing $loggerConfigPath. */
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        $this->tempDir = sys_get_temp_dir() . '/phlix_sp_wiring_' . uniqid('', true);
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
        parent::tearDown();
        LoggerFactory::reset();

        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    private function startPhp(): string
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/start.php');
        $this->assertIsString($source, 'start.php must be readable');

        return $source;
    }

    /**
     * The daemon must CALL register() on the subscriber. Match the invocation,
     * not a mention — an explanatory comment naming the class would otherwise
     * keep this green after the call site was deleted.
     */
    public function test_daemon_registers_the_smart_playlist_refresh_subscriber(): void
    {
        $this->assertMatchesRegularExpression(
            '/get\(\s*\\\\Phlix\\\\Playlists\\\\SmartPlaylistRefreshSubscriber::class\s*\)\s*'
            . '\R?\s*->register\(/',
            $this->startPhp(),
            'start.php must resolve SmartPlaylistRefreshSubscriber and CALL ->register() on '
            . 'it. Without that call the handler is never subscribed and the collections '
            . 'linked to a smart playlist never refresh their membership after a scan — the '
            . 'exact defect this wiring fixes.'
        );
    }

    /**
     * It must be registered in the `library-scan` managed worker — the process
     * that dispatches LibraryScanCompleted — and NOWHERE else.
     *
     * A refresh is O(library size × linked collections) blocking DB round-trips
     * — ONE linked collection already walks the whole library in 500-row batches
     * and then writes the membership diff. Registered in an HTTP worker it would
     * stall every concurrent connection on that worker the moment the event
     * fired there.
     */
    public function test_the_subscriber_is_wired_only_inside_the_library_scan_worker(): void
    {
        $source = $this->startPhp();

        $this->assertSame(
            1,
            substr_count($source, 'SmartPlaylistRefreshSubscriber::class'),
            'The subscriber must be resolved exactly ONCE in start.php. A second call site '
            . '(e.g. an HTTP worker onWorkerStart) would run a whole-library refresh on an '
            . 'HTTP worker\'s event loop.'
        );

        $gate = strpos($source, "\$procKey === 'library-scan'");
        $this->assertIsInt($gate, "start.php must gate managed-worker wiring on 'library-scan'");

        $wiring = strpos($source, 'SmartPlaylistRefreshSubscriber::class');
        $this->assertIsInt($wiring);
        $this->assertGreaterThan(
            $gate,
            $wiring,
            'The subscriber must be wired INSIDE the library-scan gate.'
        );

        $workerStart = strpos($source, '$managed->start($pollSeconds);');
        $this->assertIsInt($workerStart, 'start.php must start the managed worker');
        $this->assertLessThan(
            $workerStart,
            $wiring,
            'The subscriber must be registered before the worker starts polling, or the '
            . 'first drained scan job dispatches its events into an empty registry.'
        );
    }

    /**
     * The boot wiring above is wrapped in a `catch (\Throwable)` that only
     * LOGS, so an unresolvable subscriber degrades SILENTLY back to "no smart
     * collection ever refreshes" — the very defect being fixed. This resolves it
     * exactly the way `start.php` does, from the real provider set.
     *
     * It also asserts the resolved handler carries its optional
     * `collectionManager` / `collectionRepo`: those are defaulted ctor params,
     * so a bare `autowire()` SKIPS them and the handler's own
     * `if ($this->collectionManager === null) { return; }` guard turns the
     * refresh into a silent no-op — and since the collection refresh is the ONLY
     * thing the handler does, the whole wiring would then have no effect at all.
     */
    public function test_the_subscriber_resolves_from_the_production_container(): void
    {
        $container = $this->containerWithMockedDb();

        $subscriber = $container->get(SmartPlaylistRefreshSubscriber::class);
        $this->assertInstanceOf(SmartPlaylistRefreshSubscriber::class, $subscriber);

        $handler = $this->readPrivate($subscriber, 'handler');
        $this->assertInstanceOf(
            SmartPlaylistRefreshHandler::class,
            $handler,
            'The subscriber must be built with the container\'s refresh handler.'
        );

        $this->assertInstanceOf(
            CollectionManager::class,
            $this->readPrivate($handler, 'collectionManager'),
            'A handler resolved without a CollectionManager silently skips every '
            . 'smart-collection refresh.'
        );
        $this->assertInstanceOf(
            CollectionRepository::class,
            $this->readPrivate($handler, 'collectionRepo')
        );
    }

    /**
     * Build the canonical production container with the MySQL connection
     * rebound to a mock, mirroring {@see \Phlix\Tests\Unit\Common\Container\ContainerFactoryTest}.
     */
    private function containerWithMockedDb(): ContainerInterface
    {
        $mockConnection = $this->createMock(Connection::class);

        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($mockConnection) implements ServiceProviderInterface {
            public function __construct(private Connection $connection)
            {
            }

            /**
             * @param ContainerBuilder<\DI\Container> $builder
             * @param array<string, mixed>            $appConfig
             */
            public function register(ContainerBuilder $builder, array $appConfig): void
            {
                $connection = $this->connection;
                $builder->addDefinitions([
                    Connection::class => factory(static fn (): Connection => $connection),
                ]);
            }
        };

        return ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ], $providers);
    }

    private function readPrivate(object $target, string $property): mixed
    {
        $prop = (new ReflectionClass($target))->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($target);
    }
}
