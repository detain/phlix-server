<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Uuid;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Server\Http\Controllers\MusicController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use Throwable;
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
 * @covers \Phlix\Server\Http\Controllers\MusicController
 * @covers \Phlix\Media\Music\MusicLibraryService
 */
final class MusicApiReadPathIntegrationTest extends TestCase
{
    /** Fixture namespace. Every seeded artist name and library carries it. */
    private const NAMESPACE_PREFIX = '!S99-';

    /** Library name, used to sweep leftovers from an interrupted run. */
    private const LIBRARY_NAME = 'S99 Music IT Library';

    /** How many filler tracks the >1,000th-row playback test seeds. */
    private const FILLER_TRACKS = 1000;

    /** The page size the pre-S99 track lookup linear-scanned. */
    private const LEGACY_SCAN_PAGE = 1000;

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

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);

        if (!$this->isMysqlReachable($host, $port)) {
            $this->markTestSkipped(
                sprintf('No MySQL on %s:%d — skipping music read-path integration test. Runs in CI.', $host, $port),
            );
        }

        try {
            ConnectionPool::init(dirname(__DIR__, 3) . '/config/database.php');
            $this->db = ConnectionPool::getConnection('mysql');
        } catch (Throwable $e) {
            $this->markTestSkipped('Could not connect to MySQL: ' . $e->getMessage());
        }

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

    private function request(): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->query = ['limit' => '100', 'offset' => '0'];

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

    private function isMysqlReachable(string $host, int $port): bool
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if ($sock === false) {
            return false;
        }
        fclose($sock);

        return true;
    }
}
