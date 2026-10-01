<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use DI\ContainerBuilder;
use Phlix\Auth\UserRepository;
use Phlix\Collections\Collection;
use Phlix\Collections\CollectionManager;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * M-5 interim gate — collection MUTATION routes require admin, reads do not.
 *
 * ## Why this file exists
 *
 * `migrations/005_collections.sql` carries no owner column, so every row in
 * `collections` is server-global: while the ownership model (open owner
 * decision #6) is unresolved, ANY authenticated user — kid profiles included
 * — could create, rename, delete and bulk-mutate the shared collection set
 * through the ten `AuthMiddleware`-only routes in
 * {@see \Phlix\Server\Core\Application::loadCollectionRoutes()}.
 *
 * Interim owner ruling: reads stay member-accessible; writes require admin.
 * The writes moved into a lone `[AdminMiddleware]` group — the same idiom as
 * `DELETE /api/v1/media/{id}` (Step 11.6): AdminMiddleware itself answers
 * 401 `auth.required` for an anonymous caller and 403 `auth.not_admin` (plus a
 * `logPermissionDenied` audit row) for an authenticated non-admin, so no
 * AuthMiddleware companion is stacked on the group.
 *
 * ## Discipline
 *
 * - Dispatch goes through the PRODUCTION router built by `new Application(...)`
 *   over `ContainerFactory::defaultProviders()` (the same recipe
 *   `ApplicationRouterWirePathGuardTest` uses), so deleting or re-gating a
 *   route in `Application.php` reds this file — a hand-registered router could
 *   never observe the production wiring.
 * - The mutation never happens silently: every manager method the write
 *   handlers call is pinned `never()` on the refusal paths. Pre-gate, the
 *   non-admin dispatch reached the handler and called the mutator — that was
 *   the measured red this file exists to keep green.
 * - The admin success cases sit beside the refusals so a blanket-deny bug
 *   cannot masquerade as the gate.
 * - Fail-closed shape: when `AdminMiddleware` cannot be resolved, the write
 *   group is NOT registered (404 from the router) while the read group keeps
 *   serving — mirroring the structural fail-closed the sibling gates pin.
 *
 * NB: this file carries NO coverage-metadata annotation, deliberately. Per this
 * repo's policy (S141, enforced by CoverageMetadataPolicyTest) such a marker in
 * `tests/` silently DISCARDS every other file the test executes. The policy
 * check matches the token itself, so it must not be spelled out even in prose.
 */
final class CollectionsAdminGateTest extends TestCase
{
    private const MEMBER_ID = 'user-1';
    private const ADMIN_ID = 'admin-1';
    private const COLLECTION_ID = 'col-1';

    private string $tempDir = '';
    private string $loggerConfigPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        // AccessScheduleMiddleware is registered GLOBALLY by the Application
        // constructor and keys off the process-static RequestContext; a leaked
        // user id from a previous dispatch would turn unrelated controls into
        // 403s. Cleared on both ends, exactly as the wire-path guard does.
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        $this->tempDir = sys_get_temp_dir() . '/phlix_m5_gate_' . uniqid('', true);
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
     * Every write route and the manager mutator its handler calls (directly or
     * after a `findById` lookup). This is the complete M-5 write surface at
     * gate time — 8 registrations over 7 distinct handler methods (`create` is
     * wired twice: `/collections` and the `/playlists` alias).
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
     *   `[VERB, concrete path, manager mutator, request body]`
     */
    public static function writeRouteProvider(): array
    {
        return [
            'POST /api/v1/collections' => [
                'POST', '/api/v1/collections', 'create',
                ['name' => 'Watched stuff', 'library_id' => 'lib-1'],
            ],
            'PUT /api/v1/collections/{id}' => [
                'PUT', '/api/v1/collections/col-1', 'update',
                ['name' => 'Renamed'],
            ],
            'DELETE /api/v1/collections/{id}' => [
                'DELETE', '/api/v1/collections/col-1', 'delete',
                [],
            ],
            'POST /api/v1/playlists (alias)' => [
                'POST', '/api/v1/playlists', 'create',
                ['name' => 'Road trip', 'library_id' => 'lib-1'],
            ],
            'POST /api/v1/collections/{id}/items/{mediaItemId}' => [
                'POST', '/api/v1/collections/col-1/items/media-1', 'addItem',
                [],
            ],
            'DELETE /api/v1/collections/{id}/items/{mediaItemId}' => [
                'DELETE', '/api/v1/collections/col-1/items/media-1', 'removeItem',
                [],
            ],
            'POST /api/v1/collections/{id}/bulk-add' => [
                'POST', '/api/v1/collections/col-1/bulk-add', 'bulkAddFromSearch',
                ['media_item_ids' => ['media-1']],
            ],
            'POST /api/v1/collections/{id}/refresh' => [
                'POST', '/api/v1/collections/col-1/refresh', 'refreshSmartCollection',
                [],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Refusal: authenticated NON-ADMIN → 403 auth.not_admin, no mutation
    // -----------------------------------------------------------------

    /**
     * @dataProvider writeRouteProvider
     * @param array<string, mixed> $body
     */
    public function testNonAdminWriteIsRefusedWith403AndNeverMutates(
        string $verb,
        string $path,
        string $mutator,
        array $body
    ): void {
        $manager = $this->untouchedManager();
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())
            ->method('logPermissionDenied')
            ->with(self::MEMBER_ID, 'admin', 'access')
            ->willReturnSelf();

        $app = $this->application($manager, isAdmin: false, audit: $audit);

        $response = $this->dispatch($app, $verb, $path, self::MEMBER_ID, $body);

        self::assertSame(
            403,
            $response->statusCode,
            "{$verb} {$path} must 403 an authenticated NON-ADMIN while the collections"
            . ' ownership model is unresolved (any 2xx here means the shared collection'
            . ' set is still world-writable)'
        );
        self::assertSame(
            'auth.not_admin',
            $this->decode($response)['code'] ?? null,
            "{$verb} {$path} must refuse on the ADMIN branch, not the auth branch"
        );
        // The refusal must happen BEFORE the handler read or wrote anything:
        // every manager method on this double (findById included) is pinned
        // never() by untouchedManager(), so a red here means the request
        // reached the mutation handler.
    }

    // -----------------------------------------------------------------
    // Refusal: anonymous → 401 auth.required, no lookup, no audit
    // -----------------------------------------------------------------

    /**
     * @dataProvider writeRouteProvider
     * @param array<string, mixed> $body
     */
    public function testAnonymousWriteIsRejectedWith401BeforeAnyHandlerWork(
        string $verb,
        string $path,
        string $mutator,
        array $body
    ): void {
        $manager = $this->untouchedManager();
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::never())->method('logPermissionDenied');

        $app = $this->application($manager, isAdmin: false, audit: $audit);

        $response = $this->dispatch($app, $verb, $path, null, $body);

        self::assertSame(401, $response->statusCode, "{$verb} {$path} must 401 an anonymous caller");
        self::assertSame('auth.required', $this->decode($response)['code'] ?? null);
        // untouchedManager() pins every manager method never(): a handler that
        // ran at all violates the double.
    }

    // -----------------------------------------------------------------
    // Success control: ADMIN write passes through UNCHANGED
    // -----------------------------------------------------------------

    /**
     * @dataProvider writeRouteProvider
     * @param array<string, mixed> $body
     */
    public function testAdminWriteReachesHandlerAndMutates(
        string $verb,
        string $path,
        string $mutator,
        array $body
    ): void {
        $manager = $this->willingManager();
        $manager->expects(self::once())->method($mutator);
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::never())->method('logPermissionDenied');

        $app = $this->application($manager, isAdmin: true, audit: $audit);

        $response = $this->dispatch($app, $verb, $path, self::ADMIN_ID, $body);

        // The handler's own success envelope (201 create, 200 otherwise) — the
        // gate must be transparent for admins, not merely permissive.
        $expected = $mutator === 'create' ? 201 : 200;
        self::assertSame(
            $expected,
            $response->statusCode,
            "{$verb} {$path} must reach CollectionController::{$mutator}() for an admin"
            . " (got {$response->statusCode})"
        );
    }

    // -----------------------------------------------------------------
    // Reads stay member-accessible — no regression
    // -----------------------------------------------------------------

    public function testNonAdminCanStillListCollections(): void
    {
        $manager = $this->untouchedManager();
        $manager->method('findAll')->willReturn([$this->collection(smart: false)]);

        $app = $this->application($manager, isAdmin: false, audit: $this->silentAudit());

        $response = $this->dispatch($app, 'GET', '/api/v1/collections', self::MEMBER_ID, []);

        self::assertSame(200, $response->statusCode, 'collection reads stay member-accessible');
        $decoded = $this->decode($response);
        self::assertArrayHasKey('collections', $decoded);
        self::assertCount(1, $decoded['collections']);
    }

    public function testNonAdminCanStillReadSingleCollectionAndLibraryScopedList(): void
    {
        $manager = $this->untouchedManager();
        $manager->method('getCollectionsForLibrary')->willReturn([]);

        $app = $this->application($manager, isAdmin: false, audit: $this->silentAudit());

        $show = $this->dispatch($app, 'GET', '/api/v1/collections/col-1', self::MEMBER_ID, []);
        // The manager double knows no such row → the HANDLER's 404, which proves
        // the request passed the gate and reached CollectionController::show().
        self::assertSame(404, $show->statusCode);
        self::assertStringNotContainsString('auth.', (string) $show->body);

        $forLibrary = $this->dispatch(
            $app,
            'GET',
            '/api/v1/libraries/lib-1/collections',
            self::MEMBER_ID,
            []
        );
        self::assertSame(200, $forLibrary->statusCode);
        self::assertSame(['collections' => []], $this->decode($forLibrary));
    }

    // -----------------------------------------------------------------
    // Fail-closed shape: unwired AdminMiddleware drops the WRITE group only
    // -----------------------------------------------------------------

    /**
     * Poisoning the shared `AdminMiddleware::class` container entry is not an
     * option against a full `Application` — sibling loaders (S272's
     * `getMediaMatchController()`) resolve it eagerly, deliberately un-caught,
     * during construction. So the fail-closed SHAPE of the collection loaders
     * specifically is asserted structurally, invoking the private loader over a
     * minimal container exactly like `DlnaAdminRoutesTest` does for the DLNA
     * group: a route the gate cannot protect must NOT be registered at all.
     */
    public function testWriteGroupVanishesWhenAdminGateUnavailable(): void
    {
        $router = $this->invokeLoader(adminResolvable: false);

        self::assertNotContains(
            '/api/v1/collections',
            $this->pathsFor($router, 'POST'),
            'an unresolvable AdminMiddleware must leave the write group unregistered'
            . ' (structural fail-closed), never registered-ungated'
        );
        self::assertContains(
            '/api/v1/collections',
            $this->pathsFor($router, 'GET'),
            'the read group must not depend on the admin gate'
        );
    }

    /**
     * The middleware SHAPE the dispatch tests above cannot see from the wire:
     * writes carry a lone AdminMiddleware, reads a lone AuthMiddleware — the
     * sibling idiom (no stacked auth companion), stated once structurally so a
     * future "just add AuthMiddleware back" edit is visible in this file too.
     */
    public function testGroupsCarryTheExpectedMiddlewareShape(): void
    {
        $router = $this->invokeLoader(adminResolvable: true);

        $create = $this->findRoute($router, 'POST', '/api/v1/collections');
        self::assertNotNull($create, 'POST /api/v1/collections must be registered');
        /** @var array<int, mixed> $writeMw */
        $writeMw = $create['middleware'] ?? [];
        self::assertCount(1, $writeMw, 'the write group carries exactly one gate');
        self::assertInstanceOf(AdminMiddleware::class, $writeMw[0]);

        $list = $this->findRoute($router, 'GET', '/api/v1/collections');
        self::assertNotNull($list, 'GET /api/v1/collections must be registered');
        /** @var array<int, mixed> $readMw */
        $readMw = $list['middleware'] ?? [];
        self::assertCount(1, $readMw, 'the read group carries exactly one gate');
        self::assertInstanceOf(
            \Phlix\Server\Http\Middleware\AuthMiddleware::class,
            $readMw[0]
        );
    }

    // -----------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------

    private function dispatch(
        Application $app,
        string $verb,
        string $path,
        ?string $userId,
        array $body
    ): Response {
        // One dispatch per RequestContext generation: the gate helpers publish
        // the user id on success, and the global schedule middleware reads it.
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        $request = new Request();
        $request->method = $verb;
        $request->path = $path;
        $request->remoteIp = '127.0.0.1';
        $request->userId = $userId;
        $request->body = $body;

        return $app->dispatch($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $body = json_decode((string) $response->body, true);
        self::assertIsArray($body, 'response body must be a JSON object');
        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * Manager double on which NOTHING may happen: every read the handlers do
     * (`findById`) and every mutator is pinned `never()`.
     *
     * @return CollectionManager&\PHPUnit\Framework\MockObject\MockObject
     */
    private function untouchedManager(): CollectionManager
    {
        $manager = $this->createMock(CollectionManager::class);
        foreach (
            [
                'create', 'update', 'delete', 'addItem', 'removeItem',
                'bulkAddFromSearch', 'refreshSmartCollection', 'findById',
            ] as $forbidden
        ) {
            $manager->expects(self::never())->method($forbidden);
        }
        return $manager;
    }

    /**
     * Manager double rigged so each write handler would COMPLETE its mutation
     * if reached: `findById` answers with an existing smart collection.
     *
     * @return CollectionManager&\PHPUnit\Framework\MockObject\MockObject
     */
    private function willingManager(): CollectionManager
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->method('findById')->willReturn($this->collection(smart: true));
        return $manager;
    }

    private function silentAudit(): AuditLogger
    {
        $audit = $this->createMock(AuditLogger::class);
        $audit->method('logPermissionDenied')->willReturnSelf();
        return $audit;
    }

    private function collection(bool $smart): Collection
    {
        return new Collection(
            id: self::COLLECTION_ID,
            name: 'Gate fixture',
            libraryId: 'lib-1',
            smartPlaylistId: $smart ? 'smart-1' : null,
            parentId: null,
        );
    }

    /**
     * Production provider stack (the wire-path guard's recipe) with the four
     * collaborators this gate observes doubled: the MySQL {@see Connection},
     * the {@see CollectionManager} behind the controller, and the
     * {@see UserRepository}/{@see AuditLogger} behind AdminMiddleware.
     */
    private function application(
        CollectionManager $manager,
        bool $isAdmin,
        AuditLogger $audit
    ): Application {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturnCallback(
            static fn (string $id): ?array => $isAdmin && $id === self::ADMIN_ID
                ? ['id' => $id, 'is_admin' => 1, 'status' => 'active']
                : null
        );

        $connection = $this->createMock(Connection::class);
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getPooledConnection')->willReturn($connection);

        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($connection, $manager, $users, $audit) implements ServiceProviderInterface {
            public function __construct(
                private readonly Connection $connection,
                private readonly CollectionManager $manager,
                private readonly UserRepository $users,
                private readonly AuditLogger $audit,
            ) {
            }

            public function register(ContainerBuilder $builder, array $appConfig): void
            {
                $connection = $this->connection;
                $manager = $this->manager;
                $users = $this->users;
                $audit = $this->audit;

                $builder->addDefinitions([
                    Connection::class => factory(static fn (): Connection => $connection),
                    CollectionManager::class => static fn (): CollectionManager => $manager,
                    UserRepository::class => static fn (): UserRepository => $users,
                    AuditLogger::class => static fn (): AuditLogger => $audit,
                ]);
            }
        };

        $container = ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ], $providers);

        /** @var ContainerInterface $container */
        return new Application($container, [], $pool);
    }

    /**
     * Invoke the private `loadCollectionRoutes()` on a bare Application over a
     * minimal container (DlnaAdminRoutesTest's structural pattern) and return
     * the router it registered onto.
     */
    private function invokeLoader(bool $adminResolvable): Router
    {
        $manager = $this->createMock(CollectionManager::class);

        $adminMiddleware = $adminResolvable
            ? new AdminMiddleware($this->createMock(UserRepository::class), $this->silentAudit())
            : null;

        $container = new class ($manager, $adminMiddleware) implements ContainerInterface {
            public function __construct(
                private readonly CollectionManager $manager,
                private readonly ?AdminMiddleware $adminMiddleware,
            ) {
            }

            public function get(string $id): mixed
            {
                if ($id === CollectionManager::class) {
                    return $this->manager;
                }
                if ($id === AdminMiddleware::class) {
                    if ($this->adminMiddleware === null) {
                        throw new \RuntimeException('admin gate unavailable');
                    }
                    return $this->adminMiddleware;
                }
                throw new \RuntimeException("Unexpected container get: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === CollectionManager::class || $id === AdminMiddleware::class;
            }
        };

        $ref = new \ReflectionClass(Application::class);
        /** @var Application $app */
        $app = $ref->newInstanceWithoutConstructor();

        $router = new Router();

        $routerProp = $ref->getProperty('router');
        $routerProp->setAccessible(true);
        $routerProp->setValue($app, $router);

        $containerProp = $ref->getProperty('container');
        $containerProp->setAccessible(true);
        $containerProp->setValue($app, $container);

        $loader = $ref->getMethod('loadCollectionRoutes');
        $loader->setAccessible(true);
        $loader->invoke($app);

        return $router;
    }

    /**
     * @return list<string> registered path literals for one verb
     */
    private function pathsFor(Router $router, string $verb): array
    {
        return array_column($router->getRoutes()[$verb] ?? [], 'path');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRoute(Router $router, string $verb, string $path): ?array
    {
        foreach ($router->getRoutes()[$verb] ?? [] as $route) {
            if (($route['path'] ?? null) === $path) {
                /** @var array<string, mixed> $route */
                return $route;
            }
        }
        return null;
    }
}
