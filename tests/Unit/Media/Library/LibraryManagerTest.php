<?php

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\ScanResult;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Common\Logger\LoggerFactory;
use Workerman\MySQL\Connection;

class LibraryManagerTest extends TestCase
{
    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    public function testCanCreateLibraryManager(): void
    {
        $db = $this->createMock(Connection::class);
        $scanner = $this->createMock(MediaScanner::class);
        $watcher = $this->createMock(FolderWatcher::class);
        $musicScanner = $this->createMock(MusicLibraryScanner::class);
        $musicLibraryService = $this->createMock(MusicLibraryService::class);

        $manager = new LibraryManager($db, $scanner, $watcher, $musicLibraryService);

        $this->assertInstanceOf(LibraryManager::class, $manager);
    }

    /**
     * Regression: rescanLibrary() must NEVER issue the destructive
     * `DELETE FROM media_items WHERE library_id = ?`. That statement used to wipe
     * every user's watch progress / favorites / ratings for the library because
     * `user_item_data` (and the watch-history tables) reference `media_items(id)`
     * with `ON DELETE CASCADE`. The new implementation re-scans in place and only
     * prunes items whose source file is gone.
     */
    public function testRescanIsNonDestructiveAndPreservesSurvivingRows(): void
    {
        // A file that still exists on disk (its row must survive the rescan).
        $survivingPath = tempnam(sys_get_temp_dir(), 'phlix_rescan_keep_');
        $this->assertIsString($survivingPath);

        $queries = [];
        $db = $this->makeDb($queries, [
            'library_row' => [
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'video',
                'paths' => json_encode(['/nonexistent/library/path']),
                'options' => json_encode([]),
            ],
            'prune_rows' => [
                ['id' => 'keep-1', 'path' => $survivingPath],
            ],
            'count_before' => 3,
            'count_after' => 3,
        ]);

        $scanner = $this->createMock(MediaScanner::class);
        $watcher = $this->createMock(FolderWatcher::class);
        $musicLibraryService = $this->createMock(MusicLibraryService::class);

        $manager = new LibraryManager($db, $scanner, $watcher, $musicLibraryService);

        $result = $manager->rescanLibrary('lib-1');

        // The whole point: no library-wide DELETE (which would cascade user data).
        foreach ($queries as [$sql, $sqlParams]) {
            $this->assertStringNotContainsString(
                'DELETE FROM media_items WHERE library_id',
                $sql,
                'rescan must not delete media_items by library_id',
            );
        }

        // The surviving file's row must NOT be pruned (its file still exists).
        $this->assertNotContains(
            ['DELETE FROM media_items WHERE id = ?', ['keep-1']],
            $this->deletes($queries),
        );

        $this->assertInstanceOf(ScanResult::class, $result);

        @unlink($survivingPath);
    }

