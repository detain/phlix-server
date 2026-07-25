<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Uuid;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Transcoding\FfmpegRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Real-DB proof that a music scan writes CONTINUOUSLY and survives being killed
 * mid-walk (S95 · live-investigation).
 *
 * The shipped scanner was two-phase: `groupFilesByAlbum()` walked and tag-probed
 * EVERY audio file under a path into one in-memory map, and only then did the
 * upsert loop run. Nothing at all was persisted until the walk finished. Measured
 * on production, from `library_scan_jobs`: the rescan queued 2026-07-24 19:18:08
 * committed its first row at 23:27:11 — **4 h 09 m of zero durable work** — then
 * wrote for 71 minutes and died at 00:38:21 with
 * `error: Interrupted by server restart` at 29,245 of 61,135 files. The three
 * earlier rescans (07-21, 07-22 x2) were each killed during the walk phase and
 * therefore persisted **nothing whatsoever**.
 *
 * A mocked `Connection` cannot prove the fix: the claim is about when rows become
 * durable and whether a second pass duplicates them, which is a property of real
 * INSERTs against real constraints (`music_artists.name` UNIQUE,
 * `music_tracks.media_item_id` UNIQUE, the FK cascades in migration 065). Mock-DB
 * tests are exactly what hid this class of defect before — see the LiveTv
 * RowQuery/ResultSet and metrics ONLY_FULL_GROUP_BY regressions. The buffer's
 * cadence and memory ceiling are additionally pinned without a database in
 * {@see \Phlix\Tests\Unit\Media\Music\MusicLibraryScannerTest}.
 *
 * CI applies all migrations to the `phlix_test` MySQL service before the suite;
 * locally, with no reachable MySQL, it self-skips.
 *
 * RUNTIME NOTE: this class issues roughly 2,300 autocommitted statements, so its
 * wall-clock is dominated by the server's per-commit fsync, not by PHP. Measured
 * on one box: 106 s with `innodb_flush_log_at_trx_commit=1`/`sync_binlog=1`, and
 * 2.3 s with both relaxed — a 45x spread on identical assertions. If it ever needs
 * to be cheaper, lower {@see self::ALBUMS}; the only constraint is that it must
 * stay comfortably above `MusicLibraryScanner::MAX_OPEN_ALBUMS` (32), or no album
 * is ever evicted and the mid-walk flush this class exists to prove never fires.
 *
 * @covers \Phlix\Media\Music\MusicLibraryScanner
 */
final class MusicScanIncrementalFlushIntegrationTest extends TestCase
{
    /** Albums in the synthetic tree. Comfortably over MAX_OPEN_ALBUMS (32). */
    private const ALBUMS = 60;

    /** Audio files per album directory. */
    private const TRACKS_PER_ALBUM = 2;

    /**
     * File at which {@see self::testInterruptedScanRetainsRowsAndRescanResumesWithoutDuplicating()}
     * simulates the worker restart. By then 50 albums have been opened, so
     * 50 − MAX_OPEN_ALBUMS(32) = 18 have been evicted and written.
     */
    private const INTERRUPT_AT = 100;

    /** Albums (and therefore artists) written before the simulated interruption. */
    private const FLUSHED_BEFORE_INTERRUPT = 18;

    private ?Connection $db = null;

    private string $libraryId = '';

