<?php

/**
 * Phlix media server test: Media\Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Transcoding\FfmpegRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * S145 — the REACH half: why fixing `upsertTrack()` alone is cosmetic, and what makes
 * the fix reach the rows that are already wrong.
 *
 * ## The state this file reproduces is the production state, not a contrived one
 *
 * A track is mis-parented when its ALBUM or ARTIST tag was edited while its title,
 * track number, disc number and duration stayed identical. `music_tracks.album_id`
 * and `artist_id` are `INT UNSIGNED NOT NULL` with enforced FKs (migration 065), so
 * the row is never NULL and never violates a constraint — it is **wrong but valid**,
 * pointing at a real, stale album. Nothing surfaces it. Measured on production
 * 2026-07-27: **310 albums owning zero tracks**, and of the albums created after the
 * initial import settled, **100 % (07-26) and 97.8 % (07-27) were empty shells**.
 *
 * The rows in that state have one thing in common that defeats the obvious fix: the
 * FILE did not change. Its S122(a) `(mtime, size)` stamp is intact, so the skip at
 * the top of the walk `continue`s **before** `probeMetadata()` and before the file is
 * buffered for `flushAlbum()` — and `upsertTrack()` is never called at all. Measured:
 * **29,134 of 61,111 production tracks (47.7 %, rising toward 100 %)** would be
 * skipped by the next ordinary scan.
 *
 * {@see self::testAMisParentedTrackIsNotHealedByAnOrdinaryRescan()} is the executable
 * form of that sentence, and it is a DELIBERATE NEGATIVE ASSERTION: it asserts the
 * unhealed outcome. Deleting it because "the test asserts the bug" would delete the
 * only proof that the `$readEveryFile` mode is load-bearing rather than decorative.
 *
 * ## Why these are unit tests against a double, when S145's acceptance test is not
 *
 * The acceptance proof — which columns the `UPDATE` actually writes — is
 * {@see \Phlix\Tests\Integration\Media\MusicRetagReparentIntegrationTest}, against
 * real MySQL and a real ID3 tag write, because a double cannot prove a column list.
 * What a double CAN prove, and a real DB makes slow and awkward, is reach: a stamped
 * row that no file change will ever re-open. That is what this file is for.
 *
 * @internal
 */
final class MusicScanReparentTest extends TestCase
{
    /** `music_artists.id` of the wrong artist a track is re-pointed at. */
    private const WRONG_ARTIST_ID = 90;

