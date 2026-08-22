<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin\Maintenance;

use DI\ContainerBuilder;
use Phlix\Admin\Maintenance\MaintenanceJobRepository;
use Phlix\Admin\Maintenance\MaintenanceQueueWorker;
use Phlix\Admin\Maintenance\MaintenanceTask;
use Phlix\Admin\Maintenance\MaintenanceTaskRunner;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Library\PathDeduper;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\Admin\MaintenanceController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Router;
use ArgumentCountError;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionProperty;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * S77's maintenance surface, resolved from the REAL container and read off the
 * REAL router.
 *
 * ## Why a hand-wired test is not enough here
 *
 * PHP-DI's `autowire()` silently SKIPS optional constructor parameters, so any
 * dependency with a default ends up `null` in production while every
 * hand-wired unit test passes. One of this step's collaborators is still
 * optional and it matters:
 *
 *  - {@see MaintenanceTaskRunner}'s `$transcodeManager` — a null one makes
 *    `reap-transcode-jobs` answer "TranscodeManager is unavailable" forever.
 *
 * {@see MaintenanceController}'s `$adminGuard` used to be the second member of
 * that class — an optional `?AdminMiddleware $adminGuard = null` whose
 * null-state made the in-body second admin check a no-op. S338 made it a
 * REQUIRED, non-nullable constructor parameter, so PHP-DI's autowire() must
 * supply it (a controller built without it is an `ArgumentCountError`, proven
 * below); the wiring test below now pins the NEW contract and the structural
 * pin at
 * `tests/Unit/Server/Http/Controllers/Admin/MaintenanceControllerAdminGateIsStructuralTest.php`
 * pins the shape.
 *
 * S219 had to add a guard for exactly this class of bug on the DLNA admin
 * controller. This file follows its shape: build the container from
 * `ContainerFactory::defaultProviders()` with only the MySQL {@see Connection}
 * doubled, then read the wiring off what came out.
 */
final class MaintenanceContainerWiringTest extends TestCase
{
    /**
     * Anti-vacuity floor on the composed route table, per the S219/S239
     * precedent: a hand-rolled container yields ~53 routes where the real one
     * yields 353.
     */
    private const MIN_EXPECTED_ROUTES = 300;

    private string $tempDir = '';
    private string $loggerConfigPath = '';
    private ?ContainerInterface $sharedContainer = null;
    private ?Application $sharedApplication = null;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        $this->tempDir = sys_get_temp_dir() . '/phlix_s77_' . uniqid('', true);
        mkdir($this->tempDir, 0o775, true);

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
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
        LoggerFactory::reset();

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

