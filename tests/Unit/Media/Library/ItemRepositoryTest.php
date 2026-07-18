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
        //
        // create() also syncs the media_item_genres join table (migration 051)
        // after the main INSERT — captured here only, so this test still
        // isolates the main INSERT's params regardless of that extra call.
        $captured = null;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$captured) {
            if (str_starts_with(trim($sql), 'INSERT INTO media_items')) {
                $captured = $params;
            }
            return [];
        });

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
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$captured) {
            if (str_starts_with(trim($sql), 'INSERT INTO media_items')) {
                $captured = $params;
            }
            return [];
        });

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

    /**
     * SV-0.8: the single-row {@see ItemRepository::findByPath()} must issue an
     * index-friendly `library_id = ? AND path_hash = ? AND path = ?` query when
     * a libraryId is supplied — leading with library_id so the composite
     * `(library_id, path_hash)` unique index is usable, and keeping the raw
     * `path` as a collision tiebreak. The SHA1 of the path is bound, not the
     * raw path, for the hash column.
     */
    public function testFindByPathUsesLibraryScopedPathHashIndexQuery(): void
    {
        $capturedSql = '';
        $capturedParams = null;

        $db = $this->createMock(Connection::class);
        // Return a matching row from the fast path_hash pass so the raw-path
        // fallback pass is skipped and exactly ONE query is issued on a hit.
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, $params = []) use (&$capturedSql, &$capturedParams) {
                $capturedSql = $sql;
                $capturedParams = $params;
                return [[
                    'id' => 'movie-1',
                    'path' => '/movies/test.mkv',
                    'type' => 'movie',
                    'library_id' => 'lib-1',
                    'metadata_json' => '{}',
                ]];
            });

        $repo = new ItemRepository($db);
        $repo->findByPath('/movies/test.mkv', 'lib-1');

        $this->assertStringContainsString(
            'WHERE library_id = ? AND path_hash = ? AND path = ?',
            $capturedSql,
            'library-scoped findByPath must lead with library_id so the composite index is usable'
        );
        $this->assertSame(
            ['lib-1', sha1('/movies/test.mkv'), '/movies/test.mkv'],
            $capturedParams,
            'binds library id, then SHA1(path), then the raw path tiebreak'
        );
    }

    /**
     * SV-0.8 HIGH-finding regression: a NON-deduped type (series/season/image/
     * audiobook/track) has a NULL generated `path_hash`, so `path_hash = SHA1(?)`
     * NEVER matches it (`NULL = <hash>` is never true in SQL). findByPath MUST
     * fall back to a raw `path = ?` lookup so those rows are still found — before
     * this fix the fast pass silently missed them and the scanner forked a fresh
     * DUPLICATE container/item on every rescan (no unique constraint catches a
     * NULL-hash path, since NULLs never collide in a unique index).
     */
    public function testFindByPathFallsBackToRawPathForNullHashRow(): void
    {
        $queries = [];

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$queries) {
            $queries[] = ['sql' => $sql, 'params' => $params];
            // Fast path_hash pass finds nothing — a NULL-hash row is invisible to it.
            if (str_contains($sql, 'path_hash')) {
                return [];
            }
            // Raw-path fallback pass resolves the season container by exact path.
            return [[
                'id' => 'season-1',
                'path' => 'season:lib-1:some-show:1',
                'type' => 'season',
                'library_id' => 'lib-1',
                'parent_id' => 'series-1',
                'metadata_json' => '{"season": 1}',
            ]];
        });

        $repo = new ItemRepository($db);
        $result = $repo->findByPath('season:lib-1:some-show:1', 'lib-1');

        $this->assertIsArray($result, 'a NULL-path_hash row must be resolved via the raw-path fallback');
        $this->assertSame('season-1', $result['id']);
        $this->assertCount(2, $queries, 'fast path_hash pass, then the raw-path fallback pass');
        $this->assertStringContainsString('path_hash = ?', $queries[0]['sql'], 'pass 1 is the indexed hash lookup');
        $this->assertStringNotContainsString('path_hash', $queries[1]['sql'], 'pass 2 is a raw-path lookup');
        $this->assertStringContainsString(
            'WHERE library_id = ? AND path = ?',
            $queries[1]['sql'],
            'the fallback stays scoped to library_id (an index range, not a full scan)'
        );
        $this->assertSame(['lib-1', 'season:lib-1:some-show:1'], $queries[1]['params']);
    }

    /**
     * SV-0.8: the fast pass short-circuits — when the indexed `path_hash` lookup
     * resolves the row (a deduped type), the raw-path fallback pass must NOT run,
     * so the common case stays a single indexed query.
     */
    public function testFindByPathSkipsFallbackWhenFastPassResolves(): void
    {
        $queryCount = 0;

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql) use (&$queryCount) {
            $queryCount++;
            return [[
                'id' => 'movie-1',
                'path' => '/movies/test.mkv',
                'type' => 'movie',
                'library_id' => 'lib-1',
                'metadata_json' => '{}',
            ]];
        });

        $repo = new ItemRepository($db);
        $repo->findByPath('/movies/test.mkv', 'lib-1');

        $this->assertSame(1, $queryCount, 'a deduped-type hit must issue exactly one (indexed) query');
    }

    /**
     * S8: an empty path list must never reach the database — a malformed
     * `IN ()` clause would otherwise be sent to MySQL.
     */
    public function testFindPathsMapReturnsEmptyArrayWithoutQueryingForEmptyInput(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $repo = new ItemRepository($db);

        $this->assertSame([], $repo->findPathsMap([]));
    }

    /**
     * S8: findPathsMap() issues exactly ONE query for N paths (not N queries)
     * — the batch/N+1-prevention contract — with correctly-ordered
     * placeholders and the paths bound positionally in the given order.
     */
    public function testFindPathsMapIssuesExactlyOneQueryWithCorrectPlaceholdersAndBindings(): void
    {
        $capturedSql = '';
        $capturedParams = null;
        $callCount = 0;

        $db = $this->createMock(Connection::class);
        // Resolve every path in the fast path_hash pass so the raw-path fallback
        // pass never runs — the batch stays a single query for N deduped paths.
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, $params = []) use (&$capturedSql, &$capturedParams, &$callCount) {
                $callCount++;
                $capturedSql = $sql;
                $capturedParams = $params;
                return [
                    ['id' => 'a', 'path' => '/a.mkv', 'type' => 'movie', 'metadata_json' => '{}'],
                    ['id' => 'b', 'path' => '/b.mkv', 'type' => 'movie', 'metadata_json' => '{}'],
                    ['id' => 'c', 'path' => '/c.mkv', 'type' => 'movie', 'metadata_json' => '{}'],
                ];
            });

        $repo = new ItemRepository($db);
        $repo->findPathsMap(['/a.mkv', '/b.mkv', '/c.mkv']);

        $this->assertSame(1, $callCount, 'exactly one query for N paths, not N queries');
        $this->assertStringContainsString('WHERE path_hash IN (?,?,?)', $capturedSql);
        $this->assertSame([sha1('/a.mkv'), sha1('/b.mkv'), sha1('/c.mkv')], $capturedParams);
    }

    /**
     * SV-0.8: when a libraryId is supplied the predicate must lead with
     * `library_id = ?` so the composite `(library_id, path_hash)` unique index
     * (migration 072) is used left-prefix-first — an index scan, not a full
     * table scan. The library id is bound FIRST, ahead of the SHA1 hashes, in
     * the repo's positional-parameter convention.
     */
    public function testFindPathsMapScopesToLibraryAndUsesPathHashIndex(): void
    {
        $capturedSql = '';
        $capturedParams = null;

        $db = $this->createMock(Connection::class);
        // Resolve both paths in the fast pass so the fallback pass is skipped and
        // exactly one (library-scoped, indexed) query is issued.
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, $params = []) use (&$capturedSql, &$capturedParams) {
                $capturedSql = $sql;
                $capturedParams = $params;
                return [
                    ['id' => 'a', 'path' => '/a.mkv', 'type' => 'movie', 'metadata_json' => '{}'],
                    ['id' => 'b', 'path' => '/b.mkv', 'type' => 'movie', 'metadata_json' => '{}'],
                ];
            });

        $repo = new ItemRepository($db);
        $repo->findPathsMap(['/a.mkv', '/b.mkv'], 'lib-1');

        // library_id leads the predicate, path_hash IN (...) follows.
        $this->assertStringContainsString(
            'WHERE library_id = ? AND path_hash IN (?,?)',
            $capturedSql,
            'library-scoped lookup must lead with library_id so the composite index is usable'
        );
        $this->assertSame(
            ['lib-1', sha1('/a.mkv'), sha1('/b.mkv')],
            $capturedParams,
            'library id binds first, then one SHA1 per path, positionally'
        );
    }

    /**
     * SV-0.8: the raw-path equality tiebreak. A row returned by the hash
     * predicate whose actual `path` is NOT in the requested input list (the
     * astronomically rare SHA1 collision, or a scoping artifact) must be
     * excluded from the map — hash membership alone is not trusted.
     */
    public function testFindPathsMapExcludesRowsWhoseRawPathIsNotRequested(): void
    {
        $db = $this->createMock(Connection::class);
        // The DB (hypothetically) returns a row for a path we did NOT ask for.
        $db->method('query')->willReturn([
            [
                'id' => 'collision',
                'path' => '/some/other/path.mkv',
                'type' => 'movie',
                'metadata_json' => '{}',
            ],
        ]);

        $repo = new ItemRepository($db);
        $map = $repo->findPathsMap(['/a.mkv', '/b.mkv'], 'lib-1');

        $this->assertSame([], $map, 'a row whose raw path was not requested must be dropped');
    }

    /**
     * SV-0.8 HIGH-finding regression (batch path): findPathsMap must resolve
     * NON-deduped rows (NULL `path_hash`) via a second raw-path pass over the
     * paths the hash pass did not resolve — otherwise a photo/audiobook/music
     * library gets a FULL DUPLICATE item set on every rescan (the batch reports
     * every existing NULL-hash row as "absent" and the scanner re-creates it).
     */
    public function testFindPathsMapFallsBackToRawPathForNullHashRows(): void
    {
        $queries = [];

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$queries) {
            $queries[] = ['sql' => $sql, 'params' => $params];
            // Fast path_hash IN pass sees nothing — image rows hash to NULL.
            if (str_contains($sql, 'path_hash')) {
                return [];
            }
            // Raw-path fallback resolves the existing image rows by exact path.
            return [
                ['id' => 'img-a', 'path' => '/photos/a.jpg', 'type' => 'image', 'metadata_json' => '{}'],
                ['id' => 'img-b', 'path' => '/photos/b.jpg', 'type' => 'image', 'metadata_json' => '{}'],
            ];
        });

        $repo = new ItemRepository($db);
        $map = $repo->findPathsMap(['/photos/a.jpg', '/photos/b.jpg'], 'lib-img');

        $this->assertCount(2, $map, 'both NULL-hash rows resolved via the raw-path fallback pass');
        $this->assertArrayHasKey('/photos/a.jpg', $map);
        $this->assertArrayHasKey('/photos/b.jpg', $map);
        $this->assertCount(2, $queries, 'fast path_hash pass, then the raw-path fallback pass');
        $this->assertStringContainsString(
            'WHERE library_id = ? AND path IN (?,?)',
            $queries[1]['sql'],
            'the fallback re-probes only unresolved paths, scoped to the library'
        );
        $this->assertSame(['lib-img', '/photos/a.jpg', '/photos/b.jpg'], $queries[1]['params']);
    }

    /**
     * SV-0.8: the fallback pass must re-probe ONLY the paths the hash pass left
     * unresolved (a mixed batch of a deduped movie + a NULL-hash image) — the
     * deduped row is already in the map, so the second query is bounded to the
     * remainder and never re-fetches an already-resolved path.
     */
    public function testFindPathsMapFallbackReProbesOnlyUnresolvedPaths(): void
    {
        $queries = [];

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$queries) {
            $queries[] = ['sql' => $sql, 'params' => $params];
            if (str_contains($sql, 'path_hash')) {
                // The deduped movie resolves in the fast pass.
                return [['id' => 'mov', 'path' => '/movies/m.mkv', 'type' => 'movie', 'metadata_json' => '{}']];
            }
            // Only the NULL-hash image is left for the fallback pass.
            return [['id' => 'img', 'path' => '/photos/p.jpg', 'type' => 'image', 'metadata_json' => '{}']];
        });

        $repo = new ItemRepository($db);
        $map = $repo->findPathsMap(['/movies/m.mkv', '/photos/p.jpg'], 'lib-1');

        $this->assertCount(2, $map);
        $this->assertArrayHasKey('/movies/m.mkv', $map);
        $this->assertArrayHasKey('/photos/p.jpg', $map);
        // The fallback pass binds ONLY the unresolved image path, not the movie.
        $this->assertSame(['lib-1', '/photos/p.jpg'], $queries[1]['params']);
        $this->assertStringContainsString('path IN (?)', $queries[1]['sql']);
    }

    /**
     * S8: the returned map is keyed by the row's `path` column (not by input
     * order/index), hydrated exactly like {@see ItemRepository::findByPath()}
     * (both `metadata_json` and decoded `metadata` present), and a path with
     * no matching row is simply ABSENT from the map (never a null entry).
     */
    public function testFindPathsMapKeysResultsByPathAndOmitsMissingPaths(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'id-b',
                'path' => '/b.mkv',
                'type' => 'movie',
                'metadata_json' => '{"year": 2021}',
            ],
        ]);

        $repo = new ItemRepository($db);
        $map = $repo->findPathsMap(['/a.mkv', '/b.mkv', '/c.mkv']);

        $this->assertCount(1, $map);
        $this->assertArrayNotHasKey('/a.mkv', $map);
        $this->assertArrayNotHasKey('/c.mkv', $map);
        $this->assertArrayHasKey('/b.mkv', $map);
        $this->assertSame('id-b', $map['/b.mkv']['id']);
        $this->assertSame(['year' => 2021], $map['/b.mkv']['metadata']);
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
        $captured = null;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$captured) {
            if (str_starts_with(trim($sql), 'INSERT INTO media_items')) {
                $captured = $params;
            }
            return [];
        });

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

        // id, library_id, parent_id, name, type, path, canonical_key,
        // sort_title, content_rating, metadata_json
        $this->assertIsArray($captured);
        $this->assertCount(10, $captured);
        $this->assertSame('lib-1', $captured[1]);
        $this->assertSame('Test Movie', $captured[3]);
        $this->assertSame('movie', $captured[4]);
        $this->assertSame('/movies/test.mkv', $captured[5]);
        $this->assertNull($captured[6]);            // no canonical_key in metadata → column NULL
        $this->assertSame('Test Movie', $captured[7]); // sort_title (no leading article)
        $this->assertNull($captured[8]);            // no rating in metadata → column NULL
    }

    public function testCreateWritesCanonicalKeyColumnFromMetadataArray(): void
    {
        // Migration 043: the scanner stamps metadata_json.canonical_key (Step 1.2);
        // create() must COPY it into the indexed `canonical_key` column (source of
        // truth) without disturbing the blob.
        $capturedSql = null;
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$capturedSql, &$capturedParams) {
            if (str_starts_with(trim($sql), 'INSERT INTO media_items')) {
                $capturedSql = $sql;
                $capturedParams = $params;
            }
            return [];
        });

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
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$capturedParams) {
            if (str_starts_with(trim($sql), 'INSERT INTO media_items')) {
                $capturedParams = $params;
            }
            return [];
        });

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
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$capturedParams) {
            if (str_starts_with(trim($sql), 'INSERT INTO media_items')) {
                $capturedParams = $params;
            }
            return [];
        });

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
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$capturedSql, &$capturedParams) {
            if (str_starts_with(trim($sql), 'UPDATE media_items')) {
                $capturedSql = $sql;
                $capturedParams = $params;
            }
            return [];
        });

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
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$capturedSql, &$capturedParams) {
            if (str_starts_with(trim($sql), 'UPDATE media_items')) {
                $capturedSql = $sql;
                $capturedParams = $params;
            }
            return [];
        });

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
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$capturedParams) {
            if (str_starts_with(trim($sql), 'UPDATE media_items')) {
                $capturedParams = $params;
            }
            return [];
        });

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
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$capturedParams) {
            if (str_starts_with(trim($sql), 'INSERT INTO media_items')) {
                $capturedParams = $params;
            }
            return [];
        });

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
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$capturedParams) {
            if (str_starts_with(trim($sql), 'UPDATE media_items')) {
                $capturedParams = $params;
            }
            return [];
        });

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
    // media_item_genres join table (migration 051) write path — extractGenres()
    // (private, exercised only indirectly through create()/update()),
    // insertGenreRows() (create(), INSERT-only), syncGenreRows() (update(),
    // DELETE-then-INSERT). The pre-existing genre-facet-cache-invalidation
    // tests exercise this path with metadata but never assert on the resulting
    // media_item_genres SQL/bindings — these tests close that gap.
    // -----------------------------------------------------------------------

    public function testCreateInsertsDedupedNonEmptyGenreRowsWithNoPrecedingDelete(): void
    {
        // Duplicate + blank-string genres must be deduped/dropped before the
        // INSERT (the table's PRIMARY KEY is (media_item_id, genre); a
        // duplicate would otherwise throw against real MySQL).
        $calls = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$calls) {
            $calls[] = ['sql' => $sql, 'params' => $params];
            return [];
        });

        $repo = new ItemRepository($db);
        $id = $repo->create([
            'library_id' => 'lib-1',
            'name' => 'Genre Movie',
            'type' => 'movie',
            'path' => '/movies/genre-movie.mkv',
            'metadata_json' => ['genres' => ['Action', 'Action', '', 'Drama', 'Action']],
        ]);

        $deleteCalls = array_values(array_filter(
            $calls,
            static fn (array $c): bool => str_starts_with(trim($c['sql']), 'DELETE FROM media_item_genres')
        ));
        $insertCalls = array_values(array_filter(
            $calls,
            static fn (array $c): bool => str_starts_with(trim($c['sql']), 'INSERT INTO media_item_genres')
        ));

        // create() must NEVER delete from media_item_genres — a freshly
        // generated id can never have pre-existing rows (insertGenreRows(),
        // not syncGenreRows()).
        $this->assertCount(0, $deleteCalls);
        $this->assertCount(1, $insertCalls);

        $insertSql = $insertCalls[0]['sql'];
        $insertParams = $insertCalls[0]['params'];

        // Exactly 2 unique, non-empty genres survive → exactly 2 (?, ?) groups.
        $this->assertStringContainsString(
            'INSERT INTO media_item_genres (media_item_id, genre) VALUES (?, ?),(?, ?)',
            $insertSql
        );
        $this->assertSame([$id, 'Action', $id, 'Drama'], $insertParams);
    }

    public function testCreateWithNoGenresKeyIssuesNoMediaItemGenresQueryAtAll(): void
    {
        // No metadata_json.genres at all → extractGenres() → [] →
        // insertGenreRows() no-ops (issues NO query, not even an empty INSERT).
        $calls = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$calls) {
            $calls[] = $sql;
            return [];
        });

        $repo = new ItemRepository($db);
        $repo->create([
            'library_id' => 'lib-1',
            'name' => 'No Genres',
            'type' => 'movie',
            'path' => '/movies/no-genres.mkv',
            'metadata_json' => ['year' => 2000],
        ]);

        $genreCalls = array_filter($calls, static fn (string $sql): bool => str_contains($sql, 'media_item_genres'));
        $this->assertCount(0, $genreCalls);
    }

    public function testCreateWithNonArrayGenresValueIssuesNoMediaItemGenresQuery(): void
    {
        // metadata_json.genres present but NOT an array (e.g. a raw string) —
        // extractGenres() must yield [] rather than attempting to iterate a
        // scalar, so no INSERT is issued.
        $calls = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$calls) {
            $calls[] = $sql;
            return [];
        });

        $repo = new ItemRepository($db);
        $repo->create([
            'library_id' => 'lib-1',
            'name' => 'Malformed Genres',
            'type' => 'movie',
            'path' => '/movies/malformed-genres.mkv',
            'metadata_json' => ['genres' => 'Action'],
        ]);

        $genreCalls = array_filter($calls, static fn (string $sql): bool => str_contains($sql, 'media_item_genres'));
        $this->assertCount(0, $genreCalls);
    }

    public function testUpdateSyncsGenreRowsWithDeleteThenInsertInThatOrder(): void
    {
        $calls = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$calls) {
            $calls[] = ['sql' => $sql, 'params' => $params];
            return [];
        });

        $repo = new ItemRepository($db);
        $repo->update('item-1', ['metadata_json' => ['genres' => ['Sci-Fi', 'Sci-Fi', 'Drama']]]);

        $genreCalls = array_values(array_filter(
            $calls,
            static fn (array $c): bool => str_contains($c['sql'], 'media_item_genres')
        ));

        $this->assertCount(2, $genreCalls);
        $this->assertStringStartsWith('DELETE FROM media_item_genres', trim($genreCalls[0]['sql']));
        $this->assertSame(['item-1'], $genreCalls[0]['params']);
        $this->assertStringStartsWith('INSERT INTO media_item_genres', trim($genreCalls[1]['sql']));
        $this->assertStringContainsString(
            'INSERT INTO media_item_genres (media_item_id, genre) VALUES (?, ?),(?, ?)',
            $genreCalls[1]['sql']
        );
        $this->assertSame(['item-1', 'Sci-Fi', 'item-1', 'Drama'], $genreCalls[1]['params']);
    }

    public function testUpdateClearsGenreRowsWithDeleteOnlyWhenNewGenreListIsEmpty(): void
    {
        // metadata_json IS present (so the sync path runs) but carries no
        // genres key → extractGenres() → [] → syncGenreRows() DELETEs the
        // item's prior rows, then insertGenreRows() no-ops (must NOT issue an
        // empty/degenerate INSERT).
        $calls = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$calls) {
            $calls[] = ['sql' => $sql, 'params' => $params];
            return [];
        });

        $repo = new ItemRepository($db);
        $repo->update('item-1', ['metadata_json' => ['year' => 2000]]);

        $genreCalls = array_values(array_filter(
            $calls,
            static fn (array $c): bool => str_contains($c['sql'], 'media_item_genres')
        ));

        $this->assertCount(1, $genreCalls);
        $this->assertStringStartsWith('DELETE FROM media_item_genres', trim($genreCalls[0]['sql']));
        $this->assertSame(['item-1'], $genreCalls[0]['params']);
    }

    public function testUpdateWithoutMetadataJsonNeverTouchesGenreTable(): void
    {
        // No metadata_json key in $data at all → genresToSync stays null →
        // syncGenreRows() must never be called (distinct from the previous
        // test, where metadata_json IS present but genre-less).
        $calls = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$calls) {
            $calls[] = $sql;
            return [];
        });

        $repo = new ItemRepository($db);
        $repo->update('item-1', ['name' => 'Renamed Only']);

        $genreCalls = array_filter($calls, static fn (string $sql): bool => str_contains($sql, 'media_item_genres'));
        $this->assertCount(0, $genreCalls);
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

    public function testExtractContentRatingNormalizesToCanonicalEnum(): void
    {
        // Phase C: the materialized column is normalized to a canonical rating
        // (see ContentRating) rather than passed through verbatim, so the
        // RATING_ORDER-driven parental filter always recognizes the value.
        $this->assertSame('NC-17', ItemRepository::extractContentRating(['rating' => 'NC-17']));
        // TV ratings surface now that they are canonical values.
        $this->assertSame('TV-14', ItemRepository::extractContentRating(['rating' => 'TV-14']));
        // FfV-programming suffix collapses to the base TV rating.
        $this->assertSame('TV-Y7', ItemRepository::extractContentRating(['rating' => 'TV-Y7-FV']));
        // NR is an alias of UNRATED.
        $this->assertSame('UNRATED', ItemRepository::extractContentRating(['rating' => 'NR']));
        // Present-but-unrecognized labels must NOT widen the column: they are
        // stored as the most-restrictive UNRATED (never NULL), so a restrictive
        // parental cap hides them instead of leaking them to every profile.
        $this->assertSame('UNRATED', ItemRepository::extractContentRating(['rating' => 'GP']));
        $this->assertSame('UNRATED', ItemRepository::extractContentRating(['rating' => 'M']));
        $this->assertSame('UNRATED', ItemRepository::extractContentRating(['rating' => 'FSK 16']));
        $this->assertSame('UNRATED', ItemRepository::extractContentRating(['official_rating' => 'Approved']));
    }

    public function testExtractContentRatingDistinguishesUnratedFromAbsent(): void
    {
        // FINDING 1: a genuinely absent/empty cert stays NULL ("truly no
        // rating"), while a present-but-unrecognized cert becomes 'UNRATED'
        // (most restrictive). NULL and 'UNRATED' are deliberately different so
        // the NULL-inclusive rating filter can be gated by allow_unrated.
        $this->assertNull(ItemRepository::extractContentRating(['year' => 2020]));           // absent
        $this->assertNull(ItemRepository::extractContentRating(['rating' => '']));            // empty
        $this->assertNull(ItemRepository::extractContentRating(['rating' => '   ']));         // blank
        $this->assertSame('UNRATED', ItemRepository::extractContentRating(['rating' => 'GP'])); // unknown
    }

    public function testExtractContentRatingPrefersOfficialRatingOverRating(): void
    {
        // The resolver stores the content cert under `official_rating`; it wins
        // over any legacy `rating` key so a resolved cert reaches the column.
        $this->assertSame(
            'TV-MA',
            ItemRepository::extractContentRating(['official_rating' => 'TV-MA', 'rating' => 'PG'])
        );
        // Falls back to `rating` when `official_rating` is absent.
        $this->assertSame('PG-13', ItemRepository::extractContentRating(['rating' => 'PG-13']));
    }

    public function testExtractContentRatingReturnsNullWhenRatingKeyAbsent(): void
    {
        $this->assertNull(ItemRepository::extractContentRating(['year' => 2020, 'genres' => ['Drama']]));
    }

    /**
     * Capture the allowed-rating list getByMaxRating() feeds getByAllowedRatings()
     * for a given cap. The SELECT binds [libraryId, ...allowedRatings, limit, offset],
     * so the allowed ratings are the params between the first and the last two.
     *
     * @return list<string>
     */
    private function captureAllowedRatingsForMax(string $maxRating): array
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$captured) {
            if (str_starts_with(trim($sql), 'SELECT * FROM media_items')) {
                // Drop libraryId (first) and limit+offset (last two).
                $captured = array_slice($params, 1, count($params) - 3);
            }
            return [];
        });

        (new ItemRepository($db))->getByMaxRating('lib-1', $maxRating);

        /** @var list<string> $captured */
        return $captured;
    }

    public function testGetByMaxRatingIncludesTvRatingsAtOrBelowPg13(): void
    {
        // A PG-13 cap (rank 3) must allow TV-14 (rank 3) and everything below,
        // and exclude R/TV-MA and above.
        $allowed = $this->captureAllowedRatingsForMax('PG-13');

        foreach (['G', 'TV-Y', 'TV-G', 'TV-Y7', 'PG', 'TV-PG', 'PG-13', 'TV-14'] as $rating) {
            $this->assertContains($rating, $allowed, "{$rating} should be allowed under a PG-13 cap");
        }
        foreach (['R', 'TV-MA', 'NC-17', 'X', 'UNRATED'] as $rating) {
            $this->assertNotContains($rating, $allowed, "{$rating} must be excluded under a PG-13 cap");
        }
    }

    public function testGetByMaxRatingIncludesTvMaAtRCap(): void
    {
        // An R cap (rank 4) allows TV-MA (rank 4) but not NC-17/X/UNRATED.
        $allowed = $this->captureAllowedRatingsForMax('R');

        $this->assertContains('R', $allowed);
        $this->assertContains('TV-MA', $allowed);
        $this->assertContains('TV-14', $allowed);
        $this->assertNotContains('NC-17', $allowed);
        $this->assertNotContains('X', $allowed);
        $this->assertNotContains('UNRATED', $allowed);
    }

    /**
     * Capture the SELECT SQL getByAllowedRatings() runs, so we can assert
     * whether the `content_rating IS NULL` clause is present.
     */
    private function captureAllowedRatingsSql(bool $allowUnrated): string
    {
        $capturedSql = '';
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$capturedSql) {
            if (str_starts_with(trim($sql), 'SELECT * FROM media_items')) {
                $capturedSql = $sql;
            }
            return [];
        });

        (new ItemRepository($db))->getByAllowedRatings('lib-1', ['G', 'PG'], 100, 0, $allowUnrated);

        return $capturedSql;
    }

    public function testGetByAllowedRatingsIncludesNullWhenUnratedAllowed(): void
    {
        // FINDING 3: default (allowUnrated=true) preserves the historical
        // behavior — genuinely-unrated (NULL) items are included.
        $sql = $this->captureAllowedRatingsSql(true);
        $this->assertStringContainsString('content_rating IS NULL', $sql);
    }

    public function testGetByAllowedRatingsExcludesNullWhenUnratedDisallowed(): void
    {
        // FINDING 3: with allow_unrated=false the NULL inclusion is dropped, so
        // truly-unrated items do not leak to a profile that forbids them.
        $sql = $this->captureAllowedRatingsSql(false);
        $this->assertStringNotContainsString('content_rating IS NULL', $sql);
        $this->assertStringContainsString('content_rating IN', $sql);
    }

    public function testGetByMaxRatingThreadsAllowUnratedFlag(): void
    {
        // getByMaxRating must forward allowUnrated to getByAllowedRatings.
        $capturedSql = '';
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$capturedSql) {
            if (str_starts_with(trim($sql), 'SELECT * FROM media_items')) {
                $capturedSql = $sql;
            }
            return [];
        });

        (new ItemRepository($db))->getByMaxRating('lib-1', 'PG-13', 100, 0, false);

        $this->assertStringNotContainsString('content_rating IS NULL', $capturedSql);
    }

    /**
     * Run query() against a mock DB, capturing every SQL string + bindings pair.
     * query() issues a COUNT(*) then a SELECT, so the parental cap must appear in
     * BOTH for counts/pagination to stay consistent with the filtered page.
     *
     * @param array<string, mixed> $params
     * @return list<array{sql: string, params: array<int, mixed>}>
     */
    private function captureQueryCalls(array $params): array
    {
        $calls = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $p = []) use (&$calls) {
            $calls[] = ['sql' => $sql, 'params' => is_array($p) ? $p : []];
            return str_contains($sql, 'COUNT(*)') ? [['count' => 0]] : [];
        });

        (new ItemRepository($db))->query($params);

        return $calls;
    }

    public function testQueryThreadsParentalCapIntoCountAndSelect(): void
    {
        // A PG-13-shaped allow-list must appear in BOTH the COUNT(*) and the
        // SELECT so the total, pagination and the page all reflect the cap.
        $allowed = ['G', 'TV-Y', 'TV-G', 'TV-Y7', 'PG', 'TV-PG', 'PG-13', 'TV-14'];
        $calls = $this->captureQueryCalls([
            'limit' => 50,
            'offset' => 0,
            'allowedRatings' => $allowed,
            'allowUnrated' => true,
        ]);

        $this->assertCount(2, $calls);
        foreach ($calls as $call) {
            $this->assertStringContainsString('content_rating IN', $call['sql']);
            $this->assertStringContainsString('content_rating IS NULL', $call['sql']);
            foreach ($allowed as $rating) {
                $this->assertContains($rating, $call['params']);
            }
            // R / NC-17 / X / UNRATED must never be bound as allowed values.
            $this->assertNotContains('R', $call['params']);
            $this->assertNotContains('TV-MA', $call['params']);
            $this->assertNotContains('UNRATED', $call['params']);
        }
    }

    public function testQueryParentalCapExcludesNullWhenUnratedDisallowed(): void
    {
        $calls = $this->captureQueryCalls([
            'allowedRatings' => ['G', 'PG'],
            'allowUnrated' => false,
        ]);

        foreach ($calls as $call) {
            $this->assertStringContainsString('content_rating IN', $call['sql']);
            $this->assertStringNotContainsString('content_rating IS NULL', $call['sql']);
        }
    }

    public function testQueryWithoutParentalCapAppliesNoRatingFilter(): void
    {
        // Regression guard: the owner / no-profile / no-cap path must produce the
        // exact same unfiltered query as before — no content_rating clause at all.
        $calls = $this->captureQueryCalls(['limit' => 50, 'offset' => 0]);

        foreach ($calls as $call) {
            $this->assertStringNotContainsString('content_rating IN', $call['sql']);
            $this->assertStringNotContainsString('content_rating IS NULL', $call['sql']);
        }
    }

    public function testQueryParentalCapWithEmptyAllowListAppliesNoFilter(): void
    {
        // An empty allow-list must NOT hide everything — it degrades to no cap.
        $calls = $this->captureQueryCalls(['allowedRatings' => []]);

        foreach ($calls as $call) {
            $this->assertStringNotContainsString('content_rating IN', $call['sql']);
        }
    }

    /**
     * Capture the SELECT SQL + bindings getByType() runs.
     *
     * @param array<int|string, mixed>|null $allowedRatings
     * @return array{sql: string, params: array<int, mixed>}
     */
    private function captureGetByTypeCall(?array $allowedRatings, bool $allowUnrated = true): array
    {
        $captured = ['sql' => '', 'params' => []];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $p = []) use (&$captured) {
            $captured = ['sql' => $sql, 'params' => is_array($p) ? $p : []];
            return [];
        });

        (new ItemRepository($db))->getByType('lib-1', 'movie', 100, 0, $allowedRatings, $allowUnrated);

        return $captured;
    }

    public function testGetByTypeWithoutCapIsUnfiltered(): void
    {
        // Default call (no cap) preserves today's behaviour exactly.
        $call = $this->captureGetByTypeCall(null);

        $this->assertStringContainsString('type = ?', $call['sql']);
        $this->assertStringNotContainsString('content_rating IN', $call['sql']);
        $this->assertStringNotContainsString('content_rating IS NULL', $call['sql']);
    }

    public function testGetByTypeWithCapFiltersRatingsAndHonorsAllowUnrated(): void
    {
        $call = $this->captureGetByTypeCall(['G', 'PG'], true);
        $this->assertStringContainsString('content_rating IN', $call['sql']);
        $this->assertStringContainsString('content_rating IS NULL', $call['sql']);
        $this->assertContains('G', $call['params']);
        $this->assertContains('PG', $call['params']);

        $callNoUnrated = $this->captureGetByTypeCall(['G', 'PG'], false);
        $this->assertStringContainsString('content_rating IN', $callNoUnrated['sql']);
        $this->assertStringNotContainsString('content_rating IS NULL', $callNoUnrated['sql']);
    }

    public function testGetByTypeWithEmptyCapAppliesNoFilter(): void
    {
        $call = $this->captureGetByTypeCall([]);
        $this->assertStringNotContainsString('content_rating IN', $call['sql']);
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
        //
        // update() also syncs the media_item_genres join table (migration 051)
        // after the main UPDATE when metadata_json is present — captured here
        // only, so this test still isolates the main UPDATE's SQL/params.
        $capturedSql = null;
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$capturedSql, &$capturedParams) {
            if (str_starts_with(trim($sql), 'UPDATE media_items')) {
                $capturedSql = $sql;
                $capturedParams = $params;
            }
            return [];
        });

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

    /**
     * SV-1.1(a): the persisted media_streams color columns (migration 073) are
     * READ into an extractColorMetadata()-shaped array so the HDR tone-map
     * decision can be sourced from the scan instead of a live probe. DECIMAL
     * columns arrive from the driver as strings — assert they are cast to float.
     */
    public function testGetVideoStreamColorMetadataReturnsPersistedColorForVideoStream(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('FROM media_streams'),
                    $this->stringContains("stream_type = 'video'"),
                    $this->stringContains('color_transfer')
                ),
                ['movie-1']
            )
            ->willReturn([
                [
                    'color_space' => 'bt2020nc',
                    'color_transfer' => 'smpte2084',
                    'color_primaries' => 'bt2020',
                    'max_luminance' => '1000.00',
                    'avg_luminance' => '200.00',
                ],
            ]);

        $repo = new ItemRepository($db);

        $this->assertSame([
            'color_space' => 'bt2020nc',
            'color_transfer' => 'smpte2084',
            'color_primaries' => 'bt2020',
            'max_luminance' => 1000.0,
            'avg_luminance' => 200.0,
        ], $repo->getVideoStreamColorMetadata('movie-1'));
    }

    /**
     * SV-1.1(a) byte-identity: a populated row (color_transfer present) with
     * individually-NULL space/primaries/luminance must fill the SAME per-field
     * defaults FfmpegRunner::extractColorMetadata() applies, so the decision +
     * resolved tone-map filter are identical to the probe-derived path.
     */
    public function testGetVideoStreamColorMetadataDefaultsMissingColumnsLikeExtractColorMetadata(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'color_space' => null,
                'color_transfer' => 'arib-std-b67',
                'color_primaries' => null,
                'max_luminance' => null,
                'avg_luminance' => null,
            ],
        ]);

        $repo = new ItemRepository($db);

        $this->assertSame([
            'color_space' => 'bt2020nc',
            'color_transfer' => 'arib-std-b67',
            'color_primaries' => 'bt2020',
            'max_luminance' => 1000.0,
            'avg_luminance' => 200.0,
        ], $repo->getVideoStreamColorMetadata('movie-1'));
    }

    /**
     * SV-1.1(a): no video-stream row → null, so the caller falls back to a live
     * probe (unscanned item / audio-only media).
     */
    public function testGetVideoStreamColorMetadataReturnsNullWhenNoVideoStreamRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new ItemRepository($db);

        $this->assertNull($repo->getVideoStreamColorMetadata('movie-1'));
    }

    /**
     * SV-1.1(a): a pre-073 / un-rescanned video-stream row whose color columns are
     * ALL NULL must return null (NOT misleading SDR-looking defaults) so the
     * caller falls back to the live probe — otherwise genuine HDR content whose
     * columns were never populated would be washed out.
     */
    public function testGetVideoStreamColorMetadataReturnsNullForPre073UnpopulatedRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'color_space' => null,
                'color_transfer' => null,
                'color_primaries' => null,
                'max_luminance' => null,
                'avg_luminance' => null,
            ],
        ]);

        $repo = new ItemRepository($db);

        $this->assertNull($repo->getVideoStreamColorMetadata('movie-1'));
    }

    /**
     * SV-1.1(a): a malformed result set whose first element is not an
     * associative row (defensive guard) resolves to null → the caller falls back
     * to the live probe rather than crashing on a non-array row.
     */
    public function testGetVideoStreamColorMetadataReturnsNullForNonArrayRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([42]);

        $repo = new ItemRepository($db);

        $this->assertNull($repo->getVideoStreamColorMetadata('movie-1'));
    }

    public function testAddStreamInsertsStream(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO media_streams'),
                $this->callback(function ($params) {
                    // 17 bindings: migration 071 added channels/title/is_default;
                    // migration 073 (SV-1.1) added color_space/color_transfer/
                    // color_primaries/max_luminance/avg_luminance.
                    return count($params) === 17
                        && $params[1] === 'movie-1'
                        && $params[2] === 0
                        && $params[3] === 'video'
                        && $params[4] === 'h264'
                        && $params[7] === null   // channels (video row)
                        && $params[10] === null  // title
                        && $params[11] === 0     // is_default defaults to 0
                        && $params[12] === null  // color_space (unset)
                        && $params[13] === null  // color_transfer (unset)
                        && $params[14] === null  // color_primaries (unset)
                        && $params[15] === null  // max_luminance (unset)
                        && $params[16] === null; // avg_luminance (unset)
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

    public function testAddStreamPersistsTrackMetadataColumns(): void
    {
        // Migration 071: channels/title/is_default flow through to the INSERT
        // so audio + subtitle track menus have real metadata to shape.
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('INSERT INTO media_streams'),
                    $this->stringContains('channels'),
                    $this->stringContains('title'),
                    $this->stringContains('is_default')
                ),
                $this->callback(function ($params) {
                    // 17 bindings after migration 073 (SV-1.1) color columns.
                    return count($params) === 17
                        && $params[3] === 'audio'
                        && $params[5] === 'eng'      // language
                        && $params[7] === 6          // channels
                        && $params[10] === 'Surround 5.1' // title
                        && $params[11] === 1;        // is_default
                })
            );

        $repo = new ItemRepository($db);
        $id = $repo->addStream('movie-1', [
            'stream_index' => 1,
            'stream_type' => 'audio',
            'codec' => 'ac3',
            'language' => 'eng',
            'bitrate' => 448000,
            'channels' => 6,
            'title' => 'Surround 5.1',
            'is_default' => 1,
        ]);

        $this->assertNotEmpty($id);
    }

    public function testAddStreamPersistsColorMetadataColumns(): void
    {
        // Migration 073 (SV-1.1): HDR color metadata flows through the INSERT so
        // the transcode/tone-map pipeline can read the source's color primaries
        // and mastering-display luminance. Luminance is is_numeric-guarded and
        // cast to float; a non-numeric value persists as NULL.
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('INSERT INTO media_streams'),
                    $this->stringContains('color_space'),
                    $this->stringContains('color_transfer'),
                    $this->stringContains('color_primaries'),
                    $this->stringContains('max_luminance'),
                    $this->stringContains('avg_luminance')
                ),
                $this->callback(function ($params) {
                    return count($params) === 17
                        && $params[12] === 'bt2020nc'   // color_space
                        && $params[13] === 'smpte2084'  // color_transfer
                        && $params[14] === 'bt2020'     // color_primaries
                        && $params[15] === 1000.0       // max_luminance: numeric → float
                        && $params[16] === null;        // avg_luminance: non-numeric → NULL
                })
            );

        $repo = new ItemRepository($db);
        $id = $repo->addStream('movie-1', [
            'stream_index' => 0,
            'stream_type' => 'video',
            'codec' => 'hevc',
            'color_space' => 'bt2020nc',
            'color_transfer' => 'smpte2084',
            'color_primaries' => 'bt2020',
            'max_luminance' => '1000',   // numeric string → (float) 1000.0
            'avg_luminance' => 'N/A',    // non-numeric → is_numeric guard → NULL
        ]);

        $this->assertNotEmpty($id);
    }

    public function testGetItemStreamsAliasesIsDefaultAsDisposition(): void
    {
        // StreamTrackShaper reads the ffprobe-shaped `disposition` key; the
        // repository surfaces the stored is_default column under that name.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'stream-2',
                'media_item_id' => 'movie-1',
                'stream_index' => 1,
                'stream_type' => 'audio',
                'codec' => 'ac3',
                'is_default' => 1,
            ],
        ]);

        $repo = new ItemRepository($db);
        $result = $repo->getItemStreams('movie-1');

        $this->assertSame(1, $result[0]['disposition']);
    }

    public function testMarkStreamsProbedStampsMediaItem(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('UPDATE media_items'),
                    $this->stringContains('streams_probed_at = NOW()')
                ),
                ['movie-1']
            );

        $repo = new ItemRepository($db);
        $repo->markStreamsProbed('movie-1');
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
        // Each create() also syncs the media_item_genres join table (migration
        // 051) after its INSERT, so `query()` is called more than once per
        // item overall — count only the media_items INSERTs specifically.
        $insertCount = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql) use (&$insertCount) {
            if (str_starts_with(trim($sql), 'INSERT INTO media_items')) {
                $insertCount++;
            }
            return [];
        });

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
        $this->assertSame(2, $insertCount);
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

        $this->assertEquals(['year' => 2020, 'director' => 'Test Director'], ($result ?? [])['metadata']);
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

        $this->assertEmpty($result);
    }

    public function testFindShowsWithUnfingerprintedEpisodesReturnsEmptyWhenDbReturnsNonArray(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(null);

        $repo = new ItemRepository($db);
        $result = $repo->findShowsWithUnfingerprintedEpisodes(20);

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
        // P1-S2: Browse "sort by rating" (?sort=rating → rating_sort) now uses the
        // denormalized `rating_score` column directly instead of CASE/WHEN on
        // `content_rating`. Items with no rating (rating_score IS NULL) sort to
        // the end via 'rating_score DESC' — NULLS LAST is implicit in DESC ordering.
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                if (str_contains($sql, 'COUNT(*)') || str_contains($sql, 'metadata_ratings')) {
                    return true;
                }
                // Rating sort uses the indexed rating_score column directly.
                // The old JSON extraction and CASE/WHEN content_rating approach
                // are both gone from the ORDER BY.
                return str_contains($sql, 'rating_score DESC')
                    && str_contains($sql, 'sort_title')
                    && str_contains($sql, 'name')
                    && !str_contains($sql, "WHEN content_rating = 'G' THEN")
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

        $this->assertEquals(0, $result['total']);
    }

    public function testQueryWithGenresFilterAppliesCorrectly(): void
    {
        $db = $this->createMock(Connection::class);
        // Genre filtering is an EXISTS correlated subquery against the
        // media_item_genres join table (migration 051), which replaced the
        // migration 050 multi-valued functional index over
        // metadata_json.$.genres after that index reproducibly triggered real
        // InnoDB purge-thread errors under sustained churn.
        $db->expects($this->exactly(2))
            ->method('query')
            ->with(
                $this->stringContains('EXISTS (')
            )
            ->willReturnOnConsecutiveCalls([['count' => 0]], []);

        $repo = new ItemRepository($db);
        $repo->query(['genres' => ['Action', 'Drama']]);
    }

    public function testQueryGenresFilterBuildsExactExistsClauseAndBindingOrder(): void
    {
        // Strengthens testQueryWithGenresFilterAppliesCorrectly (which only
        // asserts stringContains('EXISTS (')) with the EXACT clause shape,
        // exact placeholder count, and exact binding order — a swapped
        // binding, a wrong placeholder count, or a dropped array_filter (that
        // would let '' or a non-string genre leak into the bindings) would
        // pass the weaker assertion but fails here.
        $calls = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) use (&$calls) {
            $calls[] = ['sql' => $sql, 'params' => $params];
            return str_starts_with(trim($sql), 'SELECT COUNT') ? [['count' => 0]] : [];
        });

        $repo = new ItemRepository($db);
        // '' and 123 (non-string) must be filtered out of both the SQL
        // placeholder count and the bound values.
        $repo->query(['genres' => ['Action', '', 123, 'Drama'], 'limit' => 10, 'offset' => 5], 'lib-1');

        $this->assertCount(2, $calls);
        [$countCall, $selectCall] = $calls;

        foreach ([$countCall, $selectCall] as $call) {
            $this->assertStringContainsString('library_id = ?', $call['sql']);
            $this->assertStringContainsString('EXISTS (', $call['sql']);
            $this->assertStringContainsString('FROM media_item_genres mig', $call['sql']);
            $this->assertStringContainsString('mig.media_item_id = media_items.id', $call['sql']);
            // Exactly 2 placeholders — proves the blank string and the
            // non-string 123 were filtered out, not just "some" of them.
            $this->assertStringContainsString('mig.genre IN (?,?)', $call['sql']);
        }

        $this->assertSame(['lib-1', 'Action', 'Drama'], $countCall['params']);
        $this->assertSame(['lib-1', 'Action', 'Drama', 10, 5], $selectCall['params']);
    }

    public function testGetByAllowedGenresWithEmptyArrayDelegatesToGetByLibrary(): void
    {
        // The empty-allow-list short circuit (line ~1383) must never build
        // the EXISTS/NOT EXISTS genre clause at all — it must be
        // byte-identical to a plain getByLibrary() call.
        $capturedSql = null;
        $capturedBindings = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function (string $sql) use (&$capturedSql): bool {
                    $capturedSql = $sql;
                    return true;
                }),
                $this->callback(function (array $bindings) use (&$capturedBindings): bool {
                    $capturedBindings = $bindings;
                    return true;
                })
            )
            ->willReturn([]);

        $repo = new ItemRepository($db);
        $result = $repo->getByAllowedGenres('lib-1', [], 50, 10);

        $this->assertSame([], $result);
        $this->assertIsString($capturedSql);
        $this->assertStringNotContainsString('EXISTS', $capturedSql);
        $this->assertStringContainsString('WHERE library_id = ?', $capturedSql);
        $this->assertSame(['lib-1', 50, 10], $capturedBindings);
    }

    public function testGetByAllowedGenresBuildsExistsOrNotExistsQueryWithExactBindingOrder(): void
    {
        $capturedSql = null;
        $capturedBindings = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function (string $sql) use (&$capturedSql): bool {
                    $capturedSql = $sql;
                    return true;
                }),
                $this->callback(function (array $bindings) use (&$capturedBindings): bool {
                    $capturedBindings = $bindings;
                    return true;
                })
            )
            ->willReturn([]);

        $repo = new ItemRepository($db);
        $repo->getByAllowedGenres('lib-1', ['Action', 'Drama'], 50, 10);

        $this->assertIsString($capturedSql);
        $this->assertStringContainsString('WHERE library_id = ?', $capturedSql);
        $this->assertStringContainsString('EXISTS (', $capturedSql);
        $this->assertStringContainsString('FROM media_item_genres mig', $capturedSql);
        $this->assertStringContainsString('mig.media_item_id = media_items.id', $capturedSql);
        // Exactly 2 placeholders for the 2 allowed genres.
        $this->assertStringContainsString('mig.genre IN (?,?)', $capturedSql);
        // The "or has no genres at all" fallback — a SEPARATE join-table
        // reference (mig2), never re-using mig's row scope.
        $this->assertStringContainsString('OR NOT EXISTS (', $capturedSql);
        $this->assertStringContainsString('FROM media_item_genres mig2', $capturedSql);
        $this->assertStringContainsString('mig2.media_item_id = media_items.id', $capturedSql);
        $this->assertStringContainsString('ORDER BY sort_title ASC, name ASC', $capturedSql);
        $this->assertStringContainsString('LIMIT ? OFFSET ?', $capturedSql);

        // Exact binding order: libraryId, then each allowed genre (in the
        // caller-supplied order), then limit, then offset.
        $this->assertSame(['lib-1', 'Action', 'Drama', 50, 10], $capturedBindings);
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

    public function testDistinctGenresJoinsMediaItemGenresTableAndShapesRows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function (string $sql): bool {
                    // Set-based read of the media_item_genres join table
                    // (migration 051) — no unbounded SELECT * scan into PHP —
                    // DISTINCT + ORDER done server-side, same as the pre-051
                    // JSON_TABLE unnest it replaced. `COLLATE utf8mb4_unicode_ci`
                    // is re-asserted on the selected genre value so this
                    // case-insensitive facet DISTINCT stays independent of the
                    // column's storage collation (utf8mb4_bin, adopted for the
                    // exact-match filter predicates — see the method's docblock).
                    return str_contains($sql, 'FROM media_item_genres mig')
                        && str_contains($sql, 'JOIN media_items mi')
                        && str_contains($sql, 'SELECT DISTINCT mig.genre COLLATE utf8mb4_unicode_ci')
                        && str_contains($sql, 'ORDER BY genre ASC')
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
     * in-worker cache — the media_item_genres join-table read (migration 051) runs exactly once.
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
        $distinctGenresQueryCalls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $bindings = []) use (&$distinctGenresQueryCalls): array {
                if (str_contains($sql, 'FROM media_item_genres mig')) {
                    $distinctGenresQueryCalls++;
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

        $this->assertSame(3, $distinctGenresQueryCalls, 'one media_item_genres read per distinct scope, no repeats');
    }

    /**
     * The public invalidate hook drops the scope so the next call recomputes.
     */
    public function testInvalidateGenreFacetsForcesRecompute(): void
    {
        $distinctGenresQueryCalls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$distinctGenresQueryCalls): array {
                if (str_contains($sql, 'FROM media_item_genres mig')) {
                    $distinctGenresQueryCalls++;
                }
                return [['genre' => 'Action']];
            }
        );

        $repo = new ItemRepository($db);

        $repo->distinctGenres('lib-A');            // compute #1
        $repo->distinctGenres('lib-A');            // cached
        $repo->invalidateGenreFacets('lib-A');     // drop scope
        $repo->distinctGenres('lib-A');            // compute #2

        $this->assertSame(2, $distinctGenresQueryCalls);
    }

    /**
     * Invalidating a library scope also drops the all-libraries (global) scope,
     * since that set spans the library.
     */
    public function testInvalidateLibraryScopeAlsoDropsGlobalScope(): void
    {
        $distinctGenresQueryCalls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$distinctGenresQueryCalls): array {
                if (str_contains($sql, 'FROM media_item_genres mig')) {
                    $distinctGenresQueryCalls++;
                }
                return [['genre' => 'Action']];
            }
        );

        $repo = new ItemRepository($db);

        $repo->distinctGenres();                   // global compute #1
        $repo->distinctGenres();                   // cached
        $repo->invalidateGenreFacets('lib-A');     // library write → also flush global
        $repo->distinctGenres();                   // global compute #2

        $this->assertSame(2, $distinctGenresQueryCalls);
    }

    /**
     * A flush with no library (null) clears every cached scope.
     */
    public function testInvalidateGenreFacetsNullFlushesAllScopes(): void
    {
        $distinctGenresQueryCalls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$distinctGenresQueryCalls): array {
                if (str_contains($sql, 'FROM media_item_genres mig')) {
                    $distinctGenresQueryCalls++;
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

        $this->assertSame(4, $distinctGenresQueryCalls);
    }

    /**
     * create() invalidates the affected library's facets (a new item can add a
     * genre), so the next lookup recomputes.
     */
    public function testCreateInvalidatesGenreFacetsForItsLibrary(): void
    {
        $distinctGenresQueryCalls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$distinctGenresQueryCalls): array {
                if (str_contains($sql, 'FROM media_item_genres mig')) {
                    $distinctGenresQueryCalls++;
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

        $this->assertSame(2, $distinctGenresQueryCalls);
    }

    /**
     * update() invalidates facets only when metadata_json (where genres live) is
     * written; a non-metadata update leaves the cache warm.
     */
    public function testUpdateInvalidatesGenreFacetsOnlyWhenMetadataChanges(): void
    {
        $distinctGenresQueryCalls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$distinctGenresQueryCalls): array {
                if (str_contains($sql, 'FROM media_item_genres mig')) {
                    $distinctGenresQueryCalls++;
                }
                return [['genre' => 'Action']];
            }
        );

        $repo = new ItemRepository($db);

        // Non-metadata update does NOT invalidate.
        $repo->distinctGenres('lib-A');            // compute #1
        $repo->update('item-1', ['name' => 'Renamed']);
        $repo->distinctGenres('lib-A');            // still cached → no recompute
        $this->assertSame(1, $distinctGenresQueryCalls);

        // A metadata_json rewrite flushes all scopes.
        $repo->update('item-1', ['metadata_json' => ['genres' => ['Sci-Fi']]]);
        $repo->distinctGenres('lib-A');            // compute #2
        $this->assertSame(2, $distinctGenresQueryCalls);
    }

    /**
     * delete() with no StatsCollector cannot resolve the owning library, so it
     * flushes all scopes to stay correct.
     */
    public function testDeleteInvalidatesGenreFacets(): void
    {
        $distinctGenresQueryCalls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$distinctGenresQueryCalls): array {
                if (str_contains($sql, 'FROM media_item_genres mig')) {
                    $distinctGenresQueryCalls++;
                }
                return [['genre' => 'Action']];
            }
        );

        $repo = new ItemRepository($db); // no StatsCollector

        $repo->distinctGenres('lib-A');            // compute #1
        $repo->distinctGenres('lib-A');            // cached
        $repo->delete('item-1');
        $repo->distinctGenres('lib-A');            // compute #2

        $this->assertSame(2, $distinctGenresQueryCalls);
    }

    /**
     * deleteByLibrary() empties a library's genre set, so its scope (and the
     * global scope) recompute afterward.
     */
    public function testDeleteByLibraryInvalidatesGenreFacets(): void
    {
        $distinctGenresQueryCalls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$distinctGenresQueryCalls): array {
                if (str_contains($sql, 'FROM media_item_genres mig')) {
                    $distinctGenresQueryCalls++;
                }
                return [['genre' => 'Action']];
            }
        );

        $repo = new ItemRepository($db);

        $repo->distinctGenres('lib-A');            // compute #1
        $repo->distinctGenres('lib-A');            // cached
        $repo->deleteByLibrary('lib-A');
        $repo->distinctGenres('lib-A');            // compute #2

        $this->assertSame(2, $distinctGenresQueryCalls);
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
        $cache = is_array($cache) ? $cache : [];
        $entry = is_array($cache['lib-A'] ?? null) ? $cache['lib-A'] : [];
        $entry['expires_at'] = 0;
        $cache['lib-A'] = $entry;
        $cacheProp->setValue($repo, $cache);

        $repo->distinctGenres('lib-A'); // stale → recompute

        $after = $cacheProp->getValue($repo);
        $keysAfter = array_keys(is_array($after) ? $after : []);
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

        $maxConst = (new \ReflectionClassConstant(
            ItemRepository::class,
            'GENRE_FACET_CACHE_MAX'
        ))->getValue();
        $max = is_int($maxConst) ? $maxConst : 0;

        // Fill exactly to the bound (scopes lib-0 .. lib-(max-1)) — no eviction yet.
        for ($i = 0; $i < $max; $i++) {
            $repo->distinctGenres('lib-' . $i);
        }
        $cacheProp = new \ReflectionProperty(ItemRepository::class, 'genreFacetCache');
        $cacheNow = $cacheProp->getValue($repo);
        $this->assertCount($max, is_array($cacheNow) ? $cacheNow : [], 'at the bound, nothing evicted yet');

        // Touch the oldest scope so it becomes MRU (LRU-hot), making lib-1 the
        // new oldest.
        $repo->distinctGenres('lib-0');

        // One more distinct scope overflows the bound → the coldest scope (lib-1)
        // is evicted, not the just-touched lib-0.
        $repo->distinctGenres('lib-' . $max);

        $cacheKeys = $cacheProp->getValue($repo);
        $keys = array_keys(is_array($cacheKeys) ? $cacheKeys : []);
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
                    return in_array('lib-year-test', $params, true)
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

        $this->assertSame([], $result);
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
                    return in_array('lib-multi', $params, true)
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

    public function testUpsertByPathReturnsExistingIdWithoutInserting(): void
    {
        // When the path is already indexed, upsertByPath must return that row's
        // id and issue NO INSERT (no duplicate, no side effects).
        $insertSeen = false;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql) use (&$insertSeen) {
            if (str_starts_with(trim($sql), 'INSERT INTO media_items')) {
                $insertSeen = true;
            }
            // The query now uses path_hash = SHA1(?) AND path = ? for index optimization
            if (str_contains($sql, 'path_hash') && str_contains($sql, 'FROM media_items')) {
                return [['id' => 'existing-1', 'metadata_json' => '{}']];
            }
            return [];
        });

        $repo = new ItemRepository($db);
        $id = $repo->upsertByPath([
            'library_id' => 'lib-1',
            'name'       => 'Ep',
            'type'       => 'episode',
            'path'       => '/m/ep.mkv',
        ]);

        $this->assertSame('existing-1', $id);
        $this->assertFalse($insertSeen, 'no INSERT should be issued when the path already exists');
    }

    public function testUpsertByPathCreatesWhenAbsentAndReturnsNewId(): void
    {
        // Path not found → delegate to create(), which performs the INSERT and
        // returns the id. We supply an explicit id so the return is deterministic.
        $insertSeen = false;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql) use (&$insertSeen) {
            if (str_starts_with(trim($sql), 'INSERT INTO media_items')) {
                $insertSeen = true;
            }
            // Every SELECT (including the path pre-check) finds nothing.
            return [];
        });

        $repo = new ItemRepository($db);
        $id = $repo->upsertByPath([
            'id'         => 'new-1',
            'library_id' => 'lib-1',
            'name'       => 'Ep',
            'type'       => 'episode',
            'path'       => '/m/new.mkv',
        ]);

        $this->assertSame('new-1', $id);
        $this->assertTrue($insertSeen, 'a new path must be inserted via create()');
    }

    public function testUpsertByPathReusesRacedRowWhenInsertViolatesUnique(): void
    {
        // Pre-check finds nothing; a concurrent worker inserts the same path, so
        // create()'s INSERT raises a unique violation; the re-fetch then finds
        // the winner's row and upsertByPath returns its id (no exception).
        //
        // Note: with the path_hash optimization, the findByPath query now uses
        // "WHERE path_hash = ? AND path = ?" which the mock matches via path_hash.
        $pathSelects = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql) use (&$pathSelects) {
            // The new query uses path_hash in the WHERE clause
            if (str_contains($sql, 'path_hash')) {
                $pathSelects++;
                // First call (pre-check) misses; second call (post-race) hits.
                return $pathSelects === 1 ? [] : [['id' => 'winner-1', 'metadata_json' => '{}']];
            }
            if (str_starts_with(trim($sql), 'INSERT INTO media_items')) {
                throw new \RuntimeException("Duplicate entry for key 'idx_media_items_library_path_hash'");
            }
            return [];
        });

        $repo = new ItemRepository($db);
        $id = $repo->upsertByPath([
            'library_id' => 'lib-1',
            'name'       => 'Ep',
            'type'       => 'episode',
            'path'       => '/m/race.mkv',
        ]);

        $this->assertSame('winner-1', $id);
        $this->assertSame(2, $pathSelects, 'pre-check + one post-race re-fetch');
    }

    public function testUpsertByPathRethrowsWhenInsertFailsAndRowStillMissing(): void
    {
        // INSERT fails for a non-race reason and no row appears on re-fetch, so
        // the original error must surface rather than be swallowed.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql) {
            if (str_starts_with(trim($sql), 'INSERT INTO media_items')) {
                throw new \RuntimeException('Incorrect string value');
            }
            return [];
        });

        $repo = new ItemRepository($db);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Incorrect string value');
        $repo->upsertByPath([
            'library_id' => 'lib-1',
            'name'       => 'Ep',
            'type'       => 'episode',
            'path'       => '/m/broken.mkv',
        ]);
    }
}
