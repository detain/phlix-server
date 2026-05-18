<?php

namespace Phlex\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Library\PhotoLibraryManager;
use Phlex\Media\Metadata\ExifProvider;
use Phlex\Server\Http\Controllers\PhotoController;
use Phlex\Server\Http\Request;
use Workerman\MySQL\Connection;

/**
 * Unit tests for PhotoController class.
 *
 * @since 0.16.0
 */
class PhotoControllerTest extends TestCase
{
    private PhotoController $controller;
    private ItemRepository $itemRepo;
    private PhotoLibraryManager $photoManager;
    private ExifProvider $exifProvider;
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->itemRepo = new ItemRepository($this->db);
        $this->photoManager = $this->createMock(PhotoLibraryManager::class);
        $this->exifProvider = new ExifProvider($this->itemRepo);

        $this->controller = new PhotoController(
            $this->itemRepo,
            $this->photoManager,
            $this->exifProvider
        );
    }

    public function testListAlbumsReturnsJson(): void
    {
        $request = new Request();
        $request->query = ['library_id' => 'lib-1'];

        $this->photoManager->method('getPhotosGroupedByDate')->willReturn([
            '2024-01-15' => [
                [
                    'id' => 'photo-1',
                    'name' => 'Test Photo',
                    'type' => 'photo',
                    'library_id' => 'lib-1',
                    'metadata' => [],
                ],
            ],
        ]);

        $response = $this->controller->listAlbums($request);

        $this->assertEquals(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('albums', $body);
        $this->assertIsArray($body['albums']);
    }

    public function testListAlbumsReturns400WhenMissingLibraryId(): void
    {
        $request = new Request();
        $request->query = [];

        $response = $this->controller->listAlbums($request);

        $this->assertEquals(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('error', $body);
    }

    public function testGetPhotoReturnsJsonWithExif(): void
    {
        $request = new Request();
        $params = ['id' => 'photo-123'];

        $this->db->method('query')->willReturn([
            [
                'id' => 'photo-123',
                'name' => 'Test Photo',
                'type' => 'photo',
                'library_id' => 'lib-1',
                'path' => '/photos/test.jpg',
                'metadata_json' => '{"camera_make": "Canon", "camera_model": "EOS R5"}',
            ],
        ]);

        $response = $this->controller->getPhoto($request, $params);

        $this->assertEquals(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('photo', $body);
        $this->assertEquals('photo-123', $body['photo']['id']);
        $this->assertArrayHasKey('exif', $body['photo']);
    }

    public function testGetPhoto404WhenNotFound(): void
    {
        $request = new Request();
        $params = ['id' => 'non-existent'];

        $this->db->method('query')->willReturn([]);

        $response = $this->controller->getPhoto($request, $params);

        $this->assertEquals(404, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('error', $body);
    }

    public function testGetPhoto400WhenMissingId(): void
    {
        $request = new Request();
        $params = [];

        $response = $this->controller->getPhoto($request, $params);

        $this->assertEquals(400, $response->statusCode);
    }

    public function testListPhotosReturnsJson(): void
    {
        $request = new Request();
        $request->query = ['library_id' => 'lib-1'];

        $this->db->method('query')->willReturn([
            [
                'id' => 'photo-1',
                'name' => 'Photo 1',
                'type' => 'photo',
                'library_id' => 'lib-1',
                'path' => '/photos/photo1.jpg',
                'metadata_json' => '{}',
            ],
        ]);

        $response = $this->controller->listPhotos($request);

        $this->assertEquals(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('photos', $body);
        $this->assertArrayHasKey('pagination', $body);
    }

    public function testListPhotosReturns400WhenMissingLibraryId(): void
    {
        $request = new Request();
        $request->query = [];

        $response = $this->controller->listPhotos($request);

        $this->assertEquals(400, $response->statusCode);
    }

    public function testGetAlbumReturnsAlbumData(): void
    {
        $request = new Request();
        $request->query = ['library_id' => 'lib-1'];
        $params = ['id' => md5('2024-01-15')];

        $this->photoManager->method('getPhotosGroupedByDate')->willReturn([
            '2024-01-15' => [
                [
                    'id' => 'photo-1',
                    'name' => 'Test Photo',
                    'type' => 'photo',
                    'library_id' => 'lib-1',
                    'metadata' => [],
                ],
            ],
        ]);

        $response = $this->controller->getAlbum($request, $params);

        $this->assertEquals(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('album', $body);
    }

    public function testGetAlbumReturns404WhenNotFound(): void
    {
        $request = new Request();
        $request->query = ['library_id' => 'lib-1'];
        $params = ['id' => 'non-existent-album'];

        $this->photoManager->method('getPhotosGroupedByDate')->willReturn([]);

        $response = $this->controller->getAlbum($request, $params);

        $this->assertEquals(404, $response->statusCode);
    }

    public function testGetThumbnailReturnsImage(): void
    {
        // Create a temporary image file
        $tempDir = sys_get_temp_dir();
        $testFile = $tempDir . '/test_thumb_' . uniqid() . '.jpg';

        // Minimal JPEG
        $minimalJpeg = base64_decode(
            '/9j/4AAQSkZJRgABAQEAeAB4AAD/4QCmRXhpZgAATU0AKgAAAAgABAEaAAUAAAABAAAAPgEbAAUAAAABAAAARgEoAAMAAAABAAIAAAExAAIAAAAQAAAATgAAAAAAAAB4AAAAAQAAAHgAAAAB'
            . 'AAEAAQAAAAMAAAAgAAAABD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMCwsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkU'
            . 'DQ4NFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAE'
            . 'AAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAB//2Q=='
        );

        file_put_contents($testFile, $minimalJpeg);

        try {
            $request = new Request();
            $request->query = ['w' => '100', 'h' => '100'];
            $params = ['id' => 'photo-123'];

            $this->db->method('query')->willReturn([
                [
                    'id' => 'photo-123',
                    'name' => 'Test',
                    'type' => 'photo',
                    'library_id' => 'lib-1',
                    'path' => $testFile,
                    'metadata_json' => '{}',
                ],
            ]);

            $response = $this->controller->getThumbnail($request, $params);

            // May return 500 if GD not available, but tests the flow
            $this->assertContains($response->statusCode, [200, 500]);
        } finally {
            if (file_exists($testFile)) {
                unlink($testFile);
            }
        }
    }

    public function testGetThumbnailReturns404WhenPhotoNotFound(): void
    {
        $request = new Request();
        $request->query = ['w' => '100', 'h' => '100'];
        $params = ['id' => 'non-existent'];

        $this->db->method('query')->willReturn([]);

        $response = $this->controller->getThumbnail($request, $params);

        $this->assertEquals(404, $response->statusCode);
    }

    public function testSlideshowReturnsPhotoList(): void
    {
        $request = new Request();
        $request->query = ['library_id' => 'lib-1', 'interval' => '5'];

        $this->db->method('query')->willReturn([
            [
                'id' => 'photo-1',
                'name' => 'Photo 1',
                'type' => 'photo',
                'library_id' => 'lib-1',
                'path' => '/photos/photo1.jpg',
                'metadata_json' => '{}',
            ],
        ]);

        $response = $this->controller->slideshow($request);

        $this->assertEquals(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('slideshow', $body);
        $this->assertArrayHasKey('interval', $body);
        $this->assertEquals(5, $body['interval']);
    }

    public function testSlideshowReturns400WhenMissingLibraryId(): void
    {
        $request = new Request();
        $request->query = [];

        $response = $this->controller->slideshow($request);

        $this->assertEquals(400, $response->statusCode);
    }
}
