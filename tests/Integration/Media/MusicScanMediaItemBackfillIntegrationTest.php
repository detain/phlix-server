<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Uuid;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Transcoding\FfmpegRunner;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Real-DB proof that a `music_artists` / `music_albums` row whose `media_item_id` is
 * NULL gets BACKFILLED by a later scan instead of staying NULL forever
 * (S96(e) · re-routed from S95 review r1, MED-3).
 *
 * THE DEFECT. {@see MusicLibraryScanner} mints the `media_items` row one autocommitted
 * statement before the matching `music_*` row, and `createMediaItem()` swallows its own
 * `Throwable` and returns `''`. So one transient failure wrote the `music_artists` row
 * with `media_item_id = NULL` — and nothing ever repaired it: both upserts find-or-create
 * on their NATURAL key (`music_artists.name`, `music_albums(artist_id, title)`), so every
 * later scan found that row, returned the stored NULL and short-circuited BEFORE the
 * orphan-adoption lookup could run. The S95 reviewer measured it still NULL after two
 * clean rescans. Nothing fails loudly either, because migration 065 declares both columns
 * `NULL UNIQUE`. The user-visible result is a permanently artwork-less artist/album that
 * no `media_items`-driven surface can see (`/api/v1/media?type=artist`, the DLNA bridge,
 * the stats type maps all count BY TYPE off `media_items`).
 *
 * WHY REAL MYSQL. The claim is about a `NULL UNIQUE` column, a guarded conditional UPDATE
 * (`AND media_item_id IS NULL`) and its AFFECTED-ROW count, and the `ON DELETE SET NULL`
 * FKs from migration 065 — all properties of the real server. This repo has a documented
 * history of mock-DB tests hiding exactly this class of defect (the LiveTv
 * RowQuery/ResultSet and metrics ONLY_FULL_GROUP_BY regressions), and S95's own review
 * measured the NULL residue on real MySQL, not against a double. The in-memory-double
 * twins live in {@see \Phlix\Tests\Unit\Media\Music\MusicLibraryScannerTest}.
 *
 * CI applies all migrations to the `phlix_test` MySQL service before the suite; locally,
 * with no reachable MySQL, it self-skips.
 *
 * @covers \Phlix\Media\Music\MusicLibraryScanner
 */
final class MusicScanMediaItemBackfillIntegrationTest extends TestCase
{
    private ?Connection $db = null;

    private string $libraryId = '';

    /**
     * Fixture-local namespace for `music_artists.name`, which is UNIQUE across the whole
     * table — every run needs its own. Leading `!` collates first, matching the
     * convention the sibling music integration tests settled on.
     */
    private string $prefix = '';

    /** @var string Root of the synthetic audio tree. */
    private string $root = '';

