<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use DI\ContainerBuilder;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\Admin\AdminRestartController;
use Phlix\Server\Http\Controllers\Dlna\AdminDlnaServerController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * S219 — the DLNA admin controller's PRODUCTION DI wiring.
 *
 * ## The measured hole
 *
 * `AdminDlnaServerController::setSettingsRepository()` and
 * `::setRestartController()` have exactly two production call sites —
 * `Application::loadDlnaAdminRoutes()` at `Application.php:1089` and `:1102`.
 * **Every existing test wires the controller by hand.** Replacing the
 * `setSettingsRepository()` call site with a no-op left
 * `DlnaAdminRoutesTest` + `tests/Unit/Server/Http/Controllers/Dlna` +
 * `tests/Unit/Dlna` at `OK (310 tests, 929 assertions)`.
 *
 * In production that same no-op makes **every** DLNA start/stop return
 * `503 {"message":"DLNA settings store is unavailable…"}`, because
 * `applyEnabled()` short-circuits on `$this->settings === null` before anything
 * else. The toggle in the admin UI would simply stop working, silently, with a
 * fully green suite.
 *
 * ## Why the test is shaped this way
 *
 * **A test that builds the object by hand cannot prove the container builds
 * it.** So this file never constructs the wired controller: it reflects the
 * `Router` that a real `Application` composed from
 * `ContainerFactory::defaultProviders()` — the same provider stack
 * `public/index.php` and the Workerman daemon use, with only the MySQL
 * {@see Connection} doubled — and pulls the controller instance back out of the
 * route table, which is where `loadDlnaAdminRoutes()` put it.
 *
 * Note that the three DLNA admin routes bind an already-constructed controller
 * (`[$controller, 'status']`), NOT a class-string the container resolves at
 * dispatch. That is precisely why the wiring matters and why it must be read
 * off the registered instance: a class-string binding would hand dispatch a
 * fresh, unwired object every time.
 *
 * Every claim here has a paired NEGATIVE control built by hand, so "the wired
 * instance answers 409" cannot be confused with "everything answers 409".
 *
 * @see \Phlix\Tests\Unit\Server\Core\ApplicationRouterWirePathGuardTest The S239
 *      precedent this harness follows: assertions against the PRODUCTION router.
 */
final class ApplicationDlnaAdminWiringGuardTest extends TestCase
{
    /**
     * Lower bound on a sane composed route table — the anti-vacuity floor S239
     * established. A hand-rolled container yields **53** routes where the real
     * one yields **345** (measured by S164). Without this, a harness that
     * silently built the wrong container, or a `catch (\Throwable)` in a loader
     * that started swallowing a construction failure, would make every
     * assertion below either vacuous or fail for a misleading reason.
     */
    private const MIN_EXPECTED_ROUTES = 300;

    /** The three routes `loadDlnaAdminRoutes()` registers. Exact literals, never substrings. */
    private const STATUS_PATH = '/api/v1/admin/dlna/status';
    private const START_PATH  = '/api/v1/admin/dlna/start';
    private const STOP_PATH   = '/api/v1/admin/dlna/stop';

    /** The 503 body `applyEnabled()` emits when the settings store is unwired. */
    private const UNWIRED_MESSAGE = 'DLNA settings store is unavailable; cannot change CDS state.';

    private string $tempDir = '';
    private string $loggerConfigPath = '';
    private ?ContainerInterface $sharedContainer = null;
    private ?Application $sharedApplication = null;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        // Application registers AccessScheduleMiddleware globally and it answers
        // 403 whenever RequestContext carries a user id. That context is process
        // static, so a sibling test that left one set would poison the dispatch
        // assertions here. Cleared on both ends, as S239 does.
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        $this->tempDir = sys_get_temp_dir() . '/phlix_s219_' . uniqid('', true);
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

