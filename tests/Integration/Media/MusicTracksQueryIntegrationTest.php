<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Auth\AuthManager;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Uuid;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\WebPortalRouter;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Real-DB proof for {@see MusicLibraryService::getAllTracks()} — the query
 * behind `GET /api/v1/music/tracks` (S94 · live-investigation).
 *
 * The shipped query selected AND ordered by `al.name`, but `music_albums` has
 * a `title` column and no `name` column at all (migration 065), so every single
 * call to the endpoint died with `SQLSTATE[42S22] Unknown column 'al.name' in
 * 'field list'` — and because `WebPortalRouter::getMusicTracks()` has no
 * try/catch, that surfaced as an unguarded HTTP 500.
 *
 * A mocked `Connection` cannot catch a wrong column name — it happily returns
 * whatever rows the test feeds it, which is precisely how this defect shipped
 * past a mock-only unit suite (the same class of miss as the LiveTv
 * RowQuery/ResultSet and metrics ONLY_FULL_GROUP_BY bugs). So this test runs the
 * real query against real MySQL. The SQL *shape* is additionally pinned in
 * {@see \Phlix\Tests\Unit\Media\Music\MusicLibraryServiceTest} for runs with no
 * database.
 *
 * The fixture is built so the expected ordering (`artist name → album title →
 * disc → track`) differs from insertion/auto-increment order on every level:
 * the later-inserted artist sorts first, the later-inserted album sorts first,
 * and tracks are inserted with their disc/track numbers descending.
 *
 * CI applies all migrations to the `phlix_test` MySQL service before the suite;
 * locally, with no reachable MySQL, it self-skips — the same guard
 * {@see \Phlix\Tests\Integration\Auth\NextUpIntegrationTest} uses.
 *
 * @covers \Phlix\Media\Music\MusicLibraryService
 */
final class MusicTracksQueryIntegrationTest extends TestCase
{
    private ?Connection $db = null;

    private string $libraryId = '';

    /**
     * Fixture-local name prefix. `getAllTracks()` is unscoped (it returns every
     * track in the library), so assertions filter to this run's rows — the
     * global ORDER BY preserves their relative order.
     */
    private string $prefix = '';

    /** @var list<int> Seeded music_artists ids (cascade-deletes albums + tracks). */
    private array $artistIds = [];