    /**
     * A file that no longer exists on disk is pruned (its leaf row deleted by id),
     * while a surviving file's row is left in place.
     */
    public function testRescanPrunesItemsWhoseSourceFileIsGone(): void
    {
        // An accessible library root so the storage-availability guard permits
        // pruning (the surviving file lives inside it).
        $rootDir = sys_get_temp_dir() . '/phlix_rescan_root_' . uniqid();
        mkdir($rootDir, 0755, true);
        $survivingPath = tempnam($rootDir, 'phlix_rescan_keep_');
        $this->assertIsString($survivingPath);
        // A genuinely-removed file that lived UNDER the accessible root.
        $gonePath = $rootDir . '/removed-file.mkv';

        $queries = [];
        $db = $this->makeDb($queries, [
            'library_row' => [
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'video',
                'paths' => json_encode([$rootDir]),
                'options' => json_encode([]),
            ],
            'prune_rows' => [
                ['id' => 'keep-1', 'path' => $survivingPath],
                ['id' => 'gone-1', 'path' => $gonePath],
                // A synthetic container row must never be file-existence checked.
                ['id' => 'series-1', 'path' => 'series:lib-1:the-office'],
            ],
            'count_before' => 3,
            'count_after' => 2,
        ]);

        $scanner = $this->createMock(MediaScanner::class);
        $watcher = $this->createMock(FolderWatcher::class);
        $musicLibraryService = $this->createMock(MusicLibraryService::class);

        $manager = new LibraryManager($db, $scanner, $watcher, $musicLibraryService);

        $result = $manager->rescanLibrary('lib-1');

        $deletes = $this->deletes($queries);

        // The removed file's leaf row IS pruned.
        $this->assertContains(['DELETE FROM media_items WHERE id = ?', ['gone-1']], $deletes);
        // The surviving file's row is preserved.
        $this->assertNotContains(['DELETE FROM media_items WHERE id = ?', ['keep-1']], $deletes);
        // The synthetic container is not filesystem-checked / pruned as a leaf.
        $this->assertNotContains(['DELETE FROM media_items WHERE id = ?', ['series-1']], $deletes);

        // removed = 1 leaf pruned; ScanResult surfaces it.
        $this->assertSame(1, $result->removed);

        @unlink($survivingPath);
        @rmdir($rootDir);
    }

    /**
     * Empty series/season containers left behind after leaf pruning are removed
     * (seasons first, then series).
     */
    public function testRescanPrunesEmptyContainers(): void
    {
        // An accessible library root so the storage-availability guard permits
        // pruning of the now-empty containers.
        $rootDir = sys_get_temp_dir() . '/phlix_rescan_root_' . uniqid();
        mkdir($rootDir, 0755, true);

        $queries = [];
        $db = $this->makeDb($queries, [
            'library_row' => [
                'id' => 'lib-1',
                'name' => 'Shows',
                'type' => 'series',
                'paths' => json_encode([$rootDir]),
                'options' => json_encode([]),
            ],
            'prune_rows' => [],
            'count_before' => 2,
            'count_after' => 0,
            'childless_season' => [['id' => 'season-1']],
            'childless_series' => [['id' => 'series-1']],
        ]);

        $scanner = $this->createMock(MediaScanner::class);
        $watcher = $this->createMock(FolderWatcher::class);
        $musicLibraryService = $this->createMock(MusicLibraryService::class);

        $manager = new LibraryManager($db, $scanner, $watcher, $musicLibraryService);

        $result = $manager->rescanLibrary('lib-1');

        $deletes = $this->deletes($queries);
        $this->assertContains(['DELETE FROM media_items WHERE id = ?', ['season-1']], $deletes);
        $this->assertContains(['DELETE FROM media_items WHERE id = ?', ['series-1']], $deletes);

        // Two empty containers pruned.
        $this->assertSame(2, $result->removed);

        @rmdir($rootDir);
    }

    /**
     * CRITICAL data-loss guard: when the library's storage is temporarily
     * unavailable (unmounted NAS/SMB/USB, autofs not triggered, misconfigured
     * path) the configured root is not a directory, the scan finds ZERO files,
     * and an unguarded prune would see file_exists()===false for EVERY item and
     * DELETE the whole library — cascading into user_item_data / watch-history.
     * The guard must SKIP pruning entirely: no deletes issued, items + user data
     * intact, ScanResult.removed == 0.
     */
    public function testRescanSkipsPruneWhenLibraryRootIsUnavailable(): void
    {
        $queries = [];
        $db = $this->makeDb($queries, [
            'library_row' => [
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'video',
                // Root is not a directory — storage unavailable / unmounted.
                'paths' => json_encode(['/nonexistent/unmounted/library/path']),
                'options' => json_encode([]),
            ],
            // DB still holds items — a naive prune would delete them all.
            'prune_rows' => [
                ['id' => 'keep-1', 'path' => '/nonexistent/unmounted/library/path/a.mkv'],
                ['id' => 'keep-2', 'path' => '/nonexistent/unmounted/library/path/b.mkv'],
            ],
            'count_before' => 2,
            'count_after' => 2,
        ]);

        $scanner = $this->createMock(MediaScanner::class);
        $watcher = $this->createMock(FolderWatcher::class);
        $musicLibraryService = $this->createMock(MusicLibraryService::class);

        $manager = new LibraryManager($db, $scanner, $watcher, $musicLibraryService);

        $result = $manager->rescanLibrary('lib-1');

        // NO deletes at all — neither library-wide nor per-item leaf deletes.
        $deletes = $this->deletes($queries);
        $this->assertSame([], $deletes, 'unavailable storage must issue no DELETE');
        foreach ($queries as [$sql, $sqlParams]) {
            $this->assertStringNotContainsString(
                'DELETE FROM media_items WHERE library_id',
                $sql,
            );
        }

        // Nothing pruned — user data preserved.
        $this->assertSame(0, $result->removed);
    }

