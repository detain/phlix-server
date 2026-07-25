<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Stats;

use DateTime;
use PDOException;
use PHPUnit\Framework\TestCase;
use Phlix\Media\MediaItemType;
use Phlix\Stats\StatsCollector;
use RuntimeException;
use Workerman\MySQL\Connection;

class StatsCollectorTest extends TestCase
{
    public function testRecordPlaybackStartCreatesEvent(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO stats_playback_events'),
                $this->callback(function ($params) {
                    return count($params) === 6
                        && $params[0] !== '' // event id
                        && $params[1] === 'user-123'
                        && $params[2] === 'media-456'
                        && $params[3] === 'movie'
                        && $params[4] === 'device-789'
                        && $params[5] === null; // client_ip
                })
            );

        $collector = new StatsCollector($db);
        $eventId = $collector->recordPlaybackStart('user-123', 'media-456', 'movie', 'device-789');

        $this->assertNotEmpty($eventId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{4}[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}[0-9a-f]{4}[0-9a-f]{4}$/',
            $eventId
        );
    }

    /**
     * S102: the raw `media_items.type` value is bound VERBATIM — the column now
     * carries all 13 members (migration 094), so nothing folds `episode` into
     * `series`. This pins the SQL shape for runs with no database; the real
     * INSERT is proven in
     * {@see \Phlix\Tests\Integration\Stats\PlaybackEventMediaTypeEnumTest}.
     */
    public function testRecordPlaybackStartBindsEveryMediaTypeVerbatim(): void
    {
        foreach (MediaItemType::ALL as $type) {
            $db = $this->createMock(Connection::class);
            $db->expects($this->once())
                ->method('query')
                ->with(
                    $this->stringContains('INSERT INTO stats_playback_events'),
                    $this->callback(static fn(array $params): bool => $params[3] === $type)
                );

            (new StatsCollector($db))->recordPlaybackStart('user-1', 'media-1', $type, 'device-1');
        }
    }

    /**
     * S102: a value the column ENUM does not contain would be MySQL error 1265,
     * so it is coerced to the shared fallback instead of losing the event.
     * `image` is the classic wrong value here — a scanner label that has never
     * been a `media_items.type` member.
     */
    public function testRecordPlaybackStartCoercesATypeOutsideTheEnum(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO stats_playback_events'),
                $this->callback(static fn(array $params): bool => $params[3] === MediaItemType::FALLBACK)
            );

        (new StatsCollector($db))->recordPlaybackStart('user-1', 'media-1', 'image', 'device-1');
    }

    /**
     * S102 — THE BOUNDARY. Recording statistics is telemetry: it must never be
     * able to break the user action that triggered it. Before the fix the
     * driver's exception escaped `recordPlaybackStart()` and, since
     * `PlaybackController::dispatchPlaybackStarted()` has no try/catch, escaped
     * the HTTP worker as well — a 500 on every episode play.
     *
     * @return array<string, array{0: \Throwable}>
     */
    public static function writeFailureProvider(): array
    {
        return [
            'error 1265 (the S102 production failure)' => [
                new PDOException("SQLSTATE[01000]: Warning: 1265 Data truncated for column 'media_type' at row 1"),
            ],
            'connection lost' => [new RuntimeException('MySQL server has gone away')],
            'engine-level error' => [new \Error('driver blew up')],
        ];
    }

    /**
     * @dataProvider writeFailureProvider
     */
    public function testAFailingWriteIsContainedAndNeverPropagates(\Throwable $failure): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException($failure);

        $collector = new StatsCollector($db);

        // Every WRITE path has to be contained, not just the one that broke.
        $eventId = $collector->recordPlaybackStart('user-1', 'media-1', 'episode', 'device-1');
        $this->assertNotSame('', $eventId, 'A contained write must still return a usable event id');

        $collector->recordPlaybackEnd($eventId, 60, true);
        $collector->recordLibraryChange('item_added', 'media-1');
        $collector->recordUserActivity('user-1', 'login');
        $collector->recordStorageSnapshot('movie', 1, 1);

        // Reaching here at all IS the assertion: nothing propagated.
        $this->assertTrue(true);
    }

    /**
     * The boundary is deliberately narrow: READ failures still surface, because
     * they serve the admin dashboard, where a broken query must be a visible
     * error rather than a silently empty chart.
     */
    public function testReadFailuresStillPropagate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(new RuntimeException('read exploded'));

        $collector = new StatsCollector($db);

        $this->expectException(RuntimeException::class);
        $collector->getTopMedia(10, null);
    }

    /**
     * S102: `stats_storage.media_type` stays COARSE (it has a real reader in
     * `DashboardService::getStorageSummary()`), so the writer folds whatever it
     * is handed onto a bucket. The fold is idempotent, so the two existing
     * callers — which already pass bucket names — are unaffected.
     */
    public function testRecordStorageSnapshotFoldsRawTypesToBuckets(): void
    {
        foreach (['episode' => 'series', 'track' => 'music', 'audiobook' => 'book', 'movie' => 'movie'] as $t => $b) {
            $db = $this->createMock(Connection::class);
            $db->expects($this->once())
                ->method('query')
                ->with(
                    $this->stringContains('INSERT INTO stats_storage'),
                    $this->callback(static fn(array $params): bool => $params[2] === $b)
                );

            (new StatsCollector($db))->recordStorageSnapshot($t, 1, 1024);
        }
    }

    public function testRecordPlaybackEndCalculatesDuration(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE stats_playback_events'),
                $this->callback(function ($params) {
                    return count($params) === 3
                        && $params[0] === 3600 // duration_seconds
                        && $params[1] === true // completed
                        && $params[2] === 'event-123'; // eventId
                })
            );

        $collector = new StatsCollector($db);
        $collector->recordPlaybackEnd('event-123', 3600, true);
    }

    public function testRecordLibraryChangeStoresChange(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO stats_library_changes'),
                $this->callback(function ($params) {
                    return count($params) === 6
                        && $params[0] !== '' // id
                        && $params[1] === 'item_added'
                        && $params[2] === 'media-456'
                        && $params[3] === 'lib-123'
                        && $params[4] === 'user-789'
                        && $params[5] !== null; // details_json
                })
            );

        $collector = new StatsCollector($db);
        $collector->recordLibraryChange('item_added', 'media-456', 'lib-123', 'user-789', ['path' => '/movies/test.mkv']);
    }

    public function testRecordUserActivityStoresActivity(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO stats_user_activity'),
                $this->callback(function ($params) {
                    return count($params) === 6
                        && $params[0] !== '' // id
                        && $params[1] === 'user-123'
                        && $params[2] === 'login'
                        && $params[3] === '192.168.1.1'
                        && $params[4] === null // user_agent
                        && $params[5] !== null; // details_json
                })
            );

        $collector = new StatsCollector($db);
        $collector->recordUserActivity('user-123', 'login', '192.168.1.1', ['device' => 'Chrome']);
    }

    public function testGetTopUsersAggregatesWatchTime(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'user_id' => 'user-123',
                'total_watch_time' => '36000',
                'play_count' => '10',
            ],
            [
                'user_id' => 'user-456',
                'total_watch_time' => '18000',
                'play_count' => '5',
            ],
        ]);

        $collector = new StatsCollector($db);
        $topUsers = $collector->getTopUsers(10, null);

        $this->assertCount(2, $topUsers);
        $this->assertEquals('user-123', $topUsers[0]['user_id']);
        $this->assertEquals(36000, $topUsers[0]['total_watch_time']);
        $this->assertEquals(10, $topUsers[0]['play_count']);
        $this->assertEquals('user-456', $topUsers[1]['user_id']);
        $this->assertEquals(18000, $topUsers[1]['total_watch_time']);
        $this->assertEquals(5, $topUsers[1]['play_count']);
    }

    public function testGetTopMediaAggregatesPlayCount(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'media_item_id' => 'media-001',
                'play_count' => '25',
                'total_duration' => '90000',
            ],
            [
                'media_item_id' => 'media-002',
                'play_count' => '15',
                'total_duration' => '45000',
            ],
        ]);

        $collector = new StatsCollector($db);
        $topMedia = $collector->getTopMedia(10, null);

        $this->assertCount(2, $topMedia);
        $this->assertEquals('media-001', $topMedia[0]['media_item_id']);
        $this->assertEquals(25, $topMedia[0]['play_count']);
        $this->assertEquals(90000, $topMedia[0]['total_duration']);
        $this->assertEquals('media-002', $topMedia[1]['media_item_id']);
        $this->assertEquals(15, $topMedia[1]['play_count']);
        $this->assertEquals(45000, $topMedia[1]['total_duration']);
    }

    public function testGetTopUsersInnerJoinsUsersToExcludeOrphans(): void
    {
        // S14: orphan exclusion is enforced at the query level — getTopUsers()
        // INNER JOINs `users` so playback events belonging to a since-deleted
        // account are dropped before aggregation and can never surface as a
        // blank "Top Users" row. Assert the JOIN is present and the aggregates
        // are unchanged (the PK join is 1:1, so no COUNT/SUM fan-out).
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('INNER JOIN users u ON e.user_id = u.id'),
                    $this->stringContains('COALESCE(SUM(e.duration_seconds), 0) AS total_watch_time'),
                    $this->stringContains('COUNT(*) AS play_count')
                ),
                $this->anything()
            )
            ->willReturn([
                [
                    'user_id' => 'user-live',
                    'total_watch_time' => '36000',
                    'play_count' => '10',
                ],
            ]);

        $collector = new StatsCollector($db);
        $topUsers = $collector->getTopUsers(10, null);

        // Regression: the surviving row's aggregates pass through unchanged by
        // the join (no double-count).
        $this->assertCount(1, $topUsers);
        $this->assertSame('user-live', $topUsers[0]['user_id']);
        $this->assertSame(36000, $topUsers[0]['total_watch_time']);
        $this->assertSame(10, $topUsers[0]['play_count']);
    }

    public function testGetTopMediaInnerJoinsMediaItemsToExcludeOrphans(): void
    {
        // S14: orphan exclusion is enforced at the query level — getTopMedia()
        // INNER JOINs `media_items` so plays of a since-deleted item are dropped
        // before aggregation and can never surface as a blank / no-title row.
        // Assert the JOIN is present and the aggregates are unchanged (the PK
        // join is 1:1, so no COUNT/SUM fan-out).
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('INNER JOIN media_items mi ON e.media_item_id = mi.id'),
                    $this->stringContains('COUNT(*) AS play_count'),
                    $this->stringContains('COALESCE(SUM(e.duration_seconds), 0) AS total_duration')
                ),
                $this->anything()
            )
            ->willReturn([
                [
                    'media_item_id' => 'media-live',
                    'play_count' => '25',
                    'total_duration' => '90000',
                ],
            ]);

        $collector = new StatsCollector($db);
        $topMedia = $collector->getTopMedia(10, null);

        // Regression: the surviving row's aggregates pass through unchanged by
        // the join (no double-count).
        $this->assertCount(1, $topMedia);
        $this->assertSame('media-live', $topMedia[0]['media_item_id']);
        $this->assertSame(25, $topMedia[0]['play_count']);
        $this->assertSame(90000, $topMedia[0]['total_duration']);
    }

    public function testGetPlaybackStatsReturnsTimeSeries(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'date' => '2024-01-01',
                'play_count' => '100',
                'total_duration' => '360000',
                'completed_count' => '50',
            ],
            [
                'date' => '2024-01-02',
                'play_count' => '120',
                'total_duration' => '432000',
                'completed_count' => '60',
            ],
        ]);

        $collector = new StatsCollector($db);
        $from = new DateTime('2024-01-01');
        $to = new DateTime('2024-01-02');
        $stats = $collector->getPlaybackStats($from, $to);

        $this->assertCount(2, $stats);
        $this->assertEquals('2024-01-01', $stats[0]['date']);
        $this->assertEquals(100, $stats[0]['play_count']);
        $this->assertEquals(360000, $stats[0]['total_duration']);
        $this->assertEquals(50, $stats[0]['completed_count']);
        $this->assertEquals('2024-01-02', $stats[1]['date']);
        $this->assertEquals(120, $stats[1]['play_count']);
        $this->assertEquals(432000, $stats[1]['total_duration']);
        $this->assertEquals(60, $stats[1]['completed_count']);
    }
}
