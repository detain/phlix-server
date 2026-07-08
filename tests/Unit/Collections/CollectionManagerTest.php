<?php

namespace Phlix\Tests\Unit\Collections;

use PHPUnit\Framework\TestCase;
use Phlix\Collections\Collection;
use Phlix\Collections\CollectionItemRepository;
use Phlix\Collections\CollectionManager;
use Phlix\Collections\CollectionRepository;
use Phlix\Collections\CollectionWithItems;
use Phlix\Media\Library\ItemRepository;
use Phlix\Playlists\SmartPlaylist;
use Phlix\Playlists\SmartPlaylistEngine;
use Phlix\Playlists\SmartPlaylistRepository;
use Workerman\MySQL\Connection;

class CollectionManagerTest extends TestCase
{
    public function testCanCreateCollectionManager(): void
    {
        $db = $this->createMock(Connection::class);
        $collectionRepo = new CollectionRepository($db);
        $itemRepo = new CollectionItemRepository($db);
        $itemRepository = $this->createMock(ItemRepository::class);
        $engine = new SmartPlaylistEngine($itemRepository);
        $playlistRepo = new SmartPlaylistRepository($db); // Real instance with mock db
        $mediaItemRepo = $this->createMock(ItemRepository::class);

        $manager = new CollectionManager(
            $collectionRepo,
            $itemRepo,
            $engine,
            $playlistRepo,
            $mediaItemRepo
        );

        $this->assertInstanceOf(CollectionManager::class, $manager);
    }

    public function testAddItemInsertsToRepository(): void
    {
        $db = $this->createMock(Connection::class);
        $collectionRepo = new CollectionRepository($db);
        $itemRepo = $this->createMock(CollectionItemRepository::class);
        $itemRepository = $this->createMock(ItemRepository::class);
        $engine = new SmartPlaylistEngine($itemRepository);
        $playlistRepo = new SmartPlaylistRepository($db);
        $mediaItemRepo = $this->createMock(ItemRepository::class);

        $itemRepo->expects($this->once())
            ->method('getMaxSortOrder')
            ->with('col-1')
            ->willReturn(5);

        $itemRepo->expects($this->once())
            ->method('insert')
            ->with('col-1', 'media-1', 6);

        $manager = new CollectionManager(
            $collectionRepo,
            $itemRepo,
            $engine,
            $playlistRepo,
            $mediaItemRepo
        );

        $manager->addItem('col-1', 'media-1');
    }

    public function testRemoveItemDeletesFromRepository(): void
    {
        $db = $this->createMock(Connection::class);
        $collectionRepo = new CollectionRepository($db);
        $itemRepo = $this->createMock(CollectionItemRepository::class);
        $itemRepository = $this->createMock(ItemRepository::class);
        $engine = new SmartPlaylistEngine($itemRepository);
        $playlistRepo = new SmartPlaylistRepository($db);
        $mediaItemRepo = $this->createMock(ItemRepository::class);

        $itemRepo->expects($this->once())
            ->method('delete')
            ->with('col-1', 'media-1');

        $manager = new CollectionManager(
            $collectionRepo,
            $itemRepo,
            $engine,
            $playlistRepo,
            $mediaItemRepo
        );

        $manager->removeItem('col-1', 'media-1');
    }

    public function testBulkAddFromSearchCallsRepoMultipleTimes(): void
    {
        $db = $this->createMock(Connection::class);
        $collectionRepo = new CollectionRepository($db);
        $itemRepo = $this->createMock(CollectionItemRepository::class);
        $itemRepository = $this->createMock(ItemRepository::class);
        $engine = new SmartPlaylistEngine($itemRepository);
        $playlistRepo = new SmartPlaylistRepository($db);
        $mediaItemRepo = $this->createMock(ItemRepository::class);

        $itemRepo->expects($this->once())
            ->method('getMaxSortOrder')
            ->with('col-1')
            ->willReturn(0);

        // Batch check for existing items (single query instead of N)
        $itemRepo->expects($this->once())
            ->method('findExistingInCollection')
            ->with('col-1', ['media-1', 'media-2', 'media-3'])
            ->willReturn([]);  // None exist

        // Batch insert (single query instead of N)
        $itemRepo->expects($this->once())
            ->method('batchInsert')
            ->with('col-1', [
                ['mediaItemId' => 'media-1', 'sortOrder' => 1],
                ['mediaItemId' => 'media-2', 'sortOrder' => 2],
                ['mediaItemId' => 'media-3', 'sortOrder' => 3],
            ]);

        $manager = new CollectionManager(
            $collectionRepo,
            $itemRepo,
            $engine,
            $playlistRepo,
            $mediaItemRepo
        );

        $manager->bulkAddFromSearch('col-1', ['media-1', 'media-2', 'media-3']);
    }

