<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container;

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Library\LibraryScanWorker;
use Phlix\Media\MediaAsset\MediaAssetBackfill;
use Phlix\Media\MediaAsset\MediaAssetJobStore;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * S284 — the PRODUCTION container really injects the media-asset backfill.
 *
 * ## The specific way this can be silently wrong
 *
 * `MediaServicesProvider` registers {@see LibraryScanWorker} with PHP-DI's
 * `autowire()`, and `autowire()` SKIPS constructor parameters that carry a
 * default. `?MediaAssetBackfill $mediaAssetBackfill = null` is exactly that
 * shape, so without a matching `->constructorParameter(...)` the dependency
 * resolves to **null** — with no error, no warning and every unit test still
 * green, because the unit tests construct the worker directly and pass it in.
 * The estate has been bitten by this before ([[project_di_provider_silent_degradation]]);
 * the sibling checks in `ContainerFactoryTest` for `MediaScanner`'s two job
 * stores exist for the same reason.
 *
 * The observable damage would be that every `media_assets` job fails at runtime
 * and no library ever acquires trickplay artefacts — i.e. the exact condition
 * S284 exists to end, restored silently.
 *
 * The container is the REAL provider stack (`ContainerFactory::defaultProviders()`,
 * what `public/index.php` and the Workerman daemon use), with only the MySQL
 * {@see Connection} rebound to a mock.
 */
final class MediaAssetBackfillWiringTest extends TestCase
{
    private string $loggerConfigPath = '';
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        $this->tempDir = sys_get_temp_dir() . '/phlix_s284_container_' . uniqid('', true);
        mkdir($this->tempDir, 0775, true);

        $this->loggerConfigPath = $this->tempDir . '/logger.php';
        file_put_contents($this->loggerConfigPath, "<?php\nreturn [\n"
            . "    'default' => 'file',\n"
            . "    'handlers' => [\n"
            . "        'file' => [\n"
            . "            'type' => 'stream',\n"
            . "            'path' => " . var_export($this->tempDir . '/app.log', true) . ",\n"
            . "            'level' => 'debug',\n"
            . "        ],\n"
            . "    ],\n"
            . "];\n");

        putenv('JWT_SECRET');
        putenv('PHLIX_CONTAINER_COMPILE');
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
        $this->rmdir($this->tempDir);
    }

    private function rmdir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function containerWithMockedDb(): ContainerInterface
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

    public function testTheScanWorkerResolvesWithANonNullMediaAssetBackfill(): void
    {
        $container = $this->containerWithMockedDb();

        /** @var LibraryScanWorker $worker */
        $worker = $container->get(LibraryScanWorker::class);

        $this->assertInstanceOf(
            MediaAssetBackfill::class,
            $this->readPrivate($worker, 'mediaAssetBackfill'),
            'LibraryScanWorker must resolve with a MediaAssetBackfill; when it is null '
            . 'every `media_assets` job fails and no library can ever acquire trickplay '
            . 'artefacts. PHP-DI autowire() SKIPS defaulted ctor params, so this needs an '
            . 'explicit ->constructorParameter() in MediaServicesProvider.'
        );
    }

    /**
     * The backfill's queue MUST be the same store the {@see MediaAssetJobStore}
     * binding hands the worker. If the producer and the consumer resolve
     * different queue directories, the backfill writes job files nothing ever
     * drains — a failure that looks exactly like success from the admin UI.
     */
    public function testTheBackfillAndTheAssetWorkerShareOneQueueStore(): void
    {
        $container = $this->containerWithMockedDb();

        /** @var LibraryScanWorker $worker */
        $worker = $container->get(LibraryScanWorker::class);
        /** @var MediaAssetBackfill $backfill */
        $backfill = $this->readPrivate($worker, 'mediaAssetBackfill');

        $this->assertInstanceOf(MediaAssetBackfill::class, $backfill);
        $this->assertSame(
            $container->get(MediaAssetJobStore::class),
            $this->readPrivate($backfill, 'store'),
            'the backfill must write to the SAME queue the MediaAssetWorker drains'
        );
    }

    /**
     * The CONTROL: the container really is the production stack and really does
     * resolve this graph, so an `assertInstanceOf` passing above cannot be
     * explained by a container that returns anything for anything.
     */
    public function testTheBackfillIsResolvableOnItsOwnFromTheProductionProviders(): void
    {
        $container = $this->containerWithMockedDb();

        $this->assertTrue($container->has(MediaAssetBackfill::class));
        $this->assertInstanceOf(MediaAssetBackfill::class, $container->get(MediaAssetBackfill::class));
    }
}
