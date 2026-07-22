<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Admin\DashboardService;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Streaming\StreamManager;
use Phlix\Media\Streaming\StreamState;
use Phlix\Session\SessionManager;
use Phlix\Stats\StatsCollector;
use Workerman\MySQL\Connection;

class DashboardServiceTest extends TestCase
{
    /** @return Connection&MockObject */
    private function createMockConnection(): Connection
    {
        return $this->createMock(Connection::class);
    }

    /** @return StatsCollector&MockObject */
    private function createMockStatsCollector(): StatsCollector
    {
        return $this->createMock(StatsCollector::class);
    }

    /** @return SessionManager&MockObject */
    private function createMockSessionManager(): SessionManager
    {
        return $this->createMock(SessionManager::class);
    }

    /** @return StreamManager&MockObject */
    private function createMockStreamManager(): StreamManager
    {
        return $this->createMock(StreamManager::class);
    }

    /** @return ItemRepository&MockObject */
    private function createMockItemRepository(): ItemRepository
    {
        return $this->createMock(ItemRepository::class);
    }

    public function test_get_now_playing_returns_active_sessions(): void
    {
        // Create mock StreamState
        $streamState = new StreamState();
        $streamState->id = 'stream-123';
        $streamState->mediaItemId = 'media-456';
        $streamState->sessionId = 'session-789';
        $streamState->userId = 'user-abc';
        $streamState->positionTicks = 300000000;
        $streamState->durationTicks = 600000000;
        $streamState->status = StreamState::STATUS_PLAYING;

        $mockConnection = $this->createMockConnection();
        $mockConnection->method('query')->willReturn([
            ['id' => 'user-abc', 'username' => 'testuser', 'avatar_url' => null],
        ]);

        $mockStreamManager = $this->createMockStreamManager();
        $mockStreamManager->method('getActiveStreams')->willReturn([$streamState]);

        $mockItemRepository = $this->createMockItemRepository();
        $mockItemRepository->method('findById')->willReturn([
            'id' => 'media-456',
            'name' => 'Test Movie',
            'type' => 'movie',
            'metadata' => ['poster_url' => '/poster.jpg'],
        ]);

        $mockSessionManager = $this->createMockSessionManager();
        $mockSessionManager->method('getSession')->willReturn([
            'id' => 'session-789',
            'device_name' => 'Chrome',
            'device_type' => 'desktop',
        ]);

        $mockStatsCollector = $this->createMockStatsCollector();

        $service = new DashboardService(
            $mockStatsCollector,
            $mockSessionManager,
            $mockStreamManager,
            $mockItemRepository,
            $mockConnection
        );

        $result = $service->getNowPlaying();

        $this->assertCount(1, $result);
        $this->assertEquals('stream-123', $result[0]['stream_id']);
        $this->assertEquals('user-abc', $result[0]['user_id']);
        $this->assertEquals('testuser', $result[0]['username']);
        $this->assertEquals('Test Movie', $result[0]['media_title']);
        $this->assertEquals(50.0, $result[0]['progress_percent']);
        $this->assertEquals('playing', $result[0]['status']);
        $this->assertEquals('Chrome', $result[0]['device_name']);
    }

    public function test_get_top_users_returns_leaderboard(): void
    {
        $mockConnection = $this->createMockConnection();
        $mockConnection->method('query')
            ->willReturnCallback(function (string $sql, array $params = []): array {
                // Return user data based on the user ID being queried
                $userId = $params[0] ?? '';
                if ($userId === 'user-123') {
                    return [['id' => 'user-123', 'username' => 'alice', 'avatar_url' => '/avatar1.png']];
                }
                if ($userId === 'user-456') {
                    return [['id' => 'user-456', 'username' => 'bob', 'avatar_url' => '/avatar2.png']];
                }
                return [];
            });

        $mockStatsCollector = $this->createMockStatsCollector();
        $mockStatsCollector->method('getTopUsers')->willReturn([
            ['user_id' => 'user-123', 'total_watch_time' => 7200, 'play_count' => 5],
            ['user_id' => 'user-456', 'total_watch_time' => 3600, 'play_count' => 3],
        ]);

        $mockSessionManager = $this->createMockSessionManager();
        $mockStreamManager = $this->createMockStreamManager();
        $mockItemRepository = $this->createMockItemRepository();

        $service = new DashboardService(
            $mockStatsCollector,
            $mockSessionManager,
            $mockStreamManager,
            $mockItemRepository,
            $mockConnection
        );

        $result = $service->getTopUsers(10, 30);

        $this->assertCount(2, $result);
        $this->assertEquals('user-123', $result[0]['user_id']);
        $this->assertEquals('alice', $result[0]['username']);
        $this->assertEquals(7200, $result[0]['total_watch_time']);
        $this->assertEquals(5, $result[0]['play_count']);
        $this->assertEquals('/avatar1.png', $result[0]['avatar_url']);

        $this->assertEquals('user-456', $result[1]['user_id']);
        $this->assertEquals('bob', $result[1]['username']);
    }

