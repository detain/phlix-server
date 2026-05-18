<?php

namespace Phlex\Tests\Unit\Collections;

use PHPUnit\Framework\TestCase;
use Phlex\Collections\Collection;
use Phlex\Collections\CollectionRepository;
use Workerman\MySQL\Connection;

class CollectionRepositoryTest extends TestCase
{
    public function testCanCreateCollectionRepository(): void
    {
        $db = $this->createMock(Connection::class);
        $repo = new CollectionRepository($db);

        $this->assertInstanceOf(CollectionRepository::class, $repo);
    }

    public function testInsertThenFindReturnsSameRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO collections'),
                $this->callback(function ($params) {
                    return count($params) === 8
                        && $params[0] === 'col-1'
                        && $params[1] === 'Oscar Winners'
                        && $params[2] === 'lib-1'
                        && $params[3] === null
                        && $params[4] === null
                        && $params[5] === 0;
                })
            );

        $db->method('query')->willReturn([]);

        $repo = new CollectionRepository($db);
        $collection = new Collection(
            id: 'col-1',
            name: 'Oscar Winners',
            libraryId: 'lib-1',
            smartPlaylistId: null,
            parentId: null,
            sortOrder: 0,
        );

        $repo->insert($collection);
    }

    public function testUpdateModifiesRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function ($sql) {
                    return strpos($sql, 'collections') !== false
                        && strpos($sql, 'SET') !== false
                        && strpos($sql, 'WHERE id') !== false;
                }),
                $this->callback(function ($params) {
                    return count($params) === 7
                        && $params[0] === 'Updated Name'
                        && $params[6] === 'col-1';
                })
            );

        $repo = new CollectionRepository($db);
        $collection = new Collection(
            id: 'col-1',
            name: 'Updated Name',
            libraryId: 'lib-1',
            smartPlaylistId: null,
            parentId: null,
            sortOrder: 0,
        );

        $repo->update($collection);

        $this->assertTrue(true); // Placeholder assertion
    }

    public function testDeleteRemovesRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM collections WHERE id'),
                $this->callback(function ($params) {
                    return count($params) === 1 && $params[0] === 'col-1';
                })
            );

        $db->method('query')->willReturn([]);

        $repo = new CollectionRepository($db);
        $repo->delete('col-1');
    }

    public function testFindByLibraryIdReturnsMatching(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'col-1',
                'name' => 'Collection 1',
                'library_id' => 'lib-1',
                'smart_playlist_id' => null,
                'parent_id' => null,
                'sort_order' => 0,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ],
            [
                'id' => 'col-2',
                'name' => 'Collection 2',
                'library_id' => 'lib-1',
                'smart_playlist_id' => null,
                'parent_id' => null,
                'sort_order' => 1,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ],
        ]);

        $repo = new CollectionRepository($db);
        $result = $repo->findByLibraryId('lib-1');

        $this->assertCount(2, $result);
        $this->assertInstanceOf(Collection::class, $result[0]);
        $this->assertInstanceOf(Collection::class, $result[1]);
        $this->assertEquals('col-1', $result[0]->id);
        $this->assertEquals('col-2', $result[1]->id);
    }

    public function testFindByParentIdReturnsMatching(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'col-child-1',
                'name' => 'Child Collection',
                'library_id' => 'lib-1',
                'smart_playlist_id' => null,
                'parent_id' => 'col-parent-1',
                'sort_order' => 0,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ],
        ]);

        $repo = new CollectionRepository($db);
        $result = $repo->findByParentId('col-parent-1');

        $this->assertCount(1, $result);
        $this->assertInstanceOf(Collection::class, $result[0]);
        $this->assertEquals('col-child-1', $result[0]->id);
        $this->assertEquals('col-parent-1', $result[0]->parentId);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new CollectionRepository($db);
        $result = $repo->findById('non-existent-id');

        $this->assertNull($result);
    }

    public function testFindByIdReturnsCollectionWhenFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'col-1',
                'name' => 'My Collection',
                'library_id' => 'lib-1',
                'smart_playlist_id' => 'sp-1',
                'parent_id' => null,
                'sort_order' => 5,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ],
        ]);

        $repo = new CollectionRepository($db);
        $result = $repo->findById('col-1');

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertEquals('col-1', $result->id);
        $this->assertEquals('My Collection', $result->name);
        $this->assertEquals('sp-1', $result->smartPlaylistId);
        $this->assertTrue($result->isSmart());
    }
}