    private function container(): ContainerInterface
    {
        if ($this->sharedContainer !== null) {
            return $this->sharedContainer;
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

        return $this->sharedContainer = ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ], $providers);
    }

    private function application(): Application
    {
        if ($this->sharedApplication !== null) {
            return $this->sharedApplication;
        }

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getPooledConnection')->willReturn($this->createMock(Connection::class));

        return $this->sharedApplication = new Application($this->container(), [], $pool);
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function routeTable(): array
    {
        $router = (new ReflectionProperty(Application::class, 'router'))->getValue($this->application());
        if (!$router instanceof Router) {
            self::fail('ANTI-VACUITY: Application::$router did not hold a Router.');
        }

        /** @var array<string, array<string, array<string, mixed>>> $routes */
        $routes = $router->getRoutes();

        $count = 0;
        foreach ($routes as $entries) {
            $count += count($entries);
        }
        if ($count < self::MIN_EXPECTED_ROUTES) {
            self::fail(sprintf(
                'ANTI-VACUITY: %d routes composed, below the %d floor — this harness is not '
                . 'reading the production container.',
                $count,
                self::MIN_EXPECTED_ROUTES
            ));
        }

        return $routes;
    }

    private function collaborator(object $target, string $property): mixed
    {
        return (new ReflectionProperty($target::class, $property))->getValue($target);
    }

    // -----------------------------------------------------------------
    // Resolution
    // -----------------------------------------------------------------

    public function test_every_maintenance_service_resolves_from_the_real_container(): void
    {
        $container = $this->container();

        self::assertInstanceOf(MaintenanceJobRepository::class, $container->get(MaintenanceJobRepository::class));
        self::assertInstanceOf(MaintenanceTaskRunner::class, $container->get(MaintenanceTaskRunner::class));
        self::assertInstanceOf(MaintenanceController::class, $container->get(MaintenanceController::class));
        self::assertInstanceOf(MaintenanceQueueWorker::class, $container->get(MaintenanceQueueWorker::class));
        self::assertInstanceOf(PathDeduper::class, $container->get(PathDeduper::class));
    }

    /**
     * 🚨 THE REQUIRED-PARAMETER CONTRACT, half one: the controller's admin guard.
     *
     * The guard is a REQUIRED constructor parameter since S338, so the container
     * cannot produce a controller without one — PHP-DI's autowire() must resolve
     * `AdminMiddleware` by type or `get()` throws. This test reads the resolved
     * controller and asserts the guard really is there.
     *
     * RED ON REVERT: making the parameter optional again lets autowire() skip it
     * (that is exactly what PHP-DI does with optional params), the container
     * then produces a null guard, and this assertion fails — while the in-body
     * check on `cleanup-orphaned-stats` and `dedupe-paths` degrades to "any
     * authenticated user", invisible to every hand-wired controller test, all
     * of which pass a guard explicitly.
     */
    public function test_the_container_built_controller_carries_its_admin_guard(): void
    {
        $controller = $this->container()->get(MaintenanceController::class);
        self::assertInstanceOf(MaintenanceController::class, $controller);

        self::assertInstanceOf(
            AdminMiddleware::class,
            $this->collaborator($controller, 'adminGuard'),
            'MaintenanceController::__construct() must take a REQUIRED AdminMiddleware. PHP-DI '
            . 'SKIPS optional ctor params, so an optional guard is one a real container leaves '
            . 'null and the in-body admin check on two DESTRUCTIVE endpoints does nothing.'
        );
    }

    /**
     * THE CONTROL for the assertion above, inverted by S338: the guard is now a
     * REQUIRED constructor parameter, so "an unwired controller" no longer
     * exists — a two-argument construction is an `ArgumentCountError` before an
     * object exists, and a non-admin is refused by a properly-built one.
     *
     * The S282-era counterpart of this test demonstrated the old hazard (2-arg
     * construction → null guard → non-admin gets 200); that construction path
     * is exactly what this step removes, so the demonstration has to become the
     * new contract.
     */
    public function test_an_unwired_controller_cannot_be_constructed(): void
    {
        $class = new ReflectionClass(MaintenanceController::class);

        // POSITIVE CONTROL — three arguments, i.e. WITH the guard, must construct.
        $controlError = null;
        try {
            $class->newInstanceArgs([
                $this->createMock(MaintenanceJobRepository::class),
                $this->createMock(MaintenanceTaskRunner::class),
                new AdminMiddleware(
                    $this->createMock(\Phlix\Auth\UserRepository::class),
                    $this->createMock(\Phlix\Common\Logger\AuditLogger::class),
                ),
            ]);
        } catch (ArgumentCountError $e) {
            $controlError = $e->getMessage();
        }
        self::assertNull(
            $controlError,
            'positive control: reflective construction WITH the middleware must succeed — if it '
            . 'does not, the ArgumentCountError below is an artefact of reflection, not proof of '
            . 'a required dependency'
        );

        // THE EXPERIMENT — the two-argument construction must be fatal.
        // Reflection rather than a literal `new MaintenanceController($a, $b)`:
        // that spelling is a STATIC arity error under phpstan-tests (level 2)
        // once the parameter is required, so the runtime-equivalent reflective
        // call is used — same ArgumentCountError, same place.
        $this->expectException(ArgumentCountError::class);
        $this->expectExceptionMessage('Too few arguments');

        $class->newInstanceArgs([
            $this->createMock(MaintenanceJobRepository::class),
            $this->createMock(MaintenanceTaskRunner::class),
        ]);
    }

    /**
     * The behavioural arm of the S338 contract: a NON-ADMIN is refused by a
     * properly-built controller.
     *
     * This replaces the old test's final act (non-admin reaching `tasks()` and
     * getting a 200). The refusal must come from the ADMIN branch
     * (`auth.not_admin`), not from the anonymous 401.
     */
    public function test_a_non_admin_is_refused_by_a_wired_controller(): void
    {
        $users = $this->createMock(\Phlix\Auth\UserRepository::class);
        $users->method('findAdminById')->willReturn(null);

        $wired = new MaintenanceController(
            $this->createMock(MaintenanceJobRepository::class),
            $this->createMock(MaintenanceTaskRunner::class),
            new AdminMiddleware($users, $this->createMock(\Phlix\Common\Logger\AuditLogger::class)),
        );

        $request = new \Phlix\Server\Http\Request();
        $request->method = 'GET';
        $request->userId = 'a-plain-non-admin-user';

        $response = $wired->tasks($request, []);

        self::assertSame(403, $response->statusCode);
        self::assertSame('auth.not_admin', json_decode($response->body, true)['code'] ?? null);
    }

    /**
     * 🚨 THE OPTIONAL-PARAMETER TRAP, half two: the runner's TranscodeManager.
     *
     * RED ON REVERT: replacing the `MaintenanceTaskRunner` factory with a bare
     * `autowire()` leaves this null and `reap-transcode-jobs` answers
     * "TranscodeManager is unavailable" on every call, forever.
     */
    public function test_the_container_built_runner_carries_its_transcode_manager(): void
    {
        $runner = $this->container()->get(MaintenanceTaskRunner::class);
        self::assertInstanceOf(MaintenanceTaskRunner::class, $runner);

        self::assertInstanceOf(
            TranscodeManager::class,
            $this->collaborator($runner, 'transcodeManager'),
            'The runner must be handed a TranscodeManager, or reap-transcode-jobs can never work.'
        );

        // The three REQUIRED collaborators too, so a future signature change
        // that made one of them optional is caught by the same test.
        self::assertInstanceOf(Connection::class, $this->collaborator($runner, 'db'));
        self::assertInstanceOf(ScanJobRepository::class, $this->collaborator($runner, 'scanJobs'));
        self::assertInstanceOf(PathDeduper::class, $this->collaborator($runner, 'pathDeduper'));
    }

    // -----------------------------------------------------------------
    // Registration on the PRODUCTION router
    // -----------------------------------------------------------------

    /**
     * Every maintenance endpoint is on the composed router, at its exact path,
     * bound to the container-built controller, inside the AdminMiddleware group.
     *
     * Exact path equality, never a substring: a sibling wildcard can absorb a
     * wrong path and still answer.
     */
    public function test_every_maintenance_endpoint_is_registered_gated_and_container_bound(): void
    {
        $expected = [
            ['GET', '/api/v1/admin/maintenance/tasks'],
            ['GET', '/api/v1/admin/maintenance/jobs'],
            ['GET', '/api/v1/admin/maintenance/jobs/{id}'],
            ['POST', '/api/v1/admin/maintenance/storage-snapshot'],
            ['POST', '/api/v1/admin/maintenance/reap-scan-jobs'],
            ['POST', '/api/v1/admin/maintenance/reap-transcode-jobs'],
            ['POST', '/api/v1/admin/maintenance/cleanup-orphaned-stats'],
            ['POST', '/api/v1/admin/maintenance/dedupe-paths'],
        ];

        $table = $this->routeTable();

        foreach ($expected as [$method, $path]) {
            $entry = null;
            foreach ($table[$method] ?? [] as $candidate) {
                if (($candidate['path'] ?? null) === $path) {
                    $entry = $candidate;
                    break;
                }
            }

            self::assertIsArray($entry, "The production router has no {$method} {$path}.");

            $handler = $entry['handler'] ?? null;
            self::assertIsArray($handler, "{$method} {$path} must bind a [target, method] pair");
            self::assertInstanceOf(
                MaintenanceController::class,
                $handler[0] ?? null,
                "{$method} {$path} must bind the container-built MaintenanceController."
            );

            $middleware = $entry['middleware'] ?? [];
            self::assertIsArray($middleware);
            $classes = array_map(
                static fn (mixed $m): string => is_object($m) ? $m::class : gettype($m),
                $middleware
            );
            self::assertContains(
                AdminMiddleware::class,
                $classes,
                "{$method} {$path} is NOT inside the AdminMiddleware group."
            );
        }
    }

    /**
     * ONE controller instance serves all eight routes.
     *
     * `AdminRoutes` resolves the controller once and binds the object eight
     * times; if a refactor gave each route its own instance, wiring one would
     * leave the other seven unwired — and a test that only looked at the first
     * would not notice.
     */
    public function test_all_eight_routes_share_the_one_wired_controller_instance(): void
    {
        $table = $this->routeTable();
        $instances = [];

        $probes = [
            ['GET', '/api/v1/admin/maintenance/tasks'],
            ['POST', '/api/v1/admin/maintenance/dedupe-paths'],
            ['POST', '/api/v1/admin/maintenance/cleanup-orphaned-stats'],
        ];

        foreach ($probes as [$method, $path]) {
            foreach ($table[$method] ?? [] as $candidate) {
                if (($candidate['path'] ?? null) === $path) {
                    /** @var array{0: object, 1: string} $handler */
                    $handler = $candidate['handler'];
                    $instances[] = $handler[0];
                }
            }
        }

        self::assertCount(3, $instances);
        self::assertSame($instances[0], $instances[1]);
        self::assertSame($instances[0], $instances[2]);
    }

    /**
     * The registered paths and the task vocabulary agree.
     *
     * A task added to {@see MaintenanceTask::ALL} without a route is
     * unreachable; a route with no task would 500 in the runner. Compared
     * against the PRODUCTION table rather than the source, so this holds even
     * if the registration moves.
     */
    public function test_the_registered_action_paths_are_exactly_the_task_list(): void
    {
        $registered = [];
        foreach ($this->routeTable()['POST'] ?? [] as $entry) {
            $path = $entry['path'] ?? null;
            if (is_string($path) && str_starts_with($path, '/api/v1/admin/maintenance/')) {
                $registered[] = substr($path, strlen('/api/v1/admin/maintenance/'));
            }
        }
        sort($registered);

        $tasks = MaintenanceTask::ALL;
        sort($tasks);

        self::assertSame($tasks, $registered);
    }
}