    public function test_get_top_media_returns_popular_items(): void
    {
        $mockConnection = $this->createMockConnection();
        $mockConnection->method('query')->willReturn([]);

        $mockStatsCollector = $this->createMockStatsCollector();
        $mockStatsCollector->method('getTopMedia')->willReturn([
            ['media_item_id' => 'media-123', 'play_count' => 10, 'total_duration' => 18000],
            ['media_item_id' => 'media-456', 'play_count' => 5, 'total_duration' => 9000],
        ]);

        $mockItemRepository = $this->createMockItemRepository();
        $mockItemRepository->method('findById')
            ->willReturnCallback(function (string $id): array {
                if ($id === 'media-123') {
                    return [
                        'id' => 'media-123',
                        'name' => 'Popular Movie',
                        'type' => 'movie',
                        'metadata' => ['poster_url' => '/poster1.jpg'],
                    ];
                }
                return [
                    'id' => 'media-456',
                    'name' => 'Another Movie',
                    'type' => 'movie',
                    'metadata' => ['poster_url' => '/poster2.jpg'],
                ];
            });

        $mockSessionManager = $this->createMockSessionManager();
        $mockStreamManager = $this->createMockStreamManager();

        $service = new DashboardService(
            $mockStatsCollector,
            $mockSessionManager,
            $mockStreamManager,
            $mockItemRepository,
            $mockConnection
        );

        $result = $service->getTopMedia(10, 30);

        $this->assertCount(2, $result);
        $this->assertEquals('media-123', $result[0]['media_item_id']);
        $this->assertEquals('Popular Movie', $result[0]['title']);
        $this->assertEquals(10, $result[0]['play_count']);
        $this->assertEquals('/poster1.jpg', $result[0]['poster_url']);

        $this->assertEquals('media-456', $result[1]['media_item_id']);
        $this->assertEquals('Another Movie', $result[1]['title']);
        $this->assertEquals(5, $result[1]['play_count']);
    }

    public function test_get_top_media_excludes_orphaned_deleted_item(): void
    {
        // S14 orphan guard (behavioural proof of the consumer-side skip).
        // A play-count row whose media item has since been deleted must NOT
        // surface as a blank (null-title) dashboard entry: getTopMedia() skips
        // any row whose ItemRepository::findById() hydrate returns null.
        $mockConnection = $this->createMockConnection();
        $mockConnection->method('query')->willReturn([]);

        $mockStatsCollector = $this->createMockStatsCollector();
        // The aggregate still reports the deleted item's historical plays here —
        // this is exactly the TOCTOU window the defense-in-depth guard closes
        // (item removed after StatsCollector's INNER JOIN would have dropped it).
        $mockStatsCollector->method('getTopMedia')->willReturn([
            ['media_item_id' => 'media-live', 'play_count' => 10, 'total_duration' => 18000],
            ['media_item_id' => 'media-gone', 'play_count' => 7, 'total_duration' => 12600],
        ]);

        $mockItemRepository = $this->createMockItemRepository();
        $mockItemRepository->method('findById')
            ->willReturnCallback(function (string $id): ?array {
                if ($id === 'media-live') {
                    return [
                        'id' => 'media-live',
                        'name' => 'Surviving Movie',
                        'type' => 'movie',
                        'metadata' => ['poster_url' => '/poster-live.jpg'],
                    ];
                }
                // Deleted item — the repository no longer has a row for it.
                return null;
            });

        $service = new DashboardService(
            $mockStatsCollector,
            $this->createMockSessionManager(),
            $this->createMockStreamManager(),
            $mockItemRepository,
            $mockConnection
        );

        $result = $service->getTopMedia(10, 30);

        // Only the surviving item is returned; the orphaned row is hidden.
        $this->assertCount(1, $result);
        $this->assertSame('media-live', $result[0]['media_item_id']);
        $this->assertSame('Surviving Movie', $result[0]['title']);

        // The deleted item never leaks through, and no blank-title row exists.
        foreach ($result as $row) {
            $this->assertNotSame('media-gone', $row['media_item_id']);
            $this->assertNotSame('', $row['title'], 'no blank-title row may reach the dashboard');
        }

        // Regression: the surviving neighbour's aggregates are passed through
        // unchanged by the orphan skip — no double-count, no perturbation.
        $this->assertSame(10, $result[0]['play_count']);
        $this->assertSame(18000, $result[0]['total_duration']);
        $this->assertSame('/poster-live.jpg', $result[0]['poster_url']);
    }

