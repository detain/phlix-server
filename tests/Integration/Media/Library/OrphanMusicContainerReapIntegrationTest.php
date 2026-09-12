<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Library;

use Phlix\Common\Uuid;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Tests\Integration\Media\ProbeCountingIntegrationScanner;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S153 [CS153ORPHANREAPX9D5] — a healing rescan must leave `music_albums` /
 * `music_artists` CLEANER than it found them, instead of trading one class of
 * orphan for another.
 *
 * ## The measured defect (production, 2026-07-27, job 238ba78d)
 *
 * A complete, successful full-read rescan of the production Music library
 * (`items_found = items_updated = 61135`, `items_removed = 0`, `items_failed = 0`):
 *
 * | metric                 | before | after   | delta |
 * |------------------------|--------|---------|-------|
 * | shell albums (0 tracks)| 500    | 450     | −50 ✅ |
 * | empty artists          | 13     | 16      | +3 ⚠  |
 * | `music_albums` rows    | 11,535 | 11,596  | +61 ⚠ |
 *
 * S145's repair re-parents a mis-filed track onto the album/artist its tags name
 * ({@see MusicLibraryScanner::upsertTrack()} — the widened `UPDATE … SET album_id = ?,
 * artist_id = ?`), and mints the correctly-named album when it is absent
 * ({@see MusicLibraryScanner::upsertAlbum()} — that mint is the +61). The row the
 * track VACATED is recounted by the S148 `vacatedAlbums` set and then never
 * removed: `refreshAlbumTrackTotal()` sets `total_tracks = 0` and that is the end
 * of its involvement. Each healing pass therefore fills shells and mints new
 * ones. The repair is idempotent for track parentage but NOT for container rows,
 * so a repeatedly retagged library accumulates shells indefinitely.
 *
 * ## The fix this file pins
 *
 * A distinct reap pass inside the existing `prune` job home (migration 084) plus
 * an automatic run at the end of `rescan` — gated (every gate reddens its own
 * case below):
 *  1. library type `music` only (the `music_*` tables are the music hierarchy);
 *  2. EVERY configured root currently `is_dir()` — an album whose files live on an
 *     unmounted root can legitimately show zero tracks right now, and the
 *     `music_*` tables carry no `library_id`, so the pass cannot attribute a
 *     shell to a root and must refuse globally (the same per-root presence
 *     discipline `pruneRemovedItems()` applies per leaf);
 *  3. failure evidence — the rescan path passes its own fresh `items_failed`
 *     count; the standalone `prune` path consults the latest COMPLETED
 *     `scan`/`rescan` job row (`items_failed` column, migration 095) and skips
 *     when it is absent or non-zero; a `scan`/`rescan` row still `queued`/`running`
 *     also refuses the pass (the prune's own row is excluded — the pass runs
 *     inside it);
 *  4. 🔴 the CASCADE landmine: `fk_tracks_album` / `fk_tracks_artist` /
 *     `fk_albums_artist` are all `ON DELETE CASCADE` (migration 065), so one
 *     over-selected row destroys live tracks. The pass deletes per id with the
 *     zero-tracks predicate RE-PROVEN inside the DELETE statement itself, so no
 *     execution of that statement can cascade a track — and case 2 below is the
 *     fixture the step spec demands: a shell album and a populated album that
 *     differ by exactly one track.
 *
 * ## Why a real database
 *
 * An in-memory double cannot express a CASCADE — the AC says so, and it is the
 * same M10 blindness class that escaped once in this program. Every "no
 * populated album is ever selected" claim here reads the table state back from a
 * real MySQL server after real statements have run against it. With none
 * reachable the whole file self-skips; CI runs it for real.
 *
 * ## Purity of the pass
 *
 * Deleting `music_albums`/`music_artists` rows never touches `media_items`
 * (explicitly out of S153's scope): the FKs run the other way (`SET NULL` from
 * the container row toward `media_items.id`), so an anchor row outlives the
 * container that referenced it — case 2 asserts exactly that.
 */
final class OrphanMusicContainerReapIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    private const SKIP_REASON = 'skipping the S153 orphan-container reap test. Runs in CI.';

    /**
     * S486 — teardown-race sentinel. Names this lane in every cleanup failure
     * this file raises, so a leftover `phlix_s153_it_*` from the zero-residue
     * census (or a loud teardown `fail()`) attributes to this class and nobody
     * has to guess which lane regressed. Mirrors the S460 lane-sentinel shape.
     */
    private const S486_LANE_SENTINEL = 'S486FIXREAPX9P4';

    private ?Connection $db = null;

    /** The music library under test. */
    private string $libraryId = '';

    /** Marker prefix on every artist/album/item name this test writes (purge handle). */
    private string $prefix = '';

    /** Main fixture root (a real directory containing the mp3 copies). */
    private string $root = '';

    /** @var list<string> Extra library ids to purge (attribution / root-absence cases). */
    private array $extraLibraryIds = [];

    /** @var list<string> Directories created by the test (unlink children first). */
    private array $cleanupDirs = [];

    /** @var list<string> Extra files (renamed poisons) to remove. */
    private array $cleanupFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase(self::SKIP_REASON);

        $this->libraryId = Uuid::v4();
        $this->prefix = '!S153-' . substr(Uuid::v4(), 0, 8) . '-';

        // S486: register BEFORE creating, and create guarded — a directory that
        // exists is always on the cleanup list, and a `mkdir()` that loses a
        // name collision can no longer emit its own unsuppressed warning.
        $this->root = sys_get_temp_dir() . '/phlix_s153_it_' . bin2hex(random_bytes(6));
        $this->makeCleanupDirectory($this->root);

        $this->insertLibrary($this->libraryId, [$this->root]);
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null) {
            // music_albums / music_tracks cascade off music_artists (migration 065);
            // every artist this file creates — scanned or seeded — carries the prefix.
            $db->query('DELETE FROM music_artists WHERE name LIKE ?', [$this->prefix . '%']);
            foreach (array_merge([$this->libraryId], $this->extraLibraryIds) as $libraryId) {
                $db->query('DELETE FROM media_items WHERE library_id = ?', [$libraryId]);
                $db->query('DELETE FROM library_scan_jobs WHERE library_id = ?', [$libraryId]);
                $db->query('DELETE FROM libraries WHERE id = ?', [$libraryId]);
            }
        }

        // S486 — teardown was racing itself. `testHealingRescan…` registers the
        // renamed file in `$cleanupFiles` while its parent directory sits in
        // `$cleanupDirs`, and the directory pass unlinks every child first. The
        // old blind `@chmod()`/`@unlink()` over the files list then called both
        // functions on a path that was already gone — under the serial printers
        // the `@` hides that (PHPUnit's collector drops suppressed warnings), but
        // the parallel gate's paraunit PRINTER surfaces suppressed PHP events,
        // which is exactly the `chmod(): No such file or directory` +
        // `unlink(…/s153-renamed-ok.mp3)` pair CI showed. There is no
        // asynchronous remover in this class — the race was same-process,
        // deterministic double-cleanup — so an existence guard is complete
        // protection here, and every removal that is ATTEMPTED is verified
        // afterwards: a file we could not delete fails loudly naming the lane
        // sentinel instead of stranding a `phlix_s153_it_*` for the census to
        // blame anonymously. Registered files first (children by name), then the
        // directory sweep (whatever is left, then the directory itself).
        foreach ($this->cleanupFiles as $file) {
            $this->discardPath($file);
        }
        foreach ($this->cleanupDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->discardPath($dir . '/' . $entry);
            }
            if (!@rmdir($dir) && $this->pathStillThere($dir)) {
                // rmdir's own verdict is the signal; the re-check separates
                // "someone else already removed it" (tolerate — that is the
                // race) from "it is still here" (fail — a child is not a plain
                // file, or the unlink genuinely failed). Silence here is what
                // makes the census anonymous; it must name its cause.
                $this->fail(
                    self::S486_LANE_SENTINEL . ': cleanup FAILED — directory ' . $dir
                    . ' could not be removed after its children were discarded (check ' . $dir . ')'
                );
            }
        }

        $this->cleanupDirs = [];
        $this->cleanupFiles = [];
        $this->extraLibraryIds = [];

        parent::tearDown();
    }

    /**
     * Register, then create — guarded. `@mkdir` plus the `is_dir` re-check on
     * both sides means a pre-existing name is accepted, and a genuine failure
     * fails HERE with the path instead of emitting a raw `mkdir(): File exists`
     * warning and leaving later filesystem steps to stumble over it.
     */
    private function makeCleanupDirectory(string $dir): void
    {
        $this->cleanupDirs[] = $dir;

        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            $this->fail(self::S486_LANE_SENTINEL . ': setUp FAILED — could not create ' . $dir);
        }
    }

    /**
     * Remove one path if it is there; verify the removal if it was.
     *
     * The entry guard is the race fix — `chmod()`/`unlink()` are never called on
     * a path that does not exist, which is the only state this class's own
     * double-registration produces. The post-attempt check is the census fix —
     * a path that WAS there and is STILL there means the teardown genuinely
     * failed, and that must name itself rather than surface as an anonymous
     * leftover temp directory several suites later.
     */
    private function discardPath(string $path): void
    {
        if (!file_exists($path)) {
            return; // already gone (double registration, or removed with its directory)
        }
        if (is_dir($path)) {
            return; // left for the directory pass, which fails loudly if it cannot remove it
        }
        @chmod($path, 0o644);
        @unlink($path);
        if ($this->pathStillThere($path)) {
            $this->fail(self::S486_LANE_SENTINEL . ': cleanup FAILED — ' . $path . ' still exists after unlink');
        }
    }

    /**
     * A deliberately fresh stat — PHP's own stat cache would otherwise let a
     * pre-attempt `is_dir()` answer this question from memory instead of from
     * the filesystem, and the point of the post-attempt check is to ask the
     * filesystem.
     */
    private function pathStillThere(string $path): bool
    {
        clearstatcache(true, $path);

        return is_dir($path) || is_file($path) || is_link($path);
    }

    /**
     * HEADLINE — the production fingerprint, then its reversal.
     *
     * Three tracks filed under album A1 / artist X are retagged (real ID3v2 writes)
     * to album A2 / artist Y and the library takes a healing rescan. That is
     * exactly the production shape: the repair MOVES the tracks (filling nothing —
     * A2 starts empty and receives them) and MINTS A2/Y, while A1 and X are left
     * vacated. Pre-fix this assertion block is RED the way production was RED:
     * two albums, one of them a shell, and two artists, one of them empty — the
     * +61-rows fingerprint — and it compounds on every repeat of the pass.
     *
     * Post-fix: one album, one artist, three track rows with UNCHANGED ids (the
     * tracks are re-parented, never re-minted), zero shells.
     */
    public function testAHealingRescanLeavesTheMusicTablesCleanerThanItFoundThem(): void
    {
        $paths = $this->copyFixtureTracks(3);
        $manager = $this->manager();

        $first = $manager->rescanLibrary($this->libraryId);
        // `rescanLibrary()`'s `added` is the ROW-COUNT DELTA over all media_items
        // types (see its docblock): 3 track leaves + the album and artist containers.
        self::assertSame(5, $first->added);
        self::assertSame(0, $first->failed);
        self::assertSame(1, $this->countRunAlbums(), 'one album after the indexing scan');
        self::assertSame(1, $this->countRunArtists());

        $before = $this->trackIdMap();

        foreach ($paths as $path) {
            $this->retag($path, album: 'Refiled Album', artist: 'Refiled Artist');
        }

        $healing = $manager->rescanLibrary($this->libraryId);
        self::assertSame(0, $healing->failed, 'the healing pass must have seen zero read failures');

        self::assertSame(
            1,
            $this->countRunAlbums(),
            'RED pre-fix (the production fingerprint): the vacated album A1 survives at zero tracks while '
            . 'the tag-named album A2 is minted — 11,596 music_albums rows, 450 of them shells, after a '
            . 'single successful healing rescan on 2026-07-27'
        );
        self::assertSame(
            1,
            $this->countRunArtists(),
            'RED pre-fix: the production run took ZERO-TRACK artists from 13 to 16 — the vacated '
            . 'artist X loses every track to Y and only the reap (wave 1 first, then wave 2) removes '
            . 'it; pre-fix it survives holding the shell album the pass just emptied'
        );
        self::assertSame(
            0,
            $this->countArtistsWithZeroTracks(),
            'the production metric itself — artists with zero tracks, whatever they still own — '
            . 'RED pre-fix for the same reason'
        );
        self::assertSame(
            0,
            $this->countEmptyArtists(),
            'strictest reading: no artist of this run is left with neither tracks nor albums'
        );
        self::assertSame(
            0,
            $this->countShellAlbums(),
            'S153 [CS153ORPHANREAPX9D5]: shell albums must DECREASE to zero, not merely improve'
        );
        self::assertSame(0, $this->countEmptyArtists());

        $after = $this->trackIdMap();
        self::assertSame(
            array_keys($before),
            array_keys($after),
            'music_tracks rows are re-parented, never destroyed and re-minted — same ids'
        );
        self::assertSame(3, array_sum($after));
        $survivorAlbum = $this->albumIdByTitle($this->prefix . 'Refiled Album');
        self::assertGreaterThan(0, $survivorAlbum);
        foreach (array_keys($after) as $trackId) {
            self::assertSame(
                $survivorAlbum,
                $this->trackAlbumId($trackId),
                'every healed track must point at the surviving album'
            );
        }
    }

    /**
     * THE LANDMINE CASE — a shell album and a populated album differing by exactly
     * ONE track. The step spec demands the fixture because `fk_tracks_album` is
     * `ON DELETE CASCADE`: a selection predicate that is one row too wide destroys
     * a real album's tracks, not the row it meant to prune.
     *
     * Album A (one track) and its artist X both survive; shell S (zero tracks, its
     * own `media_items` anchor in this library) is reaped; empty artist E (anchor
     * present, no albums, no tracks) is reaped — the E case exercises wave 2's own
     * predicate, and a shell attributed to ANOTHER library must be left alone
     * (this pass runs for library A and may not touch B's hierarchy).
     *
     * The reap is driven through the public `pruneLibrary()` entry — the `prune`
     * job home — with a clean completed scan row as its failure evidence.
     */
    public function testReapRemovesTheShellNotThePopulatedAlbumThatDiffersByExactlyOneTrack(): void
    {
        $this->copyFixtureTracks(1);
        self::assertSame(
            3,
            $this->manager()->rescanLibrary($this->libraryId)->added,
            "1 leaf + album/artist containers = 3 media_items rows"
        );

        $populatedAlbum = $this->albumIdByTitle($this->prefix . 'Measured Album');
        self::assertGreaterThan(0, $populatedAlbum, 'the scan must have filed the track under its tag album');
        $artistX = $this->artistIdByName($this->prefix . 'Measured Artist');

        $shellAnchor = $this->seedMediaItem('album', $this->prefix . 'Shell Anchor');
        $shellId = $this->seedShellAlbum($artistX, $this->prefix . 'Shell', $shellAnchor);
        // The UNANCHORED shape (media_item_id NULL — the mint-failure row S96(e)
        // heals) is pinned only here: it is reap-eligible for any qualifying pass,
        // so it stays out of the gate cases' seeds.
        $unanchoredShellId = $this->seedShellAlbum($artistX, $this->prefix . 'Shell Unanchored', null);

        $emptyArtistAnchor = $this->seedMediaItem('artist', $this->prefix . 'Empty Artist Anchor');
        $emptyArtistId = $this->seedArtist($this->prefix . 'Empty Artist', $emptyArtistAnchor);

        // A shell attributed to a DIFFERENT library: its anchor media_items row is in B.
        $otherLibrary = Uuid::v4();
        $this->extraLibraryIds[] = $otherLibrary;
        $this->insertLibrary($otherLibrary, []);
        $foreignAnchor = $this->seedMediaItem('album', $this->prefix . 'Foreign Anchor', $otherLibrary);
        $foreignShellId = $this->seedShellAlbum($artistX, $this->prefix . 'Foreign Shell', $foreignAnchor);

        $this->seedCompletedScanJob(0);

        $removed = $this->manager()->pruneLibrary($this->libraryId);

        self::assertSame(
            3,
            $removed,
            'the prune total now includes reaped music containers: shell S + unanchored shell + empty '
            . 'artist E (the job row items_removed must not silently undercount what the pass deleted)'
        );

        self::assertSame(
            1,
            $this->countRows('SELECT id FROM music_albums WHERE id = ?', [$populatedAlbum]),
            'RED under a predicate that drops the NOT EXISTS(tracks) clause: the populated album is '
            . 'one track away from the shell and CASCADE would have deleted its track'
        );
        self::assertSame(
            1,
            $this->countTracksScoped(),
            'music_tracks count UNCHANGED — the AC; a cascade deletion shows up right here'
        );
        self::assertSame(
            $populatedAlbum,
            $this->trackAlbumId($this->onlyTrackId()),
            'the surviving track still points at the surviving album'
        );

        self::assertSame(0, $this->countRows('SELECT id FROM music_albums WHERE id = ?', [$shellId]));
        self::assertSame(0, $this->countRows('SELECT id FROM music_artists WHERE id = ?', [$emptyArtistId]));
        self::assertSame(
            0,
            $this->countRows('SELECT id FROM music_albums WHERE id = ?', [$unanchoredShellId]),
            'the unanchored (media_item_id NULL) mint-failure shape is reaped too'
        );
        self::assertSame(
            1,
            $this->countRows('SELECT id FROM music_albums WHERE id = ?', [$foreignShellId]),
            'the pass is library-scoped by attribution: a shell anchored in another library is not '
            . 'this library\'s evidence-backed orphan, and B\'s roots were never verified'
        );
        self::assertSame(
            1,
            $this->countRows('SELECT id FROM media_items WHERE id = ?', [$shellAnchor]),
            'reaping never deletes media_items rows (S153 out-of-scope): the FK runs container → '
            . 'media_items with SET NULL, so the anchor row outlives the album that named it'
        );
    }

    /**
     * Gate 3a (standalone): the latest COMPLETED scan/rescan row carried
     * `items_failed > 0`. A scan that could not read every file leaves rows behind
     * that LOOK like orphans but are files on temporarily unreadable storage —
     * reaping straight after such a scan deletes real catalogue data (step spec,
     * landmine 2). The prune still returns 0 and touches nothing.
     */
    public function testReapIsSkippedWhenTheLatestCompletedScanReportedFailures(): void
    {
        $this->copyFixtureTracks(1);
        self::assertSame(
            3,
            $this->manager()->rescanLibrary($this->libraryId)->added,
            "1 leaf + album/artist containers = 3 media_items rows"
        );

        $artistX = $this->artistIdByName($this->prefix . 'Measured Artist');
        $shellAnchor = $this->seedMediaItem('album', $this->prefix . 'Shell Anchor');
        $shellId = $this->seedShellAlbum($artistX, $this->prefix . 'Shell', $shellAnchor);

        $this->seedCompletedScanJob(3);

        $removed = $this->manager()->pruneLibrary($this->libraryId);

        self::assertSame(0, $removed, 'a dirty read-history must refuse the reap entirely');
        self::assertSame(
            1,
            $this->countRows('SELECT id FROM music_albums WHERE id = ?', [$shellId]),
            'RED if the items_failed gate is removed from the standalone prune path'
        );
        self::assertSame(1, $this->countTracksScoped(), 'and obviously no tracks may cascade away');
    }

    /**
     * Gate 2 — the pass refuses while ANY configured root is absent, and the same
     * seeds are reaped the moment the root returns (presence, not a permanent
     * latch).
     *
     * Why the gate exists even though `pruneRemovedItems()` already spares rows on
     * dark roots: the two passes read different evidence. The leaf pass checks each
     * FILE; the container pass has no files to check (that is what "zero tracks"
     * means), so its only honest signal that the library is fully observable is
     * every configured root answering `is_dir()`. The step spec names exactly this
     * pair — `items_failed = 0` AND root presence — and a shell minted while one
     * root is dark (partial read failure on the present root, an album vacated last
     * week by retags whose files now sit under the dark one's mountpoint) must not
     * be deleted on evidence the unobservable half of the library could not
     * contribute to.
     */
    public function testReapIsSkippedWhenAnyConfiguredRootIsAbsent(): void
    {
        $this->copyFixtureTracks(1);
        self::assertSame(3, $this->manager()->rescanLibrary($this->libraryId)->added, '1 leaf + containers');

        // A second, currently ABSENT configured root (never created — the
        // mountpoint leftover that is outright missing; `is_dir()` is false).
        $ghost = $this->root . '-ghost';
        $this->db()->query('UPDATE libraries SET paths = ? WHERE id = ?', [
            json_encode([$this->root, $ghost]),
            $this->libraryId,
        ]);

        $artistX = $this->artistIdByName($this->prefix . 'Measured Artist');
        $shellAnchor = $this->seedMediaItem('album', $this->prefix . 'Shell Anchor');
        $shellId = $this->seedShellAlbum($artistX, $this->prefix . 'Shell', $shellAnchor);
        $this->seedCompletedScanJob(0);

        $manager = $this->manager();
        self::assertSame(
            0,
            $manager->pruneLibrary($this->libraryId),
            'RED if the all-roots-present gate is removed: the library is not fully observable and '
            . 'a container-shaped orphan may simply be storage that is dark right now'
        );
        self::assertSame(1, $this->countRows('SELECT id FROM music_albums WHERE id = ?', [$shellId]));

        $this->makeCleanupDirectory($ghost);

        self::assertSame(
            1,
            $manager->pruneLibrary($this->libraryId),
            'with every root present the same seed IS reaped — the gate is presence, not paranoia'
        );
        self::assertSame(0, $this->countRows('SELECT id FROM music_albums WHERE id = ?', [$shellId]));
        self::assertSame(
            1,
            $this->countTracksScoped(),
            'the populated side of the table is still untouched'
        );
    }

    /**
     * Gate 3b: a `scan`/`rescan` row that is still queued/running means the tables
     * are being rewritten RIGHT NOW — parentage moves mid-flight and a container
     * can read as empty between the UPDATE and the mint. The pass must defer to
     * the live scan. (The prune's own running row is type='prune' and must NOT
     * self-block — that is half of this case.)
     */
    public function testReapIsSkippedWhileAnotherScanJobIsActive(): void
    {
        $this->copyFixtureTracks(1);
        self::assertSame(
            3,
            $this->manager()->rescanLibrary($this->libraryId)->added,
            "1 leaf + album/artist containers = 3 media_items rows"
        );

        $artistX = $this->artistIdByName($this->prefix . 'Measured Artist');
        $shellAnchor = $this->seedMediaItem('album', $this->prefix . 'Shell Anchor');
        $shellId = $this->seedShellAlbum($artistX, $this->prefix . 'Shell', $shellAnchor);

        $this->seedCompletedScanJob(0);
        $this->seedJob($this->libraryId, 'rescan', 'running', 0);

        self::assertSame(
            0,
            $this->manager()->pruneLibrary($this->libraryId),
            'RED if the live-scan guard is removed: the operator just asked for a heal, the prune '
            . 'must not race it. Note the seedCompletedScanJob above — the latest COMPLETED row is '
            . 'clean, only the RUNNING row refuses the pass'
        );
        self::assertSame(1, $this->countRows('SELECT id FROM music_albums WHERE id = ?', [$shellId]));

        // Flip the active row to completed-clean; the same prune call now proceeds.
        $this->db()->query(
            "UPDATE library_scan_jobs SET status = 'completed', completed_at = NOW() WHERE library_id = ?",
            [$this->libraryId],
        );

        self::assertSame(1, $this->manager()->pruneLibrary($this->libraryId));
        self::assertSame(0, $this->countRows('SELECT id FROM music_albums WHERE id = ?', [$shellId]));
    }

    /**
     * Gate 1/3 on the RESCAN side, end to end: a healing rescan that could not
     * index one file reports items_failed > 0 and its own reap is SKIPPED — the
     * vacated shell from this very pass survives — and the next fully-clean rescan
     * reaps it.
     *
     * The failure is production-reachable and needs no stub: a filename carrying a
     * raw 0xFF byte cannot be stored in a utf8mb4 column (MySQL 1366), so
     * createMediaItem() catches its Throwable and returns '', and upsertTrack()
     * returns 'failed'. That is the same 1366 class that S158's step-number
     * collision recorded on this very codebase.
     */
    public function testHealingRescanWithAFailedReadDoesNotReapAndTheNextCleanOneDoes(): void
    {
        $ok = $this->copyFixture(1)[0];
        $poison = $this->root . '/s153-' . "\xFF" . 'poison.mp3';
        copy(dirname(__DIR__, 3) . '/Fixtures/Media/Music/tagged-short.mp3', $poison);
        clearstatcache();

        $manager = $this->manager();
        $first = $manager->rescanLibrary($this->libraryId);
        self::assertSame(1, $first->failed, 'the 0xFF-byte filename must already fail the indexing scan');
        self::assertSame(3, $first->added, 'the one readable track + album/artist containers landed');

        // Now vacate the populated album: move the ONE indexed track to a new album
        // so the pass itself produces the shell, then prove a failed scan will not
        // reap the shell its own walk made.
        $this->retag($ok, album: 'Refiled Album');
        $albumA = $this->albumIdByTitle($this->prefix . 'Measured Album');
        self::assertGreaterThan(0, $albumA);

        $dirty = $manager->rescanLibrary($this->libraryId);
        self::assertSame(
            1,
            $dirty->failed,
            'the 0xFF-byte filename must cost exactly one failed file (1366 -> createMediaItem \'\')'
        );
        self::assertSame(
            1,
            $this->countRows('SELECT id FROM music_albums WHERE id = ?', [$albumA]),
            'RED if the rescan path reaps despite items_failed > 0: the shell it just vacated is only '
            . 'provably orphaned once a read-complete pass has seen every file'
        );

        $clean = $this->root . '/s153-renamed-ok.mp3';
        rename($poison, $clean);
        $this->cleanupFiles[] = $clean;
        // Retag it onto the SAME album the moved track now names — left on its
        // fixture tags it would re-populate the shell via upsertAlbum's natural-key
        // find and the reap assertion below would be testing the wrong thing.
        $this->retag($clean, album: 'Refiled Album');

        $third = $manager->rescanLibrary($this->libraryId);
        self::assertSame(0, $third->failed);
        self::assertSame(
            0,
            $this->countRows('SELECT id FROM music_albums WHERE id = ?', [$albumA]),
            'the first fully-clean pass reaps what the dirty pass correctly spared'
        );
        self::assertSame(2, $this->countTracksScoped(), 'indexed set settled at both real files');
    }

    /**
     * The two silent refusals no other case exercises: gate 1 (`type === 'music'`)
     * and the `paths === []` half of gate 2. Both refuse the whole pass even with
     * everything else perfectly clean — completed zero-failure job row, the seeds
     * ATTRIBUTED to the pruning library (so attribution is not what saves them):
     * a video library's prune must never touch the music hierarchy at all, and a
     * music library that has no roots has no observable storage to vouch for its
     * evidence.
     */
    public function testReapRefusesANonMusicLibraryAndAMusicLibraryWithoutRoots(): void
    {
        $videoLibrary = Uuid::v4();
        $this->extraLibraryIds[] = $videoLibrary;
        $videoRoot = $this->root . '-video';
        $this->makeCleanupDirectory($videoRoot);
        $this->db()->query(
            "INSERT INTO libraries (id, name, type, paths) VALUES (?, 'S153 IT Video', 'video', ?)",
            [$videoLibrary, json_encode([$videoRoot])],
        );

        $rootlessLibrary = Uuid::v4();
        $this->extraLibraryIds[] = $rootlessLibrary;
        $this->insertLibrary($rootlessLibrary, []);

        $artistId = $this->seedArtist(
            $this->prefix . 'Refused-pass Artist',
            $this->seedMediaItem('artist', $this->prefix . 'Refused Artist Anchor', $videoLibrary)
        );
        $videoShellId = $this->seedShellAlbum(
            $artistId,
            $this->prefix . 'Video Library Shell',
            $this->seedMediaItem('album', $this->prefix . 'Video Shell Anchor', $videoLibrary)
        );
        $rootlessShellId = $this->seedShellAlbum(
            $artistId,
            $this->prefix . 'Rootless Library Shell',
            $this->seedMediaItem('album', $this->prefix . 'Rootless Shell Anchor', $rootlessLibrary)
        );
        $this->seedJob($videoLibrary, 'scan', 'completed', 0);
        $this->seedJob($rootlessLibrary, 'scan', 'completed', 0);

        $manager = $this->manager();

        self::assertSame(
            0,
            $manager->pruneLibrary($videoLibrary),
            'RED if gate 1 (type === music) is removed: a video prune would delete music hierarchy rows'
        );
        self::assertSame(
            0,
            $manager->pruneLibrary($rootlessLibrary),
            'RED if the empty-roots refusal is removed: nothing on disk was ever verified'
        );
        self::assertSame(1, $this->countRows('SELECT id FROM music_albums WHERE id = ?', [$videoShellId]));
        self::assertSame(1, $this->countRows('SELECT id FROM music_albums WHERE id = ?', [$rootlessShellId]));
        self::assertSame(1, $this->countRows('SELECT id FROM music_artists WHERE id = ?', [$artistId]));
    }



    private function db(): Connection
    {
        self::assertNotNull($this->db);

        return $this->db;
    }

    /** @param list<string> $paths */
    private function insertLibrary(string $id, array $paths): void
    {
        $this->db()->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, \'music\', ?)',
            [$id, 'S153 IT Library', json_encode($paths)],
        );
    }

    /**
     * Copies the tagged fixture N times into the main root.
     *
     * @return list<string> absolute paths, in creation order
     */
    private function copyFixtureTracks(int $n): array
    {
        $paths = $this->copyFixture($n);
        clearstatcache();

        return $paths;
    }

    /**
     * @return list<string> absolute paths, in creation order
     */
    private function copyFixture(int $n): array
    {
        $fixture = dirname(__DIR__, 3) . '/Fixtures/Media/Music/tagged-short.mp3';
        self::assertFileExists($fixture);

        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $path = $this->root . '/s153-track-' . $i . '.mp3';
            copy($fixture, $path);
            $out[] = $path;
        }
        clearstatcache();

        return $out;
    }

    /**
     * Real ID3v2 write through getid3_writetags — the same mechanism
     * {@see \Phlix\Tests\Integration\Media\MusicRetagReparentIntegrationTest} pins S145
     * with: it changes mtime+size (so the skip index lets the scan re-read) while
     * leaving title/track/disc/duration byte-identical.
     */
    private function retag(string $path, ?string $album = null, ?string $artist = null): void
    {
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
     * The manager the job queue actually runs: real DB, real scanner (probe-counting
     * subclass for the purge prefix), real prune, real reap.
     */
    private function manager(): LibraryManager
    {
        $db = $this->db();
        $itemRepository = new ItemRepository($db);
        $scanner = new MediaScanner($db, $itemRepository, null, null, null, new FfmpegRunner());

        $musicScanner = new ProbeCountingIntegrationScanner($db, new FfmpegRunner());
        $musicScanner->artistPrefix = $this->prefix;

        return new LibraryManager(
            $db,
            $scanner,
            new FolderWatcher(),
            new MusicLibraryService($db, $musicScanner),
            null,
            $itemRepository,
        );
    }

    // ── seeding ──────────────────────────────────────────────────────────────

    /** @return string the new media_items id (a legal SET NULL anchor for containers). */
    private function seedMediaItem(string $type, string $name, ?string $libraryId = null): string
    {
        $id = Uuid::v4();
        $this->db()->query(
            'INSERT INTO media_items (id, library_id, type, name, path) VALUES (?, ?, ?, ?, \'\')',
            [$id, $libraryId ?? $this->libraryId, $type, $name],
        );

        return $id;
    }

    /** A real music_artists row (no albums, no tracks) — returns its id. */
    private function seedArtist(string $name, ?string $mediaItemId = null): int
    {
        $this->db()->query(
            'INSERT INTO music_artists (name, media_item_id) VALUES (?, ?)',
            [$name, $mediaItemId],
        );

        return (int) $this->db()->lastInsertId();
    }

    /**
     * A zero-track album under $artistId — returns its id.
     *
     * ⚠ `$mediaItemId = null` puts the row in the suite-GLOBAL unanchored bucket
     * (any qualifying music pass of any library may reap it) — pass null only where
     * that branch is the thing under test; gate cases anchor to their own library so
     * a leaked or racing seed cannot skew them.
     */
    private function seedShellAlbum(int $artistId, string $title, ?string $mediaItemId = null): int
    {
        $this->db()->query(
            'INSERT INTO music_albums (artist_id, media_item_id, title) VALUES (?, ?, ?)',
            [$artistId, $mediaItemId, $title],
        );

        return (int) $this->db()->lastInsertId();
    }

    private function seedCompletedScanJob(int $itemsFailed): void
    {
        $this->seedJob($this->libraryId, 'scan', 'completed', $itemsFailed);
    }

    private function seedJob(string $libraryId, string $type, string $status, int $itemsFailed): void
    {
        $this->db()->query(
            'INSERT INTO library_scan_jobs (id, library_id, type, status, items_failed, completed_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [Uuid::v4(), $libraryId, $type, $status, $itemsFailed],
        );
    }

    // ── reads ────────────────────────────────────────────────────────────────

    private function countRows(string $sql, array $params): int
    {
        $rows = $this->db()->query($sql, $params);

        return is_array($rows) ? count($rows) : 0;
    }

    /** Albums of this run: joined through their artist, which always carries the prefix. */
    private function countRunAlbums(): int
    {
        return $this->countRows(
            'SELECT a.id FROM music_albums a JOIN music_artists ar ON ar.id = a.artist_id'
            . ' WHERE ar.name LIKE ?',
            [$this->prefix . '%'],
        );
    }

    private function countRunArtists(): int
    {
        return $this->countRows('SELECT id FROM music_artists WHERE name LIKE ?', [$this->prefix . '%']);
    }

    /** music_albums rows of THIS run with zero music_tracks. */
    private function countShellAlbums(): int
    {
        return $this->countRows(
            'SELECT a.id FROM music_albums a JOIN music_artists ar ON ar.id = a.artist_id'
            . ' WHERE ar.name LIKE ? AND NOT EXISTS (SELECT 1 FROM music_tracks t WHERE t.album_id = a.id)',
            [$this->prefix . '%'],
        );
    }

    /** Artists of this run holding ZERO tracks — the production 13 → 16 metric, albums ignored. */
    private function countArtistsWithZeroTracks(): int
    {
        return $this->countRows(
            'SELECT ar.id FROM music_artists ar'
            . ' WHERE ar.name LIKE ?'
            . ' AND NOT EXISTS (SELECT 1 FROM music_tracks t WHERE t.artist_id = ar.id)',
            [$this->prefix . '%'],
        );
    }

    /** music_artists rows of THIS run with zero tracks AND zero albums. */
    private function countEmptyArtists(): int
    {
        return $this->countRows(
            'SELECT ar.id FROM music_artists ar'
            . ' WHERE ar.name LIKE ?'
            . ' AND NOT EXISTS (SELECT 1 FROM music_tracks t WHERE t.artist_id = ar.id)'
            . ' AND NOT EXISTS (SELECT 1 FROM music_albums a WHERE a.artist_id = ar.id)',
            [$this->prefix . '%'],
        );
    }

    /** Track ids of this library, each mapped to its current track count (1 per row). */
    private function trackIdMap(): array
    {
        $rows = $this->db()->query(
            'SELECT t.id FROM music_tracks t JOIN media_items mi ON mi.id = t.media_item_id'
            . ' WHERE mi.library_id = ?',
            [$this->libraryId],
        );
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['id'])) {
                $out[(int) $row['id']] = 1;
            }
        }

        return $out;
    }

    private function trackAlbumId(int $trackId): int
    {
        return (int) $this->scalar('SELECT album_id AS v FROM music_tracks WHERE id = ?', [$trackId]);
    }

    private function onlyTrackId(): int
    {
        $map = $this->trackIdMap();
        self::assertCount(1, $map, 'this case keeps exactly one track');

        return (int) array_key_first($map);
    }

    private function countTracksScoped(): int
    {
        return count($this->trackIdMap());
    }

    private function albumIdByTitle(string $title): int
    {
        return (int) $this->scalar(
            'SELECT a.id AS v FROM music_albums a JOIN music_artists ar ON ar.id = a.artist_id'
            . ' WHERE a.title = ? AND ar.name LIKE ?',
            [$title, $this->prefix . '%'],
        );
    }

    private function artistIdByName(string $name): int
    {
        return (int) $this->scalar('SELECT id AS v FROM music_artists WHERE name = ?', [$name]);
    }

    /** @param array<int, mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $rows = $this->db()->query($sql, $params);

        return is_array($rows) && is_array($rows[0] ?? null) ? ($rows[0]['v'] ?? 0) : 0;
    }
}
