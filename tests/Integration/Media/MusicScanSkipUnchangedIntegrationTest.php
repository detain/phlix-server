<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Common\Uuid;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Music\MusicScanSkipIndex;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Real-DB proof for the S122(a) unchanged-file fast path.
 *
 * ## Why a real database is REQUIRED here and not merely nice
 *
 * Three of the load-bearing pieces of S122(a) are MySQL SEMANTICS that an in-memory
 * double cannot express, and each one fails in a way that is invisible without a
 * server:
 *
 *  1. **`JSON_SET(COALESCE(metadata_json, JSON_OBJECT()), …)`.** `JSON_SET(NULL, …)`
 *     returns **NULL** in MySQL. Without the `COALESCE`, stamping a row whose
 *     `metadata_json` is NULL does not add two keys — it ERASES the document, taking
 *     `sub_type` and `name` with it, and then the row can never be skipped either. A
 *     PHP double that merges into an array cannot reproduce that at all.
 *     {@see self::testStampingARowWithNoMetadataDocumentDoesNotEraseIt()}
 *  2. **`metadata_json->>'$.file_mtime'` extraction.** The identity map reads the
 *     stamp back out with the JSON path operator against a real `JSON` column; a
 *     double hands back whatever the test put in.
 *  3. **The heal gate's `SELECT … UNION ALL SELECT … LIMIT 1`.** That statement has to
 *     PARSE, has to be classified as a `select` by `Connection::query()`'s
 *     leading-keyword dispatch (so it returns a row list rather than `null`), and has
 *     to answer correctly. A double that string-matches the SQL proves none of it.
 *
 * This is the same lesson the LiveTv `RowQuery`/`ResultSet` and metrics
 * `ONLY_FULL_GROUP_BY` regressions taught: mock-DB tests are exactly what hides this
 * class of defect.
 *
 * ## The fixture is a REAL MP3 and the tag reader is the REAL getID3
 *
 * `tests/Fixtures/Media/Music/tagged-short.mp3` is copied into the tree, so the whole
 * chain runs unmocked: getID3 reads real ID3v2 frames, the merged comments view is
 * built, and the file is never opened at all on a rescan. The probe counter is the
 * assertion surface — the step is "do not open the file", so counting opens is the
 * only thing worth asserting.
 *
 * Self-skips with no reachable MySQL; runs for real in CI.
 *
 */
