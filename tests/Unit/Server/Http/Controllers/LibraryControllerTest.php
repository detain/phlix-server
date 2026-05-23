<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\LibraryManager;
use Phlix\Server\Http\Controllers\LibraryController;
use Phlix\Server\Http\Request;

/**
 * Unit tests for {@see LibraryController}.
 *
 * Covers the seven handler methods now wired in Application::loadLibraryRoutes():
 *   GET    /api/v1/libraries          -> index
 *   GET    /api/v1/libraries/{id}      -> show
 *   POST   /api/v1/libraries           -> create
 *   PUT    /api/v1/libraries/{id}     -> update
 *   DELETE /api/v1/libraries/{id}     -> delete
 *   POST   /api/v1/libraries/{id}/scan  -> scan
 *   POST   /api/v1/libraries/{id}/rescan -> rescan
 *
 * Uses createMock(LibraryManager::class) following the project's existing
 * controller-test conventions (see SessionControllerTest, AuthControllerTest).
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

        $controller = new LibraryController($libraryManager);

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

        $controller = new LibraryController($libraryManager);

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

        $controller = new LibraryController($libraryManager);

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

        $controller = new LibraryController($libraryManager);

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

        $controller = new LibraryController($libraryManager);

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
        $controller = new LibraryController($libraryManager);

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

        $controller = new LibraryController($libraryManager);

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

        $controller = new LibraryController($libraryManager);

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

        $controller = new LibraryController($libraryManager);

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

        $controller = new LibraryController($libraryManager);

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

        $controller = new LibraryController($libraryManager);

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

        $controller = new LibraryController($libraryManager);

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

        $controller = new LibraryController($libraryManager);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->delete($request, ['id' => 'nonexistent']);

        $this->assertSame(404, $response->statusCode);
    }

    /**
     * Happy path: scan() returns 200 when library exists.
     */
    public function testScanReturns200WhenLibraryExists(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);
        $libraryManager->expects($this->once())
            ->method('scanLibrary')
            ->with('lib-1');

        $controller = new LibraryController($libraryManager);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->scan($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Library scan started', $body['message']);
    }

    /**
     * Negative: scan() returns 404 when library not found.
     */
    public function testScanReturns404WhenLibraryNotFound(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('nonexistent')
            ->willReturn(null);
        $libraryManager->expects($this->never())->method('scanLibrary');

        $controller = new LibraryController($libraryManager);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->scan($request, ['id' => 'nonexistent']);

        $this->assertSame(404, $response->statusCode);
    }

    /**
     * Happy path: rescan() returns 200 when library exists.
     */
    public function testRescanReturns200WhenLibraryExists(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);
        $libraryManager->expects($this->once())
            ->method('rescanLibrary')
            ->with('lib-1');

        $controller = new LibraryController($libraryManager);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->rescan($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Library rescan started', $body['message']);
    }

    /**
     * Negative: rescan() returns 404 when library not found.
     */
    public function testRescanReturns404WhenLibraryNotFound(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('nonexistent')
            ->willReturn(null);
        $libraryManager->expects($this->never())->method('rescanLibrary');

        $controller = new LibraryController($libraryManager);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->rescan($request, ['id' => 'nonexistent']);

        $this->assertSame(404, $response->statusCode);
    }
}
