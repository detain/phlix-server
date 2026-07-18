<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session;

use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * @covers \Phlix\Session\PlaybackController
 */
final class PlaybackControllerTest extends TestCase
{
    /** @var array{query: string, params: array<int|string, mixed>}|null */
    private ?array $captured = null;

    public function testContinueWatchingDeduplicatesByMediaItemBeforeLimiting(): void
    {
        // Two rows for the same media item (two sessions/devices) plus a distinct
        // one — the raw table would return all three.
        $rows = [
            [
                'id' => 'p1',
                'media_item_id' => 'm1',
                'name' => 'MASH S09E02',
                'type' => 'series',
                'metadata_json' => '{"poster_url":"/a.jpg"}',
            ],
            ['id' => 'p2', 'media_item_id' => 'm2', 'name' => 'Other', 'type' => 'movie', 'metadata_json' => '{}'],
        ];
        $sessionManager = $this->createMock(SessionManager::class);
        $controller = new PlaybackController($this->captureConnection($rows), $sessionManager);

        $result = $controller->getContinueWatching('user-1', 10);

        self::assertNotNull($this->captured);
        $query = $this->captured['query'];

        // The dedup happens in SQL: one row per media item, newest first, BEFORE
        // the limit applies.
        self::assertMatchesRegularExpression('/ROW_NUMBER\(\)\s*OVER/i', $query);
        self::assertMatchesRegularExpression('/PARTITION BY\s+ps\.media_item_id/i', $query);
        self::assertStringContainsString('WHERE ranked.rn = 1', $query);

        // The limit is applied to the de-duplicated set, not the raw rows.
        self::assertStringContainsString('LIMIT ?', $query);
        self::assertSame(['user-1', 10], $this->captured['params']);

        // metadata_json is decoded onto a `metadata` key for the client.
        $metadata = $result[0]['metadata'];
        self::assertIsArray($metadata);
        self::assertSame('/a.jpg', $metadata['poster_url'] ?? null);
    }

    public function testContinueWatchingPassesThroughTheConfiguredLimit(): void
    {
        $controller = new PlaybackController($this->captureConnection([]), $this->createMock(SessionManager::class));

        $controller->getContinueWatching('user-9', 3);

        self::assertNotNull($this->captured);
        self::assertSame(['user-9', 3], $this->captured['params']);
    }

    public function testContinueWatchingReturnsMediaItemIdNotPlaybackStateId(): void
    {
        // The top-level `id` field must be the media item UUID (mi.id),
        // NOT the playback_state.id — the SQL now selects mi.id AS id.
        $rows = [
            [
                'id' => 'media-item-uuid',
                'media_item_id' => 'media-item-uuid',
                'session_id' => 'session-1',
                'position_ticks' => 1000,
                'duration_ticks' => 10000,
                'playback_status' => 'playing',
                'updated_at' => date('Y-m-d H:i:s'),
                'name' => 'S01E01',
                'type' => 'episode',
                'metadata_json' => '{"poster_url":"/episode.jpg"}',
                'parent_metadata_json' => '{"poster_url":"/season.jpg"}',
                'series_metadata_json' => '{"poster_url":"/series.jpg"}',
            ],
        ];
        $sessionManager = $this->createMock(SessionManager::class);
        $controller = new PlaybackController($this->captureConnection($rows), $sessionManager);

        $result = $controller->getContinueWatching('user-1', 10);

        self::assertCount(1, $result);
        // id must be the media item UUID, not the playback_state row id
        self::assertSame('media-item-uuid', $result[0]['id']);
        self::assertSame('media-item-uuid', $result[0]['media_item_id']);
    }

    public function testContinueWatchingEpisodeUsesSeriesPosterWhenStoredPosterIsStill(): void
    {
        // Episode with a still as poster → override with series poster.
        // The stored poster_url matches still_url, indicating it's a TMDB still.
        $rows = [
            [
                'id' => 'ep-media-uuid',
                'media_item_id' => 'ep-media-uuid',
                'session_id' => 'session-1',
                'position_ticks' => 1000,
                'duration_ticks' => 10000,
                'playback_status' => 'playing',
                'updated_at' => date('Y-m-d H:i:s'),
                'name' => 'S01E01',
                'type' => 'episode',
                'metadata_json' => '{"poster_url":"/stills/ep01.jpg","still_url":"/stills/ep01.jpg"}',
                'parent_metadata_json' => '{"poster_url":"/season/poster.jpg"}',
                'series_metadata_json' => '{"poster_url":"/series/poster.jpg"}',
            ],
        ];
        $sessionManager = $this->createMock(SessionManager::class);
        $controller = new PlaybackController($this->captureConnection($rows), $sessionManager);

        $result = $controller->getContinueWatching('user-1', 10);

        self::assertCount(1, $result);
        // Series poster must be used, not the still
        self::assertSame('/series/poster.jpg', $result[0]['metadata']['poster_url']);
    }

    public function testContinueWatchingEpisodeUsesSeasonPosterWhenSeriesPosterUnavailable(): void
    {
        // Episode with still as poster, but series poster is null → use season poster.
        $rows = [
            [
                'id' => 'ep-media-uuid',
                'media_item_id' => 'ep-media-uuid',
                'session_id' => 'session-1',
                'position_ticks' => 1000,
                'duration_ticks' => 10000,
                'playback_status' => 'playing',
                'updated_at' => date('Y-m-d H:i:s'),
                'name' => 'S01E01',
                'type' => 'episode',
                'metadata_json' => '{"poster_url":"/stills/ep01.jpg","still_url":"/stills/ep01.jpg"}',
                'parent_metadata_json' => '{"poster_url":"/season/poster.jpg"}',
                'series_metadata_json' => '{}',
            ],
        ];
        $sessionManager = $this->createMock(SessionManager::class);
        $controller = new PlaybackController($this->captureConnection($rows), $sessionManager);

        $result = $controller->getContinueWatching('user-1', 10);

        self::assertCount(1, $result);
        // Season poster must be used as fallback when series poster is unavailable
        self::assertSame('/season/poster.jpg', $result[0]['metadata']['poster_url']);
    }

