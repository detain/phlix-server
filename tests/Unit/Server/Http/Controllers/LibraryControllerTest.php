<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
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
     * Happy path: index() returns 200 with libraries list for authenticated user.
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

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $controller = new LibraryController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'user-1';

        $response = $controller->index($request, []);

        $this->assertSame(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('libraries', $body);
        $this->assertCount(2, $body['libraries']);
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
        $scanJobs = $this->createMock(ScanJobRepository::class);
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
        $body = json_decode($response->body, true);
        $this->assertSame('new-lib-id', $body['library_id']);
        $this->assertSame('Library created successfully', $body['message']);
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
        $body = json_decode($response->body, true);
        $this->assertSame('Library updated successfully', $body['message']);
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
}
