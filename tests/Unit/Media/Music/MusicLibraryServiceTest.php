<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Phlix\Media\Library\ScanResult;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Music\MusicLibraryService;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see MusicLibraryService}, focused on the scan/progress
 * forwarding added for the music-scan-hang fix.
 */
final class MusicLibraryServiceTest extends TestCase
{
    public function testScanDirectoryForwardsPathAndProgressSinkToScanner(): void
    {
        $sink = static function (int $p, int $t, string $path): void {
            unset($p, $t, $path);
        };
        $expected = new ScanResult();

        $scanner = $this->createMock(MusicLibraryScanner::class);
        $scanner->expects($this->once())
            ->method('scanDirectory')
            ->with('/music/rock', $this->identicalTo($sink))
            ->willReturn($expected);

        $service = new MusicLibraryService($this->createMock(Connection::class), $scanner);

        $this->assertSame($expected, $service->scanDirectory('/music/rock', $sink));
    }

    public function testScanDirectoryForwardsNullSinkByDefault(): void
    {
        $scanner = $this->createMock(MusicLibraryScanner::class);
        $scanner->expects($this->once())
            ->method('scanDirectory')
            ->with('/music/jazz', null)
            ->willReturn(new ScanResult());

        $service = new MusicLibraryService($this->createMock(Connection::class), $scanner);
        $service->scanDirectory('/music/jazz');
    }

    public function testCountFilesDelegatesToScanner(): void
    {
        $scanner = $this->createMock(MusicLibraryScanner::class);
        $scanner->expects($this->once())
            ->method('countAudioFiles')
            ->with('/music/rock')
            ->willReturn(42);

        $service = new MusicLibraryService($this->createMock(Connection::class), $scanner);
        $this->assertSame(42, $service->countFiles('/music/rock'));
    }

    /**
     * S94: `music_albums` has a `title` column and NO `name` column, so the
     * tracks join must read `al.title` — `al.name` made every
     * `/api/v1/music/tracks` call 500 with "Unknown column 'al.name' in 'field
     * list'". A mocked connection cannot reject a bad column name (that is
     * exactly how the defect shipped), so this test inspects the SQL the
     * service emits; the real-DB proof lives in
     * {@see \Phlix\Tests\Integration\Media\MusicTracksQueryIntegrationTest}.
     *
     * Also pins the `AS album_name` output alias, which is API contract:
     * {@see \Phlix\Server\Http\Controllers\MusicController} reads
     * `$row['album_name']` (S99 — before that it was the now-deleted
     * `WebPortalRouter::getMusicTracks()`), so renaming the alias would silently
     * blank the album on every track card.
     */
    public function testGetAllTracksSelectsAndOrdersByTheAlbumTitleColumn(): void
    {
        /** @var array{sql: string, params: mixed}|null $captured */
        $captured = null;

        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(
                /**
                 * @return list<array<string, mixed>>
                 */
                function (mixed $sql = '', mixed $params = null) use (&$captured): array {
                    $captured = [
                        'sql' => is_string($sql) ? $sql : '',
                        'params' => $params,
                    ];
                    return [];
                }
            );

        $service = new MusicLibraryService($db, $this->createMock(MusicLibraryScanner::class));
        $service->getAllTracks(25, 5);

        $this->assertIsArray($captured);
        $sql = $captured['sql'];

        // The album title column, aliased to the contractual output key.
        $this->assertMatchesRegularExpression(
            '/al\.title\s+AS\s+album_name/i',
            $sql,
            'The tracks query must select music_albums.title AS album_name'
        );
        // And the ORDER BY must use it too (the second failing site).
        $this->assertMatchesRegularExpression(
            '/ORDER\s+BY\s+ar\.name,\s*al\.title,\s*t\.disc_number,\s*t\.track_number/i',
            $sql,
            'The tracks query must order by artist name, album title, disc, track'
        );
        // No reference to the non-existent music_albums.name column anywhere.
        $this->assertStringNotContainsString(
            'al.name',
            $sql,
            'music_albums has no `name` column — al.name raises SQLSTATE 42S22'
        );
        // Pagination is still clamped and bound positionally.
        $this->assertSame([25, 5], $captured['params']);
    }

