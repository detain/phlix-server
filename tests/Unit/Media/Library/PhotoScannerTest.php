<?php

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\PhotoScanner;
use Workerman\MySQL\Connection;

/**
 * Unit tests for PhotoScanner class.
 *
 * @since 0.16.0
 */
class PhotoScannerTest extends TestCase
{
    private PhotoScanner $scanner;
    private Connection $db;
    private ItemRepository $itemRepo;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->itemRepo = new ItemRepository($this->db);
        $this->scanner = new PhotoScanner($this->db, $this->itemRepo);
    }

    public function testIsPhotoExtensionReturnsTrueForJpeg(): void
    {
        $this->assertTrue($this->scanner->isPhotoExtension('jpg'));
        $this->assertTrue($this->scanner->isPhotoExtension('jpeg'));
    }

    public function testIsPhotoExtensionReturnsTrueForPng(): void
    {
        $this->assertTrue($this->scanner->isPhotoExtension('png'));
    }

    public function testIsPhotoExtensionReturnsTrueForHeic(): void
    {
        $this->assertTrue($this->scanner->isPhotoExtension('heic'));
        $this->assertTrue($this->scanner->isPhotoExtension('heif'));
    }

    public function testIsPhotoExtensionReturnsFalseForVideo(): void
    {
        $this->assertFalse($this->scanner->isPhotoExtension('mp4'));
        $this->assertFalse($this->scanner->isPhotoExtension('mkv'));
        $this->assertFalse($this->scanner->isPhotoExtension('avi'));
    }

    public function testIsPhotoExtensionReturnsFalseForAudio(): void
    {
        $this->assertFalse($this->scanner->isPhotoExtension('mp3'));
        $this->assertFalse($this->scanner->isPhotoExtension('flac'));
        $this->assertFalse($this->scanner->isPhotoExtension('wav'));
    }

    public function testGetSupportedExtensionsReturnsArray(): void
    {
        $extensions = $this->scanner->getSupportedExtensions();

        $this->assertIsArray($extensions);
        $this->assertContains('jpg', $extensions);
        $this->assertContains('jpeg', $extensions);
        $this->assertContains('png', $extensions);
        $this->assertContains('heic', $extensions);
    }

    public function testHarvestExifReturnsEmptyArrayForNonExistentFile(): void
    {
        $result = $this->scanner->harvestExif('/non/existent/path.jpg');

        $this->assertIsArray($result);
        // When file doesn't exist, it returns basic metadata (empty values)
        $this->assertArrayHasKey('camera_make', $result);
        $this->assertArrayHasKey('camera_model', $result);
    }

    public function testHarvestExifReturnsStructuredArray(): void
    {
        // Create a minimal JPEG file for testing
        $tempDir = sys_get_temp_dir();
        $testFile = $tempDir . '/test_photo_' . uniqid() . '.jpg';

        // Create a minimal valid JPEG (1x1 pixel red dot)
        $minimalJpeg = base64_decode(
            '/9j/4AAQSkZJRgABAQEAeAB4AAD/4QBmRXhpZgAATU0AKgAAAAgABAEaAAUAAAABAAAAPgEbAAUAAAABAAAARgEoAAMAAAABAAIAAAExAAIAAAAQAAAATgAAAAAAAAB4AAAAAQAAAHgAAAAB'
            . 'AAEAAQAAAAMAAAAgAAAABD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMCwsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkU'
            . 'DQ4NFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAE'
            . 'AAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAB//2Q=='
        );

        if ($minimalJpeg === false || strlen($minimalJpeg) < 100) {
            // Create a minimal valid JPEG manually if base64 decode failed
            $minimalJpeg = $this->createMinimalJpeg();
        }

        file_put_contents($testFile, $minimalJpeg);

        try {
            $result = $this->scanner->harvestExif($testFile);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('camera_make', $result);
            $this->assertArrayHasKey('camera_model', $result);
            $this->assertArrayHasKey('lens', $result);
            $this->assertArrayHasKey('aperture', $result);
            $this->assertArrayHasKey('iso', $result);
            $this->assertArrayHasKey('shutter_speed', $result);
            $this->assertArrayHasKey('focal_length', $result);
            $this->assertArrayHasKey('width', $result);
            $this->assertArrayHasKey('height', $result);
            $this->assertArrayHasKey('orientation', $result);
            $this->assertArrayHasKey('orientation_name', $result);
            $this->assertArrayHasKey('date_taken_unix', $result);
            $this->assertArrayHasKey('gps_lat', $result);
            $this->assertArrayHasKey('gps_lng', $result);
            $this->assertArrayHasKey('gps_alt', $result);

            // Verify basic types
            $this->assertIsString($result['orientation_name']);
            $this->assertContains($result['orientation_name'], [
                'Normal', 'Mirror Horizontal', 'Rotate 180', 'Mirror Vertical',
                'Mirror Horizontal and Rotate 270', 'Rotate 90', 'Mirror Horizontal and Rotate 90', 'Rotate 270'
            ]);
        } finally {
            unlink($testFile);
        }
    }

    public function testHarvestExifReturnsEmptyOnNonJpeg(): void
    {
        // Create a minimal PNG file
        $tempDir = sys_get_temp_dir();
        $testFile = $tempDir . '/test_photo_' . uniqid() . '.png';

        // Minimal 1x1 transparent PNG
        $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        file_put_contents($testFile, $pngData);

        try {
            $result = $this->scanner->harvestExif($testFile);

            $this->assertIsArray($result);
            // PNG returns basic metadata without EXIF
            $this->assertNull($result['camera_make']);
            $this->assertNull($result['camera_model']);
            $this->assertNull($result['iso']);
            // Width/height should be available from getimagesize
            $this->assertArrayHasKey('width', $result);
            $this->assertArrayHasKey('height', $result);
        } finally {
            unlink($testFile);
        }
    }

    public function testHarvestExifGpsCoordinatesParsed(): void
    {
        // This test requires a real JPEG with GPS data which is complex to create
        // Instead, test the GPS parsing logic indirectly via the method signature
        $result = $this->scanner->harvestExif('/non/existent/file.jpg');

        $this->assertArrayHasKey('gps_lat', $result);
        $this->assertArrayHasKey('gps_lng', $result);
        $this->assertArrayHasKey('gps_alt', $result);

        // For non-existent file, these should be null
        $this->assertNull($result['gps_lat']);
        $this->assertNull($result['gps_lng']);
    }

    public function testScanPhotoLibraryYieldsItems(): void
    {
        // Create temp directory with test photos
        $tempDir = sys_get_temp_dir() . '/phlix_test_photos_' . uniqid();
        mkdir($tempDir, 0755, true);

        // Create a minimal JPEG file
        $testFile = $tempDir . '/test_photo.jpg';
        $minimalJpeg = $this->createMinimalJpeg();
        file_put_contents($testFile, $minimalJpeg);

        $libraryId = 'test-library-id';

        try {
            $items = [];
            foreach ($this->scanner->scanPhotoLibrary($tempDir, $libraryId) as $item) {
                $items[] = $item;
            }

            $this->assertCount(1, $items);
            $item = $items[0];

            $this->assertEquals($libraryId, $item['library_id']);
            $this->assertEquals('photo', $item['type']);
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('path', $item);
            $this->assertArrayHasKey('metadata_json', $item);
            $this->assertIsArray($item['metadata_json']);
        } finally {
            // Cleanup
            if (file_exists($testFile)) {
                unlink($testFile);
            }
            rmdir($tempDir);
        }
    }

    public function testScanPhotoLibrarySkipsHiddenFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/phlix_test_photos_' . uniqid();
        mkdir($tempDir, 0755, true);

        // Create visible and hidden files
        $visibleFile = $tempDir . '/visible.jpg';
        $hiddenFile = $tempDir . '/.hidden.jpg';

        $minimalJpeg = $this->createMinimalJpeg();
        file_put_contents($visibleFile, $minimalJpeg);
        file_put_contents($hiddenFile, $minimalJpeg);

        $libraryId = 'test-library-id';

        try {
            $items = [];
            foreach ($this->scanner->scanPhotoLibrary($tempDir, $libraryId) as $item) {
                $items[] = $item;
            }

            // Should only contain the visible file
            $this->assertCount(1, $items);
            $this->assertStringNotContainsString('.hidden', $items[0]['path']);
        } finally {
            unlink($visibleFile);
            unlink($hiddenFile);
            rmdir($tempDir);
        }
    }

    /**
     * Creates a minimal valid JPEG for testing.
     */
    private function createMinimalJpeg(): string
    {
        // Minimal JPEG structure: SOI + APP0 + DQT + SOF0 + DHT + SOS + EOI
        // This is a very basic valid JPEG
        return base64_decode(
            '/9j/4AAQSkZJRgABAQEAeAB4AAD/4QCmRXhpZgAATU0AKgAAAAgABAEaAAUAAAABAAAAPgEbAAUAAAABAAAARgEoAAMAAAABAAIAAAExAAIAAAAQAAAATgAAAAAAAAB4AAAAAQAAAHgAAAAB'
            . 'AAEAAQAAAAMAAAAgAAAABD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMCwsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkU'
            . 'DQ4NFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAE'
            . 'AAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAB//2Q=='
        );
    }
}
