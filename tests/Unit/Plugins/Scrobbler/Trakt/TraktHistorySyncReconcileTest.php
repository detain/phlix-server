<?php

/**
 * Phlix media server component: Trakt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Auth\WatchHistory;
use Phlix\Plugins\Scrobbler\Trakt\TraktApi;
use Phlix\Plugins\Scrobbler\Trakt\TraktApiException;
use Phlix\Plugins\Scrobbler\Trakt\TraktHistorySync;
use Phlix\Plugins\Scrobbler\Trakt\TraktSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Workerman\MySQL\Connection;

/**
 * SV-3.6e — reconciliation tests for the Trakt → Phlix PULL sync.
 *
 * Covers the AC-critical reconciliation core that the cumulative review flagged
 * as untested (finding #2): watched-history → local completion, playback →
 * resume position, last-write-wins, never-downgrade-completed, no-known-duration
 * skip, the COALESCE-safe null duration on the completed path, and the SV-3.6d
 * pagination page loop (multi-page fetch, reported-count / cap termination,
 * per-page-failure preserving earlier writes).
 *
 * Uses a mocked {@see TraktApi} (feeding getWatchedHistory / getPlaybackProgress
 * fixtures), a mocked {@see WatchHistory} (asserting the reconciled writes), and
 * a mocked {@see Connection}. Trakt items carry the `_resolved_media_item_id`
 * test seam so id resolution is deterministic without a live DB (one test
 * deliberately omits it to exercise the JSON_EXTRACT lookup path).
 */
final class TraktHistorySyncReconcileTest extends TestCase
{
    private const PROFILE = 'default';

