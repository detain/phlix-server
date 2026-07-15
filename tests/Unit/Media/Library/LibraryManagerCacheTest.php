<?php

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Common\Logger\LoggerFactory;
use Workerman\MySQL\Connection;

/**
 * Tests for LibraryManager cache behavior.
 *
 * @covers \Phlix\Media\Library\LibraryManager
 */
class LibraryManagerCacheTest extends TestCase
{
    private MediaScanner $scanner;
    private FolderWatcher $watcher;
    private MusicLibraryService $musicLibraryService;
    private int $queryCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');

        // Reset cache before each test
        LibraryManager::clearCache();

        $this->scanner = $this->createMock(MediaScanner::class);
        $this->watcher = $this->createMock(FolderWatcher::class);
        $musicScanner = $this->createMock(MusicLibraryScanner::class);
        $this->musicLibraryService = $this->createMock(MusicLibraryService::class);

        // Track query count for assertions
        $this->queryCount = 0;
    }

    /**
     * Creates a mock database connection that tracks queries.
     */
    private function mockDbWithQueryTracking(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql) {
            $this->queryCount++;
            if (str_contains($sql, 'SELECT')) {
                return [
                    [
                        'id' => 'lib-1',
                        'name' => 'Movies',
                        'type' => 'video',
                        'paths' => '["/movies"]',
                        'options' => '{}',
                        'display_order' => 1,
                    ],
                ];
            }
            return true;
        });
        return $db;
    }

    public function testClearCacheRemovesAllCachedEntries(): void
    {
        // First call should populate cache
        $db = $this->mockDbWithQueryTracking();
        $manager = new LibraryManager($db, $this->scanner, $this->watcher, $this->musicLibraryService);

        $result1 = $manager->getAllLibraries();
        $this->assertNotEmpty($result1);
        $firstQueryCount = $this->queryCount;

        // Second call should use cache (no additional query)
        $result2 = $manager->getAllLibraries();
        $this->assertEquals($firstQueryCount, $this->queryCount);

        // Clear the cache
        LibraryManager::clearCache();

        // After clear, next call should query DB again
        $result3 = $manager->getAllLibraries();
        $this->assertGreaterThan($firstQueryCount, $this->queryCount);
    }

    public function testGetAllLibrariesReturnsCachedDataOnHit(): void
    {
        $db = $this->mockDbWithQueryTracking();
        $manager = new LibraryManager($db, $this->scanner, $this->watcher, $this->musicLibraryService);

        // First call - cache miss, DB query executed
        $result1 = $manager->getAllLibraries();
        $this->assertCount(1, $result1);
        $queriesBefore = $this->queryCount;

        // Second call - should be cache hit
        $result2 = $manager->getAllLibraries();
        $this->assertEquals($queriesBefore, $this->queryCount);
        $this->assertEquals($result1, $result2);
    }

    public function testGetAllLibrariesQueriesDbOnCacheMiss(): void
    {
        $db = $this->mockDbWithQueryTracking();
        $manager = new LibraryManager($db, $this->scanner, $this->watcher, $this->musicLibraryService);

        // First call should query DB
        $manager->getAllLibraries();
        $this->assertEquals(1, $this->queryCount);

        // Second call should use cache (no additional query)
        $manager->getAllLibraries();
        $this->assertEquals(1, $this->queryCount);
    }

    public function testGetAllLibrariesReturnsEmptyArrayOnNoResults(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $manager = new LibraryManager($db, $this->scanner, $this->watcher, $this->musicLibraryService);

        $result = $manager->getAllLibraries();

        $this->assertEmpty($result);
    }

    public function testCreateLibraryInvalidatesCache(): void
    {
        // Set up mock that returns libraries initially, then succeeds on insert
        $db = $this->createMock(Connection::class);
        $callCount = 0;
        $db->method('query')->willReturnCallback(function (string $sql) use (&$callCount) {
            $callCount++;
            if (str_contains($sql, 'SELECT')) {
                return [
                    [
                        'id' => 'lib-1',
                        'name' => 'Movies',
                        'type' => 'video',
                        'paths' => '["/movies"]',
                        'options' => '{}',
                        'display_order' => 1,
                    ],
                ];
            }
            if (str_contains($sql, 'INSERT')) {
                return true;
            }
            return true;
        });

        $watcher = $this->createMock(FolderWatcher::class);

        $manager = new LibraryManager($db, $this->scanner, $watcher, $this->musicLibraryService);

        // First call populates cache
        $manager->getAllLibraries();
        $queriesAfterCacheLoad = $callCount;

        // Creating a new library invalidates cache
        $manager->createLibrary('New Library', 'video', ['/new/path']);

        // getAllLibraries should now query DB again (cache was invalidated)
        $manager->getAllLibraries();
        $this->assertGreaterThan($queriesAfterCacheLoad, $callCount);
    }

    public function testUpdateLibraryInvalidatesCache(): void
    {
        $db = $this->createMock(Connection::class);
        $callCount = 0;
        $db->method('query')->willReturnCallback(function (string $sql) use (&$callCount) {
            $callCount++;
            if (str_contains($sql, 'SELECT')) {
                return [
                    [
                        'id' => 'lib-1',
                        'name' => 'Movies',
                        'type' => 'video',
                        'paths' => '["/movies"]',
                        'options' => '{}',
                        'display_order' => 1,
                    ],
                ];
            }
            if (str_contains($sql, 'UPDATE')) {
                return true;
            }
            return true;
        });

        $manager = new LibraryManager($db, $this->scanner, $this->watcher, $this->musicLibraryService);

        // First call populates cache
        $manager->getAllLibraries();
        $queriesAfterCacheLoad = $callCount;

        // Updating a library invalidates cache
        $manager->updateLibrary('lib-1', ['name' => 'Updated Movies']);

        // getAllLibraries should now query DB again
        $manager->getAllLibraries();
        $this->assertGreaterThan($queriesAfterCacheLoad, $callCount);
    }

    public function testDeleteLibraryInvalidatesCache(): void
    {
        $db = $this->createMock(Connection::class);
        $callCount = 0;
        $db->method('query')->willReturnCallback(function (string $sql) use (&$callCount) {
            $callCount++;
            if (str_contains($sql, 'SELECT')) {
                return [
                    [
                        'id' => 'lib-1',
                        'name' => 'Movies',
                        'type' => 'video',
                        'paths' => '["/movies"]',
                        'options' => '{}',
                        'display_order' => 1,
                    ],
                ];
            }
            if (str_contains($sql, 'DELETE')) {
                return true;
            }
            return true;
        });

        $manager = new LibraryManager($db, $this->scanner, $this->watcher, $this->musicLibraryService);

        // First call populates cache
        $manager->getAllLibraries();
        $queriesAfterCacheLoad = $callCount;

        // Deleting a library invalidates cache
        $manager->deleteLibrary('lib-1');

        // getAllLibraries should now query DB again
        $manager->getAllLibraries();
        $this->assertGreaterThan($queriesAfterCacheLoad, $callCount);
    }

    public function testGetAllLibrariesCachesCorrectData(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'video',
                'paths' => '["/movies"]',
                'options' => '{}',
                'display_order' => 1,
            ],
            [
                'id' => 'lib-2',
                'name' => 'Music',
                'type' => 'music',
                'paths' => '["/music"]',
                'options' => '{}',
                'display_order' => 2,
            ],
        ]);

        $manager = new LibraryManager($db, $this->scanner, $this->watcher, $this->musicLibraryService);

        $result = $manager->getAllLibraries();

        // Verify library count
        $this->assertCount(2, $result);

        // Verify first library has correct basic fields
        $this->assertEquals('lib-1', $result[0]['id']);
        $this->assertEquals('Movies', $result[0]['name']);
        $this->assertEquals('video', $result[0]['type']);
        $this->assertEquals(['/movies'], $result[0]['paths']);
        $this->assertEquals([], $result[0]['options']);

        // Verify second library
        $this->assertEquals('lib-2', $result[1]['id']);
        $this->assertEquals('Music', $result[1]['name']);
        $this->assertEquals(['/music'], $result[1]['paths']);
    }

    public function testGetLibraryDoesNotUseCache(): void
    {
        $db = $this->createMock(Connection::class);
        $callCount = 0;
        $db->method('query')->willReturnCallback(function (string $sql) use (&$callCount) {
            $callCount++;
            if (str_contains($sql, 'SELECT') && str_contains($sql, 'libraries')) {
                return [
                    [
                        'id' => 'lib-1',
                        'name' => 'Movies',
                        'type' => 'video',
                        'paths' => '["/movies"]',
                        'options' => '{}',
                        'display_order' => 1,
                    ],
                ];
            }
            return [
                [
                    'id' => 'lib-1',
                    'name' => 'Movies',
                    'type' => 'video',
                    'paths' => '["/movies"]',
                    'options' => '{}',
                    'display_order' => 1,
                ],
            ];
        });

        $manager = new LibraryManager($db, $this->scanner, $this->watcher, $this->musicLibraryService);

        // getAllLibraries uses cache
        $manager->getAllLibraries();
        $queriesAfterCacheLoad = $callCount;

        // getLibrary should NOT use the cache (it uses fetchLibraryRow directly)
        $manager->getLibrary('lib-1');
        $this->assertGreaterThan($queriesAfterCacheLoad, $callCount);
    }

    public function testClearCacheOnNonExistentCacheIsSafe(): void
    {
        // Ensure cache is clear (may already be clear)
        LibraryManager::clearCache();

        // Should not throw any exception
        $this->expectNotToPerformAssertions();
        LibraryManager::clearCache();
    }

    public function testMultipleInstancesShareCache(): void
    {
        $db = $this->mockDbWithQueryTracking();
        $manager1 = new LibraryManager($db, $this->scanner, $this->watcher, $this->musicLibraryService);

        // First instance populates cache
        $manager1->getAllLibraries();
        $queriesAfterFirst = $this->queryCount;

        // Second instance with same static cache should use it
        $db2 = $this->createMock(Connection::class);
        $db2->method('query')->willReturnCallback(function (string $sql) {
            // This should NOT be called if cache is working
            $this->fail('Second instance should use cached data, not query DB');
        });

        $manager2 = new LibraryManager($db2, $this->scanner, $this->watcher, $this->musicLibraryService);
        $manager2->getAllLibraries(); // Should use cache from manager1

        $this->assertEquals($queriesAfterFirst, $this->queryCount);
    }

    public function testUpdateLibraryWithEmptyDataDoesNotInvalidateCache(): void
    {
        $db = $this->createMock(Connection::class);
        $callCount = 0;
        $db->method('query')->willReturnCallback(function (string $sql) use (&$callCount) {
            $callCount++;
            if (str_contains($sql, 'SELECT')) {
                return [
                    [
                        'id' => 'lib-1',
                        'name' => 'Movies',
                        'type' => 'video',
                        'paths' => '["/movies"]',
                        'options' => '{}',
                        'display_order' => 1,
                    ],
                ];
            }
            return true;
        });

        $manager = new LibraryManager($db, $this->scanner, $this->watcher, $this->musicLibraryService);

        // Populate cache
        $manager->getAllLibraries();
        $queriesAfterCacheLoad = $callCount;

        // Update with empty data - early return before invalidation
        $manager->updateLibrary('lib-1', []);

        // No new queries expected since early return
        $this->assertEquals($queriesAfterCacheLoad, $callCount);

        // Cache should still be valid
        $manager->getAllLibraries();
        $this->assertEquals($queriesAfterCacheLoad, $callCount);
    }
}
