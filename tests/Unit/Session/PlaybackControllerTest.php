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