    public function testContinueWatchingMovieKeepsOwnPoster(): void
    {
        // Movies (and series) retain their own poster_url — no override.
        // The movie has a non-still poster, so it should be preserved.
        $rows = [
            [
                'id' => 'movie-uuid',
                'media_item_id' => 'movie-uuid',
                'session_id' => 'session-1',
                'position_ticks' => 1000,
                'duration_ticks' => 10000,
                'playback_status' => 'playing',
                'updated_at' => date('Y-m-d H:i:s'),
                'name' => 'Big Movie',
                'type' => 'movie',
                // Movie's own poster is set; not a still_url
                'metadata_json' => '{"poster_url":"/movies/big.jpg","title":"Big Movie"}',
                'parent_metadata_json' => null,
                'series_metadata_json' => null,
            ],
        ];
        $sessionManager = $this->createMock(SessionManager::class);
        $controller = new PlaybackController($this->captureConnection($rows), $sessionManager);

        $result = $controller->getContinueWatching('user-1', 10);

        self::assertCount(1, $result);
        // Movie keeps its own poster — series/season posters are not applied to movies
        self::assertSame('/movies/big.jpg', $result[0]['metadata']['poster_url']);
    }

    public function testContinueWatchingRetainsPositionDurationMediaItemIdAndMetadata(): void
    {
        // Verify all required fields are present and correct.
        $rows = [
            [
                'id' => 'media-uuid-123',
                'media_item_id' => 'media-uuid-123',
                'session_id' => 'session-abc',
                'position_ticks' => 3600000,   // 1 hour in ticks
                'duration_ticks' => 7200000,   // 2 hours in ticks
                'playback_status' => 'paused',
                'updated_at' => date('Y-m-d H:i:s'),
                'name' => 'Test Movie',
                'type' => 'movie',
                'metadata_json' => '{"poster_url":"/movie.jpg","title":"Test Movie","year":2024}',
                'parent_metadata_json' => null,
                'series_metadata_json' => null,
            ],
        ];
        $sessionManager = $this->createMock(SessionManager::class);
        $controller = new PlaybackController($this->captureConnection($rows), $sessionManager);

        $result = $controller->getContinueWatching('user-1', 10);

        self::assertCount(1, $result);
        self::assertSame(3600000, $result[0]['position_ticks']);
        self::assertSame(7200000, $result[0]['duration_ticks']);
        self::assertSame('media-uuid-123', $result[0]['media_item_id']);
        self::assertIsArray($result[0]['metadata']);
        self::assertSame('/movie.jpg', $result[0]['metadata']['poster_url']);
        self::assertSame('Test Movie', $result[0]['metadata']['title']);
        self::assertSame(2024, $result[0]['metadata']['year']);
    }

    public function testContinueWatchingPosterNotOverriddenWhenEpisodeHasRealOwnPoster(): void
    {
        // Episode has a real poster (different from still_url and cover images) →
        // it must NOT be overridden by series/season poster.
        $rows = [
            [
                'id' => 'ep-media-uuid',
                'media_item_id' => 'ep-media-uuid',
                'session_id' => 'session-1',
                'position_ticks' => 1000,
                'duration_ticks' => 10000,
                'playback_status' => 'playing',
                'updated_at' => date('Y-m-d H:i:s'),
                'name' => 'S01E01',
                'type' => 'episode',
                // Episode has its own real poster (not a still)
                'metadata_json' => '{"poster_url":"/episodes/realposter.jpg","still_url":"/stills/ep01.jpg","cover_image_large":"/episodes/cover.jpg"}',
                'parent_metadata_json' => '{"poster_url":"/season/poster.jpg"}',
                'series_metadata_json' => '{"poster_url":"/series/poster.jpg"}',
            ],
        ];
        $sessionManager = $this->createMock(SessionManager::class);
        $controller = new PlaybackController($this->captureConnection($rows), $sessionManager);

        $result = $controller->getContinueWatching('user-1', 10);

        self::assertCount(1, $result);
        // Episode's own real poster must be preserved
        self::assertSame('/episodes/realposter.jpg', $result[0]['metadata']['poster_url']);
    }

    /**
     * A Connection stub that records the query + params and returns $rows.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function captureConnection(array $rows): Connection
    {
        $test = $this;

        return new class ($test, $rows) extends Connection {
            /** @param list<array<string, mixed>> $rows */
            public function __construct(
                private readonly PlaybackControllerTest $test,
                private readonly array $rows,
            ) {
                // Skip parent constructor — no real DB connection in unit tests.
            }

            /**
             * @param string                       $query
             * @param array<int|string, mixed>|null $params
             * @param int                          $fetchmode
             * @return list<array<string, mixed>>
             */
            public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
            {
                $this->test->record((string) $query, is_array($params) ? $params : []);

                return $this->rows;
            }
        };
    }

    /** @param array<int|string, mixed> $params */
    public function record(string $query, array $params): void
    {
        $this->captured = ['query' => $query, 'params' => $params];
    }
}
