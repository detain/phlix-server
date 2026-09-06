<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * S348 — the library-maintenance POST-route set must mirror phlix-ui's
 * `AdminLibrariesApi` maintenance-method list.
 *
 * ## The defect class (S332)
 *
 * phlix-ui's `AdminLibrariesApi` (src/api/admin/libraries.ts) hand-enumerates one
 * method per library maintenance endpoint. That hand list grows with the LIST,
 * not with the ROUTES: S284 shipped `POST /api/v1/libraries/{id}/regenerate-assets`
 * on the server and the admin UI never gained a `regenerateAssets` method, so the
 * admin UI could not trigger the re-prime and no gate went red. This file is the
 * cross-repo pin that makes that class of drift loud.
 *
 * ## Why the assertions are shaped the way they are
 *
 * - **The route set is PARSED from the PRODUCTION route table**, not hand-written.
 *   The table is reflected off an `Application` built from
 *   `ContainerFactory::defaultProviders()` — the same provider stack
 *   `public/index.php` and the Workerman daemon use — with only the MySQL
 *   {@see Connection} doubled. A hand-written fixture cannot detect that the real
 *   thing differs (S345); this file extracts what is actually registered.
 * - **The denominator is printed.** Every failure message names the maintenance
 *   suffix set the guard examined and the phlix-ui mirror it was compared to.
 * - **Exact whole-list comparison in BOTH directions; no substring matching.**
 *   `'regenerate-assets-MUTATED'` CONTAINS `'regenerate-assets'`, so a
 *   `str_contains`/`assertContains` assertion would pass the exact mutation it
 *   exists to catch (S37/S236/S239). The guard instead splits the symmetric
 *   difference into `missing` (a UI method with no server route) and `extra`
 *   (a server route with no UI method) and asserts each is empty.
 * - **The mirror is the phlix-ui method NAMES.** {@see self::PHLIX_UI_MAINTENANCE_METHODS}
 *   spells the `AdminLibrariesApi` method identifiers (`scan`, `rescan`,
 *   `matchMetadata`, …) exactly as they appear in that class; the URL suffix each
 *   posts to is derived mechanically (camelCase → kebab-case). Adding a server
 *   maintenance route therefore requires updating BOTH the phlix-ui API method AND
 *   this list in the same PR — the guard cannot read the TypeScript source across
 *   repos, so the list is the proxy for it, exactly as in the S341 pin
 *   (`ScanJobRepository::ALLOWED_TYPES` vs the migration ENUM).
 *
 * ## What counts as a library-maintenance route
 *
 * A route counts when ALL of these hold:
 *  - verb is `POST`;
 *  - the literal path is `/api/v1/libraries/{id}/<suffix>` with exactly ONE
 *    single-segment `<suffix>` (`[a-z][a-z0-9-]*`);
 *  - the handler is a `[LibraryController, method]` pair.
 *
 * That selects exactly the nine async maintenance actions the admin UI mirrors
 * (`scan`, `rescan`, `match-metadata`, `refresh-metadata`, `prune`,
 * `clear-metadata`, `clear-artwork`, `delete-all`, `regenerate-assets`). It
 * deliberately EXCLUDES the read surface (`GET .../scan-status`, `GET
 * .../scan-history`) and the theme-media surface (`POST
 * .../{id}/theme-media/scan` is two segments and a different controller) —
 * neither has a mirror in `AdminLibrariesApi`.
 *
 * ## Coverage statement
 *
 * This file pins REGISTRATION only, the same strength as the S239 manifest's
 * "all rails" pass: a maintenance route that is renamed, deleted, re-verbed,
 * re-pointed away from `LibraryController`, or added without a mirror update reds
 * it. It does NOT dispatch the routes — response envelopes, status codes and the
 * in-handler admin gate are pinned by S284's
 * {@see \Phlix\Tests\Unit\Server\Http\Controllers\LibraryRegenerateAssetsAdminGateTest}
 * and the S272 destructive-actions gate test. The S239 file still pins the full
 * 353-route manifest including these nine literals.
 *
 * @see \Phlix\Tests\Unit\Server\Core\ApplicationRouterWirePathGuardTest S239, whose
 *      route-table reflection this file reuses and whose no-substring rule it follows.
 * @see \Phlix\Tests\Unit\Common\Database\ScanJobsEnumMigrationTest S341, the
 *      mirror-pin precedent this file is shaped after.
 */