    /**
     * Requirement #2 (watched → completed) + #7 (duration COALESCE null).
     *
     * A fully-watched Trakt item with no `runtime` (Trakt omits it without
     * extended=full) becomes a local completion, and the duration bound to
     * updateProgress is `null` (NOT 0) so `COALESCE(?, duration_ticks)` preserves
     * a previously-known duration. The strict identicalTo(null) constraint would
     * fail if the code passed 0 (0 == null under a loose compare, but 0 !== null).
     */
    public function testWatchedHistoryReconcilesToCompletedWithNullDuration(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn([
            'items' => [
                [
                    'movie' => ['ids' => ['tmdb' => 500]],
                    '_resolved_media_item_id' => 'm1',
                    'watched_at' => '2026-01-01T00:00:00Z',
                ],
            ],
            'pageCount' => 1,
        ]);
        $api->method('getPlaybackProgress')->willReturn([]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn(null);
        $wh->expects($this->once())
            ->method('updateProgress')
            ->with(
                $this->identicalTo(self::PROFILE),
                $this->identicalTo('m1'),
                $this->identicalTo(0),
                $this->identicalTo(null),
                $this->identicalTo(WatchHistory::STATUS_COMPLETED)
            )
            ->willReturn([]);

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(1, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * When Trakt DOES report a `runtime`, the completed path uses it as both
     * position and duration (the fully-watched 100% shape).
     */
    public function testWatchedHistoryWithRuntimeUsesFullDuration(): void
    {
        $expectedTicks = 120 * WatchHistory::TICKS_PER_SECOND;

        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn([
            'items' => [
                [
                    'movie' => ['ids' => ['tmdb' => 501]],
                    '_resolved_media_item_id' => 'm1b',
                    'watched_at' => '2026-01-01T00:00:00Z',
                    'runtime' => 120,
                ],
            ],
            'pageCount' => 1,
        ]);
        $api->method('getPlaybackProgress')->willReturn([]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn(null);
        $wh->expects($this->once())
            ->method('updateProgress')
            ->with(
                $this->identicalTo(self::PROFILE),
                $this->identicalTo('m1b'),
                $this->identicalTo($expectedTicks),
                $this->identicalTo($expectedTicks),
                $this->identicalTo(WatchHistory::STATUS_COMPLETED)
            )
            ->willReturn([]);

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(1, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * Requirement #3 (playback → resume position).
     *
     * An in-progress `/sync/playback` item writes a resume position of
     * duration·percent/100 with status PAUSED — NOT a forced 100%. Duration comes
     * from the local record's stored `duration_ticks`.
     */
    public function testPlaybackProgressWritesResumePosition(): void
    {
        $durationTicks = 7200 * WatchHistory::TICKS_PER_SECOND; // 2h
        $expectedPosition = (int) round($durationTicks * 0.5);

        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn(['items' => [], 'pageCount' => 1]);
        $api->method('getPlaybackProgress')->willReturn([
            [
                'movie' => ['ids' => ['tmdb' => 7]],
                '_resolved_media_item_id' => 'm2',
                'progress' => 50.0,
                'paused_at' => '2026-02-01T00:00:00Z',
            ],
        ]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn([
            'duration_ticks' => $durationTicks,
            'playback_status' => WatchHistory::STATUS_PAUSED,
            'progress_percent' => 10.0,
            'last_watched_at' => '2020-01-01 00:00:00',
        ]);
        $wh->expects($this->once())
            ->method('updateProgress')
            ->with(
                $this->identicalTo(self::PROFILE),
                $this->identicalTo('m2'),
                $this->identicalTo($expectedPosition),
                $this->identicalTo($durationTicks),
                $this->identicalTo(WatchHistory::STATUS_PAUSED)
            )
            ->willReturn([]);

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(1, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * A resume position is still written when the local `last_watched_at` is
     * unparseable (parseLocalTimestamp returns null → traktSupersedes defers to
     * the status guard, which is not completed here).
     */
    public function testResumeWrittenWhenLocalTimestampUnparseable(): void
    {
        $durationTicks = 3600 * WatchHistory::TICKS_PER_SECOND;

        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn(['items' => [], 'pageCount' => 1]);
        $api->method('getPlaybackProgress')->willReturn([
            [
                'episode' => ['ids' => ['tvdb' => 314]],
                '_resolved_media_item_id' => 'm2b',
                'progress' => 25.0,
                'paused_at' => '2026-02-01T00:00:00Z',
            ],
        ]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn([
            'duration_ticks' => $durationTicks,
            'playback_status' => WatchHistory::STATUS_PAUSED,
            'progress_percent' => 5.0,
            'last_watched_at' => 'not-a-timestamp',
        ]);
        $wh->expects($this->once())
            ->method('updateProgress')
            ->with(
                $this->identicalTo(self::PROFILE),
                $this->identicalTo('m2b'),
                $this->identicalTo((int) round($durationTicks * 0.25)),
                $this->identicalTo($durationTicks),
                $this->identicalTo(WatchHistory::STATUS_PAUSED)
            )
            ->willReturn([]);

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(1, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * Requirement #4 (last-write-wins).
     *
     * A Trakt "watched" event OLDER than the local `last_watched_at` is skipped
     * — no write clobbers a fresher local (in-progress) record.
     */
    public function testOlderTraktWatchIsSkipped(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn([
            'items' => [
                [
                    'movie' => ['ids' => ['tmdb' => 600]],
                    '_resolved_media_item_id' => 'm3',
                    'watched_at' => '2020-01-01T00:00:00Z',
                ],
            ],
            'pageCount' => 1,
        ]);
        $api->method('getPlaybackProgress')->willReturn([]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn([
            'playback_status' => WatchHistory::STATUS_PAUSED,
            'progress_percent' => 20.0,
            'last_watched_at' => '2030-06-01 12:00:00',
        ]);
        $wh->expects($this->never())->method('updateProgress');

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(0, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * Requirement #5 (never-downgrade-completed).
     *
     * A locally-completed item is not downgraded to in-progress by a Trakt
     * playback entry, even a newer one.
     */
    public function testCompletedItemNotDowngradedByPlayback(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn(['items' => [], 'pageCount' => 1]);
        $api->method('getPlaybackProgress')->willReturn([
            [
                'episode' => ['ids' => ['tvdb' => 99]],
                '_resolved_media_item_id' => 'm4',
                'progress' => 30.0,
                'paused_at' => '2030-01-01T00:00:00Z',
            ],
        ]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn([
            'playback_status' => WatchHistory::STATUS_COMPLETED,
            'progress_percent' => 100.0,
            'duration_ticks' => 1000,
            'last_watched_at' => '2020-01-01 00:00:00',
        ]);
        $wh->expects($this->never())->method('updateProgress');

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(0, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * isLocallyCompleted also short-circuits on the progress threshold (>= 90%),
     * not only the explicit 'completed' status — a watched item is skipped when
     * the local record is already at/above the completion threshold.
     */
    public function testWatchedSkippedWhenLocallyAtCompletionThreshold(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn([
            'items' => [
                [
                    'movie' => ['ids' => ['tmdb' => 601]],
                    '_resolved_media_item_id' => 'm4b',
                    'watched_at' => '2030-01-01T00:00:00Z',
                ],
            ],
            'pageCount' => 1,
        ]);
        $api->method('getPlaybackProgress')->willReturn([]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn([
            'playback_status' => WatchHistory::STATUS_PAUSED,
            'progress_percent' => 95.0,
            'last_watched_at' => '2020-01-01 00:00:00',
        ]);
        $wh->expects($this->never())->method('updateProgress');

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(0, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * Requirement #6 (no-known-duration skip).
     *
     * An in-progress Trakt item for a media item with no known duration (no local
     * duration_ticks, no scanned metadata) is skipped — no bogus seek is written.
     */
    public function testPlaybackSkippedWhenNoDurationKnown(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn(['items' => [], 'pageCount' => 1]);
        $api->method('getPlaybackProgress')->willReturn([
            [
                'movie' => ['ids' => ['tmdb' => 8]],
                '_resolved_media_item_id' => 'm5',
                'progress' => 40.0,
                'paused_at' => '2030-01-01T00:00:00Z',
            ],
        ]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn([
            'duration_ticks' => 0,
            'playback_status' => WatchHistory::STATUS_PAUSED,
            'progress_percent' => 5.0,
            'last_watched_at' => '2020-01-01 00:00:00',
        ]);
        $wh->expects($this->never())->method('updateProgress');

        // metadata duration lookup also yields nothing.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['dur' => null]]);

        $sync = $this->makeSync($api, $wh, $db);

        $this->assertSame(0, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * A playback entry with zero (or absent) progress is skipped.
     */
    public function testPlaybackSkippedWhenProgressZero(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn(['items' => [], 'pageCount' => 1]);
        $api->method('getPlaybackProgress')->willReturn([
            [
                'movie' => ['ids' => ['tmdb' => 9]],
                '_resolved_media_item_id' => 'm6',
                'progress' => 0.0,
                'paused_at' => '2030-01-01T00:00:00Z',
            ],
        ]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->expects($this->never())->method('updateProgress');

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(0, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * Requirement #8 (pagination) — reported count > 1 fetches pages 2..N and
     * reconciles the total across pages; a short final page terminates.
     */
    public function testPaginationFetchesAllReportedPages(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturnCallback(
            function (string $username, int $page, int $limit, string $token): array {
                if ($page === 1) {
                    return ['items' => $this->makeItems(1, 100), 'pageCount' => 2];
                }
                // Page 2: short (< limit) → terminates the loop.
                return ['items' => $this->makeItems(1000, 30), 'pageCount' => 2];
            }
        );
        $api->method('getPlaybackProgress')->willReturn([]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn(null);
        $wh->expects($this->exactly(130))->method('updateProgress')->willReturn([]);

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(130, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * Requirement #8 (pagination termination) — the reported page count bounds
     * the loop even when every page is FULL (== limit): after page 2 (== reported
     * count) the walk stops without fetching page 3.
     */
    public function testPaginationStopsAtReportedPageCountEvenIfPagesFull(): void
    {
        /** @var list<int> $pagesRequested */
        $pagesRequested = [];

        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturnCallback(
            function (string $username, int $page, int $limit, string $token) use (&$pagesRequested): array {
                $pagesRequested[] = $page;
                return ['items' => $this->makeItems($page * 1000, 100), 'pageCount' => 2];
            }
        );
        $api->method('getPlaybackProgress')->willReturn([]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn(null);
        $wh->method('updateProgress')->willReturn([]);

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(200, $sync->syncTraktToPhlix(self::PROFILE));
        $this->assertSame([1, 2], $pagesRequested);
    }

    /**
     * Requirement #8 (cap) — a reported page count exceeding MAX_HISTORY_PAGES is
     * truncated to the cap (exercising the truncate/warn branch); the walk still
     * terminates via a short final page.
     */
    public function testReportedPageCountAboveCapIsTruncated(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturnCallback(
            function (string $username, int $page, int $limit, string $token): array {
                if ($page === 1) {
                    // Report an absurd page count to trip the > cap truncation.
                    return ['items' => $this->makeItems(1, 100), 'pageCount' => 5000];
                }
                return ['items' => $this->makeItems(1000, 10), 'pageCount' => 5000];
            }
        );
        $api->method('getPlaybackProgress')->willReturn([]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn(null);
        $wh->expects($this->exactly(110))->method('updateProgress')->willReturn([]);

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(110, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * Requirement #8 (per-page failure) — a fetch failure on a later page is
     * logged and ends the walk, PRESERVING the reconciliations already written by
     * earlier pages (page 1's 100 writes are kept even though page 2 throws).
     */
    public function testPageFetchFailurePreservesEarlierWrites(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturnCallback(
            function (string $username, int $page, int $limit, string $token): array {
                if ($page === 1) {
                    return ['items' => $this->makeItems(1, 100), 'pageCount' => 3];
                }
                throw new TraktApiException('page 2 fetch failed');
            }
        );
        $api->method('getPlaybackProgress')->willReturn([]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn(null);
        $wh->expects($this->exactly(100))->method('updateProgress')->willReturn([]);

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(100, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * Fault isolation — a watched-history fetch failure contributes 0 but does
     * NOT discard the playback (resume) reconciliation.
     */
    public function testWatchedFetchFailureIsIsolatedFromPlayback(): void
    {
        $durationTicks = 4000 * WatchHistory::TICKS_PER_SECOND;

        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willThrowException(new TraktApiException('history down'));
        $api->method('getPlaybackProgress')->willReturn([
            [
                'movie' => ['ids' => ['tmdb' => 11]],
                '_resolved_media_item_id' => 'p1',
                'progress' => 25.0,
                'paused_at' => '2030-01-01T00:00:00Z',
            ],
        ]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn([
            'duration_ticks' => $durationTicks,
            'playback_status' => WatchHistory::STATUS_PAUSED,
            'progress_percent' => 1.0,
            'last_watched_at' => '2000-01-01 00:00:00',
        ]);
        $wh->expects($this->once())
            ->method('updateProgress')
            ->with(
                $this->identicalTo(self::PROFILE),
                $this->identicalTo('p1'),
                $this->identicalTo((int) round($durationTicks * 0.25)),
                $this->identicalTo($durationTicks),
                $this->identicalTo(WatchHistory::STATUS_PAUSED)
            )
            ->willReturn([]);

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(1, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * Fault isolation — a playback fetch failure contributes 0 but does NOT
     * discard the watched-history completions.
     */
    public function testPlaybackFetchFailureIsIsolated(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn([
            'items' => [
                [
                    'movie' => ['ids' => ['tmdb' => 12]],
                    '_resolved_media_item_id' => 'w1',
                    'watched_at' => '2026-01-01T00:00:00Z',
                ],
            ],
            'pageCount' => 1,
        ]);
        $api->method('getPlaybackProgress')->willThrowException(new TraktApiException('playback down'));

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn(null);
        $wh->expects($this->once())
            ->method('updateProgress')
            ->with(
                $this->identicalTo(self::PROFILE),
                $this->identicalTo('w1'),
                $this->identicalTo(0),
                $this->identicalTo(null),
                $this->identicalTo(WatchHistory::STATUS_COMPLETED)
            )
            ->willReturn([]);

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(1, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * When no `_resolved_media_item_id` seam is present, the local id is resolved
     * via the JSON_EXTRACT external-id lookup against the DB (findMediaItemId →
     * findMediaItemIdByExternalId).
     */
    public function testMediaItemResolvedViaDatabaseExternalId(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn([
            'items' => [
                [
                    'movie' => ['ids' => ['tmdb' => 555]],
                    'watched_at' => '2026-01-01T00:00:00Z',
                ],
            ],
            'pageCount' => 1,
        ]);
        $api->method('getPlaybackProgress')->willReturn([]);

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['id' => 'db-resolved-1']]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn(null);
        $wh->expects($this->once())
            ->method('updateProgress')
            ->with(
                $this->identicalTo(self::PROFILE),
                $this->identicalTo('db-resolved-1'),
                $this->identicalTo(0),
                $this->identicalTo(null),
                $this->identicalTo(WatchHistory::STATUS_COMPLETED)
            )
            ->willReturn([]);

        $sync = $this->makeSync($api, $wh, $db);

        $this->assertSame(1, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * A Trakt item carrying neither movie nor episode ids is skipped (no local
     * media item can be resolved).
     */
    public function testItemWithoutIdsIsSkipped(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn([
            'items' => [
                ['watched_at' => '2026-01-01T00:00:00Z'],
            ],
            'pageCount' => 1,
        ]);
        $api->method('getPlaybackProgress')->willReturn([]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->expects($this->never())->method('updateProgress');

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(0, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * The sync is a no-op (returns 0, touches no Trakt API) when the plugin is
     * not configured.
     */
    public function testSyncSkippedWhenNotConfigured(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->expects($this->never())->method('getWatchedHistory');
        $api->expects($this->never())->method('getPlaybackProgress');

        $wh = $this->createMock(WatchHistory::class);
        $wh->expects($this->never())->method('updateProgress');

        // Empty settings → hasTokens() false → isConfigured() false.
        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class), new TraktSettings());

        $this->assertSame(0, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * The sync is a no-op when sync is explicitly disabled in settings.
     */
    public function testSyncSkippedWhenSyncDisabled(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->expects($this->never())->method('getWatchedHistory');
        $api->expects($this->never())->method('getPlaybackProgress');

        $wh = $this->createMock(WatchHistory::class);
        $wh->expects($this->never())->method('updateProgress');

        $settings = new TraktSettings(
            accessToken: 'access',
            refreshToken: 'refresh',
            username: 'testuser',
            syncEnabled: false,
        );
        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class), $settings);

        $this->assertSame(0, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * A non-array item on a page is skipped, and an item with a malformed
     * `watched_at` still reconciles (parseTraktTimestamp falls back to "now",
     * treated as newest, so the item is not silently dropped).
     */
    public function testNonArrayAndMalformedWatchedItemsHandled(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn([
            'items' => [
                'not-an-array',
                [
                    'movie' => ['ids' => ['tmdb' => 700]],
                    '_resolved_media_item_id' => 'mm1',
                    'watched_at' => 'totally-not-a-date',
                ],
            ],
            'pageCount' => 1,
        ]);
        $api->method('getPlaybackProgress')->willReturn([]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn(null);
        $wh->expects($this->once())
            ->method('updateProgress')
            ->with(
                $this->identicalTo(self::PROFILE),
                $this->identicalTo('mm1'),
                $this->identicalTo(0),
                $this->identicalTo(null),
                $this->identicalTo(WatchHistory::STATUS_COMPLETED)
            )
            ->willReturn([]);

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(1, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * When the local record has no stored duration, the resume duration is taken
     * from the scanner's `metadata_json.duration_seconds` (converted to ticks).
     */
    public function testPlaybackUsesScannedMetadataDuration(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn(['items' => [], 'pageCount' => 1]);
        $api->method('getPlaybackProgress')->willReturn([
            [
                'movie' => ['ids' => ['tmdb' => 20]],
                '_resolved_media_item_id' => 'md1',
                'progress' => 50.0,
                'paused_at' => '2030-01-01T00:00:00Z',
            ],
        ]);

        // No local row → duration resolves from scanned metadata (3600s).
        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn(null);

        $expectedDuration = 3600 * WatchHistory::TICKS_PER_SECOND;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['dur' => 3600]]);

        $wh->expects($this->once())
            ->method('updateProgress')
            ->with(
                $this->identicalTo(self::PROFILE),
                $this->identicalTo('md1'),
                $this->identicalTo((int) round($expectedDuration * 0.5)),
                $this->identicalTo($expectedDuration),
                $this->identicalTo(WatchHistory::STATUS_PAUSED)
            )
            ->willReturn([]);

        $sync = $this->makeSync($api, $wh, $db);

        $this->assertSame(1, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * Playback reconciliation skips: a non-array entry, an entry with no
     * resolvable ids, and an entry whose `paused_at` is older than the local
     * `last_watched_at` (last-write-wins on the playback path).
     */
    public function testPlaybackSkipsNonArrayUnresolvedAndOlderEntries(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn(['items' => [], 'pageCount' => 1]);
        $api->method('getPlaybackProgress')->willReturn([
            'not-an-array',
            ['progress' => 60.0, 'paused_at' => '2030-01-01T00:00:00Z'], // no ids
            [
                'movie' => ['ids' => ['tmdb' => 30]],
                '_resolved_media_item_id' => 'older1',
                'progress' => 30.0,
                'paused_at' => '2020-01-01T00:00:00Z', // older than local
            ],
        ]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn([
            'duration_ticks' => 1000,
            'playback_status' => WatchHistory::STATUS_PAUSED,
            'progress_percent' => 15.0,
            'last_watched_at' => '2035-01-01 00:00:00', // newer than Trakt
        ]);
        $wh->expects($this->never())->method('updateProgress');

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(0, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * A playback entry with negative or non-numeric progress is skipped
     * (extractProgressPercent returns null).
     */
    public function testPlaybackNegativeAndNonNumericProgressSkipped(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn(['items' => [], 'pageCount' => 1]);
        $api->method('getPlaybackProgress')->willReturn([
            [
                'movie' => ['ids' => ['tmdb' => 41]],
                '_resolved_media_item_id' => 'neg1',
                'progress' => -5.0,
                'paused_at' => '2030-01-01T00:00:00Z',
            ],
            [
                'movie' => ['ids' => ['tmdb' => 42]],
                '_resolved_media_item_id' => 'nan1',
                'progress' => 'not-a-number',
                'paused_at' => '2030-01-01T00:00:00Z',
            ],
        ]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->expects($this->never())->method('updateProgress');

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(0, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * Id resolution falls through to a second id type when the first misses in
     * the DB (findMediaItemIdByExternalId returns null for tmdb, resolves tvdb).
     */
    public function testMediaItemResolvedViaSecondIdTypeAfterMiss(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn([
            'items' => [
                [
                    'movie' => ['ids' => ['tmdb' => 111, 'tvdb' => 222]],
                    'watched_at' => '2026-01-01T00:00:00Z',
                ],
            ],
            'pageCount' => 1,
        ]);
        $api->method('getPlaybackProgress')->willReturn([]);

        // First lookup (tmdb) misses (empty), second (tvdb) resolves.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls([], [['id' => 'db-2']]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn(null);
        $wh->expects($this->once())
            ->method('updateProgress')
            ->with(
                $this->identicalTo(self::PROFILE),
                $this->identicalTo('db-2'),
                $this->identicalTo(0),
                $this->identicalTo(null),
                $this->identicalTo(WatchHistory::STATUS_COMPLETED)
            )
            ->willReturn([]);

        $sync = $this->makeSync($api, $wh, $db);

        $this->assertSame(1, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * An item with ids that resolve to nothing in the DB is skipped (the DB
     * lookup returns a non-array / no row → findMediaItemId returns null).
     */
    public function testItemWithUnresolvableIdsIsSkipped(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn([
            'items' => [
                [
                    'movie' => ['ids' => ['tmdb' => 999]],
                    'watched_at' => '2026-01-01T00:00:00Z',
                ],
            ],
            'pageCount' => 1,
        ]);
        $api->method('getPlaybackProgress')->willReturn([]);

        // Unstubbed query() returns null → findMediaItemIdByExternalId yields null.
        $wh = $this->createMock(WatchHistory::class);
        $wh->expects($this->never())->method('updateProgress');

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(0, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * A non-scalar external id is skipped, and a DB row whose `id` is neither a
     * string nor numeric yields no match — the item resolves to nothing.
     */
    public function testExternalIdNonScalarAndUnusableRowsHandled(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn([
            'items' => [
                [
                    // tmdb is a nested array (non-scalar → skipped); tvdb queried.
                    'movie' => ['ids' => ['tmdb' => ['nested' => 'x'], 'tvdb' => 222]],
                    'watched_at' => '2026-01-01T00:00:00Z',
                ],
            ],
            'pageCount' => 1,
        ]);
        $api->method('getPlaybackProgress')->willReturn([]);

        // tvdb lookup returns a row whose id is null → unusable → null.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['id' => null]]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->expects($this->never())->method('updateProgress');

        $sync = $this->makeSync($api, $wh, $db);

        $this->assertSame(0, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * A resume is skipped when the local record has no `last_watched_at` (the
     * timestamp guard defers) AND no resolvable duration (metadata query returns
     * nothing) — exercising the null-local-timestamp and non-array-result paths.
     */
    public function testResumeSkippedWhenNoLocalTimestampAndMetadataMiss(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn(['items' => [], 'pageCount' => 1]);
        $api->method('getPlaybackProgress')->willReturn([
            [
                'movie' => ['ids' => ['tmdb' => 77]],
                '_resolved_media_item_id' => 'nt1',
                'progress' => 40.0,
                'paused_at' => '2030-01-01T00:00:00Z',
            ],
        ]);

        $wh = $this->createMock(WatchHistory::class);
        // No last_watched_at key, no usable duration.
        $wh->method('getForMediaItem')->willReturn([
            'duration_ticks' => 0,
            'playback_status' => WatchHistory::STATUS_PAUSED,
            'progress_percent' => 5.0,
        ]);
        $wh->expects($this->never())->method('updateProgress');

        // Unstubbed query() returns null → metadata lookup yields 0.
        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(0, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * The metadata duration lookup tolerates a malformed result row (result is an
     * array but its first element is not a row array) → duration 0 → skipped.
     */
    public function testResumeSkippedWhenMetadataRowMalformed(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn(['items' => [], 'pageCount' => 1]);
        $api->method('getPlaybackProgress')->willReturn([
            [
                'movie' => ['ids' => ['tmdb' => 78]],
                '_resolved_media_item_id' => 'mr1',
                'progress' => 40.0,
                'paused_at' => '2030-01-01T00:00:00Z',
            ],
        ]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn(null);
        $wh->expects($this->never())->method('updateProgress');

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(['not-a-row']);

        $sync = $this->makeSync($api, $wh, $db);

        $this->assertSame(0, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * extractDurationTicks resolves a `runtime` nested under movie/episode (not
     * just a top-level runtime) for the completed 100% shape.
     */
    public function testWatchedRuntimeNestedUnderMovieAndEpisode(): void
    {
        $movieTicks = 90 * WatchHistory::TICKS_PER_SECOND;
        $episodeTicks = 45 * WatchHistory::TICKS_PER_SECOND;

        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn([
            'items' => [
                [
                    'movie' => ['ids' => ['tmdb' => 810], 'runtime' => 90],
                    '_resolved_media_item_id' => 'r1',
                    'watched_at' => '2026-01-01T00:00:00Z',
                ],
                [
                    'episode' => ['ids' => ['tvdb' => 811], 'runtime' => 45],
                    '_resolved_media_item_id' => 'r2',
                    'watched_at' => '2026-01-01T00:00:00Z',
                ],
            ],
            'pageCount' => 1,
        ]);
        $api->method('getPlaybackProgress')->willReturn([]);

        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getForMediaItem')->willReturn(null);
        $matcher = $this->exactly(2);
        $wh->expects($matcher)
            ->method('updateProgress')
            ->willReturnCallback(function (
                string $profileId,
                string $mediaItemId,
                int $positionTicks,
                ?int $durationTicks,
                string $status
            ) use (
                $matcher,
                $movieTicks,
                $episodeTicks
            ): array {
                $this->assertSame(self::PROFILE, $profileId);
                $this->assertSame(WatchHistory::STATUS_COMPLETED, $status);
                if ($matcher->numberOfInvocations() === 1) {
                    $this->assertSame('r1', $mediaItemId);
                    $this->assertSame($movieTicks, $positionTicks);
                    $this->assertSame($movieTicks, $durationTicks);
                } else {
                    $this->assertSame('r2', $mediaItemId);
                    $this->assertSame($episodeTicks, $positionTicks);
                    $this->assertSame($episodeTicks, $durationTicks);
                }
                return [];
            });

        $sync = $this->makeSync($api, $wh, $this->createMock(Connection::class));

        $this->assertSame(2, $sync->syncTraktToPhlix(self::PROFILE));
    }

    /**
     * Build a configured TraktHistorySync for the reconciliation tests.
     */
    private function makeSync(
        TraktApi $api,
        WatchHistory $wh,
        Connection $db,
        ?TraktSettings $settings = null,
    ): TraktHistorySync {
        $settings ??= new TraktSettings(
            accessToken: 'access',
            refreshToken: 'refresh',
            username: 'testuser',
            syncEnabled: true,
        );

        return new TraktHistorySync($api, $wh, $settings, $db, new NullLogger());
    }

    /**
     * Generate a page of $count resolvable Trakt watched-history items with
     * unique ids and the `_resolved_media_item_id` seam.
     *
     * @return list<array<string, mixed>>
     */
    private function makeItems(int $start, int $count): array
    {
        $items = [];
        for ($i = $start; $i < $start + $count; $i++) {
            $items[] = [
                'movie' => ['ids' => ['tmdb' => $i]],
                '_resolved_media_item_id' => 'm' . $i,
            ];
        }

        return $items;
    }
}
