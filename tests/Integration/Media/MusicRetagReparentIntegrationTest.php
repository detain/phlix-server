<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Common\Uuid;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S145 acceptance — a retagged track is re-filed under the album and artist its tags
 * actually name, and the album it left stops counting it.
 *
 * ## Why a real database, and why a real tag write
 *
 * The claim under test is **which columns a specific `UPDATE` writes**. Until S145 the
 * statement read
 *
 * ```sql
 * UPDATE music_tracks SET title = ?, track_number = ?, disc_number = ?, duration_secs = ?
 *  WHERE id = ?
 * ```
 *
 * — no `album_id`, no `artist_id` — and the change predicate that decides whether to
 * run it compared the same four fields, so it could not even *detect* mis-parentage.
 * A repo-wide grep finds exactly three statements that write `music_tracks`, all in
 * `MusicLibraryScanner`, and the other two are INSERTs. So a track could not move,
 * ever. An in-memory double is precisely the thing that cannot prove a column list:
 * it string-matches the SQL and mutates whatever the double's author decided to
 * mutate. Only a server can answer "did that column change".
 *
 * The retag is a **genuine ID3v2 write** through `getid3_writetags` (shipped by
 * `james-heinrich/getid3` in `getid3/write.php`) against a copy of
 * `tests/Fixtures/Media/Music/tagged-short.mp3`, not a stubbed probe. That matters for
 * two independent reasons:
 *
 *  1. it changes the file's mtime **and** size exactly as a real retag does, which is
 *     what makes an ordinary scan re-read the file at all (S122(a));
 *  2. it leaves `TITLE`, `TRACK`, `part_of_a_set` and the audio stream untouched, so
 *     `title` / `track_number` / `disc_number` / `duration_secs` are byte-for-byte
 *     identical afterwards — the exact input class the old four-field predicate waved
 *     through as `'skipped'`.
 *
 * ## What fails at `5c0fdead`
 *
 * Case 1's `album_id`, the new album's `total_tracks` and the old album's
 * `total_tracks`; case 2's `artist_id` as well. The shell album itself is created
 * either way — that is the production fingerprint (310 zero-track albums measured on
 * 2026-07-27, and 100 % of albums created on 07-26 / 97.8 % on 07-27 were shells), so
 * asserting only "a new album row exists" would pass against the defect.
 *
 * Self-skips with no reachable MySQL; runs for real in CI.
 *
 */
