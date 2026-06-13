<?php

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\ItemRepository;
use Phlix\Common\Logger\LoggerFactory;
use Workerman\MySQL\Connection;

class MediaScannerTest extends TestCase
{
    /** @var string Temporary directory created per test for filesystem scans. */
    private string $tmpDir = '';

    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    public function testCanCreateMediaScanner(): void
    {
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $this->createMock(ItemRepository::class)
        );

        $this->assertInstanceOf(MediaScanner::class, $scanner);
    }

    public function testSeriesScanBuildsSeriesSeasonEpisodeHierarchy(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        $this->tmpDir = $this->makeTempDirWith([
            '24 S01E01.mkv',
            '24 S01E02.mkv',
            '24 S02E01.mkv',
        ]);

        $scanner->scan('lib-1', $this->tmpDir, 'series');

        $items = $repo->items();

        $series = array_values(array_filter($items, fn ($i) => $i['type'] === 'series'));
        $seasons = array_values(array_filter($items, fn ($i) => $i['type'] === 'season'));
        $episodes = array_values(array_filter($items, fn ($i) => $i['type'] === 'episode'));

        // Exactly one series container, two seasons, three episodes.
        $this->assertCount(1, $series, 'one series container for "24"');
        $this->assertCount(2, $seasons, 'season 1 and season 2 containers');
        $this->assertCount(3, $episodes, 'three episode rows');

        // Series is top-level (no parent) and named after the show.
        $this->assertNull($series[0]['parent_id']);
        $this->assertSame('24', $series[0]['name']);

        // Both seasons hang off the series.
        foreach ($seasons as $season) {
            $this->assertSame($series[0]['id'], $season['parent_id']);
        }

        // Each episode hangs off a season (never directly off the series, never top-level).
        $seasonIds = array_map(fn ($s) => $s['id'], $seasons);
        foreach ($episodes as $ep) {
            $this->assertNotNull($ep['parent_id'], 'episode must have a season parent');
            $this->assertContains($ep['parent_id'], $seasonIds, 'episode parent is a season');
            $this->assertArrayHasKey('season', $ep['metadata_json']);
            $this->assertArrayHasKey('episode', $ep['metadata_json']);
        }
    }

    public function testEpisodesShareContainersAcrossSeasons(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        $this->tmpDir = $this->makeTempDirWith([
            'Breaking Bad S01E01.mkv',
            'Breaking Bad S01E02.mkv',
        ]);

        $scanner->scan('lib-1', $this->tmpDir, 'series');

        $episodes = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'episode'));
        $this->assertCount(2, $episodes);
        // Both episodes of season 1 share one season container.
        $this->assertSame($episodes[0]['parent_id'], $episodes[1]['parent_id']);
    }

    public function testMovieLibraryStillProducesTopLevelMovies(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        $this->tmpDir = $this->makeTempDirWith([
            'Inception (2010).mkv',
        ]);

        $scanner->scan('lib-1', $this->tmpDir, 'movie');

        $items = $repo->items();
        $this->assertCount(1, $items);
        $this->assertSame('movie', $items[0]['type']);
        $this->assertNull($items[0]['parent_id']);
    }

    public function testSeriesLibraryNonEpisodeFileBecomesMovieNotSeries(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        $this->tmpDir = $this->makeTempDirWith([
            'A Random Documentary.mkv',
        ]);

        $scanner->scan('lib-1', $this->tmpDir, 'series');

        $items = $repo->items();
        $this->assertCount(1, $items);
        // A loose, non-episodic file in a series library must NOT become a bogus
        // top-level "series" (the old determineMediaType bug); it falls back to movie.
        $this->assertSame('movie', $items[0]['type']);
        $this->assertNull($items[0]['parent_id']);
    }

    public function testSeriesPerDirectoryGroupsEachDirectoryAsOneSeries(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        // Two distinct series dirs; episode filenames carry only SxxExx markers,
        // not the show name. Plus a loose file directly under the library root.
        $this->tmpDir = $this->makeTempTree([
            'Some Show (2013)' => [
                'Some Show S01E01 1080p.mkv',
                'Some Show S01E02 1080p.mkv',
            ],
            'Cowboy Bebop [1998]' => [
                'CB S01E01.mkv',
            ],
        ], ['loose-file.mkv']);

        $scanner->scan('lib-1', $this->tmpDir, 'series', true);

        $items = $repo->items();
        $series = array_values(array_filter($items, fn ($i) => $i['type'] === 'series'));
        $episodes = array_values(array_filter($items, fn ($i) => $i['type'] === 'episode'));

        // Exactly one series container per directory.
        $this->assertCount(2, $series, 'one series container per top-level directory');

        $seriesNames = array_map(fn ($s) => $s['name'], $series);
        sort($seriesNames);
        $this->assertSame(['Cowboy Bebop', 'Some Show'], $seriesNames);

        // The folder-derived series_title (year-stripped) + year are persisted as
        // the match hint on the series container metadata.
        $someShow = $this->seriesByName($series, 'Some Show');
        $this->assertSame('Some Show', $someShow['metadata_json']['series_title']);
        $this->assertSame(2013, $someShow['metadata_json']['year']);

        $bebop = $this->seriesByName($series, 'Cowboy Bebop');
        $this->assertSame('Cowboy Bebop', $bebop['metadata_json']['series_title']);
        $this->assertSame(1998, $bebop['metadata_json']['year']);

        // 3 episodes total (2 under Some Show, 1 under Cowboy Bebop). The loose
        // root file is NOT an episode and must not crash the scan.
        $this->assertCount(3, $episodes);

        // The loose root file fell through to the normal (filename) path → movie.
        $loose = array_values(array_filter($items, fn ($i) => $i['type'] === 'movie'));
        $this->assertCount(1, $loose, 'the loose root file is handled as a movie, not crashed');
    }

    public function testSeriesPerDirectoryAttachesEpisodesToFolderSeriesIgnoringFilenameTitle(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        // Folder name is the real show; the two episode filenames carry DIFFERENT
        // (wrong) title text. They must still attach under the folder's series.
        $this->tmpDir = $this->makeTempTree([
            'Assassination Classroom (2013)' => [
                'AssClass S01E01.mkv',
                'Something Totally Else S01E02.mkv',
            ],
        ]);

        $scanner->scan('lib-1', $this->tmpDir, 'series', true);

        $items = $repo->items();
        $series = array_values(array_filter($items, fn ($i) => $i['type'] === 'series'));
        $seasons = array_values(array_filter($items, fn ($i) => $i['type'] === 'season'));
        $episodes = array_values(array_filter($items, fn ($i) => $i['type'] === 'episode'));

        // ONE series regardless of the divergent filename titles — the whole point.
        $this->assertCount(1, $series);
        $this->assertSame('Assassination Classroom', $series[0]['name']);
        $this->assertSame('Assassination Classroom', $series[0]['metadata_json']['series_title']);
        $this->assertSame(2013, $series[0]['metadata_json']['year']);

        // One shared season; both episodes hang off it.
        $this->assertCount(1, $seasons);
        $this->assertSame($series[0]['id'], $seasons[0]['parent_id']);
        $this->assertCount(2, $episodes);
        foreach ($episodes as $ep) {
            $this->assertSame($seasons[0]['id'], $ep['parent_id']);
        }
    }

    public function testSeriesPerDirectoryFalseReproducesPriorFilenameGrouping(): void
    {
        // Regression guard: flag=false must reproduce the prior behaviour —
        // grouping by FILENAME title, not by directory. Two different-titled dirs
        // with episodes named after their real shows yield two filename-derived
        // series, exactly as before the feature.
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        $this->tmpDir = $this->makeTempTree([
            'Some Folder Name' => [
                'Breaking Bad S01E01.mkv',
            ],
            'Another Folder' => [
                '24 S01E01.mkv',
            ],
        ]);

        // Default (flag omitted) and explicit false must behave identically.
        $scanner->scan('lib-1', $this->tmpDir, 'series', false);

        $items = $repo->items();
        $series = array_values(array_filter($items, fn ($i) => $i['type'] === 'series'));

        $seriesNames = array_map(fn ($s) => $s['name'], $series);
        sort($seriesNames);
        // Series come from the FILENAME, not the directory name.
        $this->assertSame(['24', 'Breaking Bad'], $seriesNames);

        // And the folder-derived series_title hint is NOT written in flat mode.
        foreach ($series as $s) {
            $this->assertArrayNotHasKey('series_title', $s['metadata_json']);
        }
    }

    public function testSeriesPerDirectoryIgnoredForNonSeriesLibraryType(): void
    {
        // The per-directory branch only fires for type='series'. A movie library
        // with the flag set still walks recursively (flat), so the nested file is
        // discovered as a movie.
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        $this->tmpDir = $this->makeTempTree([
            'Inception (2010)' => [
                'Inception (2010).mkv',
            ],
        ]);

        $scanner->scan('lib-1', $this->tmpDir, 'movie', true);

        $items = $repo->items();
        $this->assertCount(1, $items);
        $this->assertSame('movie', $items[0]['type']);
    }

    public function testSeriesPerDirectoryStampsHintOnExistingContainerWithoutClobbering(): void
    {
        // Simulate a library that was ALREADY scanned (the prod TV/Anime case):
        // a series container already exists at the stable synthetic path, with
        // real metadata (tmdb_id/poster) but NO folder-derived hint yet. A plain
        // rescan with the flag on must STAMP the hint while preserving the
        // existing metadata keys.
        $repo = $this->makeFakeRepo();

        // Synthetic path = "series:<lib>:<slug(full basename)>".
        $syntheticPath = 'series:lib-1:assassination-classroom-2013';
        $repo->seed([
            'id' => 'existing-series',
            'library_id' => 'lib-1',
            'parent_id' => null,
            'name' => 'Assassination Classroom',
            'type' => 'series',
            'path' => $syntheticPath,
            'metadata_json' => ['tmdb_id' => 999, 'poster_url' => 'http://x/p.jpg'],
        ]);

        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        $this->tmpDir = $this->makeTempTree([
            'Assassination Classroom (2013)' => [
                'AC S01E01.mkv',
            ],
        ]);

        $scanner->scan('lib-1', $this->tmpDir, 'series', true);

        // Exactly one series container (the existing one was reused, not duped).
        $series = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'series'));
        $this->assertCount(1, $series);
        $meta = $series[0]['metadata_json'];

        // Hint stamped...
        $this->assertSame('Assassination Classroom', $meta['series_title']);
        $this->assertSame(2013, $meta['year']);
        // ...without clobbering the pre-existing metadata.
        $this->assertSame(999, $meta['tmdb_id']);
        $this->assertSame('http://x/p.jpg', $meta['poster_url']);
    }

    public function testSeriesPerDirectoryIsIdempotentWhenHintAlreadyMatches(): void
    {
        // When the existing container ALREADY carries the exact same hint, a
        // rescan must NOT issue a metadata write (stay idempotent / cheap).
        $repo = $this->makeFakeRepo();

        $syntheticPath = 'series:lib-1:assassination-classroom-2013';
        $repo->seed([
            'id' => 'existing-series',
            'library_id' => 'lib-1',
            'parent_id' => null,
            'name' => 'Assassination Classroom',
            'type' => 'series',
            'path' => $syntheticPath,
            'metadata_json' => [
                'series_title' => 'Assassination Classroom',
                'year' => 2013,
                'tmdb_id' => 999,
            ],
        ]);

        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        $this->tmpDir = $this->makeTempTree([
            'Assassination Classroom (2013)' => [
                'AC S01E01.mkv',
            ],
        ]);

        $scanner->scan('lib-1', $this->tmpDir, 'series', true);

        // No metadata UPDATE on the already-correct series container.
        $seriesUpdates = array_filter($repo->updates, fn ($u) => $u['id'] === 'existing-series');
        $this->assertCount(0, $seriesUpdates, 'no redundant hint write on an up-to-date container');
    }

    public function testSeriesPerDirectorySiblingYearFoldersDoNotMerge(): void
    {
        // Two sibling directories that differ ONLY by year must produce TWO
        // distinct series containers (their episodes must not merge).
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        $this->tmpDir = $this->makeTempTree([
            'The Office (2005)' => ['The Office S01E01.mkv'],
            'The Office (2001)' => ['The Office S01E01.mkv'],
        ]);

        $scanner->scan('lib-1', $this->tmpDir, 'series', true);

        $series = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'series'));
        $this->assertCount(2, $series, 'distinct year folders => distinct series containers');

        $paths = array_map(fn ($s) => $s['path'], $series);
        sort($paths);
        $this->assertSame(
            ['series:lib-1:the-office-2001', 'series:lib-1:the-office-2005'],
            $paths
        );

        // The two same-named episodes land under DIFFERENT seasons (no merge).
        $episodes = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'episode'));
        $this->assertCount(2, $episodes);
        $this->assertNotSame($episodes[0]['parent_id'], $episodes[1]['parent_id']);
    }

    public function testSeriesPerDirectoryPunctuationVariantFoldersDoNotMerge(): void
    {
        // "Re:Zero" vs "Re Zero" slug identically on the bare title; slugging the
        // full basename keeps them distinct only when the basenames differ. Here
        // the year-tagged folder vs the bare folder stay distinct.
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        $this->tmpDir = $this->makeTempTree([
            'Re Zero (2016)' => ['RZ S01E01.mkv'],
            'Re Zero' => ['RZ S02E01.mkv'],
        ]);

        $scanner->scan('lib-1', $this->tmpDir, 'series', true);

        $series = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'series'));
        $this->assertCount(2, $series, 'distinct basenames => distinct series containers');
    }

    // --- helpers -----------------------------------------------------------

    /**
     * Find the (single) series container row with a given display name.
     *
     * @param array<int, array<string, mixed>> $series
     * @return array<string, mixed>
     */
    private function seriesByName(array $series, string $name): array
    {
        foreach ($series as $row) {
            if (($row['name'] ?? null) === $name) {
                return $row;
            }
        }
        $this->fail("No series container named {$name}");
    }

    /**
     * Build a temp library tree: a map of subdir => [filenames], plus optional
     * loose files placed directly under the library root.
     *
     * @param array<string, array<int, string>> $dirs      Subdir => episode filenames.
     * @param array<int, string>                 $rootFiles Loose files at the root.
     */
    private function makeTempTree(array $dirs, array $rootFiles = []): string
    {
        $root = sys_get_temp_dir() . '/phlix_scan_tree_' . uniqid();
        mkdir($root, 0775, true);
        foreach ($dirs as $dirName => $files) {
            $sub = $root . '/' . $dirName;
            mkdir($sub, 0775, true);
            foreach ($files as $file) {
                file_put_contents($sub . '/' . $file, 'x');
            }
        }
        foreach ($rootFiles as $file) {
            file_put_contents($root . '/' . $file, 'x');
        }
        return $root;
    }

    /**
     * Regression: episodes of a series whose TITLE contains a dot ("Dr. Stone")
     * must still be detected as episodes and grouped under one series. A blind
     * pathinfo() double-strip used to reduce "Dr. Stone S01E05 ….mkv" to "Dr",
     * losing the SxxExx marker so every such file mis-filed as a loose movie.
     */
    public function testDottedSeriesTitleGroupsAsEpisodesNotMovies(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        $this->tmpDir = $this->makeTempDirWith([
            'Dr. Stone S01E01 [1080p] Stone World.mkv',
            'Dr. Stone S01E02 [1080p] King of the Stone World.mkv',
            'Dr. STONE S02E01.mp4',
        ]);

        $scanner->scan('lib-1', $this->tmpDir, 'series');

        $items = $repo->items();
        $episodes = array_values(array_filter($items, fn ($i) => $i['type'] === 'episode'));
        $movies = array_values(array_filter($items, fn ($i) => $i['type'] === 'movie'));
        $series = array_values(array_filter($items, fn ($i) => $i['type'] === 'series'));

        $this->assertCount(3, $episodes, 'all three files detected as episodes');
        $this->assertCount(0, $movies, 'no file mis-filed as a loose movie');
        $this->assertCount(1, $series, 'one shared "Dr. Stone" series container');
        // Casing follows whichever file created the container first ("Dr. Stone"
        // vs "Dr. STONE"); both share one slug, so only the count is asserted
        // exactly while the name is matched case-insensitively.
        $this->assertEqualsIgnoringCase('Dr. Stone', (string) $series[0]['name']);
    }

    /**
     * Regression: a filename carrying stray non-UTF-8 bytes (a Windows-1252
     * 0x9C here) must be coerced to valid UTF-8 before INSERT — otherwise MySQL
     * raises 1366 and, with no per-file guard, the whole scan job aborts.
     */
    public function testNonUtf8FilenameIsSanitisedAndScanCompletes(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        // Raw 0x9C byte (œ in Windows-1252) embedded in the filename.
        $bad = "Co\x9Cur S01E01.mkv";
        $this->tmpDir = $this->makeTempDirWith([$bad, 'Clean Show S01E02.mkv']);

        // Must not throw, and must persist rows.
        $scanner->scan('lib-1', $this->tmpDir, 'series');

        $items = $repo->items();
        $this->assertNotEmpty($items, 'scan persisted at least one row');
        foreach ($items as $item) {
            $this->assertTrue(
                mb_check_encoding((string) $item['name'], 'UTF-8'),
                'every stored name must be valid UTF-8'
            );
            $this->assertTrue(
                mb_check_encoding((string) $item['path'], 'UTF-8'),
                'every stored path must be valid UTF-8'
            );
        }
    }

    /**
     * In-memory ItemRepository double: records create()s and supports findByPath
     * for the scanner's dedup + find-or-create-container logic.
     */
    private function makeFakeRepo(): ItemRepository
    {
        $mockConn = $this->createMock(Connection::class);

        return new class ($mockConn) extends ItemRepository {
            /** @var array<int, array<string, mixed>> */
            private array $store = [];
            private int $seq = 0;
            /** @var array<int, array{id: string, data: array<string, mixed>}> */
            public array $updates = [];

            public function findByPath(string $path): ?array
            {
                foreach ($this->store as $item) {
                    if (($item['path'] ?? null) === $path) {
                        // Mirror the real repo: expose decoded metadata under
                        // both `metadata_json` (array) and `metadata`.
                        $row = $item;
                        $row['metadata'] = is_array($item['metadata_json'] ?? null)
                            ? $item['metadata_json']
                            : [];
                        return $row;
                    }
                }
                return null;
            }

            public function create(array $data): string
            {
                $id = 'id-' . (++$this->seq);
                $this->store[] = [
                    'id' => $id,
                    'library_id' => $data['library_id'] ?? null,
                    'parent_id' => $data['parent_id'] ?? null,
                    'name' => $data['name'] ?? null,
                    'type' => $data['type'] ?? null,
                    'path' => $data['path'] ?? null,
                    'metadata_json' => $data['metadata_json'] ?? [],
                ];
                return $id;
            }

            public function update(string $id, array $data): void
            {
                $this->updates[] = ['id' => $id, 'data' => $data];
                foreach ($this->store as &$item) {
                    if (($item['id'] ?? null) === $id) {
                        if (array_key_exists('metadata_json', $data)) {
                            $item['metadata_json'] = $data['metadata_json'];
                        }
                        return;
                    }
                }
            }

            /**
             * Seed a pre-existing row (simulating a prior scan), returning its id.
             *
             * @param array<string, mixed> $row
             */
            public function seed(array $row): string
            {
                $id = $row['id'] ?? ('id-' . (++$this->seq));
                $id = is_string($id) ? $id : 'id-' . $this->seq;
                $this->store[] = array_merge(['id' => $id], $row);
                return $id;
            }

            /** @return array<int, array<string, mixed>> */
            public function items(): array
            {
                return $this->store;
            }
        };
    }

    /**
     * @param array<int, string> $filenames
     */
    private function makeTempDirWith(array $filenames): string
    {
        $dir = sys_get_temp_dir() . '/phlix_scan_test_' . uniqid();
        mkdir($dir, 0775, true);
        foreach ($filenames as $name) {
            file_put_contents($dir . '/' . $name, 'x');
        }
        return $dir;
    }

    private function removeDir(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
