<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\PathDeduper;
use Workerman\MySQL\Connection;

class PathDeduperTest extends TestCase
{
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
            if (str_contains($sql, 'SELECT rating FROM metadata_ratings')) {
                return [['rating' => 7.5]];
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
