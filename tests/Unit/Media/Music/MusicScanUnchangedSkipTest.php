<?php

/**
 * Phlix media server test: Media\Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Music\MusicScanSkipIndex;
use Phlix\Media\Transcoding\FfmpegRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S122(a) — the unchanged-file fast path, and every way it must NOT fire.
 *
 * A skip predicate is a cache, so this file is written the way a cache-invalidation
 * suite has to be: for each half of the predicate there is a test that the half
 * FIRES and a test that it does NOT, and for each gate that suppresses the fast
 * path there is a test that the suppression works. A green suite that only ever
 * proves "unchanged files are fast" would pass just as happily against a scanner
 * that skipped everything unconditionally.
 *
 * The scans here are driven end to end through {@see MusicLibraryScanner::scanDirectory()}
 * against {@see SkipSchemaConnection}, a purpose-built in-memory `media_items` +
 * `music_*` schema, and every assertion is on a PROBE COUNT — the number of times the
 * scanner actually opened a file to read tags. That is the only measure that matters:
 * the whole step is "do not open the file", and a test that asserted only on
 * `ScanResult` counters would stay green if the skip were moved to AFTER
 * `probeMetadata()`, which would delete 100 % of the benefit.
 *
 * @internal
 */
#[CoversClass(MusicLibraryScanner::class)]
#[CoversClass(MusicScanSkipIndex::class)]
final class MusicScanUnchangedSkipTest extends TestCase
{
    /** @var list<string> Temp directories to remove. */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    /**
     * AC 5, first half — an unchanged file IS skipped, and skipped means NOT OPENED.
     *
     * The second scan must probe ZERO files. `scanned` still counts them (they were
     * visited), `added`/`updated`/`failed` stay at 0, and the album's `total_tracks`
     * is untouched because no album was flushed.
     */
    public function testAnUnchangedFileIsSkippedWithoutBeingProbed(): void
    {
        [$dir, $db] = $this->fixture(3);
        $scanner = $this->scanner($db);

        $first = $scanner->scanDirectory($dir, null, 'lib-1');
        self::assertSame(3, $first->added, 'first scan must index all three files');
        self::assertSame(3, $scanner->probeCount, 'first scan must read all three files');

        $scanner->resetProbes();
        $second = $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            0,
            $scanner->probeCount,
            'THE POINT OF S122(a): a rescan of an unchanged library must not open a single file. '
            . 'A non-zero count here means the skip is not firing — or has been moved to after '
            . 'probeMetadata(), which keeps every counter assertion green while removing the entire benefit.'
        );
        self::assertSame(3, $second->scanned, 'a skipped file is still a file the scan visited');
        self::assertSame(0, $second->added);
        self::assertSame(0, $second->updated);
        self::assertSame(0, $second->failed);
    }

    /**
     * AC 5, second half + AC 2 — a file whose mtime moved is re-read, and ONLY that file.
     */
    public function testATouchedFileIsProbedAgain(): void
    {
        [$dir, $db] = $this->fixture(3);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        touch($dir . '/track-2.mp3', time() + 120);
        clearstatcache();

        $scanner->resetProbes();
        $result = $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(1, $scanner->probeCount, 'exactly the touched file must be re-read');
        self::assertSame([$dir . '/track-2.mp3'], $scanner->probedPaths, 'and it must be the RIGHT file');
        self::assertSame(3, $result->scanned);
    }

    /**
     * AC 2 — **size differing is enough on its own.** The mtime is restored to its
     * original value after the content grows, so mtime alone would say "unchanged".
     *
     * This is the half a `mtime`-only predicate gets wrong, and the reason the
     * predicate is a conjunction.
     */
    public function testAFileWhoseSizeChangedUnderAPreservedMtimeIsProbedAgain(): void
    {
        [$dir, $db] = $this->fixture(2);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $path = $dir . '/track-1.mp3';
        $mtime = filemtime($path);
        self::assertIsInt($mtime);

        file_put_contents($path, file_get_contents($path) . 'grown');
        touch($path, $mtime);
        clearstatcache();
        self::assertSame($mtime, filemtime($path), 'the fixture must actually preserve the mtime');

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            [$path],
            $scanner->probedPaths,
            'size is half the predicate: a same-mtime size change must still force a re-read'
        );
    }

    /**
     * AC 2 — **mtime differing is enough on its own.** The file keeps its exact byte
     * length while its content and mtime change, so size alone would say "unchanged".
     */
    public function testAFileWhoseMtimeChangedAtAConstantSizeIsProbedAgain(): void
    {
        [$dir, $db] = $this->fixture(2);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $path = $dir . '/track-2.mp3';
        $before = filesize($path);
        self::assertIsInt($before);

        $content = file_get_contents($path);
        self::assertIsString($content);
        file_put_contents($path, strrev($content));
        touch($path, time() + 300);
        clearstatcache();
        self::assertSame($before, filesize($path), 'the fixture must actually preserve the size');

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame([$path], $scanner->probedPaths, 'mtime is the other half of the predicate');
    }

    /**
     * ⚠ THE DOCUMENTED FAILURE MODE, ASSERTED RATHER THAN IMPLIED.
     *
     * A file whose content changes while BOTH mtime and size are preserved is
     * **missed** — and it stays missed until something touches it. This test exists so
     * that the limitation is a stated, tested property rather than a surprise found in
     * production, and so that anyone who later claims the predicate is exact has a
     * counter-example in front of them.
     *
     * Reaching this state requires a writer that deliberately restores the timestamp
     * (`touch -r`, `cp --preserve=timestamps`, a padding tag editor). The operator
     * escape hatch is in {@see MusicScanSkipIndex}'s docblock: clear the two
     * `metadata_json` keys for the library and the next scan re-reads everything.
     */
    public function testASameSizeSameMtimeContentChangeIsMissedAndThatIsTheKnownTradeOff(): void
    {
        [$dir, $db] = $this->fixture(2);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $path = $dir . '/track-1.mp3';
        $mtime = filemtime($path);
        $size = filesize($path);
        self::assertIsInt($mtime);
        self::assertIsInt($size);

        $content = file_get_contents($path);
        self::assertIsString($content);
        file_put_contents($path, strrev($content));
        touch($path, $mtime);
        clearstatcache();
        self::assertSame($mtime, filemtime($path));
        self::assertSame($size, filesize($path));

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            0,
            $scanner->probeCount,
            'KNOWN AND ACCEPTED: an in-place edit that preserves both mtime and size is not '
            . 'detected. If this assertion ever fails because the predicate was strengthened, '
            . 'that is an improvement — update the docblock in MusicScanSkipIndex to match.'
        );
    }

    /**
     * S96(e) MUST NOT REGRESS: while any `music_*` row still has a NULL
     * `media_item_id`, the fast path is off, so the album is flushed and the heal runs.
     *
     * This is the interaction that makes S122(a) safe to combine with S96: the heal
     * lives inside `flushAlbum()`, `flushAlbum()` only runs for probed files, so a
     * scan that skipped everything would never heal anything.
     */
    public function testTheFastPathIsDisabledWhileAMusicRowStillNeedsHealing(): void
    {
        [$dir, $db] = $this->fixture(3);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        // Reproduce the S96(e) shape: an artist row whose media_item mint failed.
        $key = array_key_first($db->artists);
        self::assertIsString($key);
        $db->artists[$key]['media_item_id'] = null;

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            3,
            $scanner->probeCount,
            'an unhealed music row must switch the fast path OFF for the whole scan, or S96(e)\'s '
            . 'backfill never runs again on a settled library'
        );
        self::assertIsString(
            $db->artists[$key]['media_item_id'],
            'and the heal must actually have happened'
        );
    }

    /**
     * The other gate: while an adoptable orphan exists the fast path is off, so the
     * adoption in `upsertArtist()` still gets its chance.
     */
    public function testTheFastPathIsDisabledWhileAnOrphanedMediaItemIsAdoptable(): void
    {
        [$dir, $db] = $this->fixture(3);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        // An artist media_items row that no music_artists row points at — the exact
        // residue hasAdoptableMusicMediaItem() exists to find.
        $db->mediaItems[] = [
            'id' => 'orphan-artist-media-item',
            'library_id' => 'lib-1',
            'type' => 'artist',
            'name' => 'Nobody Points At Me',
            'path' => '',
            'parent_id' => null,
            'metadata_json' => ['sub_type' => 'artist', 'name' => 'Nobody Points At Me'],
        ];

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            3,
            $scanner->probeCount,
            'an adoptable orphan must switch the fast path OFF, or the orphan can never be adopted'
        );
    }

    /**
     * ⚠ THE `JOIN music_tracks` IN {@see MusicScanSkipIndex::load()}, PINNED.
     *
     * A `media_items` row that carries a stamp but has NO `music_tracks` row is a file
     * that is NOT in the library — the shape produced when the track INSERT writes
     * nothing after the media_item was already minted. Without the join it would be
     * skipped forever and the file would be permanently missing; with it, the file is
     * probed and the track row is created.
     */
    public function testAStampedMediaItemWithNoTrackRowIsProbedAndRepaired(): void
    {
        [$dir, $db] = $this->fixture(3);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');
        self::assertCount(3, $db->tracks);

        // Drop one music_tracks row, leaving its stamped media_items row in place.
        $lostMediaItemId = array_key_first($db->tracks);
        self::assertIsString($lostMediaItemId);
        unset($db->tracks[$lostMediaItemId]);

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            1,
            $scanner->probeCount,
            'a stamped media_item with no track row must NOT be skippable — deleting the join in '
            . 'MusicScanSkipIndex::load() turns this retry into permanent data loss'
        );
        self::assertArrayHasKey($lostMediaItemId, $db->tracks, 'and the missing track row must be restored');
    }

    /**
     * A file the scan LOST must not be stamped, or the next scan skips a file that was
     * never successfully indexed.
     */
    public function testAFileTheScanLostIsNotStampedAndIsRetried(): void
    {
        [$dir, $db] = $this->fixture(1);
        $scanner = $this->scanner($db);

        $db->returnNullFor('INSERT INTO music_tracks');
        $failed = $scanner->scanDirectory($dir, null, 'lib-1');
        self::assertSame(1, $failed->failed, 'the fixture must actually lose the file');
        self::assertCount(0, $db->tracks);

        $db->clearNullFor();
        $scanner->resetProbes();
        $recovered = $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(1, $scanner->probeCount, 'a lost file must be retried, never skipped');
        self::assertSame(0, $recovered->failed);
        self::assertCount(1, $db->tracks, 'and the retry must index it');
    }

    /**
     * THE DEPLOY PATH, stated as a test because it is the honest answer to "is the
     * FIRST rescan after this ships fast?" — no, it is not.
     *
     * Every pre-S122 row carries no `(mtime, size)`, so nothing is skippable until a
     * scan records it. The first rescan therefore reads everything (exactly as today)
     * and stamps as it goes; the second is the fast one. There is deliberately no
     * filesystem backfill migration — recording today's mtime for a row whose indexed
     * tags may predate a change would manufacture a silent miss.
     */
    public function testAnUnstampedLibraryIsReadOnceAndThenSkipped(): void
    {
        [$dir, $db] = $this->fixture(4);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        // Reproduce a pre-S122 database: rows exist, stamps do not.
        foreach ($db->mediaItems as $i => $row) {
            unset(
                $db->mediaItems[$i]['metadata_json'][MusicScanSkipIndex::KEY_MTIME],
                $db->mediaItems[$i]['metadata_json'][MusicScanSkipIndex::KEY_SIZE]
            );
        }

        $scanner->resetProbes();
        $backfill = $scanner->scanDirectory($dir, null, 'lib-1');
        self::assertSame(4, $scanner->probeCount, 'an unstamped library must be read in full once');
        self::assertSame(0, $backfill->added);
        self::assertSame(0, $backfill->failed);

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');
        self::assertSame(
            0,
            $scanner->probeCount,
            'the pass that read the library must have stamped it, so the NEXT pass skips it'
        );
    }

    /**
     * The stamp is written on the `updated` branch too, not just `skipped` — otherwise
     * a file that changes once is re-read on every subsequent scan forever.
     */
    public function testAChangedFileIsStampedSoItIsSkippedOnTheFollowingScan(): void
    {
        [$dir, $db] = $this->fixture(2);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $path = $dir . '/track-1.mp3';
        touch($path, time() + 60);
        clearstatcache();
        $scanner->titleSuffix = ' (remastered)';

        $scanner->resetProbes();
        $changed = $scanner->scanDirectory($dir, null, 'lib-1');
        self::assertSame(1, $scanner->probeCount);
        self::assertSame(1, $changed->updated, 'the fixture must actually take the updated branch');

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');
        self::assertSame(0, $scanner->probeCount, 'an updated file must be stamped, not left to re-read forever');
    }

    /**
     * A skipped file must not disturb `music_albums.total_tracks`.
     *
     * `refreshAlbumTrackTotal()` runs per FLUSH, and a fully-skipped album is never
     * flushed — so the column has to already be right, which it is because the
     * previous scan derived it from `music_tracks`. This pins that the fast path does
     * not zero it.
     */
    public function testASkippedAlbumKeepsItsTrackTotal(): void
    {
        [$dir, $db] = $this->fixture(3);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $albumId = array_key_first($db->albums);
        self::assertIsInt($albumId);
        self::assertSame(3, $db->albums[$albumId]['total_tracks']);

        $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            3,
            $db->albums[$albumId]['total_tracks'],
            'a skipped album must keep the total its last flush computed'
        );
    }

    /**
     * A scan with no library id (the legacy `POST /api/v1/music/scan` path) must
     * behave exactly as before: no index is loaded, so nothing is ever skipped.
     *
     * `media_items.library_id` is NOT NULL, so there is no row such a scan could match
     * anyway — loading would be a guaranteed-empty full scan.
     */
    public function testTheLegacyNoLibraryScanPathNeverSkips(): void
    {
        [$dir, $db] = $this->fixture(2);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, null);

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, null);

        self::assertSame(2, $scanner->probeCount, 'a library-less scan has no identity map and must not skip');
    }

    /**
     * Builds a one-album fixture tree plus a matching empty database.
     *
     * @param int $files Number of tracks.
     * @return array{0: string, 1: SkipSchemaConnection}
     */
    private function fixture(int $files): array
    {
        $dir = sys_get_temp_dir() . '/phlix_s122_' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tempDirs[] = $dir;

        for ($i = 1; $i <= $files; $i++) {
            // Distinct lengths so a size-based assertion cannot pass by accident.
            file_put_contents($dir . '/track-' . $i . '.mp3', str_repeat('a', 100 + $i));
        }

        return [$dir, new SkipSchemaConnection()];
    }

    /**
     * A scanner whose tag reads are counted and whose read-ahead pool is off.
     */
    private function scanner(SkipSchemaConnection $db): ProbeCountingScanner
    {
        return new ProbeCountingScanner(
            $db,
            $this->createMock(FfmpegRunner::class),
            $this->createMock(StructuredLogger::class)
        );
    }

    /**
     * @param string $dir Directory to delete recursively.
     * @return void
     */
    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}

