<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Common\Http\PageLimit;
use Phlix\Common\Uuid;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Server\Http\Controllers\MusicController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Session\SessionManager;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Real-DB proof for the S99 music read path — the fix that makes a scanned music
 * library actually display.
 *
 * **The defect.** The music scanner writes every harvested tag into the
 * normalized `music_artists` / `music_albums` / `music_tracks` tables but stamps
 * only `{"name","sub_type"}` into `media_items.metadata_json`. The
 * `/api/v1/music/*` handlers read `metadata_json.$.artist` / `.album` / `.year`,
 * which is never populated, so on the live library (29,245 tracks / 5,091 albums /
 * 2,197 artists) every field fell through to its default and
 * `GET /api/v1/music/artists` answered with ONE bogus row:
 * `{"name":"Unknown Artist","album_count":1,"track_count":100}` (100 = the
 * `getByType()` default page size).
 *
 * **Why this test must hit real MySQL.** A mocked `Connection` returns whatever
 * rows the test feeds it, so it cannot show that a JOIN resolves, that an alias
 * exists, or that `metadata_json` is empty — which is exactly how this defect
 * survived a green mock-only suite three separate diagnoses (the same class of
 * miss as the LiveTv RowQuery/ResultSet and metrics ONLY_FULL_GROUP_BY bugs). So
 * the fixture reproduces production byte-for-byte: real tags in the `music_*`
 * tables, and `media_items.metadata_json` containing ONLY `name` + `sub_type`
 * ({@see testTheFixtureReproducesTheProductionMetadataJson} asserts that, so the
 * fixture can never silently drift into a shape that would pass either way).
 *
 * CI applies all migrations to the `phlix_test` MySQL service before the suite;
 * locally, with no reachable MySQL, it self-skips — the same guard
 * {@see MusicTracksQueryIntegrationTest} uses.
 *
 */
