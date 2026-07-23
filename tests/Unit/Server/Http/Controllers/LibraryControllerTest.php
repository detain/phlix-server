<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Server\Http\Controllers\LibraryController;
use Phlix\Server\Http\Request;

/**
 * Unit tests for {@see LibraryController}.
 *
 * Covers the nine handler methods now wired in Application::loadLibraryRoutes():
 *   GET    /api/v1/libraries                    -> index
 *   GET    /api/v1/libraries/{id}                -> show
 *   POST   /api/v1/libraries                     -> create
 *   PUT    /api/v1/libraries/{id}               -> update
 *   DELETE /api/v1/libraries/{id}               -> delete
 *   POST   /api/v1/libraries/{id}/scan           -> scan         (202 enqueue)
 *   POST   /api/v1/libraries/{id}/rescan         -> rescan       (202 enqueue)
 *   GET    /api/v1/libraries/{id}/scan-status    -> scanStatus
 *   GET    /api/v1/libraries/{id}/scan-history   -> scanHistory
 *
 * Uses createMock(LibraryManager::class) + createMock(ScanJobRepository::class)
 * following the project's existing controller-test conventions (see
 * SessionControllerTest, AuthControllerTest).
 *
 * As of 1.1b the scan/rescan endpoints no longer run the scan inline — they
 * enqueue a job via ScanJobRepository and return 202; the async
 * LibraryScanWorker drains the queue off the HTTP path.
 *
 * Note: Tests that require AdminMiddleware verification (admin access checks)
 * are covered separately in integration tests with a real database. Unit tests
 * here verify the core controller logic and auth-required behavior.
 */
class LibraryControllerTest extends TestCase
{
    /**
     * Happy path: index() returns 200 with libraries list for authenticated user,
     * each enriched with item_count.
     *
     * The daemon path (this controller) must mirror the CGI
     * WebPortalRouter::getLibraries() shape — every library carries a per-row
     * item_count, counted via the item repository — so both dispatch surfaces
     * return identical responses.
     */
    public function testIndexReturns200WithLibrariesForAuthenticatedUser(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getAllLibraries')
            ->willReturn([
                ['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video'],
                ['id' => 'lib-2', 'name' => 'Music', 'type' => 'music'],
            ]);

        // countByType is called once per library with its (id, type), exactly as
        // WebPortalRouter::getLibraries() does it.
        $itemRepository = $this->createMock(ItemRepository::class);
        $itemRepository->expects($this->exactly(2))
            ->method('countByType')
            ->willReturnMap([
                ['lib-1', 'video', 42],
                ['lib-2', 'music', 7],
            ]);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs, $itemRepository);

        $request = new Request();
        $request->userId = 'user-1';

        $response = $controller->index($request, []);

        $this->assertSame(200, $response->statusCode);

        /** @var array{libraries: list<array<string, mixed>>} $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('libraries', $body);
        $this->assertCount(2, $body['libraries']);
        // item_count must be present per library and match the counted value
        // (contract parity with the CGI WebPortalRouter path).
        $this->assertArrayHasKey('item_count', $body['libraries'][0]);
        $this->assertSame(42, $body['libraries'][0]['item_count']);
        $this->assertArrayHasKey('item_count', $body['libraries'][1]);
        $this->assertSame(7, $body['libraries'][1]['item_count']);
    }

    /**
     * Back-compat: when no ItemRepository is wired (legacy two-arg
     * construction), index() still returns 200 with the libraries list but
     * omits item_count rather than faking a value.
     */
    public function testIndexOmitsItemCountWhenItemRepositoryNotWired(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getAllLibraries')
            ->willReturn([
                ['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video'],
            ]);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        // No ItemRepository injected.
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'user-1';

        $response = $controller->index($request, []);

        $this->assertSame(200, $response->statusCode);
        /** @var array{libraries: list<array<string, mixed>>} $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertCount(1, $body['libraries']);
        $this->assertArrayNotHasKey('item_count', $body['libraries'][0]);
    }

    /**
     * Negative: index() returns 401 when no userId is present.
     */
    public function testIndexReturns401WhenUnauthenticated(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('getAllLibraries');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        // request->userId intentionally left null

        $response = $controller->index($request, []);

        $this->assertSame(401, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Unauthorized', $body['error']);
    }

    /**
     * Happy path: show() returns 200 with library details for authenticated user.
     */
    public function testShowReturns200WithLibraryDetailsForAuthenticatedUser(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'user-1';

        $response = $controller->show($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);

        /** @var array{library: array<string, mixed>} $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('library', $body);
        $this->assertSame('lib-1', $body['library']['id']);
    }

    /**
     * Negative: show() returns 404 when library not found.
     */
    public function testShowReturns404WhenLibraryNotFound(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('nonexistent')
            ->willReturn(null);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'user-1';

        $response = $controller->show($request, ['id' => 'nonexistent']);

        $this->assertSame(404, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Library not found', $body['error']);
    }

    /**
     * Negative: show() returns 401 when unauthenticated.
     */
    public function testShowReturns401WhenUnauthenticated(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('getLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        // request->userId intentionally left null

        $response = $controller->show($request, ['id' => 'lib-1']);

        $this->assertSame(401, $response->statusCode);
    }

    /**
     * Happy path: create() returns 201 when called with valid data and no admin middleware set
     * (admin middleware bypass for unit testing - integration tests cover admin enforcement).
     */
    public function testCreateReturns201WhenValidDataProvided(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('createLibrary')
            ->with('Movies', 'movie', ['/mnt/movies'], [])
            ->willReturn('new-lib-id');

        // Note: AdminMiddleware is final and cannot be mocked in unit tests.
        // Admin enforcement is covered in integration tests.
        // Here we test the happy path without admin middleware set.
        // Create must enqueue an async scan job (the worker scans in the
        // background) rather than scanning inline, so create returns fast.
        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->once())
            ->method('enqueue')
            ->with('new-lib-id', 'scan')
            ->willReturn('job-1');
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => ['/mnt/movies'],
        ];

        $response = $controller->create($request, []);

        $this->assertSame(201, $response->statusCode);
        /** @var array{library_id: mixed, job_id: mixed, status: mixed, message: string} $body */
        $body = json_decode($response->body, true);
        $this->assertSame('new-lib-id', $body['library_id']);
        $this->assertSame('job-1', $body['job_id']);
        $this->assertSame('scanning', $body['status']);
        $this->assertStringContainsString('background', $body['message']);
    }

    /**
     * create() on a series library persists series_per_directory into options
     * (coerced to a real bool from the body top level).
     */
    public function testCreatePersistsSeriesPerDirectoryForSeriesLibrary(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('createLibrary')
            ->with('Anime', 'series', ['/vault1/anime'], ['series_per_directory' => true])
            ->willReturn('series-lib');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->method('enqueue')->willReturn('job-1');
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Anime',
            'type' => 'series',
            'paths' => ['/vault1/anime'],
            // Sent as the string "true" — must coerce to a real bool.
            'series_per_directory' => 'true',
        ];

        $response = $controller->create($request, []);

        $this->assertSame(201, $response->statusCode);
    }

    /**
     * create() also accepts series_per_directory nested inside `options` and
     * coerces an int 1 → true.
     */
    public function testCreateCoercesSeriesPerDirectoryInsideOptions(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('createLibrary')
            ->with('Anime', 'series', ['/vault1/anime'], ['series_per_directory' => true])
            ->willReturn('series-lib');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->method('enqueue')->willReturn('job-1');
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Anime',
            'type' => 'series',
            'paths' => ['/vault1/anime'],
            'options' => ['series_per_directory' => 1],
        ];

        $response = $controller->create($request, []);

        $this->assertSame(201, $response->statusCode);
    }

    /**
     * create() drops series_per_directory for a NON-series library type, even
     * when supplied — the option is series-only.
     */
    public function testCreateDropsSeriesPerDirectoryForNonSeriesLibrary(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('createLibrary')
            ->with('Movies', 'movie', ['/mnt/movies'], [])
            ->willReturn('movie-lib');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->method('enqueue')->willReturn('job-1');
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => ['/mnt/movies'],
            'series_per_directory' => true,
        ];

        $response = $controller->create($request, []);

        $this->assertSame(201, $response->statusCode);
    }

