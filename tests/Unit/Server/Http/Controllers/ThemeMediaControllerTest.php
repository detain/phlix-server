<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Media\Library\LibraryManager;
use Phlix\Server\Http\Controllers\ThemeMediaController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Theming\ThemeAudio;
use Phlix\Theming\ThemeMedia;
use Phlix\Theming\ThemeMediaFinder;
use Phlix\Theming\ThemeMediaRepository;
use Phlix\Theming\ThemeVideo;

/**
 * Unit tests for {@see ThemeMediaController}.
 *
 * Covers the three handler methods now wired in Application::loadLibraryRoutes():
 *   GET    /api/v1/libraries/{id}/theme-media       -> getThemeMedia
 *   POST   /api/v1/libraries/{id}/theme-media/scan -> scanThemeMedia
 *   DELETE /api/v1/libraries/{id}/theme-media      -> deleteThemeMedia
 *
 * Uses createMock() for dependencies following the project's existing
 * controller-test conventions.
 */
class ThemeMediaControllerTest extends TestCase
{
    /**
     * Happy path: getThemeMedia() returns 200 with theme media data for existing library.
     */
    public function testGetThemeMediaReturns200WithThemeMediaData(): void
    {
        $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
        $themeMediaRepository->expects($this->once())
            ->method('findByLibraryId')
            ->with('lib-1')
            ->willReturn(new ThemeMedia(
                libraryId: 'lib-1',
                audio: new ThemeAudio('/path/to/theme.mp3', '/stream/theme-media/lib-1/audio', 120, 'mp3'),
                video: null,
                scannedAt: new \DateTimeImmutable('2024-01-15T10:30:00+00:00')
            ));

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);

        $themeMediaFinder = $this->createMock(ThemeMediaFinder::class);

        $controller = new ThemeMediaController(
            $themeMediaRepository,
            $themeMediaFinder,
            $libraryManager
        );

        $request = new Request();

        $response = $controller->getThemeMedia($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('lib-1', $body['library_id']);
        $this->assertNotNull($body['audio']);
        $this->assertNull($body['video']);
        $this->assertTrue($body['has_theme']);
    }

    /**
     * Happy path: getThemeMedia() returns 200 with empty theme when no theme media cached.
     */
    public function testGetThemeMediaReturns200WithEmptyWhenNoCache(): void
    {
        $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
        $themeMediaRepository->expects($this->once())
            ->method('findByLibraryId')
            ->with('lib-1')
            ->willReturn(null);

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);

        $themeMediaFinder = $this->createMock(ThemeMediaFinder::class);

        $controller = new ThemeMediaController(
            $themeMediaRepository,
            $themeMediaFinder,
            $libraryManager
        );

        $request = new Request();

        $response = $controller->getThemeMedia($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertSame('lib-1', $body['library_id']);
        $this->assertNull($body['audio']);
        $this->assertNull($body['video']);
        $this->assertFalse($body['has_theme']);
    }

    /**
     * Negative: getThemeMedia() returns 400 when library ID is empty.
     */
    public function testGetThemeMediaReturns400WhenLibraryIdEmpty(): void
    {
        $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
        $themeMediaRepository->expects($this->never())->method('findByLibraryId');

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('getLibrary');

        $themeMediaFinder = $this->createMock(ThemeMediaFinder::class);

        $controller = new ThemeMediaController(
            $themeMediaRepository,
            $themeMediaFinder,
            $libraryManager
        );

        $request = new Request();

        $response = $controller->getThemeMedia($request, ['id' => '']);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Library ID is required', $body['error']);
    }

