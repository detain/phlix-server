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

    // --- helpers -----------------------------------------------------------

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

            public function findByPath(string $path): ?array
            {
                foreach ($this->store as $item) {
                    if (($item['path'] ?? null) === $path) {
                        return $item;
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