    /**
     * Fixture-local namespace for `music_artists.name`, which is UNIQUE — every
     * run needs its own. Leading `!` collates first, matching the convention the
     * sibling music integration test settled on.
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
                sprintf('No MySQL on %s:%d — skipping music incremental-flush test. Runs in CI.', $host, $port),
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
        $this->prefix = '!S95-' . substr(Uuid::v4(), 0, 8) . '-';

        $this->db->query(
            "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'music', '[]')",
            [$this->libraryId, 'S95 Music IT Library'],
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
     * THE acceptance criterion: rows exist in `media_items` and the `music_*`
     * tables while the walk is still running.
     *
     * The progress sink polls the real database on every tick until it sees a row,
     * so the recorded tick IS the file at which work first became durable. With
     * one album per directory and two tracks each, the 33rd album opens at file 65
     * and overflows the 32-album window, flushing the least-recently-touched album.
     * The sink runs BEFORE its file is probed, so that flush is first *observable*
     * one tick later, at 66 — the sibling unit test watches the statement stream
     * instead and therefore sees it at 65.
     *
     * On the pre-S95 code this poll never succeeds — not once in 120 ticks — so
     * `$firstDurableTick` stays NULL and the first assertion fails.
     */
    public function testRowsBecomeDurableBeforeTheWalkCompletes(): void
    {
        $total = $this->buildAlbumTree();
        $scanner = $this->scanner();

        $firstDurableTick = null;
        $tracksAtFirstWrite = 0;

        $result = $scanner->scanDirectory(
            $this->root,
            function (int $processed) use (&$firstDurableTick, &$tracksAtFirstWrite): void {
                if ($firstDurableTick !== null) {
                    return;
                }
                if ($this->countArtists() > 0) {
                    $firstDurableTick = $processed;
                    $tracksAtFirstWrite = $this->countTracks();
                }
            },
            $this->libraryId,
        );

        $this->assertNotNull(
            $firstDurableTick,
            'No row was durable at ANY point during the walk. That is precisely the pre-S95 defect: '
            . 'the whole tree is tag-probed into memory before a single INSERT is issued, so a scan '
            . 'killed mid-walk loses everything.',
        );
        $this->assertLessThan(
            $total,
            $firstDurableTick,
            'the first durable row must land strictly before the final file',
        );
        $this->assertSame(
            66,
            $firstDurableTick,
            'the 33rd album overflows the window at file 65, first observable by the sink at tick 66',
        );
        $this->assertGreaterThan(0, $tracksAtFirstWrite, 'the flush writes tracks, not just an artist row');

        // The walk finished, so everything is now indexed exactly once.
        $this->assertSame(self::ALBUMS, $this->countArtists());
        $this->assertSame(self::ALBUMS, $this->countAlbums());
        $this->assertSame($total, $this->countTracks());
        $this->assertSame($total, $result->added);
        $this->assertSame($total, $result->scanned);
    }

    /**
     * Killing the scan mid-walk must retain everything already written, and the
     * next scan must resume — adding only what is missing and duplicating nothing.
     *
     * The interruption is modelled by throwing from the progress sink, which is
     * called outside any catch in `scanDirectory()`, so it unwinds the scan exactly
     * as a worker restart would: no further code runs, and whatever was already
     * committed stays committed (there is no enclosing transaction, on purpose).
     */
    public function testInterruptedScanRetainsRowsAndRescanResumesWithoutDuplicating(): void
    {
        $total = $this->buildAlbumTree();
        $scanner = $this->scanner();

        // -- pass 1: die part-way through the walk ---------------------------
        try {
            $scanner->scanDirectory(
                $this->root,
                static function (int $processed): void {
                    if ($processed >= self::INTERRUPT_AT) {
                        throw new RuntimeException('simulated worker restart');
                    }
                },
                $this->libraryId,
            );
            $this->fail('the simulated restart should have propagated out of scanDirectory()');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated worker restart', $e->getMessage());
        }

        $partialArtists = $this->countArtists();
        $partialTracks = $this->countTracks();

        // Retention: the interrupted pass kept real work. Pre-S95 this is 0.
        $this->assertGreaterThan(
            0,
            $partialTracks,
            'an interrupted scan must retain the rows it already wrote; 0 here is the pre-S95 defect',
        );
        $expectedPartialTracks = self::FLUSHED_BEFORE_INTERRUPT * self::TRACKS_PER_ALBUM;
        $this->assertSame(self::FLUSHED_BEFORE_INTERRUPT, $partialArtists);
        $this->assertSame($expectedPartialTracks, $partialTracks);

        // Snapshot the exact identities written, to prove the rescan REUSES them.
        $partialIds = $this->trackMediaItemIds();
        $this->assertCount($expectedPartialTracks, $partialIds);

        // -- pass 2: a full, uninterrupted rescan ----------------------------
        $result = $scanner->scanDirectory($this->root, null, $this->libraryId);

        // Resumed, not restarted: only the missing files were added.
        $this->assertSame(
            $total - $partialTracks,
            $result->added,
            'the rescan must add exactly the files the interrupted pass had not reached',
        );
        $this->assertSame(0, $result->updated, 'nothing changed on disk, so nothing should be updated');

        // No duplication anywhere.
        $this->assertSame(self::ALBUMS, $this->countArtists(), 'artists must not be duplicated');
        $this->assertSame(self::ALBUMS, $this->countAlbums(), 'albums must not be duplicated');
        $this->assertSame($total, $this->countTracks(), 'exactly one track row per audio file');
        $this->assertSame($total, $this->countTrackMediaItems(), 'exactly one media_items row per audio file');

        // Every id from the interrupted pass survived — the rescan reused the rows
        // rather than replacing them.
        $survivors = array_intersect($partialIds, $this->trackMediaItemIds());
        $this->assertCount(
            $expectedPartialTracks,
            $survivors,
            'every media_items row written before the interruption must still be the SAME row afterwards',
        );

        // -- pass 3: re-scanning already-indexed files is a no-op ------------
        // Scoped to one album directory on purpose: the property under test is
        // per-file ("an already-indexed file is neither re-added nor re-updated"),
        // and a third full walk would triple this test's commit-bound runtime for
        // no extra coverage.
        $noop = $scanner->scanDirectory($this->root . '/album-0000', null, $this->libraryId);
        $this->assertSame(0, $noop->added, 'an already-indexed file must not be re-added');
        $this->assertSame(0, $noop->updated, 'an unchanged file must not be re-updated');
        $this->assertSame(self::TRACKS_PER_ALBUM, $noop->scanned, 'it did re-read those files');
        $this->assertSame($total, $this->countTracks(), 'and wrote no new rows');
    }

