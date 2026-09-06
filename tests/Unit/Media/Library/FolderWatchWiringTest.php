<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Library\FolderWatchScheduler;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Media\Library\LibraryManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * CONSEQUENCE guard: the scheduler being correct is worthless if nothing starts
 * it, if the container hands it a dispatcher-less watcher, or if the config flag
 * never reaches it.
 *
 * That is exactly how this feature died the first time.
 * `FolderWatcher::checkForChanges()` was complete, tested and container-bound —
 * and had NO caller anywhere in the repo, so `LibraryUpdated` was never
 * dispatched in production. `start.php` cannot be exercised without booting
 * Workerman, so the call site is asserted against its source, deliberately: the
 * failure being guarded is "the call site was never added", which no runtime
 * test in this suite can observe.
 */
final class FolderWatchWiringTest extends TestCase
{
    /** @var string Path to an isolated logger config that writes to a temp dir. */
    private string $loggerConfigPath = '';

    /** @var string Temp directory backing $loggerConfigPath. */
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        $this->tempDir = sys_get_temp_dir() . '/phlix_fw_wiring_' . uniqid('', true);
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
        // S439: the container graph this test resolves constructs MediaAssetJobStore
        // and SimilarityJobStore through MediaServicesProvider's factories at the
        // production default queue paths, and their constructors mint the shared
        // /tmp directories. Sweep them so the suite leaves zero residue.
        foreach (['phlix_media_asset_jobs', 'phlix_similarity_jobs'] as $sharedQueue) {
            $sharedDir = sys_get_temp_dir() . '/' . $sharedQueue;
            if (is_dir($sharedDir)) {
                foreach (glob($sharedDir . '/*') ?: [] as $queued) {
                    @unlink($queued);
                }
                @rmdir($sharedDir);
            }
        }
        parent::tearDown();
        LoggerFactory::reset();

        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    private function startPhp(): string
    {
        $source = file_get_contents($this->repoRoot() . '/start.php');
        $this->assertIsString($source, 'start.php must be readable');

        return $source;
    }

    // -------------------------------------------------------------------
    // The call site
    // -------------------------------------------------------------------

    /**
     * The daemon must CALL start() on the scheduler. Match the invocation, not a
     * mention — an explanatory comment naming the class would otherwise keep
     * this green after the call site was deleted.
     */
    public function test_daemon_starts_the_folder_watch_scheduler(): void
    {
        $this->assertMatchesRegularExpression(
            '/get\(\s*\\\\Phlix\\\\Media\\\\Library\\\\FolderWatchScheduler::class\s*\)\s*'
            . '\R?\s*->start\(/',
            $this->startPhp(),
            'start.php must resolve FolderWatchScheduler and CALL ->start() on it. Without '
            . 'that call FolderWatcher::checkForChanges() has no caller again and '
            . 'LibraryUpdated is never dispatched — the exact defect this wiring fixes.'
        );
    }

    /**
     * It must be started in the `library-scan` managed worker and NOWHERE else.
     *
     * Two independent reasons: PSR-14 dispatch is per-process and the only
     * LibraryUpdated listener (SmartPlaylistRefreshSubscriber) is registered
     * only there, so anywhere else the event would reach nobody; and a change
     * check is a blocking recursive stat() walk that would stall every
     * concurrent connection on an HTTP worker.
     */
    public function test_the_scheduler_is_wired_only_inside_the_library_scan_worker(): void
    {
        $source = $this->startPhp();

        $this->assertSame(
            1,
            substr_count($source, 'FolderWatchScheduler::class'),
            'The scheduler must be resolved exactly ONCE in start.php. A second call site '
            . '(e.g. an HTTP worker onWorkerStart) would run blocking directory walks on an '
            . 'HTTP worker\'s event loop.'
        );

        $gate = strpos($source, "\$procKey === 'library-scan'");
        $this->assertIsInt($gate, "start.php must gate managed-worker wiring on 'library-scan'");

        $wiring = strpos($source, 'FolderWatchScheduler::class');
        $this->assertIsInt($wiring);
        $this->assertGreaterThan(
            $gate,
            $wiring,
            'The scheduler must be wired INSIDE the library-scan gate.'
        );

        $workerStart = strpos($source, '$managed->start($pollSeconds);');
        $this->assertIsInt($workerStart, 'start.php must start the managed worker');
        $this->assertLessThan(
            $workerStart,
            $wiring,
            'The scheduler must be armed before the worker starts polling.'
        );
    }

    // -------------------------------------------------------------------
    // Silent degradation
    // -------------------------------------------------------------------