final class LibraryMaintenanceRoutesUiMirrorGuardTest extends TestCase
{
    /**
     * The phlix-ui `AdminLibrariesApi` maintenance-method identifiers, in the
     * order that class declares them (src/api/admin/libraries.ts).
     *
     * ## How to change this list
     *
     * Adding, renaming or removing a library maintenance method in phlix-ui's
     * `AdminLibrariesApi` MUST be accompanied by the matching edit here, in the
     * same commit — and by the matching server route. The kebab-case URL suffix
     * each method posts to is derived by {@see self::urlSuffixForMethod()}; do not
     * store suffixes here, store the method names the mirror is named for.
     *
     * @var list<string>
     */
    private const PHLIX_UI_MAINTENANCE_METHODS = [
        'scan',
        'rescan',
        'matchMetadata',
        'refreshMetadata',
        'prune',
        'clearMetadata',
        'clearArtwork',
        'deleteAll',
        'regenerateAssets',
    ];

    /**
     * Lower bound on the size of a sane maintenance-route set.
     *
     * Its only job is ANTI-VACUITY. If the route-table extractor stops reading
     * the production router, or the selector regex stops matching, the
     * missing/extra assertions below would otherwise pass with an EMPTY actual
     * set. 8 sits below the measured 9 and far above the 0 a broken extractor
     * yields, so it separates "the extractor was hollowed" from "the surface
     * genuinely shrank" (the latter also requires editing this file's mirror
     * list, which is itself reviewed).
     */
    private const MIN_EXPECTED_MAINTENANCE_ROUTES = 8;

    /**
     * A library-maintenance route: `POST /api/v1/libraries/{id}/<suffix>` with a
     * single `<suffix>` segment. Anchored both ends — no substring matching, so a
     * mutated longer/shorter sibling can never satisfy it.
     */
    private const MAINTENANCE_PATH_PATTERN = '#^/api/v1/libraries/\{id\}/([a-z][a-z0-9-]*)$#';

    private string $tempDir = '';
    private string $loggerConfigPath = '';
    private ?ContainerInterface $sharedContainer = null;
    private ?Application $sharedApplication = null;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        // Cleared for the same reason the S239 guard clears it: AccessScheduleMiddleware
        // is registered GLOBALLY and reads RequestContext, so a sibling test that left a
        // user id set would poison any future dispatch controls in this file.
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        $this->tempDir = sys_get_temp_dir() . '/phlix_s348_' . uniqid('', true);
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
     * The maintenance-route suffix set of the PRODUCTION route table
     * `Application::dispatch()` delegates to — parsed, never hand-written.
     *
     * Fails LOUDLY if the `router` property no longer holds a {@see Router} or a
     * maintenance-route path stops carrying a `[LibraryController, method]` pair,
     * so a hollowed extractor cannot masquerade as an empty surface.
     *
     * @return list<string> sorted kebab-case suffixes (e.g. `regenerate-assets`)
     */
    private function maintenanceSuffixes(): array
    {
        $property = (new ReflectionClass(Application::class))->getProperty('router');
        $property->setAccessible(true);
        $router = $property->getValue($this->application());

        $this->assertInstanceOf(
            Router::class,
            $router,
            'ANTI-VACUITY: Application::$router did not hold a Router instance, so the '
            . 'library-maintenance route set could not be read from the production table.'
        );

        /** @var array<string, array<string, array<string, mixed>>> $routes */
        $routes = $router->getRoutes();

        $suffixes = [];
        foreach ($routes as $method => $entries) {
            foreach ($entries as $entry) {
                if ($method !== 'POST') {
                    continue;
                }

                $path = $entry['path'] ?? null;
                if (!is_string($path) || preg_match(self::MAINTENANCE_PATH_PATTERN, $path, $match) !== 1) {
                    continue;
                }

                $handler = $entry['handler'] ?? null;
                $this->assertIsArray(
                    $handler,
                    "maintenance route {$path} must bind a [target, method] pair, not a closure"
                );
                $target = $handler[0];
                $this->assertTrue(
                    is_object($target) || is_string($target),
                    "maintenance route {$path} must bind an object or a class-string handler target"
                );
                $this->assertSame(
                    'LibraryController',
                    $this->shortName(is_object($target) ? $target::class : $target),
                    "maintenance route {$path} must be handled by LibraryController, not a sibling "
                    . 'controller — the phlix-ui AdminLibrariesApi mirror is scoped to that class'
                );

                $suffixes[] = $match[1];
            }
        }

        sort($suffixes);

        return $suffixes;
    }