    /**
     * `music_albums.total_tracks` is derived from the persisted rows after every
     * flush, so an album written in more than one batch still ends up exact.
     *
     * This also exercises the aliased correlated-subquery UPDATE against real
     * MySQL — a mock would happily accept SQL the server rejects.
     */
    public function testAlbumSplitAcrossDirectoriesIsOneAlbumWithAnExactTrackTotal(): void
    {
        $this->root = $this->tempRoot();
        foreach (['CD1', 'CD2'] as $disc) {
            $discDir = $this->root . '/' . $disc;
            mkdir($discDir, 0777, true);
            $this->cleanup[] = $discDir;
            for ($t = 1; $t <= 3; $t++) {
                $this->writeFile($discDir, sprintf('%02d-track.mp3', $t));
            }
        }

        $scanner = $this->scanner(static function (string $path): array {
            return [
                'artist' => 'SPLIT-ARTIST',
                'album' => 'SPLIT-ALBUM',
                'title' => basename($path, '.mp3'),
                'track_number' => (int) substr(basename($path), 0, 2),
                'disc_number' => str_contains($path, 'CD2') ? 2 : 1,
                'duration_secs' => 111,
                'year' => 1998,
                'genre' => null,
            ];
        });

        $result = $scanner->scanDirectory($this->root, null, $this->libraryId);

        $this->assertSame(6, $result->added);
        $this->assertSame(1, $this->countArtists(), 'one artist tag → one artist row');
        $this->assertSame(1, $this->countAlbums(), 'an album spread over two directories is still ONE album');
        $this->assertSame(6, $this->countTracks());

        $rows = $this->db?->query(
            "SELECT al.total_tracks
               FROM music_albums al
               JOIN music_artists ar ON ar.id = al.artist_id
              WHERE ar.name LIKE ?",
            [$this->prefix . '%'],
        );
        $this->assertIsArray($rows);
        $this->assertArrayHasKey(0, $rows);
        $this->assertIsArray($rows[0]);
        $this->assertSame(
            6,
            (int) $rows[0]['total_tracks'],
            'total_tracks must be recomputed from music_tracks, not left at a single batch size',
        );
    }

    // -- fixtures --------------------------------------------------------------