    /**
     * The boot wiring is wrapped in a `catch (\Throwable)` that only LOGS, so an
     * unresolvable scheduler degrades SILENTLY back to "nothing is ever
     * watched". This resolves it exactly the way `start.php` does, from the real
     * provider set.
     *
     * It also asserts the resolved watcher carries an event dispatcher.
     * `FolderWatcher::dispatchLibraryUpdated()` opens with
     * `if ($this->eventDispatcher === null) { return; }`, and `eventDispatcher`
     * is a defaulted ctor param — which PHP-DI's `autowire()` SKIPS — so a
     * watcher built without that explicit binding would walk every directory on
     * schedule and dispatch nothing at all.
     */
    public function test_the_scheduler_resolves_from_the_production_container(): void
    {
        $container = $this->containerWithMockedDb();

        $scheduler = $container->get(FolderWatchScheduler::class);
        $this->assertInstanceOf(FolderWatchScheduler::class, $scheduler);

        $watcher = $this->readPrivate($scheduler, 'watcher');
        $this->assertInstanceOf(FolderWatcher::class, $watcher);
        $this->assertInstanceOf(
            EventDispatcherInterface::class,
            $this->readPrivate($watcher, 'eventDispatcher'),
            'A watcher resolved without an event dispatcher silently swallows every '
            . 'LibraryUpdated, so the whole wiring would have no effect.'
        );

        $this->assertInstanceOf(
            LibraryManager::class,
            $this->readPrivate($scheduler, 'libraries'),
            'The scheduler needs the library list: FolderWatcher::watch() is otherwise '
            . 'only called by LibraryManager::createLibrary(), in an HTTP worker, so a '
            . 'managed worker would hold an empty watch list forever.'
        );
    }

    // -------------------------------------------------------------------
    // The flag has to be both OFF by default and actually reachable
    // -------------------------------------------------------------------

    public function test_folder_watching_ships_disabled(): void
    {
        $config = require $this->repoRoot() . '/config/folder_watch.php';

        $this->assertIsArray($config);
        $this->assertFalse(
            $config['enabled'],
            'config/folder_watch.php must ship enabled => false: a change check is a '
            . 'blocking recursive stat() walk on the library-scan worker.'
        );
    }

    /**
     * A container built the way `start.php` builds it — with no `folder_watch`
     * key at all — must produce a DISABLED scheduler.
     */
    public function test_absent_config_leaves_the_scheduler_disabled(): void
    {
        $scheduler = $this->containerWithMockedDb()->get(FolderWatchScheduler::class);
        $this->assertInstanceOf(FolderWatchScheduler::class, $scheduler);

        $this->assertFalse($scheduler->isEnabled());
    }

    /**
     * The flag must be REACHABLE, not merely present: `enabled` and
     * `intervalSeconds` are optional ctor params and PHP-DI skips optional
     * params during autowiring, so an unbound one would pin the feature to its
     * default no matter what the operator configured.
     */
    public function test_configured_values_reach_the_scheduler(): void
    {
        $scheduler = $this->containerWithMockedDb([
            'folder_watch' => ['enabled' => true, 'interval_seconds' => 42],
        ])->get(FolderWatchScheduler::class);
        $this->assertInstanceOf(FolderWatchScheduler::class, $scheduler);

        $this->assertTrue($scheduler->isEnabled());
        $this->assertSame(42, $this->readPrivate($scheduler, 'intervalSeconds'));
    }

    /**
     * ...and the config file has to be composed into the app config, or the key
     * the provider reads is permanently absent and the operator's setting is
     * unreachable however it is written.
     */
    public function test_server_config_composes_the_folder_watch_file(): void
    {
        $server = require $this->repoRoot() . '/config/server.php';

        $this->assertIsArray($server);
        $this->assertArrayHasKey(
            'folder_watch',
            $server,
            'config/server.php must compose config/folder_watch.php, or '
            . 'MediaServicesProvider reads an absent key and the flag can never be turned on.'
        );
        $this->assertIsArray($server['folder_watch']);
        $this->assertArrayHasKey('enabled', $server['folder_watch']);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /**
     * Build the canonical production container with the MySQL connection
     * rebound to a mock, mirroring
     * {@see \Phlix\Tests\Unit\Playlists\SmartPlaylistRefreshWiringTest}.
     *
     * @param array<string, mixed> $extraConfig Merged over the base app config.
     */
    private function containerWithMockedDb(array $extraConfig = []): ContainerInterface
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

        return ContainerFactory::create(array_merge([
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ], $extraConfig), $providers);
    }

    private function readPrivate(object $target, string $property): mixed
    {
        $prop = (new ReflectionClass($target))->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($target);
    }
}
