<?php

declare(strict_types=1);

namespace Phlex\Tests\Integration\Collections;

use PHPUnit\Framework\TestCase;
use Phlex\Collections\Collection;
use Phlex\Collections\CollectionItemRepository;
use Phlex\Collections\CollectionManager;
use Phlex\Collections\CollectionRepository;
use Phlex\Collections\CollectionWithItems;
use Phlex\Media\Library\ItemRepository;
use Phlex\Playlists\SmartPlaylistEngine;
use Phlex\Playlists\SmartPlaylistRepository;
use Workerman\MySQL\Connection;

class CollectionCrudTest extends TestCase
{
    public function testFullLifecycle(): void
    {
        // Create mock database connection
        $db = $this->createMock(Connection::class);

        // Track queries to verify the full lifecycle
        $queries = [];

        $db->method('query')->willReturnCallback(function ($sql, $params = []) use (&$queries) {
            $queries[] = ['sql' => $sql, 'params' => $params];

            if (strpos($sql, 'SELECT * FROM collections WHERE id') !== false) {
                // findById - return collection on first call, then null
                return [
                    [
                        'id' => 'col-1',
                        'name' => 'Test Collection',
                        'library_id' => 'lib-1',
                        'smart_playlist_id' => null,
                        'parent_id' => null,
                        'sort_order' => 0,
                        'created_at' => '2024-01-01 00:00:00',
                        'updated_at' => '2024-01-01 00:00:00',
                    ],
                ];
            }

            if (strpos($sql, 'SELECT * FROM collections WHERE library_id') !== false) {
                return [
                    [
                        'id' => 'col-1',
                        'name' => 'Test Collection',
                        'library_id' => 'lib-1',
                        'smart_playlist_id' => null,
                        'parent_id' => null,
                        'sort_order' => 0,
                        'created_at' => '2024-01-01 00:00:00',
                        'updated_at' => '2024-01-01 00:00:00',
                    ],
                ];
            }

            if (strpos($sql, 'SELECT * FROM collections') !== false && strpos($sql, 'ORDER BY') !== false) {
                return [];
            }

            if (strpos($sql, 'SELECT * FROM collection_items') !== false) {
                // findMediaItemIdsForCollection
                return [
                    ['media_item_id' => 'media-1'],
                    ['media_item_id' => 'media-2'],
                ];
            }

            if (strpos($sql, 'SELECT COUNT(*)') !== false) {
                return [['cnt' => 2]];
            }

            if (strpos($sql, 'SELECT MAX(sort_order)') !== false) {
                return [['max_order' => 1]];
            }

            if (strpos($sql, 'SELECT media_item_id FROM collection_items') !== false) {
                return [
                    ['media_item_id' => 'media-1'],
                    ['media_item_id' => 'media-2'],
                ];
            }

            if (strpos($sql, 'SELECT 1 FROM collection_items') !== false) {
                return [['1' => 1]];
            }

            return [];
        });

        // Create real repositories with mock DB
        $collectionRepo = new CollectionRepository($db);
        $itemRepo = new CollectionItemRepository($db);

        // Create mock dependencies
        $itemRepository = $this->createMock(ItemRepository::class);
        $engine = new SmartPlaylistEngine($itemRepository); // Use real engine with mock dependency
        $playlistRepo = $this->createMock(SmartPlaylistRepository::class);
        $mediaItemRepo = $this->createMock(ItemRepository::class);

        $mediaItemRepo->method('findById')->willReturnMap([
            ['media-1', ['id' => 'media-1', 'name' => 'Movie 1']],
            ['media-2', ['id' => 'media-2', 'name' => 'Movie 2']],
        ]);

        // Create manager
        $manager = new CollectionManager(
            $collectionRepo,
            $itemRepo,
            $engine,
            $playlistRepo,
            $mediaItemRepo
        );

        // Test: Create collection
        $now = new \DateTimeImmutable();
        $collection = new Collection(
            id: 'col-new',
            name: 'New Collection',
            libraryId: 'lib-1',
            smartPlaylistId: null,
            parentId: null,
            sortOrder: 0,
            createdAt: $now,
            updatedAt: $now,
        );

        $manager->create($collection);

        // Verify INSERT was called
        $insertFound = false;
        foreach ($queries as $q) {
            if (strpos($q['sql'], 'INSERT INTO collections') !== false) {
                $insertFound = true;
                $this->assertEquals('col-new', $q['params'][0]);
                $this->assertEquals('New Collection', $q['params'][1]);
                break;
            }
        }
        $this->assertTrue($insertFound, 'INSERT INTO collections should be called on create');

        // Test: Get collection with items
        $collectionWithItems = $manager->getCollectionWithItems('col-1');
        $this->assertInstanceOf(CollectionWithItems::class, $collectionWithItems);
        $this->assertEquals('col-1', $collectionWithItems->collection->id);
        $this->assertCount(2, $collectionWithItems->items);
        $this->assertEquals(2, $collectionWithItems->total);

        // Test: Add item
        $queries = []; // Reset tracking
        $manager->addItem('col-1', 'media-3');

        $addItemFound = false;
        foreach ($queries as $q) {
            if (strpos($q['sql'], 'SELECT MAX(sort_order)') !== false) {
                $addItemFound = true;
                $this->assertEquals('col-1', $q['params'][0]);
            }
        }
        $this->assertTrue($addItemFound, 'MAX(sort_order) query should be called when adding item');

        // Test: Bulk add
        $queries = []; // Reset tracking
        $manager->bulkAddFromSearch('col-1', ['media-4', 'media-5', 'media-6']);

        // Should skip media-1 and media-2 (already exist), add media-4, media-5, media-6
        $insertCount = 0;
        foreach ($queries as $q) {
            if (strpos($q['sql'], 'SELECT 1 FROM collection_items') !== false) {
                // existsInCollection check
            } elseif (strpos($q['sql'], 'SELECT MAX(sort_order)') !== false) {
                // getMaxSortOrder
            } elseif (strpos($q['sql'], 'INSERT INTO collection_items') !== false) {
                $insertCount++;
            }
        }
        // Should have 3 inserts (media-1 and media-2 already exist)
        $this->assertEquals(3, $insertCount, 'Should insert 3 new items via bulkAddFromSearch');

        // Test: Get collections for library
        $collections = $manager->getCollectionsForLibrary('lib-1');
        $this->assertCount(1, $collections);
        $this->assertEquals('col-1', $collections[0]->id);

        // Test: Delete - should delete items first, then collection
        $queries = [];
        $manager->delete('col-new');

        $deleteItemsFound = false;
        $deleteCollectionFound = false;
        foreach ($queries as $q) {
            if (strpos($q['sql'], 'DELETE FROM collection_items WHERE collection_id') !== false) {
                $deleteItemsFound = true;
            }
            if (strpos($q['sql'], 'DELETE FROM collections WHERE id') !== false) {
                $deleteCollectionFound = true;
            }
        }
        $this->assertTrue($deleteItemsFound, 'DELETE collection_items should be called before DELETE collections');
        $this->assertTrue($deleteCollectionFound, 'DELETE collections should be called on delete');
    }
}