    /**
     * HIGH-1 (S99 review r1): the album listing embeds each album's tracks, and
     * that batch had NO `LIMIT`. Clamping the album page to 100 bounds nothing —
     * 100 albums may hold 30,000 tracks, each costing an `hash_hmac()` mint on the
     * event loop and all of it buffered whole by two shared hub workers.
     *
     * Pins BOTH bounds and the round-robin ordering that makes the batch ceiling
     * degrade fairly (a plain `LIMIT` over `ORDER BY album_id` would hand the tail
     * of the page an EMPTY track list, which reads as a broken album).
     */
    public function testGetTracksByAlbumIdsWindowsPerAlbumAndCapsTheBatch(): void
    {
        $captured = $this->captureQuery(
            fn(MusicLibraryService $s): mixed => $s->getTracksByAlbumIds([1, 2, 3])
        );

        $sql = $captured['sql'];

        $this->assertMatchesRegularExpression(
            '/ROW_NUMBER\(\)\s+OVER\s*\(\s*PARTITION\s+BY\s+t\.album_id\s+ORDER\s+BY\s+'
            . 't\.disc_number,\s*t\.track_number,\s*t\.id\s*\)\s+AS\s+rn/i',
            $sql,
            'Tracks must be windowed PER ALBUM, not capped across the whole batch'
        );
        $this->assertMatchesRegularExpression('/WHERE\s+r\.rn\s*<=\s*\?/i', $sql, 'The per-album cap must be bound');
        $this->assertMatchesRegularExpression(
            '/ORDER\s+BY\s+r\.rn,\s*r\.album_id\s+LIMIT\s+\?/i',
            $sql,
            'The batch ceiling must be applied round-robin (ORDER BY rn), never per album order'
        );

        // 3 albums x 100 per album = 300, under the 2,000 absolute ceiling.
        $this->assertSame([1, 2, 3, 100, 300], $captured['params']);
    }

    /**
     * The batch ceiling is absolute: it must bind, not merely exist.
     *
     * @dataProvider embeddedTrackBoundProvider
     * @param list<int> $albumIds
     */
    public function testGetTracksByAlbumIdsBoundsAreAbsolute(
        array $albumIds,
        int $perAlbumLimit,
        int $expectedPerAlbum,
        int $expectedBatch
    ): void {
        $captured = $this->captureQuery(
            fn(MusicLibraryService $s): mixed => $s->getTracksByAlbumIds($albumIds, $perAlbumLimit)
        );

        /** @var list<int> $params */
        $params = is_array($captured['params']) ? $captured['params'] : [];
        $bound = array_slice($params, -2);

        $this->assertSame(
            [$expectedPerAlbum, $expectedBatch],
            $bound,
            'Per-album window and batch ceiling must both be clamped before binding'
        );
        $this->assertLessThanOrEqual(
            MusicLibraryService::MAX_EMBEDDED_ROWS,
            $bound[1],
            'No caller may raise the batch above MAX_EMBEDDED_ROWS'
        );
    }

    /**
     * @return array<string, array{0: list<int>, 1: int, 2: int, 3: int}>
     */
    public static function embeddedTrackBoundProvider(): array
    {
        $hundredAlbums = range(1, 100);

        return [
            // A full PageLimit::MAX album page x the per-album window = 10,000
            // rows, so the absolute ceiling is what actually binds.
            'full album page' => [$hundredAlbums, 100, 100, MusicLibraryService::MAX_EMBEDDED_ROWS],
            // The album DETAIL endpoint asks for one album with the window raised.
            'single album detail' => [
                [7],
                MusicLibraryService::MAX_EMBEDDED_ROWS,
                MusicLibraryService::MAX_EMBEDDED_ROWS,
                MusicLibraryService::MAX_EMBEDDED_ROWS,
            ],
            // A caller cannot opt out of either bound.
            'absurd per-album limit' => [
                $hundredAlbums,
                5000000,
                MusicLibraryService::MAX_EMBEDDED_ROWS,
                MusicLibraryService::MAX_EMBEDDED_ROWS,
            ],
            'int max per-album limit' => [
                [7],
                PHP_INT_MAX,
                MusicLibraryService::MAX_EMBEDDED_ROWS,
                MusicLibraryService::MAX_EMBEDDED_ROWS,
            ],
            'zero becomes one' => [[7], 0, 1, 1],
            'negative becomes one' => [[7], -5, 1, 1],
        ];
    }