    /**
     * The PRODUCTION container: `ContainerFactory::defaultProviders()`, with only
     * the MySQL {@see Connection} doubled. Nothing about DLNA, settings, routing
     * or the restart controller is substituted.
     */
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
     * The route table of the `Router` instance `Application::dispatch()` uses.
     *
     * Fails LOUDLY if it reads nothing or too little, so a hollowed registrar
     * cannot turn this file into a vacuous pass.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function routeTable(): array
    {
        $property = (new ReflectionClass(Application::class))->getProperty('router');
        $property->setAccessible(true);
        $router = $property->getValue($this->application());

        if (!$router instanceof Router) {
            $this->fail(
                'ANTI-VACUITY: Application::$router did not hold a Router instance, so the '
                . 'production wiring could not be read at all.'
            );
        }

        /** @var array<string, array<string, array<string, mixed>>> $routes */
        $routes = $router->getRoutes();

        $count = 0;
        foreach ($routes as $entries) {
            $count += count($entries);
        }

        if ($count < self::MIN_EXPECTED_ROUTES) {
            $this->fail(sprintf(
                'ANTI-VACUITY: the composed Application route table holds %d route(s), fewer than '
                . 'the %d floor. Either a loader was hollowed, or this harness is no longer building '
                . 'the PRODUCTION container (a hand-rolled one yields 53 where the real one yields '
                . '345 — S164). Either way this wiring guard is not guarding anything.',
                $count,
                self::MIN_EXPECTED_ROUTES
            ));
        }

        return $routes;
    }

    /**
     * The handler target the production router registered for `$method $path`.
     *
     * Asserts the binding is an already-constructed OBJECT, not a class-string:
     * a class-string binding would mean dispatch builds a fresh, unwired
     * controller and the whole wiring question moves elsewhere. Reading the
     * object out of the route table is the only way to observe what
     * `loadDlnaAdminRoutes()` actually wired.
     */
    private function registeredController(string $method, string $path): AdminDlnaServerController
    {
        foreach ($this->routeTable()[$method] ?? [] as $entry) {
            if (($entry['path'] ?? null) !== $path) {
                continue;
            }

            $handler = $entry['handler'] ?? null;
            self::assertIsArray($handler, "{$method} {$path} must bind a [target, method] pair");
            self::assertIsObject(
                $handler[0] ?? null,
                "{$method} {$path} must bind an already-CONSTRUCTED controller. A class-string "
                . 'binding would have the container build a fresh, unwired instance at dispatch.'
            );
            self::assertInstanceOf(AdminDlnaServerController::class, $handler[0]);

            return $handler[0];
        }

        self::fail(
            "The production route table has no {$method} {$path}. loadDlnaAdminRoutes() either did "
            . 'not run or no longer registers it, so the wiring assertions cannot be made.'
        );
    }

    /**
     * Read a private collaborator off a controller instance.
     */
    private function collaborator(AdminDlnaServerController $controller, string $property): mixed
    {
        $reflected = (new ReflectionClass(AdminDlnaServerController::class))->getProperty($property);
        $reflected->setAccessible(true);

        return $reflected->getValue($controller);
    }

    private function post(AdminDlnaServerController $controller, string $path): Response
    {
        $request = new Request();
        $request->method   = 'POST';
        $request->path     = $path;
        $request->remoteIp = '127.0.0.1';

        return $path === self::START_PATH
            ? $controller->start($request, [])
            : $controller->stop($request, []);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded, 'the response body must be a JSON object');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    // -----------------------------------------------------------------
    // Anti-vacuity, named
    // -----------------------------------------------------------------

    /**
     * The floor every other test here depends on, asserted under its own name so
     * a hollowed registrar or a wrong container fails with a message that SAYS
     * what happened.
     */
    public function testTheReflectedRouteTableIsTheProductionOneAndNotHollowedOut(): void
    {
        $count = 0;
        foreach ($this->routeTable() as $entries) {
            $count += count($entries);
        }

        self::assertGreaterThanOrEqual(self::MIN_EXPECTED_ROUTES, $count);
    }

    // -----------------------------------------------------------------
    // The wiring itself, read off the container-composed instance
    // -----------------------------------------------------------------

