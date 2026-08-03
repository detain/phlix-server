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
     * ENUM landmine: the stored media_items.type value is `photo` (NOT `image`)
     * and must reach recordPlaybackStart() VERBATIM — no remap to `image`, no
     * clobber to `movie`. Guards the 13-member type ENUM's exact passthrough.
     */
    public function testRecordsPhotoTypeVerbatim(): void
    {
        $db = $this->connectionReturningType([['type' => 'photo']]);

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSession')->willReturn([
            'user_id'   => 'user-5',
            'device_id' => 'device-5',
        ]);

        $stats = $this->createMock(StatsCollector::class);
        $stats->expects($this->once())
            ->method('recordPlaybackStart')
            ->with(
                $this->equalTo('user-5'),
                $this->equalTo('media-photo-1'),
                $this->equalTo('photo'),   // ← verbatim: not 'image', not 'movie'
                $this->equalTo('device-5'),
            )
            ->willReturn('evt-5');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $controller = new PlaybackController($db, $sessionManager, null, $dispatcher, $stats);

        $controller->reportProgress('sess-5', 'media-photo-1', 50, 1000, false);
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
     * A row that exists but has an empty `type` column is treated exactly like a
     * missing row: the empty string is rejected by the `$type !== ''` guard and
     * the fallback 'movie' is recorded rather than an empty type polluting stats.
     */
    public function testFallsBackToMovieWhenTypeIsEmptyString(): void
    {
        // Row present but type = '' → distinct branch of the `$type !== ''` guard.
        $db = $this->connectionReturningType([['type' => '']]);

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSession')->willReturn([
            'user_id'   => 'user-7',
            'device_id' => 'device-7',
        ]);

        $stats = $this->createMock(StatsCollector::class);
        $stats->expects($this->once())
            ->method('recordPlaybackStart')
            ->with(
                $this->equalTo('user-7'),
                $this->equalTo('blank-1'),
                $this->equalTo('movie'),     // ← empty type coerced to the default
                $this->equalTo('device-7'),
            )
            ->willReturn('evt-7');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $controller = new PlaybackController($db, $sessionManager, null, $dispatcher, $stats);

        $controller->reportProgress('sess-7', 'blank-1', 10, 500, false);
    }

    /**
     * S102 — playback must survive a broken stats subsystem.
     *
     * `dispatchPlaybackStarted()` has no try/catch, so before the boundary landed
     * inside {@see StatsCollector} the driver's exception propagated out of
     * `reportProgress()` and into the HTTP worker. Here the collector is REAL
     * (not a mock) over a connection that throws exactly the production error, so
     * the containment is exercised end to end without needing a database — the
     * real-schema half of the proof is
     * {@see \Phlix\Tests\Integration\Stats\PlaybackEventMediaTypeEnumTest}.
     */
    public function testAFailingStatsWriteDoesNotBreakProgressReporting(): void
    {
        $db = $this->connectionReturningType([['type' => 'episode']]);

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSession')->willReturn([
            'user_id'   => 'user-102',
            'device_id' => 'device-102',
        ]);

        // A connection that fails EVERY stats write with the exact production error.
        $statsDb = $this->createMock(Connection::class);
        $statsDb->method('query')->willThrowException(new \PDOException(
            "SQLSTATE[01000]: Warning: 1265 Data truncated for column 'media_type' at row 1"
        ));

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')->willReturnArgument(0);

        $controller = new PlaybackController(
            $db,
            $sessionManager,
            null,
            $dispatcher,
            new StatsCollector($statsDb),
        );

        // No expectException(): reporting progress must complete, and the
        // PlaybackStarted event must still be dispatched (asserted above) — the
        // stats failure may not swallow the rest of the method either.
        $controller->reportProgress('sess-102', 'media-ep-102', 100, 2000, false);

        $this->assertTrue(true, 'reportProgress() completed despite the stats write failing');
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