    /** @var list<string> Seeded media_items ids. */
    private array $mediaIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);

        if (!$this->isMysqlReachable($host, $port)) {
            $this->markTestSkipped(
                sprintf('No MySQL on %s:%d — skipping music tracks integration test. Runs in CI.', $host, $port),
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
        $this->prefix = 'S94-' . substr(Uuid::v4(), 0, 8) . '-';

        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $this->purgeFixtures();
        parent::tearDown();
    }

    /**
     * The query must execute at all (the regression), expose the album title
     * under the contractual `album_name` key, and order by
     * artist → album title → disc → track.
     */
    public function testGetAllTracksJoinsTheAlbumTitleAndOrdersByArtistAlbumDiscTrack(): void
    {
        $service = $this->service();

        // Reverting `al.title` to `al.name` makes this call throw
        // "Unknown column 'al.name' in 'field list'" instead of returning rows.
        $rows = $service->getAllTracks(100, 0);

        $mine = $this->onlyMine($rows);

        $this->assertSame(
            [
                // Artist A (inserted SECOND) sorts before artist B.
                ['A Alpha', 'Aardvark Album', 1, 1, 'Alpha d1t1'],
                ['A Alpha', 'Aardvark Album', 1, 2, 'Alpha d1t2'],
                // "Zed Album" was inserted FIRST (lower id) but sorts last.
                ['A Alpha', 'Zed Album', 1, 1, 'Zed d1t1'],
                ['B Beta', 'Only Album', 1, 1, 'Beta d1t1'],
                ['B Beta', 'Only Album', 1, 2, 'Beta d1t2'],
                ['B Beta', 'Only Album', 2, 1, 'Beta d2t1'],
            ],
            $mine,
            'Tracks must be ordered by artist name, then album TITLE, then disc, then track number',
        );
    }

    /**
     * Every row carries the joined `artist_name`/`album_name` aliases plus the
     * `music_tracks` columns `WebPortalRouter::getMusicTracks()` shapes for the
     * client. Renaming an alias would silently blank a field on every card.
     */
    public function testEveryRowCarriesTheKeysTheApiResponseShapeReads(): void
    {
        $rows = $this->service()->getAllTracks(100, 0);

        $seen = 0;
        foreach ($rows as $row) {
            $artist = $row['artist_name'] ?? null;
            if (!is_string($artist) || !str_starts_with($artist, $this->prefix)) {
                continue;
            }
            ++$seen;
            foreach (['id', 'title', 'artist_name', 'album_name', 'track_number', 'duration_secs'] as $key) {
                $this->assertArrayHasKey($key, $row, sprintf('Row is missing the `%s` key', $key));
            }
            $this->assertNotSame('', (string) $row['album_name'], 'album_name must be the joined album title');
            $this->assertSame(180, (int) $row['duration_secs']);
        }

        $this->assertSame(6, $seen, 'All six seeded tracks must come back');
    }

    /**
     * LIMIT/OFFSET must page through that one ordering rather than re-shuffling
     * (asserted relative to the full page, so unrelated rows cannot break it).
     */
    public function testLimitAndOffsetSliceTheSameOrdering(): void
    {
        $service = $this->service();

        $full = array_map(
            static fn(array $row): string => (string) ($row['id'] ?? ''),
            $service->getAllTracks(100, 0),
        );
        $this->assertGreaterThanOrEqual(6, count($full));

        $firstTwo = array_map(
            static fn(array $row): string => (string) ($row['id'] ?? ''),
            $service->getAllTracks(2, 0),
        );
        $this->assertSame(array_slice($full, 0, 2), $firstTwo);

        $nextTwo = array_map(
            static fn(array $row): string => (string) ($row['id'] ?? ''),
            $service->getAllTracks(2, 2),
        );
        $this->assertSame(array_slice($full, 2, 2), $nextTwo);
    }

    /**
     * The S94 acceptance criterion at the seam that actually 500'd:
     * `WebPortalRouter::getMusicTracks()` has no try/catch, so the SQL error
     * escaped as an unguarded HTTP 500. Assert the handler answers **200** with
     * the client-shaped, correctly-ordered rows.
     */
    public function testTheTracksEndpointHandlerAnswers200WithOrderedRows(): void
    {
        $router = new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $this->createMock(ItemRepository::class),
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
            musicLibraryService: $this->service(),
        );

        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/music/tracks';
        $request->query = ['limit' => '100', 'offset' => '0'];

        $response = $router->getMusicTracks($request, []);

        $this->assertSame(200, $response->statusCode, 'GET /api/v1/music/tracks must not 500');

        /** @var array<string, mixed> $payload */
        $payload = (array) json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('tracks', $payload);
        $this->assertIsArray($payload['tracks']);
        $this->assertGreaterThanOrEqual(6, $payload['total']);

        $mine = [];
        foreach ($payload['tracks'] as $track) {
            $this->assertIsArray($track);
            $artist = $track['artist'] ?? null;
            if (!is_string($artist) || !str_starts_with($artist, $this->prefix)) {
                continue;
            }
            $mine[] = [
                substr($artist, strlen($this->prefix)),
                (string) ($track['album'] ?? ''),
                (int) ($track['track_number'] ?? 0),
            ];
        }

        // The shaped `album` field is fed by the `album_name` alias — with the
        // pre-fix `al.name` this handler never even produced a response.
        $this->assertSame(
            [
                ['A Alpha', 'Aardvark Album', 1],
                ['A Alpha', 'Aardvark Album', 2],
                ['A Alpha', 'Zed Album', 1],
                ['B Beta', 'Only Album', 1],
                ['B Beta', 'Only Album', 2],
                ['B Beta', 'Only Album', 1],
            ],
            $mine,
            'The endpoint must expose the album title and preserve the artist→album→disc→track order',
        );
    }

    private function service(): MusicLibraryService
    {
        $db = $this->db;
        $this->assertNotNull($db);

        // Read-path test: the scanner collaborator is never exercised.
        return new MusicLibraryService($db, $this->createMock(MusicLibraryScanner::class));
    }

    /**
     * Reduce the (unscoped) result set to this fixture's rows, as comparable
     * [artist, album, disc, track, title] tuples with the prefix stripped.
     *
     * @param array<array<string, mixed>> $rows
     * @return list<array{0: string, 1: string, 2: int, 3: int, 4: string}>
     */
    private function onlyMine(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $artist = $row['artist_name'] ?? null;
            if (!is_string($artist) || !str_starts_with($artist, $this->prefix)) {
                continue;
            }
            $out[] = [
                substr($artist, strlen($this->prefix)),
                (string) ($row['album_name'] ?? ''),
                (int) ($row['disc_number'] ?? 0),
                (int) ($row['track_number'] ?? 0),
                str_replace($this->prefix, '', (string) ($row['title'] ?? '')),
            ];
        }
        return $out;
    }

    /**
     * Seed two artists / three albums / six tracks whose correct ordering is the
     * reverse of their insertion order at every level of the ORDER BY.
     */
    private function seedFixtures(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $db->query(
            "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'music', '[]')",
            [$this->libraryId, 'S94 Music IT Library'],
        );

        // Artist B first: auto-increment order is the OPPOSITE of name order.
        $beta = $this->artist('B Beta');
        $alpha = $this->artist('A Alpha');

        // "Zed Album" first: auto-increment order is the OPPOSITE of title order.
        $zed = $this->album($alpha, 'Zed Album', 1);
        $aardvark = $this->album($alpha, 'Aardvark Album', 2);
        $only = $this->album($beta, 'Only Album', 3);

        // Tracks inserted with disc/track descending.
        $this->track($aardvark, $alpha, 'Alpha d1t2', 1, 2);
        $this->track($aardvark, $alpha, 'Alpha d1t1', 1, 1);
        $this->track($zed, $alpha, 'Zed d1t1', 1, 1);
        $this->track($only, $beta, 'Beta d2t1', 2, 1);
        $this->track($only, $beta, 'Beta d1t2', 1, 2);
        $this->track($only, $beta, 'Beta d1t1', 1, 1);
    }

    private function artist(string $name): int
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $db->query("INSERT INTO music_artists (name) VALUES (?)", [$this->prefix . $name]);
        $id = $this->lastInsertId('music_artists', 'name', $this->prefix . $name);
        $this->artistIds[] = $id;

        return $id;
    }

    private function album(int $artistId, string $title, int $totalTracks): int
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $db->query(
            "INSERT INTO music_albums (artist_id, title, year, total_tracks) VALUES (?, ?, ?, ?)",
            [$artistId, $title, 1999, $totalTracks],
        );

        return $this->lastInsertId('music_albums', 'artist_id', (string) $artistId, $title);
    }

    private function track(int $albumId, int $artistId, string $title, int $disc, int $trackNumber): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        // music_tracks.media_item_id is NOT NULL and FK-constrained to media_items.
        $mediaItemId = Uuid::v4();
        $db->query(
            "INSERT INTO media_items (id, library_id, name, type, path, metadata_json)
             VALUES (?, ?, ?, 'track', ?, ?)",
            [
                $mediaItemId,
                $this->libraryId,
                $this->prefix . $title,
                '/s94-music-it/' . $mediaItemId . '.flac',
                (string) json_encode(['runtime' => 3]),
            ],
        );
        $this->mediaIds[] = $mediaItemId;

        $db->query(
            "INSERT INTO music_tracks
                (media_item_id, album_id, artist_id, title, track_number, disc_number, duration_secs)
             VALUES (?, ?, ?, ?, ?, ?, 180)",
            [$mediaItemId, $albumId, $artistId, $this->prefix . $title, $trackNumber, $disc],
        );
    }

    /**
     * Resolve an AUTO_INCREMENT id by unique-ish lookup. `LAST_INSERT_ID()` is
     * not safe here: the pooled connection may hand a different socket to the
     * follow-up query.
     */
    private function lastInsertId(string $table, string $column, string $value, ?string $title = null): int
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

        // music_albums/music_tracks cascade off music_artists.
        foreach ($this->artistIds as $artistId) {
            $db->query('DELETE FROM music_artists WHERE id = ?', [$artistId]);
        }
        foreach ($this->mediaIds as $id) {
            $db->query('DELETE FROM media_items WHERE id = ?', [$id]);
        }
        if ($this->libraryId !== '') {
            $db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }
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
