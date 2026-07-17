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
        $survivingPath = tempnam(sys_get_temp_dir(), 'phlix_rescan_keep_');
        $this->assertIsString($survivingPath);
        $gonePath = '/nonexistent/path/removed-file.mkv';

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
    }

    /**
     * Empty series/season containers left behind after leaf pruning are removed
     * (seasons first, then series).
     */
    public function testRescanPrunesEmptyContainers(): void
    {
        $queries = [];
        $db = $this->makeDb($queries, [
            'library_row' => [
                'id' => 'lib-1',
                'name' => 'Shows',
                'type' => 'series',
                'paths' => json_encode(['/nonexistent/library/path']),
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
