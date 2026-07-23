<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session;

use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Phlix\Stats\StatsCollector;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Workerman\MySQL\Connection;

/**
 * S31: the playback-start stats row must carry the REAL media_items.type, not
 * the previously hardcoded 'movie'. Without this, Top Media / Most Watched
 * aggregate every episode/track/photo play under 'movie'.
 *
 * @covers \Phlix\Session\PlaybackController
 */
final class PlaybackControllerStatsTypeTest extends TestCase
{
    /**
     * A fresh (previously-unseen) session/media pair triggers dispatchPlaybackStarted,
     * which must resolve the item's actual type from media_items and hand it to
     * StatsCollector::recordPlaybackStart().
     */
    public function testRecordsRealMediaTypeForNonMovieItem(): void
    {
        // media_items lookup returns an EPISODE — the exact value that used to be
        // clobbered to 'movie'.
        $db = $this->connectionReturningType([['type' => 'episode']]);

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSession')->willReturn([
            'user_id'   => 'user-1',
            'device_id' => 'device-1',
        ]);

        $stats = $this->createMock(StatsCollector::class);
        $stats->expects($this->once())
            ->method('recordPlaybackStart')
            ->with(
                $this->equalTo('user-1'),
                $this->equalTo('media-ep-1'),
                $this->equalTo('episode'),   // ← the fix: NOT 'movie'
                $this->equalTo('device-1'),
            )
            ->willReturn('evt-1');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $controller = new PlaybackController($db, $sessionManager, null, $dispatcher, $stats);

        // previousStatus is null (playback_state SELECT returns []), so this is a
        // "start" transition and dispatchPlaybackStarted runs.
        $controller->reportProgress('sess-1', 'media-ep-1', 100, 2000, false);
    }

    /**
     * When the item row is missing (since-deleted), the type falls back to
     * 'movie' — the SAME default MediaItemShaper::shape() uses for an unknown
     * type — rather than inventing a new sentinel.
     */
    public function testFallsBackToMovieWhenItemMissing(): void
    {
        // media_items lookup returns nothing → fallback default.
        $db = $this->connectionReturningType([]);

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSession')->willReturn([
            'user_id'   => 'user-9',
            'device_id' => 'device-9',
        ]);

        $stats = $this->createMock(StatsCollector::class);
        $stats->expects($this->once())
            ->method('recordPlaybackStart')
            ->with(
                $this->equalTo('user-9'),
                $this->equalTo('gone-1'),
                $this->equalTo('movie'),     // ← safe fallback default
                $this->equalTo('device-9'),
            )
            ->willReturn('evt-9');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $controller = new PlaybackController($db, $sessionManager, null, $dispatcher, $stats);

        $controller->reportProgress('sess-9', 'gone-1', 0, 0, false);
    }

    /**
     * Build a Connection stub whose media_items type-lookup returns $typeRows and
     * whose playback_state status lookup returns [] (so the first report is treated
     * as a fresh start).
     *
     * @param list<array<string, mixed>> $typeRows Rows for the media_items.type SELECT.
     */
    private function connectionReturningType(array $typeRows): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function ($sql = '', $params = null) use ($typeRows): array {
                if (is_string($sql) && str_contains($sql, 'SELECT type FROM media_items')) {
                    return $typeRows;
                }
                // playback_state status lookup + the INSERT both return no rows.
                return [];
            }
        );

        return $db;
    }
}
