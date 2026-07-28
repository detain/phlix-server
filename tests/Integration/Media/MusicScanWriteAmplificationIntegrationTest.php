<?php

/**
 * Phlix media server test: Media\Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Common\Uuid;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use Phlix\Tests\Unit\Media\Music\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * S148 acceptance — a healing full-read `rescan` must READ everything and WRITE only
 * what changed.
 *
 * ## The defect, in one sentence
 *
 * S145 implemented the full-read mode by NOT LOADING the S122(a) skip index. That
 * forced every file to be opened, which was the point — but it also made
 * {@see \Phlix\Media\Music\MusicScanSkipIndex::isStampCurrent()} answer FALSE for
 * every file, so {@see \Phlix\Media\Music\MusicLibraryScanner::stampFileIdentity()}
 * issued one `JSON_SET` `UPDATE` per file read, **all of them writing back the values
 * already stored**. On the production library that is 61,135 no-op row rewrites per
 * healing pass. S148 moves the gate onto `canSkip()`: the index is loaded and consulted
 * for STAMPING while the READ is forced.
 *
 * ## Why every assertion in this file needs a real server
 *
 * The claims are **counts of statements MySQL received** — "zero stamp UPDATEs",
 * "exactly one recount per vacated album". The in-memory doubles can count statements
 * too (see {@see \Phlix\Tests\Unit\Media\Music\MusicScanReparentTest}, which pins the
 * same two claims cheaply), so the reason this file exists is NOT that a count is
 * unobservable there. It is that the count is only as true as the double's model: the
 * scanner's write volume is decided by the rows it reads back, and a fake returns the
 * rows its author modelled. This file removes the model.
 *
 * {@see RecordingMySqlConnection} therefore subclasses the production connection and
 * forwards everything to a real server, recording as a side effect — and it keeps the
 * bound PARAMETERS, which the unit doubles discard, so "WHICH album was recounted" is
 * askable here and nowhere else.
 *
 * ⚠ **This is the same lesson mutation M10 taught during S145** — reverting only the
 * widened `SELECT` survived the entire unit suite, because both in-memory doubles hand
 * back a stored row wholesale and ignore the statement's column list, so the modelled
 * scan wrote nothing while the real one rewrote all 61,111 rows.
 *
 * ## Reach is asserted in the same file as write volume, deliberately
 *
 * The cheap way to make the write count go to zero is to let the scan skip files, which
 * would silently re-break S145 — a retagged track filed under the wrong album can ONLY
 * be repaired by opening the file, because the skip `continue`s before
 * `probeMetadata()`. So every write-count assertion here sits next to a probe count,
 * and {@see self::testTheLoadedIndexStillHealsAMisParentedTrackWhoseFileNeverChanged()}
 * proves the healing itself against real rows.
 *
 * Self-skips with no reachable MySQL; runs for real in CI.
 *
 * @covers \Phlix\Media\Music\MusicLibraryScanner
 */
final class MusicScanWriteAmplificationIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    /** The scanner's stamp statement, verbatim enough to match nothing else. */
    private const STAMP_SQL = 'UPDATE media_items SET metadata_json = JSON_SET';

    /** The scanner's `refreshAlbumTrackTotal()` statement. */
    private const RECOUNT_SQL = 'SET a.total_tracks';

    private ?RecordingMySqlConnection $db = null;

    private string $libraryId = '';

    /** Marker prefix so the fixtures can be purged without touching anything else. */
    private string $prefix = '';

    private string $root = '';

    /** @var list<string> Paths to remove, in creation order. */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();

        /** @var array{connections: array<string, array<string, mixed>>} $config */
        $config = include dirname(__DIR__, 3) . '/config/database.php';
        $conn = $config['connections']['mysql'];

        $host = is_scalar($conn['host'] ?? null) ? (string) $conn['host'] : '127.0.0.1';
        $port = is_numeric($conn['port'] ?? null) ? (int) $conn['port'] : 3306;

        // $host/$port come from config/database.php:14-15, which resolve DB_HOST/DB_PORT
        // exactly as IntegrationDbGuard does; passed explicitly so this site keeps
        // probing the same address it always has.
        $this->requireHealthyDatabase(
            'skipping the S148 real-DB write-volume test. Runs in CI.',
            $host,
            $port,
        );

        // A DEDICATED connection, not ConnectionPool's shared one: the pool caches its
        // instance for the whole process, so swapping in a recording subclass there
        // would leak into every later test in the run.
        $this->db = new RecordingMySqlConnection(
            $host,
            $port,
            is_scalar($conn['username'] ?? null) ? (string) $conn['username'] : '',
            is_scalar($conn['password'] ?? null) ? (string) $conn['password'] : '',
            is_scalar($conn['database'] ?? null) ? (string) $conn['database'] : '',
            'utf8mb4',
        );

        $this->libraryId = Uuid::v4();
        $this->prefix = '!S148-' . substr(Uuid::v4(), 0, 8) . '-';

        $this->db->query(
            "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'music', '[]')",
            [$this->libraryId, 'S148 Music IT Library'],
        );
    }

    protected function tearDown(): void
    {
        $this->db?->stopLog();
        $this->db?->clearFaults();
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
     * ⭐ **AC 1 — the headline.** A full read of an UNCHANGED library opens and tag-reads
     * every file and issues **zero** stamp `UPDATE`s.
     *
     * Both halves are asserted together on purpose. Zero writes with a reduced probe
     * count would not be a fix, it would be S145 undone: the whole reason `rescan` reads
     * every file is that a mis-parented track's file never changes, so nothing else will
     * ever re-open it.
     *
     * RED against S145 (`8b953e82`): 3 stamp UPDATEs, one per file, each writing back the
     * `(mtime, size)` already stored — 61,135 of them on the production library.
     */
    public function testAFullReadOfAnUnchangedLibraryIssuesNoStampUpdates(): void
    {
        $paths = $this->buildTree(['Album A' => 3]);
        $scanner = $this->scanner();
        $db = $this->connection();

        $first = $scanner->scanDirectory($this->root, null, $this->libraryId);
        self::assertSame(3, $first->added, 'the first scan must index all three files');
        self::assertSame(0, $first->failed);

        $scanner->resetProbes();
        $db->startLog();
        $second = $scanner->scanDirectory($this->root, null, $this->libraryId, true);
        $db->stopLog();

        self::assertSame(3, $scanner->probeCount, 'THE REACH HALF: the full-read mode must open every file');
        $probed = $scanner->probedPaths;
        sort($probed);
        self::assertSame(
            $paths,
            $probed,
            'and it must be every file, not three reads of one — a probe count alone cannot tell those '
            . 'apart. (Sorted on both sides: RecursiveDirectoryIterator yields readdir order, which is '
            . 'not the fixture creation order and is not a property worth pinning.)'
        );

        self::assertSame(
            0,
            $db->countMatching(self::STAMP_SQL),
            'THE WRITE HALF, and the point of S148: every one of those reads found the stored stamp '
            . 'already current, so not one row may be rewritten. RED at 8b953e82 with 3 — which is '
            . '61,135 no-op UPDATEs on the production library, per healing pass'
        );

        self::assertSame(0, $second->updated, 'and nothing changed, so nothing is reported as updated');
        self::assertSame(0, $second->added);
        self::assertSame(0, $second->failed);
        self::assertSame(3, $this->countTracks(), 'nothing duplicated');
    }

    /**
     * ⭐ **AC 1, ON THE SHAPE THE STEP EXISTS FOR — an UNHEALED library.**
     *
     * ⚠ **Review r1 finding 1.** The gate is
     * `if ($readEveryFile || (!$mayAdopt && !$needsHealing))`. Every other test in this
     * file runs with `$needsHealing === false` and `$mayAdopt === false`, where the
     * SECOND disjunct already loads the index — so the `$readEveryFile ||` half, which is
     * the entire change, was never the reason the index was loaded anywhere. Mutation M8
     * (delete `$readEveryFile ||`, i.e. let the load inherit the heal/adopt gate)
     * SURVIVED the whole 7,746-test suite. Reproduced twice, on an otherwise-unmodified
     * `8862fca9` tree with only M8 applied: `Tests: 7746, 0 failures`, exit 0. With this
     * test in place the same mutant is RED — `Failed asserting that 3 is identical to 0`
     * at the assertion below.
     *
     * ⚠ The SKIP COUNT is deliberately not quoted here, because it is not stable: three
     * of the six Workerman-`Timer` self-skips in `tests/Unit/LiveTv/Relay/`
     * (`HlsRelayManagerTest` and `HlsSegmentPrefetcherTest` carry three apiece, on the
     * same `isTimerAvailable()` condition and the same message) report "Workerman Timer
     * not available" only on some runs, so the same tree reports `Skipped: 7` or
     * `Skipped: 10` under `executionOrder="random"`. A delta of exactly 3 says one
     * file's worth flipped, not which file. Neither number is evidence of anything about
     * this mutant. `Failures: 0` is, and none of the varying skips is in this file.
     *
     * It is not a harmless mutant. A healing `rescan` is run BECAUSE the library is
     * unhealed — that is the operator's reason for asking — so the production shape is
     * exactly this one, and under M8 it pays one `JSON_SET` per file again: 61,135 of
     * them on the production library.
     *
     * The ORDINARY scan below is the control, and it is a measurement, not decoration:
     * with the same unhealed row present it issues **3 probes and 3 stamp UPDATEs**,
     * because there the heal gate genuinely does keep the index unloaded (review r1 B2's
     * finding, on a real server). Same fixture, same row, one flag apart — so the only
     * thing standing between the full read and those 3 UPDATEs is `$readEveryFile ||`.
     */
    public function testAFullReadOfAnUnhealedLibraryIssuesNoStampUpdates(): void
    {
        $paths = $this->buildTree(['Album A' => 3]);
        $scanner = $this->scanner();
        $db = $this->connection();

        self::assertSame(3, $scanner->scanDirectory($this->root, null, $this->libraryId)->added);
        self::assertFalse($this->healGateAnswersYes(), 'the fixture must start healthy');

        $this->mintUnhealedAlbum();
        self::assertTrue(
            $this->healGateAnswersYes(),
            'THE PRECONDITION, asserted rather than assumed: without a row that makes '
            . 'hasUnhealedMusicMediaItem() answer TRUE this degrades into the case the other '
            . 'tests already cover, and M8 would survive it too'
        );

        // ── The control: an ORDINARY scan, unhealed row present. ────────────────
        $scanner->resetProbes();
        $db->startLog();
        $scanner->scanDirectory($this->root, null, $this->libraryId);
        $db->stopLog();

        self::assertSame(3, $scanner->probeCount, 'the heal gate switches the fast path off, so nothing is skipped');
        self::assertSame(
            3,
            $db->countMatching(self::STAMP_SQL),
            'and the index is NOT loaded on this scan, so every one of those probes re-stamps a row '
            . 'that did not change. That is deliberate and unchanged by S148 — an ordinary scan of an '
            . 'unhealed library still pays it, which is what makes the number below meaningful'
        );

        // ── The subject: the same fixture, the same unhealed row, full-read on. ──
        $scanner->resetProbes();
        $db->startLog();
        $result = $scanner->scanDirectory($this->root, null, $this->libraryId, true);
        $db->stopLog();

        self::assertSame(3, $scanner->probeCount, 'THE REACH HALF: a full read still opens every file');
        $probed = $scanner->probedPaths;
        sort($probed);
        self::assertSame($paths, $probed, 'and it is every file, not three reads of one');

        self::assertSame(
            0,
            $db->countMatching(self::STAMP_SQL),
            'THE POINT, and the assertion that kills M8: 3 immediately above, 0 here, one flag apart. '
            . 'RED under `if (!$mayAdopt && !$needsHealing)` with 3 — the defect restored on the exact '
            . 'scan the step exists for'
        );

        self::assertSame(0, $result->updated, 'nothing changed on disk');
        self::assertSame(0, $result->added);
        self::assertSame(0, $result->failed);
        self::assertSame(3, $this->countTracks(), 'nothing duplicated');
        self::assertTrue(
            $this->healGateAnswersYes(),
            'and the row is STILL unhealed afterwards — so $needsHealing was TRUE for both scans, not '
            . 'merely at the moment the fixture was built'
        );
    }

    /**
     * ⭐ **AC 1, on the OTHER shape that switches the load gate off — an ADOPTABLE ORPHAN.**
     *
     * `$mayAdopt` and `$needsHealing` are two independent one-query-per-scan gates and
     * `!$mayAdopt && !$needsHealing` fails if EITHER is set, so both have to be pinned or
     * half the disjunct is still untested. This is the `$mayAdopt` half: an
     * `artist`-typed `media_items` row with `path = ''` that no `music_artists` row
     * points at, which is what `hasAdoptableMusicMediaItem()` reads.
     *
     * Its name matches nothing the walk flushes, so nothing adopts it and the flag stays
     * TRUE for the whole scan — asserted at the end rather than assumed.
     */
    public function testAFullReadWithAnAdoptableOrphanIssuesNoStampUpdates(): void
    {
        $this->buildTree(['Album A' => 3]);
        $scanner = $this->scanner();
        $db = $this->connection();

        self::assertSame(3, $scanner->scanDirectory($this->root, null, $this->libraryId)->added);
        self::assertFalse($this->orphanGateAnswersYes(), 'the fixture must start with nothing to adopt');

        $this->mintAdoptableOrphan();
        self::assertTrue($this->orphanGateAnswersYes(), 'THE PRECONDITION, asserted rather than assumed');
        self::assertFalse(
            $this->healGateAnswersYes(),
            'and ONLY $mayAdopt may be set here: an unhealed row as well would make this a duplicate '
            . 'of the test above instead of a test of the other half'
        );

        // The control, again: `canSkip()` is already false because $mayAdopt is true, so
        // an ordinary scan reads everything — and re-stamps everything.
        $scanner->resetProbes();
        $db->startLog();
        $scanner->scanDirectory($this->root, null, $this->libraryId);
        $db->stopLog();

        self::assertSame(3, $scanner->probeCount);
        self::assertSame(3, $db->countMatching(self::STAMP_SQL), 'the index is unloaded, so every probe re-stamps');

        $scanner->resetProbes();
        $db->startLog();
        $result = $scanner->scanDirectory($this->root, null, $this->libraryId, true);
        $db->stopLog();

        self::assertSame(3, $scanner->probeCount, 'THE REACH HALF');
        self::assertSame(
            0,
            $db->countMatching(self::STAMP_SQL),
            'THE POINT: RED under `if (!$mayAdopt && !$needsHealing)` with 3'
        );
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->failed);
        self::assertTrue(
            $this->orphanGateAnswersYes(),
            'the orphan was never adopted, so $mayAdopt was TRUE throughout both scans'
        );
    }

    /**
     * ⚠ **Review r1 finding 3 — a throw from the FLUSHED album\'s own recount must not
     * strand the vacated album\'s.**
     *
     * `flushAlbum()`\'s `finally` recounts the album being flushed and then drains the
     * vacated-album set. As straight-line calls, a throw from the first skipped the
     * second entirely: the emptied album kept a `total_tracks` too high **forever** — it
     * is never flushed again, so no later scan heals it — while
     * `MusicLibraryService::getArtistWithAlbums()` sums that column. Under S145 the
     * vacated recount ran inline inside the per-TRACK `try`/`catch`, so this window was a
     * regression introduced by the S148 deferral, not a pre-existing one.
     *
     * The fixture mis-parents by SQL rather than by retagging so that BOTH album ids
     * exist before the scan — the failure has to be injected on one specific id, and the
     * album a retag creates has no id until the scan has already run.
     *
     * Measured at `8862fca9`: the shell album stayed at `total_tracks = 3` while owning 0
     * rows, and the scan reported `failed = 0`.
     */
    public function testAThrowFromTheFlushedAlbumsOwnRecountStillRecountsTheVacatedAlbum(): void
    {
        $paths = $this->buildTree(['Album A' => 3]);
        $scanner = $this->scanner();
        $db = $this->connection();

        $scanner->scanDirectory($this->root, null, $this->libraryId);

        $albumA = $this->albumIdByTitle('Album A');
        $shell = $this->mintShellAlbum();

        // Move all three OFF Album A and onto the shell, by SQL, so the files on disk are
        // untouched and their stamps stay current — the production mis-parented shape.
        foreach ($paths as $path) {
            $db->query(
                'UPDATE music_tracks SET album_id = ? WHERE media_item_id = ?',
                [$shell, $this->mediaItemIdFor($path)],
            );
        }
        $db->query('UPDATE music_albums SET total_tracks = 3 WHERE id = ?', [$shell]);
        $db->query('UPDATE music_albums SET total_tracks = 0 WHERE id = ?', [$albumA]);

        // The healing pass flushes ALBUM A (the tags name it) and vacates the shell. Only
        // Album A's own recount is made to fail.
        $db->failOn(self::RECOUNT_SQL, $albumA);

        $db->startLog();
        $result = $scanner->scanDirectory($this->root, null, $this->libraryId, true);
        $db->stopLog();
        $db->clearFaults();

        self::assertSame(
            1,
            $this->recountsOf($db, $albumA),
            'the injected failure must actually have FIRED — without this the test could pass by the '
            . 'statement never being issued at all'
        );
        self::assertSame(3, $result->updated, 'all three tracks were still re-parented back');
        self::assertSame(3, $this->countTracksOnAlbum($albumA), 'and the rows really moved');

        self::assertSame(
            0,
            $this->albumTotal($shell),
            'THE POINT: the album the three tracks LEFT is recounted even though the recount before it '
            . 'in the same `finally` threw. RED at 8862fca9 with 3 — a permanent over-count on the '
            . 'artist page, reported as a clean scan'
        );

        self::assertSame(
            0,
            $this->albumTotal($albumA),
            'while Album A itself keeps the stale 0 the fixture set: its recount is the statement that '
            . 'was made to fail, and repairing THAT is not what this test claims'
        );
        self::assertSame(
            0,
            $result->failed,
            'and the accounting is unchanged by the fix — every file was written, so none is charged. '
            . 'Master b8a0bd7e reports the same 0 under the same injected throw; the fix is about the '
            . 'vacated count, not about the counters'
        );
    }

    /**
     * The stamp suppression must be driven by the RECORDED IDENTITY, not by the mode.
     *
     * One file of the three is touched between the two scans. A full read must then
     * issue **exactly one** stamp UPDATE — for that file and no other. A "never stamp
     * during a full read" shortcut would also make the headline test above pass, and
     * would silently stop the healing scan from recording the identities it just read,
     * so the NEXT ordinary scan would re-read the whole library forever.
     */
    public function testAFullReadStampsOnlyTheFileThatActuallyChanged(): void
    {
        $paths = $this->buildTree(['Album A' => 3]);
        $scanner = $this->scanner();
        $db = $this->connection();

        $scanner->scanDirectory($this->root, null, $this->libraryId);

        // Move ONE file's mtime without changing a tag: the identity moves, the tags do
        // not, so the row is unchanged but its stamp is stale.
        touch($paths[1], time() + 120);
        clearstatcache(true, $paths[1]);

        $scanner->resetProbes();
        $db->startLog();
        $scanner->scanDirectory($this->root, null, $this->libraryId, true);
        $db->stopLog();

        self::assertSame(3, $scanner->probeCount, 'a full read still reads all three');

        $stamps = $db->matching(self::STAMP_SQL);
        self::assertCount(
            1,
            $stamps,
            'exactly the file whose identity moved is re-stamped; the suppression must compare the '
            . 'recorded identity, not merely be switched off'
        );
        self::assertSame(
            $this->mediaItemIdFor($paths[1]),
            (string) ($stamps[0]['params'][2] ?? ''),
            'and it must be THAT file: the stamp UPDATE binds the media_items id last'
        );
    }

    /**
     * ⭐ **AC 2 — a retagged N-track album issues exactly ONE recount per vacated album.**
     *
     * Four tracks move off `Album A` in one flush. `refreshAlbumTrackTotal()` used to be
     * called from `upsertTrack()`, once per moved TRACK, so the row the album vacated was
     * recounted four times with four identical correlated `COUNT(*)`s.
     *
     * The fixture is built so a leaked predicate cannot accidentally produce the right
     * number: a SECOND album (`Album B`, 2 tracks) is present and untouched, so
     * "recount everything once" and "recount the vacated album once" are distinguishable,
     * and 4 ≠ 1 ≠ 2 ≠ 3 across the three album ids.
     *
     * RED against S145 (`8b953e82`): 4 recounts of `Album A`.
     */
    public function testARetaggedFourTrackAlbumRecountsTheVacatedAlbumExactlyOnce(): void
    {
        $paths = $this->buildTree(['Album A' => 4, 'Album B' => 2]);
        $scanner = $this->scanner();
        $db = $this->connection();

        $scanner->scanDirectory($this->root, null, $this->libraryId);

        $albumA = $this->albumIdByTitle('Album A');
        $albumB = $this->albumIdByTitle('Album B');
        self::assertSame(4, $this->albumTotal($albumA), 'the fixture must start with all four on Album A');
        self::assertSame(2, $this->albumTotal($albumB));

        foreach (array_slice($paths, 0, 4) as $path) {
            $this->retag($path, album: 'Album C');
        }

        $db->startLog();
        $result = $scanner->scanDirectory($this->root, null, $this->libraryId, true);
        $db->stopLog();

        $albumC = $this->albumIdByTitle('Album C');
        self::assertNotSame(0, $albumC, 'the retagged album row must exist');

        self::assertSame(
            1,
            $this->recountsOf($db, $albumA),
            'THE POINT: one recount of the album the four tracks LEFT. RED at 8b953e82 with 4 — '
            . 'refreshAlbumTrackTotal() was called from upsertTrack(), once per moved track, so an '
            . 'N-track album issued N identical recounts of one row'
        );
        self::assertSame(
            1,
            $this->recountsOf($db, $albumC),
            'and one for the album they arrived at — flushAlbum()\'s finally, unchanged by S148'
        );
        self::assertSame(
            1,
            $this->recountsOf($db, $albumB),
            'the untouched album is flushed by the full read and recounted once, so a mutant that '
            . 'recounts every album N times cannot hide behind the vacated-album number'
        );
        self::assertSame(
            3,
            $db->countMatching(self::RECOUNT_SQL),
            'three albums, three recounts, full stop'
        );

        // The counts themselves must still be right — a dedupe that dropped the recount
        // would also produce "1" for nothing.
        self::assertSame(0, $this->albumTotal($albumA), 'the vacated album owns nothing now');
        self::assertSame(4, $this->albumTotal($albumC));
        self::assertSame(2, $this->albumTotal($albumB));

        self::assertSame(4, $result->updated, 'exactly the four retagged tracks moved');
        self::assertSame(0, $result->failed);
        self::assertSame(6, $this->countTracks());
    }

    /**
     * The `reparented` summary counter, and the dedupe, must be independent.
     *
     * Four tracks move off one album. The recount is now deduped to one; `reparented`
     * must stay at **four**, because it answers "how many tracks moved?" and is the
     * operator's only evidence that a multi-hour healing pass repaired anything. A
     * dedupe applied to the counter as well would report 1 and understate the repair
     * by a factor of N.
     *
     * ⚠ The OTHER half of S148's counter fix — the `$existingAlbumId === 0` shape that
     * S145's shared `if/elseif` guard left uncounted — is **not reachable from a real
     * database**: `music_tracks.album_id` is `INT UNSIGNED NOT NULL` with an enforced FK
     * (migration 065), so no row can carry the coercion's absent-column 0. It is pinned
     * where it CAN be expressed, on the in-memory double, by
     * {@see \Phlix\Tests\Unit\Media\Music\MusicScanReparentTest::testATrackWhoseStoredAlbumIdReadsAsZeroIsStillCountedAsReparented()}.
     * Stating that here rather than writing a real-DB test that cannot fail — a check
     * that cannot fail is not evidence.
     */
    public function testEveryMovedTrackIsCountedAsReparentedEvenThoughTheRecountIsDeduped(): void
    {
        $paths = $this->buildTree(['Album A' => 4]);
        $logger = new RecordingLogger();
        $scanner = $this->scanner($logger);
        $db = $this->connection();

        $scanner->scanDirectory($this->root, null, $this->libraryId);

        foreach ($paths as $path) {
            $this->retag($path, album: 'Album C');
        }

        $db->startLog();
        $result = $scanner->scanDirectory($this->root, null, $this->libraryId, true);
        $db->stopLog();

        self::assertSame(4, $result->updated);

        $summary = $logger->contextOf('Music directory scan complete');
        self::assertIsArray($summary);
        self::assertSame(
            4,
            $summary['reparented'] ?? null,
            'all four moved, so all four are reported — the counter answers "did this track move?", '
            . 'never "is there an album row to recount?"'
        );
        self::assertSame(
            1,
            $this->recountsOf($db, $this->albumIdByTitle('Album A')),
            'and the four moves still cost ONE recount of the album they vacated'
        );
        self::assertTrue($summary['read_every_file'] ?? null);
        self::assertSame(
            4,
            $summary['skip_index_entries'] ?? null,
            'the summary reports a populated index — ⚠ DOCUMENTATION, NOT A KILLER, and measured as '
            . 'such: MusicScanSkipIndex::remember() adds an entry after every stamp write, so an '
            . 'UNLOADED index ends a 4-file scan holding 4 entries too. Re-applying S145\'s load gate '
            . 'leaves THIS assertion green. The discriminator is the UPDATE count above'
        );
    }

    /**
     * ⭐ **THE REACH GUARANTEE, against real rows.** A track whose file NEVER changes and
     * whose stamp is intact is still healed by a full read — with the index now LOADED.
     *
     * This is the test that fails if S148's fix were implemented by letting the loaded
     * index suppress READS as well as stamps. The row is mis-parented by direct SQL, so
     * the file on disk is byte-identical and its recorded `(mtime, size)` matches exactly
     * — precisely the production state S145 measured on 29,134 of 61,111 tracks.
     */
    public function testTheLoadedIndexStillHealsAMisParentedTrackWhoseFileNeverChanged(): void
    {
        $paths = $this->buildTree(['Album A' => 3]);
        $scanner = $this->scanner();
        $db = $this->connection();

        $scanner->scanDirectory($this->root, null, $this->libraryId);

        $rightAlbum = $this->albumIdByTitle('Album A');
        $shell = $this->mintShellAlbum();
        $db->query(
            'UPDATE music_tracks SET album_id = ? WHERE media_item_id = ?',
            [$shell, $this->mediaItemIdFor($paths[0])],
        );
        $db->query(
            'UPDATE music_albums a SET a.total_tracks ='
            . ' (SELECT COUNT(*) FROM music_tracks t WHERE t.album_id = a.id) WHERE a.id IN (?, ?)',
            [$shell, $rightAlbum],
        );
        self::assertSame(1, $this->albumTotal($shell), 'the fixture must reproduce the shell-album state');
        self::assertSame(2, $this->albumTotal($rightAlbum));

        // An ORDINARY scan must NOT heal it — the file is unchanged and stamped, so it is
        // never opened. Asserting the unhealed outcome is what proves the full-read mode
        // is load-bearing rather than decorative.
        $scanner->resetProbes();
        $scanner->scanDirectory($this->root, null, $this->libraryId);
        self::assertSame(
            $shell,
            $this->albumIdOfTrack($paths[0]),
            'an ordinary scan cannot repair a row it never reads'
        );

        $scanner->resetProbes();
        $db->startLog();
        $result = $scanner->scanDirectory($this->root, null, $this->libraryId, true);
        $db->stopLog();

        self::assertSame(3, $scanner->probeCount, 'the loaded index must NOT suppress a single read');
        self::assertSame(
            $rightAlbum,
            $this->albumIdOfTrack($paths[0]),
            'THE REACH GUARANTEE: the track is filed back under the album its tags name'
        );
        self::assertSame(3, $this->albumTotal($rightAlbum));
        self::assertSame(0, $this->albumTotal($shell), 'and the album it left is recounted, once');
        self::assertSame(1, $this->recountsOf($db, $shell));
        self::assertSame(1, $result->updated, 'exactly the mis-parented row moved');

        // The other two files were read, found correct, and their stamps were current —
        // so the healing pass rewrote nothing it did not have to.
        self::assertSame(
            0,
            $db->countMatching(self::STAMP_SQL),
            'no file changed on disk, so no stamp may be rewritten even on the pass that repaired a row'
        );
    }

    /**
     * How many recounts targeted one album id.
     *
     * @param RecordingMySqlConnection $db      Recording connection.
     * @param int                      $albumId `music_albums.id`.
     * @return int
     */
    private function recountsOf(RecordingMySqlConnection $db, int $albumId): int
    {
        $n = 0;
        foreach ($db->matching(self::RECOUNT_SQL) as $entry) {
            if ((int) ($entry['params'][0] ?? 0) === $albumId) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Rewrites the ID3v2 tag of `$path` in place, changing only what is asked for.
     *
     * Deliberately a copy of {@see MusicRetagReparentIntegrationTest::retag()} rather
     * than a shared base class: that file is S145's acceptance proof and must stay
     * readable on its own. Both write through the real `getid3_writetags`, so the tags
     * the scanner reads back are tags a tag editor really wrote.
     *
     * @param string      $path   Absolute path to the MP3 to rewrite.
     * @param string|null $album  New ALBUM value, or NULL to keep the existing one.
     * @param string|null $artist New ARTIST value, or NULL to keep the existing one.
     * @return void
     */
    private function retag(string $path, ?string $album = null, ?string $artist = null): void
    {
        // Instantiating getID3 first is REQUIRED: `write.php` throws
        // "getid3.php MUST be included before calling getid3_writetags".
        $reader = new \getID3();
        $info = $reader->analyze($path);
        $tags = is_array($info['tags']['id3v2'] ?? null) ? $info['tags']['id3v2'] : [];

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
    }

    /**
     * Copies the real MP3 fixture once per requested track and tags each copy with its
     * album, returning the paths in WALK order.
     *
     * @param array<string, int> $albums Album title => number of tracks.
     * @return list<string> Walk-ordered absolute paths.
     */
    private function buildTree(array $albums): array
    {
        $this->root = sys_get_temp_dir() . '/phlix_s148_it_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
        $this->cleanup[] = $this->root;

        $fixture = dirname(__DIR__, 2) . '/Fixtures/Media/Music/tagged-short.mp3';
        self::assertFileExists($fixture);

        $n = 0;
        foreach ($albums as $title => $tracks) {
            for ($i = 1; $i <= $tracks; $i++) {
                $n++;
                // Numbered across ALL albums so walk order is the array order above —
                // `RecursiveDirectoryIterator` is not sorted, so the paths are re-derived
                // from a real walk below rather than assumed.
                $path = $this->root . '/track-' . sprintf('%02d', $n) . '.mp3';
                copy($fixture, $path);
                $this->cleanup[] = $path;
                $this->retag($path, album: $title);
            }
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
        sort($out);
        self::assertCount($n, $out);

        return $out;
    }

    /**
     * A zero-track album row owned by this fixture's artist — the production shell shape.
     *
     * ⚠ **It gets its own `media_items` row, and that is not decoration.** A
     * `music_albums` row with `media_item_id IS NULL` makes
     * `MusicLibraryScanner::hasUnhealedMusicMediaItem()` answer TRUE (it is deliberately
     * NOT scoped to a library — those tables have no `library_id`), which switches the
     * S122(a) fast path off for the WHOLE box. The "an ordinary scan cannot repair a row
     * it never reads" assertion then fails, not because the scanner is wrong but because
     * the fixture disabled the very skip it is asserting on. Measured: without this the
     * ordinary scan healed the row.
     *
     * That is a property of THIS fixture only. The `$needsHealing === true` case has its
     * own test — {@see self::testAFullReadOfAnUnhealedLibraryIssuesNoStampUpdates()},
     * which mints the NULL-`media_item_id` row on purpose — so nothing is left uncovered
     * by the choice made here.
     */
    private function mintShellAlbum(): int
    {
        $db = $this->connection();
        $artistId = (int) $this->scalar(
            'SELECT id AS v FROM music_artists WHERE name LIKE ? LIMIT 1',
            [$this->prefix . '%'],
        );
        self::assertNotSame(0, $artistId);

        $mediaItemId = Uuid::v4();
        $db->query(
            'INSERT INTO media_items (id, library_id, type, name, path, metadata_json, created_at, updated_at)'
            . " VALUES (?, ?, 'album', ?, '', '{}', NOW(), NOW())",
            [$mediaItemId, $this->libraryId, $this->prefix . 'Shell Album'],
        );
        $db->query(
            'INSERT INTO music_albums (artist_id, title, total_tracks, media_item_id) VALUES (?, ?, 0, ?)',
            [$artistId, $this->prefix . 'Shell Album', $mediaItemId],
        );

        return (int) $this->scalar(
            'SELECT id AS v FROM music_albums WHERE artist_id = ? AND title = ?',
            [$artistId, $this->prefix . 'Shell Album'],
        );
    }

    /**
     * A `music_albums` row with `media_item_id IS NULL` — the S96(e) unhealed shape, and
     * the thing that makes `MusicLibraryScanner::hasUnhealedMusicMediaItem()` answer TRUE.
     *
     * ⚠ Its title matches nothing the walk flushes, so `upsertAlbum()` never reaches it
     * and the heal never lands: the flag stays TRUE for the whole scan, which is the
     * condition under test. (`media_item_id` is `NULL UNIQUE` per migration 065, and
     * NULLs do not collide, so more than one such row is legal.)
     *
     * @return void
     */
    private function mintUnhealedAlbum(): void
    {
        $artistId = (int) $this->scalar(
            'SELECT id AS v FROM music_artists WHERE name LIKE ? LIMIT 1',
            [$this->prefix . '%'],
        );
        self::assertNotSame(0, $artistId, 'the first scan must have created the fixture artist');

        $this->connection()->query(
            'INSERT INTO music_albums (artist_id, title, total_tracks, media_item_id) VALUES (?, ?, 0, NULL)',
            [$artistId, $this->prefix . 'Unhealed Album'],
        );
    }

    /**
     * An `artist`-typed `media_items` row with `path = ''` that no `music_artists` row
     * points at — what `MusicLibraryScanner::hasAdoptableMusicMediaItem()` reads, and the
     * only thing that makes `$mayAdopt` TRUE.
     *
     * ⚠ The name is deliberately one the walk can never produce, so
     * `findAdoptableArtistMediaItemId()` (which matches on `media_items.name`) misses it
     * and it is never adopted. An orphan that gets adopted mid-scan would flip the very
     * flag the test is about.
     *
     * `path = ''` leaves `path_hash` NULL for every generated-column definition this repo
     * has shipped (migrations 072/087 hash only non-empty paths of file-backed types), so
     * this row cannot collide on `(library_id, path_hash)` with the shell album's row.
     *
     * @return void
     */
    private function mintAdoptableOrphan(): void
    {
        $this->connection()->query(
            'INSERT INTO media_items (id, library_id, type, name, path, metadata_json, created_at, updated_at)'
            . " VALUES (?, ?, 'artist', ?, '', '{}', NOW(), NOW())",
            [Uuid::v4(), $this->libraryId, $this->prefix . 'Orphan Artist Matching No Tag'],
        );
    }

    /**
     * Runs `hasUnhealedMusicMediaItem()`'s statement verbatim, so a test can assert the
     * gate it depends on rather than assume it.
     */
    private function healGateAnswersYes(): bool
    {
        $rows = $this->connection()->query(
            'SELECT 1 AS unhealed FROM music_artists WHERE media_item_id IS NULL'
            . ' UNION ALL SELECT 1 AS unhealed FROM music_albums WHERE media_item_id IS NULL LIMIT 1',
            [],
        );

        return is_array($rows) && count($rows) > 0;
    }

    /** The same, for `hasAdoptableMusicMediaItem()`'s statement, scoped to this library. */
    private function orphanGateAnswersYes(): bool
    {
        $rows = $this->connection()->query(
            'SELECT mi.id FROM media_items mi'
            . ' LEFT JOIN music_artists ar ON ar.media_item_id = mi.id'
            . ' LEFT JOIN music_albums al ON al.media_item_id = mi.id'
            . " WHERE mi.type IN ('artist', 'album') AND mi.path = ''"
            . ' AND mi.library_id <=> ? AND ar.id IS NULL AND al.id IS NULL LIMIT 1',
            [$this->libraryId],
        );

        return is_array($rows) && count($rows) > 0;
    }

    /** How many `music_tracks` rows currently sit on an album. */
    private function countTracksOnAlbum(int $albumId): int
    {
        return (int) $this->scalar('SELECT COUNT(*) AS v FROM music_tracks WHERE album_id = ?', [$albumId]);
    }

    /** `music_albums.total_tracks` as persisted. */
    private function albumTotal(int $albumId): int
    {
        return (int) $this->scalar('SELECT total_tracks AS v FROM music_albums WHERE id = ?', [$albumId]);
    }

    /** The id of this fixture's album with the given (unprefixed) title, or 0. */
    private function albumIdByTitle(string $title): int
    {
        return (int) $this->scalar(
            'SELECT a.id AS v FROM music_albums a JOIN music_artists ar ON ar.id = a.artist_id'
            . ' WHERE a.title = ? AND ar.name LIKE ?',
            [$this->prefix . $title, $this->prefix . '%'],
        );
    }

    /** `music_tracks.album_id` for a file. */
    private function albumIdOfTrack(string $path): int
    {
        return (int) $this->scalar(
            'SELECT t.album_id AS v FROM music_tracks t JOIN media_items mi ON mi.id = t.media_item_id'
            . ' WHERE mi.library_id = ? AND mi.path = ?',
            [$this->libraryId, $path],
        );
    }

    /** `media_items.id` for a file. */
    private function mediaItemIdFor(string $path): string
    {
        $id = (string) $this->scalar(
            'SELECT id AS v FROM media_items WHERE library_id = ? AND path = ?',
            [$this->libraryId, $path],
        );
        self::assertNotSame('', $id, 'no media_items row for ' . $path);

        return $id;
    }

    /**
     * @param string $sql    Statement selecting a single column aliased `v`.
     * @param array<int, mixed> $params Bound parameters.
     * @return mixed The first row's `v`, or 0 when there is no row.
     */
    private function scalar(string $sql, array $params): mixed
    {
        $rows = $this->connection()->query($sql, $params);

        return is_array($rows) && is_array($rows[0] ?? null) ? ($rows[0]['v'] ?? 0) : 0;
    }

    private function countTracks(): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) AS v FROM music_tracks t'
            . ' JOIN media_items mi ON mi.id = t.media_item_id WHERE mi.library_id = ?',
            [$this->libraryId],
        );
    }

    /** The recording connection, non-null. */
    private function connection(): RecordingMySqlConnection
    {
        $db = $this->db;
        self::assertNotNull($db);

        return $db;
    }

    /**
     * The shared probe-counting scanner, wired to the RECORDING connection.
     *
     * @param RecordingLogger|null $logger Captures the completion summary when a test
     *        needs to assert on `reparented` / `skip_index_entries`.
     */
    private function scanner(?RecordingLogger $logger = null): ProbeCountingIntegrationScanner
    {
        $scanner = new ProbeCountingIntegrationScanner($this->connection(), new FfmpegRunner(), $logger);
        $scanner->artistPrefix = $this->prefix;

        return $scanner;
    }

    private function purgeFixtures(): void
    {
        $db = $this->db;
        if ($db === null) {
            return;
        }

        $db->recording = false;
        // music_albums/music_tracks cascade off music_artists (migration 065).
        $db->query('DELETE FROM music_artists WHERE name LIKE ?', [$this->prefix . '%']);
        if ($this->libraryId !== '') {
            $db->query('DELETE FROM media_items WHERE library_id = ?', [$this->libraryId]);
            $db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }
    }
}
