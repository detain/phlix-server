<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\LiveTv\Recorder;
use Phlix\Media\Library\RatingGate;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\LiveTvStreamController;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * Wave-I review close — the PRODUCTION composition of the DVR recording stream
 * controller must actually hold a {@see RatingGate}, not just be THREAD-argued
 * one by a line of source.
 *
 * ## Why the whole-line regex pin is not enough
 *
 * `LiveTvRecordingParentalGateTest` pins the exact source line
 * `new LiveTvStreamController($recorder, $storagePath, $this->optionalRatingGate())`.
 * That proves the CALL carries the third argument — but `optionalRatingGate()`
 * resolves the gate inside a `try { … } catch (\Throwable) { return null; }`, so
 * any drift in the production provider graph (the `RatingGate` binding removed
 * from `MediaServicesProvider`, a constructor it autowires renamed, the gate's
 * `UserRepository` dependency made unresolvable) makes that catch return null:
 * the controller keeps its nullable gate property, every over-cap request takes
 * the silent owner-safe no-op branch, the bytes flow, and the regex pin stays
 * GREEN. This is the same defect class `DashControllerWiringTest` (S59) and
 * `CastingWiringGuardTest` (Device-M1) exist for — a null-safe call against a
 * dependency the container failed to provide is invisible to construction-site
 * checks. Mutation B in the lane report proves the split: forcing
 * `optionalRatingGate()` to return null reddens THIS file while the regex pin
 * passes.
 *
 * ## What makes it a real check
 *
 * - The container is the PRODUCTION one — `ContainerFactory::defaultProviders()`,
 *   the same stack `start.php` builds — with only the MySQL {@see Connection}
 *   doubled (the verbatim CastingWiringGuardTest pattern).
 * - The controller is the one `Application` itself constructed while composing
 *   the router, reached through the route table — NOT one this file builds. If
 *   the registration were deleted the lookup fails loudly.
 * - The gate is identity-pinned against the HLS/DASH controllers' gates: every
 *   serve-time re-check in the estate must consult ONE shared RatingGate.
 * - An anti-vacuity test proves the property genuinely CAN be null, so the
 *   assertions above are statements about wiring, not about a type they could
 *   never have failed on.
 */
final class LiveTvStreamControllerWiringGuardTest extends TestCase
{
    private string $tempDir = '';
    private string $loggerConfigPath = '';
    private ?Application $sharedApplication = null;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        $this->tempDir = sys_get_temp_dir() . '/phlix_livetv_wiring_' . uniqid('', true);
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
        // Same residue the Dash wiring test sweeps: resolving the production
        // graph constructs MediaAssetJobStore/SimilarityJobStore at their shared
        // /tmp queue paths. Leave zero residue behind.
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

    public function testTheComposedRecordingStreamControllerHoldsTheContainersRatingGate(): void
    {
        $controller = $this->handlerFor('/livetv/recording/{id}/stream');
        $this->assertInstanceOf(LiveTvStreamController::class, $controller);

        $gate = $this->dependency($controller, 'ratingGate');
        $this->assertInstanceOf(
            RatingGate::class,
            $gate,
            'LiveTvStreamController::$ratingGate is null on the container-composed handler. '
            . 'streamRecording() treats a null gate as a strict no-op (owner-safe), so every '
            . 'over-cap DVR fetch is served again — the exact wave-I bypass — while the '
            . 'whole-line source pin in LiveTvRecordingParentalGateTest stays green, because '
            . 'Application::optionalRatingGate() swallows the container failure as null.'
        );
    }

    public function testItIsTheSAMEGateTheHlsAndDashControllersGot(): void
    {
        $livetvGate = $this->dependency($this->handlerFor('/livetv/recording/{id}/stream'), 'ratingGate');
        $hlsGate = $this->dependency($this->handlerFor('/hls/{job_id}/{file}'), 'ratingGate');
        $dashGate = $this->dependency($this->handlerFor('/dash/{job_id}/{file}'), 'ratingGate');

        // Without these, three NULL gates would chain-assertSame(null, null)
        // green — the identity pin only means something on live objects.
        $this->assertInstanceOf(RatingGate::class, $livetvGate);
        $this->assertInstanceOf(RatingGate::class, $hlsGate);
        $this->assertInstanceOf(RatingGate::class, $dashGate);

        $this->assertSame(
            $hlsGate,
            $livetvGate,
            'The recording route and the HLS segments of the same media must be judged by ONE '
            . 'shared RatingGate; two instances mean two filter-resolution caches diverging on '
            . 'the same profile cap.'
        );
        $this->assertSame($dashGate, $livetvGate, 'same law, DASH surface');
    }

    /**
     * Anti-vacuity: the dependency really CAN be null, so the assertions above
     * are statements about the wiring rather than about a type they could not
     * have failed on. The null is reachable by construction — the parameter is
     * trailing-optional for the pre-gate 2-arg callers (the legacy suite pins
     * that shape). If the parameter is ever made required, this case fails and
     * the two above stop distinguishing anything: the signal to delete all three.
     */
    public function testTheGateIsGenuinelyNullableSoTheAssertionsAboveCanFail(): void
    {
        $bare = new LiveTvStreamController($this->createMock(Recorder::class), '/tmp/phlix_recordings');

        $this->assertNull($this->dependency($bare, 'ratingGate'));

        $parameter = (new \ReflectionMethod(LiveTvStreamController::class, '__construct'))->getParameters()[2] ?? null;
        $this->assertNotNull($parameter);
        $this->assertTrue($parameter->allowsNull(), 'the gate parameter is nullable — hence this file');
    }

    // -----------------------------------------------------------------

    /**
     * The handler object registered under an exact path literal, read off the
     * router {@see Application} composed. Fails loudly when the route is absent,
     * so a deleted registration cannot read as "nothing to check".
     */
    private function handlerFor(string $path): object
    {
        $application = $this->application();
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

    /**
     * The PRODUCTION container: `ContainerFactory::defaultProviders()`, with only
     * the MySQL {@see Connection} doubled — the verbatim CastingWiringGuardTest
     * pattern — then handed to a real `Application` so the route table (and the
     * controller composition this guard inspects) is built by production code.
     */
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