    /**
     * Same fan-out class, second door: the artists listing embeds each artist's
     * album titles, which was likewise unbounded.
     */
    public function testGetAlbumTitlesByArtistIdsWindowsPerArtistAndCapsTheBatch(): void
    {
        $captured = $this->captureQuery(
            fn(MusicLibraryService $s): mixed => $s->getAlbumTitlesByArtistIds([4, 5])
        );

        $this->assertMatchesRegularExpression(
            '/ROW_NUMBER\(\)\s+OVER\s*\(\s*PARTITION\s+BY\s+al\.artist_id/i',
            $captured['sql'],
            'Album titles must be windowed PER ARTIST'
        );
        $this->assertMatchesRegularExpression(
            '/ORDER\s+BY\s+r\.rn,\s*r\.artist_id\s+LIMIT\s+\?/i',
            $captured['sql'],
        );
        $this->assertSame([4, 5, 100, 200], $captured['params']);
    }

    /**
     * MED-2: `?artist=` must filter in SQL. The page-1-and-filter-locally
     * behaviour it replaces is why 77 of the 100 artists on screen drilled down to
     * an empty album list — page 1 of `/albums` spans 23 of 2,197 artists.
     *
     * LOW-10: and the album page must NOT aggregate the whole track table any
     * more (`134 ms` per browse measured on production).
     */
    public function testGetAllAlbumsFiltersByArtistAndDropsTheTrackAggregate(): void
    {
        $filtered = $this->captureQuery(
            fn(MusicLibraryService $s): mixed => $s->getAllAlbums(100, 0, 'Pink Floyd')
        );

        $this->assertMatchesRegularExpression('/WHERE\s+ar\.name\s*=\s*\?/i', $filtered['sql']);
        $this->assertSame(['Pink Floyd', 100, 0], $filtered['params']);

        // The ORDER BY must be a TOTAL order, or a duplicated title can show the
        // same album on two pages (2,622 of 5,091 production albums share a title).
        $this->assertMatchesRegularExpression(
            '/ORDER\s+BY\s+ar\.name,\s*al\.title,\s*al\.id/i',
            $filtered['sql'],
        );

        // No `LEFT JOIN music_tracks … GROUP BY` aggregate: the track counts come
        // from the batched, indexed getTrackCountsByAlbumIds() instead.
        $this->assertStringNotContainsString('music_tracks', $filtered['sql']);
        $this->assertStringNotContainsString('GROUP BY', $filtered['sql']);

        $unfiltered = $this->captureQuery(fn(MusicLibraryService $s): mixed => $s->getAllAlbums(10, 20));
        $this->assertStringNotContainsString('WHERE', $unfiltered['sql']);
        $this->assertSame([10, 20], $unfiltered['params']);
    }

    /**
     * `total` must describe the same set the page came from, or a filtered page
     * reports the whole library's album count.
     */
    public function testGetAlbumsCountHonoursTheArtistFilter(): void
    {
        $filtered = $this->captureQuery(
            fn(MusicLibraryService $s): mixed => $s->getAlbumsCount('Pink Floyd')
        );
        $this->assertMatchesRegularExpression('/WHERE\s+ar\.name\s*=\s*\?/i', $filtered['sql']);
        $this->assertSame(['Pink Floyd'], $filtered['params']);

        $all = $this->captureQuery(fn(MusicLibraryService $s): mixed => $s->getAlbumsCount());
        $this->assertStringContainsString('FROM music_albums', $all['sql']);
        $this->assertStringNotContainsString('WHERE', $all['sql']);
    }

