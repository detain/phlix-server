<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Stats;

use DateTime;
use PHPUnit\Framework\TestCase;
use Phlex\Stats\StatsCollector;
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