    /**
     * A single accessible root that has been legitimately emptied (the directory
     * still exists / is mounted, but every file it once held is gone) must prune
     * ALL of its now-missing leaf items — the storage IS reachable, the media was
     * genuinely removed. This proves the prior "emptied library never prunes"
     * regression is fixed: per-item-root scoping replaces the old blunt
     * presentCount===0 heuristic that skipped pruning here.
     */
    public function testRescanPrunesAllItemsWhenAccessibleRootLegitimatelyEmptied(): void
    {
        // Root exists (empty dir, i.e. mounted) but none of the DB items' files
        // are present any more.
        $rootDir = sys_get_temp_dir() . '/phlix_rescan_root_' . uniqid();
        mkdir($rootDir, 0755, true);

        $queries = [];
        $db = $this->makeDb($queries, [
            'library_row' => [
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'video',
                'paths' => json_encode([$rootDir]),
                'options' => json_encode([]),
            ],
            'prune_rows' => [
                ['id' => 'gone-1', 'path' => $rootDir . '/gone-a.mkv'],
                ['id' => 'gone-2', 'path' => $rootDir . '/gone-b.mkv'],
            ],
            'count_before' => 2,
            'count_after' => 0,
        ]);

        $scanner = $this->createMock(MediaScanner::class);
        $watcher = $this->createMock(FolderWatcher::class);
        $musicLibraryService = $this->createMock(MusicLibraryService::class);

        $manager = new LibraryManager($db, $scanner, $watcher, $musicLibraryService);

        $result = $manager->rescanLibrary('lib-1');

        $deletes = $this->deletes($queries);
        $this->assertContains(['DELETE FROM media_items WHERE id = ?', ['gone-1']], $deletes);
        $this->assertContains(['DELETE FROM media_items WHERE id = ?', ['gone-2']], $deletes);
        $this->assertSame(2, $result->removed);

        @rmdir($rootDir);
    }

