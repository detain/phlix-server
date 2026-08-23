<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\Admin\MaintenanceController;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * S330 — the admin-maintenance route set must mirror phlix-ui's
 * `AdminMaintenanceApi` endpoint/task list.
 *
 * ## The defect class (S78 / S332)
 *
 * S78's brief named eight Tasks-page sections and only four shipped: scan-all,
 * recompute-similarity and newsletter send-now existed in prose but had no
 * server route and no UI method (the fourth, "check for updates now", is
 * S273-owned). A route set and a client list that drift APART is the same
 * defect class S332 pinned for the library surface: the hand-written
 * `MAINTENANCE_ENDPOINTS`/`MAINTENANCE_TASK_NAMES` lists in
 * `src/api/admin/maintenance.ts` grow with the LIST, not with the ROUTES, so a
 * future server route added without a UI mirror is silently untriggerable from
 * the admin Tasks page. This file is the cross-repo pin that makes that class
 * of drift loud, in BOTH directions.
 *
 * ## Why the assertions are shaped the way they are
 *
 * - **The route set is PARSED from the PRODUCTION route table**, not
 *   hand-written. The table is reflected off an `Application` built from
 *   `ContainerFactory::defaultProviders()` — the same provider stack
 *   `public/index.php` and the Workerman daemon use — with only the MySQL
 *   {@see Connection} doubled. A hand-written fixture cannot detect that the
 *   real thing differs (S345); this file extracts what is actually registered.
 * - **The denominator is printed.** Every failure message names the route set
 *   the guard examined and the phlix-ui mirror it was compared to.
 * - **Exact whole-list comparison in BOTH directions; no substring matching.**
 *   `'reap-scan-jobs-MUTATED'` CONTAINS `'reap-scan-jobs'`, so a
 *   `str_contains`/`assertContains` assertion would pass the exact mutation it
 *   exists to catch (S37/S236/S239). The guard instead splits the symmetric
 *   difference into `missing` (a UI entry with no server route) and `extra`
 *   (a server route with no UI entry) and asserts each is empty.
 * - **The mirror is the phlix-ui endpoint/task NAME SETS.**
 *   {@see self::PHLIX_UI_MAINTENANCE_TASK_NAMES} spells the
 *   `MAINTENANCE_TASK_NAMES` members and
 *   {@see self::PHLIX_UI_MAINTENANCE_ENDPOINT_PATHS} the `MAINTENANCE_ENDPOINTS`
 *   literals (plus the derived `jobs/{id}` template) exactly as they appear in
 *   `src/api/admin/maintenance.ts`. Adding a server maintenance route therefore
 *   requires updating BOTH the phlix-ui API list AND this file's mirror in the
 *   same PR — the guard cannot read the TypeScript source across repos, so the
 *   list is the proxy for it, exactly as in the S348 mirror-pin contract.
 * - **The 8-route vs 7-literal asymmetry is handled explicitly.** The server
 *   registers eight routes (three GET reads incl. the two-segment `jobs/{id}`
 *   plus five POST actions); phlix-ui has seven literal paths plus the derived
 *   `jobs/{id}` and five task names. The guard therefore asserts TWO equalities
 *   rather than one naive list-equality: the POST action suffixes ↔ the task
 *   names, and the full path-template set ↔ the endpoint paths.
 *
 * ## What counts as an admin-maintenance route
 *
 * A route counts when ALL of these hold:
 *  - the literal path begins `/api/v1/admin/maintenance/`; and
 *  - the handler is a `[MaintenanceController, method]` pair.
 *
 * That selects exactly the eight routes S77/S78 ship (`tasks`, `jobs`,
 * `jobs/{id}`, `storage-snapshot`, `reap-scan-jobs`, `reap-transcode-jobs`,
 * `cleanup-orphaned-stats`, `dedupe-paths`). It deliberately EXCLUDES the
 * library-maintenance surface (`POST /api/v1/libraries/{id}/<suffix>` →
 * `LibraryController`), which S348's
 * {@see LibraryMaintenanceRoutesUiMirrorGuardTest} pins — different controller,
 * different prefix, no duplication between the two guards.
 *
 * ## Coverage statement
 *
 * This file pins REGISTRATION only, the same strength as S348's guard and the
 * S239 manifest's "all rails" pass: a maintenance route that is renamed,
 * deleted, re-verbed, re-pointed away from `MaintenanceController`, or added
 * without a mirror update reds it. It does NOT dispatch the routes — response
 * envelopes, status codes and the in-handler admin gate are pinned by S338's
 * {@see \Phlix\Tests\Unit\Server\Http\Controllers\Admin\MaintenanceControllerAdminGateIsStructuralTest}
 * / `MaintenanceContainerWiringTest` and the S77 `MaintenanceControllerTest`.
 *
 * @see \Phlix\Tests\Unit\Server\Core\LibraryMaintenanceRoutesUiMirrorGuardTest S348, whose
 *      route-table reflection this file reuses and whose no-substring rule it follows.
 * @see \Phlix\Tests\Unit\Server\Core\ApplicationRouterWirePathGuardTest S239, the
 *      full-route-manifest guard this file complements at the maintenance surface.
 */
