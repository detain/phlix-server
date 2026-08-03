<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Common\Uuid;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Server\Http\Controllers\MusicController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Session\SessionManager;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S99 end-to-end: rows written by the REAL {@see MusicLibraryScanner} are served
 * by the REAL {@see MusicController}, with no hand-built fixture in between.
 *
 * **Why this exists next to {@see MusicApiReadPathIntegrationTest}.** That test
 * hits real MySQL, which is the important half — but it seeds the `music_*` and
 * `media_items` rows with its own `INSERT` statements, and its claim that they
 * match production is carried by a code COMMENT ("EXACTLY what
 * MusicLibraryScanner::createMediaItem() writes") plus one assertion on the
 * `metadata_json` key set. A fixture that hand-constructs the very structure the
 * code under test consumes cannot distinguish "the read path handles what the
 * scanner writes" from "the read path handles what this file writes" — and S99's
 * root cause was precisely a writer/reader disagreement that three separate
 * diagnoses missed.
 *
 * So this test never writes a `music_*` or `media_items` row itself. It runs
 * `MusicLibraryScanner::scanDirectory()` against a synthetic tree and then reads
 * `/api/v1/music/*` back. The ONLY thing it stubs is `probeViaGetId3()` — the
 * getID3 tag read — via the existing {@see BackfillTaggedScanner} seam, because
 * the tags have to be deterministic. Every write (media_items minting,
 * `metadata_json` content, the artist/album/track upserts and their FK wiring) is
 * production code.
 *
 * The complementary shape: {@see MusicApiReadPathIntegrationTest} covers the
 * fan-out caps, name-keyed lookups and the >1,000th-row track at volumes a real
 * scan would make far too slow here; this covers the writer/reader contract those
 * volumes assume.
 */
final class MusicScannerToApiReadPathIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    /** Tracks written into the scanned tree. */
    private const TRACK_COUNT = 6;

    /** Per-track duration the stubbed tag reader reports. */
    private const DURATION_SECS = 251;

    /** Release year the stubbed tag reader reports. */
    private const YEAR = 1997;

    private ?Connection $db = null;

    private string $libraryId = '';

    /** Fixture namespace; `!` collates first so these rows stay on page 1. */
    private string $prefix = '';

    private string $artistName = '';

    private string $albumTitle = '';

    private string $root = '';

    /** @var list<string> Paths to remove in tearDown, in creation order. */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping the S99 scanner→API test. Runs in CI.');
        $this->assertNotNull($this->db);

        $this->libraryId = Uuid::v4();
        $this->prefix = '!S99E2E-' . substr(Uuid::v4(), 0, 8) . '-';
        $this->artistName = $this->prefix . 'Portishead';
        $this->albumTitle = $this->prefix . 'Dummy';

        $this->db->query(
            "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'music', '[]')",
            [$this->libraryId, 'S99 scanner→API IT Library'],
        );

        $this->buildTree();
        $this->runRealScan();
    }

    protected function tearDown(): void
    {
        $this->purgeFixtures();

        foreach (array_reverse($this->cleanup) as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
        $this->cleanup = [];

        parent::tearDown();
    }

    /**
     * The premise, measured rather than asserted from a comment: what the REAL
     * scanner stamps into `media_items.metadata_json` for a track carries no
     * `artist` / `album` / `year` at all. That is the production shape the pre-S99
     * reader was reading — and the reason it answered with defaults.
     */
    public function testTheRealScannerWritesNoTagsIntoMetadataJson(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $rows = $db->query(
            "SELECT metadata_json FROM media_items WHERE library_id = ? AND type = 'track'",
            [$this->libraryId],
        );
        $this->assertIsArray($rows);
        $this->assertCount(self::TRACK_COUNT, $rows, 'the scan must have minted one media_items row per file');

        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $raw = $row['metadata_json'] ?? null;
            $this->assertIsString($raw);
            $decoded = json_decode($raw, true);
            $this->assertIsArray($decoded);

            $keys = array_keys($decoded);
            sort($keys);
            // ⚠ MEASURED, not assumed. `MusicApiReadPathIntegrationTest`'s
            // hand-built fixture writes `{name, sub_type}` and its comment claims
            // that is "EXACTLY what MusicLibraryScanner::createMediaItem() writes".
            // It is not, and has not been since the S95 skip-unchanged work: the
            // real scanner also stamps `file_size` + `file_mtime`
            // (MusicLibraryScanner passes them as `$extraMetadata`). Neither is a
            // tag, so the S99 premise is unaffected — but the claim was stale, and
            // only a scan can tell.
            $this->assertSame(
                ['file_mtime', 'file_size', 'name', 'sub_type'],
                $keys,
                'the scanner writes name+sub_type+file_size+file_mtime — if this changes, re-check the S99 premise',
            );
            $this->assertArrayNotHasKey('artist', $decoded);
            $this->assertArrayNotHasKey('album', $decoded);
            $this->assertArrayNotHasKey('year', $decoded);
        }
    }

    /**
     * THE acceptance criterion for `/tracks`: real title, artist, album and
     * duration for rows the scanner actually wrote, and a non-zero `total`.
     *
     * Pre-S99 every one of these read a default off the empty `metadata_json`
     * (`'Unknown Artist'` / `'Unknown Album'` / `0`) and `total` was hardcoded 0
     * because the handler summed a `libraries.item_count` column that does not
     * exist.
     */
    public function testTracksEndpointServesTheTagsTheScannerHarvested(): void
    {
        $body = $this->json($this->controller()->listTracks($this->request(), []));

        $this->assertIsArray($body['tracks'] ?? null);
        $mine = $this->minePrefixed($body['tracks'], 'artist');
        $this->assertCount(self::TRACK_COUNT, $mine);

        foreach ($mine as $track) {
            $this->assertSame($this->artistName, $track['artist'] ?? null);
            $this->assertSame($this->albumTitle, $track['album'] ?? null);
            $this->assertSame(self::DURATION_SECS, $track['duration_secs'] ?? null);
            $this->assertIsString($track['name'] ?? null);
            $this->assertStringStartsWith('Track ', (string) $track['name']);
            // The public id is the media_items UUID, not music_tracks.id.
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-/',
                (string) ($track['id'] ?? ''),
            );
        }

        $this->assertIsInt($body['total'] ?? null);
        $this->assertGreaterThanOrEqual(self::TRACK_COUNT, $body['total']);
    }

    /**
     * `/artists` must count the scanner's real rows — not answer with the single
     * bogus `{"name":"Unknown Artist","track_count":100}` the pre-S99 handler
     * produced from the `getByType()` default page size.
     */
    public function testArtistsEndpointListsTheScannedArtistWithRealCounts(): void
    {
        $body = $this->json($this->controller()->listArtists($this->request(), []));

        $this->assertIsArray($body['artists'] ?? null);
        $mine = $this->minePrefixed($body['artists'], 'name');
        $this->assertCount(1, $mine, 'exactly one artist was scanned');

        $artist = $mine[0];
        $this->assertSame($this->artistName, $artist['name'] ?? null);
        $this->assertSame(1, $artist['album_count'] ?? null);
        $this->assertSame(self::TRACK_COUNT, $artist['track_count'] ?? null);
        $this->assertNotSame('Unknown Artist', $artist['name'] ?? null);

        $this->assertIsInt($body['total'] ?? null);
        $this->assertGreaterThanOrEqual(1, $body['total']);
    }

    /**
     * `/albums` must carry the album's artist and year, both of which live only in
     * `music_albums` / `music_artists` — never in `metadata_json`.
     */
    public function testAlbumsEndpointCarriesTheScannedArtistAndYear(): void
    {
        $body = $this->json($this->controller()->listAlbums($this->request(), []));

        $this->assertIsArray($body['albums'] ?? null);
        // The album's display title is served under `name` (formatAlbum()), NOT
        // `title` — the client contract has no album PK, so `name` IS the identity
        // `/albums/{mbid}` takes back.
        $mine = $this->minePrefixed($body['albums'], 'name');
        $this->assertCount(1, $mine);

        $album = $mine[0];
        $this->assertSame($this->albumTitle, $album['name'] ?? null);
        $this->assertSame($this->artistName, $album['artist'] ?? null);
        $this->assertSame(self::YEAR, $album['year'] ?? null);
        $this->assertSame(self::TRACK_COUNT, $album['track_count'] ?? null);
    }

    /**
     * The SPA is NAME-keyed: `/api/v1/music/artists/{mbid}` and
     * `/albums/{mbid}` receive the display name, not an integer PK. Both detail
     * routes must resolve a scanner-written row by that name.
     */
    public function testDetailRoutesResolveTheScannedRowsByName(): void
    {
        $artist = $this->json(
            $this->controller()->getArtist($this->request(), ['mbid' => $this->artistName]),
        );
        $this->assertIsArray($artist['artist'] ?? null);
        $this->assertSame($this->artistName, $artist['artist']['name'] ?? null);

        $album = $this->json(
            $this->controller()->getAlbum($this->request(), ['mbid' => $this->albumTitle]),
        );
        $this->assertIsArray($album['album'] ?? null);
        $this->assertSame($this->albumTitle, $album['album']['name'] ?? null);
        $this->assertSame($this->artistName, $album['album']['artist'] ?? null);
        $this->assertCount(self::TRACK_COUNT, $album['album']['tracks'] ?? []);
    }

    /**
     * A single track resolves by the `media_items` UUID the scanner minted — the
     * id every other endpoint hands the client — and carries its real tags.
     */
    public function testASingleScannedTrackResolvesByItsMediaItemUuid(): void
    {
        $list = $this->json($this->controller()->listTracks($this->request(), []));
        $this->assertIsArray($list['tracks'] ?? null);
        $mine = $this->minePrefixed($list['tracks'], 'artist');
        $this->assertNotSame([], $mine);

        $id = (string) ($mine[0]['id'] ?? '');
        $this->assertNotSame('', $id);

        $response = $this->controller()->getTrack($this->request(), ['id' => $id]);
        $this->assertSame(200, $response->statusCode);

        $body = $this->json($response);
        $this->assertIsArray($body['track'] ?? null);
        $this->assertSame($id, $body['track']['id'] ?? null);
        $this->assertSame($this->artistName, $body['track']['artist'] ?? null);
        $this->assertSame(self::DURATION_SECS, $body['track']['duration_secs'] ?? null);
    }

    // -- fixture ---------------------------------------------------------------

    private function buildTree(): void
    {
        $this->root = sys_get_temp_dir() . '/phlix_s99_e2e_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        $this->cleanup[] = $this->root;

        for ($t = 1; $t <= self::TRACK_COUNT; $t++) {
            $path = $this->root . sprintf('/%02d-track.mp3', $t);
            file_put_contents($path, 'not-real-audio');
            $this->cleanup[] = $path;
        }
    }

    /**
     * Run the production scanner. Only the getID3 tag read is stubbed — see the
     * class docblock for why that seam and nothing else.
     */
    private function runRealScan(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $scanner = new BackfillTaggedScanner($db, $this->createMock(FfmpegRunner::class));
        $artist = $this->artistName;
        $album = $this->albumTitle;
        $scanner->tagger = static fn(string $path): array => [
            'artist' => $artist,
            'album' => $album,
            'title' => 'Track ' . (int) substr(basename($path), 0, 2),
            'track_number' => (int) substr(basename($path), 0, 2),
            'disc_number' => 1,
            'duration_secs' => self::DURATION_SECS,
            'year' => self::YEAR,
            'genre' => 'Trip Hop',
        ];

        $scanner->scanDirectory($this->root, null, $this->libraryId);
    }

    private function purgeFixtures(): void
    {
        $db = $this->db;
        if ($db === null) {
            return;
        }

        // music_albums / music_tracks cascade off music_artists (migration 065).
        $db->query('DELETE FROM music_artists WHERE name LIKE ?', [$this->prefix . '%']);
        $db->query('DELETE FROM media_items WHERE library_id = ?', [$this->libraryId]);
        $db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
    }

    // -- read path -------------------------------------------------------------

    private function controller(): MusicController
    {
        $db = $this->db;
        $this->assertNotNull($db);

        return new MusicController(
            new MusicLibraryService($db, $this->createMock(MusicLibraryScanner::class)),
            $this->createMock(SessionManager::class),
        );
    }

    private function request(): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->query = ['limit' => '100'];

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $decoded = json_decode((string) $response->body, true);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Filter a listing down to THIS run's rows by the namespaced value in `$key`.
     *
     * @param mixed  $rows Listing payload.
     * @param string $key  Field carrying the namespaced name.
     *
     * @return list<array<string, mixed>>
     */
    private function minePrefixed(mixed $rows, string $key): array
    {
        $this->assertIsArray($rows);

        $out = [];
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
}