    /**
     * update() on a series library merges the coerced series_per_directory flag
     * into the EXISTING options blob (preserving other options).
     */
    public function testUpdateMergesSeriesPerDirectoryIntoExistingOptions(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn([
                'id' => 'lib-1',
                'name' => 'Anime',
                'type' => 'series',
                'options' => ['scan_interval' => 3600],
            ]);
        $libraryManager->expects($this->once())
            ->method('updateLibrary')
            ->with('lib-1', $this->callback(static function (mixed $data): bool {
                if (!is_array($data)) {
                    return false;
                }
                // The top-level key is stripped before delegating; the merged
                // options preserve scan_interval and carry the coerced bool.
                return !array_key_exists('series_per_directory', $data)
                    && is_array($data['options'] ?? null)
                    && $data['options']['scan_interval'] === 3600
                    && $data['options']['series_per_directory'] === true;
            }));

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['series_per_directory' => 'on'];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
    }

    /**
     * update() also handles series_per_directory that arrives ONLY nested inside
     * `options` (no top-level key), symmetrically with create(). The raw nested
     * value is coerced to a real bool and merged into the existing options blob.
     */
    public function testUpdatePersistsNestedOptionsSeriesPerDirectory(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn([
                'id' => 'lib-1',
                'name' => 'Anime',
                'type' => 'series',
                'options' => ['scan_interval' => 3600],
            ]);
        $libraryManager->expects($this->once())
            ->method('updateLibrary')
            ->with('lib-1', $this->callback(static function (mixed $data): bool {
                if (!is_array($data)) {
                    return false;
                }
                // No top-level key; the nested raw "true" is coerced to a real
                // bool and the existing scan_interval is preserved.
                return !array_key_exists('series_per_directory', $data)
                    && is_array($data['options'] ?? null)
                    && $data['options']['scan_interval'] === 3600
                    && $data['options']['series_per_directory'] === true;
            }));

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['options' => ['series_per_directory' => 'true']];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
    }

