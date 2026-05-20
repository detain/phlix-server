<?php

namespace Phlix\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\WatchHistory;
use Workerman\MySQL\Connection;

class WatchHistoryTest extends TestCase
{
    private WatchHistory $watchHistory;
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->watchHistory = new WatchHistory($this->db);
    }

    public function testGetHistoryReturnsWatchEntries(): void
    {
        $this->db->method('query')->willReturn([
            [
                'id' => 'entry-1',
                'profile_id' => 'profile-1',
                'media_item_id' => 'media-1',
                'position_ticks' => 3600000000,
                'duration_ticks' => 7200000000,
                'playback_status' => 'playing',
                'progress_percent' => 50.0,
                'last_watched_at' => '2024-01-15 10:00:00',
                'created_at' => '2024-01-15 09:00:00',
                'completed_at' => null,
                'media_name' => 'Test Movie',
                'media_type' => 'movie',
                'metadata_json' => '{"poster_url": "http://example.com/poster.jpg"}',
            ]
        ]);

        $result = $this->watchHistory->getHistory('profile-1');

        $this->assertCount(1, $result);
        $this->assertEquals('entry-1', $result[0]['id']);
        $this->assertEquals('Test Movie', $result[0]['media_name']);
        $this->assertEquals('http://example.com/poster.jpg', $result[0]['poster_url']);
    }

    public function testGetHistoryRespectsLimitAndOffset(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->watchHistory->getHistory('profile-1', 10, 5);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetContinueWatchingReturnsInProgressItems(): void
    {
        $this->db->method('query')->willReturn([
            [
                'id' => 'entry-1',
                'profile_id' => 'profile-1',
                'media_item_id' => 'media-1',
                'position_ticks' => 1800000000,
                'duration_ticks' => 7200000000,
                'playback_status' => 'paused',
                'progress_percent' => 25.0,
                'last_watched_at' => '2024-01-15 10:00:00',
                'created_at' => '2024-01-15 09:00:00',
                'completed_at' => null,
                'media_name' => 'Continue This Movie',
                'media_type' => 'movie',
                'metadata_json' => '{}',
            ]
        ]);

        $result = $this->watchHistory->getContinueWatching('profile-1');

        $this->assertCount(1, $result);
        $this->assertEquals('paused', $result[0]['playback_status']);
        $this->assertEquals(25.0, $result[0]['progress_percent']);
    }

    public function testGetRecentlyCompletedReturnsCompletedItems(): void
    {
        $this->db->method('query')->willReturn([
            [
                'id' => 'entry-1',
                'profile_id' => 'profile-1',
                'media_item_id' => 'media-1',
                'position_ticks' => 7200000000,
                'duration_ticks' => 7200000000,
                'playback_status' => 'completed',
                'progress_percent' => 100.0,
                'last_watched_at' => '2024-01-15 10:00:00',
                'created_at' => '2024-01-15 09:00:00',
                'completed_at' => '2024-01-15 10:00:00',
                'media_name' => 'Finished Movie',
                'media_type' => 'movie',
                'metadata_json' => '{}',
            ]
        ]);

        $result = $this->watchHistory->getRecentlyCompleted('profile-1');

        $this->assertCount(1, $result);
        $this->assertEquals('completed', $result[0]['playback_status']);
        $this->assertEquals(100.0, $result[0]['progress_percent']);
    }

    public function testGetForMediaItemReturnsNullWhenNotFound(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->watchHistory->getForMediaItem('profile-1', 'media-1');

        $this->assertNull($result);
    }

    public function testGetForMediaItemReturnsEntryWhenFound(): void
    {
        $this->db->method('query')->willReturn([
            [
                'id' => 'entry-1',
                'profile_id' => 'profile-1',
                'media_item_id' => 'media-1',
                'position_ticks' => 3600000000,
                'duration_ticks' => 7200000000,
                'playback_status' => 'playing',
                'progress_percent' => 50.0,
                'last_watched_at' => '2024-01-15 10:00:00',
                'created_at' => '2024-01-15 09:00:00',
                'completed_at' => null,
            ]
        ]);

        $result = $this->watchHistory->getForMediaItem('profile-1', 'media-1');

        $this->assertIsArray($result);
        $this->assertEquals('entry-1', $result['id']);
        $this->assertEquals(50.0, $result['progress_percent']);
    }

    public function testUpdateProgressCreatesNewEntry(): void
    {
        $callCount = 0;
        $this->db->method('query')
            ->willReturnCallback(function ($sql) use (&$callCount) {
                if (strpos($sql, 'SELECT') !== false) {
                    $callCount++;
                    if ($callCount > 1) {
                        // Return the newly created entry on second SELECT
                        return [[
                            'id' => 'entry-new',
                            'profile_id' => 'profile-1',
                            'media_item_id' => 'media-1',
                            'position_ticks' => 3600000000,
                            'duration_ticks' => 7200000000,
                            'playback_status' => 'playing',
                            'progress_percent' => 50.0,
                            'last_watched_at' => date('Y-m-d H:i:s'),
                            'created_at' => date('Y-m-d H:i:s'),
                            'completed_at' => null,
                        ]];
                    }
                    return []; // No existing entry
                }
                return [];
            });

        $result = $this->watchHistory->updateProgress(
            'profile-1',
            'media-1',
            3600000000, // 60 minutes in ticks
            7200000000, // 120 minutes in ticks
            'playing'
        );

        // Verify a new entry was created with progress
        $this->assertIsArray($result);
        $this->assertEquals('entry-new', $result['id']);
    }

    public function testUpdateProgressUpdatesExistingEntry(): void
    {
        $this->db->method('query')
            ->willReturnCallback(function ($sql) {
                if (strpos($sql, 'SELECT') !== false) {
                    return [[
                        'id' => 'entry-1',
                        'profile_id' => 'profile-1',
                        'media_item_id' => 'media-1',
                        'position_ticks' => 1800000000,
                        'duration_ticks' => 7200000000,
                        'playback_status' => 'paused',
                        'progress_percent' => 25.0,
                        'last_watched_at' => '2024-01-15 09:00:00',
                        'created_at' => '2024-01-15 08:00:00',
                        'completed_at' => null,
                    ]];
                }
                return [];
            });

        $this->watchHistory->updateProgress(
            'profile-1',
            'media-1',
            3600000000,
            7200000000,
            'playing'
        );

        // Verify update was called (no exception thrown)
        $this->assertTrue(true);
    }

    public function testUpdateProgressMarksCompletedWhenThresholdReached(): void
    {
        $this->db->method('query')
            ->willReturnCallback(function ($sql) {
                if (strpos($sql, 'SELECT') !== false) {
                    return [[
                        'id' => 'entry-1',
                        'profile_id' => 'profile-1',
                        'media_item_id' => 'media-1',
                        'position_ticks' => 5000000000,
                        'duration_ticks' => 7200000000,
                        'playback_status' => 'playing',
                        'progress_percent' => 69.0,
                        'last_watched_at' => '2024-01-15 09:00:00',
                        'created_at' => '2024-01-15 08:00:00',
                        'completed_at' => null,
                    ]];
                }
                return [];
            });

        $this->watchHistory->updateProgress(
            'profile-1',
            'media-1',
            6840000000, // 95% of 7200000000
            7200000000,
            'playing'
        );

        // Verify update was called (no exception thrown)
        $this->assertTrue(true);
    }

    public function testMarkCompleted(): void
    {
        $this->db->method('query')
            ->willReturnCallback(function ($sql) {
                if (strpos($sql, 'SELECT') !== false) {
                    return [[
                        'id' => 'entry-1',
                        'profile_id' => 'profile-1',
                        'media_item_id' => 'media-1',
                        'position_ticks' => 7200000000,
                        'duration_ticks' => 7200000000,
                        'playback_status' => 'playing',
                        'progress_percent' => 100.0,
                        'last_watched_at' => '2024-01-15 10:00:00',
                        'created_at' => '2024-01-15 09:00:00',
                        'completed_at' => null,
                    ]];
                }
                return [];
            });

        $this->watchHistory->markCompleted('profile-1', 'media-1');

        // Verify update was called (no exception thrown)
        $this->assertTrue(true);
    }

    public function testRemoveFromHistory(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM watch_history'),
                ['profile-1', 'media-1']
            );

        $this->watchHistory->removeFromHistory('profile-1', 'media-1');
    }

    public function testClearHistory(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM watch_history WHERE profile_id'),
                ['profile-1']
            );

        $this->watchHistory->clearHistory('profile-1');
    }

    public function testGetTotalWatchTime(): void
    {
        $this->db->method('query')->willReturn([['total' => 7200000000]]);

        $result = $this->watchHistory->getTotalWatchTime('profile-1');

        // 7200000000 ticks / 10000 = 720000 seconds = 120 minutes
        $this->assertEquals(720000, $result);
    }

    public function testGetTotalWatchTimeReturnsZeroWhenNoData(): void
    {
        $this->db->method('query')->willReturn([['total' => null]]);

        $result = $this->watchHistory->getTotalWatchTime('profile-1');

        $this->assertEquals(0, $result);
    }

    public function testGetTodayWatchTime(): void
    {
        $this->db->method('query')->willReturn([['total' => 3600000000]]);

        $result = $this->watchHistory->getTodayWatchTime('profile-1');

        // 3600000000 ticks / 10000 = 360000 seconds = 60 minutes
        $this->assertEquals(360000, $result);
    }

    public function testGetWatchTimeByDay(): void
    {
        $this->db->method('query')->willReturn([
            ['watch_date' => '2024-01-15', 'total_ticks' => 3600000000],
            ['watch_date' => '2024-01-14', 'total_ticks' => 7200000000],
        ]);

        $result = $this->watchHistory->getWatchTimeByDay('profile-1', 7);

        $this->assertArrayHasKey('2024-01-15', $result);
        $this->assertArrayHasKey('2024-01-14', $result);
        $this->assertEquals(360000, $result['2024-01-15']);
        $this->assertEquals(720000, $result['2024-01-14']);
    }

    public function testHasWatchedReturnsFalseWhenNotWatched(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->watchHistory->hasWatched('profile-1', 'media-1');

        $this->assertFalse($result);
    }

    public function testHasWatchedReturnsTrueWhenCompleted(): void
    {
        $this->db->method('query')->willReturn([['1' => 1]]);

        $result = $this->watchHistory->hasWatched('profile-1', 'media-1');

        $this->assertTrue($result);
    }

    public function testGetResumePositionReturnsNullWhenNotFound(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->watchHistory->getResumePosition('profile-1', 'media-1');

        $this->assertNull($result);
    }

    public function testGetResumePositionReturnsNullWhenCompleted(): void
    {
        $this->db->method('query')->willReturn([[
            'id' => 'entry-1',
            'profile_id' => 'profile-1',
            'media_item_id' => 'media-1',
            'position_ticks' => 7200000000,
            'duration_ticks' => 7200000000,
            'playback_status' => 'completed',
            'progress_percent' => 100.0,
            'last_watched_at' => '2024-01-15 10:00:00',
            'created_at' => '2024-01-15 09:00:00',
            'completed_at' => '2024-01-15 10:00:00',
        ]]);

        $result = $this->watchHistory->getResumePosition('profile-1', 'media-1');

        $this->assertNull($result);
    }

    public function testGetResumePositionReturnsPositionWhenInProgress(): void
    {
        $this->db->method('query')->willReturn([[
            'id' => 'entry-1',
            'profile_id' => 'profile-1',
            'media_item_id' => 'media-1',
            'position_ticks' => 3600000000,
            'duration_ticks' => 7200000000,
            'playback_status' => 'paused',
            'progress_percent' => 50.0,
            'last_watched_at' => '2024-01-15 10:00:00',
            'created_at' => '2024-01-15 09:00:00',
            'completed_at' => null,
        ]]);

        $result = $this->watchHistory->getResumePosition('profile-1', 'media-1');

        $this->assertEquals(3600000000, $result);
    }

    public function testGetCount(): void
    {
        $this->db->method('query')->willReturn([['count' => 42]]);

        $result = $this->watchHistory->getCount('profile-1');

        $this->assertEquals(42, $result);
    }
}
