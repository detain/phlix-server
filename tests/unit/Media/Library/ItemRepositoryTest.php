<?php

namespace Phlex\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Library\ItemRepository;
use Workerman\MySQL\Connection;

class ItemRepositoryTest extends TestCase
{
    public function testCanCreateItemRepository(): void
    {
        $db = $this->createMock(Connection::class);
        $repo = new ItemRepository($db);

        $this->assertInstanceOf(ItemRepository::class, $repo);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new ItemRepository($db);
        $result = $repo->findById('non-existent-id');

        $this->assertNull($result);
    }

    public function testFindByIdReturnsItemWhenFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'test-id',
                'name' => 'Test Movie',
                'type' => 'movie',
                'library_id' => 'lib-1',
                'path' => '/movies/test.mkv',
                'metadata_json' => '{}',
            ]
        ]);

        $repo = new ItemRepository($db);
        $result = $repo->findById('test-id');

        $this->assertIsArray($result);
        $this->assertEquals('test-id', $result['id']);
        $this->assertEquals('Test Movie', $result['name']);
    }

    public function testFindByPathReturnsNullWhenNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new ItemRepository($db);
        $result = $repo->findByPath('/non/existent/path');

        $this->assertNull($result);
    }

    public function testFindByPathReturnsItemWhenFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'test-id',
                'name' => 'Test Movie',
                'type' => 'movie',
                'library_id' => 'lib-1',
                'path' => '/movies/test.mkv',
                'metadata_json' => '{"year": 2020}',
            ]
        ]);

        $repo = new ItemRepository($db);
        $result = $repo->findByPath('/movies/test.mkv');

        $this->assertIsArray($result);
        $this->assertEquals('/movies/test.mkv', $result['path']);
        $this->assertEquals(['year' => 2020], $result['metadata']);
    }

    public function testFindByParentReturnsChildren(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'child-1',
                'name' => 'Child 1',
                'type' => 'folder',
                'library_id' => 'lib-1',
                'parent_id' => 'parent-1',
                'path' => '/parent/child1',
                'metadata_json' => '{}',
            ],
            [
                'id' => 'child-2',
                'name' => 'Child 2',
                'type' => 'movie',
                'library_id' => 'lib-1',
                'parent_id' => 'parent-1',
                'path' => '/parent/child2',
                'metadata_json' => '{}',
            ],
        ]);

        $repo = new ItemRepository($db);
        $result = $repo->findByParent('parent-1');

        $this->assertCount(2, $result);
        $this->assertEquals('child-1', $result[0]['id']);
        $this->assertEquals('child-2', $result[1]['id']);
    }

    public function testGetByLibraryReturnsItems(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'item-1',
                'name' => 'Item 1',
                'type' => 'movie',
                'library_id' => 'lib-1',
                'path' => '/movies/item1.mkv',
                'metadata_json' => '{}',
            ],
        ]);

        $repo = new ItemRepository($db);
        $result = $repo->getByLibrary('lib-1');

        $this->assertCount(1, $result);
        $this->assertEquals('item-1', $result[0]['id']);
    }

    public function testGetByTypeReturnsFilteredItems(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'movie-1',
                'name' => 'Movie 1',
                'type' => 'movie',
                'library_id' => 'lib-1',
                'path' => '/movies/movie1.mkv',
                'metadata_json' => '{}',
            ],
        ]);

        $repo = new ItemRepository($db);
        $result = $repo->getByType('lib-1', 'movie');

        $this->assertCount(1, $result);
        $this->assertEquals('movie', $result[0]['type']);
    }

    public function testCreateGeneratesUuidAndInsertsItem(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO media_items'),
                $this->callback(function ($params) {
                    return count($params) === 7
                        && $params[1] === 'lib-1'
                        && $params[3] === 'Test Movie'
                        && $params[4] === 'movie'
                        && $params[5] === '/movies/test.mkv';
                })
            );

        $repo = new ItemRepository($db);
        $id = $repo->create([
            'library_id' => 'lib-1',
            'name' => 'Test Movie',
            'type' => 'movie',
            'path' => '/movies/test.mkv',
        ]);

        $this->assertNotEmpty($id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{4}[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}[0-9a-f]{4}[0-9a-f]{4}$/',
            $id
        );
    }

    public function testUpdateModifiesItem(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE media_items SET'),
                $this->callback(function ($params) {
                    return $params[0] === 'New Name' && $params[1] === 'test-id';
                })
            );

        $repo = new ItemRepository($db);
        $repo->update('test-id', ['name' => 'New Name']);
    }

    public function testDeleteRemovesItem(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM media_items WHERE id = ?'),
                ['test-id']
            );

        $repo = new ItemRepository($db);
        $repo->delete('test-id');
    }

    public function testSearchFuzzyReturnsMatchingItems(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'movie-1',
                'name' => 'Test Movie',
                'type' => 'movie',
                'library_id' => 'lib-1',
                'path' => '/movies/test.mkv',
                'metadata_json' => '{}',
            ],
        ]);

        $repo = new ItemRepository($db);
        $result = $repo->searchFuzzy('test%_special');

        $this->assertCount(1, $result);
        $this->assertEquals('Test Movie', $result[0]['name']);
    }

    public function testCountByTypeReturnsCorrectCount(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['count' => 5]]);

        $repo = new ItemRepository($db);
        $result = $repo->countByType('lib-1', 'movie');

        $this->assertEquals(5, $result);
    }

    public function testGetRecentlyAddedReturnsItems(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'movie-1',
                'name' => 'Recent Movie',
                'type' => 'movie',
                'library_id' => 'lib-1',
                'path' => '/movies/recent.mkv',
                'metadata_json' => '{}',
            ],
        ]);

        $repo = new ItemRepository($db);
        $result = $repo->getRecentlyAdded('lib-1', 20);

        $this->assertCount(1, $result);
    }

    public function testGetItemStreamsReturnsStreams(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'stream-1',
                'media_item_id' => 'movie-1',
                'stream_index' => 0,
                'stream_type' => 'video',
                'codec' => 'h264',
                'language' => null,
                'bitrate' => 5000000,
                'width' => 1920,
                'height' => 1080,
            ],
        ]);

        $repo = new ItemRepository($db);
        $result = $repo->getItemStreams('movie-1');

        $this->assertCount(1, $result);
        $this->assertEquals('video', $result[0]['stream_type']);
    }

    public function testAddStreamInsertsStream(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO media_streams'),
                $this->callback(function ($params) {
                    return count($params) === 9
                        && $params[1] === 'movie-1'
                        && $params[2] === 0
                        && $params[3] === 'video'
                        && $params[4] === 'h264';
                })
            );

        $repo = new ItemRepository($db);
        $id = $repo->addStream('movie-1', [
            'stream_index' => 0,
            'stream_type' => 'video',
            'codec' => 'h264',
        ]);

        $this->assertNotEmpty($id);
    }

    public function testBatchCreateCreatesMultipleItems(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('query')
            ->with($this->stringContains('INSERT INTO media_items'));

        $repo = new ItemRepository($db);
        $ids = $repo->batchCreate([
            [
                'library_id' => 'lib-1',
                'name' => 'Movie 1',
                'type' => 'movie',
                'path' => '/movies/movie1.mkv',
            ],
            [
                'library_id' => 'lib-1',
                'name' => 'Movie 2',
                'type' => 'movie',
                'path' => '/movies/movie2.mkv',
            ],
        ]);

        $this->assertCount(2, $ids);
    }

    public function testHydrateItemDecodesMetadata(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'test-id',
                'name' => 'Test Movie',
                'type' => 'movie',
                'library_id' => 'lib-1',
                'path' => '/movies/test.mkv',
                'metadata_json' => '{"year": 2020, "director": "Test Director"}',
            ]
        ]);

        $repo = new ItemRepository($db);
        $result = $repo->findById('test-id');

        $this->assertEquals(['year' => 2020, 'director' => 'Test Director'], $result['metadata']);
    }

    public function testFindShowsWithUnfingerprintedEpisodesReturnsDistinctShowIds(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['show_id' => 'show-1'],
            ['show_id' => 'show-2'],
        ]);

        $repo = new ItemRepository($db);
        $result = $repo->findShowsWithUnfingerprintedEpisodes(20);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('show-1', $result[0]);
        $this->assertEquals('show-2', $result[1]);
    }

    public function testFindShowsWithUnfingerprintedEpisodesReturnsEmptyWhenNoUnfingerprintedEpisodes(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new ItemRepository($db);
        $result = $repo->findShowsWithUnfingerprintedEpisodes(20);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testFindShowsWithUnfingerprintedEpisodesReturnsEmptyWhenDbReturnsNonArray(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(null);

        $repo = new ItemRepository($db);
        $result = $repo->findShowsWithUnfingerprintedEpisodes(20);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testFindShowsWithUnfingerprintedEpisodesRespectsLimit(): void
    {
        $db = $this->createMock(Connection::class);
        // Verify the query uses the limit parameter
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('LIMIT ?'),
                $this->callback(function ($params) {
                    return is_array($params) && ($params[0] ?? null) === 10;
                })
            )
            ->willReturn([]);

        $repo = new ItemRepository($db);
        $repo->findShowsWithUnfingerprintedEpisodes(10);
    }
}