    /**
     * Negative: getThemeMedia() returns 404 when library not found.
     */
    public function testGetThemeMediaReturns404WhenLibraryNotFound(): void
    {
        $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
        $themeMediaRepository->expects($this->never())->method('findByLibraryId');

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('nonexistent')
            ->willReturn(null);

        $themeMediaFinder = $this->createMock(ThemeMediaFinder::class);

        $controller = new ThemeMediaController(
            $themeMediaRepository,
            $themeMediaFinder,
            $libraryManager
        );

        $request = new Request();

        $response = $controller->getThemeMedia($request, ['id' => 'nonexistent']);

        $this->assertSame(404, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Library not found', $body['error']);
    }

    /**
     * Happy path: scanThemeMedia() returns 200 when scan finds theme media.
     */
    public function testScanThemeMediaReturns200WithFoundMedia(): void
    {
        // Create real temp directories for testing
        $tempDir = sys_get_temp_dir() . '/phlix_test_scan_' . uniqid();
        mkdir($tempDir, 0755, true);
        $libraryPath = $tempDir . '/movies';
        mkdir($libraryPath, 0755, true); // Create the library path directory

        try {
            $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
            $themeMediaRepository->expects($this->once())
                ->method('upsert');

            $libraryManager = $this->createMock(LibraryManager::class);
            $libraryManager->expects($this->once())
                ->method('getLibrary')
                ->with('lib-1')
                ->willReturn([
                    'id' => 'lib-1',
                    'name' => 'Movies',
                    'type' => 'video',
                    'paths' => [$libraryPath],
                ]);

            $foundThemeMedia = new ThemeMedia(
                libraryId: 'lib-1',
                audio: new ThemeAudio($libraryPath . '/theme.mp3', '/stream/theme-media/lib-1/audio', 120, 'mp3'),
                video: null,
                scannedAt: new \DateTimeImmutable()
            );

            $themeMediaFinder = $this->createMock(ThemeMediaFinder::class);
            $themeMediaFinder->expects($this->once())
                ->method('findForLibrary')
                ->with('lib-1', $libraryPath)
                ->willReturn($foundThemeMedia);

            $controller = new ThemeMediaController(
                $themeMediaRepository,
                $themeMediaFinder,
                $libraryManager
            );

            $request = new Request();

            $response = $controller->scanThemeMedia($request, ['id' => 'lib-1']);

            $this->assertSame(200, $response->statusCode);

            $body = json_decode($response->body, true);
            $this->assertSame('lib-1', $body['library_id']);
            $this->assertTrue($body['audio_found']);
            $this->assertFalse($body['video_found']);
            $this->assertTrue($body['has_theme']);
        } finally {
            // Cleanup
            @rmdir($libraryPath);
            @rmdir($tempDir);
        }
    }

    /**
     * Happy path: scanThemeMedia() returns 200 when scan finds no theme media.
     */
    public function testScanThemeMediaReturns200WithNoMediaFound(): void
    {
        // Create real temp directories for testing
        $tempDir = sys_get_temp_dir() . '/phlix_test_scan_' . uniqid();
        mkdir($tempDir, 0755, true);
        $libraryPath = $tempDir . '/movies';
        mkdir($libraryPath, 0755, true); // Create the library path directory

        try {
            $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
            $themeMediaRepository->expects($this->once())
                ->method('deleteByLibraryId')
                ->with('lib-1');

            $libraryManager = $this->createMock(LibraryManager::class);
            $libraryManager->expects($this->once())
                ->method('getLibrary')
                ->with('lib-1')
                ->willReturn([
                    'id' => 'lib-1',
                    'name' => 'Movies',
                    'type' => 'video',
                    'paths' => [$libraryPath],
                ]);

            $themeMediaFinder = $this->createMock(ThemeMediaFinder::class);
            $themeMediaFinder->expects($this->once())
                ->method('findForLibrary')
                ->with('lib-1', $libraryPath)
                ->willReturn(null);

            $controller = new ThemeMediaController(
                $themeMediaRepository,
                $themeMediaFinder,
                $libraryManager
            );

            $request = new Request();

            $response = $controller->scanThemeMedia($request, ['id' => 'lib-1']);

            $this->assertSame(200, $response->statusCode);

            $body = json_decode($response->body, true);
            $this->assertSame('lib-1', $body['library_id']);
            $this->assertFalse($body['audio_found']);
            $this->assertFalse($body['video_found']);
            $this->assertFalse($body['has_theme']);
        } finally {
            // Cleanup
            @rmdir($libraryPath);
            @rmdir($tempDir);
        }
    }

    /**
     * Negative: scanThemeMedia() returns 400 when library ID is empty.
     */
    public function testScanThemeMediaReturns400WhenLibraryIdEmpty(): void
    {
        $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
        $libraryManager = $this->createMock(LibraryManager::class);
        $themeMediaFinder = $this->createMock(ThemeMediaFinder::class);

        $controller = new ThemeMediaController(
            $themeMediaRepository,
            $themeMediaFinder,
            $libraryManager
        );

        $request = new Request();

        $response = $controller->scanThemeMedia($request, ['id' => '']);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Library ID is required', $body['error']);
    }

    /**
     * Negative: scanThemeMedia() returns 404 when library not found.
     */
    public function testScanThemeMediaReturns404WhenLibraryNotFound(): void
    {
        $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
        $themeMediaRepository->expects($this->never())->method('upsert');

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('nonexistent')
            ->willReturn(null);

        $themeMediaFinder = $this->createMock(ThemeMediaFinder::class);

        $controller = new ThemeMediaController(
            $themeMediaRepository,
            $themeMediaFinder,
            $libraryManager
        );

        $request = new Request();

        $response = $controller->scanThemeMedia($request, ['id' => 'nonexistent']);

        $this->assertSame(404, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Library not found', $body['error']);
    }

    /**
     * Happy path: deleteThemeMedia() returns 200 when deletion succeeds.
     */
    public function testDeleteThemeMediaReturns200OnSuccess(): void
    {
        $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
        $themeMediaRepository->expects($this->once())
            ->method('deleteByLibraryId')
            ->with('lib-1');

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);

        $themeMediaFinder = $this->createMock(ThemeMediaFinder::class);

        $controller = new ThemeMediaController(
            $themeMediaRepository,
            $themeMediaFinder,
            $libraryManager
        );

        $request = new Request();

        $response = $controller->deleteThemeMedia($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertSame('lib-1', $body['library_id']);
        $this->assertTrue($body['deleted']);
    }

    /**
     * Negative: deleteThemeMedia() returns 400 when library ID is empty.
     */
    public function testDeleteThemeMediaReturns400WhenLibraryIdEmpty(): void
    {
        $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
        $themeMediaRepository->expects($this->never())->method('deleteByLibraryId');

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('getLibrary');

        $themeMediaFinder = $this->createMock(ThemeMediaFinder::class);

        $controller = new ThemeMediaController(
            $themeMediaRepository,
            $themeMediaFinder,
            $libraryManager
        );

        $request = new Request();

        $response = $controller->deleteThemeMedia($request, ['id' => '']);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Library ID is required', $body['error']);
    }

    /**
     * Negative: deleteThemeMedia() returns 404 when library not found.
     */
    public function testDeleteThemeMediaReturns404WhenLibraryNotFound(): void
    {
        $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
        $themeMediaRepository->expects($this->never())->method('deleteByLibraryId');

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('nonexistent')
            ->willReturn(null);

        $themeMediaFinder = $this->createMock(ThemeMediaFinder::class);

        $controller = new ThemeMediaController(
            $themeMediaRepository,
            $themeMediaFinder,
            $libraryManager
        );

        $request = new Request();

        $response = $controller->deleteThemeMedia($request, ['id' => 'nonexistent']);

        $this->assertSame(404, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Library not found', $body['error']);
    }

    // ---------------------------------------------------------------------
    // Admin-gating of the mutation endpoints (scanThemeMedia / deleteThemeMedia).
    //
    // AdminMiddleware is `final`, so it cannot be replaced with createMock().
    // Instead we build a REAL AdminMiddleware over mocked UserRepository +
    // AuditLogger collaborators and drive its checkAccess() decision branches:
    //   - userId === null            -> checkAccess() returns 401
    //   - userId set, not an admin   -> checkAccess() returns 403 (and audits)
    //   - userId set + admin row     -> checkAccess() returns null (allowed)
    // ---------------------------------------------------------------------

    /**
     * Build a real AdminMiddleware whose checkAccess() will deny non-admins.
     *
     * The mocked UserRepository::findAdminById() returns the supplied admin
     * row (null => not an admin => 403; non-null => allowed). The AuditLogger
     * is mocked because the 403 branch calls logPermissionDenied().
     *
     * @param array<string, mixed>|null $adminRow Row returned by findAdminById().
     */
    private function makeAdminMiddleware(?array $adminRow): AdminMiddleware
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturn($adminRow);

        $audit = $this->createMock(AuditLogger::class);

        return new AdminMiddleware($users, $audit);
    }

    /**
     * Build a ThemeMediaController whose collaborators must never be touched.
     *
     * Used by the 401/403 gating tests to assert the gate short-circuits
     * BEFORE any side effect (no library lookup, no finder, no repo write).
     */
    private function makeGatedControllerExpectingNoSideEffects(): ThemeMediaController
    {
        $repository = $this->createMock(ThemeMediaRepository::class);
        $repository->expects($this->never())->method('upsert');
        $repository->expects($this->never())->method('deleteByLibraryId');

        $finder = $this->createMock(ThemeMediaFinder::class);
        $finder->expects($this->never())->method('findForLibrary');

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('getLibrary');

        return new ThemeMediaController($repository, $finder, $libraryManager);
    }

    /**
     * Gate: scanThemeMedia() returns 401 when AdminMiddleware denies with 401
     * (no userId), and performs no scan/repository side effects.
     */
    public function testScanThemeMediaReturns401WhenUnauthenticated(): void
    {
        $controller = $this->makeGatedControllerExpectingNoSideEffects();
        $controller->setAdminMiddleware($this->makeAdminMiddleware(null));

        $request = new Request();
        // userId intentionally left null -> checkAccess() returns 401.

        $response = $controller->scanThemeMedia($request, ['id' => 'lib-1']);

        $this->assertSame(401, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Unauthorized', $body['error']);
        $this->assertSame('auth.required', $body['code']);
    }

    /**
     * Gate: scanThemeMedia() returns 403 when AdminMiddleware denies with 403
     * (authenticated but not an admin), and performs no side effects.
     */
    public function testScanThemeMediaReturns403WhenNotAdmin(): void
    {
        $controller = $this->makeGatedControllerExpectingNoSideEffects();
        // findAdminById() => null => 403 for an authenticated non-admin.
        $controller->setAdminMiddleware($this->makeAdminMiddleware(null));

        $request = new Request();
        $request->userId = 'user-1';

        $response = $controller->scanThemeMedia($request, ['id' => 'lib-1']);

        $this->assertSame(403, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Forbidden', $body['error']);
        $this->assertSame('auth.not_admin', $body['code']);
    }

    /**
     * Gate: scanThemeMedia() proceeds (no 401/403) when AdminMiddleware allows
     * the request (checkAccess() returns null for a valid admin).
     */
    public function testScanThemeMediaProceedsWhenAdminAllowed(): void
    {
        $tempDir = sys_get_temp_dir() . '/phlix_test_scan_' . uniqid();
        mkdir($tempDir, 0755, true);
        $libraryPath = $tempDir . '/movies';
        mkdir($libraryPath, 0755, true);

        try {
            $repository = $this->createMock(ThemeMediaRepository::class);
            $repository->expects($this->once())->method('upsert');

            $libraryManager = $this->createMock(LibraryManager::class);
            $libraryManager->expects($this->once())
                ->method('getLibrary')
                ->with('lib-1')
                ->willReturn([
                    'id' => 'lib-1',
                    'name' => 'Movies',
                    'type' => 'video',
                    'paths' => [$libraryPath],
                ]);

            $foundThemeMedia = new ThemeMedia(
                libraryId: 'lib-1',
                audio: new ThemeAudio($libraryPath . '/theme.mp3', '/stream/theme-media/lib-1/audio', 120, 'mp3'),
                video: null,
                scannedAt: new \DateTimeImmutable()
            );

            $finder = $this->createMock(ThemeMediaFinder::class);
            $finder->expects($this->once())
                ->method('findForLibrary')
                ->with('lib-1', $libraryPath)
                ->willReturn($foundThemeMedia);

            $controller = new ThemeMediaController($repository, $finder, $libraryManager);
            // admin row present => checkAccess() returns null => allowed.
            $controller->setAdminMiddleware($this->makeAdminMiddleware(['id' => 'admin-1', 'is_admin' => 1]));

            $request = new Request();
            $request->userId = 'admin-1';

            $response = $controller->scanThemeMedia($request, ['id' => 'lib-1']);

            $this->assertSame(200, $response->statusCode);
            $this->assertNotSame(401, $response->statusCode);
            $this->assertNotSame(403, $response->statusCode);
            $body = json_decode($response->body, true);
            $this->assertSame('lib-1', $body['library_id']);
            $this->assertTrue($body['has_theme']);
        } finally {
            @rmdir($libraryPath);
            @rmdir($tempDir);
        }
    }

    /**
     * Gate: scanThemeMedia() proceeds normally when NO admin middleware is set
     * (the unit-test happy path; the gate is skipped entirely).
     */
    public function testScanThemeMediaProceedsWhenNoMiddlewareSet(): void
    {
        $repository = $this->createMock(ThemeMediaRepository::class);
        $finder = $this->createMock(ThemeMediaFinder::class);
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video', 'paths' => []]);

        // No setAdminMiddleware() call -> gate skipped.
        $controller = new ThemeMediaController($repository, $finder, $libraryManager);

        $request = new Request();

        $response = $controller->scanThemeMedia($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        $this->assertNotSame(401, $response->statusCode);
        $this->assertNotSame(403, $response->statusCode);
    }

    /**
     * Gate: deleteThemeMedia() returns 401 when AdminMiddleware denies with 401
     * (no userId), and performs no deletion side effects.
     */
    public function testDeleteThemeMediaReturns401WhenUnauthenticated(): void
    {
        $controller = $this->makeGatedControllerExpectingNoSideEffects();
        $controller->setAdminMiddleware($this->makeAdminMiddleware(null));

        $request = new Request();
        // userId intentionally left null -> checkAccess() returns 401.

        $response = $controller->deleteThemeMedia($request, ['id' => 'lib-1']);

        $this->assertSame(401, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Unauthorized', $body['error']);
        $this->assertSame('auth.required', $body['code']);
    }

    /**
     * Gate: deleteThemeMedia() returns 403 when AdminMiddleware denies with 403
     * (authenticated but not an admin), and performs no deletion side effects.
     */
    public function testDeleteThemeMediaReturns403WhenNotAdmin(): void
    {
        $controller = $this->makeGatedControllerExpectingNoSideEffects();
        $controller->setAdminMiddleware($this->makeAdminMiddleware(null));

        $request = new Request();
        $request->userId = 'user-1';

        $response = $controller->deleteThemeMedia($request, ['id' => 'lib-1']);

        $this->assertSame(403, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Forbidden', $body['error']);
        $this->assertSame('auth.not_admin', $body['code']);
    }

    /**
     * Gate: deleteThemeMedia() proceeds (no 401/403) when AdminMiddleware allows
     * the request (checkAccess() returns null for a valid admin).
     */
    public function testDeleteThemeMediaProceedsWhenAdminAllowed(): void
    {
        $repository = $this->createMock(ThemeMediaRepository::class);
        $repository->expects($this->once())
            ->method('deleteByLibraryId')
            ->with('lib-1');

        $finder = $this->createMock(ThemeMediaFinder::class);

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);

        $controller = new ThemeMediaController($repository, $finder, $libraryManager);
        $controller->setAdminMiddleware($this->makeAdminMiddleware(['id' => 'admin-1', 'is_admin' => 1]));

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->deleteThemeMedia($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        $this->assertNotSame(401, $response->statusCode);
        $this->assertNotSame(403, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertTrue($body['deleted']);
    }

    /**
     * Gate: deleteThemeMedia() proceeds normally when NO admin middleware is set.
     */
    public function testDeleteThemeMediaProceedsWhenNoMiddlewareSet(): void
    {
        $repository = $this->createMock(ThemeMediaRepository::class);
        $repository->expects($this->once())
            ->method('deleteByLibraryId')
            ->with('lib-1');

        $finder = $this->createMock(ThemeMediaFinder::class);

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);

        // No setAdminMiddleware() call -> gate skipped.
        $controller = new ThemeMediaController($repository, $finder, $libraryManager);

        $request = new Request();

        $response = $controller->deleteThemeMedia($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        $this->assertNotSame(401, $response->statusCode);
        $this->assertNotSame(403, $response->statusCode);
    }

    /**
     * Gate: getThemeMedia() (the READ) is NOT gated even when admin middleware
     * is set — matches LibraryController, which gates mutations only.
     */
    public function testGetThemeMediaIsNotGatedByAdminMiddleware(): void
    {
        $repository = $this->createMock(ThemeMediaRepository::class);
        $repository->expects($this->once())
            ->method('findByLibraryId')
            ->with('lib-1')
            ->willReturn(null);

        $finder = $this->createMock(ThemeMediaFinder::class);

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn(['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video']);

        $controller = new ThemeMediaController($repository, $finder, $libraryManager);
        // Even with an admin gate that would deny (no userId), the READ proceeds.
        $controller->setAdminMiddleware($this->makeAdminMiddleware(null));

        $request = new Request();
        // userId intentionally null: a gated endpoint would 401, the read must not.

        $response = $controller->getThemeMedia($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        $this->assertNotSame(401, $response->statusCode);
        $this->assertNotSame(403, $response->statusCode);
    }
}
