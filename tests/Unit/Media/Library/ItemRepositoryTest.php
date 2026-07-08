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

    public function testFindTopLevelByCanonicalReturnsNullForEmptyKeyWithoutQuerying(): void
    {
        // An empty canonical key is never a meaningful match, so the method must
        // short-circuit and NOT hit the database at all (avoids collapsing every
        // unkeyable row together).
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $repo = new ItemRepository($db);
        $this->assertNull($repo->findTopLevelByCanonical('lib-1', 'series', ''));
    }

    public function testFindTopLevelByCanonicalScopesQueryToLibraryTypeParentNullAndKey(): void
    {
        $capturedSql = null;
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function ($sql) use (&$capturedSql): bool {
                    $capturedSql = $sql;
                    return true;
                }),
                $this->callback(function ($params) use (&$capturedParams): bool {
                    $capturedParams = $params;
                    return true;
                })
            )
            ->willReturn([
                [
                    'id' => 'series-1',
                    'name' => 'Hunter x Hunter',
                    'type' => 'series',
                    'library_id' => 'lib-1',
                    'parent_id' => null,
                    'path' => 'series:lib-1:hunter-x-hunter',
                    'metadata_json' => '{"canonical_key": "hunterxhunter"}',
                ],
            ]);

        $repo = new ItemRepository($db);
        $result = $repo->findTopLevelByCanonical('lib-1', 'series', 'hunterxhunter');

        $this->assertIsArray($result);
        $this->assertSame('series-1', $result['id']);
        $this->assertSame(['canonical_key' => 'hunterxhunter'], $result['metadata']);

        // Scoped correctly + parameterised with colon-free positional placeholders.
        // Since migration 043 the match reads the INDEXED `canonical_key` column
        // (the source of truth) directly — NOT a JSON_EXTRACT predicate (which
        // could never use the (library_id, type, canonical_key) index).
        $this->assertIsString($capturedSql);
        $this->assertStringContainsString('parent_id IS NULL', $capturedSql);
        $this->assertStringContainsString('library_id = ?', $capturedSql);
        $this->assertStringContainsString('type = ?', $capturedSql);
        $this->assertStringContainsString('canonical_key = ?', $capturedSql);
        $this->assertStringNotContainsString(
            'JSON_EXTRACT(metadata_json',
            $capturedSql,
            'must match the indexed column, not the JSON blob',
        );
        $this->assertStringNotContainsString(':', $capturedSql, 'placeholders must be colon-free');
        $this->assertSame(['lib-1', 'series', 'hunterxhunter'], $capturedParams);
    }

    public function testFindTopLevelByCanonicalReturnsNullWhenNoMatch(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new ItemRepository($db);
        $this->assertNull($repo->findTopLevelByCanonical('lib-1', 'movie', 'nomatch:2020'));
    }

    public function testGetTopLevelByLibraryPagesParentlessRowsScopedAndOrdered(): void
    {
        // Real getTopLevelByLibrary() body: scoped to library + parent_id IS NULL,
        // stable id-ASC order (so paging never skips/repeats), colon-free
        // positional placeholders, LIMIT ? OFFSET ?, hydrated rows.
        $capturedSql = null;
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function ($sql) use (&$capturedSql): bool {
                    $capturedSql = $sql;
                    return true;
                }),
                $this->callback(function ($params) use (&$capturedParams): bool {
                    $capturedParams = $params;
                    return true;
                })
            )
            ->willReturn([
                [
                    'id' => 'series-a',
                    'name' => 'Alpha',
                    'type' => 'series',
                    'library_id' => 'lib-1',
                    'parent_id' => null,
                    'metadata_json' => '{"canonical_key": "alpha"}',
                ],
                [
                    'id' => 'series-b',
                    'name' => 'Bravo',
                    'type' => 'series',
                    'library_id' => 'lib-1',
                    'parent_id' => null,
                    'metadata_json' => '{"year": 2011}',
                ],
            ]);

        $repo = new ItemRepository($db);
        $rows = $repo->getTopLevelByLibrary('lib-1', 2, 4);

        // Hydrated: each row carries a decoded 'metadata' array.
        $this->assertCount(2, $rows);
        $this->assertSame('series-a', $rows[0]['id']);
        $this->assertSame(['canonical_key' => 'alpha'], $rows[0]['metadata']);
        $this->assertSame(['year' => 2011], $rows[1]['metadata']);

        // SQL contract: scoped, ordered, paged, colon-free positional placeholders.
        $this->assertIsString($capturedSql);
        $this->assertStringContainsString('library_id = ?', $capturedSql);
        $this->assertStringContainsString('parent_id IS NULL', $capturedSql);
        $this->assertStringContainsString('ORDER BY id ASC', $capturedSql);
        $this->assertStringContainsString('LIMIT ? OFFSET ?', $capturedSql);
        $this->assertStringNotContainsString(':', $capturedSql, 'placeholders must be colon-free');
        $this->assertSame(['lib-1', 2, 4], $capturedParams);
    }

    public function testGetTopLevelByLibraryReturnsEmptyPastTheEnd(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new ItemRepository($db);
        $this->assertSame([], $repo->getTopLevelByLibrary('lib-1', 500, 1000));
    }

    public function testCountDescendantsUsesRecursiveCteScopedToTheSubtree(): void
    {
        // Real countDescendants() body: a single WITH RECURSIVE walk over
        // parent_id (anchor = direct children of $itemId, recursive = deeper
        // levels), counted in one query, colon-free positional placeholder.
        $capturedSql = null;
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function ($sql) use (&$capturedSql): bool {
                    $capturedSql = $sql;
                    return true;
                }),
                $this->callback(function ($params) use (&$capturedParams): bool {
                    $capturedParams = $params;
                    return true;
                })
            )
            ->willReturn([['count' => 100]]);

        $repo = new ItemRepository($db);
        $count = $repo->countDescendants('series-big');

        $this->assertSame(100, $count);

        $this->assertIsString($capturedSql);

        // OUTAGE GUARD: the statement MUST start with `SELECT`, NOT `WITH`. The
        // workerman/mysql driver decides whether to FETCH a result set from the
        // leading keyword; a `WITH RECURSIVE ...` statement is not recognised as
        // row-returning so `query()` returned NULL → countDescendants() reported
        // 0 for every item against a live DB. Wrapping the CTE in an outer SELECT
        // makes the driver fetch the rows. Pin the leading keyword so it can't
        // regress (CI has no live MySQL to catch it otherwise).
        $trimmedSql = ltrim($capturedSql);
        $this->assertStringStartsWith('SELECT', $trimmedSql, 'statement must start with SELECT so the driver fetches rows');
        $this->assertStringStartsNotWith('WITH', $trimmedSql, 'a leading WITH is not recognised as row-returning by the driver');

        // Still the same arbitrary-depth recursive walk, just wrapped: the CTE
        // body, anchor, recursion join and count column must all survive.
        $this->assertStringContainsString('WITH RECURSIVE descendants AS', $capturedSql);
        $this->assertStringContainsString('SELECT id FROM media_items WHERE parent_id = ?', $capturedSql);
        $this->assertStringContainsString('UNION ALL', $capturedSql);
        $this->assertStringContainsString('JOIN descendants d ON mi.parent_id = d.id', $capturedSql);
        $this->assertStringContainsString('SELECT COUNT(*) AS count FROM (', $capturedSql);

        // Exactly one colon-free positional placeholder.
        $this->assertStringNotContainsString(':', $capturedSql, 'placeholders must be colon-free');
        $this->assertSame(1, substr_count($capturedSql, '?'), 'exactly one positional placeholder');
        $this->assertSame(['series-big'], $capturedParams);
    }

    public function testCountDescendantsReturnsZeroForALeaf(): void
    {
        // A movie/leaf has no children → the CTE yields a 0 count (and a
        // numeric-string count from the driver is tolerated as int 0).
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['count' => '0']]);

        $repo = new ItemRepository($db);
        $this->assertSame(0, $repo->countDescendants('movie-1'));
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
                    // id, library_id, parent_id, name, type, path, canonical_key,
                    // sort_title, content_rating, metadata_json
                    return count($params) === 10
                        && $params[1] === 'lib-1'
                        && $params[3] === 'Test Movie'
                        && $params[4] === 'movie'
                        && $params[5] === '/movies/test.mkv'
                        && $params[6] === null   // no canonical_key in metadata → column NULL
                        && $params[7] === 'Test Movie' // sort_title (no leading article)
                        && $params[8] === null;  // no rating in metadata → column NULL
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

    public function testCreateWritesCanonicalKeyColumnFromMetadataArray(): void
    {
        // Migration 043: the scanner stamps metadata_json.canonical_key (Step 1.2);
        // create() must COPY it into the indexed `canonical_key` column (source of
        // truth) without disturbing the blob.
        $capturedSql = null;
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function ($sql) use (&$capturedSql): bool {
                    $capturedSql = $sql;
                    return true;
                }),
                $this->callback(function ($params) use (&$capturedParams): bool {
                    $capturedParams = $params;
                    return true;
                })
            );

        $repo = new ItemRepository($db);
        $repo->create([
            'library_id' => 'lib-1',
            'name' => 'Hunter x Hunter',
            'type' => 'series',
            'path' => 'series:lib-1:hunter-x-hunter',
            'metadata_json' => ['canonical_key' => 'hunterxhunter', 'name' => 'Hunter x Hunter'],
        ]);

        $this->assertIsString($capturedSql);
        $this->assertStringContainsString('canonical_key', $capturedSql);
        $this->assertIsArray($capturedParams);
        // Bound order (migration 050): id, library_id, parent_id, name, type,
        // path, canonical_key(6), sort_title(7), content_rating(8), blob(9).
        $this->assertSame('hunterxhunter', $capturedParams[6]);
        $this->assertSame('Hunter x Hunter', $capturedParams[7]); // sort_title (no leading article)
        $this->assertIsString($capturedParams[9]);
        $this->assertStringContainsString('hunterxhunter', $capturedParams[9]); // blob still carries it
    }

    public function testCreateDerivesCanonicalKeyColumnFromRawJsonStringMetadata(): void
    {
        // metadata_json may arrive as a pre-encoded JSON string (legacy callers).
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->anything(),
                $this->callback(function ($params) use (&$capturedParams): bool {
                    $capturedParams = $params;
                    return true;
                })
            );

        $repo = new ItemRepository($db);
        $repo->create([
            'library_id' => 'lib-1',
            'name' => 'Mad Max Fury Road',
            'type' => 'movie',
            'path' => '/movies/madmax.mkv',
            'metadata_json' => '{"canonical_key":"madmaxfuryroad:2015","year":2015}',
        ]);

        $this->assertIsArray($capturedParams);
        $this->assertSame('madmaxfuryroad:2015', $capturedParams[6]);
    }

    public function testCreateLeavesCanonicalKeyColumnNullForBlankOrMissingKey(): void
    {
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->anything(),
                $this->callback(function ($params) use (&$capturedParams): bool {
                    $capturedParams = $params;
                    return true;
                })
            );

        $repo = new ItemRepository($db);
        $repo->create([
            'library_id' => 'lib-1',
            'name' => 'No Key',
            'type' => 'movie',
            'path' => '/movies/nokey.mkv',
            'metadata_json' => ['canonical_key' => '   ', 'year' => 2000],
        ]);

        $this->assertIsArray($capturedParams);
        $this->assertNull($capturedParams[6]);
    }

    public function testUpdateModifiesItem(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE media_items SET'),
                $this->callback(function ($params) {
                    // A name update also materializes sort_title (migration 050):
                    // SET sort_title = ?, name = ? WHERE id = ?
                    return $params[0] === 'New Name' // sort_title (no leading article)
                        && $params[1] === 'New Name' // name
                        && $params[2] === 'test-id';
                })
            );

        $repo = new ItemRepository($db);
        $repo->update('test-id', ['name' => 'New Name']);
    }

    public function testUpdateSyncsCanonicalKeyColumnWhenMetadataJsonChanges(): void
    {
        // A metadata_json (re)write must keep the indexed canonical_key column in
        // lockstep so findTopLevelByCanonical() never sees a stale column.
        $capturedSql = null;
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function ($sql) use (&$capturedSql): bool {
                    $capturedSql = $sql;
                    return true;
                }),
                $this->callback(function ($params) use (&$capturedParams): bool {
                    $capturedParams = $params;
                    return true;
                })
            );

        $repo = new ItemRepository($db);
        $repo->update('series-1', [
            'metadata_json' => ['canonical_key' => 'hunterxhunter:2011', 'year' => 2011],
        ]);

        $this->assertIsString($capturedSql);
        $this->assertStringContainsString('canonical_key = ?', $capturedSql);
        $this->assertStringContainsString('content_rating = ?', $capturedSql);
        $this->assertStringContainsString('metadata_json = ?', $capturedSql);
        $this->assertIsArray($capturedParams);
        // A metadata_json rewrite also syncs content_rating (migration 050):
        // SET canonical_key = ?, content_rating = ?, metadata_json = ? WHERE id = ?
        $this->assertSame('hunterxhunter:2011', $capturedParams[0]);
        $this->assertNull($capturedParams[1]); // no rating in this metadata
        $this->assertIsString($capturedParams[2]);
        $this->assertSame('series-1', $capturedParams[3]);
    }

    public function testUpdateSyncsCanonicalKeyColumnFromRawJsonStringMetadata(): void
    {
        // metadata_json may arrive as a pre-encoded JSON STRING (the create()
        // path already pins this; pin the update() lockstep too): the indexed
        // column is derived from the decoded string while the string blob is
        // passed through to `metadata_json = ?` verbatim (not double-encoded).
        $capturedSql = null;
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function ($sql) use (&$capturedSql): bool {
                    $capturedSql = $sql;
                    return true;
                }),
                $this->callback(function ($params) use (&$capturedParams): bool {
                    $capturedParams = $params;
                    return true;
                })
            );

        $repo = new ItemRepository($db);
        $repo->update('movie-1', [
            'metadata_json' => '{"canonical_key":"madmaxfuryroad:2015","year":2015}',
        ]);

        $this->assertIsString($capturedSql);
        $this->assertStringContainsString('canonical_key = ?', $capturedSql);
        $this->assertStringContainsString('content_rating = ?', $capturedSql);
        $this->assertStringContainsString('metadata_json = ?', $capturedSql);
        $this->assertIsArray($capturedParams);
        // SET canonical_key = ?, content_rating = ?, metadata_json = ? WHERE id = ?
        $this->assertSame('madmaxfuryroad:2015', $capturedParams[0]);
        $this->assertNull($capturedParams[1]); // no rating in this metadata
        // The raw string blob is passed through unchanged (not array-encoded).
        $this->assertSame('{"canonical_key":"madmaxfuryroad:2015","year":2015}', $capturedParams[2]);
        $this->assertSame('movie-1', $capturedParams[3]);
    }

    public function testUpdateClearsCanonicalKeyColumnWhenMetadataLosesKey(): void
    {
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->anything(),
                $this->callback(function ($params) use (&$capturedParams): bool {
                    $capturedParams = $params;
                    return true;
                })
            );

        $repo = new ItemRepository($db);
        $repo->update('series-1', [
            'metadata_json' => ['year' => 2011], // no canonical_key → column NULLed
        ]);

        // SET canonical_key = ?, content_rating = ?, metadata_json = ? WHERE id = ?
        $this->assertIsArray($capturedParams);
        $this->assertNull($capturedParams[0]);          // canonical_key nulled
        $this->assertNull($capturedParams[1]);          // content_rating nulled (no rating)
        $this->assertSame('series-1', $capturedParams[3]);
    }

    public function testCreateMaterializesStrippedSortTitleAndContentRating(): void
    {
        // The headline S7 write-path behavior: create() must persist the
        // ARTICLE-STRIPPED sort key (not the raw display name) into the indexed
        // sort_title column, and the extracted rating into content_rating — while
        // leaving the DISPLAY name intact. The other create() tests use
        // article-free, rating-free inputs where SortTitle::from() is the identity
        // and extractContentRating() is null, so neither transform is actually
        // proven on the write path without this case (a regression that dropped
        // the transform — writing $scrubbedName / null — would pass them all).
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO media_items'),
                $this->callback(function ($params) use (&$capturedParams): bool {
                    $capturedParams = $params;
                    return true;
                })
            );

        $repo = new ItemRepository($db);
        $repo->create([
            'library_id' => 'lib-1',
            'name' => 'The Matrix',
            'type' => 'movie',
            'path' => '/movies/the-matrix.mkv',
            'metadata_json' => ['rating' => 'R', 'year' => 1999],
        ]);

        $this->assertIsArray($capturedParams);
        // Bound order: id, library_id, parent_id, name(3), type, path,
        // canonical_key(6), sort_title(7), content_rating(8), blob(9).
        $this->assertSame('The Matrix', $capturedParams[3]); // display name preserved
        $this->assertSame('Matrix', $capturedParams[7]);     // sort_title = article-STRIPPED
        $this->assertSame('R', $capturedParams[8]);          // content_rating materialized
    }

    public function testUpdateMaterializesStrippedSortTitleOnArticleLeadingNameChange(): void
    {
        // A name change to an article-leading title must persist the STRIPPED key
        // into sort_title while the display name keeps its article. Guards against
        // a regression that wrote the raw name into sort_title (the existing
        // name-change test uses "New Name", where SortTitle::from() is identity).
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE media_items SET'),
                $this->callback(function ($params) use (&$capturedParams): bool {
                    $capturedParams = $params;
                    return true;
                })
            );

        $repo = new ItemRepository($db);
        $repo->update('movie-1', ['name' => 'The Godfather']);

        // SET sort_title = ?, name = ? WHERE id = ?
        $this->assertIsArray($capturedParams);
        $this->assertSame('Godfather', $capturedParams[0]);    // sort_title = STRIPPED
        $this->assertSame('The Godfather', $capturedParams[1]); // display name preserved
        $this->assertSame('movie-1', $capturedParams[2]);
    }

    public function testUpdateMaterializesContentRatingFromMetadataRating(): void
    {
        // A metadata_json rewrite carrying a real rating must populate the indexed
        // content_rating column with that value. The other update tests only cover
        // rating-less metadata (content_rating → NULL), so a regression that always
        // wrote NULL to content_rating would pass them; this proves the live value.
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('content_rating = ?'),
                $this->callback(function ($params) use (&$capturedParams): bool {
                    $capturedParams = $params;
                    return true;
                })
            );

        $repo = new ItemRepository($db);
        $repo->update('movie-1', [
            'metadata_json' => ['rating' => 'PG-13', 'year' => 1994],
        ]);

        // SET canonical_key = ?, content_rating = ?, metadata_json = ? WHERE id = ?
        $this->assertIsArray($capturedParams);
        $this->assertNull($capturedParams[0]);          // no canonical_key in metadata
        $this->assertSame('PG-13', $capturedParams[1]); // content_rating materialized
        $this->assertSame('movie-1', $capturedParams[3]);
    }

    // -----------------------------------------------------------------------
    // extractContentRating() — the shared helper that materializes the indexed
    // content_rating column from metadata_json (migration 050). Must exactly
    // reproduce the old `JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.rating'))`
    // read: a STRING rating when present, NULL for every other shape, whether
    // the blob arrives already-decoded (scanner path) or as a raw JSON string
    // (CLI/backfill path). No mock needed — it is a pure static function.
    // -----------------------------------------------------------------------

    public function testExtractContentRatingReturnsStringRatingFromArray(): void
    {
        $this->assertSame('R', ItemRepository::extractContentRating(['rating' => 'R', 'year' => 1999]));
    }

    public function testExtractContentRatingPreservesComplexCertificateLabels(): void
    {
        // VARCHAR(32) certificate labels pass through verbatim, unquoted.
        $this->assertSame('TV-Y7-FV', ItemRepository::extractContentRating(['rating' => 'TV-Y7-FV']));
        $this->assertSame('NC-17', ItemRepository::extractContentRating(['rating' => 'NC-17']));
    }

    public function testExtractContentRatingReturnsNullWhenRatingKeyAbsent(): void
    {
        $this->assertNull(ItemRepository::extractContentRating(['year' => 2020, 'genres' => ['Drama']]));
    }

    public function testExtractContentRatingReturnsNullForEmptyArray(): void
    {
        $this->assertNull(ItemRepository::extractContentRating([]));
    }

    public function testExtractContentRatingReturnsNullWhenRatingIsExplicitNull(): void
    {
        $this->assertNull(ItemRepository::extractContentRating(['rating' => null]));
    }

    /**
     * A non-string rating (array/int/float/bool) is NOT materialized — the
     * column stays NULL, exactly as JSON_UNQUOTE(JSON_EXTRACT()) yielded no
     * usable scalar string for these shapes.
     *
     * @dataProvider malformedRatingShapes
     */
    public function testExtractContentRatingReturnsNullForMalformedRatingShapes(mixed $rating): void
    {
        $this->assertNull(ItemRepository::extractContentRating(['rating' => $rating]));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedRatingShapes(): array
    {
        return [
            'array rating'  => [['R', 'PG']],
            'object rating' => [['label' => 'R']],
            'int rating'    => [13],
            'float rating'  => [1.5],
            'bool rating'   => [true],
        ];
    }

    public function testExtractContentRatingDecodesRawJsonStringBlob(): void
    {
        // The CLI/backfill path can hand it the raw JSON string.
        $this->assertSame('PG-13', ItemRepository::extractContentRating('{"rating":"PG-13","year":2012}'));
    }

    public function testExtractContentRatingReturnsNullForJsonStringWithoutRating(): void
    {
        $this->assertNull(ItemRepository::extractContentRating('{"year":2012}'));
    }

    public function testExtractContentRatingReturnsNullForInvalidJsonString(): void
    {
        $this->assertNull(ItemRepository::extractContentRating('not-json-at-all'));
        $this->assertNull(ItemRepository::extractContentRating('{"rating":'));
    }

    public function testExtractContentRatingReturnsNullForNonArrayNonStringInput(): void
    {
        $this->assertNull(ItemRepository::extractContentRating(null));
        $this->assertNull(ItemRepository::extractContentRating(42));
        $this->assertNull(ItemRepository::extractContentRating(true));
    }

    public function testExtractContentRatingReturnsNullWhenJsonStringDecodesToScalar(): void
    {
        // A JSON string that decodes to a non-array (e.g. a bare string/number)
        // is not a metadata blob → NULL.
        $this->assertNull(ItemRepository::extractContentRating('"R"'));
        $this->assertNull(ItemRepository::extractContentRating('123'));
    }

    public function testUpdateDoesNotTouchCanonicalKeyColumnWhenMetadataJsonAbsent(): void
    {
        // A non-metadata update (e.g. re-parenting) must NOT clobber the column.
        $capturedSql = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function ($sql) use (&$capturedSql): bool {
                    $capturedSql = $sql;
                    return true;
                }),
                $this->anything()
            );

        $repo = new ItemRepository($db);
        $repo->update('episode-1', ['parent_id' => 'season-1']);

        $this->assertIsString($capturedSql);
        $this->assertStringNotContainsString('canonical_key', $capturedSql);
    }

    public function testUpdateHonorsExplicitCanonicalKeyOverDerivedMetadataValue(): void
    {
        // If a caller passes canonical_key explicitly AND a metadata_json, the
        // explicit column value must win (no double-set of the column).
        $capturedSql = null;
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function ($sql) use (&$capturedSql): bool {
                    $capturedSql = $sql;
                    return true;
                }),
                $this->callback(function ($params) use (&$capturedParams): bool {
                    $capturedParams = $params;
                    return true;
                })
            );

        $repo = new ItemRepository($db);
        $repo->update('series-1', [
            'metadata_json' => ['canonical_key' => 'from-blob'],
            'canonical_key' => 'explicit-wins',
        ]);

        $this->assertIsString($capturedSql);
        // canonical_key set exactly once (the explicit one), not duplicated.
        $this->assertSame(1, substr_count($capturedSql, 'canonical_key = ?'));
        $this->assertIsArray($capturedParams);
        $this->assertContains('explicit-wins', $capturedParams);
        $this->assertNotContains('from-blob', $capturedParams);
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

    public function testDeleteStreamsByItemDeletesAllStreamsForItem(): void
    {
        // Idempotent stream replace (step A1): the scanner clears an item's
        // existing media_streams rows before re-inserting a fresh probe so a
        // rescan never duplicates rows.
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM media_streams WHERE media_item_id = ?'),
                ['movie-1']
            );

        $repo = new ItemRepository($db);
        $repo->deleteStreamsByItem('movie-1');
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

    public function testQueryWithMatchedFiltersOnMetadataRefreshedNotNull(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('query')
            ->with($this->stringContains('metadata_refreshed_at IS NOT NULL'))
            ->willReturnOnConsecutiveCalls([['count' => 0]], []);

        $repo = new ItemRepository($db);
        $repo->query(['match' => 'matched']);
    }

    public function testQueryWithUnmatchedFiltersOnMetadataRefreshedIsNull(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                return str_contains($sql, 'metadata_refreshed_at IS NULL')
                    && !str_contains($sql, 'metadata_refreshed_at IS NOT NULL');
            }))
            ->willReturnOnConsecutiveCalls([['count' => 0]], []);

        $repo = new ItemRepository($db);
        $repo->query(['match' => 'unmatched']);
    }

    public function testQueryWithCompaniesFiltersOnProductionCompaniesOrStudio(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('query')
            ->with(
                $this->callback(function (string $sql): bool {
                    // Matches the rich production_companies[*].name array OR the
                    // legacy single studio string.
                    return str_contains($sql, "'\$.production_companies[*].name'")
                        && str_contains($sql, "JSON_EXTRACT(metadata_json, '\$.studio')) = ?");
                }),
                $this->callback(function ($params): bool {
                    // LIKE wildcard binding for JSON_SEARCH + exact binding for the
                    // studio comparison, both for "Warner Bros.".
                    return is_array($params)
                        && in_array('%Warner Bros.%', $params, true)
                        && in_array('Warner Bros.', $params, true);
                }),
            )
            ->willReturnOnConsecutiveCalls([['count' => 0]], []);

        $repo = new ItemRepository($db);
        $repo->query(['companies' => ['Warner Bros.']]);
    }

    public function testQueryWithMultipleCompaniesCombinesAsOr(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                // Two company clauses OR-ed together (any-match), each itself an
                // (array-name OR studio) pair.
                return substr_count($sql, "'\$.production_companies[*].name'") === 2;
            }))
            ->willReturnOnConsecutiveCalls([['count' => 0]], []);

        $repo = new ItemRepository($db);
        $repo->query(['companies' => ['Warner Bros.', 'FOX']]);
    }

    public function testLetterCountsGroupsByFirstLetterAndShapesRows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                // Buckets by the first letter of the materialized sort key
                // column (so "The Plot" counts under P), not the raw first char.
                return str_contains($sql, 'UPPER(LEFT(sort_title, 1))')
                    && str_contains($sql, 'AS letter')
                    && str_contains($sql, 'GROUP BY letter');
            }))
            ->willReturn([
                ['letter' => 'A', 'n' => 12],
                ['letter' => 'B', 'n' => '5'], // numeric-string count tolerated
                ['letter' => '', 'n' => 3],    // empty sort key → folded to '#', not dropped
            ]);

        $repo = new ItemRepository($db);
        $this->assertSame(
            [
                ['letter' => 'A', 'count' => 12],
                ['letter' => 'B', 'count' => 5],
                ['letter' => '#', 'count' => 3],
            ],
            $repo->letterCounts(['topLevel' => true], 'lib-1'),
        );
    }

    public function testLetterCountsAppliesTheSameFiltersAsQuery(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                return str_contains($sql, 'library_id = ?')
                    && str_contains($sql, 'parent_id IS NULL')
                    && str_contains($sql, 'metadata_refreshed_at IS NULL');
            }))
            ->willReturn([]);

        $repo = new ItemRepository($db);
        $repo->letterCounts(['topLevel' => true, 'match' => 'unmatched'], 'lib-7');
    }

    public function testQueryDefaultNameSortIgnoresLeadingArticle(): void
    {
        $db = $this->createMock(Connection::class);
        // count() then the paged SELECT share the matcher; only the SELECT carries
        // ORDER BY. The default name sort files "The Plot" under P (article-stripped
        // key first) then falls back to the raw name as a stable tiebreak.
        $db->expects($this->exactly(2))
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                if (str_contains($sql, 'COUNT(*)')) {
                    return true; // the count query has no ORDER BY
                }
                // Materialized sort_title column first (files "The Plot" under P),
                // then the raw name as a stable tiebreak.
                return str_contains($sql, 'ORDER BY sort_title ASC, name ASC');
            }))
            ->willReturnOnConsecutiveCalls([['count' => 0]], []);

        $repo = new ItemRepository($db);
        $repo->query([]); // defaults: sort=name, order=asc
    }

    public function testQueryNameSortDescAppliesDescToBothKeys(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                if (str_contains($sql, 'COUNT(*)')) {
                    return true;
                }
                return str_contains($sql, 'ORDER BY sort_title DESC, name DESC');
            }))
            ->willReturnOnConsecutiveCalls([['count' => 0]], []);

        $repo = new ItemRepository($db);
        $repo->query(['sort' => 'name', 'order' => 'desc']);
    }

    public function testQueryYearSortKeepsArticleInsensitiveTiebreak(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                if (str_contains($sql, 'COUNT(*)')) {
                    return true;
                }
                // Year primary, then the materialized sort_title as the tiebreak.
                return str_contains($sql, "JSON_EXTRACT(metadata_json, '\$.year')")
                    && str_contains($sql, 'ASC, sort_title ASC, name ASC');
            }))
            ->willReturnOnConsecutiveCalls([['count' => 0]], []);

        $repo = new ItemRepository($db);
        $repo->query(['sort' => 'year']);
    }

    public function testQueryRatingSortOrdersByMaterializedContentRatingColumn(): void
    {
        // Browse "sort by rating" (?sort=rating → rating_sort) must build its
        // restriction-rank CASE off the materialized `content_rating` column
        // (migration 050), NOT the old JSON_EXTRACT(metadata_json,'$.rating') blob
        // read. This is a live query() ORDER-BY path S7 rewrote; without this case
        // the branch is uncovered and a regression to the JSON read would pass.
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                if (str_contains($sql, 'COUNT(*)')) {
                    return true;
                }
                // Rank CASE reads the indexed column, article-insensitive tiebreak
                // follows, and the old JSON extraction is gone from the ORDER BY.
                return str_contains($sql, "WHEN content_rating = 'G' THEN")
                    && str_contains($sql, 'ELSE 999 END')
                    && str_contains($sql, ', sort_title ASC, name ASC')
                    && !str_contains($sql, "JSON_EXTRACT(metadata_json, '\$.rating')");
            }))
            ->willReturnOnConsecutiveCalls([['count' => 0]], []);

        $repo = new ItemRepository($db);
        $repo->query(['sort' => 'rating']);
    }

    public function testQueryDateAddedSortIsNotArticleStripped(): void
    {
        $db = $this->createMock(Connection::class);
        // `date_added` → created_at must keep its natural ordering: no
        // article-stripping CASE applied to a timestamp column.
        $db->expects($this->exactly(2))
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                if (str_contains($sql, 'COUNT(*)')) {
                    return true;
                }
                return str_contains($sql, 'ORDER BY created_at ASC')
                    && !str_contains($sql, 'TRIM(CASE');
            }))
            ->willReturnOnConsecutiveCalls([['count' => 0]], []);

        $repo = new ItemRepository($db);
        $repo->query(['sort' => 'date_added']);
    }

    public function testGetByLibraryOrdersByArticleStrippedTitle(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                return str_contains($sql, 'ORDER BY sort_title ASC, name ASC')
                    && str_contains($sql, 'LIMIT ? OFFSET ?');
            }))
            ->willReturn([]);

        $repo = new ItemRepository($db);
        $repo->getByLibrary('lib-1');
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
        // Genre filtering is a membership test against the multi-valued index on
        // metadata_json.$.genres (migration 050): `? MEMBER OF (...)`, one per
        // requested genre, OR'd together.
        $db->expects($this->exactly(2))
            ->method('query')
            ->with(
                $this->stringContains("? MEMBER OF (metadata_json->'\$.genres')")
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

    public function testDistinctGenresUnnestsViaJsonTableAndShapesRows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function (string $sql): bool {
                    // Set-based unnest of $.genres via JSON_TABLE — no unbounded
                    // SELECT * scan into PHP — DISTINCT + ORDER done server-side.
                    return str_contains($sql, 'JSON_TABLE(')
                        && str_contains($sql, "'\$.genres[*]'")
                        && str_contains($sql, 'SELECT DISTINCT')
                        && str_contains($sql, 'ORDER BY g.genre ASC')
                        && !str_contains($sql, 'SELECT *');
                }),
                $this->callback(fn (array $bindings): bool => $bindings === []),
            )
            ->willReturn([
                ['genre' => 'Action'],
                ['genre' => 'Drama'],
            ]);

        $repo = new ItemRepository($db);
        $this->assertSame(['Action', 'Drama'], $repo->distinctGenres());
    }

    public function testDistinctGenresScopesToLibraryWhenProvided(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(fn (string $sql): bool => str_contains($sql, 'mi.library_id = ?')),
                $this->callback(fn (array $bindings): bool => $bindings === ['lib-A']),
            )
            ->willReturn([['genre' => 'Horror']]);

        $repo = new ItemRepository($db);
        $this->assertSame(['Horror'], $repo->distinctGenres('lib-A'));
    }

    public function testDistinctGenresReturnsEmptyArrayWhenNoGenres(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new ItemRepository($db);
        $this->assertSame([], $repo->distinctGenres('lib-empty'));
    }

    public function testDistinctGenresDropsNonStringAndBlankRows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['genre' => 'Comedy'],
            ['genre' => ''],     // blank → dropped
            ['genre' => null],   // null → dropped
            ['other' => 'x'],    // missing key → dropped
            ['genre' => 'Sci-Fi'],
        ]);

        $repo = new ItemRepository($db);
        $this->assertSame(['Comedy', 'Sci-Fi'], $repo->distinctGenres());
    }

    public function testDistinctGenresToleratesNonArrayDbResult(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(false);

        $repo = new ItemRepository($db);
        $this->assertSame([], $repo->distinctGenres());
    }

    // -------------------------------------------------------------------------
    // distinctGenres() in-worker TTL cache + invalidation (S5)
    // -------------------------------------------------------------------------

    /**
     * A repeat lookup for the same scope within the TTL is served from the
     * in-worker cache — the JSON_TABLE scan runs exactly once.
     */
    public function testDistinctGenresServesRepeatCallsFromCache(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturn([['genre' => 'Action'], ['genre' => 'Drama']]);

        $repo = new ItemRepository($db);

        $first = $repo->distinctGenres('lib-A');
        $second = $repo->distinctGenres('lib-A');
        $third = $repo->distinctGenres('lib-A');

        $this->assertSame(['Action', 'Drama'], $first);
        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
    }

    /**
     * Each scope (per-library and the unscoped/global set) is cached
     * independently, so distinct scopes each recompute once.
     */
    public function testDistinctGenresCachesEachScopeIndependently(): void
    {
        $jsonTableCalls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $bindings = []) use (&$jsonTableCalls): array {
                if (str_contains($sql, 'JSON_TABLE(')) {
                    $jsonTableCalls++;
                    return $bindings === ['lib-B'] ? [['genre' => 'Horror']] : [['genre' => 'Action']];
                }
                return [];
            }
        );

        $repo = new ItemRepository($db);

        $this->assertSame(['Action'], $repo->distinctGenres('lib-A'));
        $this->assertSame(['Horror'], $repo->distinctGenres('lib-B'));
        $this->assertSame(['Action'], $repo->distinctGenres()); // global scope
        // Repeats — all cache hits.
        $repo->distinctGenres('lib-A');
        $repo->distinctGenres('lib-B');
        $repo->distinctGenres();

        $this->assertSame(3, $jsonTableCalls, 'one JSON_TABLE scan per distinct scope, no repeats');
    }

    /**
     * The public invalidate hook drops the scope so the next call recomputes.
     */
    public function testInvalidateGenreFacetsForcesRecompute(): void
    {
        $jsonTableCalls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$jsonTableCalls): array {
                if (str_contains($sql, 'JSON_TABLE(')) {
                    $jsonTableCalls++;
                }
                return [['genre' => 'Action']];
            }
        );

        $repo = new ItemRepository($db);

        $repo->distinctGenres('lib-A');            // compute #1
        $repo->distinctGenres('lib-A');            // cached
        $repo->invalidateGenreFacets('lib-A');     // drop scope
        $repo->distinctGenres('lib-A');            // compute #2

        $this->assertSame(2, $jsonTableCalls);
    }

    /**
     * Invalidating a library scope also drops the all-libraries (global) scope,
     * since that set spans the library.
     */
    public function testInvalidateLibraryScopeAlsoDropsGlobalScope(): void
    {
        $jsonTableCalls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$jsonTableCalls): array {
                if (str_contains($sql, 'JSON_TABLE(')) {
                    $jsonTableCalls++;
                }
                return [['genre' => 'Action']];
            }
        );

        $repo = new ItemRepository($db);

        $repo->distinctGenres();                   // global compute #1
        $repo->distinctGenres();                   // cached
        $repo->invalidateGenreFacets('lib-A');     // library write → also flush global
        $repo->distinctGenres();                   // global compute #2

        $this->assertSame(2, $jsonTableCalls);
    }

    /**
     * A flush with no library (null) clears every cached scope.
     */
    public function testInvalidateGenreFacetsNullFlushesAllScopes(): void
    {
        $jsonTableCalls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$jsonTableCalls): array {
                if (str_contains($sql, 'JSON_TABLE(')) {
                    $jsonTableCalls++;
                }
                return [['genre' => 'Action']];
            }
        );

        $repo = new ItemRepository($db);

        $repo->distinctGenres('lib-A');            // compute
        $repo->distinctGenres('lib-B');            // compute
        $repo->invalidateGenreFacets(null);        // flush everything
        $repo->distinctGenres('lib-A');            // recompute
        $repo->distinctGenres('lib-B');            // recompute

        $this->assertSame(4, $jsonTableCalls);
    }

    /**
     * create() invalidates the affected library's facets (a new item can add a
     * genre), so the next lookup recomputes.
     */
    public function testCreateInvalidatesGenreFacetsForItsLibrary(): void
    {
        $jsonTableCalls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$jsonTableCalls): array {
                if (str_contains($sql, 'JSON_TABLE(')) {
                    $jsonTableCalls++;
                    return [['genre' => 'Action']];
                }
                return [];
            }
        );

        $repo = new ItemRepository($db);

        $repo->distinctGenres('lib-A');            // compute #1
        $repo->distinctGenres('lib-A');            // cached
        $repo->create([
            'library_id' => 'lib-A',
            'type' => 'movie',
            'name' => 'New Movie',
            'path' => '/media/new.mkv',
            'metadata_json' => ['genres' => ['Comedy']],
        ]);
        $repo->distinctGenres('lib-A');            // compute #2

        $this->assertSame(2, $jsonTableCalls);
    }

    /**
     * update() invalidates facets only when metadata_json (where genres live) is
     * written; a non-metadata update leaves the cache warm.
     */
    public function testUpdateInvalidatesGenreFacetsOnlyWhenMetadataChanges(): void
    {
        $jsonTableCalls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$jsonTableCalls): array {
                if (str_contains($sql, 'JSON_TABLE(')) {
                    $jsonTableCalls++;
                }
                return [['genre' => 'Action']];
            }
        );

        $repo = new ItemRepository($db);

        // Non-metadata update does NOT invalidate.
        $repo->distinctGenres('lib-A');            // compute #1
        $repo->update('item-1', ['name' => 'Renamed']);
        $repo->distinctGenres('lib-A');            // still cached → no recompute
        $this->assertSame(1, $jsonTableCalls);

        // A metadata_json rewrite flushes all scopes.
        $repo->update('item-1', ['metadata_json' => ['genres' => ['Sci-Fi']]]);
        $repo->distinctGenres('lib-A');            // compute #2
        $this->assertSame(2, $jsonTableCalls);
    }

    /**
     * delete() with no StatsCollector cannot resolve the owning library, so it
     * flushes all scopes to stay correct.
     */
    public function testDeleteInvalidatesGenreFacets(): void
    {
        $jsonTableCalls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$jsonTableCalls): array {
                if (str_contains($sql, 'JSON_TABLE(')) {
                    $jsonTableCalls++;
                }
                return [['genre' => 'Action']];
            }
        );

        $repo = new ItemRepository($db); // no StatsCollector

        $repo->distinctGenres('lib-A');            // compute #1
        $repo->distinctGenres('lib-A');            // cached
        $repo->delete('item-1');
        $repo->distinctGenres('lib-A');            // compute #2

        $this->assertSame(2, $jsonTableCalls);
    }

    /**
     * deleteByLibrary() empties a library's genre set, so its scope (and the
     * global scope) recompute afterward.
     */
    public function testDeleteByLibraryInvalidatesGenreFacets(): void
    {
        $jsonTableCalls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$jsonTableCalls): array {
                if (str_contains($sql, 'JSON_TABLE(')) {
                    $jsonTableCalls++;
                }
                return [['genre' => 'Action']];
            }
        );

        $repo = new ItemRepository($db);

        $repo->distinctGenres('lib-A');            // compute #1
        $repo->distinctGenres('lib-A');            // cached
        $repo->deleteByLibrary('lib-A');
        $repo->distinctGenres('lib-A');            // compute #2

        $this->assertSame(2, $jsonTableCalls);
    }

    /**
     * Regression: a Reviewer finding on the recompute path (cache miss due to
     * TTL expiry, key still physically present) — plain value reassignment of an
     * ALREADY-PRESENT array key does NOT move it to the end in PHP; it stays at
     * its original position. Without the fix (unset() before reassigning), the
     * "MRU position" claim was false, and oldest-first (`array_key_first`)
     * eviction could drop a just-recomputed (freshest) scope before a genuinely
     * untouched one. This forces a scope stale WITHOUT going through
     * invalidateGenreFacets() (which already unsets and would mask the bug) and
     * asserts the recomputed key is physically repositioned to the last slot.
     */
    public function testStaleEntryRecomputeRepositionsToMruSlot(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['genre' => 'Action']]);

        $repo = new ItemRepository($db);

        $repo->distinctGenres('lib-A'); // populated first
        $repo->distinctGenres('lib-B'); // populated second

        // Directly expire 'lib-A' in place (key stays present) — the exact
        // "stale but not invalidated" state the recompute path must handle.
        $cacheProp = new \ReflectionProperty(ItemRepository::class, 'genreFacetCache');
        $cache = $cacheProp->getValue($repo);
        $cache['lib-A']['expires_at'] = 0;
        $cacheProp->setValue($repo, $cache);

        $repo->distinctGenres('lib-A'); // stale → recompute

        $keysAfter = array_keys($cacheProp->getValue($repo));
        $this->assertSame(
            ['lib-B', 'lib-A'],
            $keysAfter,
            'a recomputed stale entry must move to the MRU (last) slot'
        );
    }

    /**
     * Security-critical bound (the CARDINAL rule this step exists for):
     * WebPortalRouter::getMediaFacets passes the raw, unvalidated caller-supplied
     * ?libraryId= straight into the cache scope key, and ItemRepository is a
     * resident per-worker singleton — so an authenticated attacker can churn
     * unbounded fake scopes. distinctGenres() must cap the map at
     * GENRE_FACET_CACHE_MAX with oldest-first (LRU) eviction. This drives the
     * cache PAST the bound to exercise the eviction branch (array_key_first() +
     * unset(), otherwise uncovered) and proves both the hard cap and that a
     * recently-touched (hot) scope survives while a genuinely-cold one is dropped.
     */
    public function testGenreFacetCacheEvictsOldestScopeBeyondBound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['genre' => 'Action']]);

        $repo = new ItemRepository($db);

        $max = (int) (new \ReflectionClassConstant(
            ItemRepository::class,
            'GENRE_FACET_CACHE_MAX'
        ))->getValue();

        // Fill exactly to the bound (scopes lib-0 .. lib-(max-1)) — no eviction yet.
        for ($i = 0; $i < $max; $i++) {
            $repo->distinctGenres('lib-' . $i);
        }
        $cacheProp = new \ReflectionProperty(ItemRepository::class, 'genreFacetCache');
        $this->assertCount($max, $cacheProp->getValue($repo), 'at the bound, nothing evicted yet');

        // Touch the oldest scope so it becomes MRU (LRU-hot), making lib-1 the
        // new oldest.
        $repo->distinctGenres('lib-0');

        // One more distinct scope overflows the bound → the coldest scope (lib-1)
        // is evicted, not the just-touched lib-0.
        $repo->distinctGenres('lib-' . $max);

        $keys = array_keys($cacheProp->getValue($repo));
        $this->assertCount($max, $keys, 'the map stays hard-capped at the bound');
        $this->assertNotContains('lib-1', $keys, 'the coldest (untouched) scope was evicted first (LRU)');
        $this->assertContains('lib-0', $keys, 'a recently-touched hot scope survives eviction');
        $this->assertContains('lib-' . $max, $keys, 'the newest scope is retained');
    }

    // -------------------------------------------------------------------------
    // valueBuckets() tests
    // -------------------------------------------------------------------------

    public function testValueBucketsYearFieldParameterized(): void
    {
        $capturedSql = null;
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function (string $sql) use (&$capturedSql): bool {
                    $capturedSql = $sql;
                    // Year bucket groups/orders by the RELEASE year from metadata.
                    $e = "CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '\$.year')) AS SIGNED)";
                    return str_contains($sql, "{$e} AS bucket_value")
                        && str_contains($sql, "GROUP BY {$e}")
                        && str_contains($sql, "ORDER BY {$e} ASC");
                }),
                $this->callback(function (array $params) use (&$capturedParams): bool {
                    $capturedParams = $params;
                    // libraryId bound as positional placeholder (colon-free)
                    return is_array($params)
                        && in_array('lib-year-test', $params, true)
                        && !str_contains(print_r($params, true), ':');
                })
            )
            ->willReturn([
                ['bucket_value' => '2020', 'item_count' => 5],
                ['bucket_value' => '2021', 'item_count' => 3],
            ]);

        $repo = new ItemRepository($db);
        $result = $repo->valueBuckets('year', [], 'lib-year-test');

        $this->assertCount(2, $result);
        $this->assertSame(['value' => '2020', 'count' => 5], $result[0]);
        $this->assertSame(['value' => '2021', 'count' => 3], $result[1]);
    }

    public function testValueBucketsRatingFieldCanonicalExpression(): void
    {
        $capturedSql = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function (string $sql) use (&$capturedSql): bool {
                    $capturedSql = $sql;
                    // Rating groups/orders by the materialized content_rating
                    // column (migration 050), not a per-row JSON extraction.
                    $e = 'content_rating';
                    return str_contains($sql, "{$e} AS bucket_value")
                        && str_contains($sql, "GROUP BY {$e}")
                        && str_contains($sql, "ORDER BY {$e} ASC");
                }),
                $this->anything()
            )
            ->willReturn([
                ['bucket_value' => 'PG', 'item_count' => 10],
                ['bucket_value' => 'R', 'item_count' => 7],
            ]);

        $repo = new ItemRepository($db);
        $result = $repo->valueBuckets('rating', []);

        $this->assertCount(2, $result);
        $this->assertSame(['value' => 'PG', 'count' => 10], $result[0]);
    }

    public function testValueBucketsNoSelectStar(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function (string $sql): bool {
                    return !str_contains($sql, 'SELECT *')
                        && str_contains($sql, 'AS bucket_value')
                        && str_contains($sql, 'COUNT(*) AS item_count');
                }),
                $this->anything()
            )
            ->willReturn([]);

        $repo = new ItemRepository($db);
        $repo->valueBuckets('year', []);
    }

    public function testValueBucketsOrderHonored(): void
    {
        $capturedSql = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function (string $sql) use (&$capturedSql): bool {
                    $capturedSql = $sql;
                    // desc order must be reflected in both ORDER BY and GROUP BY (MySQL allows expr DESC in GROUP BY)
                    $e = "CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '\$.year')) AS SIGNED)";
                    return str_contains($sql, "ORDER BY {$e} DESC")
                        && str_contains($sql, "GROUP BY {$e}");
                }),
                $this->anything()
            )
            ->willReturn([
                ['bucket_value' => '2023', 'item_count' => 20],
                ['bucket_value' => '2020', 'item_count' => 8],
            ]);

        $repo = new ItemRepository($db);
        $result = $repo->valueBuckets('year', ['order' => 'desc'], 'lib-desc');

        $this->assertCount(2, $result);
        // Descending: 2023 first
        $this->assertSame(['value' => '2023', 'count' => 20], $result[0]);
    }

    public function testValueBucketsCountSum(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['bucket_value' => 'G',      'item_count' => 3],
            ['bucket_value' => 'PG',    'item_count' => 5],
            ['bucket_value' => 'PG-13', 'item_count' => 2],
            ['bucket_value' => 'R',     'item_count' => 10],
        ]);

        $repo = new ItemRepository($db);
        $result = $repo->valueBuckets('rating', []);

        $totalFromBuckets = array_sum(array_column($result, 'count'));
        $this->assertSame(20, $totalFromBuckets);
        $this->assertCount(4, $result);
    }

    public function testValueBucketsUnknownFieldDefaultsToName(): void
    {
        $capturedSql = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function (string $sql) use (&$capturedSql): bool {
                    $capturedSql = $sql;
                    // Unknown field 'foobar' falls back to name bucketing (letter expression)
                    return str_contains($sql, 'UPPER(LEFT(')
                        && str_contains($sql, 'AS bucket_value')
                        && str_contains($sql, 'GROUP BY');
                }),
                $this->anything()
            )
            ->willReturn([]);

        $repo = new ItemRepository($db);
        $result = $repo->valueBuckets('foobar', []);

        $this->assertIsArray($result);
    }

    public function testValueBucketsRuntimeFieldUsesRuntimeSort(): void
    {
        $capturedSql = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function (string $sql) use (&$capturedSql): bool {
                    $capturedSql = $sql;
                    $e = "CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '\$.runtime')) AS SIGNED)";
                    return str_contains($sql, "{$e} AS bucket_value")
                        && str_contains($sql, "GROUP BY {$e}")
                        && str_contains($sql, "ORDER BY {$e} ASC");
                }),
                $this->anything()
            )
            ->willReturn([
                ['bucket_value' => '90', 'item_count' => 4],
            ]);

        $repo = new ItemRepository($db);
        $repo->valueBuckets('runtime', []);
    }

    public function testValueBucketsDateAddedFieldUsesDateCreatedAt(): void
    {
        $capturedSql = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function (string $sql) use (&$capturedSql): bool {
                    $capturedSql = $sql;
                    // GROUP BY uses DATE(created_at) for bucketing; ORDER BY uses
                    // created_at (mirrors buildOrderClause's date_added → created_at mapping).
                    return str_contains($sql, 'DATE(created_at) AS bucket_value')
                        && str_contains($sql, 'GROUP BY DATE(created_at)')
                        && str_contains($sql, 'ORDER BY created_at ASC');
                }),
                $this->anything()
            )
            ->willReturn([
                ['bucket_value' => '2024-01-15', 'item_count' => 2],
            ]);

        $repo = new ItemRepository($db);
        $repo->valueBuckets('date_added', []);
    }

    public function testValueBucketsBoundedAt200Rows(): void
    {
        $capturedSql = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function (string $sql) use (&$capturedSql): bool {
                    $capturedSql = $sql;
                    return str_contains($sql, 'LIMIT 200');
                }),
                $this->anything()
            )
            ->willReturn([]);

        $repo = new ItemRepository($db);
        $repo->valueBuckets('year', []);
    }

    public function testValueBucketsReusesBuildFiltersWithAllParams(): void
    {
        $capturedSql = null;
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function (string $sql) use (&$capturedSql): bool {
                    $capturedSql = $sql;
                    // buildFilters(): libraryId → library_id = ?; search → MATCH/FULLTEXT.
                    // topLevel is dropped when search is non-empty (matches query() behaviour).
                    return str_contains($sql, 'library_id = ?')
                        && str_contains($sql, 'MATCH(name) AGAINST');
                }),
                $this->callback(function (array $params) use (&$capturedParams): bool {
                    $capturedParams = $params;
                    // libraryId + search term bound as positional placeholders
                    return is_array($params)
                        && in_array('lib-multi', $params, true)
                        && in_array('batman', $params, true);
                })
            )
            ->willReturn([]);

        $repo = new ItemRepository($db);
        $repo->valueBuckets('year', [
            'topLevel' => true,
            'search' => 'batman',
        ], 'lib-multi');
    }
}
