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

    // ── helpers ──────────────────────────────────────────────────────────────

    private function db(): Connection
    {
        if ($this->db === null) {
            self::fail('database connection was not acquired');
        }

        return $this->db;
    }

    /** A real scanner + a real manager over the real connection — no doubles. */
    private function manager(): LibraryManager
    {
        $itemRepository = new ItemRepository($this->db());
        $scanner = new MediaScanner($this->db(), $itemRepository);

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

    private function createLibrary(string $type): string
    {
        $libraryId = $this->uuid();
        $this->db()->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$libraryId, 'S158 ' . $type, $type, (string) json_encode([$this->root])],
        );
        $this->libraryIds[] = $libraryId;

        return $libraryId;
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
