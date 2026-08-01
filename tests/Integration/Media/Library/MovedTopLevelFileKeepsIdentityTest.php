<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Library;

use Phlix\Media\Library\FolderWatcher;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S158 — moving a TOP-LEVEL media file must not cost it its identity.
 *
 * ## The defect
 *
 * Move a movie out of one folder and into another, rescan, and the row was
 * deleted along with every piece of user data hanging off it; the next rescan
 * brought the film back with a NEW uuid, unwatched, unfavourited, unrated and
 * with no resume position.
 *
 * Neither half of the mechanism is wrong on its own — the damage is their
 * INTERACTION inside one {@see LibraryManager::rescanLibrary()} call:
 *
 *  - {@see MediaScanner::processFile()} stamps a deliberately path-independent
 *    `canonical_key` (title+year) on every parent-less row, so after the move
 *    `findByPath()` misses but the canonical lookup HITS. The scanner reused the
 *    row and returned early — never reaching `upsertByPath()`, so
 *    `media_items.path` still named the old, now-nonexistent file.
 *  - `rescanLibrary()` then runs {@see LibraryManager::pruneRemovedItems()} in
 *    the SAME run, which deletes every leaf failing `file_exists()`. That row.
 *    `ON DELETE CASCADE` took `user_item_data` and `watch_history` with it.
 *
 * A test that exercised only one of those halves would look correct and prove
 * nothing, so every case below drives the FULL `rescanLibrary()` path — real
 * scanner, real prune, real files on disk, one real MySQL.
 *
 * ## Where the fix lives, and why the tests are shaped around the PRUNE
 *
 * The scan does not write the new path. It records the row as an adoption
 * candidate; `pruneRemovedItems()` re-points it at the one place it already
 * knows it would otherwise delete it. That matters here because three of the
 * prune's four deletion conditions are whole-library aggregates (is any root
 * accessible; is this row attributable to one of them; does that root have any
 * present item at all) that no test of a single file can observe. So the cases
 * below deliberately vary the SHAPE of the library — one root, two roots, a root
 * whose presence guard is shut, a root that is an empty-but-present mountpoint —
 * rather than repeating one shape with different file counts.
 *
 * ## Why this test cannot be written against a test double
 *
 * The in-memory `Connection` doubles used across `tests/Unit` return canned rows
 * wholesale and ignore the statement's column list, so a double physically
 * cannot show whether `media_items.path` was actually WRITTEN — the one fact
 * this step turns on. It also cannot show that `path_hash` (a STORED generated
 * column, migrations 072/087) recomputes from the new path, nor that the
 * `ON DELETE CASCADE` foreign keys fire. Hence real MySQL; with none reachable
 * the test self-skips.
 *
 * ## The library always holds a second, unmoved file
 *
 * `pruneRemovedItems()`' per-root presence guard refuses to prune a root with
 * ZERO currently-present items. A one-file library therefore masks the defect
 * completely (measured: the row survives, merely stale). Two files is the
 * smallest shape that is honest about production.
 *
 * @covers \Phlix\Media\Library\MediaScanner
 * @covers \Phlix\Media\Library\LibraryManager
 */
final class MovedTopLevelFileKeepsIdentityTest extends TestCase
{
    use RequiresRealDatabase;

    private const SKIP_REASON = 'skipping the S158 moved-file identity test. Runs in CI / docker-compose.';

    private ?Connection $db = null;

    private string $root = '';

    /** @var list<string> */
    private array $libraryIds = [];

    private string $userId = '';

    private string $profileId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase(self::SKIP_REASON);

        $this->root = sys_get_temp_dir() . '/phlix-s158-' . bin2hex(random_bytes(6));