    /**
     * MED-3: `music_albums.title` has no unique key and 2,622 of production's
     * 5,091 albums share a title (`Featuring Freshness` ×35), so the lookup must
     * (a) accept an artist to disambiguate and (b) be deterministic without one —
     * `ORDER BY ar.name, al.title` alone left all 35 rows tied.
     */
    public function testFindAlbumByTitleIsDeterministicAndArtistScopable(): void
    {
        $plain = $this->captureQuery(
            fn(MusicLibraryService $s): mixed => $s->findAlbumByTitle('Featuring Freshness')
        );

        $this->assertMatchesRegularExpression(
            '/ORDER\s+BY\s+ar\.name,\s*al\.title,\s*al\.id\s+LIMIT\s+1/i',
            $plain['sql'],
            'Without al.id the winner among duplicate titles is up to InnoDB'
        );
        $this->assertSame(['Featuring Freshness'], $plain['params']);

        $scoped = $this->captureQuery(
            fn(MusicLibraryService $s): mixed => $s->findAlbumByTitle('Featuring Freshness', 'The Right Artist')
        );
        $this->assertMatchesRegularExpression(
            '/WHERE\s+al\.title\s*=\s*\?\s+AND\s+ar\.name\s*=\s*\?/i',
            $scoped['sql'],
        );
        $this->assertSame(['Featuring Freshness', 'The Right Artist'], $scoped['params']);
    }

    /**
     * The batched track counter replaces the album query's aggregate, so it must
     * be ONE grouped query over the page's ids (never one query per album).
     */
    public function testGetTrackCountsByAlbumIdsIsOneGroupedIndexedQuery(): void
    {
        $captured = $this->captureQuery(
            fn(MusicLibraryService $s): mixed => $s->getTrackCountsByAlbumIds([3, 4, 4, 0, -1])
        );

        $this->assertMatchesRegularExpression(
            '/WHERE\s+album_id\s+IN\s*\(\?,\?\)\s+GROUP\s+BY\s+album_id/i',
            $captured['sql'],
            'Non-positive and duplicate ids must be filtered out before binding'
        );
        $this->assertSame([3, 4], $captured['params']);
    }

    // -----------------------------------------------------------------------
    // S97 — the `music_*` hierarchy readers the rewired consumers depend on.
    // -----------------------------------------------------------------------

    /**
     * S97 — an album's tracks are resolved by joining `music_albums` on its
     * `media_item_id`, in disc/track order, and the ids returned are the TRACK
     * `media_items` UUIDs (which are the playable ones).
     */
    public function testGetTrackMediaItemIdsForAlbumJoinsTheAlbumOnItsMediaItemId(): void
    {
        $captured = $this->captureQuery(
            fn(MusicLibraryService $s): mixed => $s->getTrackMediaItemIdsForAlbum('album-uuid')
        );

        $this->assertMatchesRegularExpression(
            '/FROM\s+music_tracks\s+t\s+JOIN\s+music_albums\s+al\s+ON\s+al\.id\s*=\s*t\.album_id/i',
            preg_replace('/\s+/', ' ', $captured['sql']) ?? '',
        );
        $this->assertStringContainsString('WHERE al.media_item_id = ?', $captured['sql']);
        $this->assertStringContainsString('ORDER BY t.disc_number, t.track_number', $captured['sql']);
        $this->assertSame(
            ['album-uuid', MusicLibraryService::MAX_EMBEDDED_ROWS, 0],
            $captured['params'],
            'S147 — the third bind is the OFFSET that makes a long album pageable over DLNA'
        );
    }

    /**
     * S97 — an artist's tracks come from the denormalized `music_tracks.artist_id`
     * FK (migration 065 added it "for efficient queries"), so an artist shuffle is
     * ONE indexed statement rather than 1 + N walks through the albums.
     */
    public function testGetTrackMediaItemIdsForArtistUsesTheDenormalizedArtistFk(): void
    {
        $captured = $this->captureQuery(
            fn(MusicLibraryService $s): mixed => $s->getTrackMediaItemIdsForArtist('artist-uuid')
        );

        $this->assertMatchesRegularExpression(
            '/FROM\s+music_tracks\s+t\s+JOIN\s+music_artists\s+ar\s+ON\s+ar\.id\s*=\s*t\.artist_id/i',
            preg_replace('/\s+/', ' ', $captured['sql']) ?? '',
        );
        $this->assertSame(['artist-uuid', MusicLibraryService::MAX_EMBEDDED_ROWS], $captured['params']);
    }

