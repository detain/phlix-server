<?php

namespace Phlix\Tests\Unit\Auth;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Auth\WatchHistory;
use Workerman\MySQL\Connection;

class WatchHistoryTest extends TestCase
{
    private WatchHistory $watchHistory;
    /** @var Connection&MockObject */
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

        $this->assertCount(0, $result);
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
        $this->assertArrayHasKey('id', $result);
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

        $result = $this->watchHistory->updateProgress(
            'profile-1',
            'media-1',
            3600000000,
            7200000000,
            'playing'
        );

        // The upsert re-reads and returns the persisted entry
        $this->assertArrayHasKey('id', $result);
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

        $result = $this->watchHistory->updateProgress(
            'profile-1',
            'media-1',
            6840000000, // 95% of 7200000000
            7200000000,
            'playing'
        );

        // The upsert re-reads and returns the persisted entry
        $this->assertArrayHasKey('id', $result);
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

        $result = $this->watchHistory->markCompleted('profile-1', 'media-1');

        // markCompleted re-reads and returns the persisted entry
        $this->assertArrayHasKey('id', $result);
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

    /**
     * S36 Fixer (finding 1): the started-series candidate scan (Query A) MUST
     * inline a validated integer LIMIT to bound the one-query-per-series
     * fan-out in getNextUp() — never a bound `?` LIMIT (emulated prepares raise
     * a 1064 on a bound LIMIT in this repo), and never unbounded. The cap is
     * `max($limit * NEXT_UP_SERIES_SCAN_MULTIPLIER, NEXT_UP_SERIES_SCAN_FLOOR)`.
     *
     * @dataProvider nextUpScanCapProvider
     */
    public function testGetNextUpInlinesBoundedSeriesScanLimit(int $limit, int $expectedCap): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $this->db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$capturedSql, &$capturedParams) {
                // profileId → userId resolution.
                if (str_contains($sql, 'user_profiles')) {
                    return [['user_id' => 'user-1']];
                }
                // Query A: the started-series fan-out cap query.
                if (str_contains($sql, 'ps.media_item_id AS episode_id')) {
                    $capturedSql = $sql;
                    $capturedParams = $params;
                }
                // Empty started-series list → getNextUp short-circuits before
                // issuing any per-series Query B round-trip.
                return [];
            }
        );

        $result = $this->watchHistory->getNextUp('profile-1', $limit);

        $this->assertSame([], $result);
        $this->assertNotNull($capturedSql, 'fetchStartedSeries query must have been issued');
        // Inlined integer LIMIT — exactly the computed cap, as a literal.
        $this->assertStringContainsString('LIMIT ' . $expectedCap, $capturedSql);
        // NEVER a bound LIMIT (would 1064 under emulated prepares in this repo).
        $this->assertStringNotContainsString('LIMIT ?', $capturedSql);
        // The only bound param is the user id — the cap is inlined, not bound.
        $this->assertSame(['user-1'], $capturedParams);
    }

    /**
     * The cap must never exceed the caller's total started-series count: with a
     * capped scan the per-series fan-out is bounded, not the series count. Here
     * the mock returns MORE started series than the cap; getNextUp must issue at
     * most one Query B per returned candidate (it does not re-scan), proving the
     * per-series work is bounded by what Query A returns (which is LIMIT-capped).
     */
    public function testGetNextUpIssuesOneEpisodeQueryPerReturnedStartedSeries(): void
    {
        $startedSeries = [
            ['series_id' => 'series-a', 'episode_id' => 'ep-a', 'updated_at' => '2026-01-03 00:00:00'],
            ['series_id' => 'series-b', 'episode_id' => 'ep-b', 'updated_at' => '2026-01-02 00:00:00'],
            ['series_id' => 'series-c', 'episode_id' => 'ep-c', 'updated_at' => '2026-01-01 00:00:00'],
        ];
        $episodeQueryCount = 0;

        $this->db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($startedSeries, &$episodeQueryCount) {
                if (str_contains($sql, 'user_profiles')) {
                    return [['user_id' => 'user-1']];
                }
                if (str_contains($sql, 'ps.media_item_id AS episode_id')) {
                    return $startedSeries;
                }
                // Query B (fetchSeriesEpisodes): one per started series. Return an
                // all-watched series (finale watched) so no pick is produced —
                // the pathological complete-watcher case the cap defends against.
                if (str_contains($sql, 'series_metadata_json')) {
                    $episodeQueryCount++;
                    return [[
                        'id' => 'ep-x',
                        'name' => 'S01E01',
                        'type' => 'episode',
                        'metadata_json' => '{"season":1,"episode":1}',
                        'playback_status' => 'stopped',
                        'position_ticks' => 100000,
                        'duration_ticks' => 100000,
                    ]];
                }
                return [];
            }
        );

        $result = $this->watchHistory->getNextUp('profile-1', 20);

        // No fresh episode anywhere → empty rail, but exactly one Query B per
        // started series Query A returned (never more) — the fan-out is bounded
        // by the LIMIT-capped candidate list.
        $this->assertSame([], $result);
        $this->assertSame(count($startedSeries), $episodeQueryCount);
    }

    /**
     * getNextUp resolves the profile → owning user FIRST; a profile with no user
     * row (deleted/detached) yields an empty rail and NEVER issues the candidate-
     * series scan. Covers the null-user early return.
     */
    public function testGetNextUpReturnsEmptyWhenProfileHasNoUser(): void
    {
        $scanIssued = false;
        $this->db->method('query')->willReturnCallback(
            function (string $sql) use (&$scanIssued) {
                if (str_contains($sql, 'user_profiles')) {
                    return []; // profile resolves to no user
                }
                if (str_contains($sql, 'ps.media_item_id AS episode_id')) {
                    $scanIssued = true;
                }
                return [];
            }
        );

        $this->assertSame([], $this->watchHistory->getNextUp('ghost-profile', 20));
        $this->assertFalse($scanIssued, 'No candidate-series scan when the profile resolves to no user');
    }

    /**
     * A started "series" whose per-series episode query returns no rows (e.g. a
     * season-less flat hierarchy that never satisfies the episode→season→series
     * join chain) is skipped — it contributes nothing rather than a broken card.
     */
    public function testGetNextUpSkipsSeriesWhoseEpisodeQueryReturnsNoRows(): void
    {
        $this->db->method('query')->willReturnCallback(
            function (string $sql) {
                if (str_contains($sql, 'user_profiles')) {
                    return [['user_id' => 'user-1']];
                }
                if (str_contains($sql, 'ps.media_item_id AS episode_id')) {
                    return [[
                        'series_id' => 'series-x',
                        'episode_id' => 'ep-x',
                        'updated_at' => '2026-01-01 00:00:00',
                    ]];
                }
                // fetchSeriesEpisodes → no episodes for this series.
                return [];
            }
        );

        $this->assertSame([], $this->watchHistory->getNextUp('profile-1', 20));
    }

    /**
     * A candidate row with a blank series_id (a malformed join result) is skipped
     * BEFORE any per-series episode query is issued — defensive guard.
     */
    public function testGetNextUpSkipsStartedRowWithBlankSeriesId(): void
    {
        $episodeQueryIssued = false;
        $this->db->method('query')->willReturnCallback(
            function (string $sql) use (&$episodeQueryIssued) {
                if (str_contains($sql, 'user_profiles')) {
                    return [['user_id' => 'user-1']];
                }
                if (str_contains($sql, 'ps.media_item_id AS episode_id')) {
                    return [[
                        'series_id' => '',
                        'episode_id' => 'ep-x',
                        'updated_at' => '2026-01-01 00:00:00',
                    ]];
                }
                if (str_contains($sql, 'series_metadata_json')) {
                    $episodeQueryIssued = true;
                }
                return [];
            }
        );

        $this->assertSame([], $this->watchHistory->getNextUp('profile-1', 20));
        $this->assertFalse($episodeQueryIssued, 'A blank series_id must be skipped before the episode query');
    }

    /**
     * A malformed episode row with a blank id is dropped from the selector input
     * while a valid fresh sibling is still resolved as the pick — defensive guard.
     */
    public function testGetNextUpSkipsEpisodeRowsWithBlankId(): void
    {
        $episode = static function (string $id): array {
            return [
                'id' => $id,
                'name' => 'S01E01',
                'type' => 'episode',
                'metadata_json' => '{"season":1,"episode":1}',
                'parent_metadata_json' => '{}',
                'series_metadata_json' => '{"poster_url":"/series/x.jpg"}',
                'series_id' => 'series-x',
                'series_name' => 'Series X',
                'playback_status' => null,
                'position_ticks' => 0,
                'duration_ticks' => 0,
            ];
        };

        $this->db->method('query')->willReturnCallback(
            function (string $sql) use ($episode) {
                if (str_contains($sql, 'user_profiles')) {
                    return [['user_id' => 'user-1']];
                }
                if (str_contains($sql, 'ps.media_item_id AS episode_id')) {
                    return [[
                        'series_id' => 'series-x',
                        'episode_id' => 'ep-1',
                        'updated_at' => '2026-01-01 00:00:00',
                    ]];
                }
                if (str_contains($sql, 'series_metadata_json')) {
                    return [$episode(''), $episode('ep-1')];
                }
                return [];
            }
        );

        $result = $this->watchHistory->getNextUp('profile-1', 20);
        $this->assertCount(1, $result);
        $this->assertSame('ep-1', $result[0]['media_item_id'] ?? null);
    }

    /**
     * When the series carries no poster (its metadata_json is null) the episode
     * card falls back to the SEASON (parent) poster — covers shapeNextEpisode's
     * poster fallback branch AND decodeMetadata's null/empty-string guard.
     */
    public function testGetNextUpFallsBackToSeasonPosterWhenSeriesPosterMissing(): void
    {
        $this->db->method('query')->willReturnCallback(
            function (string $sql) {
                if (str_contains($sql, 'user_profiles')) {
                    return [['user_id' => 'user-1']];
                }
                if (str_contains($sql, 'ps.media_item_id AS episode_id')) {
                    return [[
                        'series_id' => 'series-x',
                        'episode_id' => 'ep-1',
                        'updated_at' => '2026-01-01 00:00:00',
                    ]];
                }
                if (str_contains($sql, 'series_metadata_json')) {
                    return [[
                        'id' => 'ep-1',
                        'name' => 'S01E01',
                        'type' => 'episode',
                        'metadata_json' => '{"season":1,"episode":1}',
                        'parent_metadata_json' => '{"poster_url":"/season/1.jpg"}',
                        // No series poster → season fallback; null → decodeMetadata([]).
                        'series_metadata_json' => null,
                        'series_id' => 'series-x',
                        'series_name' => 'Series X',
                        'playback_status' => null,
                        'position_ticks' => 0,
                        'duration_ticks' => 0,
                    ]];
                }
                return [];
            }
        );

        $result = $this->watchHistory->getNextUp('profile-1', 20);
        $this->assertCount(1, $result);
        $this->assertSame('/season/1.jpg', $result[0]['poster_url'] ?? null);
        $this->assertSame('series-x', $result[0]['series_id'] ?? null);
    }

    /**
     * @return array<string, array{int, int}> [requested $limit, expected inlined scan cap]
     */
    public static function nextUpScanCapProvider(): array
    {
        return [
            'small limit floors at 50'    => [1, 50],
            'default limit scales x3'     => [20, 60],
            'max limit scales x3'         => [50, 150],
            'non-positive limit clamps'   => [0, 50],
        ];
    }
}
