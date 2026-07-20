<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\PathDeduper;
use Workerman\MySQL\Connection;

class PathDeduperTest extends TestCase
{
    /**
     * The deduper's scope and the `path_hash` generated column's CASE define
     * the SAME set of rows — the column decides which rows the unique index
     * constrains, the deduper decides which rows the cleanup will merge. A
     * type in one but not the other either leaves rows unprotected or trips
     * the index on a type nothing will clean up.
     *
     * This reads the live migration SQL rather than restating the list, so an
     * edit to either side that forgets the other fails here.
     */
    public function testDedupedTypesMatchThePathHashGeneratedColumn(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../../../migrations/087_path_hash_include_track_audiobook.sql');
        $this->assertIsString($sql);

        // The CASE's `type IN (...)` list, ignoring the commented-out prose.
        $statement = substr($sql, (int) strpos($sql, 'ALTER TABLE media_items'));
        $matched = preg_match("/type IN \(([^)]*)\)/", $statement, $m);
        $this->assertSame(1, $matched, 'Could not locate the path_hash type list in migration 087.');

        $migrationTypes = [];
        foreach (explode(',', $m[1]) as $part) {
            $migrationTypes[] = trim(trim($part), "'");
        }
        sort($migrationTypes);

        $codeTypes = PathDeduper::DEDUPED_TYPES;
        sort($codeTypes);

        $this->assertSame(
            $migrationTypes,
            $codeTypes,
            'PathDeduper::DEDUPED_TYPES and migration 087\'s path_hash CASE must stay in lockstep.'
        );
    }