    public function testBulkAddFromSearchSkipsExistingItems(): void
    {
        $db = $this->createMock(Connection::class);
        $collectionRepo = new CollectionRepository($db);
        $itemRepo = $this->createMock(CollectionItemRepository::class);
        $itemRepository = $this->createMock(ItemRepository::class);
        $engine = new SmartPlaylistEngine($itemRepository);
        $playlistRepo = new SmartPlaylistRepository($db);
        $mediaItemRepo = $this->createMock(ItemRepository::class);

        $itemRepo->expects($this->once())
            ->method('getMaxSortOrder')
            ->willReturn(0);

        // Batch check: media-1 and media-2 already exist, media-3 doesn't
        $itemRepo->expects($this->once())
            ->method('findExistingInCollection')
            ->willReturn(['media-1' => true, 'media-2' => true]);

        // Only insert media-3 (media-1 and media-2 are skipped as existing)
        $itemRepo->expects($this->once())
            ->method('batchInsert')
            ->with('col-1', [
                ['mediaItemId' => 'media-3', 'sortOrder' => 1],
            ]);

        $manager = new CollectionManager(
            $collectionRepo,
            $itemRepo,
            $engine,
            $playlistRepo,
            $mediaItemRepo
        );

        $manager->bulkAddFromSearch('col-1', ['media-1', 'media-2', 'media-3']);
    }

    public function testGetCollectionWithItemsHydratesItems(): void
    {
        $db = $this->createMock(Connection::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $itemRepo = $this->createMock(CollectionItemRepository::class);
        $itemRepository = $this->createMock(ItemRepository::class);
        $engine = new SmartPlaylistEngine($itemRepository);
        $playlistRepo = new SmartPlaylistRepository($db);
        $mediaItemRepo = $this->createMock(ItemRepository::class);

        $collection = new Collection(
            id: 'col-1',
            name: 'My Collection',
            libraryId: 'lib-1',
            smartPlaylistId: null,
            parentId: null,
        );

        $collectionRepo->method('findById')
            ->with('col-1')
            ->willReturn($collection);

        $itemRepo->method('findMediaItemIdsForCollection')
            ->with('col-1')
            ->willReturn(['media-1', 'media-2']);

        // Use batch findByIds instead of individual findById calls
        $mediaItemRepo->expects($this->once())
            ->method('findByIds')
            ->with(['media-1', 'media-2'])
            ->willReturn([
                ['id' => 'media-1', 'name' => 'Item 1'],
                ['id' => 'media-2', 'name' => 'Item 2'],
            ]);

        $manager = new CollectionManager(
            $collectionRepo,
            $itemRepo,
            $engine,
            $playlistRepo,
            $mediaItemRepo
        );

        $result = $manager->getCollectionWithItems('col-1');

        $this->assertInstanceOf(CollectionWithItems::class, $result);
        $this->assertEquals('col-1', $result->collection->id);
        $this->assertCount(2, $result->items);
        $this->assertEquals(2, $result->total);
    }

    public function testGetCollectionWithItemsReturnsNullWhenNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $itemRepo = $this->createMock(CollectionItemRepository::class);
        $itemRepository = $this->createMock(ItemRepository::class);
        $engine = new SmartPlaylistEngine($itemRepository);
        $playlistRepo = new SmartPlaylistRepository($db);
        $mediaItemRepo = $this->createMock(ItemRepository::class);

        $collectionRepo->method('findById')
            ->with('non-existent')
            ->willReturn(null);

        $manager = new CollectionManager(
            $collectionRepo,
            $itemRepo,
            $engine,
            $playlistRepo,
            $mediaItemRepo
        );

        $result = $manager->getCollectionWithItems('non-existent');

        $this->assertNull($result);
    }