final class AdminMaintenanceRoutesUiMirrorGuardTest extends TestCase
{
    /**
     * The phlix-ui `MAINTENANCE_TASK_NAMES` members, in the order that array
     * declares them (src/api/admin/maintenance.ts).
     *
     * ## How to change this list
     *
     * Adding, renaming or removing a maintenance task name in phlix-ui MUST be
     * accompanied by the matching edit here, in the same commit — and by the
     * matching server POST route + `MaintenanceTask::ALL` entry. The task names
     * ARE the POST route suffixes, so no derivation is needed; keep them exact.
     *
     * @var list<string>
     */
    private const PHLIX_UI_MAINTENANCE_TASK_NAMES = [
        'storage-snapshot',
        'reap-scan-jobs',
        'reap-transcode-jobs',
        'cleanup-orphaned-stats',
        'dedupe-paths',
    ];

    /**
     * The phlix-ui `MAINTENANCE_ENDPOINTS` literals plus the DERIVED `jobs/{id}`
     * template (`getJob()` builds `` `${MAINTENANCE_ENDPOINTS.jobs}/${id}` ``),
     * as full path templates, in the order the API declares them.
     *
     * ## How to change this list
     *
     * Adding, renaming or removing a maintenance endpoint path in phlix-ui MUST
     * be accompanied by the matching edit here, in the same commit — and by the
     * matching server route. Store the full path TEMPLATES, not fragments: a
     * `/api/v1/admin/maintenance/jobs` literal is a prefix of
     * `/api/v1/admin/maintenance/jobs/{id}`, so a fragment list would silently
     * collapse the two.
     *
     * @var list<string>
     */
    private const PHLIX_UI_MAINTENANCE_ENDPOINT_PATHS = [
        '/api/v1/admin/maintenance/tasks',
        '/api/v1/admin/maintenance/jobs',
        // Derived: `getJob()` GETs `${MAINTENANCE_ENDPOINTS.jobs}/${encodeURIComponent(id)}`.
        '/api/v1/admin/maintenance/jobs/{id}',
        '/api/v1/admin/maintenance/storage-snapshot',
        '/api/v1/admin/maintenance/reap-scan-jobs',
        '/api/v1/admin/maintenance/reap-transcode-jobs',
        '/api/v1/admin/maintenance/cleanup-orphaned-stats',
        '/api/v1/admin/maintenance/dedupe-paths',
    ];

    /**
     * Lower bound on the size of a sane admin-maintenance route set.
     *
     * Its only job is ANTI-VACUITY. If the route-table extractor stops reading
     * the production router, or the path filter stops matching, the
     * missing/extra assertions below would otherwise pass with an EMPTY actual
     * set. 7 sits below the measured 8 and far above the 0 a broken extractor
     * yields, so it separates "the extractor was hollowed" from "the surface
     * genuinely shrank" (the latter also requires editing this file's mirror
     * list, which is itself reviewed).
     */
    private const MIN_EXPECTED_ADMIN_MAINTENANCE_ROUTES = 7;