    /**
     * CRITICAL multi-root partial-mount data-loss guard. A library configured with
     * two roots — root A mounted/accessible, root B unmounted — must prune only the
     * genuinely-gone file UNDER the accessible root A, while EVERY item whose path
     * lives under the unavailable root B is preserved (its storage is merely
     * unreachable, not removed). Deleting B's items here would cascade through
     * `ON DELETE CASCADE` into user_item_data / watch-history — silent partial
     * data-loss the old ANY-root-accessible + presentCount heuristic allowed.
     */
    public function testRescanMultiRootPreservesItemsUnderUnavailableRoot(): void
    {
        // Root A: an accessible temp dir holding one present file; one path under
        // A is genuinely gone.
        $rootA = sys_get_temp_dir() . '/phlix_rescan_rootA_' . uniqid();
        mkdir($rootA, 0755, true);
        $presentA = tempnam($rootA, 'phlix_rescan_keep_');
        $this->assertIsString($presentA);
        $goneA = $rootA . '/gone-under-a.mkv';

        // Root B: an unmounted / non-existent directory whose items are in the DB.
        $rootB = '/nonexistent/unmounted/nas/root_' . uniqid();

        $queries = [];
        $db = $this->makeDb($queries, [
            'library_row' => [
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'video',
                'paths' => json_encode([$rootA, $rootB]),
                'options' => json_encode([]),
            ],
            'prune_rows' => [
                ['id' => 'keepA', 'path' => $presentA],
                ['id' => 'goneA', 'path' => $goneA],
                ['id' => 'b1', 'path' => $rootB . '/show-a.mkv'],
                ['id' => 'b2', 'path' => $rootB . '/show-b.mkv'],
            ],
            'count_before' => 4,
            'count_after' => 3,
        ]);

        $scanner = $this->createMock(MediaScanner::class);
        $watcher = $this->createMock(FolderWatcher::class);
        $musicLibraryService = $this->createMock(MusicLibraryService::class);

        $manager = new LibraryManager($db, $scanner, $watcher, $musicLibraryService);

        $result = $manager->rescanLibrary('lib-1');

        $deletes = $this->deletes($queries);

        // The gone file UNDER the accessible root A is pruned.
        $this->assertContains(['DELETE FROM media_items WHERE id = ?', ['goneA']], $deletes);
        // The present file under root A is kept.
        $this->assertNotContains(['DELETE FROM media_items WHERE id = ?', ['keepA']], $deletes);
        // EVERY item under the unavailable root B is preserved.
        $this->assertNotContains(['DELETE FROM media_items WHERE id = ?', ['b1']], $deletes);
        $this->assertNotContains(['DELETE FROM media_items WHERE id = ?', ['b2']], $deletes);

        // removed counts only root A's genuinely-gone file.
        $this->assertSame(1, $result->removed);

        @unlink($presentA);
        @rmdir($rootA);
    }

    /**
     * Build a mocked Workerman MySQL connection whose query() dispatches on the
     * SQL text, records every call into $queries (by reference), and returns the
     * canned rows for the library row / prune scan / count / childless-container
     * queries a rescan issues.
     *
     * @param list<array{0:string,1:array<int,mixed>}> $queries Recorder (by ref).
     * @param array<string, mixed>                     $fixtures Canned responses.
     */
    private function makeDb(array &$queries, array $fixtures): Connection
    {
        $countCall = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$queries, $fixtures, &$countCall): mixed {
                $queries[] = [$sql, $params];

                if (str_contains($sql, 'FROM libraries WHERE id')) {
                    $row = $fixtures['library_row'] ?? null;
                    return is_array($row) ? [$row] : [];
                }
                if (str_contains($sql, 'COUNT(*) AS item_count')) {
                    // First COUNT is "before", subsequent is "after".
                    $countCall++;
                    $key = $countCall === 1 ? 'count_before' : 'count_after';
                    return [['item_count' => $fixtures[$key] ?? 0]];
                }
                if (str_contains($sql, 'SELECT id, path FROM media_items WHERE library_id')) {
                    $rows = $fixtures['prune_rows'] ?? [];
                    return is_array($rows) ? $rows : [];
                }
                if (str_contains($sql, 'NOT EXISTS') && str_contains($sql, 'c.type = ?')) {
                    $type = $params[1] ?? null;
                    if ($type === 'season') {
                        return $fixtures['childless_season'] ?? [];
                    }
                    if ($type === 'series') {
                        return $fixtures['childless_series'] ?? [];
                    }
                    return [];
                }
                if (str_starts_with($sql, 'DELETE')) {
                    return 1;
                }
                return [];
            },
        );

        return $db;
    }

    /**
     * Extract the [sql, params] pairs for DELETE statements from the recorder.
     *
     * @param list<array{0:string,1:array<int,mixed>}> $queries
     * @return list<array{0:string,1:array<int,mixed>}>
     */
    private function deletes(array $queries): array
    {
        $out = [];
        foreach ($queries as $entry) {
            if (str_starts_with($entry[0], 'DELETE')) {
                $out[] = $entry;
            }
        }
        return $out;
    }
}