final class MusicRetagReparentIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    private ?Connection $db = null;

    private string $libraryId = '';

    /** Marker prefix so the fixtures can be purged without touching anything else. */
    private string $prefix = '';

    private string $root = '';

    /** @var list<string> Paths to remove, in creation order. */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping the S145 real-DB retag test. Runs in CI.');

        self::assertNotNull($this->db);

        $this->libraryId = Uuid::v4();
        $this->prefix = '!S145-' . substr(Uuid::v4(), 0, 8) . '-';

        $this->db->query(
            "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'music', '[]')",
            [$this->libraryId, 'S145 Music IT Library'],
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
     * CASE 1 — the ALBUM tag changes and nothing else does.
     *
     * The four fields the old predicate compared are provably identical across the
     * retag (asserted below, so a fixture that accidentally changed the duration
     * cannot make this test pass for the wrong reason), which is what makes this the
     * exact production shape rather than an approximation of it.
     */
    public function testRetaggingTheAlbumMovesTheTrackToTheNewAlbum(): void
    {
        $paths = $this->buildTree(3);
        $scanner = $this->scanner();

        $first = $scanner->scanDirectory($this->root, null, $this->libraryId);
        self::assertSame(3, $first->added, 'the first scan must index all three files');
        self::assertSame(0, $first->failed);

        $before = $this->trackRow($paths[0]);
        $oldAlbumId = $before['album_id'];
        $artistId = $before['artist_id'];
        self::assertSame(3, $this->albumTotal($oldAlbumId), 'all three tracks start on one album');

        $this->retag($paths[0], album: 'Retagged Album');

        $second = $scanner->scanDirectory($this->root, null, $this->libraryId);

        $after = $this->trackRow($paths[0]);

        // The four fields the pre-S145 predicate looked at did NOT move. If any of
        // these fail the fixture is not reproducing the defect, and every assertion
        // below would be testing something else.
        self::assertSame($before['title'], $after['title'], 'the retag must not change the title');
        self::assertSame($before['track_number'], $after['track_number']);
        self::assertSame($before['disc_number'], $after['disc_number']);
        self::assertSame($before['duration_secs'], $after['duration_secs']);

        $newAlbumId = $this->albumIdByTitle($this->prefix . 'Retagged Album');
        self::assertNotSame(
            0,
            $newAlbumId,
            'the scanner has always created the new album row — that row IS the production fingerprint '
            . '(310 zero-track albums), so this assertion passes against the defect too'
        );
        self::assertNotSame($oldAlbumId, $newAlbumId);

        self::assertSame(
            $newAlbumId,
            $after['album_id'],
            'RED at 5c0fdead: the UPDATE named only title/track/disc/duration, so nothing in the '
            . 'codebase could move music_tracks.album_id and the track stayed on the old album forever'
        );
        self::assertSame($artistId, $after['artist_id'], 'the ARTIST tag did not change, so the artist must not');

        self::assertSame(
            1,
            $this->albumTotal($newAlbumId),
            'RED at 5c0fdead: the new album was minted and then left owning nothing, which is exactly '
            . 'the zero-track shell measured 310 times on production'
        );
        self::assertSame(
            2,
            $this->albumTotal($oldAlbumId),
            'RED without the vacated-album refresh: flushAlbum()\'s finally only recounts the album it '
            . 'is flushing, so the album the track LEFT would keep advertising 3 — and '
            . 'MusicLibraryService sums that column onto the artist page'
        );

        self::assertSame(1, $second->updated, 'exactly the retagged track was rewritten');
        self::assertSame(0, $second->failed);
        self::assertSame(0, $second->added, 'a retag is not a new file');
        self::assertSame(3, $this->countTracks(), 'and nothing is duplicated');
    }

    /**
     * CASE 2 — the ARTIST tag changes: BOTH ids must move.
     *
     * This is the half a fix that widened only `album_id` would still get wrong, and
     * it is also the case the step spec's original detector was blind to: when the
     * artist changes, the track keeps the old album id AND the old artist id, and the
     * old album's artist IS the old artist — so `t.artist_id <> a.artist_id` still
     * finds nothing. (Measured on production: 0 rows, permanently.)
     */
    public function testRetaggingTheArtistMovesBothTheArtistAndTheAlbum(): void
    {
        $paths = $this->buildTree(2);
        $scanner = $this->scanner();
        $scanner->scanDirectory($this->root, null, $this->libraryId);

        $before = $this->trackRow($paths[0]);
        $oldAlbumId = $before['album_id'];
        $oldArtistId = $before['artist_id'];
        self::assertSame(2, $this->albumTotal($oldAlbumId));

        $this->retag($paths[0], artist: 'Retagged Artist');

        $result = $scanner->scanDirectory($this->root, null, $this->libraryId);

        $after = $this->trackRow($paths[0]);

        self::assertNotSame(
            $oldArtistId,
            $after['artist_id'],
            'RED at 5c0fdead: artist_id is not in the UPDATE either'
        );
        self::assertNotSame($oldAlbumId, $after['album_id'], 'a new artist means a new (artist_id, title) album');
        self::assertSame(
            $this->prefix . 'Retagged Artist',
            $this->artistName($after['artist_id']),
            'and it must be the artist the tags actually name'
        );
        self::assertSame(
            $after['artist_id'],
            $this->albumArtistId($after['album_id']),
            'the track and its album must agree about the artist — the invariant that makes the '
            . 'spec\'s original detector vacuous, and which the fix must not break'
        );

        self::assertSame(1, $this->albumTotal($after['album_id']));
        self::assertSame(
            1,
            $this->albumTotal($oldAlbumId),
            'the vacated album must be recounted, not left at 2'
        );
        self::assertSame(1, $result->updated);
        self::assertSame(0, $result->failed);
        self::assertSame(2, $this->countTracks());
    }

    /**
     * The steady-state guard, against a REAL database — and it has to be here rather
     * than beside its unit siblings.
     *
     * A full read of a clean library must rewrite NOTHING. The way to break that is to
     * compare a column the `SELECT` does not fetch: the coercion reads the absent key
     * as `0`, `0 !== $albumId` for every row, and every file in the library turns into
     * an `UPDATE` on every scan. The in-memory double **cannot** catch it — its
     * `runSelect()` hands back the stored row wholesale and ignores the statement's
     * column list entirely, so it answers with columns production never asked for.
     * Only a server distinguishes "selected" from "not selected".
     *
     * (Measured: reverting the widened `SELECT` alone leaves the unit steady-state test
     * green and turns this file red.)
     */
    public function testAFullReadOfAnUnchangedLibraryRewritesNothing(): void
    {
        $this->buildTree(3);
        $scanner = $this->scanner();

        $first = $scanner->scanDirectory($this->root, null, $this->libraryId);
        self::assertSame(3, $first->added);

        $scanner->resetProbes();
        $second = $scanner->scanDirectory($this->root, null, $this->libraryId, true);

        self::assertSame(3, $scanner->probeCount, 'the full-read mode must open every file');
        self::assertSame(
            0,
            $second->updated,
            'but a correct row must not be rewritten. A non-zero count here means the widened change '
            . 'predicate is comparing something the SELECT does not fetch, which would rewrite all '
            . '61,111 production rows on every healing scan'
        );
        self::assertSame(0, $second->added);
        self::assertSame(0, $second->failed);
        self::assertSame(3, $this->countTracks());
    }

    /**
     * Rewrites the ID3v2 tag of `$path` in place, changing only what is asked for.
     *
     * ⚠ Every other frame is written back verbatim, so `title`, `track_number` and
     * `part_of_a_set` survive and the pre-S145 four-field predicate genuinely sees no
     * change. Writing the tag grows the file and moves its mtime, exactly as a real
     * retag does — which is what makes the file re-readable by an ordinary scan; this
     * test does not stub the skip index.
     *
     * @param string      $path   Absolute path to the MP3 to rewrite.
     * @param string|null $album  New ALBUM value, or NULL to keep the existing one.
     * @param string|null $artist New ARTIST value, or NULL to keep the existing one.
     * @return void
     */
    private function retag(string $path, ?string $album = null, ?string $artist = null): void
    {
        // Instantiating getID3 first is REQUIRED: `write.php` throws
        // "getid3.php MUST be included before calling getid3_writetags" when the
        // writer class is autoloaded on its own.
        $reader = new \getID3();
        $info = $reader->analyze($path);
        $tags = is_array($info['tags']['id3v2'] ?? null) ? $info['tags']['id3v2'] : [];

        $sizeBefore = filesize($path);

        $writer = new \getid3_writetags();
        $writer->filename = $path;
        $writer->tagformats = ['id3v2.3'];
        $writer->overwrite_tags = true;
        $writer->remove_other_tags = false;
        $writer->tag_encoding = 'UTF-8';

        $data = [];
        foreach (['artist', 'album', 'title', 'track_number', 'part_of_a_set', 'year', 'genre'] as $frame) {
            if (isset($tags[$frame]) && is_array($tags[$frame])) {
                $data[$frame] = $tags[$frame];
            }
        }
        if ($album !== null) {
            $data['album'] = [$album];
        }
        if ($artist !== null) {
            $data['artist'] = [$artist];
        }
        $writer->tag_data = $data;

        self::assertTrue($writer->WriteTags(), 'the tag write must succeed: ' . implode('; ', $writer->errors));

        clearstatcache(true, $path);
        self::assertNotSame(
            $sizeBefore,
            filesize($path),
            'a real retag changes the file\'s identity; if it did not, S122(a) would skip it and this '
            . 'test would be proving nothing'
        );
    }

    /**
     * The `music_tracks` row for a file, as the API reads it.
     *
     * @param string $path Absolute file path.
     * @return array{album_id:int, artist_id:int, title:string, track_number:int,
     *     disc_number:int, duration_secs:int}
     */
    private function trackRow(string $path): array
    {
        $db = $this->db;
        self::assertNotNull($db);

        $rows = $db->query(
            'SELECT t.album_id, t.artist_id, t.title, t.track_number, t.disc_number, t.duration_secs'
            . ' FROM music_tracks t JOIN media_items mi ON mi.id = t.media_item_id'
            . ' WHERE mi.library_id = ? AND mi.path = ?',
            [$this->libraryId, $path],
        );
        self::assertIsArray($rows);
        self::assertCount(1, $rows, 'exactly one music_tracks row for ' . $path);
        self::assertIsArray($rows[0]);

        return [
            'album_id' => (int) ($rows[0]['album_id'] ?? 0),
            'artist_id' => (int) ($rows[0]['artist_id'] ?? 0),
            'title' => (string) ($rows[0]['title'] ?? ''),
            'track_number' => (int) ($rows[0]['track_number'] ?? 0),
            'disc_number' => (int) ($rows[0]['disc_number'] ?? 0),
            'duration_secs' => (int) ($rows[0]['duration_secs'] ?? 0),
        ];
    }

    /** `music_albums.total_tracks` as persisted. */
    private function albumTotal(int $albumId): int
    {
        return (int) $this->scalar('SELECT total_tracks AS v FROM music_albums WHERE id = ?', [$albumId]);
    }

    /** `music_albums.artist_id` as persisted. */
    private function albumArtistId(int $albumId): int
    {
        return (int) $this->scalar('SELECT artist_id AS v FROM music_albums WHERE id = ?', [$albumId]);
    }

    /** `music_artists.name` as persisted. */
    private function artistName(int $artistId): string
    {
        return (string) $this->scalar('SELECT name AS v FROM music_artists WHERE id = ?', [$artistId]);
    }

    /** The id of this fixture's album with the given title, or 0. */
    private function albumIdByTitle(string $title): int
    {
        return (int) $this->scalar(
            'SELECT a.id AS v FROM music_albums a JOIN music_artists ar ON ar.id = a.artist_id'
            . ' WHERE a.title = ? AND ar.name LIKE ?',
            [$title, $this->prefix . '%'],
        );
    }

    /**
     * @param string $sql    Statement selecting a single column aliased `v`.
     * @param array<int, mixed> $params Bound parameters.
     * @return mixed The first row's `v`, or 0 when there is no row.
     */
    private function scalar(string $sql, array $params): mixed
    {
        $db = $this->db;
        self::assertNotNull($db);

        $rows = $db->query($sql, $params);

        return is_array($rows) && is_array($rows[0] ?? null) ? ($rows[0]['v'] ?? 0) : 0;
    }

    /**
     * Copies the real MP3 fixture into a fresh tree, once per track, and returns the
     * paths in the order the walk will visit them.
     *
     * @param int $tracks Number of copies.
     * @return list<string> Walk-ordered absolute paths.
     */
    private function buildTree(int $tracks): array
    {
        $this->root = sys_get_temp_dir() . '/phlix_s145_it_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
        $this->cleanup[] = $this->root;

        $fixture = dirname(__DIR__, 2) . '/Fixtures/Media/Music/tagged-short.mp3';
        self::assertFileExists($fixture);

        for ($i = 1; $i <= $tracks; $i++) {
            $path = $this->root . '/track-' . $i . '.mp3';
            copy($fixture, $path);
            $this->cleanup[] = $path;
        }
        clearstatcache();

        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'mp3') {
                $out[] = $file->getPathname();
            }
        }
        self::assertCount($tracks, $out);

        return $out;
    }

    /**
     * The shared probe-counting scanner. Its `probeViaGetId3()` delegates to the real
     * getID3, so the ALBUM/ARTIST frames this test writes are the ones the scanner
     * reads; it only prefixes artist and album so the fixtures stay purgeable, and
     * fixes `title` to the basename so three copies of one fixture are three tracks.
     */
    private function scanner(): ProbeCountingIntegrationScanner
    {
        $db = $this->db;
        self::assertNotNull($db);

        $scanner = new ProbeCountingIntegrationScanner($db, new FfmpegRunner());
        $scanner->artistPrefix = $this->prefix;

        return $scanner;
    }

    private function countTracks(): int
    {
        $db = $this->db;
        if ($db === null) {
            return 0;
        }

        $rows = $db->query(
            'SELECT COUNT(*) AS n FROM music_tracks t'
            . ' JOIN media_items mi ON mi.id = t.media_item_id WHERE mi.library_id = ?',
            [$this->libraryId],
        );

        return is_array($rows) && is_array($rows[0] ?? null) ? (int) ($rows[0]['n'] ?? 0) : 0;
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
}
