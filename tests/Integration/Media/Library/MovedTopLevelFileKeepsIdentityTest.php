<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Library;

use Phlix\Common\Logger\StructuredLogger;
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

    /** Set by {@see capturingLogger()}; read back by {@see logLines()}. */
    private string $logFile = '';

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

    /**
     * The candidate cap: overflow rows behave exactly as they did before S158
     * (the prune deletes them), and the operator is told ONCE.
     *
     * The cap is injected rather than reached honestly — twenty thousand moved
     * files is not a test — and injected via the constructor rather than by
     * rewriting the constant, because a test that edits the file it is testing
     * is testing something else.
     */
    public function testTheCandidateCapIsAnnouncedOnceAndTheOverflowIsPrunedAsBefore(): void
    {
        $titles = ['Solaris (1972)', 'Stalker (1979)', 'Mirror (1975)'];
        $from = [];
        foreach ($titles as $title) {
            $from[$title] = $this->writeFile('Movies/' . $title . '.mkv');
        }
        // Never moves, so the per-root presence guard is open and the prune
        // really would delete every moved row.
        $this->writeFile('Movies/Companion (2001).mkv');

        $libraryId = $this->createLibrary('movie');
        $logger = $this->capturingLogger();
        $manager = $this->manager(null, $logger, 1);

        $manager->rescanLibrary($libraryId);

        $ids = [];
        foreach ($titles as $title) {
            $ids[$title] = $this->itemIdAtPath($libraryId, $from[$title]);
            $this->assertNotSame('', $ids[$title]);
            $this->recordUserData($ids[$title]);
        }

        foreach ($titles as $title) {
            $this->movePath($from[$title], 'Archive/' . $title . '.mkv');
        }

        $result = $manager->rescanLibrary($libraryId);

        $survivors = 0;
        foreach ($ids as $id) {
            $survivors += $this->countRowsWithId($id);
        }
        $this->assertSame(1, $survivors, 'exactly one row fits in a window of one');
        $this->assertSame(
            2,
            $result->removed,
            'the overflow is pruned exactly as it was before S158 — the cap trades rows for a memory '
            . 'bound, and pretending otherwise would hide a real data loss',
        );

        $warnings = $this->logLines('Moved-file adoption candidate cap reached');
        $this->assertCount(
            1,
            $warnings,
            'the operator must be told, and told ONCE — a per-row warning on a library-wide '
            . 'reorganisation is its own outage',
        );
        $this->assertStringContainsString('"cap":1', $warnings[0], 'the warning must name the cap it hit');
    }

    /**
     * The probe budget: the row is still adopted — identity is never traded for
     * memory — but it keeps describing the file it no longer points at, and that
     * must not be silent.
     *
     * This was a review finding in its own right. The candidate cap logs; this
     * one absorbed the degradation without a word, leaving an operator with a
     * row whose `duration`/`source`/`media_streams` describe a different file and
     * which never self-repairs, because `backfillItemSourceMetadata()` returns
     * `'skipped'` the moment duration and source are populated.
     */
    public function testTheProbeBudgetIsAnnouncedOnceAndTheRowIsAdoptedButNotRedescribed(): void
    {
        $ffmpeg = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', sys_get_temp_dir());
        if (!$ffmpeg->isAvailable()) {
            self::markTestSkipped('ffmpeg/ffprobe not available; this case is about probe-derived state');
        }

        $small = $this->root . '/A/Blade Runner (1982).mp4';
        $large = $this->root . '/B/Blade.Runner.1982.1080p.mp4';
        $this->makeClip($small, 320, 240, 2);
        $this->makeClip($this->root . '/A/Metropolis (1927).mp4', 160, 120, 1);

        $libraryId = $this->createLibrary('movie');
        $logger = $this->capturingLogger();
        // Budget 0: every candidate is recorded, none keeps its probe.
        $manager = $this->manager($ffmpeg, $logger, null, 0);

        $manager->rescanLibrary($libraryId);
        $originalId = $this->itemIdAtPath($libraryId, $small);
        $this->assertNotSame('', $originalId);
        $this->recordUserData($originalId);

        $this->makeClip($large, 1280, 720, 6);
        self::assertTrue(unlink($small));
        clearstatcache(true);

        $result = $manager->rescanLibrary($libraryId);

        $this->assertSame(0, $result->removed, 'identity is never traded for memory: the row is still adopted');
        $this->assertSame([$originalId], $this->idsAtPath($libraryId, $large));
        $this->assertSame(1, $this->countUserData($originalId));
        $this->assertSame(
            [320, 240, 2],
            $this->fileDerivedState($originalId),
            'and this is the degradation the warning exists for: the row now points at the 1280x720 '
            . 'copy while still describing the 320x240 one',
        );

        $warnings = $this->logLines('Moved-file adoption probe budget reached');
        $this->assertCount(1, $warnings, 'announced, and announced once');
        $this->assertStringContainsString('"cap":0', $warnings[0]);
        $this->assertSame(
            [],
            $this->logLines('Moved-file adoption candidate cap reached'),
            'the two overflows mean different things and must not be confused for one another',
        );
    }

    /**
     * A file that vanishes between the walk and the prune is not adopted into a
     * path that is already gone again.
     *
     * Staged deterministically on `rescanLibrary()`'s progress sink, which fires
     * once per processed file: acting on the LAST tick puts the deletion exactly
     * in the window between the scan and the prune.
     */
    public function testAnAdoptionIsAbandonedWhenTheAdoptedFileVanishesBeforeThePrune(): void
    {
        $from = $this->writeFile('Movies/Solaris (1972).mkv');
        $this->writeFile('Movies/Companion (2001).mkv');

        $libraryId = $this->createLibrary('movie');
        $manager = $this->manager();
        $manager->rescanLibrary($libraryId);

        $originalId = $this->itemIdAtPath($libraryId, $from);
        $this->assertNotSame('', $originalId);

        $to = $this->movePath($from, 'Archive/Solaris (1972).mkv');

        $result = $manager->rescanLibrary($libraryId, [], $this->onLastFile(static function () use ($to): void {
            unlink($to);
            clearstatcache(true);
        }));

        $this->assertSame(1, $result->removed, 'the file really is gone now, so the row is pruned');
        $this->assertSame(0, $this->countRowsWithId($originalId));
    }

    /**
     * A concurrent worker that indexed the new path first: the adoption UPDATE
     * hits the `(library_id, path_hash)` unique index.
     *
     * The requirement is not that this never happens — it is that it degrades to
     * the pre-S158 outcome instead of becoming a new failure mode: the row is
     * pruned as it always was, the connection is not poisoned, and the library
     * settles on the next rescan.
     */
    public function testACompetingRowAtTheNewPathDegradesToThePreS158Outcome(): void
    {
        $from = $this->writeFile('Movies/Solaris (1972).mkv');
        $this->writeFile('Movies/Companion (2001).mkv');

        $libraryId = $this->createLibrary('movie');
        $logger = $this->capturingLogger();
        $manager = $this->manager(null, $logger);
        $manager->rescanLibrary($libraryId);

        $originalId = $this->itemIdAtPath($libraryId, $from);
        $this->assertNotSame('', $originalId);

        $to = $this->movePath($from, 'Archive/Solaris (1972).mkv');
        $racerId = $this->uuid();

        $db = $this->db();
        $result = $manager->rescanLibrary($libraryId, [], $this->onLastFile(
            function () use ($db, $libraryId, $racerId, $to): void {
                $db->query(
                    'INSERT INTO media_items (id, library_id, name, type, path) VALUES (?, ?, ?, ?, ?)',
                    [$racerId, $libraryId, 'Solaris', 'movie', $to],
                );
            },
        ));

        $this->assertSame(1, $result->removed, 'unchanged from master: the row the other worker replaced is pruned');
        $this->assertSame(0, $this->countRowsWithId($originalId));
        $this->assertCount(
            1,
            $this->logLines('Could not re-point a moved top-level item at its new path'),
            'the collision must be reported, not swallowed',
        );

        $probe = $db->query('SELECT 1 AS ok');
        $this->assertIsArray($probe, 'a caught 1062 must not poison the connection for everything after it');

        $settled = $manager->rescanLibrary($libraryId);
        $this->assertSame(0, $settled->removed, 'and the library settles');
        $this->assertSame(0, $settled->added);
        $this->assertSame([$racerId], $this->idsAtPath($libraryId, $to));
    }

    /**
     * The row cannot be re-read at adoption time — it is left to the prune.
     *
     * Two shapes, because they are different facts: the lookup THROWS (reported),
     * and the row is simply GONE (nothing to report — something else already
     * deleted it, and the prune's own DELETE is then a no-op).
     *
     * The database stays real; only `findById()` misbehaves.
     */
    public function testAnAdoptionIsAbandonedWhenTheRowCannotBeReRead(): void
    {
        foreach (['throws', 'missing'] as $mode) {
            $from = $this->writeFile("$mode/Movies/Solaris (1972).mkv");
            $this->writeFile("$mode/Movies/Companion (2001).mkv");

            $libraryId = $this->createLibrary('movie', [$this->root . '/' . $mode]);
            $logger = $this->capturingLogger();
            $repository = $mode === 'throws'
                ? new class ($this->db()) extends ItemRepository {
                    public function findById(string $id): ?array
                    {
                        throw new \RuntimeException('re-read failed');
                    }
                }
                : new class ($this->db()) extends ItemRepository {
                    public function findById(string $id): ?array
                    {
                        return null;
                    }
                };
            $manager = $this->manager(null, $logger, null, null, $repository);

            $manager->rescanLibrary($libraryId);
            $originalId = $this->itemIdAtPath($libraryId, $from);
            $this->assertNotSame('', $originalId, "[$mode] the film must be indexed first");

            $this->movePath($from, "$mode/Archive/Solaris (1972).mkv");
            $result = $manager->rescanLibrary($libraryId);

            $this->assertSame(1, $result->removed, "[$mode] the adoption is abandoned, so the prune proceeds");
            $this->assertSame(0, $this->countRowsWithId($originalId), "[$mode] row pruned");
            $this->assertCount(
                $mode === 'throws' ? 1 : 0,
                $this->logLines('Could not re-read a top-level item that was about to be adopted'),
                "[$mode] a throw is reported; an already-deleted row is not an incident",
            );
        }
    }

    /**
     * The stream replacement fails during an adoption.
     *
     * The path write must NOT be held hostage to it — that write is what saves
     * the row from deletion. So the row keeps its identity and its new path, the
     * previous `media_streams` are left intact rather than half-written, and the
     * failure is reported.
     */
    public function testAnAdoptedRowKeepsItsPreviousStreamsWhenTheReplacementFails(): void
    {
        $ffmpeg = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', sys_get_temp_dir());
        if (!$ffmpeg->isAvailable()) {
            self::markTestSkipped('ffmpeg/ffprobe not available; this case is about probe-derived state');
        }

        $small = $this->root . '/A/Blade Runner (1982).mp4';
        $large = $this->root . '/B/Blade.Runner.1982.1080p.mp4';
        $this->makeClip($small, 320, 240, 2);
        $this->makeClip($this->root . '/A/Metropolis (1927).mp4', 160, 120, 1);

        $libraryId = $this->createLibrary('movie');
        $logger = $this->capturingLogger();
        // Fails ONLY the stream replacement, and only once the item already
        // exists — so the first scan writes a real stream set to leave behind.
        $repository = new class ($this->db()) extends ItemRepository {
            public bool $failStreams = false;

            /** @param list<array<string, mixed>> $streams */
            public function replaceStreams(string $itemId, array $streams): void
            {
                if ($this->failStreams) {
                    throw new \RuntimeException('stream replacement failed');
                }
                parent::replaceStreams($itemId, $streams);
            }
        };
        $manager = $this->manager($ffmpeg, $logger, null, null, $repository);

        $manager->rescanLibrary($libraryId);
        $originalId = $this->itemIdAtPath($libraryId, $small);
        $this->assertNotSame('', $originalId);
        $this->assertSame([320, 240, 2], $this->fileDerivedState($originalId));

        $this->makeClip($large, 1280, 720, 6);
        self::assertTrue(unlink($small));
        clearstatcache(true);
        $repository->failStreams = true;

        $result = $manager->rescanLibrary($libraryId);

        $this->assertSame(0, $result->removed, 'the row is still saved: the path write does not depend on streams');
        $this->assertSame([$originalId], $this->idsAtPath($libraryId, $large));
        $this->assertCount(
            1,
            $this->logLines('Adopted item kept its previous media_streams'),
            'a stream set left describing the wrong file must be reported',
        );

        $streams = $this->db()->query(
            'SELECT width, height FROM media_streams WHERE media_item_id = ? AND stream_type = ?',
            [$originalId, 'video'],
        );
        $this->assertIsArray($streams);
        $this->assertCount(
            1,
            $streams,
            'the PREVIOUS stream row must survive intact — a half-written or empty set would strand the '
            . 'item, because persistStreams() only stamps streams_probed_at on success',
        );
        $this->assertSame(320, (int) $streams[0]['width']);
    }

    /**
     * An exception mid-scan must still close the adoption window.
     *
     * Asserted behaviourally rather than by reflection: if the `finally` did not
     * run, the candidate would still be sitting in the map and the NEXT
     * standalone `pruneLibrary()` — an op that never scans and must therefore
     * never adopt — would silently re-point the row instead of deleting it.
     */
    public function testTheAdoptionWindowIsClosedWhenTheScanThrows(): void
    {
        $from = $this->writeFile('Movies/Solaris (1972).mkv');
        $this->writeFile('Movies/Companion (2001).mkv');

        $libraryId = $this->createLibrary('movie');
        $manager = $this->manager();
        $manager->rescanLibrary($libraryId);

        $originalId = $this->itemIdAtPath($libraryId, $from);
        $this->assertNotSame('', $originalId);

        $this->movePath($from, 'Archive/Solaris (1972).mkv');

        $thrown = null;
        try {
            $manager->rescanLibrary($libraryId, [], $this->onLastFile(static function (): void {
                throw new \RuntimeException('boom mid-scan');
            }));
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(\RuntimeException::class, $thrown, 'the scenario depends on the scan aborting');
        $this->assertSame(
            1,
            $this->countRowsWithId($originalId),
            'the aborted rescan never reached its prune, so nothing was deleted',
        );

        $pruned = $manager->pruneLibrary($libraryId);

        $this->assertSame(
            1,
            $pruned,
            'the standalone prune op does not scan, so it has no candidates and must behave exactly as '
            . 'it did on master. Adopting here would mean a candidate leaked out of the aborted rescan',
        );
        $this->assertSame(0, $this->countRowsWithId($originalId));
    }

    /**
     * Two files canonically matching ONE row in a single walk.
     *
     * Only the first recorded candidate is kept, so the outcome cannot depend on
     * which write landed last. The assertions deliberately do NOT say which of
     * the two wins — that is `readdir` order, and pinning it would be the very
     * assumption that turned CI red once already. What must hold either way: one
     * row, still the original, adopted onto one of the two real files.
     */
    public function testTwoFilesMatchingOneRowStillProduceExactlyOneAdoptedRow(): void
    {
        $from = $this->writeFile('Movies/Solaris (1972).mkv');
        $this->writeFile('Movies/Companion (2001).mkv');

        $libraryId = $this->createLibrary('movie');
        $manager = $this->manager();
        $manager->rescanLibrary($libraryId);

        $originalId = $this->itemIdAtPath($libraryId, $from);
        $this->assertNotSame('', $originalId);
        $this->recordUserData($originalId);

        // The one indexed copy disappears and TWO candidates for it appear.
        self::assertTrue(unlink($from));
        $a = $this->writeFile('Archive/Solaris (1972).mkv');
        $b = $this->writeFile('Backup/Solaris.1972.mkv');
        clearstatcache(true);

        $result = $manager->rescanLibrary($libraryId);

        $this->assertSame(0, $result->removed);
        $this->assertSame(1, $this->countRowsWithId($originalId), 'the row survives');
        $this->assertSame(1, $this->countUserData($originalId));
        $this->assertContains(
            $this->pathOf($originalId),
            [$a, $b],
            'and it points at one of the two real files',
        );
        $this->assertSame(
            2,
            $this->countItems($libraryId),
            'two candidates must not fork a second top-level row for the same film',
        );
    }

    /**
     * A plain move of a probed file must NOT rewrite `metadata_json`.
     *
     * The adopted file IS the file the row was created from, so there is nothing
     * to re-derive — and each needless `metadata_json` write flushes the whole
     * genre-facet cache and re-syncs the join rows, which on a library-wide move
     * is thousands of flushes for nothing. The decision has no other observable,
     * so it is read off the record the adoption itself emits.
     */
    public function testAPlainMoveOfAProbedFileIsAdoptedWithoutRedescribingIt(): void
    {
        $ffmpeg = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', sys_get_temp_dir());
        if (!$ffmpeg->isAvailable()) {
            self::markTestSkipped('ffmpeg/ffprobe not available; this case is about probe-derived state');
        }

        $from = $this->root . '/Movies/Solaris (1972).mp4';
        $this->makeClip($from, 320, 240, 2);
        $this->makeClip($this->root . '/Movies/Companion (2001).mp4', 160, 120, 1);

        $libraryId = $this->createLibrary('movie');
        $logger = $this->capturingLogger('info');
        $manager = $this->manager($ffmpeg, $logger);
        $manager->rescanLibrary($libraryId);

        $originalId = $this->itemIdAtPath($libraryId, $from);
        $this->assertNotSame('', $originalId);

        $to = $this->movePath($from, 'Archive/Solaris (1972).mp4');
        $result = $manager->rescanLibrary($libraryId);

        $this->assertSame(0, $result->removed);
        $this->assertSame([$originalId], $this->idsAtPath($libraryId, $to));
        $this->assertSame([320, 240, 2], $this->fileDerivedState($originalId), 'the description was already right');

        $adoptions = $this->logLines('Top-level item followed its file to a new path');
        $this->assertCount(1, $adoptions);
        $this->assertStringContainsString(
            '"source_redescribed":false',
            $adoptions[0],
            'the probe describes the same file the row already described, so nothing may be rewritten',
        );
    }

    /**
     * The two remaining `probeContradictsItem()` shapes, which the headline F2
     * case does not reach because it differs in resolution and so returns on the
     * FIRST comparison.
     *
     * (a) the row has NO recorded `source` at all — a row indexed before ffprobe
     *     was wired — and must be described for the first time;
     * (b) the `source` matches exactly but the recorded DURATION does not, which
     *     is the state of a row whose duration came from the parsed filename
     *     rather than from a probe (`processFile()` never overwrites a duration
     *     the metadata already carried).
     */
    public function testAnAdoptionRedescribesARowWithNoSourceAndOneWithOnlyAWrongDuration(): void
    {
        $ffmpeg = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', sys_get_temp_dir());
        if (!$ffmpeg->isAvailable()) {
            self::markTestSkipped('ffmpeg/ffprobe not available; this case is about probe-derived state');
        }

        // ── (a) indexed with NO probe runner at all, then adopted with one ──
        $from = $this->root . '/a/Movies/Solaris (1972).mp4';
        $this->makeClip($from, 320, 240, 2);
        $this->makeClip($this->root . '/a/Movies/Companion (2001).mp4', 160, 120, 1);

        $libraryA = $this->createLibrary('movie', [$this->root . '/a']);
        $this->manager()->rescanLibrary($libraryA);      // no ffmpeg: no source, no duration
        $idA = $this->itemIdAtPath($libraryA, $from);
        $this->assertNotSame('', $idA);
        $metaA = $this->metadataOf($idA);
        $this->assertArrayNotHasKey('source', $metaA, 'a scan with no probe runner leaves the row undescribed');

        $toA = $this->movePath($from, 'a/Archive/Solaris (1972).mp4');
        $this->manager($ffmpeg)->rescanLibrary($libraryA);

        $this->assertSame([$idA], $this->idsAtPath($libraryA, $toA));
        $this->assertSame(
            [320, 240, 2],
            $this->fileDerivedState($idA),
            'a row with no recorded source is always contradicted, so the adoption describes it for the '
            . 'first time rather than leaving it blank forever',
        );

        // ── (b) source identical (a byte-for-byte copy), duration wrong ──
        $original = $this->root . '/b/Movies/Stalker (1979).mp4';
        $this->makeClip($original, 320, 240, 2);
        $this->makeClip($this->root . '/b/Movies/Companion (2002).mp4', 160, 120, 1);

        $libraryB = $this->createLibrary('movie', [$this->root . '/b']);
        $managerB = $this->manager($ffmpeg);
        $managerB->rescanLibrary($libraryB);
        $idB = $this->itemIdAtPath($libraryB, $original);
        $this->assertNotSame('', $idB);

        // A byte-identical copy under a differently-slugging name: same canonical
        // key, and a probe that agrees with the row's `source` in every field.
        $copy = $this->root . '/b/Backup/Stalker.1979.mp4';
        mkdir(dirname($copy), 0775, true);
        self::assertTrue(copy($original, $copy));

        // Put the row in the state a filename-derived duration leaves it in.
        $meta = $this->metadataOf($idB);
        $meta['duration_seconds'] = 999;
        $this->db()->query(
            'UPDATE media_items SET metadata_json = ? WHERE id = ?',
            [(string) json_encode($meta), $idB],
        );
        self::assertTrue(unlink($original));
        clearstatcache(true);

        $resultB = $managerB->rescanLibrary($libraryB);

        $this->assertSame(0, $resultB->removed);
        $this->assertSame([$idB], $this->idsAtPath($libraryB, $copy));
        $this->assertSame(
            [320, 240, 2],
            $this->fileDerivedState($idB),
            'the source matched on every field, so only the duration comparison could have caught this — '
            . 'and 999 seconds is a scrubber that lies about the whole film',
        );
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
     * @param FfmpegRunner|null       $ffmpeg Wire a probe runner only for the cases
     *        that are about probe-derived state; the others must not pay for ffprobe.
     * @param StructuredLogger|null   $logger Capturing logger, for the cases whose
     *        subject IS the log line (an overflow an operator must be told about,
     *        or the `source_redescribed` decision, which has no other observable).
     * @param int|null $maxAdoptionCandidates Shrink the adoption window so its
     *        overflow branch is reachable with a handful of files.
     * @param int|null $maxAdoptionProbes Shrink the probe budget, likewise.
     * @param ItemRepository|null $itemRepository Substitute a repository that
     *        fails a specific write, to reach the guarded failure branches. The
     *        DATABASE stays real — only the one method under study misbehaves.
     */
    private function manager(
        ?FfmpegRunner $ffmpeg = null,
        ?StructuredLogger $logger = null,
        ?int $maxAdoptionCandidates = null,
        ?int $maxAdoptionProbes = null,
        ?ItemRepository $itemRepository = null,
    ): LibraryManager {
        $itemRepository ??= new ItemRepository($this->db());
        $scanner = new MediaScanner(
            $this->db(),
            $itemRepository,
            $logger,
            null,
            null,
            $ffmpeg,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $maxAdoptionCandidates,
            $maxAdoptionProbes,
        );

        return new LibraryManager(
            $this->db(),
            $scanner,
            new FolderWatcher(),
            // Only ever consulted for `music` libraries, which this test has none of.
            new MusicLibraryService($this->db(), new MusicLibraryScanner($this->db(), new FfmpegRunner())),
            $logger,
            $itemRepository,
        );
    }

    /**
     * A logger that writes to a file this test can read back.
     *
     * Several branches below have NO other observable: an overflow that is
     * silently absorbed, or the `source_redescribed` decision inside an
     * otherwise-identical adoption. Asserting on the record is the only way to
     * show which branch ran — and "the operator is told" is itself the
     * requirement for the two cap overflows.
     */
    private function capturingLogger(string $level = 'warning'): StructuredLogger
    {
        if (!is_dir($this->root)) {
            mkdir($this->root, 0775, true);
        }
        $this->logFile = $this->root . '/phlix-' . bin2hex(random_bytes(4)) . '.log';

        return new StructuredLogger('media', [
            'handlers' => [['type' => 'stream', 'path' => $this->logFile, 'level' => $level]],
        ]);
    }

    /** @return list<string> Captured log lines containing `$needle`. */
    private function logLines(string $needle): array
    {
        if ($this->logFile === '' || !is_file($this->logFile)) {
            return [];
        }
        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        return array_values(array_filter($lines, static fn(string $l): bool => str_contains($l, $needle)));
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

    /**
     * A `rescanLibrary()` progress sink that runs `$action` exactly once, on the
     * LAST processed file.
     *
     * That tick is the deterministic way to act in the window BETWEEN the scan
     * and the prune — which is where a racing worker lands, and the only place
     * several of the adoption failure branches are reachable from. Deliberately
     * not a race: a race would be one runner away from proving nothing.
     */
    private function onLastFile(callable $action): callable
    {
        $fired = false;

        return static function (int $processed, int $total) use ($action, &$fired): void {
            if ($fired || $processed < $total) {
                return;
            }
            $fired = true;
            $action();
        };
    }

    /** @return array<string, mixed> The row's decoded `metadata_json`. */
    private function metadataOf(string $itemId): array
    {
        $rows = $this->db()->query('SELECT metadata_json FROM media_items WHERE id = ?', [$itemId]);
        self::assertIsArray($rows);
        self::assertCount(1, $rows);
        $decoded = json_decode((string) $rows[0]['metadata_json'], true);

        return is_array($decoded) ? $decoded : [];
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
