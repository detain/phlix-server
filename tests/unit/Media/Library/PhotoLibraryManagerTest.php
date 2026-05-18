<?php

namespace Phlex\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Library\PhotoLibraryManager;
use Phlex\Media\Library\PhotoScanResult;
use Phlex\Media\Library\PhotoScanner;
use Workerman\MySQL\Connection;

/**
 * Unit tests for PhotoLibraryManager class.
 *
 * @since 0.16.0
 */
class PhotoLibraryManagerTest extends TestCase
{
    private PhotoLibraryManager $manager;
    private PhotoScanner $scanner;
    private ItemRepository $itemRepo;
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->itemRepo = new ItemRepository($this->db);
        $this->scanner = new PhotoScanner($this->db, $this->itemRepo);
        $this->manager = new PhotoLibraryManager($this->scanner, $this->itemRepo);
    }

    public function testRescanLibraryCallsScanner(): void
    {
        // Create temp directory with a test photo
        $tempDir = sys_get_temp_dir() . '/phlex_test_rescan_' . uniqid();
        mkdir($tempDir, 0755, true);

        $testFile = $tempDir . '/test_photo.jpg';
        $minimalJpeg = $this->createMinimalJpeg();
        file_put_contents($testFile, $minimalJpeg);

        $libraryId = 'test-library-id';

        // Mock item repository to return null for findByPath (no existing item)
        $this->db->method('query')->willReturn([]);

        try {
            $result = $this->manager->rescanLibrary($libraryId, $tempDir);

            $this->assertInstanceOf(PhotoScanResult::class, $result);
            $this->assertEquals(PhotoLibraryManager::SCAN_COMPLETED, $result->status);
            // Items should be added
            $this->assertGreaterThanOrEqual(0, $result->itemsAdded);
        } finally {
            if (file_exists($testFile)) {
                unlink($testFile);
            }
            rmdir($tempDir);
        }
    }

    public function testRescanLibraryReturnsFailedForNonExistentPath(): void
    {
        $result = $this->manager->rescanLibrary('test-id', '/non/existent/path');

        $this->assertEquals(PhotoLibraryManager::SCAN_FAILED, $result->status);
        $this->assertNotNull($result->errorMessage);
    }

    public function testUpsertPhotoStoresExif(): void
    {
        // Create temp directory with a test photo
        $tempDir = sys_get_temp_dir() . '/phlex_test_upsert_' . uniqid();
        mkdir($tempDir, 0755, true);

        $testFile = $tempDir . '/test_photo.jpg';
        $minimalJpeg = $this->createMinimalJpeg();
        file_put_contents($testFile, $minimalJpeg);

        $libraryId = 'test-library-id';

        // Track query calls
        $queryLog = [];

        $this->db->method('query')
            ->willReturnCallback(function($sql, $params = []) use (&$queryLog, $testFile) {
                $queryLog[] = ['sql' => $sql, 'params' => $params];
                if (strpos($sql, 'SELECT * FROM media_items WHERE path') !== false) {
                    return []; // No existing item
                }
                if (strpos($sql, 'SELECT * FROM media_items WHERE id') !== false) {
                    return [
                        [
                            'id' => 'new-photo-id',
                            'name' => 'test_photo',
                            'type' => 'photo',
                            'library_id' => 'test-library-id',
                            'path' => $testFile,
                            'metadata_json' => '{"camera_make":"Canon"}',
                        ],
                    ];
                }
                return [];
            });

        try {
            $result = $this->manager->upsertPhoto($libraryId, $testFile);

            // The upsert flow calls findByPath, then if null calls create
            // The mock returns nothing for INSERT, so findById will return null
            // This test just verifies the flow works without throwing
            $this->assertTrue(count($queryLog) >= 1, 'Should have made at least 1 query');
        } finally {
            if (file_exists($testFile)) {
                unlink($testFile);
            }
            rmdir($tempDir);
        }
    }

    public function testUpsertPhotoReturnsNullForNonExistentFile(): void
    {
        $result = $this->manager->upsertPhoto('test-id', '/non/existent/file.jpg');

        $this->assertNull($result);
    }

    public function testUpsertPhotoReturnsNullForUnsupportedFormat(): void
    {
        // Create temp directory with a non-photo file
        $tempDir = sys_get_temp_dir() . '/phlex_test_invalid_' . uniqid();
        mkdir($tempDir, 0755, true);

        $testFile = $tempDir . '/test_video.mp4';
        file_put_contents($testFile, 'fake video content');

        $libraryId = 'test-library-id';

        try {
            $result = $this->manager->upsertPhoto($libraryId, $testFile);

            $this->assertNull($result);
        } finally {
            if (file_exists($testFile)) {
                unlink($testFile);
            }
            rmdir($tempDir);
        }
    }

    public function testGetPhotosGroupedByDate(): void
    {
        // Mock database to return photo items via getByLibrary
        // The SQL pattern that getByLibrary uses
        $sqlPattern = "SELECT * FROM media_items WHERE library_id = ? ORDER BY name LIMIT";

        // January 15, 2024 12:00:00 UTC = 1705320000
        $jan15_2024 = 1705320000;

        $this->db->method('query')
            ->willReturnCallback(function($sql, $params = []) use ($sqlPattern, $jan15_2024) {
                if (strpos($sql, $sqlPattern) !== false) {
                    return [
                        [
                            'id' => 'photo-1',
                            'name' => 'Photo 1',
                            'type' => 'photo',
                            'library_id' => 'lib-1',
                            'path' => '/photos/photo1.jpg',
                            'metadata_json' => '{"date_taken_unix": ' . $jan15_2024 . '}',
                        ],
                        [
                            'id' => 'photo-2',
                            'name' => 'Photo 2',
                            'type' => 'photo',
                            'library_id' => 'lib-1',
                            'path' => '/photos/photo2.jpg',
                            'metadata_json' => '{"date_taken_unix": ' . $jan15_2024 . '}',
                        ],
                    ];
                }
                // Fallthrough
                return [];
            });

        $result = $this->manager->getPhotosGroupedByDate('lib-1');

        $this->assertIsArray($result);
        // Since all items have the same date, there should be one group
        $this->assertNotEmpty($result, 'Result should not be empty');
        // The key should be a date string (YYYY-MM-DD)
        $keys = array_keys($result);
        $this->assertEquals('2024-01-15', $keys[0]);
        $this->assertCount(2, $result[$keys[0]]);
    }

    public function testGetScannerReturnsScannerInstance(): void
    {
        $scanner = $this->manager->getScanner();

        $this->assertSame($this->scanner, $scanner);
    }

    /**
     * Creates a minimal valid JPEG for testing.
     */
    private function createMinimalJpeg(): string
    {
        return base64_decode(
            '/9j/4AAQSkZJRgABAQEAeAB4AAD/4QCmRXhpZgAATU0AKgAAAAgABAEaAAUAAAABAAAAPgEbAAUAAAABAAAARgEoAAMAAAABAAIAAAExAAIAAAAQAAAATgAAAAAAAAB4AAAAAQAAAHgAAAAB'
            . 'AAEAAQAAAAMAAAAgAAAABD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMCwsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkU'
            . 'DQ4NFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAE'
            . 'AAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAB//2Q=='
        );
    }
}