    /**
     * update() ignores series_per_directory for a non-series library — the flag
     * is stripped and the existing options are left untouched.
     */
    public function testUpdateIgnoresSeriesPerDirectoryForNonSeriesLibrary(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'movie']);
        $libraryManager->expects($this->once())
            ->method('updateLibrary')
            ->with('lib-1', $this->callback(static function (mixed $data): bool {
                // The top-level key is stripped; no options injected for a movie lib.
                return is_array($data)
                    && !array_key_exists('series_per_directory', $data)
                    && !array_key_exists('options', $data);
            }));

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['series_per_directory' => true];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
    }

    /**
     * S33 create(): the per-library autoCollections toggle round-trips through the
     * existing create endpoint, normalised to the canonical `{enabled: bool}` map
     * inside the persisted options blob.
     */
    public function testCreatePersistsAutoCollectionsToggleIntoOptions(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('createLibrary')
            ->with('Movies', 'movie', ['/vault1/movies'], ['autoCollections' => ['enabled' => false]])
            ->willReturn('lib-new');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->method('enqueue')->willReturn('job-1');
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => ['/vault1/movies'],
            'autoCollections' => false,
        ];

        $response = $controller->create($request, []);

        $this->assertSame(201, $response->statusCode);
    }

    /**
     * S33 update(): setting autoCollections MERGES into the existing options blob
     * (canonical `{enabled: bool}`), PRESERVING other stored options — proving the
     * update path does not strip/whitelist the key away.
     */
    public function testUpdateMergesAutoCollectionsIntoExistingOptions(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn([
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'movie',
                'options' => ['scan_interval' => 3600, 'image_types' => ['poster' => true]],
            ]);
        $libraryManager->expects($this->once())
            ->method('updateLibrary')
            ->with('lib-1', $this->callback(static function (mixed $data): bool {
                if (!is_array($data)) {
                    return false;
                }
                // Top-level key stripped; merged options keep scan_interval AND
                // image_types, and carry the canonical {enabled:false} map.
                return !array_key_exists('autoCollections', $data)
                    && is_array($data['options'] ?? null)
                    && ($data['options']['scan_interval'] ?? null) === 3600
                    && ($data['options']['image_types'] ?? null) === ['poster' => true]
                    && ($data['options']['autoCollections'] ?? null) === ['enabled' => false];
            }));

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['autoCollections' => ['enabled' => false]];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
    }

    /**
     * S33 update(): editing a DIFFERENT option (image_types) leaves a previously
     * stored autoCollections flag intact — the merge starts from the full existing
     * options blob, so the toggle is never silently wiped by an unrelated edit.
     */
    public function testUpdateImageTypesPreservesExistingAutoCollectionsFlag(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn([
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'movie',
                'options' => ['autoCollections' => ['enabled' => false]],
            ]);
        $libraryManager->expects($this->once())
            ->method('updateLibrary')
            ->with('lib-1', $this->callback(static function (mixed $data): bool {
                return is_array($data)
                    && is_array($data['options'] ?? null)
                    // The unrelated image_types edit did NOT drop autoCollections.
                    && ($data['options']['autoCollections'] ?? null) === ['enabled' => false];
            }));

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['image_types' => ['poster']];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
    }

    /**
     * S33 update(): a malformed autoCollections value (a map missing `enabled`)
     * is rejected with 400 and never delegated to the manager.
     */
    public function testUpdateRejectsMalformedAutoCollections(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getLibrary')->with('lib-1')->willReturn([
            'id' => 'lib-1',
            'name' => 'Movies',
            'type' => 'movie',
            'options' => [],
        ]);
        $libraryManager->expects($this->never())->method('updateLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['autoCollections' => ['not_enabled' => true]];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(400, $response->statusCode);
    }

    /**
     * S33 update(): a `{enabled: <value>}` map whose `enabled` value is a
     * non-scalar (an object/map, a list) or a nonsensical non-bool scalar (a
     * float) is malformed → 400, rather than being silently coerced to `false`
     * by toBool(). The manager is never called.
     *
     * @dataProvider provideNonBoolAutoCollectionsEnabled
     *
     * @param mixed $enabledValue The malformed `enabled` value to reject.
     */
    public function testUpdateRejectsNonBoolAutoCollectionsEnabled(mixed $enabledValue): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getLibrary')->with('lib-1')->willReturn([
            'id' => 'lib-1',
            'name' => 'Movies',
            'type' => 'movie',
            'options' => [],
        ]);
        $libraryManager->expects($this->never())->method('updateLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['autoCollections' => ['enabled' => $enabledValue]];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(400, $response->statusCode);
    }

    /**
     * Malformed `enabled` values that must be rejected with 400.
     *
     * @return array<string, array{mixed}>
     */
    public static function provideNonBoolAutoCollectionsEnabled(): array
    {
        return [
            'non-scalar map'  => [['x' => 1]],
            'list'            => [[1, 2]],
            'non-bool scalar' => [3.14],
        ];
    }

    /**
     * S33 create(): a valid `{enabled: bool}` map STILL succeeds and stores the
     * canonical `{enabled: bool}` shape — proving the non-bool tightening did NOT
     * over-reject a genuine boolean `enabled`.
     *
     * @dataProvider provideValidAutoCollectionsEnabledBool
     *
     * @param bool $enabled The valid boolean `enabled` value.
     */
    public function testCreateAcceptsValidBoolAutoCollectionsEnabled(bool $enabled): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('createLibrary')
            ->with('Movies', 'movie', ['/vault1/movies'], ['autoCollections' => ['enabled' => $enabled]])
            ->willReturn('lib-new');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->method('enqueue')->willReturn('job-1');
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => ['/vault1/movies'],
            'autoCollections' => ['enabled' => $enabled],
        ];

        $response = $controller->create($request, []);

        $this->assertSame(201, $response->statusCode);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function provideValidAutoCollectionsEnabledBool(): array
    {
        return [
            'enabled true'  => [true],
            'enabled false' => [false],
        ];
    }

    /**
     * S33 create(): a bare top-level boolean is ALSO an accepted form (documented
     * shorthand for `{enabled: bool}`) and STILL stores the canonical
     * `{enabled: bool}` shape — proving the tightening left the top-level bool path
     * untouched.
     */
    public function testCreateAcceptsBareTopLevelBoolAutoCollections(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('createLibrary')
            ->with('Movies', 'movie', ['/vault1/movies'], ['autoCollections' => ['enabled' => true]])
            ->willReturn('lib-new');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->method('enqueue')->willReturn('job-1');
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => ['/vault1/movies'],
            'autoCollections' => true,
        ];

        $response = $controller->create($request, []);

        $this->assertSame(201, $response->statusCode);
    }

    /**
     * Negative: create() returns 400 when required fields are missing.
     */
    public function testCreateReturns400WhenRequiredFieldsMissing(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('createLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['name' => 'Movies']; // missing type and paths

        $response = $controller->create($request, []);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Missing required fields: name, type, paths', $body['error']);
    }

    /**
     * Negative: create() returns 400 when library type is invalid.
     */
    public function testCreateReturns400WhenLibraryTypeInvalid(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('createLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Movies',
            'type' => 'invalid_type',
            'paths' => ['/mnt/movies'],
        ];

        $response = $controller->create($request, []);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Invalid library type', $body['error']);
        $this->assertArrayHasKey('valid_types', $body);
    }

    /**
     * Negative: create() returns 401 when not authenticated.
     */
    public function testCreateReturns401WhenUnauthenticated(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('createLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        // request->userId intentionally left null

        $response = $controller->create($request, []);

        $this->assertSame(401, $response->statusCode);
    }

    /**
     * Happy path: update() returns 200 when library exists and no admin middleware set.
     */
    public function testUpdateReturns200WhenLibraryExists(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);
        $libraryManager->expects($this->once())
            ->method('updateLibrary')
            ->with('lib-1', ['name' => 'New Name']);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['name' => 'New Name'];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Library updated successfully', $body['message']);
    }

    /**
     * Regression: clearing metadata_priority AND setting image_types in the SAME
     * update must not resurrect the cleared priority. Previously the two option
     * blocks each re-seeded their merge base from the original row, so the
     * image_types overlay re-introduced the just-cleared metadata_priority.
     */
    public function testUpdateClearingPriorityWhileSettingImagesDoesNotResurrectPriority(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn([
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'video',
                'options' => [
                    'metadata_priority' => ['tmdb', 'local'],
                    'series_per_directory' => true,
                ],
            ]);

        $captured = null;
        $libraryManager->expects($this->once())
            ->method('updateLibrary')
            ->with('lib-1', $this->callback(static function ($data) use (&$captured): bool {
                $captured = $data;
                return true;
            }));

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['metadata_priority' => null, 'image_types' => ['poster' => true]];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        $this->assertIsArray($captured);
        $this->assertIsArray($captured['options']);
        // The cleared priority must be GONE...
        $this->assertArrayNotHasKey('metadata_priority', $captured['options']);
        // ...image_types must be persisted...
        $this->assertArrayHasKey('image_types', $captured['options']);
        $this->assertTrue($captured['options']['image_types']['poster']);
        // ...and unrelated options preserved.
        $this->assertTrue($captured['options']['series_per_directory']);
    }

    /**
     * Negative: update() returns 404 when library not found.
     */
    public function testUpdateReturns404WhenLibraryNotFound(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('nonexistent')
            ->willReturn(null);
        $libraryManager->expects($this->never())->method('updateLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['name' => 'New Name'];

        $response = $controller->update($request, ['id' => 'nonexistent']);

        $this->assertSame(404, $response->statusCode);
    }

    /**
     * Happy path: delete() returns 200 when library exists.
     */
    public function testDeleteReturns200WhenLibraryExists(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);
        $libraryManager->expects($this->once())
            ->method('deleteLibrary')
            ->with('lib-1');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->delete($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Library deleted successfully', $body['message']);
    }

    /**
     * Negative: delete() returns 404 when library not found.
     */
    public function testDeleteReturns404WhenLibraryNotFound(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('nonexistent')
            ->willReturn(null);
        $libraryManager->expects($this->never())->method('deleteLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->delete($request, ['id' => 'nonexistent']);

        $this->assertSame(404, $response->statusCode);
    }

    /**
     * Happy path: scan() enqueues a job and returns 202 (1.1b) — it no longer
     * runs the scan inline.
     */
    public function testScanReturns202AndEnqueuesJobWhenLibraryExists(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);
        // The scan must NOT run inline anymore.
        $libraryManager->expects($this->never())->method('scanLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->once())
            ->method('enqueue')
            ->with('lib-1', 'scan')
            ->willReturn('job-1');

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->scan($request, ['id' => 'lib-1']);

        $this->assertSame(202, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('job-1', $body['job_id']);
        $this->assertSame('queued', $body['status']);
        $this->assertSame('Library scan queued', $body['message']);
    }

    /**
     * Negative: scan() returns 404 when library not found (no enqueue).
     */
    public function testScanReturns404WhenLibraryNotFound(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('nonexistent')
            ->willReturn(null);
        $libraryManager->expects($this->never())->method('scanLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->scan($request, ['id' => 'nonexistent']);

        $this->assertSame(404, $response->statusCode);
    }

    /**
     * Negative: scan() returns 401 when unauthenticated (no library lookup, no enqueue).
     */
    public function testScanReturns401WhenUnauthenticated(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('getLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        // request->userId intentionally left null

        $response = $controller->scan($request, ['id' => 'lib-1']);

        $this->assertSame(401, $response->statusCode);
    }

    /**
     * Happy path: rescan() enqueues a rescan job and returns 202 (1.1b).
     */
    public function testRescanReturns202AndEnqueuesJobWhenLibraryExists(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);
        $libraryManager->expects($this->never())->method('rescanLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->once())
            ->method('enqueue')
            ->with('lib-1', 'rescan')
            ->willReturn('job-2');

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->rescan($request, ['id' => 'lib-1']);

        $this->assertSame(202, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('job-2', $body['job_id']);
        $this->assertSame('queued', $body['status']);
        $this->assertSame('Library rescan queued', $body['message']);
    }

    /**
     * Negative: rescan() returns 404 when library not found (no enqueue).
     */
    public function testRescanReturns404WhenLibraryNotFound(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('nonexistent')
            ->willReturn(null);
        $libraryManager->expects($this->never())->method('rescanLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->rescan($request, ['id' => 'nonexistent']);

        $this->assertSame(404, $response->statusCode);
    }

    /**
     * Negative: rescan() returns 401 when unauthenticated (no enqueue).
     */
    public function testRescanReturns401WhenUnauthenticated(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('getLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        // request->userId intentionally left null

        $response = $controller->rescan($request, ['id' => 'lib-1']);

        $this->assertSame(401, $response->statusCode);
    }

    /**
     * Happy path: matchMetadata() enqueues a `metadata` job and returns 202,
     * mirroring scan() (reuses the scan-job queue so the UI badge works).
     */
    public function testMatchMetadataReturns202AndEnqueuesMetadataJob(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->once())
            ->method('enqueue')
            ->with('lib-1', 'metadata')
            ->willReturn('job-md');

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->matchMetadata($request, ['id' => 'lib-1']);

        $this->assertSame(202, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('job-md', $body['job_id']);
        $this->assertSame('queued', $body['status']);
        $this->assertSame('Metadata match queued', $body['message']);
    }

    /**
     * Negative: matchMetadata() returns 404 when library not found (no enqueue).
     */
    public function testMatchMetadataReturns404WhenLibraryNotFound(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('nonexistent')
            ->willReturn(null);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->matchMetadata($request, ['id' => 'nonexistent']);

        $this->assertSame(404, $response->statusCode);
    }

    /**
     * Negative: matchMetadata() returns 401 when unauthenticated (no lookup,
     * no enqueue).
     */
    public function testMatchMetadataReturns401WhenUnauthenticated(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('getLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        // request->userId intentionally left null

        $response = $controller->matchMetadata($request, ['id' => 'lib-1']);

        $this->assertSame(401, $response->statusCode);
    }

    /**
     * Happy path: refreshMetadata() enqueues a `metadata_refresh` job (force
     * re-match) and returns 202. Distinct from matchMetadata()'s `metadata` type.
     */
    public function testRefreshMetadataReturns202AndEnqueuesMetadataRefreshJob(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->once())
            ->method('enqueue')
            ->with('lib-1', 'metadata_refresh')
            ->willReturn('job-mr');

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->refreshMetadata($request, ['id' => 'lib-1']);

        $this->assertSame(202, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('job-mr', $body['job_id']);
        $this->assertSame('queued', $body['status']);
        $this->assertSame('Metadata refresh queued', $body['message']);
    }

    /**
     * Negative: refreshMetadata() returns 404 when library not found (no enqueue).
     */
    public function testRefreshMetadataReturns404WhenLibraryNotFound(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('nonexistent')
            ->willReturn(null);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->refreshMetadata($request, ['id' => 'nonexistent']);

        $this->assertSame(404, $response->statusCode);
    }

    /**
     * Negative: refreshMetadata() returns 401 when unauthenticated (no lookup,
     * no enqueue).
     */
    public function testRefreshMetadataReturns401WhenUnauthenticated(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('getLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        // request->userId intentionally left null

        $response = $controller->refreshMetadata($request, ['id' => 'lib-1']);

        $this->assertSame(401, $response->statusCode);
    }

    // ---------------------------------------------------------------------
    // Fine-grained maintenance endpoints (migration 084).
    // ---------------------------------------------------------------------

    /**
     * Happy path: prune() enqueues a `prune` job and returns 202.
     */
    public function testPruneReturns202AndEnqueuesPruneJob(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);
        $libraryManager->expects($this->never())->method('pruneLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->once())
            ->method('enqueue')
            ->with('lib-1', 'prune')
            ->willReturn('job-p');

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->prune($request, ['id' => 'lib-1']);

        $this->assertSame(202, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('job-p', $body['job_id']);
        $this->assertSame('queued', $body['status']);
        $this->assertSame('Library prune queued', $body['message']);
    }

    public function testPruneReturns404WhenLibraryNotFound(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())->method('getLibrary')->with('x')->willReturn(null);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');

        $controller = new LibraryController($libraryManager, $scanJobs);
        $request = new Request();
        $request->userId = 'admin-1';

        $this->assertSame(404, $controller->prune($request, ['id' => 'x'])->statusCode);
    }

    public function testPruneReturns401WhenUnauthenticated(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('getLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');

        $controller = new LibraryController($libraryManager, $scanJobs);
        $request = new Request();

        $this->assertSame(401, $controller->prune($request, ['id' => 'lib-1'])->statusCode);
    }

    /**
     * Happy path: clearMetadata() enqueues a `clear_metadata` job and returns 202.
     */
    public function testClearMetadataReturns202AndEnqueuesJob(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->once())
            ->method('enqueue')
            ->with('lib-1', 'clear_metadata')
            ->willReturn('job-cm');

        $controller = new LibraryController($libraryManager, $scanJobs);
        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->clearMetadata($request, ['id' => 'lib-1']);

        $this->assertSame(202, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('job-cm', $body['job_id']);
        $this->assertSame('Metadata clear queued', $body['message']);
    }

    public function testClearMetadataReturns404WhenLibraryNotFound(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())->method('getLibrary')->with('x')->willReturn(null);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');

        $controller = new LibraryController($libraryManager, $scanJobs);
        $request = new Request();
        $request->userId = 'admin-1';

        $this->assertSame(404, $controller->clearMetadata($request, ['id' => 'x'])->statusCode);
    }

    public function testClearMetadataReturns401WhenUnauthenticated(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('getLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');

        $controller = new LibraryController($libraryManager, $scanJobs);
        $request = new Request();

        $this->assertSame(401, $controller->clearMetadata($request, ['id' => 'lib-1'])->statusCode);
    }

    /**
     * Happy path: clearArtwork() enqueues a `clear_artwork` job and returns 202.
     */
    public function testClearArtworkReturns202AndEnqueuesJob(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->once())
            ->method('enqueue')
            ->with('lib-1', 'clear_artwork')
            ->willReturn('job-ca');

        $controller = new LibraryController($libraryManager, $scanJobs);
        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->clearArtwork($request, ['id' => 'lib-1']);

        $this->assertSame(202, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('job-ca', $body['job_id']);
        $this->assertSame('Artwork clear queued', $body['message']);
    }

    public function testClearArtworkReturns404WhenLibraryNotFound(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())->method('getLibrary')->with('x')->willReturn(null);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');

        $controller = new LibraryController($libraryManager, $scanJobs);
        $request = new Request();
        $request->userId = 'admin-1';

        $this->assertSame(404, $controller->clearArtwork($request, ['id' => 'x'])->statusCode);
    }

    public function testClearArtworkReturns401WhenUnauthenticated(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('getLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');

        $controller = new LibraryController($libraryManager, $scanJobs);
        $request = new Request();

        $this->assertSame(401, $controller->clearArtwork($request, ['id' => 'lib-1'])->statusCode);
    }

    /**
     * Destructive delete_all: WITHOUT a confirm flag the endpoint returns 400 and
     * does NOT enqueue anything.
     */
    public function testDeleteAllReturns400WithoutConfirmation(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');

        $controller = new LibraryController($libraryManager, $scanJobs);
        $request = new Request();
        $request->userId = 'admin-1';
        // No confirm flag.

        $response = $controller->deleteAll($request, ['id' => 'lib-1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('library.delete_all.confirm_required', $body['code']);
    }

    /**
     * Destructive delete_all: WITH confirm=true (body) the endpoint enqueues a
     * `delete_all` job and returns 202.
     */
    public function testDeleteAllReturns202WithConfirmationInBody(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->once())
            ->method('enqueue')
            ->with('lib-1', 'delete_all')
            ->willReturn('job-da');

        $controller = new LibraryController($libraryManager, $scanJobs);
        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['confirm' => true];

        $response = $controller->deleteAll($request, ['id' => 'lib-1']);

        $this->assertSame(202, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('job-da', $body['job_id']);
        $this->assertSame('queued', $body['status']);
    }

    /**
     * delete_all also accepts the confirmation via the query string (confirm=1).
     */
    public function testDeleteAllReturns202WithConfirmationInQuery(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->once())
            ->method('enqueue')
            ->with('lib-1', 'delete_all')
            ->willReturn('job-da2');

        $controller = new LibraryController($libraryManager, $scanJobs);
        $request = new Request();
        $request->userId = 'admin-1';
        $request->query = ['confirm' => '1'];

        $response = $controller->deleteAll($request, ['id' => 'lib-1']);

        $this->assertSame(202, $response->statusCode);
    }

    public function testDeleteAllReturns404WhenLibraryNotFound(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())->method('getLibrary')->with('x')->willReturn(null);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');

        $controller = new LibraryController($libraryManager, $scanJobs);
        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['confirm' => true];

        $this->assertSame(404, $controller->deleteAll($request, ['id' => 'x'])->statusCode);
    }

    public function testDeleteAllReturns401WhenUnauthenticated(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('getLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');

        $controller = new LibraryController($libraryManager, $scanJobs);
        $request = new Request();
        $request->body = ['confirm' => true];

        $this->assertSame(401, $controller->deleteAll($request, ['id' => 'lib-1'])->statusCode);
    }

    /**
     * Happy path: scanStatus() returns 200 with the latest job row (1.1b).
     */
    public function testScanStatusReturns200WithLatestJob(): void
    {
        $job = [
            'id' => 'job-1',
            'library_id' => 'lib-1',
            'type' => 'scan',
            'status' => 'running',
            'items_found' => 0,
            'items_added' => 0,
            'items_updated' => 0,
            'items_removed' => 0,
            'current_path' => '/mnt/movies/a',
            'error' => null,
            'queued_at' => '2026-05-27 10:00:00',
            'started_at' => '2026-05-27 10:00:01',
            'completed_at' => null,
        ];

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->once())
            ->method('getLatestForLibrary')
            ->with('lib-1')
            ->willReturn($job);

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->scanStatus($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array{scan_status: array<string, mixed>} $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('scan_status', $body);
        $this->assertSame('job-1', $body['scan_status']['id']);
        $this->assertSame('running', $body['scan_status']['status']);
    }

    /**
     * Happy path: scanStatus() returns 200 with scan_status null when the
     * library has no jobs yet (valid body, NOT a 404).
     */
    public function testScanStatusReturns200WithNullWhenNoJobs(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->once())
            ->method('getLatestForLibrary')
            ->with('lib-1')
            ->willReturn(null);

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->scanStatus($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('scan_status', $body);
        $this->assertNull($body['scan_status']);
    }

    /**
     * Negative: scanStatus() returns 404 when library not found (no job lookup).
     */
    public function testScanStatusReturns404WhenLibraryNotFound(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('nonexistent')
            ->willReturn(null);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('getLatestForLibrary');

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->scanStatus($request, ['id' => 'nonexistent']);

        $this->assertSame(404, $response->statusCode);
    }

    /**
     * Negative: scanStatus() returns 401 when unauthenticated.
     */
    public function testScanStatusReturns401WhenUnauthenticated(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('getLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('getLatestForLibrary');

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        // request->userId intentionally left null

        $response = $controller->scanStatus($request, ['id' => 'lib-1']);

        $this->assertSame(401, $response->statusCode);
    }

    /**
     * Happy path: scanHistory() returns 200 with rows; default limit is 20.
     */
    public function testScanHistoryReturns200WithDefaultLimit(): void
    {
        $rows = [
            ['id' => 'job-2', 'library_id' => 'lib-1', 'type' => 'rescan', 'status' => 'completed'],
            ['id' => 'job-1', 'library_id' => 'lib-1', 'type' => 'scan', 'status' => 'completed'],
        ];

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->once())
            ->method('getHistoryForLibrary')
            ->with('lib-1', 20)
            ->willReturn($rows);

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->scanHistory($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array{history: list<array<string, mixed>>} $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('history', $body);
        $this->assertCount(2, $body['history']);
        $this->assertSame('job-2', $body['history'][0]['id']);
    }

    /**
     * Happy path: scanHistory() reads the ?limit= query param and passes it through.
     */
    public function testScanHistoryHonorsLimitQueryParam(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->once())
            ->method('getHistoryForLibrary')
            ->with('lib-1', 5)
            ->willReturn([]);

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->query = ['limit' => '5'];

        $response = $controller->scanHistory($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame([], $body['history']);
    }

    /**
     * Negative: scanHistory() returns 404 when library not found (no history lookup).
     */
    public function testScanHistoryReturns404WhenLibraryNotFound(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('nonexistent')
            ->willReturn(null);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('getHistoryForLibrary');

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->scanHistory($request, ['id' => 'nonexistent']);

        $this->assertSame(404, $response->statusCode);
    }

    /**
     * Negative: scanHistory() returns 401 when unauthenticated.
     */
    public function testScanHistoryReturns401WhenUnauthenticated(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('getLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('getHistoryForLibrary');

        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        // request->userId intentionally left null

        $response = $controller->scanHistory($request, ['id' => 'lib-1']);

        $this->assertSame(401, $response->statusCode);
    }

    /**
     * create() persists a top-level metadata_priority override into options
     * (sanitised: source names trimmed, order preserved). Item 5.
     */
    public function testCreatePersistsTopLevelMetadataPriority(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('createLibrary')
            ->with('Movies', 'movie', ['/mnt/movies'], [
                'metadata_priority' => ['movie' => ['imdb', 'tmdb']],
            ])
            ->willReturn('movie-lib');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->method('enqueue')->willReturn('job-1');
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => ['/mnt/movies'],
            'metadata_priority' => ['movie' => ['imdb', ' tmdb ']],
        ];

        $response = $controller->create($request, []);

        $this->assertSame(201, $response->statusCode);
    }

    /**
     * create() also accepts metadata_priority nested inside `options`.
     */
    public function testCreateAcceptsNestedMetadataPriority(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('createLibrary')
            ->with('Movies', 'movie', ['/mnt/movies'], [
                'metadata_priority' => ['movie' => ['imdb']],
            ])
            ->willReturn('movie-lib');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->method('enqueue')->willReturn('job-1');
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => ['/mnt/movies'],
            'options' => ['metadata_priority' => ['movie' => ['imdb']]],
        ];

        $response = $controller->create($request, []);

        $this->assertSame(201, $response->statusCode);
    }

    /**
     * create() rejects a malformed metadata_priority with 400 and never persists.
     */
    public function testCreateRejectsMalformedMetadataPriority(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('createLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => ['/mnt/movies'],
            // A list value is required per type — a string is malformed.
            'metadata_priority' => ['movie' => 'tmdb'],
        ];

        $response = $controller->create($request, []);

        $this->assertSame(400, $response->statusCode);
        /** @var array{error: string} $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertStringContainsString('metadata_priority', $body['error']);
    }

    /**
     * create() treats an explicit empty map as clearing the override (the key is
     * NOT persisted → falls back to the global default).
     */
    public function testCreateEmptyMetadataPriorityClearsOverride(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('createLibrary')
            ->with('Movies', 'movie', ['/mnt/movies'], [])
            ->willReturn('movie-lib');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->method('enqueue')->willReturn('job-1');
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => ['/mnt/movies'],
            'metadata_priority' => [],
        ];

        $response = $controller->create($request, []);

        $this->assertSame(201, $response->statusCode);
    }

    /**
     * update() merges a metadata_priority override into the EXISTING options blob
     * (preserving unrelated options), sanitising the map. Item 5.
     */
    public function testUpdateMergesMetadataPriorityIntoExistingOptions(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn([
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'movie',
                'options' => ['scan_interval' => 3600],
            ]);
        $libraryManager->expects($this->once())
            ->method('updateLibrary')
            ->with('lib-1', $this->callback(static function (mixed $data): bool {
                if (!is_array($data)) {
                    return false;
                }
                return !array_key_exists('metadata_priority', $data)
                    && is_array($data['options'] ?? null)
                    && $data['options']['scan_interval'] === 3600
                    && $data['options']['metadata_priority'] === ['movie' => ['imdb', 'tmdb']];
            }));

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['metadata_priority' => ['movie' => ['imdb', 'tmdb']]];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
    }

    /**
     * update() also accepts metadata_priority nested inside `options`.
     */
    public function testUpdatePersistsNestedMetadataPriority(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn([
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'movie',
                'options' => ['scan_interval' => 3600],
            ]);
        $libraryManager->expects($this->once())
            ->method('updateLibrary')
            ->with('lib-1', $this->callback(static function (mixed $data): bool {
                return is_array($data)
                    && !array_key_exists('metadata_priority', $data)
                    && is_array($data['options'] ?? null)
                    && $data['options']['scan_interval'] === 3600
                    && $data['options']['metadata_priority'] === ['series' => ['tmdb']];
            }));

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['options' => ['metadata_priority' => ['series' => ['tmdb']]]];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
    }

    /**
     * update() with an explicit null CLEARS the override — the key is removed
     * from the merged options blob (falls back to the global default).
     */
    public function testUpdateNullMetadataPriorityClearsOverride(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn([
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'movie',
                'options' => [
                    'scan_interval' => 3600,
                    'metadata_priority' => ['movie' => ['imdb']],
                ],
            ]);
        $libraryManager->expects($this->once())
            ->method('updateLibrary')
            ->with('lib-1', $this->callback(static function (mixed $data): bool {
                return is_array($data)
                    && !array_key_exists('metadata_priority', $data)
                    && is_array($data['options'] ?? null)
                    && $data['options']['scan_interval'] === 3600
                    // The override key was dropped from the merged options.
                    && !array_key_exists('metadata_priority', $data['options']);
            }));

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['metadata_priority' => null];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
    }

    /**
     * update() rejects a malformed metadata_priority with 400 and never persists.
     */
    public function testUpdateRejectsMalformedMetadataPriority(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn([
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'movie',
                'options' => ['scan_interval' => 3600],
            ]);
        $libraryManager->expects($this->never())->method('updateLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        // Blank source name is malformed.
        $request->body = ['metadata_priority' => ['movie' => ['tmdb', '  ']]];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array{error: string} $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertStringContainsString('metadata_priority', $body['error']);
    }

    // ------------------------------------------------------------------
    // image_types (M5) — per-library artwork selection
    // ------------------------------------------------------------------

    /**
     * create() persists an `image_types` selection into options as the canonical
     * `{type: bool}` storage map (every known type present, unknowns dropped).
     */
    public function testCreatePersistsImageTypesSelection(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('createLibrary')
            ->with(
                'Movies',
                'movie',
                ['/mnt/movies'],
                $this->callback(static function (mixed $options): bool {
                    if (!is_array($options) || !isset($options['image_types'])) {
                        return false;
                    }
                    $map = $options['image_types'];
                    // Storage map: poster ON (sent), backdrop OFF (sent false),
                    // logo ON (sent), and an unknown key dropped entirely.
                    return is_array($map)
                        && ($map['poster'] ?? null) === true
                        && ($map['backdrop'] ?? null) === false
                        && ($map['logo'] ?? null) === true
                        && !array_key_exists('bogus', $map)
                        // Every canonical type is present with an explicit bool.
                        && array_key_exists('disc', $map)
                        && ($map['disc'] ?? null) === false;
                })
            )
            ->willReturn('img-lib');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->method('enqueue')->willReturn('job-1');
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => ['/mnt/movies'],
            'image_types' => [
                'poster' => true,
                'backdrop' => false,
                'logo' => true,
                'bogus' => true,
            ],
        ];

        $response = $controller->create($request, []);

        $this->assertSame(201, $response->statusCode);
    }

    /**
     * update() merges an `image_types` selection into the EXISTING options blob
     * without clobbering unrelated keys (metadata_priority, series_per_directory).
     */
    public function testUpdateMergesImageTypesWithoutClobberingOtherOptions(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn([
                'id' => 'lib-1',
                'name' => 'Anime',
                'type' => 'series',
                'options' => [
                    'metadata_priority' => ['series' => ['tvdb', 'tmdb']],
                    'series_per_directory' => true,
                ],
            ]);
        $libraryManager->expects($this->once())
            ->method('updateLibrary')
            ->with('lib-1', $this->callback(static function (mixed $data): bool {
                if (!is_array($data) || !is_array($data['options'] ?? null)) {
                    return false;
                }
                $options = $data['options'];
                // The top-level image_types key is stripped before delegating.
                if (array_key_exists('image_types', $data)) {
                    return false;
                }
                $map = $options['image_types'] ?? null;
                return is_array($map)
                    && ($map['poster'] ?? null) === true
                    && ($map['backdrop'] ?? null) === false
                    // Unrelated options are preserved untouched.
                    && ($options['metadata_priority'] ?? null) === ['series' => ['tvdb', 'tmdb']]
                    && ($options['series_per_directory'] ?? null) === true;
            }));

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['image_types' => ['poster' => true, 'backdrop' => false]];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
    }

    /**
     * update() accepts a list-shaped `image_types` nested inside `options` and
     * stores the canonical storage map, preserving other options.
     */
    public function testUpdateAcceptsNestedListShapeImageTypes(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn([
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'movie',
                'options' => ['scan_interval' => 3600],
            ]);
        $libraryManager->expects($this->once())
            ->method('updateLibrary')
            ->with('lib-1', $this->callback(static function (mixed $data): bool {
                if (!is_array($data) || !is_array($data['options'] ?? null)) {
                    return false;
                }
                $options = $data['options'];
                $map = $options['image_types'] ?? null;
                return is_array($map)
                    && ($map['poster'] ?? null) === true
                    && ($map['banner'] ?? null) === true
                    && ($map['backdrop'] ?? null) === false
                    && ($options['scan_interval'] ?? null) === 3600;
            }));

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        // List shape nested inside options.
        $request->body = ['options' => ['image_types' => ['poster', 'banner']]];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
    }

    /**
     * A null `image_types` CLEARS the selection (removes the key so the library
     * falls back to ImageType::defaults()).
     */
    public function testUpdateNullImageTypesClearsSelection(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn([
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'movie',
                'options' => ['image_types' => ['poster' => true], 'scan_interval' => 3600],
            ]);
        $libraryManager->expects($this->once())
            ->method('updateLibrary')
            ->with('lib-1', $this->callback(static function (mixed $data): bool {
                if (!is_array($data) || !is_array($data['options'] ?? null)) {
                    return false;
                }
                // Key removed; unrelated option preserved.
                return !array_key_exists('image_types', $data['options'])
                    && ($data['options']['scan_interval'] ?? null) === 3600;
            }));

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['image_types' => null];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
    }

    /**
     * A non-array `image_types` value is rejected with 400.
     */
    public function testUpdateRejectsMalformedImageTypes(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getLibrary')->with('lib-1')->willReturn([
            'id' => 'lib-1',
            'name' => 'Movies',
            'type' => 'movie',
            'options' => [],
        ]);
        $libraryManager->expects($this->never())->method('updateLibrary');

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['image_types' => 'not-an-array'];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array{error: string} $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertStringContainsString('image_types', $body['error']);
    }
}