    /**
     * `Application.php:1089` — the settings store IS wired on the instance the
     * production router serves.
     *
     * Mutation-verified: commenting out `$controller->setSettingsRepository(...)`
     * reddens this.
     */
    public function testTheContainerComposedControllerCarriesTheSettingsRepository(): void
    {
        $controller = $this->registeredController('POST', self::START_PATH);

        self::assertInstanceOf(
            SettingsRepository::class,
            $this->collaborator($controller, 'settings'),
            'loadDlnaAdminRoutes() must wire the SettingsRepository onto the controller it '
            . 'registers. Without it every DLNA start/stop answers 503.'
        );
    }

    /**
     * `Application.php:1102` — the restart controller IS wired on the same
     * instance.
     *
     * Mutation-verified: commenting out `$controller->setRestartController(...)`
     * reddens this.
     */
    public function testTheContainerComposedControllerCarriesTheRestartController(): void
    {
        $controller = $this->registeredController('POST', self::STOP_PATH);

        self::assertInstanceOf(
            AdminRestartController::class,
            $this->collaborator($controller, 'restartController'),
            'loadDlnaAdminRoutes() must wire the AdminRestartController. Without it the toggle '
            . 'persists but never schedules the graceful reload, so no worker picks the change up.'
        );
    }

    /**
     * All three DLNA admin routes must share ONE controller instance.
     *
     * `loadDlnaAdminRoutes()` wires a single object and binds it three times. If
     * a refactor gave each route its own instance, wiring one would leave the
     * other two unwired — and a test that only ever looked at `start` would
     * not notice.
     */
    public function testAllThreeDlnaAdminRoutesShareTheOneWiredControllerInstance(): void
    {
        $status = $this->registeredController('GET', self::STATUS_PATH);
        $start  = $this->registeredController('POST', self::START_PATH);
        $stop   = $this->registeredController('POST', self::STOP_PATH);

        self::assertSame($status, $start);
        self::assertSame($status, $stop);

        $expected = [
            'settings' => SettingsRepository::class,
            'restartController' => AdminRestartController::class,
        ];
        foreach ($expected as $property => $class) {
            self::assertInstanceOf($class, $this->collaborator($status, $property));
        }
    }

    // -----------------------------------------------------------------
    // The production CONSEQUENCE, with its control
    // -----------------------------------------------------------------

    /**
     * CONSEQUENCE: the container-composed controller does NOT 503 the toggle.
     *
     * Reflecting a property proves the field is set; this proves the field being
     * set is what production depends on. `applyEnabled()` checks
     * `$this->settings === null` FIRST, so an unwired controller can never get
     * past 503 — and `dlna.cds_enabled` ships false, so a `stop()` on a wired
     * one lands on the "already disabled" 409 instead.
     *
     * 409 and 503 are both refusals, which is exactly why the control below
     * matters: without it, "it answered a 4xx/5xx" would prove nothing.
     */
    public function testStopOnTheContainerComposedControllerIsNotTheUnwired503(): void
    {
        $response = $this->post($this->registeredController('POST', self::STOP_PATH), self::STOP_PATH);

        self::assertNotSame(
            503,
            $response->statusCode,
            'The container-composed controller answered the unwired-settings 503. '
            . 'loadDlnaAdminRoutes() is no longer wiring the SettingsRepository.'
        );
        self::assertSame(409, $response->statusCode, $response->body);
        self::assertNotSame(self::UNWIRED_MESSAGE, $this->body($response)['message'] ?? null);
    }

    /**
     * THE CONTROL. A hand-built, UNWIRED controller — the exact object the
     * mutation of `Application.php:1089` would leave behind — answers 503 with
     * the unwired message.
     *
     * This is what makes the 409 above discriminating rather than incidental,
     * and it is also the anti-vacuity floor for the reflection tests: it proves
     * `settings`/`restartController` genuinely CAN be null, so asserting they
     * are not is a real constraint.
     */
    public function testAnUnwiredControllerAnswersTheUnwired503(): void
    {
        $unwired = new AdminDlnaServerController();

        self::assertNull($this->collaborator($unwired, 'settings'));
        self::assertNull($this->collaborator($unwired, 'restartController'));

        $response = $this->post($unwired, self::STOP_PATH);

        self::assertSame(503, $response->statusCode);
        self::assertSame(self::UNWIRED_MESSAGE, $this->body($response)['message'] ?? null);
    }
}
