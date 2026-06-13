<?php

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Stats\StatsCollector;
use Workerman\MySQL\Connection;

class ItemRepositoryTest extends TestCase
{
    public function testCanCreateItemRepository(): void
    {
        $db = $this->createMock(Connection::class);
        $repo = new ItemRepository($db);

        $this->assertInstanceOf(ItemRepository::class, $repo);
    }

    public function testCreateScrubsInvalidUtf8InNameAndPath(): void
    {
        // Regression: a name/path with invalid UTF-8 bytes must be scrubbed to
        // valid UTF-8 before the INSERT, else MySQL rejects it with error 1366
        // ("Incorrect string value") on the utf8mb4 column.
        $captured = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->anything(),
                $this->callback(function ($params) use (&$captured): bool {
                    $captured = $params;
                    return true;
                })
            )
            ->willReturn([]);

        $repo = new ItemRepository($db);
        $repo->create([
            'id'         => 'item-1',
            'library_id' => 'lib-1',
            'name'       => "\x9CGallavich!",         // lone continuation byte
            'type'       => 'episode',
            'path'       => "/m/\x9CGallavich!.mkv",
        ]);

        $this->assertIsArray($captured);
        $this->assertTrue(mb_check_encoding($captured[3], 'UTF-8'), 'name must be valid UTF-8');
        $this->assertTrue(mb_check_encoding($captured[5], 'UTF-8'), 'path must be valid UTF-8');
        $this->assertSame('Gallavich!', $captured[3]);
        $this->assertSame('/m/Gallavich!.mkv', $captured[5]);
    }

    public function testCreatePreservesValidUtf8NameUntouched(): void
    {
        $captured = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->anything(),
                $this->callback(function ($params) use (&$captured): bool {
                    $captured = $params;
                    return true;
                })
            )
            ->willReturn([]);

        $repo = new ItemRepository($db);
        $valid = "\u{201C}Gallavich!\u{201D}"; // curly quotes — valid UTF-8
        $repo->create([
            'id'         => 'item-1',
            'library_id' => 'lib-1',
            'name'       => $valid,
            'type'       => 'episode',
            'path'       => '/m/x.mkv',
        ]);

        $this->assertIsArray($captured);
        $this->assertSame($valid, $captured[3], 'valid UTF-8 must pass through unchanged');
    }

    public function testCreateRecordsItemAddedChangeWhenCollectorWired(): void
    {
        $db = $this->createMock(Connection::class);
        $stats = $this->createMock(StatsCollector::class);
        $stats->expects($this->once())
            ->method('recordLibraryChange')
            ->with('item_added', 'item-7', 'lib-1');

        $repo = new ItemRepository($db, $stats);
        $id = $repo->create([
            'id' => 'item-7',
            'library_id' => 'lib-1',
            'name' => 'Test',
            'type' => 'movie',
            'path' => '/m/t.mkv',
        ]);

        $this->assertSame('item-7', $id);
    }

    public function testDeleteRecordsItemRemovedChangeWithLibraryId(): void
    {
        $db = $this->createMock(Connection::class);
        // The pre-delete SELECT resolves the owning library.
        $db->method('query')->willReturn([['library_id' => 'lib-9']]);

        $stats = $this->createMock(StatsCollector::class);
        $stats->expects($this->once())
            ->method('recordLibraryChange')
            ->with('item_removed', 'item-9', 'lib-9');

        (new ItemRepository($db, $stats))->delete('item-9');
    }

    public function testDeleteByLibraryRecordsSingleAggregateChange(): void
    {
        $db = $this->createMock(Connection::class);
        $stats = $this->createMock(StatsCollector::class);
        $stats->expects($this->once())
            ->method('recordLibraryChange')
            ->with('library_cleared', null, 'lib-3');

        (new ItemRepository($db, $stats))->deleteByLibrary('lib-3');
    }

    public function testCreateWithoutCollectorDoesNotThrow(): void
    {
        $db = $this->createMock(Connection::class);
        $repo = new ItemRepository($db); // no collector — recording is a no-op

        $this->assertSame(
            'item-1',
            $repo->create([
                'id' => 'item-1',
                'library_id' => 'lib-1',
                'name' => 'Test',
                'type' => 'movie',
                'path' => '/m/t.mkv',
            ]),
        );
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

    public function testSearchReturnsFullTextMatches(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with($this->stringContains('MATCH(name) AGAINST(? IN BOOLEAN MODE)'))
            ->willReturn([
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
        $result = $repo->search('Test');

        $this->assertCount(1, $result);
        $this->assertEquals('Test Movie', $result[0]['name']);
    }

    public function testSearchFallsBackToLikeWhenFullTextErrors(): void
    {
        $db = $this->createMock(Connection::class);
        // A malformed BOOLEAN MODE query (e.g. an operator-only string) makes
        // MySQL throw; search() must degrade to the LIKE-based scan rather
        // than letting the exception bubble up and crash the request.
        $db->method('query')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'IN BOOLEAN MODE')) {
                throw new \RuntimeException('syntax error in fulltext search expression');
            }
            return [
                [
                    'id' => 'movie-2',
                    'name' => 'Fallback Movie',
                    'type' => 'movie',
                    'library_id' => 'lib-1',
                    'path' => '/movies/fallback.mkv',
                    'metadata_json' => '{}',
                ],
            ];
        });

        $repo = new ItemRepository($db);
        $result = $repo->search('@@@');

        $this->assertCount(1, $result);
        $this->assertEquals('Fallback Movie', $result[0]['name']);
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

    public function testQueryReturnsItemsAndPagination(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['count' => 2]],
            [
                [
                    'id' => 'movie-1',
                    'name' => 'Test Movie',
                    'type' => 'movie',
                    'library_id' => 'lib-1',
                    'path' => '/movies/test.mkv',
                    'metadata_json' => '{"year": 2020, "poster_url": "http://example.com/poster.jpg"}',
                ],
                [
                    'id' => 'movie-2',
                    'name' => 'Another Movie',
                    'type' => 'movie',
                    'library_id' => 'lib-1',
                    'path' => '/movies/another.mkv',
                    'metadata_json' => '{"year": 2021, "poster_url": "http://example.com/poster2.jpg"}',
                ],
            ]
        );

        $repo = new ItemRepository($db);
        $result = $repo->query(['limit' => 50, 'offset' => 0]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('limit', $result);
        $this->assertArrayHasKey('offset', $result);
        $this->assertEquals(2, $result['total']);
        $this->assertEquals(50, $result['limit']);
        $this->assertEquals(0, $result['offset']);
        $this->assertCount(2, $result['items']);
    }

    public function testQueryWithLibraryIdFiltersCorrectly(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('query')
            ->with(
                $this->stringContains('library_id = ?'),
                $this->callback(function ($params) {
                    return is_array($params) && in_array('lib-specific', $params, true);
                })
            )
            ->willReturnOnConsecutiveCalls([['count' => 0]], []);

        $repo = new ItemRepository($db);
        $repo->query(['limit' => 50], 'lib-specific');
    }

    public function testQueryWithParentIdScopesToChildren(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('query')
            ->with(
                $this->stringContains('parent_id = ?'),
                $this->callback(function ($params) {
                    return is_array($params) && in_array('series-7', $params, true);
                })
            )
            ->willReturnOnConsecutiveCalls([['count' => 0]], []);

        $repo = new ItemRepository($db);
        $repo->query(['parentId' => 'series-7']);
    }

    public function testQueryWithTopLevelExcludesChildren(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('query')
            ->with($this->stringContains('parent_id IS NULL'))
            ->willReturnOnConsecutiveCalls([['count' => 0]], []);

        $repo = new ItemRepository($db);
        $repo->query(['topLevel' => true]);
    }

    public function testQueryParentIdWinsOverTopLevel(): void
    {
        $db = $this->createMock(Connection::class);
        // parentId and topLevel are mutually exclusive — parentId wins, so the
        // WHERE must use `parent_id = ?` and NOT the top-level `IS NULL` clause.
        $db->expects($this->exactly(2))
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                return str_contains($sql, 'parent_id = ?') && !str_contains($sql, 'parent_id IS NULL');
            }))
            ->willReturnOnConsecutiveCalls([['count' => 0]], []);

        $repo = new ItemRepository($db);
        $repo->query(['parentId' => 'series-7', 'topLevel' => true]);
    }

    public function testQueryTopLevelIgnoredWhenSearching(): void
    {
        $db = $this->createMock(Connection::class);
        // An active search must span the whole library, so `topLevel` is dropped
        // (no `parent_id IS NULL` clause) when a search term is present.
        $db->expects($this->exactly(2))
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                return !str_contains($sql, 'parent_id IS NULL');
            }))
            ->willReturnOnConsecutiveCalls([['count' => 0]], []);

        $repo = new ItemRepository($db);
        $repo->query(['topLevel' => true, 'search' => 'batman']);
    }

    public function testQueryWithSearchAppliesFullTextOrLike(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('query')
            ->with(
                $this->stringContains('MATCH(name) AGAINST(? IN BOOLEAN MODE) OR name LIKE ?')
            )
            ->willReturnOnConsecutiveCalls([['count' => 1]], [['id' => 'movie-1', 'name' => 'Batman', 'type' => 'movie', 'library_id' => 'lib-1', 'path' => '/m/batman.mkv', 'metadata_json' => '{}']]);

        $repo = new ItemRepository($db);
        $result = $repo->query(['search' => 'batman']);

        $this->assertCount(1, $result['items']);
        $this->assertEquals('Batman', $result['items'][0]['name']);
    }

    public function testQueryWithYearRangeFiltersCorrectly(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('query')
            ->with(
                $this->stringContains('CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, "$.year")) AS SIGNED) >= ?'),
                $this->callback(function ($params) {
                    return is_array($params) && in_array(2010, $params, true) && in_array(2020, $params, true);
                })
            )
            ->willReturnOnConsecutiveCalls([['count' => 0]], []);

        $repo = new ItemRepository($db);
        $repo->query(['yearFrom' => 2010, 'yearTo' => 2020]);
    }

    public function testQueryWithRatingsFilterAppliesCorrectly(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls([['count' => 0]], []);

        $repo = new ItemRepository($db);
        $result = $repo->query(['ratings' => ['PG', 'R']]);

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['total']);
    }

    public function testQueryWithGenresFilterAppliesCorrectly(): void
    {
        $db = $this->createMock(Connection::class);
        // The genre containment MUST be scoped to the '$.genres' path — a path-less
        // JSON_CONTAINS tests the whole document and matches nothing.
        $db->expects($this->exactly(2))
            ->method('query')
            ->with(
                $this->stringContains("JSON_CONTAINS(metadata_json, ?, '\$.genres') > 0")
            )
            ->willReturnOnConsecutiveCalls([['count' => 0]], []);

        $repo = new ItemRepository($db);
        $repo->query(['genres' => ['Action', 'Drama']]);
    }

    public function testQueryWithActorsFilterAppliesCorrectly(): void
    {
        $db = $this->createMock(Connection::class);
        // Actor matching uses JSON_SEARCH scoped to each '$.actors[*]' element so a LIKE
        // can't span the serialized "," boundary between two names.
        $db->expects($this->exactly(2))
            ->method('query')
            ->with(
                $this->stringContains("JSON_SEARCH(metadata_json, 'one', ?, NULL, '\$.actors[*]') IS NOT NULL")
            )
            ->willReturnOnConsecutiveCalls([['count' => 0]], []);

        $repo = new ItemRepository($db);
        $repo->query(['actors' => ['Tom Hanks', 'Morgan Freeman']]);
    }

    public function testQueryNormalizesLimit(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['count' => 0]]);

        $repo = new ItemRepository($db);
        $result = $repo->query(['limit' => 200]);

        $this->assertEquals(100, $result['limit']);
    }

    public function testQueryNormalizesOffset(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['count' => 0]]);

        $repo = new ItemRepository($db);
        $result = $repo->query(['offset' => -5]);

        $this->assertEquals(0, $result['offset']);
    }

    public function testQueryHydratesMetadata(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['count' => 1]],
            [[
                'id' => 'movie-1',
                'name' => 'Test Movie',
                'type' => 'movie',
                'library_id' => 'lib-1',
                'path' => '/movies/test.mkv',
                'metadata_json' => '{"year": 2020, "poster_url": "http://example.com/poster.jpg", "genres": ["Action"], "rating": "PG-13"}',
            ]]
        );

        $repo = new ItemRepository($db);
        $result = $repo->query(['limit' => 50]);

        $this->assertEquals(['year' => 2020, 'poster_url' => 'http://example.com/poster.jpg', 'genres' => ['Action'], 'rating' => 'PG-13'], $result['items'][0]['metadata']);
    }
}