final class MusicApiReadPathIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    /** Fixture namespace. Every seeded artist name and library carries it. */
    private const NAMESPACE_PREFIX = '!S99-';

    /** Library name, used to sweep leftovers from an interrupted run. */
    private const LIBRARY_NAME = 'S99 Music IT Library';

    /** How many filler tracks the >1,000th-row playback test seeds. */
    private const FILLER_TRACKS = 1000;

    /** The page size the pre-S99 track lookup linear-scanned. */
    private const LEGACY_SCAN_PAGE = 1000;

    /**
     * Albums in the HIGH-1 fan-out fixture. A full {@see PageLimit::MAX} page, so
     * the round-robin share of {@see MusicLibraryService::MAX_EMBEDDED_ROWS} is a
     * whole number and the assertions can be exact.
     */
    private const BULK_ALBUMS = 100;

    /**
     * Tracks per album in that fixture. 100 x 25 = 2,500 embedded rows — denser
     * than production's worst 100-album window (989), so the ceiling binds.
     *
     * ⚠ EQUAL sizes are the DEGENERATE case (S99 review r2, LOW-6): every album
     * reaches the same `rn`, which is why the round-robin and a flat batch `LIMIT`
     * look alike here. The skewed shape that actually discriminates lives in
     * {@see testSkewedAlbumSizesShareTheCeilingRoundRobinAndKeepPlayOrder}.
     */
    private const BULK_TRACKS_PER_ALBUM = 25;

    /**
     * The skewed fan-out fixture: production's real shape (a handful of long
     * compilations beside a crowd of singles), sized so the arithmetic is exact.
     * 1x125 + 20x100 + 79x1 = 2,204 real tracks over {@see PageLimit::MAX} albums.
     */
    private const SKEW_TRACK_COUNTS = [125, 100, 1];

    /** How many albums carry each of {@see SKEW_TRACK_COUNTS}. */
    private const SKEW_ALBUM_COUNTS = [1, 20, 79];

    /** Discs are 50 tracks wide in the skewed fixture, so `disc_number` matters. */
    private const SKEW_TRACKS_PER_DISC = 50;

    private ?Connection $db = null;

    private string $libraryId = '';

    /**
     * Fixture-local name prefix. The leading `!` sorts ahead of every digit and
     * letter in `utf8mb4_unicode_ci`, which keeps this run's rows on page 1 of the
     * (unscoped, `LIMIT 100`) listing endpoints no matter how populated the music
     * tables already are — see MusicTracksQueryIntegrationTest::$prefix.
     */
    private string $prefix = '';

    /** @var list<int> Seeded music_artists ids (cascade-deletes albums + tracks). */
    private array $artistIds = [];

    /** media_items UUID of the track seeded PAST the legacy 1,000-row scan page. */
    private string $beyondPageTrackId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping music read-path integration test. Runs in CI.');

        $this->assertNotNull($this->db);

        $this->libraryId = Uuid::v4();
        // music_artists.name is UNIQUE, so every run needs its own namespace.
        $this->prefix = self::NAMESPACE_PREFIX . substr(Uuid::v4(), 0, 8) . '-';

        // A run killed mid-test (CI timeout, ^C) leaves fixtures behind, and their
        // `!`-sorting names would crowd this run's rows out of page 1 of the
        // unscoped LIMIT-100 listings. Sweep this fixture's namespace first so the
        // class is self-healing rather than poisoned by its own debris.
        $this->purgeNamespace();

        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $this->purgeFixtures();
        parent::tearDown();
    }

    /**
     * Guards the fixture itself: `media_items.metadata_json` must carry ONLY the
     * two keys the scanner writes. If a future change starts persisting audio tags
     * there, this test fails and tells us the fixture no longer reproduces the bug
     * — rather than the read-path tests below passing for the wrong reason.
     */
    public function testTheFixtureReproducesTheProductionMetadataJson(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $rows = $db->query(
            "SELECT JSON_KEYS(metadata_json) AS ks,
                    JSON_EXTRACT(metadata_json, '$.artist') AS artist,
                    JSON_EXTRACT(metadata_json, '$.album')  AS album,
                    JSON_EXTRACT(metadata_json, '$.year')   AS yr
             FROM media_items
             WHERE library_id = ? AND type = 'track'",
            [$this->libraryId],
        );

        $this->assertIsArray($rows);
        $this->assertGreaterThanOrEqual(6, count($rows));

        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $keys = json_decode((string) ($row['ks'] ?? '[]'), true);
            $this->assertIsArray($keys);
            sort($keys);
            $this->assertSame(['name', 'sub_type'], $keys, 'The scanner writes only name + sub_type');
            $this->assertNull($row['artist'], 'metadata_json.$.artist must be absent (this IS the bug)');
            $this->assertNull($row['album'], 'metadata_json.$.album must be absent (this IS the bug)');
            $this->assertNull($row['yr'], 'metadata_json.$.year must be absent (this IS the bug)');
        }
    }

    /**
     * `GET /api/v1/music/tracks` must serve the REAL tags from `music_tracks` —
     * the pre-S99 handler read them out of `metadata_json` and therefore emitted
     * `artist: null`, `album: null`, `year: null`, `duration_secs: null` for all
     * 29,245 live rows.
     */
    public function testTracksEndpointServesRealTagsNotMetadataJsonDefaults(): void
    {
        $payload = $this->json($this->controller()->listTracks($this->request(), []));

        $this->assertIsArray($payload['tracks'] ?? null);
        $mine = $this->minePrefixed($payload['tracks'], 'artist');
        $this->assertCount(6, $mine, 'All six seeded tracks must come back on page 1');

        foreach ($mine as $track) {
            $this->assertNotSame('Unknown Artist', $track['artist']);
            $this->assertNotSame('Unknown Album', $track['album']);
            $this->assertNotNull($track['artist'], 'artist must come from music_artists.name');
            $this->assertNotNull($track['album'], 'album must come from music_albums.title');
            $this->assertSame(1999, $track['year'], 'year must come from music_albums.year');
            $this->assertSame(180, $track['duration_secs'], 'duration must come from music_tracks');
            $this->assertIsString($track['name']);
            $this->assertNotSame('', $track['name']);
            // The absolute filesystem path must never be in the payload — this is
            // now reachable over the internet-facing hub relay.
            $this->assertArrayNotHasKey('path', $track);
        }

        // Ordering is artist name -> album title -> disc -> track (S94's query).
        $this->assertSame(
            [
                ['A Alpha', 'Aardvark Album', 1, 1],
                ['A Alpha', 'Aardvark Album', 1, 2],
                ['A Alpha', 'Zed Album', 1, 1],
                ['B Beta', 'Only Album', 1, 1],
                ['B Beta', 'Only Album', 1, 2],
                ['B Beta', 'Only Album', 2, 1],
            ],
            array_map(
                fn(array $t): array => [
                    $this->unprefixed($t['artist']),
                    (string) $t['album'],
                    (int) $t['disc_number'],
                    (int) $t['track_number'],
                ],
                $mine,
            ),
        );
    }

    /**
     * `total` was hardcoded 0 for every caller, forever: the handler summed
     * `libraries.item_count`, and the `libraries` table has no such column
     * (`id, name, type, paths, options, created_at, display_order`), so the
     * `?? 0` fallback fired unconditionally.
     */
    public function testTracksTotalIsANonZeroCountFromTheMusicTables(): void
    {
        $payload = $this->json($this->controller()->listTracks($this->request(), []));

        $this->assertArrayHasKey('total', $payload);
        $this->assertIsInt($payload['total']);
        $this->assertGreaterThanOrEqual(6, $payload['total'], '`total` must count music_tracks, not a phantom column');
        $this->assertNotSame(0, $payload['total']);

        // And it is the real table count, not the page size.
        $rows = $this->db?->query('SELECT COUNT(*) AS c FROM music_tracks');
        $this->assertIsArray($rows);
        $this->assertIsArray($rows[0] ?? null);
        $this->assertSame((int) $rows[0]['c'], $payload['total']);
    }

    /**
     * `GET /api/v1/music/artists` must list the real artists with real counts,
     * instead of collapsing every row into one `'Unknown Artist'`.
     */
    public function testArtistsEndpointListsRealArtistsWithCounts(): void
    {
        $payload = $this->json($this->controller()->listArtists($this->request(), []));

        $this->assertIsArray($payload['artists'] ?? null);
        $mine = $this->minePrefixed($payload['artists'], 'name');
        $this->assertCount(2, $mine);

        $byName = [];
        foreach ($mine as $artist) {
            $this->assertNotSame('Unknown Artist', $artist['name']);
            $byName[$this->unprefixed($artist['name'])] = $artist;
        }

        $this->assertSame(2, $byName['A Alpha']['album_count'], 'Alpha has two albums');
        $this->assertSame(3, $byName['A Alpha']['track_count'], 'Alpha has three tracks');
        $this->assertSame(1, $byName['B Beta']['album_count']);
        $this->assertSame(3, $byName['B Beta']['track_count']);

        // The `albums` key carries the real album titles (batched, never N+1).
        $alphaAlbums = $byName['A Alpha']['albums'];
        $this->assertIsArray($alphaAlbums);
        $this->assertSame(['Aardvark Album', 'Zed Album'], $alphaAlbums);

        $this->assertGreaterThanOrEqual(2, $payload['total']);
    }

    /**
     * `/artists/{mbid}` is NAME-keyed (`phlix-ui` routes `/app/music/artist/:name`
     * and passes the display name), and matching stays case-insensitive as the
     * pre-S99 `strcasecmp()` handler was.
     */
    public function testArtistDetailIsNameKeyedAndCaseInsensitive(): void
    {
        $controller = $this->controller();

        $exact = $this->json($controller->getArtist($this->request(), ['mbid' => $this->prefix . 'A Alpha']));
        $this->assertIsArray($exact['artist'] ?? null);
        $this->assertSame($this->prefix . 'A Alpha', $exact['artist']['name']);
        $this->assertSame(2, $exact['artist']['album_count']);
        $this->assertSame(3, $exact['artist']['track_count']);
        $this->assertSame(['Aardvark Album', 'Zed Album'], $exact['artist']['albums']);

        $shouted = $controller->getArtist(
            $this->request(),
            ['mbid' => strtoupper($this->prefix . 'a alpha')],
        );
        $this->assertSame(200, $shouted->statusCode, 'Artist lookup must stay case-insensitive');

        $missing = $controller->getArtist($this->request(), ['mbid' => $this->prefix . 'No Such Artist']);
        $this->assertSame(404, $missing->statusCode);
    }

    /**
     * `GET /api/v1/music/albums` must carry the album's artist, year and track
     * count, plus the embedded track list every client's browse fast-path reads.
     */
    public function testAlbumsEndpointCarriesArtistYearAndEmbeddedTracks(): void
    {
        $payload = $this->json($this->controller()->listAlbums($this->request(), []));

        $this->assertIsArray($payload['albums'] ?? null);
        $mine = $this->minePrefixed($payload['albums'], 'artist');
        $this->assertCount(3, $mine);

        $byTitle = [];
        foreach ($mine as $album) {
            $this->assertNotSame('Unknown Album', $album['name']);
            $byTitle[(string) $album['name']] = $album;
        }
        $this->assertArrayHasKey('Aardvark Album', $byTitle);
        $this->assertArrayHasKey('Zed Album', $byTitle);
        $this->assertArrayHasKey('Only Album', $byTitle);

        $aardvark = $byTitle['Aardvark Album'];
        $this->assertSame($this->prefix . 'A Alpha', $aardvark['artist']);
        $this->assertSame(1999, $aardvark['year']);
        $this->assertSame(2, $aardvark['track_count'], 'track_count counts indexed music_tracks rows');
        $this->assertFalse($aardvark['tracks_truncated'], 'A complete embedded list must not be flagged');
        $this->assertIsArray($aardvark['tracks']);
        $this->assertCount(2, $aardvark['tracks']);

        // Embedded tracks are ordered by disc then track and carry real values.
        $this->assertSame(
            [[1, 1, 'Alpha d1t1'], [1, 2, 'Alpha d1t2']],
            array_map(
                fn(array $t): array => [
                    (int) $t['disc_number'],
                    (int) $t['track_number'],
                    $this->unprefixed($t['name']),
                ],
                $aardvark['tracks'],
            ),
        );

        $this->assertGreaterThanOrEqual(3, $payload['total']);
    }

    /**
     * `/albums/{mbid}` is TITLE-keyed (`phlix-ui` routes `/app/music/album/:name`),
     * case-insensitive, and returns the album's full track listing.
     */
    public function testAlbumDetailIsTitleKeyedAndCaseInsensitive(): void
    {
        $controller = $this->controller();

        $payload = $this->json($controller->getAlbum($this->request(), ['mbid' => 'Only Album']));
        $this->assertIsArray($payload['album'] ?? null);
        $this->assertSame('Only Album', $payload['album']['name']);
        $this->assertSame($this->prefix . 'B Beta', $payload['album']['artist']);
        $this->assertCount(3, $payload['album']['tracks']);

        $shouted = $controller->getAlbum($this->request(), ['mbid' => 'ONLY ALBUM']);
        $this->assertSame(200, $shouted->statusCode, 'Album lookup must stay case-insensitive');

        $missing = $controller->getAlbum($this->request(), ['mbid' => $this->prefix . 'No Such Album']);
        $this->assertSame(404, $missing->statusCode);
    }

    /**
     * The playback blocker: the pre-S99 track lookup paged the first 1,000
     * `media_items` rows of each music library and compared ids in PHP, so with
     * 29,245 tracks every track past the 1,000th 404'd — the SPA calls this
     * endpoint at play time to mint `stream_url`, so those tracks were unplayable.
     *
     * The assertion is self-discriminating and needs no source revert: it first
     * proves, against real data, that the target track is genuinely OUTSIDE the
     * exact page the old code fetched (`ItemRepository::getByType($lib,'track',
     * 1000, 0)`), then proves the endpoint resolves it anyway.
     */
    public function testTrackBeyondTheLegacyThousandRowScanPageResolves(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $this->seedTrackBeyondLegacyScanPage();

        // 1) The pre-S99 lookup's exact page does NOT contain the target track.
        $legacyPage = (new ItemRepository($db))->getByType(
            $this->libraryId,
            'track',
            self::LEGACY_SCAN_PAGE,
            0,
        );
        $this->assertCount(self::LEGACY_SCAN_PAGE, $legacyPage);
        $legacyIds = array_map(static fn(array $row): string => (string) ($row['id'] ?? ''), $legacyPage);
        $this->assertNotContains(
            $this->beyondPageTrackId,
            $legacyIds,
            'Fixture must place the target track past the legacy 1,000-row scan page',
        );

        // 2) The keyed lookup resolves it — with real tags and a playable URL.
        $response = $this->controller()->getTrack($this->request(), ['id' => $this->beyondPageTrackId]);
        $this->assertSame(200, $response->statusCode, 'Track past the 1,000th row must not 404');

        $payload = $this->json($response);
        $this->assertIsArray($payload['track'] ?? null);
        $track = $payload['track'];
        $this->assertSame($this->beyondPageTrackId, $track['id'], 'The public track id IS the media_items UUID');
        $this->assertSame($this->prefix . 'zzz-target', $track['name']);
        $this->assertSame($this->prefix . 'A Alpha', $track['artist']);
        $this->assertSame('Aardvark Album', $track['album']);
        $this->assertSame(321, $track['duration_secs']);
        $this->assertIsString($track['stream_url']);
        $this->assertStringContainsString(
            '/media/' . $this->beyondPageTrackId . '/stream',
            $track['stream_url'],
        );
    }

    /**
     * An unknown media-item id still 404s (the keyed lookup must not resolve
     * arbitrary ids).
     */
    public function testUnknownTrackIdStill404s(): void
    {
        $response = $this->controller()->getTrack($this->request(), ['id' => Uuid::v4()]);

        $this->assertSame(404, $response->statusCode);
    }

    /**
     * HIGH-1 (S99 review r1) against real rows: `/api/v1/music/albums` embeds each
     * album's tracks, and that batch had NO `LIMIT`. Clamping the ALBUM page to 100
     * bounds nothing — 100 albums can hold any number of tracks — and every embedded
     * track costs one `hash_hmac()` mint on the event loop while the whole body is
     * buffered by both shared hub workers (`/api/v1/music` is not in the hub's
     * `STREAMING_BODY_PREFIXES`).
     *
     * The fixture is deliberately DENSER than production's worst 100-album window
     * (2,500 tracks vs the live library's 989) so the ceiling actually binds, and the
     * test pins all four properties that make the bound safe:
     *
     * 1. the total is capped at {@see MusicLibraryService::MAX_EMBEDDED_ROWS};
     * 2. truncation is round-robin — NO album comes back with an empty track list,
     *    which is what a plain `LIMIT` over `ORDER BY album_id` would have done to
     *    the tail of the page;
     * 3. `track_count` remains the TRUE indexed count and `tracks_truncated` is set,
     *    so a short list is never silent;
     * 4. below the ceiling nothing is truncated at all — i.e. the 2,000-row BATCH
     *    ceiling never engages on production's real worst 100-album window (989
     *    tracks). NB the 100-per-album window is a different bound and does engage:
     *    two live albums hold >100 tracks (125 and 109), which is what
     *    `tracks_truncated` is for.
     */
    public function testAlbumListEmbeddedTrackFanOutIsCappedAndTruncationIsVisible(): void
    {
        $bulkArtist = $this->prefix . '0 Bulk';
        $this->seedBulkAlbums($bulkArtist, self::BULK_ALBUMS, self::BULK_TRACKS_PER_ALBUM);

        // --- 1..3: the full page exceeds the ceiling -------------------------
        $payload = $this->json($this->controller()->listAlbums(
            $this->request(['artist' => $bulkArtist, 'limit' => (string) self::BULK_ALBUMS]),
            [],
        ));

        /** @var list<array<string, mixed>> $albums */
        $albums = is_array($payload['albums'] ?? null) ? $payload['albums'] : [];
        $this->assertCount(self::BULK_ALBUMS, $albums, 'The whole album page must come back');

        $embedded = 0;
        $emptyAlbums = 0;
        foreach ($albums as $album) {
            /** @var list<array<string, mixed>> $tracks */
            $tracks = is_array($album['tracks'] ?? null) ? $album['tracks'] : [];
            $embedded += count($tracks);
            if (count($tracks) === 0) {
                $emptyAlbums++;
            }

            $this->assertSame(
                self::BULK_TRACKS_PER_ALBUM,
                $album['track_count'],
                'track_count must stay the TRUE indexed count, not the capped list length',
            );
            $this->assertTrue(
                $album['tracks_truncated'],
                'A capped album must advertise the truncation',
            );
            // Round-robin fair share: 2,000 rows / 100 albums = 20 each.
            $this->assertCount(
                intdiv(MusicLibraryService::MAX_EMBEDDED_ROWS, self::BULK_ALBUMS),
                $tracks,
                'The batch ceiling must be shared evenly across the page',
            );
            // And each album keeps its FIRST tracks, in play order.
            $this->assertSame(
                range(1, intdiv(MusicLibraryService::MAX_EMBEDDED_ROWS, self::BULK_ALBUMS)),
                array_map(static fn(array $t): int => (int) $t['track_number'], $tracks),
            );
        }

        $this->assertSame(
            MusicLibraryService::MAX_EMBEDDED_ROWS,
            $embedded,
            sprintf(
                'The embedded track fan-out must be capped at %d rows (fixture holds %d)',
                MusicLibraryService::MAX_EMBEDDED_ROWS,
                self::BULK_ALBUMS * self::BULK_TRACKS_PER_ALBUM,
            ),
        );
        $this->assertSame(0, $emptyAlbums, 'Truncation must never leave an album with zero tracks');

        // --- 4: below the ceiling, nothing is truncated ----------------------
        // 79 albums x 25 = 1,975 rows, i.e. just under the ceiling and just over
        // production's worst real 100-album window (989 tracks).
        $under = $this->json($this->controller()->listAlbums(
            $this->request(['artist' => $bulkArtist, 'limit' => '79']),
            [],
        ));

        /** @var list<array<string, mixed>> $underAlbums */
        $underAlbums = is_array($under['albums'] ?? null) ? $under['albums'] : [];
        $this->assertCount(79, $underAlbums);

        $underEmbedded = 0;
        foreach ($underAlbums as $album) {
            /** @var list<array<string, mixed>> $tracks */
            $tracks = is_array($album['tracks'] ?? null) ? $album['tracks'] : [];
            $underEmbedded += count($tracks);
            $this->assertFalse($album['tracks_truncated'], 'Under the ceiling nothing may be flagged');
            $this->assertCount(self::BULK_TRACKS_PER_ALBUM, $tracks);
        }
        $this->assertSame(79 * self::BULK_TRACKS_PER_ALBUM, $underEmbedded);
        $this->assertLessThan(MusicLibraryService::MAX_EMBEDDED_ROWS, $underEmbedded);
    }

    /**
     * S99 review r2, LOW-6: the fan-out test above seeds 100 albums of 25 tracks
     * each, and with EQUAL sizes every album reaches the same `rn` — so a flat batch
     * `LIMIT` and a per-album window are indistinguishable in the "no album is
     * empty" dimension, and `ORDER BY t.id` is indistinguishable from
     * `ORDER BY t.disc_number, t.track_number, t.id` because the fixture inserts
     * tracks in play order. Neither property was actually pinned.
     *
     * This data set is production's real shape and is built to discriminate:
     *
     * - **Skewed:** 1 album of 125 tracks + 20 of 100 + 79 of 1 = 2,204 tracks over
     *   a full 100-album page, so the round-robin arithmetic is exact and asymmetric
     *   ({@see SKEW_TRACK_COUNTS}).
     * - **Adversarially ordered:** the LONGEST albums hold the LOWEST `album_id`s,
     *   so a flat `LIMIT` over `ORDER BY album_id` spends the entire 2,000-row budget
     *   on them and leaves the 79 singles EMPTY.
     * - **Tracks inserted in REVERSE play order,** so `music_tracks.id` order
     *   disagrees with `disc_number, track_number` order and a window that sorts by
     *   `id` returns each album's LAST tracks instead of its first.
     */
    public function testSkewedAlbumSizesShareTheCeilingRoundRobinAndKeepPlayOrder(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $skewArtist = $this->prefix . '0 Skew';
        $trueCounts = $this->seedSkewedAlbums($skewArtist);
        $this->assertSame(
            self::SKEW_TRACK_COUNTS[0]
            + (self::SKEW_ALBUM_COUNTS[1] * self::SKEW_TRACK_COUNTS[1])
            + self::SKEW_ALBUM_COUNTS[2],
            array_sum($trueCounts),
            'Fixture arithmetic: 125 + 20x100 + 79x1 = 2,204 real tracks',
        );

        $payload = $this->json($this->controller()->listAlbums(
            $this->request(['artist' => $skewArtist, 'limit' => (string) PageLimit::MAX]),
            [],
        ));

        /** @var list<array<string, mixed>> $albums */
        $albums = is_array($payload['albums'] ?? null) ? $payload['albums'] : [];
        $this->assertCount(PageLimit::MAX, $albums, 'The whole 100-album page must come back');

        // Play order per album, computed independently of the endpoint: ONE query,
        // grouped in PHP (never one query per album).
        $expectedOrder = $this->playOrderByAlbum(array_keys($trueCounts));

        $embedded = 0;
        $emptyAlbums = 0;
        /** @var list<int> $perAlbum */
        $perAlbum = [];
        foreach ($albums as $album) {
            /** @var list<array<string, mixed>> $tracks */
            $tracks = is_array($album['tracks'] ?? null) ? $album['tracks'] : [];
            $embedded += count($tracks);
            $perAlbum[] = count($tracks);
            if (count($tracks) === 0) {
                $emptyAlbums++;
            }

            $title = (string) ($album['name'] ?? '');
            $albumId = $this->skewAlbumIdByTitle($trueCounts, $title);
            $trueCount = $trueCounts[$albumId];

            $this->assertSame($trueCount, $album['track_count'], 'track_count must stay the TRUE indexed count');
            $this->assertSame(
                count($tracks) < $trueCount,
                $album['tracks_truncated'],
                sprintf(
                    '%s: tracks_truncated must mean count(%d) < track_count(%d)',
                    $title,
                    count($tracks),
                    $trueCount,
                ),
            );

            // The embedded list must be a PREFIX of disc/track/id order — this is
            // what fails if the window sorts by `id` (the fixture inserts in reverse).
            $names = array_map(static fn(array $t): string => (string) $t['name'], $tracks);
            $this->assertSame(
                array_slice($expectedOrder[$albumId], 0, count($names)),
                $names,
                $title . ': the embedded list must be the FIRST tracks in disc/track order',
            );
        }

        // Round-robin, with unequal sizes: rn=1 gives all 100 albums a track, rn
        // 2..91 give 21 rows each (100 + 21x90 = 1,990), then 10 rows of rn=92 fill
        // the budget to exactly 2,000. So 79 albums keep their single track WHOLE
        // and the 21 long ones split the remainder 91/92.
        $this->assertSame(0, $emptyAlbums, 'Round-robin truncation must never leave an album with zero tracks');
        $this->assertSame(MusicLibraryService::MAX_EMBEDDED_ROWS, $embedded, 'The ceiling must bind exactly');

        $distribution = array_count_values($perAlbum);
        ksort($distribution);
        $this->assertSame([1 => 79, 91 => 11, 92 => 10], $distribution, 'The ceiling must be shared round-robin');

        // And the DETAIL endpoint returns the 125-track compilation WHOLE, in order.
        $longestTitle = sprintf('Skew Album %03d', 1);
        $detail = $this->json($this->controller()->getAlbum(
            $this->request(['artist' => $skewArtist]),
            ['mbid' => $longestTitle],
        ));
        /** @var list<array<string, mixed>> $detailTracks */
        $detailTracks = is_array($detail['album']['tracks'] ?? null) ? $detail['album']['tracks'] : [];
        $this->assertCount(self::SKEW_TRACK_COUNTS[0], $detailTracks, 'Album detail must not truncate');
        $this->assertFalse($detail['album']['tracks_truncated']);
        $longestId = $this->skewAlbumIdByTitle($trueCounts, $longestTitle);
        $this->assertSame(
            $expectedOrder[$longestId],
            array_map(static fn(array $t): string => (string) $t['name'], $detailTracks),
            'Album detail must return the whole list in disc/track order',
        );
    }

    /**
     * S99 review r2, MED-1: the artists endpoints embed each artist's album TITLES
     * under the same per-parent window, and the live library has three artists above
     * it (Michael Jackson 142 albums, Def Leppard 109, Green Day 104). The LIST must
     * flag the short list; the DETAIL must not truncate at all.
     *
     * The flag is only trustworthy because both numbers count the same population:
     * `album_count` is `COUNT(DISTINCT al.id)` over
     * `music_artists LEFT JOIN music_albums`, and `getAlbumTitlesByArtistIds()`
     * selects `FROM music_albums WHERE artist_id IN (…)` with no join at all. This
     * test pins that equality against real rows at 142 / 100 / 2 / 0 albums.
     */
    public function testArtistAlbumListIsCappedAndFlaggedWhileArtistDetailIsWhole(): void
    {
        $prolific = $this->prefix . '0 Prolific';
        $albumCount = 142;                                  // production's worst artist
        $this->seedBulkAlbums($prolific, $albumCount, 0);

        // --- the LIST: capped, flagged, and still carries the TRUE count ------
        $payload = $this->json($this->controller()->listArtists($this->request(), []));
        /** @var list<array<string, mixed>> $artists */
        $artists = is_array($payload['artists'] ?? null) ? $payload['artists'] : [];

        $byName = [];
        foreach ($artists as $artist) {
            $byName[(string) $artist['name']] = $artist;
        }
        $this->assertArrayHasKey($prolific, $byName, 'The seeded artist must be on page 1');

        $row = $byName[$prolific];
        $this->assertSame($albumCount, $row['album_count'], 'album_count must be the TRUE total');
        $this->assertCount(
            MusicLibraryService::MAX_EMBEDDED_ROWS_PER_PARENT,
            $row['albums'],
            'The listing caps embedded album titles per artist',
        );
        $this->assertTrue($row['albums_truncated'], 'A capped discography must advertise the truncation');
        // Capped means the FIRST titles by (title, id), not an arbitrary 100.
        $this->assertSame(
            array_map(static fn(int $i): string => sprintf('Bulk Album %03d', $i), range(1, 100)),
            $row['albums'],
        );

        // An artist whose discography fits is NOT flagged (or the flag is noise).
        $alpha = $byName[$this->prefix . 'A Alpha'] ?? null;
        $this->assertIsArray($alpha);
        $this->assertSame(2, $alpha['album_count']);
        $this->assertFalse($alpha['albums_truncated']);

        // --- the DETAIL: the endpoint that must not truncate ------------------
        $detail = $this->json($this->controller()->getArtist($this->request(), ['mbid' => $prolific]));
        /** @var array<string, mixed> $artist */
        $artist = is_array($detail['artist'] ?? null) ? $detail['artist'] : [];
        $this->assertSame($albumCount, $artist['album_count']);
        $this->assertCount($albumCount, $artist['albums'], 'Artist detail must return the WHOLE discography');
        $this->assertFalse($artist['albums_truncated'], 'A complete list must not be flagged');

        $alphaDetail = $this->json(
            $this->controller()->getArtist($this->request(), ['mbid' => $this->prefix . 'A Alpha']),
        );
        $this->assertFalse($alphaDetail['artist']['albums_truncated']);
        $this->assertSame(['Aardvark Album', 'Zed Album'], $alphaDetail['artist']['albums']);
    }

    /**
     * MED-2 (S99 review r1) against real rows: `?artist=` filters SERVER-side.
     *
     * `phlix-ui`'s `MusicLibraryPage` fetches page 1 of `/albums` and filters it in
     * the browser, and page 1 of the live library spans only 23 of its 2,197
     * artists — so 77 of the 100 artists on screen drill down to an EMPTY album
     * list. This test reproduces exactly that: an artist whose albums sit beyond
     * page 1 is invisible to a client-side filter, and reachable with `?artist=`.
     */
    public function testAlbumsAreReachableByArtistEvenWhenTheyFallBeyondPageOne(): void
    {
        // 120 track-less albums under an artist sorting FIRST fill page 1 (LIMIT 100).
        $bulkArtist = $this->prefix . '0 Bulk';
        $this->seedBulkAlbums($bulkArtist, 120, 0);

        // The victim: an artist sorting LAST, whose albums are therefore off page 1.
        $lateArtist = $this->prefix . 'zz Late Artist';
        $lateId = $this->artist('zz Late Artist');
        $this->album($lateId, 'Late Album One', 1);
        $this->album($lateId, 'Late Album Two', 1);

        // 1) What a client-side filter sees: page 1 does NOT contain the artist.
        $pageOne = $this->json($this->controller()->listAlbums($this->request(), []));
        /** @var list<array<string, mixed>> $pageOneAlbums */
        $pageOneAlbums = is_array($pageOne['albums'] ?? null) ? $pageOne['albums'] : [];
        $this->assertCount(PageLimit::MAX, $pageOneAlbums, 'Page 1 must be full for this to be a real test');
        $this->assertSame(
            [],
            array_values(array_filter(
                $pageOneAlbums,
                static fn(array $a): bool => ($a['artist'] ?? null) === $lateArtist,
            )),
            'The late artist must be beyond page 1 — this is the empty drill-down',
        );

        // 2) What the server-side filter returns: exactly that artist's albums.
        $filtered = $this->json($this->controller()->listAlbums(
            $this->request(['artist' => $lateArtist]),
            [],
        ));

        /** @var list<array<string, mixed>> $filteredAlbums */
        $filteredAlbums = is_array($filtered['albums'] ?? null) ? $filtered['albums'] : [];
        $this->assertCount(2, $filteredAlbums, 'Both of the late artist\'s albums must come back');
        $this->assertSame(
            ['Late Album One', 'Late Album Two'],
            array_map(static fn(array $a): string => (string) $a['name'], $filteredAlbums),
        );
        foreach ($filteredAlbums as $album) {
            $this->assertSame($lateArtist, $album['artist']);
        }

        // `total` describes the FILTERED set, so a pager cannot mis-size itself.
        $this->assertSame(2, $filtered['total']);
        $this->assertSame($lateArtist, $filtered['artist'], 'The applied filter is echoed');
        $this->assertGreaterThan(2, $pageOne['total'], 'Unfiltered total still counts every album');
        $this->assertNull($pageOne['artist']);

        // Case-insensitive, like every other music name lookup.
        $shouted = $this->json($this->controller()->listAlbums(
            $this->request(['artist' => strtoupper($lateArtist)]),
            [],
        ));
        $this->assertCount(2, is_array($shouted['albums'] ?? null) ? $shouted['albums'] : []);
    }

    /**
     * MED-3 (S99 review r1) against real rows: `music_albums.title` is NOT unique
     * (2,622 of production's 5,091 albums share a title, `Featuring Freshness` ×35),
     * so `/albums/{title}` must be deterministic without an artist and EXACT with
     * one.
     *
     * The fixture is built to discriminate: the album belonging to the
     * alphabetically-LATER artist is inserted FIRST, so it holds the lower
     * `music_albums.id`. A lookup that just takes whatever InnoDB hands back first
     * returns the wrong one; the documented rule (first `artist_name`, then lowest
     * `al.id`) returns the earlier artist's album.
     */
    public function testDuplicateAlbumTitleResolvesDeterministicallyAndByArtist(): void
    {
        $sharedTitle = 'Featuring Freshness';

        // Inserted first => lower album id, but sorts LAST by artist name.
        $secondArtist = $this->artist('M2 Second');
        $secondAlbumId = $this->album($secondArtist, $sharedTitle, 1);
        $this->track($secondAlbumId, $secondArtist, 'Second Artist Track', 1, 1, 222);

        $firstArtist = $this->artist('M1 First');
        $firstAlbumId = $this->album($firstArtist, $sharedTitle, 1);
        $this->track($firstAlbumId, $firstArtist, 'First Artist Track', 1, 1, 111);

        $this->assertLessThan(
            $firstAlbumId,
            $secondAlbumId,
            'Fixture must give the later-sorting artist the LOWER album id',
        );

        $controller = $this->controller();

        // 1) No artist: deterministic, documented winner (first artist_name).
        $first = $this->json($controller->getAlbum($this->request(), ['mbid' => $sharedTitle]));
        $this->assertSame($this->prefix . 'M1 First', $first['album']['artist']);
        $this->assertSame(111, $first['album']['tracks'][0]['duration_secs']);

        // Repeatable, which is the property that was missing.
        $again = $this->json($controller->getAlbum($this->request(), ['mbid' => $sharedTitle]));
        $this->assertSame($first['album'], $again['album'], 'The winner must not vary between calls');

        // 2) With ?artist=: the CORRECT album, not the deterministic default.
        $scoped = $this->json($controller->getAlbum(
            $this->request(['artist' => $this->prefix . 'M2 Second']),
            ['mbid' => $sharedTitle],
        ));
        $this->assertSame($this->prefix . 'M2 Second', $scoped['album']['artist']);
        $this->assertSame(222, $scoped['album']['tracks'][0]['duration_secs']);
        $this->assertSame(1, $scoped['album']['track_count']);

        // 3) A title that exists under a DIFFERENT artist still 404s when scoped.
        $wrong = $controller->getAlbum(
            $this->request(['artist' => $this->prefix . 'A Alpha']),
            ['mbid' => $sharedTitle],
        );
        $this->assertSame(404, $wrong->statusCode, 'The artist scope must actually restrict the lookup');
    }

    // -------------------------------------------------------------------------
    // Harness
    // -------------------------------------------------------------------------

    private function controller(): MusicController
    {
        $db = $this->db;
        $this->assertNotNull($db);

        return new MusicController(
            // Read-path test: the scanner collaborator is never exercised.
            new MusicLibraryService($db, $this->createMock(MusicLibraryScanner::class)),
            new SessionManager($db),
        );
    }

    /**
     * @param array<string, string> $query Extra/overriding query parameters.
     */
    private function request(array $query = []): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->query = array_merge(['limit' => '100', 'offset' => '0'], $query);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = (array) json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Reduce an unscoped listing to this fixture's rows, by a prefixed key.
     *
     * @param mixed $rows Decoded listing payload.
     * @param string $key Row key carrying the prefixed name (`artist` or `name`).
     * @return list<array<string, mixed>>
     */
    private function minePrefixed(mixed $rows, string $key): array
    {
        $out = [];
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $value = $row[$key] ?? null;
            if (is_string($value) && str_starts_with($value, $this->prefix)) {
                /** @var array<string, mixed> $row */
                $out[] = $row;
            }
        }

        return $out;
    }

    private function unprefixed(mixed $value): string
    {
        return str_replace($this->prefix, '', is_string($value) ? $value : '');
    }

    /**
     * Two artists / three albums / six tracks, tagged for real in the `music_*`
     * tables while their `media_items` rows carry only `{"name","sub_type"}`.
     */
    private function seedFixtures(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $db->query(
            "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'music', '[]')",
            [$this->libraryId, self::LIBRARY_NAME],
        );

        // Insertion order is the OPPOSITE of display order at every level, so a
        // passing ordering assertion cannot be an auto-increment coincidence.
        $beta = $this->artist('B Beta');
        $alpha = $this->artist('A Alpha');

        $zed = $this->album($alpha, 'Zed Album', 1);
        $aardvark = $this->album($alpha, 'Aardvark Album', 2);
        $only = $this->album($beta, 'Only Album', 3);

        $this->track($aardvark, $alpha, 'Alpha d1t2', 1, 2);
        $this->track($aardvark, $alpha, 'Alpha d1t1', 1, 1);
        $this->track($zed, $alpha, 'Zed d1t1', 1, 1);
        $this->track($only, $beta, 'Beta d2t1', 2, 1);
        $this->track($only, $beta, 'Beta d1t2', 1, 2);
        $this->track($only, $beta, 'Beta d1t1', 1, 1);
    }

    /**
     * Push a track past the legacy 1,000-row scan page.
     *
     * `ItemRepository::getByType()` orders by `sort_title, name`; the scanner
     * leaves `sort_title` NULL on music rows (mirrored here), so `name` decides.
     * `filler-*` rows therefore fill the whole first page and `zzz-target` lands
     * behind them.
     */
    private function seedTrackBeyondLegacyScanPage(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        // Batched inserts (100 rows per statement) — never one query per row.
        $chunk = [];
        $flush = function () use ($db, &$chunk): void {
            if (count($chunk) === 0) {
                return;
            }
            $values = implode(',', array_fill(0, count($chunk) / 5, "(?, ?, ?, 'track', ?, ?)"));
            $db->query(
                "INSERT INTO media_items (id, library_id, name, type, path, metadata_json) VALUES " . $values,
                $chunk,
            );
            $chunk = [];
        };

        for ($i = 1; $i <= self::FILLER_TRACKS; $i++) {
            $id = Uuid::v4();
            $name = sprintf('%sfiller-%04d', $this->prefix, $i);
            array_push(
                $chunk,
                $id,
                $this->libraryId,
                $name,
                '/s99-music-it/' . $id . '.flac',
                (string) json_encode(['sub_type' => 'track', 'name' => $name]),
            );
            if (count($chunk) >= 500) {
                $flush();
            }
        }
        $flush();

        // The target: last by name, and the only one with a music_tracks row.
        $this->beyondPageTrackId = $this->track(
            $this->albumIdByTitle('Aardvark Album'),
            $this->artistIds[1],
            'zzz-target',
            1,
            99,
            321,
        );
    }

    /**
     * Seeds one artist with `$albums` albums of `$tracksPerAlbum` tracks each.
     *
     * Batched inserts throughout (never one statement per row): the fan-out test
     * needs 2,500 track rows to push the embedded-track batch past its ceiling, and
     * a per-row loop would dominate the suite's runtime.
     *
     * @param string $artistName Fully prefixed artist name.
     * @param int $albums How many albums to create.
     * @param int $tracksPerAlbum Tracks per album (0 = albums only, which is all
     *        the `?artist=` paging test needs).
     */
    private function seedBulkAlbums(string $artistName, int $albums, int $tracksPerAlbum): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $db->query("INSERT INTO music_artists (name) VALUES (?)", [$artistName]);
        $artistId = $this->lookupId('music_artists', 'name', $artistName);
        $this->artistIds[] = $artistId;

        // Albums, 100 rows per statement.
        for ($start = 1; $start <= $albums; $start += 100) {
            $params = [];
            $tuples = [];
            for ($i = $start; $i < $start + 100 && $i <= $albums; $i++) {
                $tuples[] = '(?, ?, ?, ?)';
                array_push($params, $artistId, sprintf('Bulk Album %03d', $i), 2001, $tracksPerAlbum);
            }
            $db->query(
                'INSERT INTO music_albums (artist_id, title, year, total_tracks) VALUES '
                . implode(',', $tuples),
                $params,
            );
        }

        if ($tracksPerAlbum === 0) {
            return;
        }

        /** @var array<int, int> $albumIds title index => album id */
        $albumIds = [];
        $rows = $db->query(
            'SELECT id, title FROM music_albums WHERE artist_id = ? ORDER BY title',
            [$artistId],
        );
        $this->assertIsArray($rows);
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $albumIds[] = (int) $row['id'];
        }
        $this->assertCount($albums, $albumIds);

        // media_items + music_tracks, 50 rows per statement each.
        $mediaBatch = [];
        $trackBatch = [];
        $flush = function () use ($db, &$mediaBatch, &$trackBatch): void {
            if (count($mediaBatch) > 0) {
                $db->query(
                    "INSERT INTO media_items (id, library_id, name, type, path, metadata_json) VALUES "
                    . implode(',', array_fill(0, intdiv(count($mediaBatch), 5), "(?, ?, ?, 'track', ?, ?)")),
                    $mediaBatch,
                );
                $mediaBatch = [];
            }
            if (count($trackBatch) > 0) {
                $db->query(
                    "INSERT INTO music_tracks
                        (media_item_id, album_id, artist_id, title, track_number, disc_number, duration_secs)
                     VALUES " . implode(',', array_fill(0, intdiv(count($trackBatch), 7), '(?, ?, ?, ?, ?, ?, ?)')),
                    $trackBatch,
                );
                $trackBatch = [];
            }
        };

        foreach ($albumIds as $albumIndex => $albumId) {
            for ($t = 1; $t <= $tracksPerAlbum; $t++) {
                $mediaItemId = Uuid::v4();
                $name = sprintf('%sbulk-%03d-%02d', $this->prefix, $albumIndex + 1, $t);
                array_push(
                    $mediaBatch,
                    $mediaItemId,
                    $this->libraryId,
                    $name,
                    '/s99-music-it/' . $mediaItemId . '.flac',
                    (string) json_encode(['sub_type' => 'track', 'name' => $name]),
                );
                array_push($trackBatch, $mediaItemId, $albumId, $artistId, $name, $t, 1, 200);
                if (count($trackBatch) >= 350) {
                    $flush();
                }
            }
        }
        $flush();
    }

    /**
     * Seeds the skewed fan-out fixture: one artist, 100 albums of VERY unequal
     * length, tracks inserted in REVERSE play order.
     *
     * Album titles ascend with insertion order, so `ORDER BY title` == `ORDER BY id`
     * and the longest albums hold the lowest ids — the ordering under which a flat
     * batch `LIMIT` starves the short albums instead of sharing round-robin.
     *
     * @param string $artistName Fully prefixed artist name.
     * @return array<int, int> Map of album id => its TRUE track count, in title order.
     */
    private function seedSkewedAlbums(string $artistName): array
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $db->query('INSERT INTO music_artists (name) VALUES (?)', [$artistName]);
        $artistId = $this->lookupId('music_artists', 'name', $artistName);
        $this->artistIds[] = $artistId;

        /** @var list<int> $plan Track count per album, in insertion (= title) order. */
        $plan = [];
        foreach (self::SKEW_TRACK_COUNTS as $bucket => $tracks) {
            for ($k = 0; $k < self::SKEW_ALBUM_COUNTS[$bucket]; $k++) {
                $plan[] = $tracks;
            }
        }

        // Albums, 100 rows per statement.
        foreach (array_chunk($plan, 100, true) as $chunk) {
            $tuples = [];
            $params = [];
            foreach ($chunk as $index => $tracks) {
                $tuples[] = '(?, ?, ?, ?)';
                array_push($params, $artistId, sprintf('Skew Album %03d', $index + 1), 2001, $tracks);
            }
            $db->query(
                'INSERT INTO music_albums (artist_id, title, year, total_tracks) VALUES ' . implode(',', $tuples),
                $params,
            );
        }

        /** @var array<int, int> $trueCounts album id => true track count */
        $trueCounts = [];
        $rows = $db->query('SELECT id FROM music_albums WHERE artist_id = ? ORDER BY title', [$artistId]);
        $this->assertIsArray($rows);
        $this->assertCount(count($plan), $rows);
        $albumIds = [];
        foreach ($rows as $i => $row) {
            $this->assertIsArray($row);
            $albumIds[$i] = (int) $row['id'];
            $trueCounts[(int) $row['id']] = $plan[$i];
        }
        // The discriminating property: longest album => lowest id.
        $this->assertSame(min($albumIds), $albumIds[0], 'The 125-track album must hold the LOWEST album id');

        $media = [];
        $tracks = [];
        $flush = function () use ($db, &$media, &$tracks): void {
            if (count($media) > 0) {
                $db->query(
                    "INSERT INTO media_items (id, library_id, name, type, path, metadata_json) VALUES "
                    . implode(',', array_fill(0, intdiv(count($media), 5), "(?, ?, ?, 'track', ?, ?)")),
                    $media,
                );
                $media = [];
            }
            if (count($tracks) > 0) {
                $db->query(
                    "INSERT INTO music_tracks
                        (media_item_id, album_id, artist_id, title, track_number, disc_number, duration_secs)
                     VALUES " . implode(',', array_fill(0, intdiv(count($tracks), 7), '(?, ?, ?, ?, ?, ?, ?)')),
                    $tracks,
                );
                $tracks = [];
            }
        };

        foreach ($albumIds as $index => $albumId) {
            // REVERSE: highest disc/track first, so auto-increment id order is the
            // exact opposite of play order.
            for ($t = $plan[$index]; $t >= 1; $t--) {
                $disc = 1 + intdiv($t - 1, self::SKEW_TRACKS_PER_DISC);
                $trackNumber = $t - (($disc - 1) * self::SKEW_TRACKS_PER_DISC);
                $mediaItemId = Uuid::v4();
                $name = sprintf('%sskew-%03d-%03d', $this->prefix, $index + 1, $t);
                array_push(
                    $media,
                    $mediaItemId,
                    $this->libraryId,
                    $name,
                    '/s99-music-it/' . $mediaItemId . '.flac',
                    (string) json_encode(['sub_type' => 'track', 'name' => $name]),
                );
                array_push($tracks, $mediaItemId, $albumId, $artistId, $name, $trackNumber, $disc, 200);
                if (count($tracks) >= 350) {
                    $flush();
                }
            }
        }
        $flush();

        return $trueCounts;
    }

    /**
     * Play order (`disc_number, track_number, id`) per album, in ONE query.
     *
     * Computed straight from `music_tracks` so the expectation is independent of the
     * endpoint under test.
     *
     * @param list<int> $albumIds
     * @return array<int, list<string>> album id => track titles in play order
     */
    private function playOrderByAlbum(array $albumIds): array
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $placeholders = implode(',', array_fill(0, count($albumIds), '?'));
        $rows = $db->query(
            "SELECT album_id, title FROM music_tracks
             WHERE album_id IN ({$placeholders})
             ORDER BY album_id, disc_number, track_number, id",
            $albumIds,
        );
        $this->assertIsArray($rows);

        /** @var array<int, list<string>> $byAlbum */
        $byAlbum = [];
        foreach ($albumIds as $id) {
            $byAlbum[$id] = [];
        }
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $byAlbum[(int) $row['album_id']][] = (string) $row['title'];
        }

        return $byAlbum;
    }

    /**
     * Resolve `Skew Album NNN` back to its album id via the seeded map.
     *
     * @param array<int, int> $trueCounts album id => true track count (title order)
     */
    private function skewAlbumIdByTitle(array $trueCounts, string $title): int
    {
        $index = (int) substr($title, -3);
        $this->assertGreaterThan(0, $index, 'Unexpected skew album title: ' . $title);

        $ids = array_keys($trueCounts);

        return $ids[$index - 1];
    }

    private function artist(string $name): int
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $db->query("INSERT INTO music_artists (name) VALUES (?)", [$this->prefix . $name]);
        $id = $this->lookupId('music_artists', 'name', $this->prefix . $name);
        $this->artistIds[] = $id;

        return $id;
    }

    private function album(int $artistId, string $title, int $totalTracks): int
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $db->query(
            "INSERT INTO music_albums (artist_id, title, year, total_tracks, album_art_url)
             VALUES (?, ?, ?, ?, ?)",
            [$artistId, $title, 1999, $totalTracks, 'https://art.invalid/' . rawurlencode($title) . '.jpg'],
        );

        return $this->lookupId('music_albums', 'artist_id', (string) $artistId, $title);
    }

    private function albumIdByTitle(string $title): int
    {
        return $this->lookupId('music_albums', 'artist_id', (string) $this->artistIds[1], $title);
    }

    /**
     * Insert one track: a `media_items` row with production's minimal
     * `metadata_json`, plus the `music_tracks` row that carries the real tags.
     *
     * @return string The track's `media_items` UUID (its public API id).
     */
    private function track(
        int $albumId,
        int $artistId,
        string $title,
        int $disc,
        int $trackNumber,
        int $durationSecs = 180,
    ): string {
        $db = $this->db;
        $this->assertNotNull($db);

        $mediaItemId = Uuid::v4();
        $name = $this->prefix . $title;
        $db->query(
            "INSERT INTO media_items (id, library_id, name, type, path, metadata_json)
             VALUES (?, ?, ?, 'track', ?, ?)",
            [
                $mediaItemId,
                $this->libraryId,
                $name,
                '/s99-music-it/' . $mediaItemId . '.flac',
                // EXACTLY what MusicLibraryScanner::createMediaItem() writes.
                (string) json_encode(['sub_type' => 'track', 'name' => $name]),
            ],
        );

        $db->query(
            "INSERT INTO music_tracks
                (media_item_id, album_id, artist_id, title, track_number, disc_number, duration_secs)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$mediaItemId, $albumId, $artistId, $name, $trackNumber, $disc, $durationSecs],
        );

        return $mediaItemId;
    }

    /**
     * Resolve an AUTO_INCREMENT id by unique-ish lookup. `LAST_INSERT_ID()` is
     * not safe here: the pooled connection may hand a different socket to the
     * follow-up query.
     */
    private function lookupId(string $table, string $column, string $value, ?string $title = null): int
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $sql = sprintf('SELECT id FROM %s WHERE %s = ?', $table, $column);
        $params = [$value];
        if ($title !== null) {
            $sql .= ' AND title = ?';
            $params[] = $title;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';

        $rows = $db->query($sql, $params);
        $this->assertIsArray($rows);
        $this->assertArrayHasKey(0, $rows);
        $row = $rows[0];
        $this->assertIsArray($row);
        $this->assertArrayHasKey('id', $row);

        return (int) $row['id'];
    }

    private function purgeFixtures(): void
    {
        $db = $this->db;
        if ($db === null) {
            return;
        }

        // Deleting the library cascades media_items, which cascades music_tracks;
        // deleting the artists cascades music_albums.
        if ($this->libraryId !== '') {
            $db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }
        foreach ($this->artistIds as $artistId) {
            $db->query('DELETE FROM music_artists WHERE id = ?', [$artistId]);
        }
    }

    /**
     * Delete anything left in this fixture's namespace by an interrupted run.
     *
     * Scoped to the `!S99-` artist prefix and this class's library name, so it can
     * never touch a sibling test's data (or a developer's own library).
     */
    private function purgeNamespace(): void
    {
        $db = $this->db;
        if ($db === null) {
            return;
        }

        $db->query('DELETE FROM libraries WHERE name = ?', [self::LIBRARY_NAME]);
        $db->query('DELETE FROM music_artists WHERE name LIKE ?', [self::NAMESPACE_PREFIX . '%']);
    }
}