    public function test_get_top_users_excludes_deleted_user(): void
    {
        // S14 orphan guard (analogous, user side). A watch-time row whose user
        // account has since been deleted must NOT surface as a blank (no-name)
        // leaderboard entry: getTopUsers() skips any row whose username lookup
        // returns null (the users SELECT comes back empty for a removed row).
        $mockConnection = $this->createMockConnection();
        $mockConnection->method('query')
            ->willReturnCallback(function (string $sql, array $params = []): array {
                $userId = $params[0] ?? '';
                if ($userId === 'user-live') {
                    return [['id' => 'user-live', 'username' => 'alice', 'avatar_url' => '/avatar1.png']];
                }
                // Deleted user — both the username and avatar lookups miss.
                return [];
            });

        $mockStatsCollector = $this->createMockStatsCollector();
        $mockStatsCollector->method('getTopUsers')->willReturn([
            ['user_id' => 'user-live', 'total_watch_time' => 7200, 'play_count' => 5],
            ['user_id' => 'user-gone', 'total_watch_time' => 3600, 'play_count' => 3],
        ]);

        $service = new DashboardService(
            $mockStatsCollector,
            $this->createMockSessionManager(),
            $this->createMockStreamManager(),
            $this->createMockItemRepository(),
            $mockConnection
        );

        $result = $service->getTopUsers(10, 30);

        // Only the surviving user is returned; the deleted account is hidden.
        $this->assertCount(1, $result);
        $this->assertSame('user-live', $result[0]['user_id']);
        $this->assertSame('alice', $result[0]['username']);

        // The deleted user never leaks through, and no blank-username row exists.
        foreach ($result as $row) {
            $this->assertNotSame('user-gone', $row['user_id']);
            $this->assertNotSame('', $row['username'], 'no blank-username row may reach the dashboard');
        }

        // Regression: the surviving neighbour's aggregates are passed through
        // unchanged by the orphan skip — no double-count, no perturbation.
        $this->assertSame(7200, $result[0]['total_watch_time']);
        $this->assertSame(5, $result[0]['play_count']);
        $this->assertSame('/avatar1.png', $result[0]['avatar_url']);
    }

    public function test_get_storage_summary_aggregates_by_type(): void
    {
        $mockConnection = $this->createMockConnection();
        $mockConnection->method('query')->willReturn([
            [
                'media_type' => 'movie',
                'item_count' => 150,
                'total_bytes' => 50000000000,
                'transcode_cache_bytes' => 2000000000,
            ],
            [
                'media_type' => 'series',
                'item_count' => 300,
                'total_bytes' => 120000000000,
                'transcode_cache_bytes' => 5000000000,
            ],
        ]);

        $mockStatsCollector = $this->createMockStatsCollector();
        $mockSessionManager = $this->createMockSessionManager();
        $mockStreamManager = $this->createMockStreamManager();
        $mockItemRepository = $this->createMockItemRepository();

        $service = new DashboardService(
            $mockStatsCollector,
            $mockSessionManager,
            $mockStreamManager,
            $mockItemRepository,
            $mockConnection
        );

        $result = $service->getStorageSummary();

        $this->assertArrayHasKey('movie_bytes', $result);
        $this->assertArrayHasKey('series_bytes', $result);
        $this->assertArrayHasKey('music_bytes', $result);
        $this->assertArrayHasKey('photo_bytes', $result);
        $this->assertArrayHasKey('book_bytes', $result);
        $this->assertArrayHasKey('transcode_cache_bytes', $result);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('formatted_transcode_cache', $result);

        $this->assertEquals(50000000000, $result['movie_bytes']);
        $this->assertEquals(120000000000, $result['series_bytes']);
        $this->assertEquals(7000000000, $result['transcode_cache_bytes']);

        $this->assertCount(2, $result['items']);
        $this->assertEquals('movie', $result['items'][0]['media_type']);
        $this->assertEquals(150, $result['items'][0]['item_count']);
        $this->assertEquals(50000000000, $result['items'][0]['total_bytes']);
        $this->assertEquals(2000000000, $result['items'][0]['transcode_cache_bytes']);
        $this->assertEquals('46.57 GB', $result['items'][0]['formatted_total']);
        $this->assertEquals('1.86 GB', $result['items'][0]['formatted_cache']);

        $this->assertEquals('series', $result['items'][1]['media_type']);
        $this->assertEquals(300, $result['items'][1]['item_count']);
    }