final class MusicScanSkipUnchangedIntegrationTest extends TestCase
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

        $this->db = $this->requireRealDatabase('skipping the S122 real-DB skip test. Runs in CI.');

        self::assertNotNull($this->db);

        $this->libraryId = Uuid::v4();
        $this->prefix = '!S122-' . substr(Uuid::v4(), 0, 8) . '-';

        $this->db->query(
            "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'music', '[]')",
            [$this->libraryId, 'S122 Music IT Library'],
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
     * AC 1 + AC 5 — against a real database and a real tag reader: the first scan
     * indexes and STAMPS every file, and the rescan opens none of them.
     */
    public function testARescanOfAnUnchangedLibraryOpensNoFiles(): void
    {
        $this->buildTree(3);
        $scanner = $this->scanner();

        $first = $scanner->scanDirectory($this->root, null, $this->libraryId);
        self::assertSame(3, $first->added, 'the first scan must index all three files');
        self::assertSame(0, $first->failed);
        self::assertSame(3, $scanner->probeCount);
        self::assertSame(3, $this->countStamped(), 'and it must record a real (mtime, size) for each');

        $scanner->resetProbes();
        $second = $scanner->scanDirectory($this->root, null, $this->libraryId);

        self::assertSame(
            0,
            $scanner->probeCount,
            'a rescan of an unchanged library must not open a single file — this is the 6.1 h -> minutes claim'
        );
        self::assertSame(3, $second->scanned, 'the files are still visited, just not opened');
        self::assertSame(0, $second->added);
        self::assertSame(0, $second->updated);
        self::assertSame(0, $second->failed);
        self::assertSame(3, $this->countTracks(), 'and nothing is lost or duplicated');
    }

    /**
     * AC 2 against a real database: a touched file is re-read, and only it.
     */
    public function testOnlyTheTouchedFileIsReReadOnARescan(): void
    {
        $paths = $this->buildTree(3);
        $scanner = $this->scanner();
        $scanner->scanDirectory($this->root, null, $this->libraryId);

        touch($paths[1], time() + 120);
        clearstatcache();

        $scanner->resetProbes();
        $scanner->scanDirectory($this->root, null, $this->libraryId);

        self::assertSame([$paths[1]], $scanner->probedPaths);
        self::assertSame(3, $this->countTracks());
    }

    /**
     * ⚠ THE `COALESCE` IN THE STAMP `UPDATE`, WHICH ONLY A REAL DATABASE CAN PROVE.
     *
     * `JSON_SET(NULL, '$.file_mtime', …)` returns **NULL** in MySQL, so stamping a row
     * whose `metadata_json` is NULL would, without the `COALESCE(…, JSON_OBJECT())`,
     * write NULL over the whole document — destroying `sub_type` and `name` and leaving
     * a row that can never be skipped afterwards either. This test nulls the column,
     * rescans, and requires that the row comes back with a readable stamp.
     */
    public function testStampingARowWithNoMetadataDocumentDoesNotEraseIt(): void
    {
        $paths = $this->buildTree(2);
        $scanner = $this->scanner();
        $scanner->scanDirectory($this->root, null, $this->libraryId);

        $db = $this->db;
        self::assertNotNull($db);

        // A row written by some other code path, with no JSON document at all.
        $db->query(
            'UPDATE media_items SET metadata_json = NULL WHERE library_id = ? AND path = ?',
            [$this->libraryId, $paths[0]],
        );
        self::assertSame(1, $this->countStamped(), 'the fixture must actually leave one row unstamped');

        // The unstamped file is probed (nothing to compare against) and re-stamped.
        $scanner->resetProbes();
        $scanner->scanDirectory($this->root, null, $this->libraryId);
        self::assertSame([$paths[0]], $scanner->probedPaths);

        $rows = $db->query(
            "SELECT metadata_json->>'$." . MusicScanSkipIndex::KEY_MTIME . "' AS file_mtime,"
            . " metadata_json->>'$." . MusicScanSkipIndex::KEY_SIZE . "' AS file_size,"
            . " metadata_json->>'$.sub_type' AS sub_type"
            . ' FROM media_items WHERE library_id = ? AND path = ?',
            [$this->libraryId, $paths[0]],
        );
        self::assertIsArray($rows);
        self::assertCount(1, $rows);
        self::assertIsArray($rows[0]);
        self::assertIsNumeric(
            $rows[0]['file_mtime'] ?? null,
            'JSON_SET(NULL, …) returns NULL in MySQL. A NULL here means the COALESCE was removed and '
            . 'the stamp UPDATE is now ERASING the document instead of adding two keys.'
        );
        self::assertIsNumeric($rows[0]['file_size'] ?? null);

        // And the third scan can skip it, which is only true if the document is readable.
        $scanner->resetProbes();
        $scanner->scanDirectory($this->root, null, $this->libraryId);
        self::assertSame(0, $scanner->probeCount, 'the re-stamped row must be skippable');
    }

    /**
     * ⚠ THE HEAL GATE'S `UNION ALL` STATEMENT, EXECUTED FOR REAL.
     *
     * It has to parse, be classified as a `select` by the driver's leading-keyword
     * dispatch (so it returns a row list and not `null`), and answer correctly. Then the
     * S96(e) backfill must actually run — which it can only do if the fast path stood
     * down and the album was flushed.
     */
    public function testAnUnhealedMusicRowDisablesTheFastPathAndIsThenBackfilled(): void
    {
        $this->buildTree(3);
        $scanner = $this->scanner();
        $scanner->scanDirectory($this->root, null, $this->libraryId);

        $db = $this->db;
        self::assertNotNull($db);

        // Reproduce S96(e): the artist's media_item mint failed, so the row carries NULL
        // and there is no `media_items` artist row to adopt.
        $artists = $db->query('SELECT id, media_item_id FROM music_artists WHERE name LIKE ?', [$this->prefix . '%']);
        self::assertIsArray($artists);
        self::assertCount(1, $artists);
        self::assertIsArray($artists[0]);
        $artistId = (int) ($artists[0]['id'] ?? 0);
        $orphanId = (string) ($artists[0]['media_item_id'] ?? '');
        self::assertNotSame('', $orphanId);

        $db->query('UPDATE music_artists SET media_item_id = NULL WHERE id = ?', [$artistId]);
        $db->query('DELETE FROM media_items WHERE id = ?', [$orphanId]);

        $scanner->resetProbes();
        $scanner->scanDirectory($this->root, null, $this->libraryId);

        self::assertSame(
            3,
            $scanner->probeCount,
            'while a music_* row is unhealed the fast path must stand down, or S96(e)\'s backfill — '
            . 'which only runs when an album is flushed — never happens again'
        );

        $healed = $db->query('SELECT media_item_id FROM music_artists WHERE id = ?', [$artistId]);
        self::assertIsArray($healed);
        self::assertIsArray($healed[0] ?? null);
        self::assertIsString($healed[0]['media_item_id'] ?? null, 'and the row must actually be healed');

        // With nothing left to heal, the fast path is available again.
        $scanner->resetProbes();
        $scanner->scanDirectory($this->root, null, $this->libraryId);
        self::assertSame(0, $scanner->probeCount, 'once healed, the library is fast again');
    }

    /**
     * A `media_items` row with no `music_tracks` sibling must not be skippable — the
     * `JOIN` in {@see MusicScanSkipIndex::load()}, proved against real SQL rather than
     * against a double that string-matches it.
     */
    public function testAStampedRowWithNoTrackRowIsStillProbed(): void
    {
        $paths = $this->buildTree(3);
        $scanner = $this->scanner();
        $scanner->scanDirectory($this->root, null, $this->libraryId);

        $db = $this->db;
        self::assertNotNull($db);

        $ids = $db->query('SELECT id FROM media_items WHERE library_id = ? AND path = ?', [
            $this->libraryId,
            $paths[2],
        ]);
        self::assertIsArray($ids);
        self::assertIsArray($ids[0] ?? null);
        $mediaItemId = (string) ($ids[0]['id'] ?? '');
        self::assertNotSame('', $mediaItemId);

        $db->query('DELETE FROM music_tracks WHERE media_item_id = ?', [$mediaItemId]);
        self::assertSame(2, $this->countTracks());

        $scanner->resetProbes();
        $scanner->scanDirectory($this->root, null, $this->libraryId);

        self::assertSame(
            [$paths[2]],
            $scanner->probedPaths,
            'the file with no track row must be re-read; skipping it would lose it permanently'
        );
        self::assertSame(3, $this->countTracks(), 'and the missing track row must be restored');
    }

    /**
     * Copies the real MP3 fixture into a fresh tree, once per track, and returns the
     * paths in the order the walk will visit them.
     *
     * The artist tag comes from the fixture, so the marker prefix is applied by the
     * scanner subclass instead — that is what makes `purgeFixtures()` able to clean up
     * without a `library_id` on `music_artists`.
     *
     * @param int $tracks Number of copies.
     * @return list<string> Walk-ordered absolute paths.
     */
    private function buildTree(int $tracks): array
    {
        $this->root = sys_get_temp_dir() . '/phlix_s122_it_' . bin2hex(random_bytes(6));
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

    private function scanner(): ProbeCountingIntegrationScanner
    {
        $db = $this->db;
        self::assertNotNull($db);

        $scanner = new ProbeCountingIntegrationScanner($db, new FfmpegRunner());
        $scanner->artistPrefix = $this->prefix;

        return $scanner;
    }

    /** How many `media_items` rows of this library carry a usable stamp. */
    private function countStamped(): int
    {
        $db = $this->db;
        if ($db === null) {
            return 0;
        }

        $rows = $db->query(
            "SELECT COUNT(*) AS n FROM media_items"
            . " WHERE library_id = ? AND type = 'track'"
            . " AND metadata_json->>'$." . MusicScanSkipIndex::KEY_MTIME . "' IS NOT NULL"
            . " AND metadata_json->>'$." . MusicScanSkipIndex::KEY_SIZE . "' IS NOT NULL",
            [$this->libraryId],
        );

        return is_array($rows) && is_array($rows[0] ?? null) ? (int) ($rows[0]['n'] ?? 0) : 0;
    }

    private function countTracks(): int
    {
        $db = $this->db;
        if ($db === null) {
            return 0;
        }

        $rows = $db->query(
            'SELECT COUNT(*) AS n FROM music_tracks t'
            . ' JOIN music_artists a ON a.id = t.artist_id WHERE a.name LIKE ?',
            [$this->prefix . '%'],
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