    public function testRefreshSmartCollectionDoesNothingForManualCollection(): void
    {
        $db = $this->createMock(Connection::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $itemRepo = $this->createMock(CollectionItemRepository::class);
        $itemRepository = $this->createMock(ItemRepository::class);
        $engine = new SmartPlaylistEngine($itemRepository);
        $playlistRepo = new SmartPlaylistRepository($db);
        $mediaItemRepo = $this->createMock(ItemRepository::class);

        $collection = new Collection(
            id: 'col-1',
            name: 'Manual Collection',
            libraryId: 'lib-1',
            smartPlaylistId: null, // Manual, not smart
            parentId: null,
        );

        $collectionRepo->method('findById')
            ->with('col-1')
            ->willReturn($collection);

        $manager = new CollectionManager(
            $collectionRepo,
            $itemRepo,
            $engine,
            $playlistRepo,
            $mediaItemRepo
        );

        $this->expectNotToPerformAssertions();

        // Should not throw, just return without doing anything
        $manager->refreshSmartCollection('col-1');
    }

    public function testFindAllReturnsCollections(): void
    {
        $db = $this->createMock(Connection::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $itemRepo = $this->createMock(CollectionItemRepository::class);
        $itemRepository = $this->createMock(ItemRepository::class);
        $engine = new SmartPlaylistEngine($itemRepository);
        $playlistRepo = new SmartPlaylistRepository($db);
        $mediaItemRepo = $this->createMock(ItemRepository::class);

        $collections = [
            new Collection(id: 'col-1', name: 'Collection 1', libraryId: 'lib-1', smartPlaylistId: null, parentId: null),
            new Collection(id: 'col-2', name: 'Collection 2', libraryId: 'lib-1', smartPlaylistId: null, parentId: null),
        ];

        $collectionRepo->method('findAll')
            ->willReturn($collections);

        $manager = new CollectionManager(
            $collectionRepo,
            $itemRepo,
            $engine,
            $playlistRepo,
            $mediaItemRepo
        );

        $result = $manager->findAll();

        $this->assertCount(2, $result);
        $this->assertEquals('col-1', $result[0]->id);
    }

    public function testGetCollectionsForLibrary(): void
    {
        $db = $this->createMock(Connection::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $itemRepo = $this->createMock(CollectionItemRepository::class);
        $itemRepository = $this->createMock(ItemRepository::class);
        $engine = new SmartPlaylistEngine($itemRepository);
        $playlistRepo = new SmartPlaylistRepository($db);
        $mediaItemRepo = $this->createMock(ItemRepository::class);

        $collections = [
            new Collection(id: 'col-1', name: 'Collection 1', libraryId: 'lib-1', smartPlaylistId: null, parentId: null),
        ];

        $collectionRepo->method('findByLibraryId')
            ->with('lib-1')
            ->willReturn($collections);

        $manager = new CollectionManager(
            $collectionRepo,
            $itemRepo,
            $engine,
            $playlistRepo,
            $mediaItemRepo
        );

        $result = $manager->getCollectionsForLibrary('lib-1');

        $this->assertCount(1, $result);
        $this->assertEquals('col-1', $result[0]->id);
    }

    public function testCreateInsertsCollection(): void
    {
        $db = $this->createMock(Connection::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $itemRepo = $this->createMock(CollectionItemRepository::class);
        $itemRepository = $this->createMock(ItemRepository::class);
        $engine = new SmartPlaylistEngine($itemRepository);
        $playlistRepo = new SmartPlaylistRepository($db);
        $mediaItemRepo = $this->createMock(ItemRepository::class);

        $collectionRepo->expects($this->once())
            ->method('insert');

        $manager = new CollectionManager(
            $collectionRepo,
            $itemRepo,
            $engine,
            $playlistRepo,
            $mediaItemRepo
        );

        $collection = new Collection(
            id: 'col-new',
            name: 'New Collection',
            libraryId: 'lib-1',
            smartPlaylistId: null,
            parentId: null,
        );

        $manager->create($collection);
    }

    public function testUpdateCallsRepoUpdate(): void
    {
        $db = $this->createMock(Connection::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $itemRepo = $this->createMock(CollectionItemRepository::class);
        $itemRepository = $this->createMock(ItemRepository::class);
        $engine = new SmartPlaylistEngine($itemRepository);
        $playlistRepo = new SmartPlaylistRepository($db);
        $mediaItemRepo = $this->createMock(ItemRepository::class);

        $collectionRepo->expects($this->once())
            ->method('update');

        $manager = new CollectionManager(
            $collectionRepo,
            $itemRepo,
            $engine,
            $playlistRepo,
            $mediaItemRepo
        );

        $collection = new Collection(
            id: 'col-1',
            name: 'Updated',
            libraryId: 'lib-1',
            smartPlaylistId: null,
            parentId: null,
        );

        $manager->update($collection);
    }

    public function testDeleteRemovesItemsAndCollection(): void
    {
        $db = $this->createMock(Connection::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $itemRepo = $this->createMock(CollectionItemRepository::class);
        $itemRepository = $this->createMock(ItemRepository::class);
        $engine = new SmartPlaylistEngine($itemRepository);
        $playlistRepo = new SmartPlaylistRepository($db);
        $mediaItemRepo = $this->createMock(ItemRepository::class);

        $itemRepo->expects($this->once())
            ->method('deleteAllForCollection')
            ->with('col-1');

        $collectionRepo->expects($this->once())
            ->method('delete')
            ->with('col-1');

        $manager = new CollectionManager(
            $collectionRepo,
            $itemRepo,
            $engine,
            $playlistRepo,
            $mediaItemRepo
        );

        $manager->delete('col-1');
    }

    public function testFindByIdReturnsCollection(): void
    {
        $db = $this->createMock(Connection::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $itemRepo = $this->createMock(CollectionItemRepository::class);
        $itemRepository = $this->createMock(ItemRepository::class);
        $engine = new SmartPlaylistEngine($itemRepository);
        $playlistRepo = new SmartPlaylistRepository($db);
        $mediaItemRepo = $this->createMock(ItemRepository::class);

        $collection = new Collection(
            id: 'col-1',
            name: 'My Collection',
            libraryId: 'lib-1',
            smartPlaylistId: null,
            parentId: null,
        );

        $collectionRepo->method('findById')
            ->with('col-1')
            ->willReturn($collection);

        $manager = new CollectionManager(
            $collectionRepo,
            $itemRepo,
            $engine,
            $playlistRepo,
            $mediaItemRepo
        );

        $result = $manager->findById('col-1');

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertEquals('col-1', $result->id);
    }
}