    /** `music_albums.id` of the wrong album a track is re-pointed at. */
    private const WRONG_ALBUM_ID = 91;

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
     * T2, first half — **the cosmetic-fix proof.** A mis-parented row whose file is
     * unchanged and stamped is NOT healed by an ordinary rescan, because the scan
     * never opens the file and therefore never reaches `upsertTrack()` at all.
     *
     * ⚠ The failing assertions here describe the DESIRED behaviour of the ordinary
     * scan (fast, skipping) — not a defect. A change that made this test go red by
     * healing the row would mean the S122(a) fast path had been disabled, which is
     * the 6.1-hour regression, not a fix.
     */
    public function testAMisParentedTrackIsNotHealedByAnOrdinaryRescan(): void
    {
        [$dir, $db] = $this->fixture(2);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $trackPath = $dir . '/track-1.mp3';
        [$rightAlbumId, $rightArtistId] = $this->parentageOf($db, $trackPath);
        $this->misParent($db, $trackPath);

        // Nothing on disk changed and the stamp is intact — the production state.
        $scanner->resetProbes();
        $result = $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            0,
            $scanner->probeCount,
            'the ordinary scan must still skip: the file is unchanged, so nothing re-opens it'
        );
        self::assertSame(0, $result->updated, 'and it cannot update a row it never read');
        self::assertSame(
            [self::WRONG_ALBUM_ID, self::WRONG_ARTIST_ID],
            $this->parentageOf($db, $trackPath),
            'THE POINT: the row is still mis-parented. A fix confined to upsertTrack() is invisible to '
            . 'every already-stamped file — 29,134 of 61,111 on production — so S145 needs the full-read '
            . 'mode as well, not instead.'
        );
        self::assertNotSame([$rightAlbumId, $rightArtistId], $this->parentageOf($db, $trackPath));
    }

    /**
     * T2, second half — **the reach proof.** The same untouched, stamped, mis-parented
     * row IS healed under `$readEveryFile = true`, and the album it vacated has its
     * `total_tracks` corrected in the same pass.
     */
    public function testTheFullReadModeHealsAMisParentedTrackWhoseFileNeverChanged(): void
    {
        [$dir, $db] = $this->fixture(2);
        $logger = new RecordingLogger();
        $scanner = $this->scanner($db, $logger);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $trackPath = $dir . '/track-1.mp3';
        [$rightAlbumId, $rightArtistId] = $this->parentageOf($db, $trackPath);
        $this->misParent($db, $trackPath);
        self::assertSame(1, $db->albums[$rightAlbumId]['total_tracks'], 'the fixture must leave the right '
            . 'album holding only the OTHER track, so a heal has something to restore');

        $scanner->resetProbes();
        $result = $scanner->scanDirectory($dir, null, 'lib-1', true);

        self::assertSame(
            2,
            $scanner->probeCount,
            'the full-read mode must make canSkip() refuse every skip, so EVERY file is opened. '
            . '(S148 changed HOW: S145 achieved it by leaving the index unloaded, which also killed the '
            . 'stamp suppression and rewrote every row read. The index is loaded now; the probe count is '
            . 'unchanged, and that invariance IS the point.)'
        );
        self::assertSame(
            [$rightAlbumId, $rightArtistId],
            $this->parentageOf($db, $trackPath),
            'the track must be filed back under the album and artist its tags actually name'
        );
        self::assertSame(1, $result->updated, 'exactly one row moved; the other file was unchanged');
        self::assertSame(0, $result->added, 'and nothing was duplicated');
        self::assertSame(0, $result->failed);

        self::assertSame(
            2,
            $db->albums[$rightAlbumId]['total_tracks'],
            'the album the track moved TO is recounted by flushAlbum()\'s finally'
        );
        self::assertSame(
            0,
            $db->albums[self::WRONG_ALBUM_ID]['total_tracks'],
            'and the album it LEFT must be recounted too — flushAlbum() only ever refreshes the album it '
            . 'is flushing, so without the vacated-album refresh in upsertTrack() the old album keeps a '
            . 'total_tracks one too high forever, and MusicLibraryService sums that column onto the '
            . 'artist page'
        );

        $summary = $logger->contextOf('Music directory scan complete');
        self::assertIsArray($summary);
        self::assertSame(1, $summary['reparented'] ?? null, 'the operator\'s evidence that the healing '
            . 'scan repaired something, and the explanation for the `updated` it reports');
        self::assertTrue($summary['read_every_file'] ?? null, 'and that the scan really was a full read');
    }

    /**
     * T2 — an ARTIST-tag change moves BOTH ids, not just the album.
     *
     * Modelled by re-pointing only `artist_id`, which is the half a fix that widened
     * the predicate but not the UPDATE would still get wrong.
     */
    public function testTheFullReadModeAlsoRestoresAWrongArtistId(): void
    {
        [$dir, $db] = $this->fixture(2);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $trackPath = $dir . '/track-1.mp3';
        [$rightAlbumId, $rightArtistId] = $this->parentageOf($db, $trackPath);

        $mid = $this->mediaItemIdFor($db, $trackPath);
        $db->tracks[$mid]['artist_id'] = self::WRONG_ARTIST_ID;

        $result = $scanner->scanDirectory($dir, null, 'lib-1', true);

        self::assertSame(
            [$rightAlbumId, $rightArtistId],
            $this->parentageOf($db, $trackPath),
            'artist_id must be in the UPDATE too: an ARTIST retag moves a track to a DIFFERENT artist\'s '
            . 'album, and the album half alone leaves the row half-repaired'
        );
        self::assertSame(1, $result->updated);
    }

    /**
     * T3 — **the steady-state guard.** A full read of a clean, correctly-parented
     * library must report `updated = 0`.
     *
     * This is the test that a careless widening of the change predicate fails: if the
     * new comparison is wrong in either direction — comparing an unfetched column, or
     * comparing the album's id against the artist's — every file in the library turns
     * into an UPDATE and every rescan writes 61,111 rows for nothing.
     */
    public function testAFullReadOfACleanLibraryUpdatesNothing(): void
    {
        [$dir, $db] = $this->fixture(3);
        $logger = new RecordingLogger();
        $scanner = $this->scanner($db, $logger);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $scanner->resetProbes();
        $db->statements = [];
        $result = $scanner->scanDirectory($dir, null, 'lib-1', true);
        $stampUpdates = $this->countStatements($db, 'UPDATE media_items SET metadata_json = JSON_SET');

        self::assertSame(3, $scanner->probeCount, 'a full read reads everything, by definition');
        self::assertSame(
            0,
            $result->updated,
            'but reading a correct row must not rewrite it — the widened predicate has to match on '
            . 'parentage as well as on the four tag fields'
        );
        self::assertSame(0, $result->added);
        self::assertSame(0, $result->failed);

        $summary = $logger->contextOf('Music directory scan complete');
        self::assertIsArray($summary);
        self::assertSame(0, $summary['reparented'] ?? null, 'nothing was mis-parented, so nothing moved');

        // S148 — and it must not rewrite the row's STAMP either. The full-read mode now
        // loads the skip index and gates canSkip() instead of leaving the index unloaded,
        // so isStampCurrent() can suppress every one of these no-op writes. The real-DB
        // proof is MusicScanWriteAmplificationIntegrationTest; this is the cheap guard
        // that runs on every push.
        self::assertSame(
            3,
            $summary['skip_index_entries'] ?? null,
            'the summary reports a populated index. ⚠ DOCUMENTATION, NOT A KILLER, and measured as '
            . 'such: MusicScanSkipIndex::remember() adds an entry after every stamp write, so an '
            . 'UNLOADED index also ends a 3-file scan holding 3 entries — re-applying S145\'s load '
            . 'gate leaves THIS assertion green. The killer is the UPDATE count below'
        );
        self::assertSame(
            0,
            $stampUpdates,
            'so a clean full read rewrites nothing at all: not the track row, and not its stamp. '
            . 'At 8b953e82 this was 3 — 61,135 no-op UPDATEs on the production library'
        );
    }

    /**
     * S148 — the `reparented` counter answers "did this track move?", and nothing else.
     *
     * S145 shared ONE guard between the counter and the vacated-album recount:
     * `if ($existingAlbumId !== $albumId && $existingAlbumId > 0)`. The `> 0` half exists
     * for the RECOUNT — 0 is the "column absent or unreadable" shape from the coercion,
     * not a `music_albums.id`, so recounting it would target no row. The COUNTER has no
     * business inheriting it: such a track has genuinely moved.
     *
     * ⚠ **This shape cannot be produced against a real database** — `music_tracks.album_id`
     * is `INT UNSIGNED NOT NULL` with an enforced FK (migration 065) — which is exactly
     * why it belongs on the double, and exactly why it went unnoticed. A test that can
     * only be written where the constraint does not exist is still worth writing: the
     * defect is that the counter's correctness DEPENDS on a constraint it never mentions.
     */
    public function testATrackWhoseStoredAlbumIdReadsAsZeroIsStillCountedAsReparented(): void
    {
        [$dir, $db] = $this->fixture(2);
        $logger = new RecordingLogger();
        $scanner = $this->scanner($db, $logger);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $trackPath = $dir . '/track-1.mp3';
        [$rightAlbumId, $rightArtistId] = $this->parentageOf($db, $trackPath);
        $mid = $this->mediaItemIdFor($db, $trackPath);
        // The absent-column shape, verbatim: `(int) ($row['album_id'] ?? 0)` reading 0
        // while the artist is untouched, so the artist half of S145's `elseif` cannot
        // rescue the count.
        $db->tracks[$mid]['album_id'] = 0;

        $db->statements = [];
        $scanner->scanDirectory($dir, null, 'lib-1', true);

        self::assertSame(
            [$rightAlbumId, $rightArtistId],
            $this->parentageOf($db, $trackPath),
            'the row is repaired either way — that half was never broken'
        );

        $summary = $logger->contextOf('Music directory scan complete');
        self::assertIsArray($summary);
        self::assertSame(
            1,
            $summary['reparented'] ?? null,
            'RED at 8b953e82 with 0: the counter sat behind the recount\'s `> 0` guard, so a track '
            . 'that moved off an unreadable album id was repaired SILENTLY and the operator\'s only '
            . 'evidence of the repair said nothing had happened'
        );

        self::assertSame(
            1,
            $this->countStatements($db, 'SET a.total_tracks'),
            'and still exactly one recount — the album being flushed. 0 is not a music_albums.id, so '
            . 'nothing may be recounted for the album the track "left"'
        );
    }

    /**
     * S148 — a retagged N-track album recounts the album it vacated ONCE, not N times.
     *
     * All three tracks are re-pointed at one shell album and then healed back in a single
     * flush. `refreshAlbumTrackTotal()` used to run inside `upsertTrack()`, once per moved
     * track, so the vacated row was recounted three times with three identical correlated
     * `COUNT(*)`s. The real-MySQL proof is
     * {@see \Phlix\Tests\Integration\Media\MusicScanWriteAmplificationIntegrationTest::testARetaggedFourTrackAlbumRecountsTheVacatedAlbumExactlyOnce()};
     * this pins the same claim where the statement stream is cheap to read.
     */
    public function testAllTracksMovingOffOneAlbumCostASingleRecount(): void
    {
        [$dir, $db] = $this->fixture(3);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $this->misParent($db, $dir . '/track-1.mp3');
        foreach ([2, 3] as $n) {
            $mid = $this->mediaItemIdFor($db, $dir . '/track-' . $n . '.mp3');
            $db->tracks[$mid]['album_id'] = self::WRONG_ALBUM_ID;
            $db->tracks[$mid]['artist_id'] = self::WRONG_ARTIST_ID;
        }
        $db->albums[self::WRONG_ALBUM_ID]['total_tracks'] = 3;

        $db->statements = [];
        $result = $scanner->scanDirectory($dir, null, 'lib-1', true);

        self::assertSame(3, $result->updated, 'all three moved back');
        self::assertSame(
            2,
            $this->countStatements($db, 'SET a.total_tracks'),
            'ONE recount of the album they arrived at (flushAlbum()\'s finally) and ONE of the album '
            . 'they left. RED at 8b953e82 with 4: the vacated album was recounted once per track'
        );
        self::assertSame(0, $db->albums[self::WRONG_ALBUM_ID]['total_tracks'], 'and the answer is still right');
    }

    /**
     * T3 — **the S122 guard.** The flag must default to OFF.
     *
     * If `$readEveryFile` ever defaulted to `true`, every assertion about healing in
     * this file would still pass while the entire S122(a) benefit was silently
     * deleted and a settled library went back to a multi-hour scan. The probe count
     * is the only measure that catches that, which is why it is asserted here beside
     * the summary key rather than left to the S122 suite.
     */
    public function testTheDefaultScanStillSkipsUnchangedFiles(): void
    {
        [$dir, $db] = $this->fixture(3);
        $logger = new RecordingLogger();
        $scanner = $this->scanner($db, $logger);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            0,
            $scanner->probeCount,
            'the default must remain the S122(a) fast path: a full read is minutes -> HOURS on the '
            . 'production library (the last completed one measured 9 h 55 m; an earlier "~3.5 h" here '
            . 'was an estimate presented as a measurement) and is an operator decision, never a default'
        );

        $summary = $logger->contextOf('Music directory scan complete');
        self::assertIsArray($summary);
        self::assertFalse(
            $summary['read_every_file'] ?? null,
            'and the summary must say so, so an operator who asked for a healing scan can tell from one '
            . 'log line whether they got one'
        );
    }

    /**
     * Re-points a track at {@see self::WRONG_ALBUM_ID} / {@see self::WRONG_ARTIST_ID},
     * minting those rows if needed, and recounts both albums the way a real scan
     * would have left them.
     *
     * The file is NOT touched and the stamp is NOT cleared — that is the whole point.
     *
     * @param SkipSchemaConnection $db   Fixture database.
     * @param string               $path Absolute file path.
     * @return void
     */
    private function misParent(SkipSchemaConnection $db, string $path): void
    {
        $db->artists['wrong artist'] = [
            'id' => self::WRONG_ARTIST_ID,
            'name' => 'Wrong Artist',
            'media_item_id' => 'mi-wrong-artist',
        ];
        $db->albums[self::WRONG_ALBUM_ID] = [
            'id' => self::WRONG_ALBUM_ID,
            'artist_id' => self::WRONG_ARTIST_ID,
            'title' => 'Wrong Album',
            'media_item_id' => 'mi-wrong-album',
            'total_tracks' => 0,
        ];

        $mid = $this->mediaItemIdFor($db, $path);
        $previousAlbumId = $db->tracks[$mid]['album_id'];
        $db->tracks[$mid]['album_id'] = self::WRONG_ALBUM_ID;
        $db->tracks[$mid]['artist_id'] = self::WRONG_ARTIST_ID;

        // Both totals as the broken scan would have left them: the shell counts the
        // track it wrongly owns, and the real album has lost one.
        $db->albums[self::WRONG_ALBUM_ID]['total_tracks'] = 1;
        $db->albums[$previousAlbumId]['total_tracks'] = max(
            0,
            $db->albums[$previousAlbumId]['total_tracks'] - 1
        );
    }

    /**
     * The `(album_id, artist_id)` the fixture database currently holds for a file.
     *
     * @param SkipSchemaConnection $db   Fixture database.
     * @param string               $path Absolute file path.
     * @return array{0: int, 1: int}
     */
    private function parentageOf(SkipSchemaConnection $db, string $path): array
    {
        $mid = $this->mediaItemIdFor($db, $path);
        $track = $db->tracks[$mid] ?? null;
        self::assertIsArray($track, 'no music_tracks row for ' . $path);

        return [$track['album_id'], $track['artist_id']];
    }

    /**
     * How many recorded statements contain `$needle`.
     *
     * ⚠ {@see SkipSchemaConnection::$statements} keeps the SQL only, not the bound
     * parameters, so a count here says HOW MANY statements of a shape were issued and
     * never WHICH ROW they targeted. Where the target matters, the real-MySQL
     * {@see \Phlix\Tests\Integration\Media\RecordingMySqlConnection} records both.
     *
     * @param SkipSchemaConnection $db     Fixture database.
     * @param string               $needle Statement substring.
     * @return int
     */
    private function countStatements(SkipSchemaConnection $db, string $needle): int
    {
        $n = 0;
        foreach ($db->statements as $sql) {
            if (str_contains($sql, $needle)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * The `media_items.id` the fixture database holds for a track path.
     *
     * @param SkipSchemaConnection $db   Fixture database.
     * @param string               $path Absolute file path.
     * @return string
     */
    private function mediaItemIdFor(SkipSchemaConnection $db, string $path): string
    {
        foreach ($db->mediaItems as $row) {
            if ($row['type'] === 'track' && $row['path'] === $path) {
                return $row['id'];
            }
        }

        self::fail('no media_items row for ' . $path);
    }

    /**
     * Builds a one-album fixture tree plus a matching empty database.
     *
     * @param int $files Number of tracks.
     * @return array{0: string, 1: SkipSchemaConnection}
     */
    private function fixture(int $files): array
    {
        $dir = sys_get_temp_dir() . '/phlix_s145_' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tempDirs[] = $dir;

        for ($i = 1; $i <= $files; $i++) {
            file_put_contents($dir . '/track-' . $i . '.mp3', str_repeat('a', 100 + $i));
        }

        return [$dir, new SkipSchemaConnection()];
    }

    /** A scanner whose tag reads are counted and whose read-ahead pool is off. */
    private function scanner(SkipSchemaConnection $db, ?RecordingLogger $logger = null): ProbeCountingScanner
    {
        return new ProbeCountingScanner(
            $db,
            $this->createMock(FfmpegRunner::class),
            $logger ?? new RecordingLogger()
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
