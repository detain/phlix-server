<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session;

use Phlix\Session\PlaybackStateDeduper;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * Deterministic unit coverage for {@see PlaybackStateDeduper}'s pure-PHP control
 * flow and its DEFENSIVE branches — the ones a healthy real MySQL never takes and
 * so the {@see \Phlix\Tests\Integration\Session\PlaybackStateDeduperIntegrationTest}
 * cannot reach: non-array query results, a keeper lookup that returns nothing, a
 * per-group DELETE that throws (skipped, not fatal), the no-progress break, and
 * the `addUniqueKey()` / `hasUniqueKey()` error handling.
 *
 * The mocked {@see Connection} branches its `query()` return on the SQL shape, so
 * each scenario drives exactly one code path. Real-DB row-count-safety, batching,
 * keeper selection (max updated_at / tie-break max id) and the post-key upsert
 * behaviour are proven in the integration test — not re-mocked here.
 *
 * @covers \Phlix\Session\PlaybackStateDeduper
 */
final class PlaybackStateDeduperTest extends TestCase
{
    public function testFindDuplicateGroupsReturnsEmptyWhenQueryYieldsNonArray(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(null);

        $deduper = new PlaybackStateDeduper($db);

        $this->assertSame([], $deduper->findDuplicateGroups());
    }

    public function testFindDuplicateGroupsShapesRowsAndSkipsNonArrayRows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['session_id' => 's1', 'media_item_id' => 'm1', 'cnt' => '3'],
            'not-a-row', // must be skipped, not fatal
            ['session_id' => 's2', 'media_item_id' => 'm2', 'cnt' => 2],
        ]);

        $groups = (new PlaybackStateDeduper($db))->findDuplicateGroups(10);

        $this->assertCount(2, $groups);
        $this->assertSame(['session_id' => 's1', 'media_item_id' => 'm1', 'cnt' => 3], $groups[0]);
        $this->assertSame(3, $groups[0]['cnt']); // asInt() coerced the '3' string
        $this->assertSame(2, $groups[1]['cnt']);
    }

    public function testFindDuplicateGroupsClampsLimitToAtLeastOne(): void
    {
        $captured = null;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;

            return [];
        });

        (new PlaybackStateDeduper($db))->findDuplicateGroups(-5);

        $this->assertIsString($captured);
        $this->assertStringContainsString('LIMIT 1', $captured);
    }

    public function testDedupeGroupReturnsZeroWhenKeeperLookupFindsNothing(): void
    {
        // The keeper SELECT returns no usable row → group vanished → no DELETE.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            fn (string $sql) => str_contains($sql, 'ORDER BY updated_at DESC') ? [] : 0,
        );

        $deleted = (new PlaybackStateDeduper($db))->dedupeGroup('s1', 'm1');

        $this->assertSame(0, $deleted);
    }

    public function testDedupeGroupReturnsZeroWhenKeeperRowHasEmptyId(): void
    {
        // Row present but id blank → findKeeperId() returns null path.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            fn (string $sql) => str_contains($sql, 'ORDER BY updated_at DESC') ? [['id' => '']] : 0,
        );

        $this->assertSame(0, (new PlaybackStateDeduper($db))->dedupeGroup('s1', 'm1'));
    }

    public function testDedupeGroupDeletesLosersAndReturnsAffectedCount(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'ORDER BY updated_at DESC')) {
                return [['id' => 'keeper-id']];
            }
            if (str_contains($sql, 'DELETE FROM playback_state')) {
                return 4; // affected-row count
            }

            return null;
        });

        $this->assertSame(4, (new PlaybackStateDeduper($db))->dedupeGroup('s1', 'm1'));
    }

    public function testDedupeAllStopsOnNoProgressWhenGroupsCannotBeResolved(): void
    {
        // A duplicate group is reported forever, but its keeper lookup never
        // resolves → deletedThisIteration stays 0 → the loop breaks instead of
        // spinning (the no-progress guard).
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'GROUP BY session_id')) {
                return [['session_id' => 's1', 'media_item_id' => 'm1', 'cnt' => 5]];
            }
            if (str_contains($sql, 'ORDER BY updated_at DESC')) {
                return []; // keeper never found → dedupeGroup returns 0
            }

            return 0;
        });

        $result = (new PlaybackStateDeduper($db))->dedupeAll();

        $this->assertSame(1, $result['groups']);
        $this->assertSame(0, $result['deleted']);
        $this->assertSame(1, $result['iterations']);
        $this->assertSame(0, $result['skipped']);
    }

    public function testDedupeAllSkipsGroupsWhoseDeleteThrows(): void
    {
        // The keeper lookup throws → dedupeGroup throws → dedupeAll catches it,
        // counts it as skipped, and the no-progress break ends the pass.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'GROUP BY session_id')) {
                return [['session_id' => 's1', 'media_item_id' => 'm1', 'cnt' => 5]];
            }
            if (str_contains($sql, 'ORDER BY updated_at DESC')) {
                throw new RuntimeException('boom');
            }

            return 0;
        });

        $result = (new PlaybackStateDeduper($db))->dedupeAll();

        $this->assertSame(0, $result['groups']);
        $this->assertSame(0, $result['deleted']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $result['iterations']);
    }

    public function testDedupeAllReturnsImmediatelyWhenNoDuplicates(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]); // no duplicate groups

        $result = (new PlaybackStateDeduper($db))->dedupeAll();

        $this->assertSame(
            ['groups' => 0, 'deleted' => 0, 'iterations' => 0, 'skipped' => 0],
            $result,
        );
    }

    public function testAddUniqueKeyReturnsTrueOnSuccessfulAlter(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with($this->stringContains('ADD UNIQUE KEY ' . PlaybackStateDeduper::UNIQUE_KEY_NAME))
            ->willReturn(null);

        $this->assertTrue((new PlaybackStateDeduper($db))->addUniqueKey());
    }

    public function testAddUniqueKeyReturnsFalseWhenKeyAlreadyExists(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(
            new RuntimeException("Duplicate key name 'uq_playback_state_session_media'"),
        );

        $this->assertFalse((new PlaybackStateDeduper($db))->addUniqueKey());
    }

    public function testAddUniqueKeyRethrowsWhenDuplicatesStillRemain(): void
    {
        // A lingering 1062 (duplicates not merged) must NOT be swallowed — the
        // operator has to see it and re-run cleanup_090.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(
            new RuntimeException("Duplicate entry 's1-m1' for key 'uq_playback_state_session_media'"),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate entry');

        (new PlaybackStateDeduper($db))->addUniqueKey();
    }

    public function testHasUniqueKeyTrueWhenIndexRowPresent(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['Key_name' => PlaybackStateDeduper::UNIQUE_KEY_NAME]]);

        $this->assertTrue((new PlaybackStateDeduper($db))->hasUniqueKey());
    }

    public function testHasUniqueKeyFalseWhenNoIndexRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $this->assertFalse((new PlaybackStateDeduper($db))->hasUniqueKey());
    }

    public function testHasUniqueKeyFalseWhenQueryThrows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(new RuntimeException('connection lost'));

        $this->assertFalse((new PlaybackStateDeduper($db))->hasUniqueKey());
    }
}