/**
 * A {@see MusicLibraryScanner} that counts tag reads and never spawns a reader pool.
 *
 * Overriding `probeViaGetId3()` rather than mocking getID3 is what makes the probe
 * COUNT the assertion surface: it is the exact call the S122(a) skip exists to avoid,
 * and it is reached only from `probeMetadata()`, which is reached only from the walk.
 *
 * @internal
 */
final class ProbeCountingScanner extends MusicLibraryScanner
{
    /** Tag reads performed since the counter was last reset. */
    public int $probeCount = 0;

    /** @var list<string> Paths whose tags were read, in order. */
    public array $probedPaths = [];

    /** Appended to the title, so a test can make a re-read look like a real change. */
    public string $titleSuffix = '';

    /**
     * Clears BOTH counters before a rescan.
     *
     * One method rather than two assignments at 11 call sites, because resetting the
     * count and forgetting the path list is how three of these tests first "failed":
     * the count assertion passed while the path assertion compared against the union
     * of both scans.
     *
     * @return void
     */
    public function resetProbes(): void
    {
        $this->probeCount = 0;
        $this->probedPaths = [];
    }

    protected function probeViaGetId3(string $path): ?array
    {
        $this->probeCount++;
        $this->probedPaths[] = $path;

        return [
            'artist' => 'Skip Artist',
            'album' => 'Skip Album',
            'title' => basename($path, '.mp3') . $this->titleSuffix,
            'track_number' => 1,
            'disc_number' => 1,
            'duration_secs' => 123,
            'year' => 2020,
            'genre' => 'Rock',
        ];
    }

