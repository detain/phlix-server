<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\LibraryManager;
use Phlix\Server\Http\Controllers\ThemeMediaController;
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
}
