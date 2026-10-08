<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use DI\ContainerBuilder;
use Phlix\Auth\UserRepository;
use Phlix\Collections\Collection;
use Phlix\Collections\CollectionManager;
use Phlix\Collections\CollectionWithItems;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\CollectionController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Middleware\AuthMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * Per-user collection ownership (Option A) — supersedes the M-5 interim
 * admin gate shipped in c53b1490, whose blanket 403s this file replaces with
 * owner-or-admin authz mirroring the syncplay room-visibility precedent
 * (1ef503b7): members act on rows they own, ACTIVE admins (UserRepository::
 * findAdminById — is_admin=1 AND status='active', never the soft flag) act on
 * everything, and a foreign row is refused with the byte-identical
 * "Collection not found" 404 so an ownership miss is indistinguishable from
 * absence (no 403 oracle).
 *
 * ## The shipped model this file pins
 *
 * - `migrations/112_collections_created_by.sql` gives `collections` a
 *   nullable `created_by`; the controller stamps the actor on create, every
 *   other handler guards through the unscoped `findById` parent lookup.
 * - READ predicate: `created_by = me OR created_by IS NULL` (lists are scoped
 *   in SQL via findAllVisibleTo / getCollectionsForLibraryVisibleTo; single
 *   reads gate on the fetched row). Admins see everything (raw findAll /
 *   getCollectionsForLibrary).
 * - WRITE predicate: strict `created_by === me`, OR active admin. A NULL
 *   (legacy unowned) row is readable by every authenticated user but writable
 *   ONLY by admins — the degenerate-case mirror of the old interim gate.
 * - The admin predicate resolves LAZILY: an own-row hit must never query the
 *   users table (pinned with expects(never()) on findAdminById).
 * - Absent identity fails closed to 401 `auth.required` in-handler (the
 *   belt beneath AuthMiddleware), never silently to "member with no rows".
 *
 * ## Discipline (inherited from the superseded CollectionsAdminGateTest)
 *
 * - Dispatch goes through the PRODUCTION router built by `new Application(...)`
 *   over `ContainerFactory::defaultProviders()` (the wire-path guard's recipe),
 *   so re-gating routes in `Application.php` reds this file.
 * - Refusals pin every manager mutator `never()` — a mutation that happens
 *   anyway cannot hide behind a correct status code.
 * - Success controls sit beside every refusal family so a blanket-deny bug
 *   cannot masquerade as the gate.
 *
 * NB: this file carries NO coverage-metadata annotation, deliberately. Per this
 * repo's policy (S141, enforced by CoverageMetadataPolicyTest) such a marker in
 * `tests/` silently DISCARDS every other file the test executes. The policy
 * check matches the token itself, so it must not be spelled out even in prose.
 */
final class CollectionsOwnerGateTest extends TestCase
{
    private const MEMBER_A = 'user-a';
    private const MEMBER_B = 'user-b';
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

        $this->tempDir = sys_get_temp_dir() . '/phlix_owner_gate_' . uniqid('', true);
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
        // Container graph resolution constructs MediaAssetJobStore /
        // SimilarityJobStore through MediaServicesProvider factories, which mint
        // the shared /tmp queue dirs in their constructors. Sweep so the suite
        // leaves zero residue (S439) — without this the class is the last
        // minter whenever random order places it after every sweeping sibling,
        // and the ZeroResidueCensus reddens the run.
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
     * The six ID-gated write routes (the parent `findById` row decides).
     * `create` is NOT here: it has no parent row — its contract is owner
     * stamping, pinned separately.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
     *   `[VERB, concrete path, manager mutator, request body]`
     */
    public static function gatedWriteRouteProvider(): array
    {
        return [
            'PUT /api/v1/collections/{id}' => [
                'PUT', '/api/v1/collections/col-1', 'update',
                ['name' => 'Renamed'],
            ],
            'DELETE /api/v1/collections/{id}' => [
                'DELETE', '/api/v1/collections/col-1', 'delete',
                [],
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

    /**
     * The two `create` registrations share one handler — the stamping contract
     * must hold on both paths, and the anonymous-401 sweep covers all eight.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
     */
    public static function createRouteProvider(): array
    {
        return [
            'POST /api/v1/collections (create)' => [
                'POST', '/api/v1/collections', 'create',
                ['name' => 'Mine now', 'library_id' => 'lib-1'],
            ],
            'POST /api/v1/playlists (create alias)' => [
                'POST', '/api/v1/playlists', 'create',
                ['name' => 'Road trip', 'library_id' => 'lib-1'],
            ],
        ];
    }

    /**
     * All eight write registrations (six gated + the two `create` paths).
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
     */
    public static function writeRouteProvider(): array
    {
        return self::gatedWriteRouteProvider() + self::createRouteProvider();
    }

    // -----------------------------------------------------------------
    // Writes: member on OWN row → handler reached, mutation happens
    // -----------------------------------------------------------------

    /**
     * @dataProvider gatedWriteRouteProvider
     * @param array<string, mixed> $body
     */
    public function testMemberWriteOnOwnCollectionReachesHandler(
        string $verb,
        string $path,
        string $mutator,
        array $body
    ): void {
        $manager = $this->managerReturning($this->collection(self::MEMBER_A));
        $manager->expects(self::once())->method($mutator);

        // Laziness pin: an own-row hit must never resolve the admin predicate.
        $users = $this->usersDouble(activeAdmin: true);
        $users->expects(self::never())->method('findAdminById');

        $app = $this->application($manager, $users);

        $response = $this->dispatch($app, $verb, $path, self::MEMBER_A, $body);

        self::assertSame(
            200,
            $response->statusCode,
            "{$verb} {$path} must reach CollectionController for the OWNER (the interim"
            . " gate's member 403s close with ownership authz; got {$response->statusCode})"
        );
    }

    // -----------------------------------------------------------------
    // Writes: member on FOREIGN row → 404 indistinguishable from absence
    // -----------------------------------------------------------------

    /**
     * @dataProvider gatedWriteRouteProvider
     * @param array<string, mixed> $body
     */
    public function testMemberWriteOnForeignCollectionIsRefusedLikeAbsence(
        string $verb,
        string $path,
        string $mutator,
        array $body
    ): void {
        $manager = $this->managerReturning($this->collection(self::MEMBER_B));
        $this->pinForbidden($manager, [$mutator]);

        $app = $this->application($manager, $this->usersDouble(activeAdmin: false));

        $response = $this->dispatch($app, $verb, $path, self::MEMBER_A, $body);

        $this->assertCollectionNotFound($response, "{$verb} {$path} as a foreign member");
    }

    // -----------------------------------------------------------------
    // Writes: member on NULL legacy row → refused like absence
    // -----------------------------------------------------------------

    /**
     * @dataProvider gatedWriteRouteProvider
     * @param array<string, mixed> $body
     */
    public function testMemberWriteOnLegacyNullCollectionIsRefusedLikeAbsence(
        string $verb,
        string $path,
        string $mutator,
        array $body
    ): void {
        $manager = $this->managerReturning($this->collection(null));
        $this->pinForbidden($manager, [$mutator]);

        // The NULL legacy branch MUST consult the admin predicate (that is how
        // it distinguishes "legacy, admin-writable" from "foreign"): lazy ??=
        // fires on the non-own path.
        $app = $this->application($manager, $this->usersDouble(activeAdmin: false));

        $response = $this->dispatch($app, $verb, $path, self::MEMBER_A, $body);

        $this->assertCollectionNotFound($response, "{$verb} {$path} on a legacy NULL-owned row");
    }

    // -----------------------------------------------------------------
    // Writes: ACTIVE admin on foreign/legacy rows → handler reached
    // -----------------------------------------------------------------

    /**
     * @dataProvider gatedWriteRouteProvider
     * @param array<string, mixed> $body
     */
    public function testActiveAdminWriteOnForeignCollectionReachesHandler(
        string $verb,
        string $path,
        string $mutator,
        array $body
    ): void {
        $manager = $this->managerReturning($this->collection(self::MEMBER_A));
        $manager->expects(self::once())->method($mutator);

        $app = $this->application($manager, $this->usersDouble(activeAdmin: true));

        $response = $this->dispatch($app, $verb, $path, self::ADMIN_ID, $body);

        self::assertSame(
            200,
            $response->statusCode,
            "{$verb} {$path} must reach the handler for an ACTIVE admin (omniscience arm)"
        );
    }

    /**
     * @dataProvider gatedWriteRouteProvider
     * @param array<string, mixed> $body
     */
    public function testActiveAdminWriteOnLegacyNullCollectionReachesHandler(
        string $verb,
        string $path,
        string $mutator,
        array $body
    ): void {
        $manager = $this->managerReturning($this->collection(null));
        $manager->expects(self::once())->method($mutator);

        $app = $this->application($manager, $this->usersDouble(activeAdmin: true));

        $response = $this->dispatch($app, $verb, $path, self::ADMIN_ID, $body);

        self::assertSame(
            200,
            $response->statusCode,
            "{$verb} {$path}: the NULL legacy row stays admin-writable (degenerate-case"
            . ' mirror of the retired interim gate)'
        );
    }

    // -----------------------------------------------------------------
    // Writes: anonymous → 401 auth.required from the wire, zero handler work
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

        $app = $this->application($manager, $this->usersDouble(activeAdmin: false));

        $response = $this->dispatch($app, $verb, $path, null, $body);

        self::assertSame(401, $response->statusCode, "{$verb} {$path} must 401 an anonymous caller");
        self::assertSame('auth.required', $this->decode($response)['code'] ?? null);
        // untouchedManager() pins every manager method never(): a handler that
        // ran at all violates the double.
    }

    // -----------------------------------------------------------------
    // Create: owner stamping on both registrations
    // -----------------------------------------------------------------

    /**
     * @dataProvider createRouteProvider
     * @param array<string, mixed> $body
     */
    public function testMemberCreateStampsActorAsOwner(
        string $verb,
        string $path,
        string $mutator,
        array $body
    ): void {
        $stamped = null;
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects(self::once())
            ->method('create')
            ->with(self::callback(function (Collection $collection) use (&$stamped): bool {
                $stamped = $collection->createdBy;
                return true;
            }));

        $users = $this->usersDouble(activeAdmin: false);
        $users->expects(self::never())->method('findAdminById');

        $app = $this->application($manager, $users);

        $response = $this->dispatch($app, $verb, $path, self::MEMBER_A, $body);

        self::assertSame(201, $response->statusCode, "{$verb} {$path} must let any authenticated user create");
        self::assertSame(
            self::MEMBER_A,
            $stamped,
            'manager->create() must receive the collection with created_by = actor'
        );
        self::assertSame(
            self::MEMBER_A,
            $this->decode($response)['collection']['created_by'] ?? null,
            'the response envelope exposes the new created_by field'
        );
    }

    // -----------------------------------------------------------------
    // Update: created_by is immutable — carried forward from the existing row
    // -----------------------------------------------------------------

    public function testUpdateCarriesExistingOwnerForward(): void
    {
        $manager = $this->managerReturning($this->collection(self::MEMBER_A));

        $passed = null;
        $manager->expects(self::once())
            ->method('update')
            ->with(self::callback(function (Collection $collection) use (&$passed): bool {
                $passed = $collection;
                return true;
            }));

        $app = $this->application($manager, $this->usersDouble(activeAdmin: false));

        $response = $this->dispatch(
            $app,
            'PUT',
            '/api/v1/collections/col-1',
            self::MEMBER_A,
            ['name' => 'Renamed']
        );

        self::assertSame(200, $response->statusCode);
        self::assertInstanceOf(Collection::class, $passed);
        self::assertSame('Renamed', $passed->name, 'the merge still applies the body');
        self::assertSame(
            self::MEMBER_A,
            $passed->createdBy,
            'update must NEVER re-strike ownership — created_by is carried forward'
        );
    }

    // -----------------------------------------------------------------
    // Reads: single collection — owner/NULL visible, foreign = absence 404
    // -----------------------------------------------------------------

    public function testMemberCanReadOwnCollection(): void
    {
        $manager = $this->managerReturning($this->collection(self::MEMBER_A));
        $users = $this->usersDouble(activeAdmin: false);
        $users->expects(self::never())->method('findAdminById');

        $app = $this->application($manager, $users);

        $response = $this->dispatch($app, 'GET', '/api/v1/collections/col-1', self::MEMBER_A, []);

        self::assertSame(200, $response->statusCode);
        self::assertSame(
            self::MEMBER_A,
            $this->decode($response)['collection']['created_by'] ?? null
        );
    }

    public function testMemberCanReadLegacyNullCollection(): void
    {
        $manager = $this->managerReturning($this->collection(null));

        $app = $this->application($manager, $this->usersDouble(activeAdmin: false));

        $response = $this->dispatch($app, 'GET', '/api/v1/collections/col-1', self::MEMBER_A, []);

        self::assertSame(
            200,
            $response->statusCode,
            'NULL = legacy unowned → visible to every authenticated user'
        );
    }

    public function testForeignSingleReadIsByteIdenticalToAbsence(): void
    {
        $users = $this->usersDouble(activeAdmin: false);

        $foreign = $this->application($this->managerReturning($this->collection(self::MEMBER_B)), $users);
        $missingApp = $this->application($this->managerReturning(null), $users);

        $asMember = $this->dispatch($foreign, 'GET', '/api/v1/collections/col-1', self::MEMBER_A, []);
        $asAbsence = $this->dispatch($missingApp, 'GET', '/api/v1/collections/col-1', self::MEMBER_A, []);

        $this->assertCollectionNotFound($asMember, 'single read of a foreign row');
        self::assertSame(
            $asAbsence->statusCode,
            $asMember->statusCode,
            'ownership miss must be indistinguishable from absence (status)'
        );
        self::assertSame(
            (string) $asAbsence->body,
            (string) $asMember->body,
            'ownership miss must be indistinguishable from absence (body bytes — no 403 oracle)'
        );
    }

    public function testActiveAdminCanReadForeignCollection(): void
    {
        $manager = $this->managerReturning($this->collection(self::MEMBER_A));

        $app = $this->application($manager, $this->usersDouble(activeAdmin: true));

        $response = $this->dispatch($app, 'GET', '/api/v1/collections/col-1', self::ADMIN_ID, []);

        self::assertSame(200, $response->statusCode, 'admin omniscience covers reads');
    }

    // -----------------------------------------------------------------
    // Reads: lists are SQL-scoped for members, raw for admins
    // -----------------------------------------------------------------

    public function testMemberIndexUsesScopedLookupNotGlobalFindAll(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects(self::once())
            ->method('findAllVisibleTo')
            ->with(self::MEMBER_A)
            ->willReturn([$this->collection(self::MEMBER_A), $this->collection(null)]);
        $manager->expects(self::never())->method('findAll');

        $app = $this->application($manager, $this->usersDouble(activeAdmin: false));

        $response = $this->dispatch($app, 'GET', '/api/v1/collections', self::MEMBER_A, []);

        self::assertSame(200, $response->statusCode);
        $decoded = $this->decode($response);
        self::assertCount(
            2,
            $decoded['collections'],
            'the member list carries own rows plus NULL legacy rows — scoped in SQL, not post-filtered'
        );
    }

    public function testAdminIndexUsesRawLookup(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects(self::once())
            ->method('findAll')
            ->willReturn([$this->collection(self::MEMBER_B)]);
        $manager->expects(self::never())->method('findAllVisibleTo');

        $app = $this->application($manager, $this->usersDouble(activeAdmin: true));

        $response = $this->dispatch($app, 'GET', '/api/v1/collections', self::ADMIN_ID, []);

        self::assertSame(200, $response->statusCode);
    }

    public function testMemberForLibraryUsesScopedLookupNotGlobalList(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects(self::once())
            ->method('getCollectionsForLibraryVisibleTo')
            ->with('lib-1', self::MEMBER_A)
            ->willReturn([]);
        $manager->expects(self::never())->method('getCollectionsForLibrary');

        $app = $this->application($manager, $this->usersDouble(activeAdmin: false));

        $response = $this->dispatch(
            $app,
            'GET',
            '/api/v1/libraries/lib-1/collections',
            self::MEMBER_A,
            []
        );

        self::assertSame(200, $response->statusCode);
        self::assertSame(['collections' => []], $this->decode($response));
    }

    public function testAdminForLibraryUsesRawList(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects(self::once())
            ->method('getCollectionsForLibrary')
            ->with('lib-1')
            ->willReturn([]);
        $manager->expects(self::never())->method('getCollectionsForLibraryVisibleTo');

        $app = $this->application($manager, $this->usersDouble(activeAdmin: true));

        $response = $this->dispatch($app, 'GET', '/api/v1/libraries/lib-1/collections', self::ADMIN_ID, []);

        self::assertSame(200, $response->statusCode);
    }

    // -----------------------------------------------------------------
    // Fail-closed belt: absent identity in-handler never guesses a scope
    // -----------------------------------------------------------------

    public function testHandlersRejectEmptyIdentityInPlaceWith401AuthRequired(): void
    {
        $manager = $this->untouchedManager();
        $controller = new CollectionController($manager, $this->usersDouble(activeAdmin: true));

        $cases = [
            'index'      => ['index', []],
            'create'     => ['create', ['name' => 'x', 'library_id' => 'lib-1']],
            'show'       => ['show', ['id' => self::COLLECTION_ID]],
            'update'     => ['update', ['id' => self::COLLECTION_ID]],
            'delete'     => ['delete', ['id' => self::COLLECTION_ID]],
            'addItem'    => ['addItem', ['id' => self::COLLECTION_ID, 'mediaItemId' => 'media-1']],
            'removeItem' => ['removeItem', ['id' => self::COLLECTION_ID, 'mediaItemId' => 'media-1']],
            'bulkAdd'    => ['bulkAdd', ['id' => self::COLLECTION_ID]],
            'refresh'    => ['refresh', ['id' => self::COLLECTION_ID]],
            'forLibrary' => ['forLibrary', ['libraryId' => 'lib-1']],
        ];

        foreach ($cases as $label => [$handler, $params]) {
            $request = new Request();
            $request->method = 'GET';
            $request->path = '/api/v1/collections';
            $request->body = [];
            // $request->userId stays NULL: unwired/direct invocation must not
            // guess a scope — neither "global admin view" nor "member user-".
            $response = $controller->{$handler}($request, $params);

            self::assertSame(401, $response->statusCode, "CollectionController::{$handler}() must 401 without identity");
            self::assertSame(
                ['error' => 'Unauthorized', 'code' => 'auth.required'],
                $this->decode($response),
                "CollectionController::{$handler}() must answer the AuthMiddleware-identical shape"
            );
        }
    }

    // -----------------------------------------------------------------
    // Route shape: ALL collection routes fold into a lone AuthMiddleware group
    // -----------------------------------------------------------------

    /**
     * The structural half of the fold c53b1490 documented as its revert path:
     * every collection registration — 3 reads + 8 writes — carries exactly one
     * AuthMiddleware, and the loader no longer touches AdminMiddleware at all
     * (the minimal container THROWS on that key, so any residual resolution
     * attempt reddens this test instead of degrading silently).
     */
    public function testEveryCollectionRouteCarriesLoneAuthMiddleware(): void
    {
        $router = $this->invokeLoader();

        $verbPaths = [
            'GET'    => [
                '/api/v1/collections',
                '/api/v1/collections/{id}',
                '/api/v1/libraries/{libraryId}/collections',
            ],
            'POST'   => [
                '/api/v1/collections',
                '/api/v1/playlists',
                '/api/v1/collections/{id}/items/{mediaItemId}',
                '/api/v1/collections/{id}/bulk-add',
                '/api/v1/collections/{id}/refresh',
            ],
            'PUT'    => ['/api/v1/collections/{id}'],
            'DELETE' => [
                '/api/v1/collections/{id}',
                '/api/v1/collections/{id}/items/{mediaItemId}',
            ],
        ];

        $checked = 0;
        foreach ($verbPaths as $verb => $paths) {
            foreach ($paths as $path) {
                $route = $this->findRoute($router, $verb, $path);
                self::assertNotNull($route, "{$verb} {$path} must be registered");
                /** @var array<int, mixed> $middleware */
                $middleware = $route['middleware'] ?? [];
                self::assertCount(1, $middleware, "{$verb} {$path} carries exactly one gate");
                self::assertInstanceOf(
                    AuthMiddleware::class,
                    $middleware[0],
                    "{$verb} {$path} must gate on AuthMiddleware — ownership authz lives in the handler"
                );
                $checked++;
            }
        }

        self::assertSame(11, $checked, '3 reads + 8 write registrations = the full collection surface');
    }

    // -----------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------

    /**
     * The refusal must be indistinguishable from the pre-existing "unknown id"
     * 404: exact status AND exact body, no code key, no admin vocabulary.
     *
     * @param non-empty-string $context
     */
    private function assertCollectionNotFound(Response $response, string $context): void
    {
        self::assertSame(404, $response->statusCode, "{$context} must 404 like an absent row");
        self::assertSame(
            ['error' => 'Collection not found'],
            $this->decode($response),
            "{$context} must answer the byte-identical not-found shape (no 403 oracle)"
        );
    }

    private function dispatch(
        Application $app,
        string $verb,
        string $path,
        ?string $userId,
        array $body
    ): Response {
        // One dispatch per RequestContext generation: AuthMiddleware publishes
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
     * Manager double on which NOTHING may happen: every read and mutator the
     * collection handlers can call is pinned never().
     *
     * @return CollectionManager&\PHPUnit\Framework\MockObject\MockObject
     */
    private function untouchedManager(): CollectionManager
    {
        $manager = $this->createMock(CollectionManager::class);
        $this->pinForbidden(
            $manager,
            [
                'create', 'update', 'delete', 'addItem', 'removeItem',
                'bulkAddFromSearch', 'refreshSmartCollection', 'findById',
                'getCollectionWithItems', 'findAll', 'findAllVisibleTo',
                'getCollectionsForLibrary', 'getCollectionsForLibraryVisibleTo',
            ]
        );
        return $manager;
    }

    /**
     * @param CollectionManager&\PHPUnit\Framework\MockObject\MockObject $manager
     * @param list<string>                                               $methods
     */
    private function pinForbidden(CollectionManager $manager, array $methods): void
    {
        foreach ($methods as $forbidden) {
            $manager->expects(self::never())->method($forbidden);
        }
    }

    /**
     * Manager double answering `findById`/`getCollectionWithItems` with a row
     * owned by $ownedBy (null = legacy unowned); refresh-capable fixture rows
     * are smart so the post-gate handler path completes instead of 400ing.
     *
     * @return CollectionManager&\PHPUnit\Framework\MockObject\MockObject
     */
    private function managerReturning(?Collection $collection): CollectionManager
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->method('findById')->willReturn($collection);
        $manager->method('getCollectionWithItems')->willReturn(
            $collection === null
                ? null
                : new CollectionWithItems(collection: $collection, items: [], total: 0)
        );
        return $manager;
    }

    private function collection(?string $ownedBy): Collection
    {
        return new Collection(
            id: self::COLLECTION_ID,
            name: 'Owner gate fixture',
            libraryId: 'lib-1',
            smartPlaylistId: 'smart-1',
            parentId: null,
            createdBy: $ownedBy,
        );
    }

    /**
     * UserRepository double behind the controller's admin predicate.
     * `findAdminById` is the ONLY admin truth (UserRepository itself encodes
     * is_admin=1 AND status='active'); a soft-flagged/deactivated admin simply
     * resolves null here and follows the member path — that IS the arm under
     * test, no extra mock state needed.
     *
     * @return UserRepository&\PHPUnit\Framework\MockObject\MockObject
     */
    private function usersDouble(bool $activeAdmin): UserRepository
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturnCallback(
            static fn (string $id): ?array => $activeAdmin && $id === self::ADMIN_ID
                ? ['id' => $id, 'is_admin' => 1, 'status' => 'active']
                : null
        );
        return $users;
    }

    /**
     * Production provider stack (the wire-path guard's recipe) with the three
     * collaborators this gate observes doubled: the MySQL {@see Connection},
     * the {@see CollectionManager} behind the controller, and the
     * {@see UserRepository} behind the admin predicate.
     */
    private function application(CollectionManager $manager, UserRepository $users): Application
    {
        $connection = $this->createMock(Connection::class);
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getPooledConnection')->willReturn($connection);

        $audit = $this->createMock(AuditLogger::class);
        $audit->method('logPermissionDenied')->willReturnSelf();

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
     * the router it registered onto. AdminMiddleware::class deliberately
     * THROWS — post-fold, nothing in the collection loaders may resolve it.
     */
    private function invokeLoader(): Router
    {
        $manager = $this->createMock(CollectionManager::class);
        $users = $this->createMock(UserRepository::class);

        $container = new class ($manager, $users) implements ContainerInterface {
            public function __construct(
                private readonly CollectionManager $manager,
                private readonly UserRepository $users,
            ) {
            }

            public function get(string $id): mixed
            {
                if ($id === CollectionManager::class) {
                    return $this->manager;
                }
                if ($id === UserRepository::class) {
                    return $this->users;
                }
                if ($id === AdminMiddleware::class) {
                    throw new \RuntimeException(
                        'the folded collection loaders must never resolve AdminMiddleware again'
                    );
                }
                throw new \RuntimeException("Unexpected container get: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === CollectionManager::class || $id === UserRepository::class;
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
