<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\DashController;
use Phlix\Server\Http\Controllers\HlsController;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * S59 — the DASH controller the PRODUCTION container composes must actually hold
 * a {@see TranscodeManager}.
 *
 * ## Why this file exists
 *
 * S59's whole feature is one call: `DashController::serveFile()` routes a `.m4s`
 * miss through `ensureSegment()`. That call is written `$this->transcodeManager?->…`,
 * because the constructor parameter is nullable for the container-less legacy
 * path. A null-safe call against a dependency the container failed to provide is
 * INVISIBLE: no exception, no log, just a 404 on every segment — i.e. exactly the
 * pre-S59 behaviour, shipped under a green suite.
 *
 * That is not hypothetical here. Before S59 the factory resolved the manager only
 * `if ($this->container->has(TranscodeManager::class))`, and this estate has a
 * recorded case of PHP-DI silently leaving an optional dependency null
 * (`autowire()` skipping optional constructor params). S59 made the resolution
 * unconditional; this file is what stops it going back.
 *
 * ## What makes it a real check
 *
 * - The container is the PRODUCTION one — `ContainerFactory::defaultProviders()`,
 *   the same stack `start.php` and `public/index.php` build — with only the MySQL
 *   {@see Connection} doubled. A hand-rolled container would resolve nothing and
 *   prove nothing.
 * - The controller is the one `Application` itself constructed while composing the
 *   router, reached through the route table, NOT one this file builds. If the
 *   registration were deleted the lookup fails loudly rather than passing.
 * - The HLS controller is asserted beside it as a positive control: both must hold
 *   a manager, and it must be the SAME instance, because they serve the same job
 *   directory and a second manager would mean two in-flight-encode registries
 *   disagreeing about the same segment.
 */
final class DashControllerWiringTest extends TestCase
{
    private string $tempDir = '';
    private string $loggerConfigPath = '';
    private ?Application $sharedApplication = null;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        $this->tempDir = sys_get_temp_dir() . '/phlix_s59_wiring_' . uniqid('', true);
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
        LoggerFactory::reset();
        $this->sharedApplication = null;
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

    public function testTheComposedDashControllerHoldsTheContainersTranscodeManager(): void
    {
        $dash = $this->handlerFor('/dash/{job_id}/{file}');
        $this->assertInstanceOf(DashController::class, $dash);

        $manager = $this->dependency($dash, 'transcodeManager');
        $this->assertInstanceOf(
            TranscodeManager::class,
            $manager,
            'DashController::serveFile() calls $this->transcodeManager?->ensureSegment(). A null here '
            . 'is not an error at runtime — it silently 404s every DASH segment, which is precisely '
            . 'the defect S59 exists to fix.'
        );
    }

    public function testItIsTheSAMEManagerTheHlsControllerGot(): void
    {
        $dashManager = $this->dependency($this->handlerFor('/dash/{job_id}/{file}'), 'transcodeManager');
        $hlsController = $this->handlerFor('/hls/{job_id}/{file}');
        $this->assertInstanceOf(HlsController::class, $hlsController);
        $hlsManager = $this->dependency($hlsController, 'transcodeManager');

        $this->assertInstanceOf(TranscodeManager::class, $hlsManager, 'positive control');
        $this->assertSame(
            $hlsManager,
            $dashManager,
            'HLS and DASH serve the SAME job directory and the same segments. Two managers would '
            . 'keep two in-flight-encode registries over one set of files.'
        );
    }

    /**
     * Anti-vacuity: the dependency really CAN be null, so the assertions above
     * are statements about the wiring rather than about a type they could not
     * have failed on.
     *
     * The null is reachable by construction, not by container failure —
     * `DashController`'s parameter is optional and defaults to null, which is
     * what the legacy/no-container callers (and most unit tests) use. If that
     * parameter is ever made required, this case fails and the two above stop
     * distinguishing anything, which is the signal to delete all three.
     */
    public function testTheDependencyIsGenuinelyNullableSoTheAssertionsAboveCanFail(): void
    {
        $bare = new DashController('/tmp/phlix_hls');

        $this->assertNull($this->dependency($bare, 'transcodeManager'));

        $parameter = (new \ReflectionMethod(DashController::class, '__construct'))->getParameters()[1] ?? null;
        $this->assertNotNull($parameter);
        $this->assertTrue($parameter->allowsNull(), 'the manager parameter is nullable — hence this file');
    }

    // -----------------------------------------------------------------

    /**
     * The handler object registered under an exact path literal, read off the
     * router {@see Application} composed. Fails loudly when the route is absent,
     * so a deleted registration cannot read as "nothing to check".
     */
    private function handlerFor(string $path, ?Application $application = null): object
    {
        $application ??= $this->application();
        $property = new ReflectionProperty(Application::class, 'router');
        $property->setAccessible(true);
        $router = $property->getValue($application);
        $this->assertInstanceOf(\Phlix\Server\Http\Router::class, $router);

        foreach ($router->getRoutes()['GET'] ?? [] as $entry) {
            if (($entry['path'] ?? null) !== $path) {
                continue;
            }
            $handler = $entry['handler'] ?? null;
            $this->assertIsArray($handler);
            $this->assertIsObject($handler[0], "GET {$path} must bind a constructed controller");

            return $handler[0];
        }

        $this->fail("GET {$path} is not registered at all, so this file cannot check its wiring.");
    }

    private function dependency(object $controller, string $property): ?object
    {
        $reflected = new ReflectionProperty($controller, $property);
        $reflected->setAccessible(true);
        $value = $reflected->getValue($controller);
        $this->assertTrue($value === null || is_object($value));

        /** @var object|null $value */
        return $value;
    }

    private function application(): Application
    {
        if ($this->sharedApplication !== null) {
            return $this->sharedApplication;
        }

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

        /** @var ContainerInterface $container */
        $container = ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ], $providers);

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getPooledConnection')->willReturn($this->createMock(Connection::class));

        return $this->sharedApplication = new Application($container, [], $pool);
    }
}
