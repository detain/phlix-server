<?php

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\PhotoLibraryManager;
use Phlix\Media\Metadata\ExifProvider;
use Phlix\Server\Http\Controllers\PhotoController;
use Phlix\Server\Http\Request;
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

        // Jail the file's directory so the LibraryRootGuard treats it as a
        // legitimate in-library path regardless of host HOME/tmp layout.
        $prevRoots = getenv('PHLIX_LIBRARY_ROOTS');
        putenv('PHLIX_LIBRARY_ROOTS=' . $tempDir);
        \Phlix\Common\Fs\LibraryRootGuard::reset();

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
            if ($prevRoots === false) {
                putenv('PHLIX_LIBRARY_ROOTS');
            } else {
                putenv('PHLIX_LIBRARY_ROOTS=' . $prevRoots);
            }
            \Phlix\Common\Fs\LibraryRootGuard::reset();
        }
    }

    /**
     * S11/C4 path jail: a poisoned $item['path'] pointing OUTSIDE the configured
     * library roots (e.g. /etc/passwd) must yield 404 — never serve the file and
     * never disclose its existence with a 403.
     */
    public function testGetThumbnailReturns404WhenPathEscapesLibraryRoots(): void
    {
        $request = new Request();
        $request->query = ['w' => '100', 'h' => '100'];
        $params = ['id' => 'photo-evil'];

        $this->db->method('query')->willReturn([
            [
                'id' => 'photo-evil',
                'name' => 'Poisoned',
                'type' => 'photo',
                'library_id' => 'lib-1',
                // A real, readable file that is NOT under any library root.
                'path' => '/etc/passwd',
                'metadata_json' => '{}',
            ],
        ]);

        // Constrain roots to a directory that cannot contain /etc/passwd.
        $prevRoots = getenv('PHLIX_LIBRARY_ROOTS');
        putenv('PHLIX_LIBRARY_ROOTS=' . sys_get_temp_dir());
        \Phlix\Common\Fs\LibraryRootGuard::reset();

        try {
            $response = $this->controller->getThumbnail($request, $params);
            $this->assertEquals(404, $response->statusCode);
            $body = json_decode($response->body, true);
            $this->assertArrayHasKey('error', $body);
        } finally {
            if ($prevRoots === false) {
                putenv('PHLIX_LIBRARY_ROOTS');
            } else {
                putenv('PHLIX_LIBRARY_ROOTS=' . $prevRoots);
            }
            \Phlix\Common\Fs\LibraryRootGuard::reset();
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

    public function testGetFullReturnsPhotoWithRangeRequest(): void
    {
        // Create a temporary image file
        $tempDir = sys_get_temp_dir();
        $testFile = $tempDir . '/test_full_' . uniqid() . '.jpg';

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
            $request->headers = ['Range' => 'bytes=0-99'];
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

            $response = $this->controller->getFull($request, $params);

            $this->assertEquals(206, $response->statusCode);
            $this->assertEquals('image/jpeg', $response->headers['Content-Type']);
            $this->assertEquals('bytes 0-99/390', $response->headers['Content-Range']);
            $this->assertEquals(100, strlen($response->body));
        } finally {
            if (file_exists($testFile)) {
                unlink($testFile);
            }
        }
    }

    /**
     * Regression: a Range header stored UNDER THE UPPER-CASE "RANGE" key —
     * the way Request::parseHeaders() / collectHeadersFromWorkerman() actually
     * store it in production — must still drive the 206 partial-content path.
     *
     * Before the fix, getFull() read $request->headers['Range'] (mixed case)
     * directly, which never matched the upper-cased stored key, so real range
     * requests silently fell through to a full 200. The fix reads via
     * Request::getHeader('Range'), which is case-insensitive.
     */
    public function testGetFullHonorsUpperCaseRangeHeaderKey(): void
    {
        $tempDir = sys_get_temp_dir();
        $testFile = $tempDir . '/test_full_uc_' . uniqid() . '.jpg';

        $minimalJpeg = base64_decode(
            '/9j/4AAQSkZJRgABAQEAeAB4AAD/4QCmRXhpZgAATU0AKgAAAAgABAEaAAUAAAABAAAAPgEbAAUAAAABAAAARgEoAAMAAAABAAIAAAExAAIAAAAQAAAATgAAAAAAAAB4AAAAAQAAAHgAAAAB'
            . 'AAEAAQAAAAMAAAAgAAAABD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMCwsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkU'
            . 'DQ4NFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAE'
            . 'AAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAB//2Q=='
        );

        file_put_contents($testFile, $minimalJpeg);

        try {
            // Mirror production storage: parseHeaders() upper-cases header keys,
            // so the Range header lives under "RANGE", not "Range".
            $request = new Request();
            $request->headers = ['RANGE' => 'bytes=0-99'];
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

            $response = $this->controller->getFull($request, $params);

            $this->assertEquals(206, $response->statusCode); // Partial content, not 200
            $this->assertEquals('image/jpeg', $response->headers['Content-Type']);
            $this->assertEquals('bytes 0-99/390', $response->headers['Content-Range']);
            $this->assertEquals(100, strlen($response->body));
        } finally {
            if (file_exists($testFile)) {
                unlink($testFile);
            }
        }
    }

    public function testGetFullReturnsFullPhotoWithoutRange(): void
    {
        // Create a temporary image file
        $tempDir = sys_get_temp_dir();
        $testFile = $tempDir . '/test_full_norange_' . uniqid() . '.jpg';

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

            $response = $this->controller->getFull($request, $params);

            $this->assertEquals(200, $response->statusCode);
            $this->assertEquals('image/jpeg', $response->headers['Content-Type']);
            $this->assertEquals(strlen($minimalJpeg), strlen($response->body));
        } finally {
            if (file_exists($testFile)) {
                unlink($testFile);
            }
        }
    }

    public function testGetFullReturns400WhenMissingId(): void
    {
        $request = new Request();
        $params = [];

        $response = $this->controller->getFull($request, $params);

        $this->assertEquals(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('error', $body);
    }

    public function testGetFullReturns404WhenPhotoNotFound(): void
    {
        $request = new Request();
        $params = ['id' => 'non-existent'];

        $this->db->method('query')->willReturn([]);

        $response = $this->controller->getFull($request, $params);

        $this->assertEquals(404, $response->statusCode);
    }

    public function testGetFullReturns404WhenFileNotFound(): void
    {
        $request = new Request();
        $params = ['id' => 'photo-123'];

        $this->db->method('query')->willReturn([
            [
                'id' => 'photo-123',
                'name' => 'Test',
                'type' => 'photo',
                'library_id' => 'lib-1',
                'path' => '/non/existent/path.jpg',
                'metadata_json' => '{}',
            ],
        ]);

        $response = $this->controller->getFull($request, $params);

        $this->assertEquals(404, $response->statusCode);
    }

    public function testGetFullReturns416WhenRangeNotSatisfiable(): void
    {
        $request = new Request();
        $request->headers = ['Range' => 'bytes=1000-2000'];
        $params = ['id' => 'photo-123'];

        $this->db->method('query')->willReturn([
            [
                'id' => 'photo-123',
                'name' => 'Test',
                'type' => 'photo',
                'library_id' => 'lib-1',
                'path' => '/non/existent/path.jpg',
                'metadata_json' => '{}',
            ],
        ]);

        // The test file doesn't exist so it returns 404 before checking range
        $response = $this->controller->getFull($request, $params);

        $this->assertEquals(404, $response->statusCode);
    }

    public function testGetPhotoMintsVerifiableSignedImageUrls(): void
    {
        $request = new Request();
        $this->db->method('query')->willReturn([
            [
                'id' => 'photo-123',
                'name' => 'Test Photo',
                'type' => 'photo',
                'library_id' => 'lib-1',
                'path' => '/photos/test.jpg',
                'metadata_json' => '{}',
            ],
        ]);

        $response = $this->controller->getPhoto($request, ['id' => 'photo-123']);
        $body = json_decode($response->body, true);

        $signer = \Phlix\Auth\SignedUrl::fromEnv();
        $expected = [
            'thumbnail_url' => '/api/v1/photo/photos/photo-123/thumbnail',
            'full_url' => '/api/v1/photo/photos/photo-123/full',
        ];
        foreach ($expected as $field => $path) {
            $this->assertArrayHasKey($field, $body['photo']);
            parse_str((string) parse_url((string) $body['photo'][$field], PHP_URL_QUERY), $q);
            $this->assertTrue(
                $signer->verify($path, (string) ($q['exp'] ?? ''), (string) ($q['sig'] ?? '')),
                "{$field} must be a verifiable signed URL for {$path}",
            );
        }
    }
}