    /**
     * The expected suffix set, derived from the phlix-ui mirror method names.
     *
     * @return list<string> sorted kebab-case suffixes
     */
    private function expectedSuffixes(): array
    {
        $suffixes = array_map(
            static fn (string $method): string => strtolower((string) preg_replace(
                '/(?<!^)[A-Z]/',
                '-$0',
                $method
            )),
            self::PHLIX_UI_MAINTENANCE_METHODS
        );

        sort($suffixes);

        return $suffixes;
    }

    /**
     * The core guard: the server's maintenance POST-route set must equal the
     * phlix-ui `AdminLibrariesApi` mirror, in BOTH directions.
     */
    public function testTheLibraryMaintenancePostRouteSetMatchesThePhlixUiMirrorInBothDirections(): void
    {
        $expected = $this->expectedSuffixes();
        $actual = $this->maintenanceSuffixes();

        $this->assertGreaterThanOrEqual(
            self::MIN_EXPECTED_MAINTENANCE_ROUTES,
            count($actual),
            'ANTI-VACUITY: the production route table yielded only ' . count($actual)
            . ' library-maintenance route(s) (' . implode(', ', $actual) . '), below the '
            . self::MIN_EXPECTED_MAINTENANCE_ROUTES . ' floor. Either the extractor stopped '
            . 'reading the production router, or the maintenance surface was hollowed — a '
            . 'green run here would be meaningless.'
        );

        $missing = array_values(array_diff($expected, $actual));
        $extra = array_values(array_diff($actual, $expected));

        $this->assertSame(
            [],
            $missing,
            'phlix-ui AdminLibrariesApi declares a maintenance method whose POST route is NOT '
            . 'registered on the server (' . implode(', ', $missing) . '). The phlix-ui method '
            . 'list must not outrun the server surface — remove the method or register the route. '
            . 'Denominator — expected maintenance suffixes: [' . implode(', ', $expected)
            . ']; actual registered: [' . implode(', ', $actual) . '].'
        );

        $this->assertSame(
            [],
            $extra,
            'the server registers a library-maintenance POST route with NO phlix-ui '
            . 'AdminLibrariesApi method (' . implode(', ', $extra) . '). Add the matching method '
            . 'to src/api/admin/libraries.ts AND to PHLIX_UI_MAINTENANCE_METHODS in this file, '
            . 'in the same PR (the S348 mirror-pin: the admin UI cannot trigger a route it has '
            . 'no method for). Denominator — expected maintenance suffixes: ['
            . implode(', ', $expected) . ']; actual registered: [' . implode(', ', $actual) . '].'
        );
    }

    /**
     * The mirror list must be the list this guard is named for, so a "fix" that
     * deletes a member instead of reverting a mutation cannot silence the guard.
     */
    public function testTheMirrorListStillCarriesTheNineMaintenanceMethods(): void
    {
        $this->assertSame(
            self::PHLIX_UI_MAINTENANCE_METHODS,
            [
                'scan',
                'rescan',
                'matchMetadata',
                'refreshMetadata',
                'prune',
                'clearMetadata',
                'clearArtwork',
                'deleteAll',
                'regenerateAssets',
            ],
            'PHLIX_UI_MAINTENANCE_METHODS drifted from the phlix-ui AdminLibrariesApi method '
            . 'set — restore it. The guard must name the nine methods S348 ships.'
        );
    }

    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }
}