    protected function probeViaFfprobe(string $path): ?array
    {
        self::fail('ffprobe must never be reached: probeViaGetId3() already returned usable tags');
    }

    /**
     * 1 = no reader pool. These tests are about the skip predicate; spawning three
     * child processes per scan would only add noise and wall time.
     */
    protected function readConcurrency(): int
    {
        return 1;
    }
}

/**
 * In-memory `media_items` + `music_*` schema for the S122(a) tests.
 *
 * Deliberately its OWN double rather than a change to
 * {@see MusicLibraryScannerTest}'s `MusicSchemaConnection`: that one has been hardened
 * across ten review rounds around the INSERT/UPDATE return-value contract and is
 * pinned by tests that count its arms. This one adds what S122 needs — a
 * `metadata_json` document per row, the `JSON_SET` stamp, the identity-map SELECT and
 * the heal gate — without touching those pins.
 *
 * It follows the same measured client contract, because that contract is what the
 * production code branches on: `SELECT` → list, `INSERT` → the insert id AS A STRING
 * (`'0'` for `media_items`, which has a UUID primary key and no `AUTO_INCREMENT` — and
 * `'0'` is FALSY, which is why the scanner must not use `if (!$result)`),
 * `UPDATE`/`DELETE`/`REPLACE` → an affected-row `int`, anything else → `null`.
 *
 * @internal
 */