    /**
     * Lower bound on the size of a sane POST-action set, the anti-vacuity floor
     * for the task-name equality. Measured 5; 4 separates a hollowed extractor
     * from a genuinely shrunken action surface.
     */
    private const MIN_EXPECTED_ADMIN_MAINTENANCE_ACTIONS = 4;

    /**
     * An admin-maintenance route path template: any path under the
     * `/api/v1/admin/maintenance/` prefix, optionally with a trailing `/{id}`
     * segment. Anchored both ends — no substring matching.
     */
    private const ADMIN_MAINTENANCE_PATH_PATTERN = '#^/api/v1/admin/maintenance/([a-z][a-z0-9-]*)(/\{id\})?$#';

    private string $tempDir = '';
    private string $loggerConfigPath = '';
    private ?ContainerInterface $sharedContainer = null;
    private ?Application $sharedApplication = null;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        // Cleared for the same reason the S348/S239 guards clear it:
        // AccessScheduleMiddleware is registered GLOBALLY and reads
        // RequestContext, so a sibling test that left a user id set would
        // poison any future dispatch controls in this file.
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        $this->tempDir = sys_get_temp_dir() . '/phlix_s330_' . uniqid('', true);
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
     * The PRODUCTION container: `ContainerFactory::defaultProviders()`, the same
     * stack `public/index.php` and the Workerman daemon build, with ONLY the MySQL
     * {@see Connection} doubled. Nothing about routing is substituted.
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
     * The admin-maintenance route entries of the PRODUCTION route table
     * `Application::dispatch()` delegates to — parsed, never hand-written.
     *
     * Fails LOUDLY if the `router` property no longer holds a {@see Router} or
     * an admin-maintenance route stops carrying a
     * `[MaintenanceController, method]` pair, so a hollowed extractor cannot
     * masquerade as an empty surface.
     *
     * @return list<array{method: string, path: string, handler: string}>
     */
    private function adminMaintenanceRoutes(): array
    {
        $property = (new ReflectionClass(Application::class))->getProperty('router');
        $property->setAccessible(true);
        $router = $property->getValue($this->application());

        $this->assertInstanceOf(
            Router::class,
            $router,
            'ANTI-VACUITY: Application::$router did not hold a Router instance, so the '
            . 'admin-maintenance route set could not be read from the production table.'
        );

        /** @var array<string, array<string, array<string, mixed>>> $routes */
        $routes = $router->getRoutes();

        $out = [];
        $unmatched = [];
        foreach ($routes as $method => $entries) {
            foreach ($entries as $entry) {
                $path = $entry['path'] ?? null;
                if (!is_string($path) || !str_starts_with($path, '/api/v1/admin/maintenance/')) {
                    continue;
                }

                // A maintenance-prefixed path that does not match the pattern is
                // NOT silently skipped: a future deeper-nested route (e.g.
                // `/api/v1/admin/maintenance/foo/bar`) would otherwise sit in
                // neither `actual` set and never red the mirror — a hole in the
                // "fails if either side adds one" promise. Collected here and
                // asserted empty below, so it surfaces as a loud failure naming
                // the path instead.
                if (preg_match(self::ADMIN_MAINTENANCE_PATH_PATTERN, $path) !== 1) {
                    $unmatched[] = $path;
                    continue;
                }

                $handler = $entry['handler'] ?? null;
                $this->assertIsArray(
                    $handler,
                    "admin-maintenance route {$path} must bind a [target, method] pair, not a closure"
                );
                $target = $handler[0];
                $this->assertTrue(
                    is_object($target) || is_string($target),
                    "admin-maintenance route {$path} must bind an object or a class-string handler target"
                );
                $this->assertSame(
                    'MaintenanceController',
                    $this->shortName(is_object($target) ? $target::class : $target),
                    "admin-maintenance route {$path} must be handled by MaintenanceController, not a "
                    . 'sibling controller — the phlix-ui AdminMaintenanceApi mirror is scoped to that class'
                );

                $out[] = [
                    'method' => strtoupper((string) $method),
                    'path' => $path,
                    'handler' => $this->shortName(is_object($target) ? $target::class : $target),
                ];
            }
        }

        $this->assertSame(
            [],
            $unmatched,
            'ANTI-VACUITY: an admin-maintenance route path does not match '
            . 'ADMIN_MAINTENANCE_PATH_PATTERN (' . implode(', ', $unmatched) . '). A route under '
            . '/api/v1/admin/maintenance/ that this extractor cannot see would be absent from '
            . 'both `actual` sets and the mirror would never red. Either extend the pattern to '
            . 'cover the new shape or name it in the phlix-ui mirror lists — do not let it pass '
            . 'silently.'
        );

        return $out;
    }