    /**
     * Builds ALBUMS directories of TRACKS_PER_ALBUM files each and returns the
     * total file count. No directory holds a subdirectory, so the walk yields each
     * album's files consecutively and the flush cadence is deterministic.
     */
    private function buildAlbumTree(): int
    {
        $this->root = $this->tempRoot();

        for ($a = 0; $a < self::ALBUMS; $a++) {
            $albumDir = $this->root . sprintf('/album-%04d', $a);
            mkdir($albumDir, 0777, true);
            $this->cleanup[] = $albumDir;
            for ($t = 1; $t <= self::TRACKS_PER_ALBUM; $t++) {
                $this->writeFile($albumDir, sprintf('%02d-track.mp3', $t));
            }
        }

        return self::ALBUMS * self::TRACKS_PER_ALBUM;
    }

    private function tempRoot(): string
    {
        $root = sys_get_temp_dir() . '/phlix_s95_it_' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        $this->cleanup[] = $root;

        return $root;
    }

    private function writeFile(string $dir, string $name): void
    {
        $path = $dir . '/' . $name;
        file_put_contents($path, 'not-real-audio');
        $this->cleanup[] = $path;
    }

    /**
     * A scanner against the REAL database whose tag reader is a pure function of
     * the path, so no getID3/ffprobe work is needed and every artist/album name is
     * namespaced to this run.
     */
    private function scanner(?\Closure $tagger = null): MusicLibraryScanner
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $prefix = $this->prefix;
        $scanner = new IntegrationTaggedScanner($db, $this->createMock(FfmpegRunner::class));
        $scanner->tagger = static function (string $path) use ($prefix, $tagger): array {
            $meta = $tagger !== null ? $tagger($path) : [
                'artist' => 'Artist ' . basename(dirname($path)),
                'album' => 'Album ' . basename(dirname($path)),
                'title' => basename($path, '.mp3'),
                'track_number' => (int) substr(basename($path), 0, 2),
                'disc_number' => 1,
                'duration_secs' => 222,
                'year' => 2003,
                'genre' => 'Rock',
            ];

            // Namespace the artist so this run's rows are isolatable; music_artists
            // .name is UNIQUE across the whole table.
            $meta['artist'] = $prefix . (is_string($meta['artist']) ? $meta['artist'] : 'x');

            return $meta;
        };

        return $scanner;
    }

    // -- assertions helpers ----------------------------------------------------

    private function countArtists(): int
    {
        return $this->scalar(
            "SELECT COUNT(*) AS c FROM music_artists WHERE name LIKE ?",
            [$this->prefix . '%'],
        );
    }

    private function countAlbums(): int
    {
        return $this->scalar(
            "SELECT COUNT(*) AS c
               FROM music_albums al
               JOIN music_artists ar ON ar.id = al.artist_id
              WHERE ar.name LIKE ?",
            [$this->prefix . '%'],
        );
    }

    private function countTracks(): int
    {
        return $this->scalar(
            "SELECT COUNT(*) AS c
               FROM music_tracks t
               JOIN music_artists ar ON ar.id = t.artist_id
              WHERE ar.name LIKE ?",
            [$this->prefix . '%'],
        );
    }

    private function countTrackMediaItems(): int
    {
        return $this->scalar(
            "SELECT COUNT(*) AS c FROM media_items WHERE library_id = ? AND type = 'track'",
            [$this->libraryId],
        );
    }

    /** @return list<string> */
    private function trackMediaItemIds(): array
    {
        $rows = $this->db?->query(
            "SELECT id FROM media_items WHERE library_id = ? AND type = 'track' ORDER BY id",
            [$this->libraryId],
        );

        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && is_string($row['id'] ?? null)) {
                    $out[] = $row['id'];
                }
            }
        }

        return $out;
    }

    /**
     * @param array<int, mixed> $params
     */
    private function scalar(string $sql, array $params): int
    {
        $rows = $this->db?->query($sql, $params);
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return 0;
        }

        return (int) ($rows[0]['c'] ?? 0);
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
 * Scanner subclass whose tag reader is injected, so a synthetic tree of empty
 * files can stand in for a tagged music library.
 */
final class IntegrationTaggedScanner extends MusicLibraryScanner
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