final class SkipSchemaConnection extends Connection
{
    /**
     * @var list<array{id:string, library_id:?string, type:string, name:string, path:string,
     *     parent_id:?string, metadata_json:array<string, mixed>}>
     */
    public array $mediaItems = [];

    /** @var array<string, array{id:int, name:string, media_item_id:?string}> By lower-case name. */
    public array $artists = [];

    /**
     * @var array<int, array{id:int, artist_id:int, title:string, media_item_id:?string, total_tracks:int}>
     */
    public array $albums = [];

    /**
     * @var array<string, array{id:int, album_id:int, title:string, track_number:int,
     *     disc_number:int, duration_secs:int}> By media_item_id.
     */
    public array $tracks = [];

    /** @var list<string> Every statement, in order. */
    public array $statements = [];

    /** @var list<string> Statement substrings whose query() returns NULL. */
    private array $nullOn = [];

    private int $autoInc = 0;

    /** Intentionally does not call the parent constructor (which would connect). */
    public function __construct()
    {
    }

    /**
     * Make every statement containing `$needle` report that it wrote nothing, the way
     * the real client does for an INSERT: `null`.
     *
     * @param string $needle Statement substring.
     * @return void
     */
    public function returnNullFor(string $needle): void
    {
        $this->nullOn[] = $needle;
    }