        // One user + one profile so the cascade this step is about has something
        // real to destroy: `user_item_data` hangs off the user, `watch_history`
        // off the profile, and both cascade from `media_items.id`.
        $this->userId = $this->uuid();
        $this->profileId = $this->uuid();
        $suffix = substr($this->userId, 0, 8);
        $this->db()->query(
            'INSERT INTO users (id, username, email, password_hash) VALUES (?, ?, ?, ?)',
            [$this->userId, 's158-' . $suffix, 's158-' . $suffix . '@example.test', 'x'],
        );
        $this->db()->query(
            'INSERT INTO user_profiles (id, user_id, name) VALUES (?, ?, ?)',
            [$this->profileId, $this->userId, 'S158'],
        );
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            foreach ($this->libraryIds as $libraryId) {
                // media_items cascades from libraries, and user_item_data /
                // watch_history cascade from media_items.
                $this->db->query('DELETE FROM libraries WHERE id = ?', [$libraryId]);
            }
            if ($this->profileId !== '') {
                $this->db->query('DELETE FROM user_profiles WHERE id = ?', [$this->profileId]);
            }
            if ($this->userId !== '') {
                $this->db->query('DELETE FROM users WHERE id = ?', [$this->userId]);
            }
        }
        $this->removeTree($this->root);

        parent::tearDown();
    }

    /**
     * The step's headline case: a movie moves folders between two rescans and
     * comes out the other side as the SAME row, with its user data intact and
     * its `path` pointing at where the file actually is.
     */
    public function testAMovedTopLevelFileKeepsItsIdAndItsUserData(): void
    {
        $from = $this->writeFile('Movies/Blade Runner (1982).mkv');
        $this->writeFile('Movies/Metropolis (1927).mkv');

        $libraryId = $this->createLibrary('movie');
        $manager = $this->manager();
        $manager->rescanLibrary($libraryId);

        $originalId = $this->itemIdAtPath($libraryId, $from);
        $this->assertNotSame('', $originalId, 'the first scan must index the film that is about to move');
        $this->recordUserData($originalId);

        $to = $this->movePath($from, 'Archive/Blade Runner (1982).mkv');

        $result = $manager->rescanLibrary($libraryId);

        $this->assertSame(
            0,
            $result->removed,
            'the rescan that saw the move must prune NOTHING — pruning here is the defect: the row it '
            . 'deletes is the one the scanner just decided to reuse, and the delete cascades into '
            . 'user_item_data and watch_history',
        );
        $this->assertSame(
            [$originalId],
            $this->idsAtPath($libraryId, $to),
            'the row at the NEW path must be the ORIGINAL row — a fresh uuid here means the item was '
            . 'destroyed and re-created, which is exactly the user-visible symptom (watch state, '
            . 'favourites and resume position all gone)',
        );
        $this->assertSame(
            [],
            $this->idsAtPath($libraryId, $from),
            'no row may still name the old location',
        );
        $this->assertSame(
            2,
            $this->countItems($libraryId),
            'the move must not fork a second top-level row for the same film',
        );

        // The cascade targets, read back directly rather than inferred from the
        // row count: these are what the user actually loses.
        $userData = $this->db()->query(
            'SELECT favorite, rating, like_level, watched FROM user_item_data WHERE user_id = ? AND item_id = ?',
            [$this->userId, $originalId],
        );
        $this->assertIsArray($userData);
        $this->assertCount(1, $userData, 'the favourite/rating/watched row must survive the move');
        $this->assertSame(1, (int) $userData[0]['favorite']);
        $this->assertSame(9, (int) $userData[0]['rating']);
        $this->assertSame(2, (int) $userData[0]['like_level']);
        $this->assertSame(1, (int) $userData[0]['watched']);

        $history = $this->db()->query(
            'SELECT position_ticks FROM watch_history WHERE profile_id = ? AND media_item_id = ?',
            [$this->profileId, $originalId],
        );
        $this->assertIsArray($history);
        $this->assertCount(1, $history, 'the resume position must survive the move');
        $this->assertSame(12340000000, (int) $history[0]['position_ticks']);

        // `path_hash` is a STORED generated column; the whole point of writing
        // `path` (rather than, say, tracking the move elsewhere) is that the hash
        // follows and the NEXT rescan resolves the row by plain path lookup, with
        // no canonical fallback and no churn at all.
        $hashRow = $this->db()->query(
            'SELECT path_hash FROM media_items WHERE id = ?',
            [$originalId],
        );
        $this->assertIsArray($hashRow);
        $this->assertSame(
            sha1($to),
            (string) $hashRow[0]['path_hash'],
            'the generated path_hash must follow the new path, or findByPath()/findPathsMap() keep '
            . 'missing and every future rescan re-enters the canonical fallback',
        );

        $third = $manager->rescanLibrary($libraryId);
        $this->assertSame(0, $third->added, 'a settled rescan adds nothing');
        $this->assertSame(0, $third->removed, 'a settled rescan prunes nothing');
        $this->assertSame(
            [$originalId],
            $this->idsAtPath($libraryId, $to),
            'the identity must still hold one rescan later',
        );
    }

    /**
     * The guard that keeps the fix from over-generalising.
     *
     * Canonical reuse serves a SECOND shape that is not a move: the same film
     * genuinely stored twice, both copies present on disk. There the recorded
     * path is still valid, the prune would never touch the row, and rewriting
     * `path` would make it flap to whichever copy the directory walk happened to
     * reach last — silently re-pointing playback at the other file. That case
     * must come out byte-identical to how it always behaved: one row, original
     * path, no second top-level entry.
     */
    public function testASecondCopyOfTheSameFilmIsStillDedupedAndKeepsTheOriginalPath(): void
    {
        $first = $this->writeFile('Movies/Blade Runner (1982).mkv');
        $this->writeFile('Movies/Metropolis (1927).mkv');

        $libraryId = $this->createLibrary('movie');
        $manager = $this->manager();
        $manager->rescanLibrary($libraryId);

        $originalId = $this->itemIdAtPath($libraryId, $first);
        $this->assertNotSame('', $originalId);

        // A second copy appears; the first one is still there.
        $second = $this->writeFile('Archive/Blade.Runner.1982.1080p.mkv');
        $this->assertFileExists($first, 'the duplicate case requires BOTH copies on disk');

        $result = $manager->rescanLibrary($libraryId);

        $this->assertSame(0, $result->removed, 'nothing vanished from disk, so nothing may be pruned');
        $this->assertSame(
            2,
            $this->countItems($libraryId),
            'a duplicate copy must still be deduped into the existing row, not minted as a second film',
        );
        $this->assertSame(
            [$originalId],
            $this->idsAtPath($libraryId, $first),
            'the reused row must still point at the copy it was created from',
        );
        $this->assertSame(
            [],
            $this->idsAtPath($libraryId, $second),
            'the still-present original path must NOT be rewritten to the duplicate — that would '
            . 'silently re-point playback at the other copy on every rescan',
        );
    }

    /**
     * Episodes are OUT OF SCOPE and must be provably untouched.
     *
     * An episode carries a season `parent_id`, so `processFile()`'s
     * `$parentId === null` test excludes it from the canonical branch entirely —
     * it never reaches the path-adoption code at all. A moved episode therefore
     * still gets a brand-new row and still loses the old one to the prune, which
     * is master's behaviour, and this test pins it so a later change to the
     * top-level fix cannot quietly start rewriting episode paths too.
     *
     * The series and season containers are addressed by synthetic
     * `series:`/`season:` paths, are never filesystem-checked, and must keep
     * their ids across the move.
     */
    public function testAMovedEpisodeBehavesExactlyAsItDidBeforeThisFix(): void
    {
        $episode = $this->writeFile('Firefly/Season 01/Firefly S01E01.mkv');
        $this->writeFile('Firefly/Season 01/Firefly S01E02.mkv');

        $libraryId = $this->createLibrary('series');
        $manager = $this->manager();
        $manager->rescanLibrary($libraryId);

        $originalEpisodeId = $this->itemIdAtPath($libraryId, $episode);
        $this->assertNotSame('', $originalEpisodeId);
        $seriesId = $this->onlyIdOfType($libraryId, 'series');
        $seasonId = $this->onlyIdOfType($libraryId, 'season');

        $moved = $this->movePath($episode, 'Firefly/Extras/Firefly S01E01.mkv');

        $manager->rescanLibrary($libraryId);

        $this->assertSame(
            [],
            $this->idsAtPath($libraryId, $episode),
            'unchanged from master: the moved episode\'s old row is pruned',
        );
        $newIds = $this->idsAtPath($libraryId, $moved);
        $this->assertCount(1, $newIds, 'unchanged from master: a fresh episode row is created at the new path');
        $this->assertNotSame(
            $originalEpisodeId,
            $newIds[0],
            'unchanged from master: the moved episode does NOT keep its id. S158 fixes TOP-LEVEL rows '
            . 'only; if this ever starts passing as "same id", the fix has leaked into the episode path',
        );

        $this->assertSame($seriesId, $this->onlyIdOfType($libraryId, 'series'), 'the series container is stable');
        $this->assertSame($seasonId, $this->onlyIdOfType($libraryId, 'season'), 'the season container is stable');
        $this->assertSame(
            2,
            $this->countType($libraryId, 'episode'),
            'both episodes are still indexed exactly once',
        );
    }

    /**
     * Review F1 — the ACCEPTANCE case. Adopting must never move a row OUT of the
     * prune's protected set and INTO its kill zone.
     *
     * `pruneRemovedItems()` deletes on a four-way conjunction, and only one of
     * those four is decidable from the filesystem around a single file. The first
     * cut of this fix wrote `path` from inside the scan on `!file_exists()` alone
     * and called that "the same predicate the prune uses"; it was not, and the
     * difference cost user data. This is the measured shape:
     *
     *  1. a two-root library, the same film present on the NAS root;
     *  2. the NAS unmounts leaving an empty-but-present mountpoint (autofs/NFS —
     *     `is_dir()` still true, so the root IS accessible and IS attributable,
     *     but it has ZERO present items so the per-root presence guard spares
     *     every row under it);
     *  3. the user drops a TEMPORARY copy of that film on the local root. The
     *     scan-time fix re-pointed the spared row at that copy;
     *  4. the temporary copy is deleted. The row now names a gone path inside an
     *     accessible root that HAS other present items — nothing spares it — and
     *     the prune deletes it, cascading user_item_data and watch_history.
     *
     * A parent-directory check cannot discriminate here: `/mnt/nas` is a readable
     * directory throughout. The only correct gate is the prune's own decision, so
     * the row must come through steps 3 and 4 exactly as it does on master.
     */
    public function testARowSparedByTheUnmountGuardIsNeverMigratedOntoAnotherRoot(): void
    {
        $local = $this->root . '/local';
        $nas = $this->root . '/nas';
        $stash = $this->root . '/nas-contents';
        mkdir($local, 0775, true);
        mkdir($nas, 0775, true);
        mkdir($stash, 0775, true);

        // A permanent unrelated film keeps the LOCAL root's presence guard open —
        // which is what makes step 4 lethal.
        $this->writeFile('local/Other Film (2001).mkv');
        $nasFile = $this->writeFile('nas/Blade Runner (1982).mkv');

        $libraryId = $this->createLibrary('movie', [$local, $nas]);
        $manager = $this->manager();
        $manager->rescanLibrary($libraryId);

        $originalId = $this->itemIdAtPath($libraryId, $nasFile);
        $this->assertNotSame('', $originalId, 'the first scan must index the film on the NAS root');
        $this->recordUserData($originalId);

        // The NAS unmounts: the mountpoint directory survives and is EMPTY.
        self::assertTrue(rename($nasFile, $stash . '/Blade Runner (1982).mkv'));
        clearstatcache(true);
        $this->assertDirectoryExists($nas, 'the autofs/NFS shape leaves the mountpoint behind');

        // A temporary local copy of the same film appears.
        $tempCopy = $this->writeFile('local/Blade Runner (1982).mkv');

        $second = $manager->rescanLibrary($libraryId);
        $this->assertSame(0, $second->removed, 'the presence guard spares the whole NAS root');
        $this->assertSame(
            $nasFile,
            $this->pathOf($originalId),
            'the row must STAY on the unmounted root. Re-pointing it at the local copy migrates it out '
            . 'of the set the presence guard is protecting and into a root where nothing spares it',
        );

        // The temporary copy goes away while the NAS is still down.
        self::assertTrue(unlink($tempCopy));
        clearstatcache(true);

        $third = $manager->rescanLibrary($libraryId);
        $this->assertSame(0, $third->removed, 'still nothing to prune — the NAS root is still spared');
        $this->assertSame(
            1,
            $this->countRowsWithId($originalId),
            'the original row must still exist; this assertion failing IS the data-loss bug',
        );
        $this->assertSame(1, $this->countUserData($originalId), 'and its user data with it');

        // The NAS comes back. The row is still pointing at the right file, so it
        // re-indexes in place with the SAME uuid — no canonical fallback needed.
        self::assertTrue(rename($stash . '/Blade Runner (1982).mkv', $nasFile));
        clearstatcache(true);
        $fourth = $manager->rescanLibrary($libraryId);

        $this->assertSame(0, $fourth->removed);
        $this->assertSame(
            [$originalId],
            $this->idsAtPath($libraryId, $nasFile),
            'after the remount the film is the SAME row it always was',
        );
        $this->assertSame(1, $this->countUserData($originalId));
    }

    /**
     * Review F1, the general form: a row the prune is SPARING must be left
     * completely alone, even when the scan found an obvious candidate file for it.
     *
     * One root; one film moves into a subdirectory and the only other film is
     * genuinely deleted in the same window. The root's present count is therefore
     * ZERO and the guard shuts, so nothing may be pruned AND nothing may be
     * adopted — adopting would make the root present again and un-spare the
     * genuinely-deleted row's user data in the same pass. Master's behaviour
     * exactly: two stale-but-intact rows.
     */
    public function testAdoptionNeverFiresForARootWhosePresenceGuardIsShut(): void
    {
        $moved = $this->writeFile('Solaris (1972).mkv');
        $deleted = $this->writeFile('Stalker (1979).mkv');

        $libraryId = $this->createLibrary('movie');
        $manager = $this->manager();
        $manager->rescanLibrary($libraryId);

        $movedId = $this->itemIdAtPath($libraryId, $moved);
        $deletedId = $this->itemIdAtPath($libraryId, $deleted);
        $this->assertNotSame('', $movedId);
        $this->assertNotSame('', $deletedId);
        $this->recordUserData($movedId);
        $this->recordUserData($deletedId);

        $this->movePath($moved, 'sub/Solaris (1972).mkv');
        self::assertTrue(unlink($deleted));
        clearstatcache(true);

        $result = $manager->rescanLibrary($libraryId);

        $this->assertSame(0, $result->removed, 'a root with zero present items is never pruned');
        $this->assertSame(
            $moved,
            $this->pathOf($movedId),
            'the spared row keeps its stale path: adopting it would raise the root\'s present count '
            . 'from 0 to 1 and un-spare the row next to it in the very same pass',
        );
        $this->assertSame(
            1,
            $this->countUserData($deletedId),
            'the neighbouring row the guard is protecting must keep its user data',
        );
    }

    /**
     * Review F2 — an adopted row must DESCRIBE the file it now points at.
     *
     * Canonical reuse explicitly serves "the same film stored twice", so the
     * adopted file may be a different physical copy. `duration_seconds`,
     * `metadata_json.source` and `media_streams` are FILE-derived and drive the
     * scrubber length, the direct-play/HEVC guard, the ABR ladder and the HLS job
     * key — and `backfillItemSourceMetadata()` returns `'skipped'` the moment
     * duration and source are populated, so nothing would ever repair them.
     *
     * Two rips of one film with the same canonical key: 320x240/2s and
     * 1280x720/6s. Delete the small one; the row must follow the big one AND stop
     * claiming to be 320x240.
     *
     * ## Staged in three scans, and that is not incidental
     *
     * The first revision of this case created BOTH rips before the first scan.
     * They share a canonical key, so exactly one gets a row and the other is
     * deduped — **whichever the directory walk reaches first**. `readdir` order is
     * not guaranteed and is not the same on every filesystem: it passed on two
     * dev boxes and FAILED on the GitHub runner, where the large copy won and
     * `itemIdAtPath($small)` came back empty. That is an order-dependent
     * assumption, not a flake, and it is the same class of silent
     * environment-to-environment difference this whole step exists to stop.
     *
     * So the small copy is indexed while it is the ONLY rip of that film on disk,
     * which no iteration order can change. Every later step likewise resolves by
     * path lookup, never by "whichever file was visited first".
     */
    public function testAnAdoptedDifferentCopyRedescribesTheRowsFileDerivedState(): void
    {
        $ffmpeg = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', sys_get_temp_dir());
        if (!$ffmpeg->isAvailable()) {
            self::markTestSkipped('ffmpeg/ffprobe not available; this case is about probe-derived state');
        }

        $small = $this->root . '/A/Blade Runner (1982).mp4';
        $large = $this->root . '/B/Blade.Runner.1982.1080p.mp4';
        $companion = $this->root . '/A/Metropolis (1927).mp4';
        $this->makeClip($small, 320, 240, 2);
        // A present companion so the prune's per-root guard is open — otherwise
        // the row is spared and (correctly) never adopted at all. Its canonical
        // key differs from the film's, so it can never contend for the row.
        $this->makeClip($companion, 160, 120, 1);

        // ── scan 1: the small rip is the only copy, so it IS the indexed row ──
        $libraryId = $this->createLibrary('movie');
        $manager = $this->manager($ffmpeg);
        $manager->rescanLibrary($libraryId);

        $originalId = $this->itemIdAtPath($libraryId, $small);
        $this->assertNotSame(
            '',
            $originalId,
            'the small copy is the only rip of this film on disk at this point, so it must be the '
            . 'indexed row no matter what order the walk visited the directory in',
        );
        $this->assertSame(
            [320, 240, 2],
            $this->fileDerivedState($originalId),
            'the row starts out describing the 320x240/2s copy',
        );
        $this->recordUserData($originalId);

        // ── scan 2: the second rip appears while the first is STILL on disk ──
        // Deduped into the existing row (it is not a move), and because the row's
        // own file is still there nothing may be re-pointed OR re-described.
        $this->makeClip($large, 1280, 720, 6);
        $duplicate = $manager->rescanLibrary($libraryId);

        $this->assertSame(0, $duplicate->removed);
        $this->assertSame(
            [$originalId],
            $this->idsAtPath($libraryId, $small),
            'both copies are present, so the row must still name the one it was created from',
        );
        $this->assertSame([], $this->idsAtPath($libraryId, $large), 'the duplicate must not fork a second row');
        $this->assertSame(
            [320, 240, 2],
            $this->fileDerivedState($originalId),
            're-describing here would be wrong: the row still points at the 320x240 file',
        );

        // ── scan 3: the indexed copy is deleted; the row must follow the other ──
        self::assertTrue(unlink($small));
        clearstatcache(true);

        $result = $manager->rescanLibrary($libraryId);

        $this->assertSame(0, $result->removed, 'the row follows the surviving copy instead of being pruned');
        $this->assertSame(
            [$originalId],
            $this->idsAtPath($libraryId, $large),
            'and it is still the SAME row, with its user data',
        );
        $this->assertSame(1, $this->countUserData($originalId));
        $this->assertSame(
            [1280, 720, 6],
            $this->fileDerivedState($originalId),
            'metadata_json.source, duration_seconds and media_streams must describe the copy the row '
            . 'now points at. Keeping 320x240/2s gives a wrong scrubber length and feeds the '
            . 'direct-play / HEVC-guard / ABR-ladder decisions and the HLS job key a file that is not there',
        );

        // Idempotent: a settled rescan changes nothing further.
        $settled = $manager->rescanLibrary($libraryId);
        $this->assertSame(0, $settled->removed);
        $this->assertSame(0, $settled->added);
        $this->assertSame([1280, 720, 6], $this->fileDerivedState($originalId));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function db(): Connection
    {
        if ($this->db === null) {
            self::fail('database connection was not acquired');
        }

        return $this->db;
    }

    /**
     * A real scanner + a real manager over the real connection — no doubles.
     *
     * @param FfmpegRunner|null $ffmpeg Wire a probe runner only for the case that
     *        is about probe-derived state; the others must not pay for ffprobe.
     */
    private function manager(?FfmpegRunner $ffmpeg = null): LibraryManager
    {
        $itemRepository = new ItemRepository($this->db());
        $scanner = new MediaScanner($this->db(), $itemRepository, null, null, null, $ffmpeg);

        return new LibraryManager(
            $this->db(),
            $scanner,
            new FolderWatcher(),
            // Only ever consulted for `music` libraries, which this test has none of.
            new MusicLibraryService($this->db(), new MusicLibraryScanner($this->db(), new FfmpegRunner())),
            null,
            $itemRepository,
        );
    }

    /**
     * @param list<string>|null $paths Configured roots; defaults to the single
     *        scratch root. A multi-root library is what makes the per-root
     *        presence guard observable at all.
     */
    private function createLibrary(string $type, ?array $paths = null): string
    {
        $libraryId = $this->uuid();
        $this->db()->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$libraryId, 'S158 ' . $type, $type, (string) json_encode($paths ?? [$this->root])],
        );
        $this->libraryIds[] = $libraryId;

        return $libraryId;
    }

    /** Synthesises a real, probeable clip at `$absolute`. */
    private function makeClip(string $absolute, int $width, int $height, int $seconds): void
    {
        $dir = dirname($absolute);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -f lavfi -i %s -c:v libx264 -pix_fmt yuv420p %s 2>/dev/null',
            escapeshellarg('/usr/bin/ffmpeg'),
            escapeshellarg(sprintf('testsrc=size=%dx%d:rate=10:duration=%d', $width, $height, $seconds)),
            escapeshellarg($absolute),
        );
        exec($cmd, $out, $code);
        self::assertSame(0, $code, 'failed to generate the test clip');
        self::assertFileExists($absolute);
    }

    /**
     * The row's FILE-derived state, read from all three places it lives:
     * `metadata_json.source.{width,height}` and `metadata_json.duration_seconds`,
     * cross-checked against the `media_streams` video row so a fix that updated
     * only the JSON blob cannot pass.
     *
     * @return array{0:int,1:int,2:int} width, height, duration seconds.
     */
    private function fileDerivedState(string $itemId): array
    {
        $rows = $this->db()->query('SELECT metadata_json FROM media_items WHERE id = ?', [$itemId]);
        self::assertIsArray($rows);
        self::assertCount(1, $rows);
        $decoded = json_decode((string) $rows[0]['metadata_json'], true);
        self::assertIsArray($decoded);
        $source = is_array($decoded['source'] ?? null) ? $decoded['source'] : [];

        $streams = $this->db()->query(
            'SELECT width, height FROM media_streams WHERE media_item_id = ? AND stream_type = ?',
            [$itemId, 'video'],
        );
        self::assertIsArray($streams);
        self::assertCount(1, $streams, 'exactly one video stream row is expected for these clips');
        self::assertSame(
            (int) $source['width'],
            (int) $streams[0]['width'],
            'media_streams must agree with metadata_json.source — they are written from the same probe',
        );
        self::assertSame((int) $source['height'], (int) $streams[0]['height']);

        return [
            (int) $source['width'],
            (int) $source['height'],
            (int) ($decoded['duration_seconds'] ?? 0),
        ];
    }

    private function pathOf(string $itemId): string
    {
        $rows = $this->db()->query('SELECT path FROM media_items WHERE id = ?', [$itemId]);

        return is_array($rows) && $rows !== [] ? (string) $rows[0]['path'] : '';
    }

    private function countRowsWithId(string $itemId): int
    {
        $rows = $this->db()->query('SELECT COUNT(*) AS c FROM media_items WHERE id = ?', [$itemId]);

        return is_array($rows) && isset($rows[0]['c']) ? (int) $rows[0]['c'] : -1;
    }

    private function countUserData(string $itemId): int
    {
        $rows = $this->db()->query('SELECT COUNT(*) AS c FROM user_item_data WHERE item_id = ?', [$itemId]);

        return is_array($rows) && isset($rows[0]['c']) ? (int) $rows[0]['c'] : -1;
    }

    /** Creates `$relative` under the scratch root and returns its absolute path. */
    private function writeFile(string $relative): string
    {
        $absolute = $this->root . '/' . $relative;
        $dir = dirname($absolute);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($absolute, str_repeat("\0", 512));

        return $absolute;
    }

    /** Moves an existing file to `$relative` and returns the new absolute path. */
    private function movePath(string $from, string $relative): string
    {
        $to = $this->root . '/' . $relative;
        $dir = dirname($to);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        self::assertTrue(rename($from, $to), 'the scenario depends on the file actually moving');
        clearstatcache(true);

        return $to;
    }

    private function recordUserData(string $itemId): void
    {
        $this->db()->query(
            'INSERT INTO user_item_data (user_id, item_id, favorite, rating, like_level, watched)'
            . ' VALUES (?, ?, 1, 9, 2, 1)',
            [$this->userId, $itemId],
        );
        $this->db()->query(
            'INSERT INTO watch_history (id, profile_id, media_item_id, position_ticks, duration_ticks)'
            . ' VALUES (?, ?, ?, ?, ?)',
            [$this->uuid(), $this->profileId, $itemId, 12340000000, 60000000000],
        );
    }

    /** @return list<string> ids of every row in `$libraryId` recording exactly `$path`. */
    private function idsAtPath(string $libraryId, string $path): array
    {
        $rows = $this->db()->query(
            'SELECT id FROM media_items WHERE library_id = ? AND path = ? ORDER BY id',
            [$libraryId, $path],
        );

        $ids = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && is_string($row['id'] ?? null)) {
                    $ids[] = $row['id'];
                }
            }
        }

        return $ids;
    }

    private function itemIdAtPath(string $libraryId, string $path): string
    {
        $ids = $this->idsAtPath($libraryId, $path);

        return $ids === [] ? '' : $ids[0];
    }

    private function onlyIdOfType(string $libraryId, string $type): string
    {
        $rows = $this->db()->query(
            'SELECT id FROM media_items WHERE library_id = ? AND type = ?',
            [$libraryId, $type],
        );
        self::assertIsArray($rows);
        self::assertCount(1, $rows, "expected exactly one `$type` row");

        return (string) $rows[0]['id'];
    }

    private function countItems(string $libraryId): int
    {
        $rows = $this->db()->query('SELECT COUNT(*) AS c FROM media_items WHERE library_id = ?', [$libraryId]);

        return is_array($rows) && isset($rows[0]['c']) ? (int) $rows[0]['c'] : -1;
    }

    private function countType(string $libraryId, string $type): int
    {
        $rows = $this->db()->query(
            'SELECT COUNT(*) AS c FROM media_items WHERE library_id = ? AND type = ?',
            [$libraryId, $type],
        );

        return is_array($rows) && isset($rows[0]['c']) ? (int) $rows[0]['c'] : -1;
    }

    private function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );
    }
}