    /**
     * S97 — an artist's albums, and only the ones that have a `media_items` row:
     * an album whose mint failed (S96(e)) has nothing to browse to.
     */
    public function testGetAlbumMediaItemIdsForArtistSkipsAlbumsWithoutAMediaItem(): void
    {
        $captured = $this->captureQuery(
            fn(MusicLibraryService $s): mixed => $s->getAlbumMediaItemIdsForArtist('artist-uuid', 10)
        );

        $this->assertStringContainsString('ar.media_item_id = ?', $captured['sql']);
        $this->assertStringContainsString('al.media_item_id IS NOT NULL', $captured['sql']);
        $this->assertSame(['artist-uuid', 10, 0], $captured['params']);
    }

    /**
     * S97 — the artist enumeration and its COUNT must share one predicate, or the
     * DLNA root advertises more children than it can list.
     */
    public function testArtistEnumerationAndCountShareTheSameMediaItemPredicate(): void
    {
        $list = $this->captureQuery(
            fn(MusicLibraryService $s): mixed => $s->getArtistMediaItemIds(50, 100)
        );
        $count = $this->captureQuery(
            fn(MusicLibraryService $s): mixed => $s->getArtistsWithMediaItemCount()
        );

        $this->assertStringContainsString('WHERE media_item_id IS NOT NULL', $list['sql']);
        $this->assertStringContainsString('WHERE media_item_id IS NOT NULL', $count['sql']);
        $this->assertSame([50, 100], $list['params']);
    }

    /**
     * Every one of these lists is buffered whole by a resident Workerman worker,
     * so the caller's limit is clamped rather than trusted — and an empty id is
     * answered without touching the database at all.
     */
    public function testTheHierarchyReadersClampTheirLimitAndShortCircuitOnAnEmptyId(): void
    {
        $captured = $this->captureQuery(
            fn(MusicLibraryService $s): mixed => $s->getTrackMediaItemIdsForAlbum('album-uuid', 10_000)
        );
        $this->assertSame(['album-uuid', MusicLibraryService::MAX_EMBEDDED_ROWS, 0], $captured['params']);

        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');
        $service = new MusicLibraryService($db, $this->createMock(MusicLibraryScanner::class));

        $this->assertSame([], $service->getTrackMediaItemIdsForAlbum(''));
        $this->assertSame([], $service->getTrackMediaItemIdsForArtist(''));
        $this->assertSame([], $service->getAlbumMediaItemIdsForArtist(''));
    }

    /**
     * The id column reader drops anything that is not a usable UUID: a `null` or
     * `''` handed on would either widen an `IN (…)` for nothing or reach a client
     * as an unplayable id.
     */
    public function testTheHierarchyReadersDropNonStringAndEmptyIds(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['media_item_id' => 'track-1'],
            ['media_item_id' => null],
            ['media_item_id' => ''],
            ['media_item_id' => 42],
            'not-a-row',
            ['media_item_id' => 'track-2'],
        ]);

        $service = new MusicLibraryService($db, $this->createMock(MusicLibraryScanner::class));

        $this->assertSame(
            ['track-1', 'track-2'],
            $service->getTrackMediaItemIdsForAlbum('album-uuid')
        );
    }

    /**
     * Runs one service call against a mocked connection and returns the SQL and
     * bound parameters it emitted.
     *
     * @param callable(MusicLibraryService): mixed $call
     * @return array{sql: string, params: mixed}
     */
    private function captureQuery(callable $call): array
    {
        /** @var array{sql: string, params: mixed}|null $captured */
        $captured = null;

        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(
                /**
                 * @return list<array<string, mixed>>
                 */
                function (mixed $sql = '', mixed $params = null) use (&$captured): array {
                    $captured = [
                        'sql' => is_string($sql) ? $sql : '',
                        'params' => $params,
                    ];
                    return [];
                }
            );

        $call(new MusicLibraryService($db, $this->createMock(MusicLibraryScanner::class)));

        $this->assertIsArray($captured);

        return $captured;
    }
}