    /**
     * Disarm every {@see self::returnNullFor()}.
     *
     * @return void
     */
    public function clearNullFor(): void
    {
        $this->nullOn = [];
    }

    /**
     * Mirrors the driver's own signature (`Connection::query($query, $params,
     * $fetchmode)`), which is why it is untyped here.
     *
     * @param string $query
     * @param array<int, mixed>|null $params
     * @param int $fetchmode
     * @return array<int, mixed>|int|string|null
     */
    public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        unset($fetchmode);
        $sql = ltrim((string) $query);
        $bound = is_array($params) ? array_values($params) : [];
        $this->statements[] = $sql;

        foreach ($this->nullOn as $needle) {
            if (str_contains($sql, $needle)) {
                return null;
            }
        }

        return match (strtolower(trim(explode(' ', trim($sql))[0]))) {
            'select', 'show' => $this->runSelect($sql, $bound),
            'insert' => $this->runInsert($sql, $bound),
            'update', 'delete', 'replace' => $this->runUpdate($sql, $bound),
            default => null,
        };
    }

    /** @return string */
    public function lastInsertId()
    {
        return (string) $this->autoInc;
    }

    /**
     * @param array<int, mixed> $p
     * @return list<array<string, mixed>>
     */
    private function runSelect(string $sql, array $p): array
    {
        // S122(a) identity map. The JOIN is honoured EXACTLY — a media_items row with
        // no music_tracks row must not appear, because that is the property that keeps a
        // lost file retryable instead of permanently skipped.
        if (str_contains($sql, 'JOIN music_tracks mt ON mt.media_item_id = mi.id')) {
            $out = [];
            foreach ($this->mediaItems as $row) {
                if ($row['type'] !== 'track' || $row['library_id'] !== ($p[0] ?? null)) {
                    continue;
                }
                if (!isset($this->tracks[$row['id']])) {
                    continue;
                }
                $mtime = $row['metadata_json'][MusicScanSkipIndex::KEY_MTIME] ?? null;
                $size = $row['metadata_json'][MusicScanSkipIndex::KEY_SIZE] ?? null;
                $out[] = [
                    'path' => $row['path'],
                    // `->>` unquotes to a STRING, or SQL NULL when the path is absent.
                    'file_mtime' => is_int($mtime) ? (string) $mtime : null,
                    'file_size' => is_int($size) ? (string) $size : null,
                ];
            }

            return $out;
        }

        // S122(a) heal gate (S96(e) protection).
        if (str_contains($sql, 'AS unhealed FROM music_artists WHERE media_item_id IS NULL')) {
            foreach ($this->artists as $artist) {
                if ($artist['media_item_id'] === null) {
                    return [['unhealed' => 1]];
                }
            }
            foreach ($this->albums as $album) {
                if ($album['media_item_id'] === null) {
                    return [['unhealed' => 1]];
                }
            }

            return [];
        }

        // One-per-scan orphan gate.
        if (str_contains($sql, 'LEFT JOIN music_artists ar') && str_contains($sql, 'LEFT JOIN music_albums al')) {
            foreach ($this->mediaItems as $row) {
                if (
                    in_array($row['type'], ['artist', 'album'], true)
                    && $row['path'] === ''
                    && $row['library_id'] === ($p[0] ?? null)
                    && !$this->isReferenced($row['id'])
                ) {
                    return [['id' => $row['id']]];
                }
            }

            return [];
        }

        if (str_contains($sql, 'LEFT JOIN music_artists ma')) {
            foreach ($this->mediaItems as $row) {
                if (
                    $row['type'] === 'artist'
                    && $row['name'] === ($p[0] ?? null)
                    && $row['path'] === ''
                    && $row['library_id'] === ($p[1] ?? null)
                    && !$this->isReferenced($row['id'])
                ) {
                    return [['id' => $row['id']]];
                }
            }

            return [];
        }

        if (str_contains($sql, 'LEFT JOIN music_albums ma')) {
            foreach ($this->mediaItems as $row) {
                if (
                    $row['type'] === 'album'
                    && $row['name'] === ($p[0] ?? null)
                    && $row['path'] === ''
                    && $row['library_id'] === ($p[1] ?? null)
                    && !$this->isReferenced($row['id'])
                ) {
                    return [['id' => $row['id']]];
                }
            }

            return [];
        }

        if (str_contains($sql, 'FROM music_artists WHERE name')) {
            $key = strtolower((string) ($p[0] ?? ''));

            return isset($this->artists[$key])
                ? [['id' => $this->artists[$key]['id'], 'media_item_id' => $this->artists[$key]['media_item_id']]]
                : [];
        }

        if (str_contains($sql, 'FROM music_albums WHERE artist_id')) {
            foreach ($this->albums as $album) {
                if ($album['artist_id'] === (int) ($p[0] ?? 0) && $album['title'] === ($p[1] ?? null)) {
                    return [['id' => $album['id'], 'media_item_id' => $album['media_item_id']]];
                }
            }

            return [];
        }

        if (str_contains($sql, "FROM media_items WHERE type = 'track'")) {
            foreach ($this->mediaItems as $row) {
                if (
                    $row['type'] === 'track'
                    && $row['path'] === ($p[0] ?? null)
                    && $row['library_id'] === ($p[1] ?? null)
                ) {
                    return [['id' => $row['id']]];
                }
            }

            return [];
        }

        if (str_contains($sql, 'FROM music_tracks WHERE media_item_id')) {
            $mid = (string) ($p[0] ?? '');

            return isset($this->tracks[$mid]) ? [$this->tracks[$mid]] : [];
        }

        return [];
    }

    /**
     * @param array<int, mixed> $p
     * @return string The insert id, as the real client reports it.
     */
    private function runInsert(string $sql, array $p): string
    {
        if (str_starts_with($sql, 'INSERT INTO media_items')) {
            $decoded = is_string($p[5] ?? null) ? json_decode($p[5], true) : null;
            $this->mediaItems[] = [
                'id' => (string) ($p[0] ?? ''),
                'library_id' => is_string($p[1] ?? null) ? $p[1] : null,
                'type' => (string) ($p[2] ?? ''),
                'name' => (string) ($p[3] ?? ''),
                'path' => (string) ($p[4] ?? ''),
                'parent_id' => null,
                'metadata_json' => is_array($decoded) ? $decoded : [],
            ];

            // media_items has a UUID primary key and no AUTO_INCREMENT, so a SUCCESSFUL
            // insert reports lastInsertId() = '0' — falsy, and measured.
            return '0';
        }

        if (str_starts_with($sql, 'INSERT INTO music_artists')) {
            $this->autoInc++;
            $name = (string) ($p[0] ?? '');
            $this->artists[strtolower($name)] = [
                'id' => $this->autoInc,
                'name' => $name,
                'media_item_id' => is_string($p[2] ?? null) ? $p[2] : null,
            ];

            return (string) $this->autoInc;
        }

        if (str_starts_with($sql, 'INSERT INTO music_albums')) {
            $this->autoInc++;
            $this->albums[$this->autoInc] = [
                'id' => $this->autoInc,
                'artist_id' => (int) ($p[0] ?? 0),
                'title' => (string) ($p[2] ?? ''),
                'media_item_id' => is_string($p[1] ?? null) ? $p[1] : null,
                'total_tracks' => 0,
            ];

            return (string) $this->autoInc;
        }

        if (str_starts_with($sql, 'INSERT INTO music_tracks')) {
            $this->autoInc++;
            $this->tracks[(string) ($p[0] ?? '')] = [
                'id' => $this->autoInc,
                'album_id' => (int) ($p[1] ?? 0),
                'title' => (string) ($p[3] ?? ''),
                'track_number' => (int) ($p[4] ?? 0),
                'disc_number' => (int) ($p[5] ?? 1),
                'duration_secs' => (int) ($p[6] ?? 0),
            ];

            return (string) $this->autoInc;
        }

        return '0';
    }

    /**
     * @param array<int, mixed> $p
     * @return int Affected rows, as the real client reports for these keywords.
     */
    private function runUpdate(string $sql, array $p): int
    {
        // S122(a) stamp. JSON_SET semantics: MERGE the two keys into the existing
        // document, never replace it — a fake that replaced it would hide the fact that
        // the production statement has to COALESCE a NULL document.
        if (str_contains($sql, 'UPDATE media_items SET metadata_json = JSON_SET')) {
            $id = (string) ($p[2] ?? '');
            foreach ($this->mediaItems as $i => $row) {
                if ($row['id'] !== $id) {
                    continue;
                }
                $this->mediaItems[$i]['metadata_json'][MusicScanSkipIndex::KEY_MTIME] = (int) ($p[0] ?? 0);
                $this->mediaItems[$i]['metadata_json'][MusicScanSkipIndex::KEY_SIZE] = (int) ($p[1] ?? 0);

                return 1;
            }

            return 0;
        }

        if (str_contains($sql, 'UPDATE music_artists SET media_item_id')) {
            foreach ($this->artists as $key => $artist) {
                if ($artist['id'] !== (int) ($p[1] ?? 0) || $artist['media_item_id'] !== null) {
                    continue;
                }
                $this->artists[$key]['media_item_id'] = is_string($p[0] ?? null) ? $p[0] : null;

                return 1;
            }

            return 0;
        }

        if (str_contains($sql, 'UPDATE music_albums SET media_item_id')) {
            foreach ($this->albums as $id => $album) {
                if ($album['id'] !== (int) ($p[1] ?? 0) || $album['media_item_id'] !== null) {
                    continue;
                }
                $this->albums[$id]['media_item_id'] = is_string($p[0] ?? null) ? $p[0] : null;

                return 1;
            }

            return 0;
        }

        if (str_contains($sql, 'SET a.total_tracks')) {
            $albumId = (int) ($p[0] ?? 0);
            if (!isset($this->albums[$albumId])) {
                return 0;
            }
            $count = 0;
            foreach ($this->tracks as $track) {
                if ($track['album_id'] === $albumId) {
                    $count++;
                }
            }
            $this->albums[$albumId]['total_tracks'] = $count;

            return 1;
        }

        if (str_contains($sql, 'UPDATE music_albums SET year')) {
            return 1;
        }

        if (str_contains($sql, 'UPDATE music_tracks SET title')) {
            foreach ($this->tracks as $mid => $track) {
                if ($track['id'] !== (int) ($p[4] ?? 0)) {
                    continue;
                }
                $this->tracks[$mid]['title'] = (string) ($p[0] ?? '');
                $this->tracks[$mid]['track_number'] = (int) ($p[1] ?? 0);
                $this->tracks[$mid]['disc_number'] = (int) ($p[2] ?? 1);
                $this->tracks[$mid]['duration_secs'] = (int) ($p[3] ?? 0);

                return 1;
            }

            return 0;
        }

        return 0;
    }

    /**
     * Is this `media_items.id` referenced by a `music_artists`/`music_albums` row?
     *
     * @param string $mediaItemId Candidate id.
     * @return bool
     */
    private function isReferenced(string $mediaItemId): bool
    {
        foreach ($this->artists as $artist) {
            if ($artist['media_item_id'] === $mediaItemId) {
                return true;
            }
        }
        foreach ($this->albums as $album) {
            if ($album['media_item_id'] === $mediaItemId) {
                return true;
            }
        }

        return false;
    }
}