    public function testFindDuplicateGroupsScopesQueryToTheDedupedTypes(): void
    {
        $capturedSql = '';
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;
                return [];
            }
        );

        (new PathDeduper($db))->findDuplicateGroups();

        foreach (PathDeduper::DEDUPED_TYPES as $type) {
            $this->assertStringContainsString("'" . $type . "'", $capturedSql);
        }

        // Containers carry synthetic paths that legitimately repeat.
        $this->assertStringNotContainsString("'series'", $capturedSql);
        $this->assertStringNotContainsString("'season'", $capturedSql);
    }

    /**
     * `track` is the most exposed type of all: MusicLibraryManager persists
     * tracks via ItemRepository::create(), NOT upsertByPath(), so nothing
     * dedupes music on the insert path.
     */
    public function testFindDuplicateGroupsDetectsDuplicateTracks(): void
    {
        $rows = [
            ['path' => '/m/s.flac', 'library_id' => 'lib-1', 'library_name' => 'Music', 'id' => 't1', 'name' => 'S', 'type' => 'track', 'created_at' => '2026-01-01'],
            ['path' => '/m/s.flac', 'library_id' => 'lib-1', 'library_name' => 'Music', 'id' => 't2', 'name' => 'S', 'type' => 'track', 'created_at' => '2026-01-02'],
        ];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn($rows);

        $groups = (new PathDeduper($db))->findDuplicateGroups();

        $this->assertCount(1, $groups);
        $this->assertSame(['t1', 't2'], array_column($groups[0]['items'], 'id'));
    }

    public function testFindDuplicateGroupsDetectsDuplicateAudiobooks(): void
    {
        $rows = [
            ['path' => '/a/b.m4b', 'library_id' => 'lib-2', 'library_name' => 'Books', 'id' => 'ab1', 'name' => 'B', 'type' => 'audiobook', 'created_at' => '2026-01-01'],
            ['path' => '/a/b.m4b', 'library_id' => 'lib-2', 'library_name' => 'Books', 'id' => 'ab2', 'name' => 'B', 'type' => 'audiobook', 'created_at' => '2026-01-02'],
        ];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn($rows);

        $groups = (new PathDeduper($db))->findDuplicateGroups();

        $this->assertCount(1, $groups);
        $this->assertSame(['ab1', 'ab2'], array_column($groups[0]['items'], 'id'));
    }

    public function testFindDuplicateGroupsKeepsOnlyGroupsWithTwoOrMoreItems(): void
    {
        // Two rows share (library_id, path); a third is unique. Only the shared
        // pair should be returned as a duplicate group.
        $rows = [
            ['path' => '/m/a.mkv', 'library_id' => 'lib-1', 'library_name' => 'TV', 'id' => 'a1', 'name' => 'A', 'type' => 'episode', 'created_at' => '2026-01-01'],
            ['path' => '/m/a.mkv', 'library_id' => 'lib-1', 'library_name' => 'TV', 'id' => 'a2', 'name' => 'A', 'type' => 'episode', 'created_at' => '2026-01-02'],
            ['path' => '/m/b.mkv', 'library_id' => 'lib-1', 'library_name' => 'TV', 'id' => 'b1', 'name' => 'B', 'type' => 'movie', 'created_at' => '2026-01-03'],
        ];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn($rows);

        $groups = (new PathDeduper($db))->findDuplicateGroups();

        $this->assertCount(1, $groups);
        $this->assertSame('/m/a.mkv', $groups[0]['path']);
        $this->assertCount(2, $groups[0]['items']);
        $this->assertSame(['a1', 'a2'], array_column($groups[0]['items'], 'id'));
    }

    public function testScoreItemSumsUserDataSignals(): void
    {
        // watch(2)*1 + playback(5) + no user_item_data(0) + markers>0(3)
        //   + votes>0(2) + rating floor(7.5)=7  => 19
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'FROM watch_history')) {
                return [['cnt' => 2]];
            }
            if (str_contains($sql, 'FROM playback_state')) {
                return [[1]]; // exists
            }
            if (str_contains($sql, 'FROM user_item_data')) {
                return []; // absent
            }
            if (str_contains($sql, 'FROM media_markers')) {
                return [['cnt' => 1]];
            }
            if (str_contains($sql, 'SUM(votes)')) {
                return [['votes' => 5]];
            }
            if (str_contains($sql, 'SELECT score FROM metadata_ratings')) {
                return [['score' => 7.5]];
            }
            return [];
        });

        $this->assertSame(19, (new PathDeduper($db))->scoreItem('id-1'));
    }

    public function testRepointIsCollisionSafeAndCoversEveryReferenceColumn(): void
    {
        // Capture every statement so we can assert the collision-safe shape:
        // UPDATE IGNORE + a follow-up DELETE of the loser's leftovers, on all
        // three reference columns, plus the self-referential item_similar sweep.
        $seen = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql) use (&$seen) {
            $seen[] = preg_replace('/\s+/', ' ', trim($sql));
            return 1;
        });

        (new PathDeduper($db))->repointReferencingTables('loser', 'keeper');

        $has = static function (string $needle) use ($seen): bool {
            foreach ($seen as $sql) {
                if (str_contains($sql, $needle)) {
                    return true;
                }
            }
            return false;
        };

        // media_item_id column: move then delete leftovers.
        $this->assertTrue($has('UPDATE IGNORE media_item_genres SET media_item_id = ?'));
        $this->assertTrue($has('DELETE FROM media_item_genres WHERE media_item_id = ?'));
        // Both item_similar columns are repointed.
        $this->assertTrue($has('UPDATE IGNORE item_similar SET media_item_id = ?'));
        $this->assertTrue($has('UPDATE IGNORE item_similar SET similar_item_id = ?'));
        // Self-referential rows created by the repoint are swept.
        $this->assertTrue($has('DELETE FROM item_similar WHERE media_item_id = similar_item_id'));
        // user_item_data uses item_id, not media_item_id.
        $this->assertTrue($has('UPDATE IGNORE user_item_data SET item_id = ?'));
        $this->assertTrue($has('DELETE FROM user_item_data WHERE item_id = ?'));

        // A plain (collision-unsafe) UPDATE must never be issued.
        foreach ($seen as $sql) {
            $this->assertStringNotContainsString('UPDATE media_item_genres SET', $sql);
        }
    }

    public function testDeleteItemReturnsFalseOnQueryFailure(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(false);

        $this->assertFalse((new PathDeduper($db))->deleteItem('id-1'));
    }
}