    /**
     * The POST action suffix set of the PRODUCTION route table, sorted.
     *
     * @return list<string> e.g. `dedupe-paths`
     */
    private function postActionSuffixes(): array
    {
        $suffixes = [];
        foreach ($this->adminMaintenanceRoutes() as $route) {
            if ($route['method'] !== 'POST') {
                continue;
            }
            $suffixes[] = substr($route['path'], strlen('/api/v1/admin/maintenance/'));
        }

        sort($suffixes);

        return $suffixes;
    }

    /**
     * The full path-template set of the PRODUCTION route table, sorted.
     *
     * @return list<string> e.g. `/api/v1/admin/maintenance/jobs/{id}`
     */
    private function routePathTemplates(): array
    {
        $paths = array_column($this->adminMaintenanceRoutes(), 'path');

        sort($paths);

        return $paths;
    }

    /**
     * The expected POST suffix set, derived from the phlix-ui task-name mirror.
     *
     * @return list<string> sorted suffixes
     */
    private function expectedActionSuffixes(): array
    {
        $suffixes = self::PHLIX_UI_MAINTENANCE_TASK_NAMES;
        sort($suffixes);

        return $suffixes;
    }

    /**
     * The expected full path-template set, from the phlix-ui endpoint mirror.
     *
     * @return list<string> sorted path templates
     */
    private function expectedPathTemplates(): array
    {
        $paths = self::PHLIX_UI_MAINTENANCE_ENDPOINT_PATHS;
        sort($paths);

        return $paths;
    }

    /**
     * The core guard, half one: the server's maintenance POST-action suffix set
     * must equal the phlix-ui `MAINTENANCE_TASK_NAMES` mirror, in BOTH
     * directions. The task names ARE the POST route suffixes.
     */
    public function testThePostActionSuffixSetMatchesThePhlixUiTaskNamesInBothDirections(): void
    {
        $expected = $this->expectedActionSuffixes();
        $actual = $this->postActionSuffixes();

        $this->assertGreaterThanOrEqual(
            self::MIN_EXPECTED_ADMIN_MAINTENANCE_ACTIONS,
            count($actual),
            'ANTI-VACUITY: the production route table yielded only ' . count($actual)
            . ' admin-maintenance POST action(s) (' . implode(', ', $actual) . '), below the '
            . self::MIN_EXPECTED_ADMIN_MAINTENANCE_ACTIONS . ' floor. Either the extractor stopped '
            . 'reading the production router, or the action surface was hollowed — a green run here '
            . 'would be meaningless.'
        );

        $missing = array_values(array_diff($expected, $actual));
        $extra = array_values(array_diff($actual, $expected));

        $this->assertSame(
            [],
            $missing,
            'phlix-ui MAINTENANCE_TASK_NAMES declares a task whose POST route is NOT registered on '
            . 'the server (' . implode(', ', $missing) . '). The phlix-ui task list must not outrun '
            . 'the server surface — remove the name or register the route. Denominator — expected '
            . 'action suffixes: [' . implode(', ', $expected) . ']; actual registered: ['
            . implode(', ', $actual) . '].'
        );

        $this->assertSame(
            [],
            $extra,
            'the server registers an admin-maintenance POST route with NO phlix-ui '
            . 'MAINTENANCE_TASK_NAMES entry (' . implode(', ', $extra) . '). Add the matching task '
            . 'name to src/api/admin/maintenance.ts AND to '
            . 'PHLIX_UI_MAINTENANCE_TASK_NAMES in this file, in the same PR (the S330 mirror-pin: '
            . 'the admin Tasks page cannot fire a task it has no name for). Denominator — expected '
            . 'action suffixes: [' . implode(', ', $expected) . ']; actual registered: ['
            . implode(', ', $actual) . '].'
        );
    }