    public function test_get_storage_summary_surfaces_the_book_bucket(): void
    {
        $mockConnection = $this->createMockConnection();
        $mockConnection->method('query')->willReturn([
            [
                'media_type' => 'book',
                'item_count' => 42,
                'total_bytes' => 3000000000,
                'transcode_cache_bytes' => 0,
            ],
        ]);

        $service = new DashboardService(
            $this->createMockStatsCollector(),
            $this->createMockSessionManager(),
            $this->createMockStreamManager(),
            $this->createMockItemRepository(),
            $mockConnection
        );

        $result = $service->getStorageSummary();

        $this->assertEquals(3000000000, $result['book_bytes']);
        $this->assertCount(1, $result['items']);
        $this->assertEquals('book', $result['items'][0]['media_type']);
        $this->assertEquals(42, $result['items'][0]['item_count']);
    }

    public function test_get_recent_activity_returns_feed(): void
    {
        $mockConnection = $this->createMockConnection();

        // Mock for different queries - return playback events first, then empty for others
        $mockConnection->method('query')
            ->willReturnCallback(function (string $sql): array {
                if (strpos($sql, 'stats_playback_events') !== false) {
                    return [
                        [
                            'id' => 'event-1',
                            'user_id' => 'user-123',
                            'media_item_id' => 'media-456',
                            'duration_seconds' => 3600,
                            'completed' => true,
                            'started_at' => '2024-01-15 10:00:00',
                            'ended_at' => '2024-01-15 11:00:00',
                        ],
                    ];
                }
                if (strpos($sql, 'stats_library_changes') !== false) {
                    return [
                        [
                            'id' => 'event-2',
                            'change_type' => 'item_added',
                            'media_item_id' => 'media-789',
                            'library_id' => 'lib-123',
                            'user_id' => 'user-456',
                            'changed_at' => '2024-01-15 09:00:00',
                            'details_json' => '{"path":"/movies/new.mkv"}',
                        ],
                    ];
                }
                if (strpos($sql, 'stats_user_activity') !== false) {
                    return [
                        [
                            'id' => 'event-3',
                            'user_id' => 'user-123',
                            'activity_type' => 'login',
                            'occurred_at' => '2024-01-15 08:00:00',
                            'ip_address' => '192.168.1.1',
                            'details_json' => null,
                        ],
                    ];
                }
                return [];
            });

        $mockItemRepository = $this->createMockItemRepository();
        $mockItemRepository->method('findById')->willReturn([
            'id' => 'media-456',
            'name' => 'Test Movie',
            'metadata' => [],
        ]);

        $mockStatsCollector = $this->createMockStatsCollector();
        $mockSessionManager = $this->createMockSessionManager();
        $mockStreamManager = $this->createMockStreamManager();

        $service = new DashboardService(
            $mockStatsCollector,
            $mockSessionManager,
            $mockStreamManager,
            $mockItemRepository,
            $mockConnection
        );

        $result = $service->getRecentActivity(20);

        $this->assertGreaterThanOrEqual(1, count($result));

        // Find the playback event
        $playbackEvent = null;
        foreach ($result as $event) {
            if ($event['event_type'] === 'playback_completed') {
                $playbackEvent = $event;
                break;
            }
        }

        $this->assertNotNull($playbackEvent);
        $this->assertEquals('event-1', $playbackEvent['id']);
        $this->assertEquals('playback', $playbackEvent['category']);
        $this->assertEquals('user-123', $playbackEvent['user_id']);
        $this->assertArrayHasKey('details', $playbackEvent);
        $this->assertEquals(3600, $playbackEvent['details']['duration_seconds']);
    }
}
