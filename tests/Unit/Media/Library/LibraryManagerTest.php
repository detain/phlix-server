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
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Common\Logger\LoggerFactory;
use Workerman\MySQL\Connection;
use InvalidArgumentException;
use RuntimeException;

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
     * CRITICAL single-root unmount / empty-mountpoint data-loss guard (finding
     * #1). When a NAS/SMB/USB share unmounts, the mountpoint DIRECTORY usually
     * persists as an empty dir, so is_dir($root) still returns true (the root
     * looks "accessible") while EVERY file under it reports file_exists()===false.
     * is_dir() cannot distinguish "unmounted leftover" from "legitimately
     * emptied", so the per-root PRESENCE GUARD refuses to prune ANY root whose
     * attributed items are all missing: no deletes, removed==0, items + cascading
     * user data preserved. (Behaviour change: a genuinely-emptied library now
     * RETAINS its last items — intentional full clears use the explicit
     * "delete all items" op, NOT rescan.)
     */
    public function testRescanSkipsPruneForRootWithNoPresentFiles(): void
    {
        // Root exists (empty dir — indistinguishable from an unmounted mountpoint
        // leftover) but none of the DB items' files are present any more.
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
            'count_after' => 2,
        ]);

        $scanner = $this->createMock(MediaScanner::class);
        $watcher = $this->createMock(FolderWatcher::class);
        $musicLibraryService = $this->createMock(MusicLibraryService::class);

        $manager = new LibraryManager($db, $scanner, $watcher, $musicLibraryService);

        $result = $manager->rescanLibrary('lib-1');

        // Root has ZERO present items → presence guard skips it → NO deletes.
        $deletes = $this->deletes($queries);
        $this->assertSame([], $deletes, 'a root with no present files must issue no DELETE');
        $this->assertNotContains(['DELETE FROM media_items WHERE id = ?', ['gone-1']], $deletes);
        $this->assertNotContains(['DELETE FROM media_items WHERE id = ?', ['gone-2']], $deletes);
        $this->assertSame(0, $result->removed);

        @rmdir($rootDir);
    }

    /**
     * MULTI-ROOT sibling presence guard. Root A is accessible and holds one
     * present + one gone file (A HAS presence). Root B is present as an empty
     * directory (is_dir true — e.g. an unmounted mountpoint leftover) with all of
     * its attributed items gone. Only root A's gone file is pruned; every item
     * under root B is preserved because B has zero present items. removed==1.
     */
    public function testRescanMultiRootSiblingSkipsRootWithNoPresentFiles(): void
    {
        // Root A: accessible, one present file + one genuinely-gone file.
        $rootA = sys_get_temp_dir() . '/phlix_rescan_rootA_' . uniqid();
        mkdir($rootA, 0755, true);
        $presentA = tempnam($rootA, 'phlix_rescan_keep_');
        $this->assertIsString($presentA);
        $goneA = $rootA . '/gone-under-a.mkv';

        // Root B: present as an empty dir (is_dir true) but all its items gone.
        $rootB = sys_get_temp_dir() . '/phlix_rescan_rootB_' . uniqid();
        mkdir($rootB, 0755, true);

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
        // Root A HAS a present item → its gone file is pruned.
        $this->assertContains(['DELETE FROM media_items WHERE id = ?', ['goneA']], $deletes);
        $this->assertNotContains(['DELETE FROM media_items WHERE id = ?', ['keepA']], $deletes);
        // Root B has ZERO present items → presence guard preserves ALL its items.
        $this->assertNotContains(['DELETE FROM media_items WHERE id = ?', ['b1']], $deletes);
        $this->assertNotContains(['DELETE FROM media_items WHERE id = ?', ['b2']], $deletes);
        $this->assertSame(1, $result->removed);

        @unlink($presentA);
        @rmdir($rootA);
        @rmdir($rootB);
    }

    /**
     * NESTED-roots presence guard (finding #2). A library configured with a
     * parent root `/parent` (accessible, ≥1 present item) AND a nested child root
     * `/parent/child` (present as a dir, all its items gone). Each child item is
     * attributed to the MOST-SPECIFIC matching root — the child, not the parent —
     * so the child (zero present items) is skipped and its items are preserved,
     * while the parent's genuinely-gone file is pruned. This prevents items under
     * an unmounted nested root from being deleted just because they share the
     * accessible parent's path prefix.
     */
    public function testRescanNestedRootsSkipsChildWithNoPresentFiles(): void
    {
        // Parent root: accessible, holds one present file + one gone file.
        $parent = sys_get_temp_dir() . '/phlix_rescan_parent_' . uniqid();
        mkdir($parent, 0755, true);
        $presentParent = tempnam($parent, 'phlix_rescan_keep_');
        $this->assertIsString($presentParent);
        $goneParent = $parent . '/gone-parent.mkv';

        // Nested child root under the parent — present as a dir, all items gone.
        $child = $parent . '/child';
        mkdir($child, 0755, true);

        $queries = [];
        $db = $this->makeDb($queries, [
            'library_row' => [
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'video',
                'paths' => json_encode([$parent, $child]),
                'options' => json_encode([]),
            ],
            'prune_rows' => [
                ['id' => 'keepP', 'path' => $presentParent],
                ['id' => 'goneP', 'path' => $goneParent],
                ['id' => 'c1', 'path' => $child . '/ep-a.mkv'],
                ['id' => 'c2', 'path' => $child . '/ep-b.mkv'],
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
        // Parent HAS a present item → its gone file is pruned.
        $this->assertContains(['DELETE FROM media_items WHERE id = ?', ['goneP']], $deletes);
        $this->assertNotContains(['DELETE FROM media_items WHERE id = ?', ['keepP']], $deletes);
        // Child items attributed to the child root (most-specific) → child has
        // zero present items → preserved, NOT deleted via the parent's prefix.
        $this->assertNotContains(['DELETE FROM media_items WHERE id = ?', ['c1']], $deletes);
        $this->assertNotContains(['DELETE FROM media_items WHERE id = ?', ['c2']], $deletes);
        $this->assertSame(1, $result->removed);

        @unlink($presentParent);
        @rmdir($child);
        @rmdir($parent);
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

    // ---------------------------------------------------------------------
    // Fine-grained maintenance ops (migration 084): prune / clear_metadata /
    // clear_artwork / delete_all.
    // ---------------------------------------------------------------------

    /**
     * clear_metadata: NULLs metadata_refreshed_at and STRIPS every provider field
     * from metadata_json, while PRESERVING the filesystem-derived keys (title,
     * year, canonical_key, source, duration_seconds) and never deleting the item
     * row (no DELETE on media_items → user_item_data / watch history untouched).
     */
    public function testClearMetadataStripsProviderFieldsAndPreservesBasics(): void
    {
        $queries = [];
        $db = $this->makeDb($queries, [
            'library_row' => [
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'video',
                'paths' => json_encode(['/media']),
                'options' => json_encode([]),
            ],
            'count_before' => 2,
        ]);

        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnCallback(
            static function (string $lib, int $limit, int $offset): array {
                if ($offset !== 0) {
                    return [];
                }
                return [
                    [
                        'id' => 'i1',
                        'path' => '/media/a.mkv',
                        'metadata' => [
                            'title' => 'A Movie',
                            'year' => 2020,
                            'canonical_key' => 'a-movie-2020',
                            'source' => ['width' => 1920, 'height' => 1080],
                            'duration_seconds' => 7200,
                            'overview' => 'provider synopsis',
                            'poster_url' => 'https://image.tmdb.org/p.jpg',
                            'genres' => ['Action'],
                            'official_rating' => 'PG-13',
                            'cast' => ['Somebody'],
                            'vote_average' => 7.5,
                            'still_url' => 'https://image.tmdb.org/s.jpg',
                        ],
                    ],
                    [
                        'id' => 'i2',
                        'path' => '/media/b.mkv',
                        'metadata' => ['title' => 'B', 'backdrop_url' => 'https://x/b.jpg'],
                    ],
                ];
            },
        );

        $captured = [];
        $items->method('update')->willReturnCallback(
            static function (string $id, array $data) use (&$captured): void {
                $captured[$id] = $data;
            },
        );
        // No item rows are ever deleted by clear_metadata.
        $items->expects($this->never())->method('delete');
        $items->expects($this->never())->method('deleteByLibrary');

        $manager = $this->makeManager($db, $items, null);

        $this->assertSame(2, $manager->clearMetadata('lib-1'));

        // No library-wide media_items DELETE was issued (user data preserved).
        foreach ($this->deletes($queries) as [$sql]) {
            $this->assertStringNotContainsString('media_items', $sql);
        }

        $this->assertArrayHasKey('i1', $captured);
        $meta = $captured['i1']['metadata_json'];
        $this->assertIsArray($meta);

        // Provider fields stripped.
        foreach (['overview', 'poster_url', 'genres', 'official_rating', 'cast', 'vote_average', 'still_url'] as $k) {
            $this->assertArrayNotHasKey($k, $meta, "provider key {$k} should be stripped");
        }
        // Filesystem/probe-derived basics preserved.
        $this->assertSame('A Movie', $meta['title']);
        $this->assertSame(2020, $meta['year']);
        $this->assertSame('a-movie-2020', $meta['canonical_key']);
        $this->assertArrayHasKey('source', $meta);
        $this->assertSame(7200, $meta['duration_seconds']);
        // path is never part of the update payload (row identity preserved).
        $this->assertArrayNotHasKey('path', $captured['i1']);
        // metadata_refreshed_at explicitly NULLed.
        $this->assertArrayHasKey('metadata_refreshed_at', $captured['i1']);
        $this->assertNull($captured['i1']['metadata_refreshed_at']);
    }

    /**
     * clear_metadata throws (so the job is marked failed) when no ItemRepository
     * dependency was injected.
     */
    public function testClearMetadataThrowsWithoutItemRepository(): void
    {
        $queries = [];
        $db = $this->makeDb($queries, []);
        $manager = $this->makeManager($db, null, null);

        $this->expectException(RuntimeException::class);
        $manager->clearMetadata('lib-1');
    }

    /**
     * clear_metadata throws InvalidArgumentException for a missing library.
     */
    public function testClearMetadataThrowsForMissingLibrary(): void
    {
        $queries = [];
        $db = $this->makeDb($queries, ['library_row' => null]);
        $items = $this->createMock(ItemRepository::class);
        $manager = $this->makeManager($db, $items, null);

        $this->expectException(InvalidArgumentException::class);
        $manager->clearMetadata('missing');
    }

    /**
     * clear_artwork: calls ArtworkStorage::deleteItemArtwork() for every item,
     * NULLs ONLY local (/api/v1/artwork/…) poster/logo URLs so they re-derive,
     * and leaves remote URLs + all other metadata text untouched.
     */
    public function testClearArtworkDeletesCachedArtworkAndClearsLocalUrls(): void
    {
        $queries = [];
        $db = $this->makeDb($queries, [
            'library_row' => [
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'video',
                'paths' => json_encode(['/media']),
                'options' => json_encode([]),
            ],
            'count_before' => 2,
        ]);

        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnCallback(
            static function (string $lib, int $limit, int $offset): array {
                if ($offset !== 0) {
                    return [];
                }
                return [
                    [
                        'id' => 'i1',
                        'path' => '/media/a.mkv',
                        'metadata' => [
                            'title' => 'A',
                            'overview' => 'keep me',
                            'poster_url' => '/api/v1/artwork/i1?size=w500',
                            'logo_url' => '/api/v1/artwork/i1?size=logo',
                        ],
                    ],
                    [
                        'id' => 'i2',
                        'path' => '/media/b.mkv',
                        'metadata' => ['title' => 'B', 'poster_url' => 'https://image.tmdb.org/x.jpg'],
                    ],
                ];
            },
        );

        $captured = [];
        $items->method('update')->willReturnCallback(
            static function (string $id, array $data) use (&$captured): void {
                $captured[$id] = $data;
            },
        );

        $deleted = [];
        $artwork = $this->createMock(ArtworkStorage::class);
        $artwork->method('deleteItemArtwork')->willReturnCallback(
            static function (string $id) use (&$deleted): void {
                $deleted[] = $id;
            },
        );

        $manager = $this->makeManager($db, $items, $artwork);

        $this->assertSame(2, $manager->clearArtwork('lib-1'));

        // Every item's on-disk artwork cache is dropped.
        $this->assertSame(['i1', 'i2'], $deleted);

        // i1 had LOCAL poster/logo URLs → they were cleared, metadata text kept.
        $this->assertArrayHasKey('i1', $captured);
        $meta = $captured['i1']['metadata_json'];
        $this->assertIsArray($meta);
        $this->assertArrayNotHasKey('poster_url', $meta);
        $this->assertArrayNotHasKey('logo_url', $meta);
        $this->assertSame('keep me', $meta['overview'], 'metadata text must be preserved');
        $this->assertSame('A', $meta['title']);

        // i2 had only a REMOTE poster URL → nothing to clear → no update issued.
        $this->assertArrayNotHasKey('i2', $captured);
    }

    /**
     * clear_artwork throws when no ArtworkStorage dependency was injected.
     */
    public function testClearArtworkThrowsWithoutArtworkStorage(): void
    {
        $queries = [];
        $db = $this->makeDb($queries, []);
        $items = $this->createMock(ItemRepository::class);
        $manager = $this->makeManager($db, $items, null);

        $this->expectException(RuntimeException::class);
        $manager->clearArtwork('lib-1');
    }

    /**
     * prune reuses the shared pruneRemovedItems() pass: with an ACCESSIBLE root
     * that has a present file, an item whose file is gone IS pruned and the
     * present one is kept (guards intact).
     */
    public function testPruneLibraryDropsGoneItemsWhenRootAccessible(): void
    {
        $root = sys_get_temp_dir() . '/phlix_prune_' . uniqid();
        mkdir($root);
        $present = $root . '/present.mkv';
        touch($present);
        $gone = $root . '/gone.mkv'; // never created

        $queries = [];
        $db = $this->makeDb($queries, [
            'library_row' => [
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'video',
                'paths' => json_encode([$root]),
                'options' => json_encode([]),
            ],
            'prune_rows' => [
                ['id' => 'keep', 'path' => $present],
                ['id' => 'drop', 'path' => $gone],
            ],
        ]);

        $manager = $this->makeManager($db, null, null);
        $removed = $manager->pruneLibrary('lib-1');

        $deletes = $this->deletes($queries);
        $this->assertContains(['DELETE FROM media_items WHERE id = ?', ['drop']], $deletes);
        $this->assertNotContains(['DELETE FROM media_items WHERE id = ?', ['keep']], $deletes);
        $this->assertSame(1, $removed);

        @unlink($present);
        @rmdir($root);
    }

    /**
     * prune honours the safety guard: when NO configured root is accessible, it
     * refuses to delete anything (0 removed, no DELETE issued).
     */
    public function testPruneLibrarySkipsWhenNoRootAccessible(): void
    {
        $queries = [];
        $db = $this->makeDb($queries, [
            'library_row' => [
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'video',
                'paths' => json_encode(['/nonexistent/root']),
                'options' => json_encode([]),
            ],
            'prune_rows' => [
                ['id' => 'a', 'path' => '/nonexistent/root/a.mkv'],
            ],
        ]);

        $manager = $this->makeManager($db, null, null);
        $this->assertSame(0, $manager->pruneLibrary('lib-1'));
        $this->assertSame([], $this->deletes($queries));
    }

    /**
     * delete_all delegates to ItemRepository::deleteByLibrary() (so genre/stats
     * caches are invalidated) and returns the pre-delete item count.
     */
    public function testDeleteAllItemsDelegatesToRepositoryAndReturnsCount(): void
    {
        $queries = [];
        $db = $this->makeDb($queries, [
            'library_row' => [
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'video',
                'paths' => json_encode(['/media']),
                'options' => json_encode([]),
            ],
            'count_before' => 7,
        ]);

        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->once())->method('deleteByLibrary')->with('lib-1');

        $manager = $this->makeManager($db, $items, null);
        $this->assertSame(7, $manager->deleteAllItems('lib-1'));
    }

    /**
     * delete_all falls back to a direct parameterised DELETE when no
     * ItemRepository is wired.
     */
    public function testDeleteAllItemsFallsBackToDirectDelete(): void
    {
        $queries = [];
        $db = $this->makeDb($queries, [
            'library_row' => [
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'video',
                'paths' => json_encode(['/media']),
                'options' => json_encode([]),
            ],
            'count_before' => 3,
        ]);

        $manager = $this->makeManager($db, null, null);
        $this->assertSame(3, $manager->deleteAllItems('lib-1'));

        $this->assertContains(
            ['DELETE FROM media_items WHERE library_id = ?', ['lib-1']],
            $this->deletes($queries),
        );
    }

    /**
     * Build a LibraryManager with mocked scanner/watcher/music-service and the
     * optional item/artwork deps under test.
     */
    private function makeManager(
        Connection $db,
        ?ItemRepository $items,
        ?ArtworkStorage $artwork
    ): LibraryManager {
        return new LibraryManager(
            $db,
            $this->createMock(MediaScanner::class),
            $this->createMock(FolderWatcher::class),
            $this->createMock(MusicLibraryService::class),
            null,
            $items,
            $artwork,
        );
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