    /**
     * The core guard, half two: the server's full admin-maintenance path-template
     * set (three GET reads + five POST actions) must equal the phlix-ui
     * `MAINTENANCE_ENDPOINTS` mirror plus the derived `jobs/{id}` template, in
     * BOTH directions.
     */
    public function testTheFullRoutePathSetMatchesThePhlixUiEndpointPathsInBothDirections(): void
    {
        $expected = $this->expectedPathTemplates();
        $actual = $this->routePathTemplates();

        $this->assertGreaterThanOrEqual(
            self::MIN_EXPECTED_ADMIN_MAINTENANCE_ROUTES,
            count($actual),
            'ANTI-VACUITY: the production route table yielded only ' . count($actual)
            . ' admin-maintenance route(s) (' . implode(', ', $actual) . '), below the '
            . self::MIN_EXPECTED_ADMIN_MAINTENANCE_ROUTES . ' floor. Either the extractor stopped '
            . 'reading the production router, or the maintenance surface was hollowed — a green run '
            . 'here would be meaningless.'
        );

        $missing = array_values(array_diff($expected, $actual));
        $extra = array_values(array_diff($actual, $expected));

        $this->assertSame(
            [],
            $missing,
            'phlix-ui AdminMaintenanceApi declares an endpoint path (literal or the derived '
            . 'jobs/{id} template) whose route is NOT registered on the server (' . implode(', ', $missing)
            . '). The phlix-ui endpoint list must not outrun the server surface — remove the path or '
            . 'register the route. Denominator — expected route paths: [' . implode(', ', $expected)
            . ']; actual registered: [' . implode(', ', $actual) . '].'
        );

        $this->assertSame(
            [],
            $extra,
            'the server registers an admin-maintenance route with NO phlix-ui '
            . 'AdminMaintenanceApi endpoint path (' . implode(', ', $extra) . '). Add the matching '
            . 'path to MAINTENANCE_ENDPOINTS in src/api/admin/maintenance.ts AND to '
            . 'PHLIX_UI_MAINTENANCE_ENDPOINT_PATHS in this file, in the same PR (the S330 mirror-pin: '
            . 'the admin Tasks page cannot reach a route it has no endpoint for). Denominator — '
            . 'expected route paths: [' . implode(', ', $expected) . ']; actual registered: ['
            . implode(', ', $actual) . '].'
        );
    }

    /**
     * The mirror lists must be the lists this guard is named for, so a "fix"
     * that deletes a member instead of reverting a mutation cannot silence the
     * guard.
     */
    public function testTheMirrorListsStillCarryTheFiveTaskNamesAndEightEndpointPaths(): void
    {
        $this->assertSame(
            self::PHLIX_UI_MAINTENANCE_TASK_NAMES,
            [
                'storage-snapshot',
                'reap-scan-jobs',
                'reap-transcode-jobs',
                'cleanup-orphaned-stats',
                'dedupe-paths',
            ],
            'PHLIX_UI_MAINTENANCE_TASK_NAMES drifted from the phlix-ui MAINTENANCE_TASK_NAMES '
            . 'array — restore it. The guard must name the five tasks S77/S78 ship.'
        );

        $this->assertSame(
            self::PHLIX_UI_MAINTENANCE_ENDPOINT_PATHS,
            [
                '/api/v1/admin/maintenance/tasks',
                '/api/v1/admin/maintenance/jobs',
                '/api/v1/admin/maintenance/jobs/{id}',
                '/api/v1/admin/maintenance/storage-snapshot',
                '/api/v1/admin/maintenance/reap-scan-jobs',
                '/api/v1/admin/maintenance/reap-transcode-jobs',
                '/api/v1/admin/maintenance/cleanup-orphaned-stats',
                '/api/v1/admin/maintenance/dedupe-paths',
            ],
            'PHLIX_UI_MAINTENANCE_ENDPOINT_PATHS drifted from the phlix-ui MAINTENANCE_ENDPOINTS '
            . 'array (plus the derived jobs/{id} template) — restore it. The guard must name the '
            . 'eight paths S77/S78 ship.'
        );
    }

    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }
}