    /** @var list<string> Files and directories to remove in tearDown, in creation order. */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);

        if (!$this->isMysqlReachable($host, $port)) {
            $this->markTestSkipped(
                sprintf('No MySQL on %s:%d — skipping the media_item_id backfill test. Runs in CI.', $host, $port),
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
        $this->prefix = '!S96-' . substr(Uuid::v4(), 0, 8) . '-';

        $this->db->query(
            "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'music', '[]')",
            [$this->libraryId, 'S96 Music IT Library'],
        );
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
     * THE acceptance criterion: a scan heals a NULL `media_item_id` on both the artist
     * and the album row, in place, and points them at real `media_items` rows.
     *
     * Pre-fix this scan is a complete no-op on those two columns — every assertion below
     * reads NULL, which is precisely the state the S95 reviewer found surviving two clean
     * rescans.
     */
    public function testANullMediaItemIdOnArtistAndAlbumIsBackfilledByTheNextScan(): void
    {
        $artistName = $this->prefix . 'Poisoned Artist';
        $albumTitle = 'Poisoned Album';
        $this->buildAlbum($artistName, $albumTitle, 2);

        // The shape one transient createMediaItem() failure leaves behind. Legal,
        // because migration 065 declares both columns NULL UNIQUE — which is why
        // nothing ever failed loudly.
        [$artistId, $albumId] = $this->plantNullLinkedRows($artistName, $albumTitle);
        $this->assertNull($this->artistMediaItemId($artistId), 'fixture precondition');
        $this->assertNull($this->albumMediaItemId($albumId), 'fixture precondition');

        $result = $this->scanner($artistName, $albumTitle)->scanDirectory($this->root, null, $this->libraryId);
        $this->assertSame(2, $result->added, 'the tracks still index normally');
        $this->assertSame(0, $result->failed);

        $healedArtist = $this->artistMediaItemId($artistId);
        $healedAlbum = $this->albumMediaItemId($albumId);

        $this->assertIsString(
            $healedArtist,
            'music_artists.media_item_id must be backfilled. NULL here is the S96(e) defect: the '
            . 'natural-key branch returns the stored NULL and short-circuits before the adoption '
            . 'lookup, so no scan — however clean — ever repairs it.',
        );
        $this->assertIsString($healedAlbum, 'music_albums.media_item_id must be backfilled too');

        // The ids point at REAL rows of the right ENUM member (`artist`/`album`, never
        // `music_*` — that value is not in the media_items.type ENUM at all).
        $this->assertSame('artist', $this->mediaItemType($healedArtist));
        $this->assertSame('album', $this->mediaItemType($healedAlbum));

        // Healed IN PLACE: the same rows, not replacements, so anything already
        // referencing them (music_tracks.artist_id/album_id) still resolves.
        $this->assertSame(1, $this->countScalar('SELECT COUNT(*) AS c FROM music_artists WHERE name = ?', [
            $artistName,
        ]));
        $this->assertSame(1, $this->countScalar(
            'SELECT COUNT(*) AS c FROM music_albums WHERE artist_id = ? AND title = ?',
            [$artistId, $albumTitle],
        ));
        $this->assertSame(1, $this->countMediaItems('artist'), 'exactly one artist media_items row exists');
        $this->assertSame(1, $this->countMediaItems('album'));
    }

    /**
     * The backfill ADOPTS an orphaned `media_items` row rather than minting a rival.
     *
     * This is case (c) of `findAdoptableArtistMediaItemId()`'s residue list: a mint the
     * server COMMITTED but `createMediaItem()` reported as failed. It was unreclaimable
     * BY CONSTRUCTION — the `music_artists` row holds the natural key with a NULL link, so
     * every later scan short-circuits before the adoption lookup. Driving that lookup from
     * INSIDE the natural-key branch is the only route to it, and the alternative (minting
     * a fresh id) would leave `media_items[artist] = 2` against `music_artists = 1`, which
     * is what the counts BY TYPE on the music read path and the stats maps report.
     */
    public function testTheBackfillAdoptsACommittedOrphanInsteadOfMintingASecondRow(): void
    {
        $artistName = $this->prefix . 'Committed Orphan';
        $albumTitle = 'Orphan Album';
        $this->buildAlbum($artistName, $albumTitle, 1);

        // The committed-but-unreferenced row, exactly as the scanner writes one:
        // type 'artist', empty path, this library.
        $orphanId = Uuid::v4();
        $this->db?->query(
            "INSERT INTO media_items (id, library_id, type, name, path, metadata_json, created_at, updated_at)
             VALUES (?, ?, 'artist', ?, '', ?, NOW(), NOW())",
            [$orphanId, $this->libraryId, $artistName, json_encode(['sub_type' => 'artist', 'name' => $artistName])],
        );
        [$artistId] = $this->plantNullLinkedRows($artistName, $albumTitle, false);

        $this->scanner($artistName, $albumTitle)->scanDirectory($this->root, null, $this->libraryId);

        $this->assertSame(
            $orphanId,
            $this->artistMediaItemId($artistId),
            'the orphan must be adopted by the row that should have owned it all along',
        );
        $this->assertSame(
            1,
            $this->countMediaItems('artist'),
            'and NO second artist media_items row may be minted — that leak is what the adoption '
            . 'lookup exists to prevent, and it is permanent once the natural key short-circuits',
        );
    }

    /**
     * A healthy library must pay nothing: with no NULL link anywhere, a rescan issues no
     * backfill UPDATE and changes no `media_item_id`.
     *
     * The guard matters because the adoption lookups the backfill calls scan an unindexed
     * `media_items.name` — measured at 5.078 ms per artist, ≈31 s over a first scan of the
     * production library, and quadratic in albums — which is exactly why S95 gated them
     * behind one query per scan. A backfill that ran unconditionally would hand that back.
     */
    public function testASecondScanOfAHealthyLibraryChangesNothing(): void
    {
        $artistName = $this->prefix . 'Healthy Artist';
        $albumTitle = 'Healthy Album';
        $this->buildAlbum($artistName, $albumTitle, 2);

        $scanner = $this->scanner($artistName, $albumTitle);
        $scanner->scanDirectory($this->root, null, $this->libraryId);

        $artistId = $this->countScalar('SELECT id AS c FROM music_artists WHERE name = ?', [$artistName]);
        $this->assertGreaterThan(0, $artistId);
        $firstPass = $this->artistMediaItemId($artistId);
        $this->assertIsString($firstPass, 'a clean first scan links the artist immediately');

        $second = $scanner->scanDirectory($this->root, null, $this->libraryId);

        $this->assertSame(0, $second->added, 'nothing new on disk');
        $this->assertSame(0, $second->failed);
        $this->assertSame($firstPass, $this->artistMediaItemId($artistId), 'the link is untouched');
        $this->assertSame(1, $this->countMediaItems('artist'));
        $this->assertSame(1, $this->countMediaItems('album'));
    }

    // -- fixtures --------------------------------------------------------------

    /** Builds one album directory of `$files` audio files. */
    private function buildAlbum(string $artistName, string $albumTitle, int $files): void
    {
        unset($artistName, $albumTitle);

        $this->root = sys_get_temp_dir() . '/phlix_s96_it_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        $this->cleanup[] = $this->root;

        for ($t = 1; $t <= $files; $t++) {
            $path = $this->root . sprintf('/%02d-track.mp3', $t);
            file_put_contents($path, 'not-real-audio');
            $this->cleanup[] = $path;
        }
    }

    /**
     * Writes the `music_artists` (and optionally `music_albums`) row a failed mint leaves
     * behind: present, correct on its natural key, `media_item_id` NULL.
     *
     * @return array{0: int, 1: int} `[artistId, albumId]` (albumId 0 when not planted).
     */
    private function plantNullLinkedRows(string $artistName, string $albumTitle, bool $withAlbum = true): array
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $db->query(
            'INSERT INTO music_artists (name, sort_name, media_item_id) VALUES (?, ?, NULL)',
            [$artistName, $artistName],
        );
        $artistId = (int) $db->lastInsertId();
        $this->assertGreaterThan(0, $artistId);

        $albumId = 0;
        if ($withAlbum) {
            $db->query(
                'INSERT INTO music_albums (artist_id, media_item_id, title, sort_title, year)
                 VALUES (?, NULL, ?, ?, NULL)',
                [$artistId, $albumTitle, $albumTitle],
            );
            $albumId = (int) $db->lastInsertId();
            $this->assertGreaterThan(0, $albumId);
        }

        return [$artistId, $albumId];
    }

    /**
     * A scanner against the REAL database whose tag reader is a pure function of the
     * path, so a synthetic tree of empty files stands in for a tagged music library.
     */
    private function scanner(string $artistName, string $albumTitle): MusicLibraryScanner
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $scanner = new BackfillTaggedScanner($db, $this->createMock(FfmpegRunner::class));
        $scanner->tagger = static fn(string $path): array => [
            'artist' => $artistName,
            'album' => $albumTitle,
            'title' => basename($path, '.mp3'),
            'track_number' => (int) substr(basename($path), 0, 2),
            'disc_number' => 1,
            'duration_secs' => 123,
            'year' => 2004,
            'genre' => null,
        ];

        return $scanner;
    }

    // -- assertion helpers -----------------------------------------------------

    private function artistMediaItemId(int $artistId): ?string
    {
        return $this->nullableScalar('SELECT media_item_id AS c FROM music_artists WHERE id = ?', [$artistId]);
    }

    private function albumMediaItemId(int $albumId): ?string
    {
        return $this->nullableScalar('SELECT media_item_id AS c FROM music_albums WHERE id = ?', [$albumId]);
    }

    private function mediaItemType(string $mediaItemId): ?string
    {
        return $this->nullableScalar('SELECT type AS c FROM media_items WHERE id = ?', [$mediaItemId]);
    }

    private function countMediaItems(string $type): int
    {
        return $this->countScalar(
            'SELECT COUNT(*) AS c FROM media_items WHERE library_id = ? AND type = ?',
            [$this->libraryId, $type],
        );
    }

    /**
     * @param array<int, mixed> $params
     */
    private function nullableScalar(string $sql, array $params): ?string
    {
        $rows = $this->db?->query($sql, $params);
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }

        $value = $rows[0]['c'] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param array<int, mixed> $params
     */
    private function countScalar(string $sql, array $params): int
    {
        return (int) ($this->nullableScalar($sql, $params) ?? '0');
    }

    private function purgeFixtures(): void
    {
        $db = $this->db;
        if ($db === null) {
            return;
        }

        // music_albums/music_tracks cascade off music_artists (migration 065).
        $db->query('DELETE FROM music_artists WHERE name LIKE ?', [$this->prefix . '%']);
        if ($this->libraryId !== '') {
            $db->query('DELETE FROM media_items WHERE library_id = ?', [$this->libraryId]);
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

/**
 * Scanner subclass whose tag reader is injected, so a synthetic tree of empty files can
 * stand in for a tagged music library.
 */
final class BackfillTaggedScanner extends MusicLibraryScanner
{
    /** @var \Closure(string): array<string, mixed> Path → canonical metadata. */
    public \Closure $tagger;

    /**
     * @param string $path Absolute filesystem path.
     * @return array<string, mixed>|null
     */
    protected function probeViaGetId3(string $path): ?array
    {
        return ($this->tagger)($path);
    }
}
