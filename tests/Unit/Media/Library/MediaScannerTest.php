<?php

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\SeriesContainerNaming;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\MediaAsset\MediaAssetJobStore;
use Phlix\Media\SimilarityJobStore;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Shared\Events\Library\MediaItemAdded;
use Psr\EventDispatcher\EventDispatcherInterface;
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

    /**
     * Narrow a loosely-typed item row's metadata_json into an array for
     * assertions (the in-memory repo double stores it as mixed).
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function metaOf(array $item): array
    {
        $meta = $item['metadata_json'] ?? [];
        return is_array($meta) ? $meta : [];
    }

    /**
     * Narrow a nested array-valued key (e.g. metadata_json's `source` block).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function arrOf(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        return is_array($value) ? $value : [];
    }

    /**
     * Narrow a scalar row field to a string for path/name assertions.
     *
     * @param array<string, mixed> $data
     */
    private function strOf(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        return is_scalar($value) ? (string) $value : '';
    }

    public function testCanCreateMediaScanner(): void
    {
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $this->createMock(ItemRepository::class)
        );

        $this->assertInstanceOf(MediaScanner::class, $scanner);
    }

    public function testCountFilesCountsOnlyMatchingMediaFiles(): void
    {
        $scanner = new MediaScanner($this->createMock(Connection::class), $this->makeFakeRepo());

        $this->tmpDir = $this->makeTempDirWith([
            'Movie One (2020).mkv',
            'Movie Two (2021).mp4',
            'notes.txt',     // wrong extension → not counted
            '.hidden.mkv',   // hidden → skipped
            'download.part', // skip pattern → skipped
        ]);

        // The denominator for a scan progress %: only the two real media files.
        $this->assertSame(2, $scanner->countFiles($this->tmpDir, 'movie'));
    }

    public function testCountFilesReturnsZeroForMissingPath(): void
    {
        $scanner = new MediaScanner($this->createMock(Connection::class), $this->makeFakeRepo());
        $this->assertSame(0, $scanner->countFiles('/no/such/path', 'movie'));
    }

    public function testScanInvokesOnFileForEachProcessedMediaFile(): void
    {
        $scanner = new MediaScanner($this->createMock(Connection::class), $this->makeFakeRepo());

        $this->tmpDir = $this->makeTempDirWith([
            'A (2020).mkv',
            'B (2021).mkv',
            'readme.txt', // skipped → no tick
        ]);

        $seen = [];
        $scanner->scan('lib-1', $this->tmpDir, 'movie', false, function (string $path) use (&$seen): void {
            $seen[] = basename($path);
        });

        sort($seen);
        // One progress tick per processed media file (the .txt is not ticked),
        // matching countFiles()'s denominator.
        $this->assertSame(['A (2020).mkv', 'B (2021).mkv'], $seen);
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
            $this->assertArrayHasKey('season', $this->metaOf($ep));
            $this->assertArrayHasKey('episode', $this->metaOf($ep));
        }
    }

    /**
     * F2b series/anime enrichment gap (consequence test). Background plugin
     * enrichment is driven by {@see MediaItemAdded} events, and both
     * BackgroundEnrichmentSubscriber and PluginMetadataEnricher gate on
     * ENRICHABLE_TYPES = ['movie','series']. The anime providers (anidb/mal)
     * match at the SERIES level — but the scanner historically dispatched
     * MediaItemAdded ONLY for leaf movie/episode items, so a freshly created
     * series PARENT container emitted no event and never auto-enriched.
     *
     * {@see MediaScanner::findOrCreateContainer()} now dispatches a
     * `series`-type MediaItemAdded on genuine creation of a top-level series
     * container. This pins the four consequences: exactly one `series` event
     * (one container) carrying that container's id, ZERO `season` events
     * (seasons are not an enrichable type and must never dispatch), and the
     * pre-existing leaf `episode` dispatch is preserved.
     */
    public function testSeriesContainerCreationDispatchesSeriesTypeMediaItemAdded(): void
    {
        $repo = $this->makeFakeRepo();
        $dispatcher = new LibraryRecordingEventDispatcher();
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $repo,
            null,
            $dispatcher
        );

        $this->tmpDir = $this->makeTempDirWith([
            '24 S01E01.mkv',
            '24 S01E02.mkv',
            '24 S02E01.mkv',
        ]);

        $scanner->scan('lib-1', $this->tmpDir, 'series');

        /** @var list<MediaItemAdded> $added */
        $added = array_values(array_filter(
            $dispatcher->events,
            fn ($e) => $e instanceof MediaItemAdded
        ));

        $seriesEvents = array_values(array_filter($added, fn ($e) => $e->type === 'series'));
        $seasonEvents = array_values(array_filter($added, fn ($e) => $e->type === 'season'));
        $episodeEvents = array_values(array_filter($added, fn ($e) => $e->type === 'episode'));

        // Exactly one series-container event...
        $this->assertCount(1, $seriesEvents, 'exactly one series-type MediaItemAdded is dispatched');

        // ...carrying the id of the sole series row in the repo.
        $seriesRows = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'series'));
        $this->assertCount(1, $seriesRows, 'one series container row was created');
        $this->assertSame(
            $seriesRows[0]['id'],
            $seriesEvents[0]->mediaItemId,
            'the series event references the created series container id'
        );

        // Seasons are NOT enrichable — they must never dispatch.
        $this->assertCount(0, $seasonEvents, 'season containers must NOT dispatch MediaItemAdded');

        // The existing leaf episode dispatch is preserved.
        $this->assertNotEmpty($episodeEvents, 'episode leaf items still dispatch MediaItemAdded');
    }

    /**
     * F2b create-only guard idempotency: the series dispatch fires ONLY on
     * genuine container creation. Both existing-row branches of
     * findOrCreateContainer() (findByPath hit, findTopLevelByCanonical hit)
     * return before the dispatch, so a second scan of the same library — where
     * the series container already exists — must record ZERO further `series`
     * events. Otherwise every rescan would re-enqueue enrichment for every show.
     */
    public function testSeriesContainerDispatchIsCreateOnlyNotOnRescan(): void
    {
        $repo = $this->makeFakeRepo();
        $dispatcher = new LibraryRecordingEventDispatcher();

        $this->tmpDir = $this->makeTempDirWith([
            '24 S01E01.mkv',
            '24 S01E02.mkv',
        ]);

        // First scan creates the container → one series event.
        (new MediaScanner($this->createMock(Connection::class), $repo, null, $dispatcher))
            ->scan('lib-1', $this->tmpDir, 'series');

        $firstSeries = array_values(array_filter(
            $dispatcher->events,
            fn ($e) => $e instanceof MediaItemAdded && $e->type === 'series'
        ));
        $this->assertCount(1, $firstSeries, 'first scan dispatches the series-container event');

        // Rescan with a FRESH scanner (empty containerCache) so resolution goes
        // through the existing-row branches exactly as a real second scan would.
        $dispatcher->reset();
        (new MediaScanner($this->createMock(Connection::class), $repo, null, $dispatcher))
            ->scan('lib-1', $this->tmpDir, 'series');

        $rescanSeries = array_values(array_filter(
            $dispatcher->events,
            fn ($e) => $e instanceof MediaItemAdded && $e->type === 'series'
        ));
        $this->assertCount(
            0,
            $rescanSeries,
            'the already-existing series container must NOT re-dispatch on rescan'
        );

        // And no duplicate series row was forked.
        $seriesRows = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'series'));
        $this->assertCount(1, $seriesRows, 'rescan reuses the single series container');
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

    /**
     * Step 1.2 — canonical-key container resolution. Two episode files whose
     * series titles slug DIFFERENTLY ("Hunter x Hunter" → "hunter-x-hunter",
     * "HunterxHunter" → "hunterxhunter") but share the SAME canonical dedup key
     * ("hunterxhunter") must resolve to ONE series container, not two. The first
     * file creates the series at its slug-path; the second misses both the path
     * cache and findByPath() but is reunited via findTopLevelByCanonical().
     */
    public function testEpisodesWithDifferentSlugsButSameCanonicalKeyShareOneSeries(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        $this->tmpDir = $this->makeTempDirWith([
            'Hunter x Hunter S01E01.mkv',
            'HunterxHunter S01E02.mkv',
            'Hunter.x.Hunter S01E03.mkv',
        ]);

        $scanner->scan('lib-1', $this->tmpDir, 'series');

        $items = $repo->items();
        $series = array_values(array_filter($items, fn ($i) => $i['type'] === 'series'));
        $episodes = array_values(array_filter($items, fn ($i) => $i['type'] === 'episode'));

        $this->assertCount(1, $series, 'three slug-variant filenames collapse to ONE series container');
        $this->assertCount(3, $episodes, 'all three episode rows are created');
        // Every season hangs off the single shared series.
        $seasons = array_values(array_filter($items, fn ($i) => $i['type'] === 'season'));
        foreach ($seasons as $season) {
            $this->assertSame($series[0]['id'], $season['parent_id']);
        }
        // The canonical key was persisted onto the container metadata.
        $this->assertSame('hunterxhunter', $this->metaOf($series[0])['canonical_key'] ?? null);
    }

    /**
     * Step 1.2 — legitimately distinct keys stay SEPARATE. Two series whose
     * folders give the SAME title but DIFFERENT years ("Hunter x Hunter (1999)"
     * vs "Hunter x Hunter (2011)") canonical-key to "hunterxhunter:1999" vs
     * "hunterxhunter:2011", so they must remain two distinct containers — the
     * canonical fallback must NOT merge genuinely different shows.
     */
    public function testSameTitleDifferentYearStaysSeparateContainers(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        $this->tmpDir = $this->makeTempTree([
            'Hunter x Hunter (1999)' => ['HxH S01E01.mkv'],
            'Hunter x Hunter (2011)' => ['HxH S01E01.mkv'],
        ]);

        $scanner->scan('lib-1', $this->tmpDir, 'series', true);

        $series = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'series'));
        $this->assertCount(2, $series, 'distinct years must NOT collapse into one container');

        $keys = array_map(fn ($s) => $this->metaOf($s)['canonical_key'] ?? null, $series);
        sort($keys);
        $this->assertSame(['hunterxhunter:1999', 'hunterxhunter:2011'], $keys);
    }

    /**
     * Step 1.2 — canonical fallback reuses a series created by a PRIOR scan that
     * stored it under a different synthetic path (the cross-scan dedup case: a
     * flat→per-directory re-scan, or a separator-variant filename). The pre-seeded
     * row sits at "series:lib-1:hunterxhunter"; the new scan slugs the same show
     * as "series:lib-1:hunter-x-hunter" — findByPath() misses, but the canonical
     * key reunites them so no second series is created.
     */
    public function testCanonicalFallbackReusesSeriesFromPriorScanAtDifferentPath(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        // Simulate a prior scan: a series stored under the no-separator slug-path
        // carrying the canonical key, with one existing episode/season subtree.
        $existingSeriesId = $repo->seed([
            'library_id' => 'lib-1',
            'parent_id' => null,
            'name' => 'HunterxHunter',
            'type' => 'series',
            'path' => 'series:lib-1:hunterxhunter',
            'metadata_json' => ['name' => 'HunterxHunter', 'canonical_key' => 'hunterxhunter'],
        ]);

        // New scan uses the spaced title, which slugs to "hunter-x-hunter".
        $this->tmpDir = $this->makeTempDirWith(['Hunter x Hunter S02E05.mkv']);
        $scanner->scan('lib-1', $this->tmpDir, 'series');

        $series = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'series'));
        $this->assertCount(1, $series, 'no second series row is forked for the slug variant');
        $this->assertSame($existingSeriesId, $series[0]['id'], 'the pre-existing series is reused');

        // The new season/episode attach under the REUSED series.
        $seasons = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'season'));
        $this->assertCount(1, $seasons);
        $this->assertSame($existingSeriesId, $seasons[0]['parent_id']);
    }

    /**
     * Step 1.2 — canonical fallback on the top-level MOVIE create path. Two movie
     * files whose titles slug differently ("Mad Max Fury Road (2015)" vs
     * "MadMaxFuryRoad (2015)") but share the canonical key "madmaxfuryroad:2015"
     * must produce exactly ONE top-level movie row; the second file is recognised
     * as the same film and skipped rather than forking a duplicate.
     */
    public function testMoviesWithDifferentSlugsButSameCanonicalKeyCreateOneRow(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        $this->tmpDir = $this->makeTempDirWith([
            'Mad Max Fury Road (2015).mkv',
            'MadMaxFuryRoad (2015).mkv',
        ]);

        $scanner->scan('lib-1', $this->tmpDir, 'movie');

        $movies = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'movie'));
        $this->assertCount(1, $movies, 'two slug-variant copies collapse to ONE movie row');
        $this->assertSame('madmaxfuryroad:2015', $this->metaOf($movies[0])['canonical_key'] ?? null);
    }

    /**
     * Step 1.2 — movies that are genuinely different (different year → different
     * canonical key) stay SEPARATE on the top-level movie path.
     */
    public function testMoviesWithDistinctCanonicalKeysStaySeparate(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        $this->tmpDir = $this->makeTempDirWith([
            'Dune (1984).mkv',
            'Dune (2021).mkv',
        ]);

        $scanner->scan('lib-1', $this->tmpDir, 'movie');

        $movies = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'movie'));
        $this->assertCount(2, $movies, 'distinct years must NOT collapse into one movie');
    }

    /**
     * Step 1.2 — canonical is STRICTLY a third-tier fallback: the resolution
     * order is (1) per-scan containerCache → (2) findByPath(synthetic path) →
     * (3) findTopLevelByCanonical(). When successive episodes of the same show
     * resolve to the SAME synthetic series path, the canonical fallback must
     * NEVER be consulted — the first episode creates the series (one canonical
     * lookup, which misses → create), and every later episode resolves via the
     * containerCache fast-path with ZERO further canonical lookups. This pins
     * that exact-path behavior is unchanged and the canonical query is not run
     * on the hot path for already-resolved containers.
     */
    public function testExactPathContainerCacheShortCircuitsCanonicalLookup(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        // Three episodes of ONE show whose titles all slug identically → the
        // synthetic series path is identical for all three.
        $this->tmpDir = $this->makeTempDirWith([
            'Breaking Bad S01E01.mkv',
            'Breaking Bad S01E02.mkv',
            'Breaking Bad S01E03.mkv',
        ]);

        $scanner->scan('lib-1', $this->tmpDir, 'series');

        $series = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'series'));
        $this->assertCount(1, $series, 'one series container for three same-path episodes');

        // The series container is resolved exactly ONCE via the create path
        // (its single canonical lookup misses and falls through to create);
        // the second and third episodes hit the per-scan containerCache, so the
        // canonical fallback is consulted AT MOST once for the series container —
        // never on the cache-hit hot path.
        $this->assertLessThanOrEqual(
            1,
            $repo->canonicalLookupCount,
            'canonical fallback must not run for containerCache hits (exact-path short-circuit)',
        );
    }

    /**
     * Step 1.2 — exact synthetic-path hit via findByPath() short-circuits BEFORE
     * the canonical fallback. A prior scan seeded a series at the EXACT synthetic
     * path the new scan computes; even though that row carries a canonical key
     * that would ALSO match, the row must be reused via findByPath (tier 2) and
     * the canonical lookup (tier 3) must never be reached. Proven by reusing the
     * exact same id AND a zero canonical-lookup count.
     */
    public function testExactSyntheticPathHitDoesNotConsultCanonical(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        // Seed the series at the SAME synthetic path the next scan will compute
        // for "Breaking Bad" (identical slug), carrying a matching canonical key.
        $existingSeriesId = $repo->seed([
            'library_id' => 'lib-1',
            'parent_id' => null,
            'name' => 'Breaking Bad',
            'type' => 'series',
            'path' => SeriesContainerNaming::seriesPath('lib-1', 'Breaking Bad', null),
            'metadata_json' => ['name' => 'Breaking Bad', 'canonical_key' => 'breakingbad'],
        ]);

        $this->tmpDir = $this->makeTempDirWith(['Breaking Bad S01E04.mkv']);
        $scanner->scan('lib-1', $this->tmpDir, 'series');

        $series = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'series'));
        $this->assertCount(1, $series, 'the exact-path row is reused, no second series');
        $this->assertSame($existingSeriesId, $series[0]['id'], 'reused via findByPath (tier 2)');
        $this->assertSame(
            0,
            $repo->canonicalLookupCount,
            'tier-3 canonical fallback must NOT run when findByPath (tier 2) already hit',
        );
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
        $this->assertSame('Some Show', $this->metaOf($someShow)['series_title']);
        $this->assertSame(2013, $this->metaOf($someShow)['year']);

        $bebop = $this->seriesByName($series, 'Cowboy Bebop');
        $this->assertSame('Cowboy Bebop', $this->metaOf($bebop)['series_title']);
        $this->assertSame(1998, $this->metaOf($bebop)['year']);

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
        $this->assertSame('Assassination Classroom', $this->metaOf($series[0])['series_title']);
        $this->assertSame(2013, $this->metaOf($series[0])['year']);

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
            $this->assertArrayNotHasKey('series_title', $this->metaOf($s));
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
        $meta = $this->metaOf($series[0]);

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

    public function testSeriesPerDirectoryClassifiesSeasonAndSpecialsSubdirsAndSkipsJunk(): void
    {
        // A per-series directory with SEASON subdirectories, a Specials subdir,
        // and a junk "you might also like" pointer subdir. Episodes must land in
        // the season number their FOLDER dictates (0 = Specials), and the junk
        // dir must be skipped entirely.
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        $root = sys_get_temp_dir() . '/phlix_scan_seasons_' . uniqid();
        $this->tmpDir = $root;
        $seriesDir = $root . '/Series (2000)';
        $this->mkTree([
            $seriesDir . '/Season 1' => ['S01E01.mkv', 'S01E02.mkv'],
            $seriesDir . '/Season 02 - Baby Saga' => ['whatever.mkv'],
            $seriesDir . '/Specials' => ['a special.mkv'],
            $seriesDir . "/OTHER Shows You'd Like, HERE" => ['junk.mkv'],
        ]);

        $scanner->scan('lib-1', $root, 'series', true);

        $items = $repo->items();
        $series = array_values(array_filter($items, fn ($i) => $i['type'] === 'series'));
        $seasons = array_values(array_filter($items, fn ($i) => $i['type'] === 'season'));
        $episodes = array_values(array_filter($items, fn ($i) => $i['type'] === 'episode'));

        // One series container for "Series".
        $this->assertCount(1, $series, 'one series container');

        // Season numbers come from the directories: 1, 2 and 0 (Specials). The
        // junk dir contributes NO season and NO episode.
        $seasonNumbers = array_map(
            fn ($s) => $this->metaOf($s)['season'] ?? null,
            $seasons
        );
        sort($seasonNumbers);
        $this->assertSame([0, 1, 2], $seasonNumbers, 'season 0 (Specials), 1 and 2 containers');

        // Four episodes total: 2 in season 1, 1 in season 2, 1 in Specials. The
        // junk dir's file is NOT scanned.
        $this->assertCount(4, $episodes, 'junk dir file is skipped; 4 real episodes');

        // Every episode's forced season matches its owning season container.
        $seasonById = [];
        foreach ($seasons as $s) {
            $seasonById[$this->strOf($s, 'id')] = $this->metaOf($s)['season'] ?? null;
        }
        $episodeSeasons = [];
        foreach ($episodes as $ep) {
            $this->assertArrayHasKey($ep['parent_id'], $seasonById, 'episode parent is a season container');
            $episodeSeasons[] = $this->metaOf($ep)['season'] ?? null;
        }
        sort($episodeSeasons);
        $this->assertSame([0, 1, 1, 2], $episodeSeasons, 'episodes inherit their folder season');

        // No file from the junk dir was persisted anywhere.
        foreach ($items as $item) {
            $this->assertStringNotContainsString(
                'junk.mkv',
                $this->strOf($item, 'path'),
                'the junk pointer dir must not be scanned'
            );
        }
    }

    public function testSeriesPerDirectoryDirectFilesStillWorkAlongsideSeasonSubdirs(): void
    {
        // A series dir with BOTH a season subdir AND a loose file directly under
        // the series dir. The nested file forces season 1; the direct file keeps
        // today's filename-derived behaviour (parses its own S03E07).
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        $root = sys_get_temp_dir() . '/phlix_scan_mixed_' . uniqid();
        $this->tmpDir = $root;
        $seriesDir = $root . '/My Show (1999)';
        $this->mkTree([
            $seriesDir . '/Season 1' => ['nested.mkv'],
        ]);
        // A file directly under the series dir (no season subfolder).
        file_put_contents($seriesDir . '/My Show S03E07.mkv', 'x');

        $scanner->scan('lib-1', $root, 'series', true);

        $items = $repo->items();
        $episodes = array_values(array_filter($items, fn ($i) => $i['type'] === 'episode'));
        $this->assertCount(2, $episodes, 'nested + direct file both indexed as episodes');

        $seasonsSeen = [];
        foreach ($episodes as $ep) {
            $seasonsSeen[] = $this->metaOf($ep)['season'] ?? null;
        }
        sort($seasonsSeen);
        // Nested file → forced season 1; direct file → filename-parsed season 3.
        $this->assertSame([1, 3], $seasonsSeen);
    }

    /**
     * Build a temp tree from an absolute-dir => [filenames] map. Unlike
     * {@see makeTempTree()} (root-relative), keys are absolute paths so nested
     * season subdirectories can be created.
     *
     * @param array<string, array<int, string>> $dirs
     */
    private function mkTree(array $dirs): void
    {
        foreach ($dirs as $dir => $files) {
            mkdir($dir, 0775, true);
            foreach ($files as $file) {
                file_put_contents($dir . '/' . $file, 'x');
            }
        }
    }

    // --- duration probing --------------------------------------------------

    public function testDurationSecondsPopulatedFromProbeOnNewVideoItem(): void
    {
        $repo = $this->makeFakeRepo();
        $ffmpeg = $this->makeFfmpegStub('5432.7'); // → round() = 5433
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $repo,
            null,
            null,
            null,
            $ffmpeg
        );

        $this->tmpDir = $this->makeTempDirWith(['Inception (2010).mkv']);
        $scanner->scan('lib-1', $this->tmpDir, 'movie');

        $movies = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'movie'));
        $this->assertCount(1, $movies);
        // (int) round((float) "5432.7") = 5433 — matches persistProbedDuration().
        $this->assertSame(5433, $this->metaOf($movies[0])['duration_seconds']);
    }

    public function testDurationSecondsPopulatedForAudioItem(): void
    {
        $repo = $this->makeFakeRepo();
        $ffmpeg = $this->makeFfmpegStub('212.0');
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $repo,
            null,
            null,
            null,
            $ffmpeg
        );

        $this->tmpDir = $this->makeTempDirWith(['Some Track.mp3']);
        $scanner->scan('lib-1', $this->tmpDir, 'audio');

        $items = $repo->items();
        $this->assertCount(1, $items);
        $this->assertSame('audio', $items[0]['type']);
        $this->assertSame(212, $this->metaOf($items[0])['duration_seconds']);
    }

    public function testImageAndBookItemsAreNeverProbedForDuration(): void
    {
        $repo = $this->makeFakeRepo();
        // The runner MUST NOT be probed for non-time-based media.
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');

        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $repo,
            null,
            null,
            null,
            $ffmpeg
        );

        // Image library.
        $this->tmpDir = $this->makeTempDirWith(['Photo.jpg']);
        $scanner->scan('lib-img', $this->tmpDir, 'image');
        $this->removeDir($this->tmpDir);

        // Book library.
        $this->tmpDir = $this->makeTempDirWith(['Novel.epub']);
        $scanner->scan('lib-book', $this->tmpDir, 'book');

        foreach ($repo->items() as $item) {
            $this->assertArrayNotHasKey(
                'duration_seconds',
                $this->metaOf($item),
                'image/book items must carry no probed duration'
            );
        }
    }

    /**
     * SV-0.8 HIGH-finding regression (scanner level): a SEASON container is a
     * NON-deduped type (type='season', parent_id != null), so its generated
     * `path_hash` is NULL. On a rescan {@see MediaScanner::findOrCreateContainer()}
     * resolves it via {@see ItemRepository::findByPath()} on the stable synthetic
     * season path — which, before the NULL-hash raw-path fallback, ALWAYS missed
     * (`path_hash = SHA1(?)` never matches NULL) and forked a NEW, empty duplicate
     * season on every scan (series has a canonical_key rescue; seasons do not).
     * A second scan must reuse the SAME series/season rows, never duplicate them.
     */
    public function testSeasonContainersAreReusedNotDuplicatedOnRescan(): void
    {
        $repo = $this->makeFakeRepo();

        $this->tmpDir = $this->makeTempDirWith([
            'The Wire S01E01.mkv',
            'The Wire S01E02.mkv',
            'The Wire S02E01.mkv',
        ]);

        // First scan builds the series → season → episode hierarchy.
        (new MediaScanner($this->createMock(Connection::class), $repo))
            ->scan('lib-1', $this->tmpDir, 'series');

        $seriesAfterFirst = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'series'));
        $seasonsAfterFirst = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'season'));
        $this->assertCount(1, $seriesAfterFirst, 'one series container after the first scan');
        $this->assertCount(2, $seasonsAfterFirst, 'two season containers after the first scan');

        $seasonIdsFirst = array_map(fn ($s) => $s['id'], $seasonsAfterFirst);
        sort($seasonIdsFirst);

        // Rescan with a FRESH scanner (empty containerCache) so the resolve goes
        // through findByPath() on the synthetic season paths, exactly as a real
        // second scan of the library would.
        (new MediaScanner($this->createMock(Connection::class), $repo))
            ->scan('lib-1', $this->tmpDir, 'series');

        $seriesAfterRescan = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'series'));
        $seasonsAfterRescan = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'season'));

        $this->assertCount(1, $seriesAfterRescan, 'rescan must NOT fork a duplicate series container');
        $this->assertCount(2, $seasonsAfterRescan, 'rescan must NOT fork duplicate season containers');

        $seasonIdsRescan = array_map(fn ($s) => $s['id'], $seasonsAfterRescan);
        sort($seasonIdsRescan);
        $this->assertSame($seasonIdsFirst, $seasonIdsRescan, 'the exact same season rows are reused on rescan');
    }

    /**
     * SV-0.8 HIGH-finding regression (scanner level, batch path): an image library
     * item is a NON-deduped type (type='image', NULL `path_hash`) resolved on
     * rescan via {@see ItemRepository::findPathsMap()}. Before the raw-path
     * fallback pass the batch reported every existing photo as "absent" and the
     * scanner re-created a FULL DUPLICATE set on each rescan. A second scan of the
     * same library must add nothing.
     */
    public function testImageLibraryRescanDoesNotDuplicateItems(): void
    {
        $repo = $this->makeFakeRepo();
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');

        $this->tmpDir = $this->makeTempDirWith(['One.jpg', 'Two.png', 'Three.gif']);

        $makeScanner = fn (): MediaScanner => new MediaScanner(
            $this->createMock(Connection::class),
            $repo,
            null,
            null,
            null,
            $ffmpeg
        );

        $makeScanner()->scan('lib-img', $this->tmpDir, 'image');
        $this->assertCount(3, $repo->items(), 'three image items after the first scan');

        $makeScanner()->scan('lib-img', $this->tmpDir, 'image');
        $this->assertCount(
            3,
            $repo->items(),
            'rescan must NOT duplicate the image items (NULL path_hash resolved by raw path)'
        );
    }

    /**
     * An `'image'` LIBRARY type must yield `type='photo'` MEDIA items. `image`
     * keys the extension set in the scanner's naming options, but it is not a
     * member of the `media_items.type` ENUM — the column calls that concept
     * `photo`. Regression: determineMediaType() returned the library type
     * verbatim, so every photo INSERT was rejected by a strict-mode server
     * (error 1265) and a photo library could never produce a single row.
     */
    public function testImageLibraryCreatesItemsTypedPhotoNotImage(): void
    {
        $repo = $this->makeFakeRepo();
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');

        $this->tmpDir = $this->makeTempDirWith(['One.jpg', 'Two.png']);

        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $repo,
            null,
            null,
            null,
            $ffmpeg
        );
        $scanner->scan('lib-img', $this->tmpDir, 'image');

        $types = array_column($repo->items(), 'type');
        $this->assertSame(['photo', 'photo'], $types, "photos must be typed 'photo', never 'image'");
    }

    public function testProbeReturningNullLeavesNoDurationAndDoesNotAbort(): void
    {
        $repo = $this->makeFakeRepo();
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturn(null);

        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $repo,
            null,
            null,
            null,
            $ffmpeg
        );

        $this->tmpDir = $this->makeTempDirWith(['Movie One (2020).mkv']);
        $scanner->scan('lib-1', $this->tmpDir, 'movie');

        $items = $repo->items();
        $this->assertCount(1, $items, 'scan still indexes the file when probe yields nothing');
        $this->assertArrayNotHasKey('duration_seconds', $this->metaOf($items[0]));
    }

    public function testProbeThrowingDoesNotAbortScanAndLeavesNoDuration(): void
    {
        $repo = $this->makeFakeRepo();
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willThrowException(new \RuntimeException('ffprobe boom'));

        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $repo,
            null,
            null,
            null,
            $ffmpeg
        );

        $this->tmpDir = $this->makeTempDirWith(['Movie One (2020).mkv', 'Movie Two (2021).mp4']);
        // Must NOT throw — a probe failure can never abort the scan.
        $scanner->scan('lib-1', $this->tmpDir, 'movie');

        $items = $repo->items();
        $this->assertCount(2, $items, 'both files still indexed despite probe throwing');
        foreach ($items as $item) {
            $this->assertArrayNotHasKey('duration_seconds', $this->metaOf($item));
        }
    }

    public function testRescanBackfillsMissingDurationOnExistingItem(): void
    {
        $repo = $this->makeFakeRepo();

        $this->tmpDir = $this->makeTempDirWith(['Inception (2010).mkv']);
        $path = $this->tmpDir . '/Inception (2010).mkv';

        // Simulate a prior scan: the item already exists but has no duration
        // (indexed before probing existed / never transcoded).
        $repo->seed([
            'id' => 'existing-movie',
            'library_id' => 'lib-1',
            'parent_id' => null,
            'name' => 'Inception',
            'type' => 'movie',
            'path' => $path,
            'metadata_json' => ['tmdb_id' => 27205],
        ]);

        $ffmpeg = $this->makeFfmpegStub('8880.4'); // → 8880
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $repo,
            null,
            null,
            null,
            $ffmpeg
        );

        $scanner->scan('lib-1', $this->tmpDir, 'movie');

        // No new row added (still the one seeded item).
        $items = $repo->items();
        $this->assertCount(1, $items, 'no duplicate item added on rescan');

        // The existing row was updated with the backfilled duration, preserving
        // other metadata keys.
        $updates = array_values(array_filter($repo->updates, fn ($u) => $u['id'] === 'existing-movie'));
        $this->assertCount(1, $updates, 'exactly one backfill update');
        $this->assertSame(8880, $this->metaOf($updates[0]['data'])['duration_seconds']);
        $this->assertSame(27205, $this->metaOf($updates[0]['data'])['tmdb_id'], 'existing metadata preserved');
    }

    public function testRescanDoesNotReprobeWhenDurationAndSourceAlreadyPresent(): void
    {
        $repo = $this->makeFakeRepo();

        $this->tmpDir = $this->makeTempDirWith(['Inception (2010).mkv']);
        $path = $this->tmpDir . '/Inception (2010).mkv';

        // A fully-populated prior scan: both the duration AND the source
        // technical summary are already stored, so a rescan has nothing to
        // backfill and must never probe or write again. (An item that has a
        // duration but NO source is intentionally re-probed once to backfill
        // the source summary — covered by the source-metadata tests.)
        $repo->seed([
            'id' => 'existing-movie',
            'library_id' => 'lib-1',
            'parent_id' => null,
            'name' => 'Inception',
            'type' => 'movie',
            'path' => $path,
            'metadata_json' => [
                'duration_seconds' => 1234,
                'source' => [
                    'width' => 1920,
                    'height' => 1080,
                    'video_codec' => 'h264',
                    'video_bitrate' => 5000000,
                    'pix_fmt' => 'yuv420p',
                    'audio_codec' => 'aac',
                    'audio_bitrate' => 128000,
                ],
            ],
        ]);

        // Already fully populated → never probe again, never update.
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');

        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $repo,
            null,
            null,
            null,
            $ffmpeg
        );

        $scanner->scan('lib-1', $this->tmpDir, 'movie');

        $updates = array_filter($repo->updates, fn ($u) => $u['id'] === 'existing-movie');
        $this->assertCount(0, $updates, 'no redundant write when duration and source already stored');
    }

    /**
     * Build a mocked FfmpegRunner whose probe() returns a format.duration of the
     * given seconds string (mirroring ffprobe's JSON), for every call.
     */
    private function makeFfmpegStub(string $durationSeconds): FfmpegRunner
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturn([
            'streams' => [],
            'format' => ['duration' => $durationSeconds],
        ]);
        return $ffmpeg;
    }

    // --- source metadata + media_streams (step A1) -------------------------

    /**
     * summarizeProbe() extracts the fixed source blob from a realistic probe:
     * an h264 1080p video (per-stream bitrate) + an aac audio, plus the
     * container duration. The per-stream video bitrate wins over the container
     * bitrate, and two media_streams rows (video, then audio) are derived.
     */
    public function testSummarizeProbeExtractsSourceBlobFromRealisticProbe(): void
    {
        $summary = $this->summarize($this->h264AacProbe());

        // (int) round((float) "5433.2") = 5433 — matches persistProbedDuration().
        $this->assertSame(5433, $summary['duration_seconds']);
        $this->assertSame([
            'width' => 1920,
            'height' => 1080,
            'video_codec' => 'h264',
            'video_bitrate' => 5000000, // per-stream bitrate, NOT format.bit_rate
            'pix_fmt' => 'yuv420p',
            'audio_codec' => 'aac',
            'audio_bitrate' => 128000,
        ], $summary['source']);

        // Exactly two rows: the chosen video, then the primary audio.
        $this->assertCount(2, $summary['streams']);
        $this->assertSame('video', $summary['streams'][0]['stream_type']);
        $this->assertSame(0, $summary['streams'][0]['stream_index']);
        $this->assertSame('h264', $summary['streams'][0]['codec']);
        $this->assertSame('eng', $summary['streams'][0]['language']);
        $this->assertSame(5000000, $summary['streams'][0]['bitrate']);
        $this->assertSame(1920, $summary['streams'][0]['width']);
        $this->assertSame(1080, $summary['streams'][0]['height']);
        $this->assertSame('audio', $summary['streams'][1]['stream_type']);
        $this->assertSame(1, $summary['streams'][1]['stream_index']);
        $this->assertSame('aac', $summary['streams'][1]['codec']);
        $this->assertSame(128000, $summary['streams'][1]['bitrate']);
        $this->assertNull($summary['streams'][1]['width'], 'audio row carries no dimensions');
        $this->assertNull($summary['streams'][1]['height']);
    }

    /**
     * Every source field the probe does not expose becomes null (never 0/'')
     * so the ABR-ladder clamp downstream can tell "unknown" from "zero".
     */
    public function testSummarizeProbeMissingFieldsBecomeNull(): void
    {
        $summary = $this->summarize([
            'streams' => [
                // A bare video stream: no width/height/codec_name/bit_rate/pix_fmt.
                ['index' => 0, 'codec_type' => 'video'],
            ],
            'format' => [], // no duration, no bit_rate
        ]);

        $this->assertNull($summary['duration_seconds']);
        $this->assertSame([
            'width' => null,
            'height' => null,
            'video_codec' => null,
            'video_bitrate' => null, // no stream bitrate AND no format.bit_rate
            'pix_fmt' => null,
            'audio_codec' => null,   // no audio stream at all
            'audio_bitrate' => null,
        ], $summary['source']);
        // The video row is still emitted (all-null fields); no audio row.
        $this->assertCount(1, $summary['streams']);
        $this->assertSame('video', $summary['streams'][0]['stream_type']);
        $this->assertNull($summary['streams'][0]['codec']);
        $this->assertNull($summary['streams'][0]['language']);
    }

    /**
     * A Matroska per-track `BPS` tag is the TRUE per-stream rate and must beat
     * the whole-file `format.bit_rate` fallback, which charges the video with the
     * audio's bits too. Modelled on a real 1080p HEVC + AC-3 .mkv: video is
     * 1,081,553 bps but format.bit_rate reads 1,533,248 (~40 % high), which would
     * inflate every ABR rung's target and advertised BANDWIDTH.
     */
    public function testSummarizeProbePrefersMatroskaBpsTagOverFormatBitRate(): void
    {
        $summary = $this->summarize([
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'hevc',
                 'width' => 1920, 'height' => 1080, 'pix_fmt' => 'yuv420p10le',
                 'tags' => ['BPS' => '1081553', 'DURATION' => '00:21:41.000000000']],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'ac3',
                 'channels' => 6, 'tags' => ['BPS-eng' => '448000']],
            ],
            'format' => ['duration' => '1301', 'bit_rate' => '1533248'],
        ]);

        $source = $this->arrOf($summary, 'source');
        $this->assertSame(1081553, $source['video_bitrate'], 'BPS tag beats format.bit_rate');
        $this->assertSame(448000, $source['audio_bitrate'], 'language-suffixed BPS-eng is honoured');
        $this->assertSame(1081553, $summary['streams'][0]['bitrate'], 'stream row uses the tagged rate');
    }

    /**
     * video_bitrate falls back to the whole-file container bitrate
     * (format.bit_rate) when the video stream carries none — common for
     * Matroska — so the ladder always has a usable source ceiling. The derived
     * media_streams video row uses the same fallen-back value.
     */
    public function testSummarizeProbeVideoBitrateFallsBackToFormatBitRate(): void
    {
        $summary = $this->summarize([
            'streams' => [
                // 4K hevc with NO per-stream bit_rate (typical .mkv).
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'hevc',
                 'width' => 3840, 'height' => 2160, 'pix_fmt' => 'yuv420p10le'],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'eac3'],
            ],
            'format' => ['duration' => '600', 'bit_rate' => '18000000'],
        ]);

        $this->assertSame(18000000, $this->arrOf($summary, 'source')['video_bitrate'], 'falls back to format.bit_rate');
        $this->assertSame(18000000, $summary['streams'][0]['bitrate'], 'stream row uses the fallback too');
        // Audio has no bitrate and there is NO audio-side format fallback → null.
        $this->assertNull($this->arrOf($summary, 'source')['audio_bitrate']);
        $this->assertNull($summary['streams'][1]['bitrate']);
    }

    /**
     * A container exposing neither a video nor an audio stream (subtitle/data
     * only) has no meaningful source summary and nothing to persist — but its
     * duration is still read.
     */
    public function testSummarizeProbeWithNoAudioOrVideoYieldsNullSourceAndNoStreams(): void
    {
        $summary = $this->summarize([
            'streams' => [
                ['index' => 0, 'codec_type' => 'subtitle', 'codec_name' => 'subrip'],
                ['index' => 1, 'codec_type' => 'data'],
            ],
            'format' => ['duration' => '120.0'],
        ]);

        $this->assertSame(120, $summary['duration_seconds']);
        $this->assertNull($summary['source']);
        $this->assertSame([], $summary['streams']);
    }

    /**
     * Duration is rounded like persistProbedDuration() and kept only when
     * positive; zero / negative / non-numeric collapse to null.
     */
    public function testSummarizeProbeDurationIsRoundedAndPositiveOnly(): void
    {
        $this->assertSame(43, $this->summarize([
            'streams' => [['codec_type' => 'audio', 'codec_name' => 'mp3']],
            'format' => ['duration' => '42.6'],
        ])['duration_seconds']);

        foreach (['0', '0.0', '-5', 'N/A', ''] as $raw) {
            $this->assertNull(
                $this->summarize([
                    'streams' => [['codec_type' => 'audio', 'codec_name' => 'mp3']],
                    'format' => ['duration' => $raw],
                ])['duration_seconds'],
                "duration '{$raw}' must be null"
            );
        }
    }

    /**
     * Cover-art aware: an embedded poster (attached_pic mjpeg 600x900) listed
     * BEFORE the real h264 1080p track must be ignored — both the source blob
     * and the derived media_streams video row describe the h264, never the
     * poster (whose portrait dims would wrongly cap the ABR ladder).
     */
    public function testSummarizeProbeSkipsCoverArtAndPicksRealVideoStream(): void
    {
        $summary = $this->summarize([
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'mjpeg',
                 'width' => 600, 'height' => 900, 'disposition' => ['attached_pic' => 1]],
                ['index' => 1, 'codec_type' => 'video', 'codec_name' => 'h264',
                 'width' => 1920, 'height' => 1080, 'bit_rate' => '6000000', 'pix_fmt' => 'yuv420p'],
                ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'aac', 'bit_rate' => '160000'],
            ],
            'format' => ['duration' => '1800.0'],
        ]);

        $this->assertSame(1920, $this->arrOf($summary, 'source')['width']);
        $this->assertSame(1080, $this->arrOf($summary, 'source')['height']);
        $this->assertSame('h264', $this->arrOf($summary, 'source')['video_codec']);
        $this->assertSame(6000000, $this->arrOf($summary, 'source')['video_bitrate']);

        // The video row is the h264 at index 1 — never the mjpeg poster.
        $this->assertSame('video', $summary['streams'][0]['stream_type']);
        $this->assertSame(1, $summary['streams'][0]['stream_index']);
        $this->assertSame('h264', $summary['streams'][0]['codec']);
        $this->assertSame(1920, $summary['streams'][0]['width']);
    }

    /**
     * Documented edge: when EVERY video-type stream is an attached picture (an
     * audio file with embedded album art), the summary falls back to that
     * stream — preserving prior behavior so the item still gets a source rather
     * than none.
     */
    public function testSummarizeProbeFallsBackToAttachedPicWhenEveryVideoIsCoverArt(): void
    {
        $summary = $this->summarize([
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'png',
                 'width' => 500, 'height' => 500, 'disposition' => ['attached_pic' => 1]],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'flac', 'bit_rate' => '900000'],
            ],
            'format' => ['duration' => '240.0'],
        ]);

        $this->assertSame(500, $this->arrOf($summary, 'source')['width']);
        $this->assertSame('png', $this->arrOf($summary, 'source')['video_codec']);
        $this->assertSame('png', $summary['streams'][0]['codec']);
        $this->assertSame('flac', $this->arrOf($summary, 'source')['audio_codec']);
    }

    /**
     * streamLanguage(): the "und" placeholder is dropped to null and an
     * over-long tag is truncated to the media_streams.language column width (10).
     */
    public function testSummarizeProbeDropsUndeterminedLanguageAndTruncatesLongTags(): void
    {
        $summary = $this->summarize([
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264',
                 'width' => 1280, 'height' => 720, 'tags' => ['language' => 'und']],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac',
                 'tags' => ['language' => 'this-is-a-very-long-language-tag']],
            ],
            'format' => [],
        ]);

        $this->assertNull($summary['streams'][0]['language'], '"und" is dropped');
        $this->assertSame('this-is-a-', $summary['streams'][1]['language'], 'truncated to 10 chars');
    }

    /**
     * isAttachedPic() truth table — `1` and `"1"` are true; `0`, `"0"`, other
     * numerics, a missing key, a non-array disposition, and a non-array stream
     * are all false.
     *
     * @dataProvider attachedPicCases
     */
    public function testIsAttachedPicTruthTable(mixed $stream, bool $expected, string $desc): void
    {
        $this->assertSame($expected, $this->invokeIsAttachedPic($stream), $desc);
    }

    /** @return array<int, array{0: mixed, 1: bool, 2: string}> */
    public static function attachedPicCases(): array
    {
        return [
            [['disposition' => ['attached_pic' => 1]], true, 'int 1 => true'],
            [['disposition' => ['attached_pic' => '1']], true, 'numeric string "1" => true'],
            [['disposition' => ['attached_pic' => 0]], false, 'int 0 => false'],
            [['disposition' => ['attached_pic' => '0']], false, 'numeric string "0" => false'],
            [['disposition' => ['attached_pic' => 2]], false, 'other numeric (2) => false'],
            [['disposition' => []], false, 'missing attached_pic key => false'],
            [['disposition' => 'yes'], false, 'non-array disposition => false'],
            [['codec_type' => 'video'], false, 'no disposition key => false'],
            ['not-an-array', false, 'non-array (string) stream => false'],
            [null, false, 'null stream => false'],
            [42, false, 'scalar (int) stream => false'],
        ];
    }

    /**
     * Initial scan (create path): metadata_json['source'] is MERGED into the
     * freshly-parsed metadata — the filename-derived keys (name, year) survive
     * alongside the probed source + duration — and the video + primary audio
     * are persisted to media_streams under the new item's id.
     */
    public function testInitialScanMergesSourceWithParsedMetadataAndPersistsStreams(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $repo,
            null,
            null,
            null,
            $this->makeProbeStub($this->h264AacProbe())
        );

        $this->tmpDir = $this->makeTempDirWith(['Inception (2010).mkv']);
        $scanner->scan('lib-1', $this->tmpDir, 'movie');

        $movies = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'movie'));
        $this->assertCount(1, $movies);
        $meta = $this->metaOf($movies[0]);

        // Parsed-from-filename keys are NOT clobbered by the source merge.
        $this->assertSame('2010', $meta['year']);
        $this->assertSame('Inception', $meta['name']);
        // Probed scalars merged in.
        $this->assertSame(5433, $meta['duration_seconds']);
        $this->assertSame('h264', $this->arrOf($meta, 'source')['video_codec']);
        $this->assertSame(1920, $this->arrOf($meta, 'source')['width']);

        // Streams persisted against the created item's id: video then audio.
        $this->assertNotSame([], $repo->addedStreams);
        $this->assertSame(
            [$movies[0]['id']],
            array_values(array_unique(array_map(fn ($s) => $s['item_id'], $repo->addedStreams)))
        );
        $this->assertSame(['video', 'audio'], array_map(fn ($s) => $s['data']['stream_type'], $repo->addedStreams));
    }

    /**
     * Initial scan with embedded cover art: the persisted media_streams video
     * row is the real h264 (index 1), never the attached-pic mjpeg poster — the
     * end-to-end scan wiring, not just the pure summarizer.
     */
    public function testInitialScanPersistsRealVideoStreamNotCoverArt(): void
    {
        $repo = $this->makeFakeRepo();
        $probe = [
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'mjpeg',
                 'width' => 600, 'height' => 900, 'disposition' => ['attached_pic' => 1]],
                ['index' => 1, 'codec_type' => 'video', 'codec_name' => 'h264',
                 'width' => 1920, 'height' => 1080, 'bit_rate' => '6000000', 'pix_fmt' => 'yuv420p'],
                ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'aac', 'bit_rate' => '160000'],
            ],
            'format' => ['duration' => '1800.0'],
        ];
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $repo,
            null,
            null,
            null,
            $this->makeProbeStub($probe)
        );

        $this->tmpDir = $this->makeTempDirWith(['Poster Embedded Movie (2021).mkv']);
        $scanner->scan('lib-1', $this->tmpDir, 'movie');

        $movies = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'movie'));
        $this->assertCount(1, $movies);
        $this->assertSame(1920, $this->arrOf($this->metaOf($movies[0]), 'source')['width'], 'source is the real video, not the poster');

        $videoRows = array_values(array_filter($repo->addedStreams, fn ($s) => $s['data']['stream_type'] === 'video'));
        $this->assertCount(1, $videoRows);
        $this->assertSame('h264', $videoRows[0]['data']['codec']);
        $this->assertSame(1, $videoRows[0]['data']['stream_index']);
        $this->assertSame(1920, $videoRows[0]['data']['width']);
    }

    /**
     * Full stream set (migration 071): summarizeProbe() derives EVERY
     * video/audio/subtitle stream — not just the primary video + audio — with
     * channels, container title tag, and the ffprobe disposition.default flag,
     * while data/attachment streams are still skipped.
     */
    public function testSummarizeProbeDerivesFullStreamSetWithTrackMetadata(): void
    {
        $summary = $this->summarize($this->multiTrackProbe());

        $this->assertSame(
            ['video', 'audio', 'audio', 'subtitle', 'subtitle'],
            array_map(fn ($s) => $s['stream_type'], $summary['streams']),
            'every playable stream is derived; the data stream is skipped'
        );
        $this->assertSame([0, 1, 2, 3, 4], array_map(fn ($s) => $s['stream_index'], $summary['streams']));

        // Default 5.1 English audio: channels + title + disposition.default.
        $this->assertSame('eng', $summary['streams'][1]['language']);
        $this->assertSame(6, $summary['streams'][1]['channels']);
        $this->assertSame('Surround 5.1', $summary['streams'][1]['title']);
        $this->assertSame(1, $summary['streams'][1]['is_default']);

        // Secondary stereo commentary track: not default.
        $this->assertSame(2, $summary['streams'][2]['channels']);
        $this->assertSame("Director's Commentary", $summary['streams'][2]['title']);
        $this->assertSame(0, $summary['streams'][2]['is_default']);

        // Subtitle rows: text srt (default) + bitmap pgs (persisted too — the
        // shaper filters non-text codecs at render time, not the scanner).
        $this->assertSame('subrip', $summary['streams'][3]['codec']);
        $this->assertSame('eng', $summary['streams'][3]['language']);
        $this->assertSame(1, $summary['streams'][3]['is_default']);
        $this->assertSame('hdmv_pgs_subtitle', $summary['streams'][4]['codec']);
        $this->assertSame('ger', $summary['streams'][4]['language']);
        $this->assertNull($summary['streams'][4]['channels'], 'channels is audio-only');

        // Video row: no channels, is_default honoured from the probe.
        $this->assertNull($summary['streams'][0]['channels']);
        $this->assertSame(1, $summary['streams'][0]['is_default']);

        // The source blob still describes the PRIMARY video + audio only.
        $this->assertSame('h264', $this->arrOf($summary, 'source')['video_codec']);
        $this->assertSame('ac3', $this->arrOf($summary, 'source')['audio_codec']);
    }

    /**
     * End-to-end scan wiring for the full set: an initial scan persists every
     * audio + subtitle row (with disposition/channels/title) to media_streams
     * and stamps the item's streams_probed_at marker so the lazy playback-info
     * backfill never re-probes it.
     */
    public function testInitialScanPersistsSubtitleAndAllAudioStreamsAndMarksProbed(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $repo,
            null,
            null,
            null,
            $this->makeProbeStub($this->multiTrackProbe())
        );

        $this->tmpDir = $this->makeTempDirWith(['Inception (2010).mkv']);
        $scanner->scan('lib-1', $this->tmpDir, 'movie');

        $movies = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'movie'));
        $this->assertCount(1, $movies);
        $itemId = $movies[0]['id'];
        $this->assertIsString($itemId);

        $rows = $repo->streamTable[$itemId];
        $this->assertSame(
            ['video', 'audio', 'audio', 'subtitle', 'subtitle'],
            array_map(fn ($r) => $r['stream_type'], $rows)
        );
        $this->assertSame(6, $rows[1]['channels']);
        $this->assertSame('Surround 5.1', $rows[1]['title']);
        $this->assertSame(1, $rows[1]['is_default']);
        $this->assertSame(0, $rows[2]['is_default']);
        $this->assertSame('subrip', $rows[3]['codec']);

        // streams_probed_at stamped exactly once for the new item.
        $this->assertSame([$itemId], $repo->streamsProbedMarks);
    }

    /**
     * Rescan (backfill path): the probed source is merged into the EXISTING
     * DB metadata — tmdb_id / genres are preserved and an already-present
     * positive duration is never overwritten by the probe.
     */
    public function testRescanMergesSourcePreservingExistingMetadata(): void
    {
        $repo = $this->makeFakeRepo();
        $this->tmpDir = $this->makeTempDirWith(['Inception (2010).mkv']);
        $path = $this->tmpDir . '/Inception (2010).mkv';

        $repo->seed([
            'id' => 'existing-movie',
            'library_id' => 'lib-1',
            'parent_id' => null,
            'name' => 'Inception',
            'type' => 'movie',
            'path' => $path,
            'metadata_json' => [
                'tmdb_id' => 27205,
                'genres' => ['Action', 'Sci-Fi'],
                'duration_seconds' => 8880, // pre-existing positive duration
            ],
        ]);

        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $repo,
            null,
            null,
            null,
            $this->makeProbeStub($this->h264AacProbe())
        );
        $scanner->scan('lib-1', $this->tmpDir, 'movie');

        $this->assertCount(1, $repo->items(), 'no duplicate row on rescan');
        $updates = array_values(array_filter($repo->updates, fn ($u) => $u['id'] === 'existing-movie'));
        $this->assertCount(1, $updates, 'exactly one source-backfill update');
        $meta = $this->metaOf($updates[0]['data']);

        $this->assertSame('h264', $this->arrOf($meta, 'source')['video_codec'], 'source merged in');
        $this->assertSame(27205, $meta['tmdb_id'], 'tmdb_id preserved');
        $this->assertSame(['Action', 'Sci-Fi'], $meta['genres'], 'genres preserved');
        $this->assertSame(8880, $meta['duration_seconds'], 'existing positive duration not overwritten by the 5433 probe');
    }

    /**
     * media_streams idempotency: repeated rescans of the same file never
     * accumulate rows — each generation clears the item's rows (deleteStreams
     * ByItem) BEFORE re-inserting, so the table always holds exactly the fresh
     * video + audio pair.
     */
    public function testRescanReplacesMediaStreamsIdempotentlyWithNoDuplicateRows(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $repo,
            null,
            null,
            null,
            $this->makeProbeStub($this->h264AacProbe())
        );

        $existing = [
            'id' => 'movie-1',
            'type' => 'movie',
            'path' => '/library/Inception (2010).mkv',
            'metadata' => ['duration_seconds' => 8880], // has duration, lacks source
        ];

        // Three rescans of the same snapshot (source never lands in $existing).
        $this->assertSame('updated', $scanner->backfillItemSourceMetadata($existing));
        $this->assertSame('updated', $scanner->backfillItemSourceMetadata($existing));
        $this->assertSame('updated', $scanner->backfillItemSourceMetadata($existing));

        // Table holds exactly ONE video + ONE audio row — no accumulation.
        $this->assertCount(2, $repo->streamTable['movie-1']);
        $this->assertSame(['video', 'audio'], array_map(fn ($r) => $r['stream_type'], $repo->streamTable['movie-1']));

        // Every generation deletes before it re-inserts: (delete, add, add) x 3.
        $this->assertSame(
            ['delete', 'add', 'add', 'delete', 'add', 'add', 'delete', 'add', 'add'],
            array_map(fn ($o) => $o['op'], $repo->streamOps)
        );
        $this->assertSame('movie-1', $repo->streamOps[0]['item_id']);
    }

    /**
     * Backfill core: an item that already has BOTH a positive duration and a
     * source blob is skipped WITHOUT probing and without any write.
     */
    public function testBackfillSkipsAlreadyPopulatedItemWithoutProbing(): void
    {
        $repo = $this->makeFakeRepo();
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo, null, null, null, $ffmpeg);

        $existing = [
            'id' => 'movie-1',
            'type' => 'movie',
            'path' => '/x/Movie.mkv',
            'metadata' => [
                'duration_seconds' => 1234,
                'source' => [
                    'width' => 1920, 'height' => 1080, 'video_codec' => 'h264',
                    'video_bitrate' => 5000000, 'pix_fmt' => 'yuv420p',
                    'audio_codec' => 'aac', 'audio_bitrate' => 128000,
                ],
            ],
        ];

        $this->assertSame('skipped', $scanner->backfillItemSourceMetadata($existing));
        $this->assertSame([], $repo->updates);
        $this->assertSame([], $repo->streamOps);
    }

    /**
     * Backfill repairability: when a media_streams write fails part-way (the
     * audio insert throws after the video insert), the method returns 'failed'
     * and writes NEITHER source NOR duration — so the row stays source-less and
     * the CLI (source IS NULL) reselects it on the next run.
     */
    public function testBackfillReturnsFailedAndLeavesSourceUnwrittenWhenStreamPersistFails(): void
    {
        $repo = $this->makeFakeRepo();
        $repo->throwOnAddStreamCall = 2; // audio insert fails, after the video insert
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $repo,
            null,
            null,
            null,
            $this->makeProbeStub($this->h264AacProbe())
        );

        $existing = [
            'id' => 'movie-1',
            'type' => 'movie',
            'path' => '/x/Movie.mkv',
            'metadata' => ['duration_seconds' => 8880], // has duration, lacks source
        ];

        $this->assertSame('failed', $scanner->backfillItemSourceMetadata($existing));
        $this->assertSame([], $repo->updates, 'no source/duration written on a stream-persist failure');
        // The delete + first (video) insert happened before the audio insert threw.
        $this->assertSame(['movie-1'], $repo->deletedStreamItems);
        $this->assertCount(1, $repo->addedStreams, 'only the video insert landed before the failure');
    }

    /**
     * Backfill 'updated' edge: streams are (idempotently) persisted but the
     * probe supplies nothing new for metadata (source already present, no
     * duration and none probeable), so no metadata_json write is issued yet the
     * result is still 'updated' because streams were rewritten.
     */
    public function testBackfillPersistsStreamsButSkipsMetadataWriteWhenNothingNew(): void
    {
        $repo = $this->makeFakeRepo();
        $probe = $this->h264AacProbe();
        $probe['format'] = []; // no probeable duration
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $repo,
            null,
            null,
            null,
            $this->makeProbeStub($probe)
        );

        $existing = [
            'id' => 'movie-1',
            'type' => 'movie',
            'path' => '/x/Movie.mkv',
            'metadata' => ['source' => ['width' => 1920, 'height' => 1080]], // has source, no duration
        ];

        $this->assertSame('updated', $scanner->backfillItemSourceMetadata($existing));
        $this->assertSame([], $repo->updates, 'no metadata write when neither duration nor source is new');
        $this->assertCount(2, $repo->addedStreams, 'streams are still idempotently persisted');
    }

    /**
     * Backfill guards: no ffprobe runner => 'skipped'; a non-time-based type is
     * never probed => 'skipped'; a probe returning null (missing/unreadable
     * file) => 'failed' with no write.
     */
    public function testBackfillSkipsNullFfmpegAndNonTimeBasedTypeAndReportsFailedProbe(): void
    {
        // No ffmpeg runner.
        $repoA = $this->makeFakeRepo();
        $noFfmpeg = new MediaScanner($this->createMock(Connection::class), $repoA);
        $this->assertSame(
            'skipped',
            $noFfmpeg->backfillItemSourceMetadata(['id' => 'x', 'type' => 'movie', 'path' => '/x.mkv', 'metadata' => []])
        );

        // Non-time-based type is never probed.
        $repoB = $this->makeFakeRepo();
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->never())->method('probe');
        $scanner = new MediaScanner($this->createMock(Connection::class), $repoB, null, null, null, $ff);
        $this->assertSame(
            'skipped',
            $scanner->backfillItemSourceMetadata(['id' => 'x', 'type' => 'photo', 'path' => '/x.jpg', 'metadata' => []])
        );

        // Probe returns null → failed, nothing written.
        $repoC = $this->makeFakeRepo();
        $nullProbe = $this->createMock(FfmpegRunner::class);
        $nullProbe->method('probe')->willReturn(null);
        $scanner2 = new MediaScanner($this->createMock(Connection::class), $repoC, null, null, null, $nullProbe);
        $this->assertSame(
            'failed',
            $scanner2->backfillItemSourceMetadata(['id' => 'x', 'type' => 'movie', 'path' => '/missing.mkv', 'metadata' => []])
        );
        $this->assertSame([], $repoC->updates);
        $this->assertSame([], $repoC->streamOps);
    }

    /**
     * Null-ffmpeg path unchanged: a scan with no ffprobe runner indexes the file
     * but writes NEITHER metadata_json['source'] NOR any media_streams rows.
     */
    public function testNullFfmpegScanWritesNeitherSourceNorStreams(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo);

        $this->tmpDir = $this->makeTempDirWith(['Inception (2010).mkv']);
        $scanner->scan('lib-1', $this->tmpDir, 'movie');

        $movies = array_values(array_filter($repo->items(), fn ($i) => $i['type'] === 'movie'));
        $this->assertCount(1, $movies, 'file still indexed without an ffprobe runner');
        $this->assertArrayNotHasKey('source', $this->metaOf($movies[0]));
        $this->assertArrayNotHasKey('duration_seconds', $this->metaOf($movies[0]));
        $this->assertSame([], $repo->streamOps, 'no media_streams writes without an ffprobe runner');
    }

    /**
     * Defensive: a malformed non-array entry in the streams list is skipped, and
     * the real video stream after it is still selected.
     */
    public function testSummarizeProbeIgnoresNonArrayStreamEntries(): void
    {
        $summary = $this->summarize([
            'streams' => [
                'not-an-array',
                ['index' => 1, 'codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
            ],
            'format' => ['duration' => '10.0'],
        ]);

        $this->assertSame('h264', $this->arrOf($summary, 'source')['video_codec']);
        $this->assertSame(1280, $this->arrOf($summary, 'source')['width']);
    }

    /**
     * streamLanguage() guards: a non-array stream, a stream with no tags, and a
     * non-array tags value all yield null; "und"/empty are dropped; a real tag
     * is returned and truncated to 10 chars.
     */
    public function testStreamLanguageGuardsCoverNonArrayStreamAndMissingTags(): void
    {
        $this->assertNull($this->invokeStreamLanguage('not-an-array'), 'non-array stream => null');
        $this->assertNull($this->invokeStreamLanguage(['codec_type' => 'audio']), 'no tags key => null');
        $this->assertNull($this->invokeStreamLanguage(['tags' => 'nope']), 'non-array tags => null');
        $this->assertNull($this->invokeStreamLanguage(['tags' => ['language' => 'und']]), '"und" => null');
        $this->assertNull($this->invokeStreamLanguage(['tags' => ['language' => '']]), 'empty => null');
        $this->assertSame('fr', $this->invokeStreamLanguage(['tags' => ['language' => 'fr']]));
        $this->assertSame('abcdefghij', $this->invokeStreamLanguage(['tags' => ['language' => 'abcdefghijKLMNOP']]));
    }

    /**
     * Backfill guards: an $existing row lacking a usable id or path is skipped
     * BEFORE any probe or write.
     */
    public function testBackfillSkipsWhenExistingRowLacksIdOrPath(): void
    {
        $repo = $this->makeFakeRepo();
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $repo,
            null,
            null,
            null,
            $this->makeProbeStub($this->h264AacProbe())
        );

        $this->assertSame('skipped', $scanner->backfillItemSourceMetadata([
            'type' => 'movie',
            'path' => '/x/Movie.mkv',
            'metadata' => [],
        ]), 'missing id => skipped');
        $this->assertSame('skipped', $scanner->backfillItemSourceMetadata([
            'id' => 'movie-1',
            'type' => 'movie',
            'metadata' => [],
        ]), 'missing path => skipped');

        $this->assertSame([], $repo->updates);
        $this->assertSame([], $repo->streamOps);
    }

    /**
     * Backfill is fully guarded: an UNEXPECTED throwable escaping after streams
     * persist (here the metadata update() throws) is swallowed and reported as
     * 'failed' — it never propagates to abort the scan or the batch CLI.
     */
    public function testBackfillReturnsFailedWhenAnUnexpectedThrowableEscapesIntoTheGuard(): void
    {
        $repo = $this->makeFakeRepo();
        $repo->throwOnUpdate = true;
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $repo,
            null,
            null,
            null,
            $this->makeProbeStub($this->h264AacProbe())
        );

        $existing = [
            'id' => 'movie-1',
            'type' => 'movie',
            'path' => '/x/Movie.mkv',
            'metadata' => [], // neither duration nor source → a metadata write is attempted
        ];

        $this->assertSame('failed', $scanner->backfillItemSourceMetadata($existing));
        // Streams persisted (delete + 2 adds) before the metadata write threw.
        $this->assertSame(['delete', 'add', 'add'], array_map(fn ($o) => $o['op'], $repo->streamOps));
        $this->assertSame([], $repo->updates, 'the throwing update() recorded nothing');
    }

    /**
     * A realistic single-video + single-audio ffprobe result: 1080p h264 with a
     * per-stream video bitrate, stereo aac, and a container duration + bitrate —
     * the shape {@see FfmpegRunner::probe()} itself yields.
     *
     * @return array<string, mixed>
     */
    private function h264AacProbe(): array
    {
        return [
            'streams' => [
                [
                    'index' => 0,
                    'codec_type' => 'video',
                    'codec_name' => 'h264',
                    'width' => 1920,
                    'height' => 1080,
                    'bit_rate' => '5000000',
                    'pix_fmt' => 'yuv420p',
                    'tags' => ['language' => 'eng'],
                ],
                [
                    'index' => 1,
                    'codec_type' => 'audio',
                    'codec_name' => 'aac',
                    'bit_rate' => '128000',
                    'tags' => ['language' => 'eng'],
                ],
            ],
            'format' => ['duration' => '5433.2', 'bit_rate' => '5200000'],
        ];
    }

    /**
     * A realistic multi-track ffprobe result: 1080p h264, TWO audio tracks
     * (default eng 5.1 + stereo commentary), a text srt subtitle (default), a
     * bitmap PGS subtitle, and a data stream that must never persist.
     *
     * @return array<string, mixed>
     */
    private function multiTrackProbe(): array
    {
        return [
            'streams' => [
                [
                    'index' => 0,
                    'codec_type' => 'video',
                    'codec_name' => 'h264',
                    'width' => 1920,
                    'height' => 1080,
                    'bit_rate' => '5000000',
                    'pix_fmt' => 'yuv420p',
                    'disposition' => ['default' => 1],
                ],
                [
                    'index' => 1,
                    'codec_type' => 'audio',
                    'codec_name' => 'ac3',
                    'bit_rate' => '448000',
                    'channels' => 6,
                    'disposition' => ['default' => 1],
                    'tags' => ['language' => 'eng', 'title' => 'Surround 5.1'],
                ],
                [
                    'index' => 2,
                    'codec_type' => 'audio',
                    'codec_name' => 'aac',
                    'bit_rate' => '128000',
                    'channels' => 2,
                    'disposition' => ['default' => 0],
                    'tags' => ['language' => 'eng', 'title' => "Director's Commentary"],
                ],
                [
                    'index' => 3,
                    'codec_type' => 'subtitle',
                    'codec_name' => 'subrip',
                    'disposition' => ['default' => 1],
                    'tags' => ['language' => 'eng'],
                ],
                [
                    'index' => 4,
                    'codec_type' => 'subtitle',
                    'codec_name' => 'hdmv_pgs_subtitle',
                    'tags' => ['language' => 'ger'],
                ],
                ['index' => 5, 'codec_type' => 'data'],
            ],
            'format' => ['duration' => '5433.2', 'bit_rate' => '5800000'],
        ];
    }

    /**
     * Build a mocked FfmpegRunner whose probe() returns the given raw ffprobe
     * array (streams + format) for every call.
     *
     * @param array<string, mixed> $probe
     */
    private function makeProbeStub(array $probe): FfmpegRunner
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturn($probe);
        return $ffmpeg;
    }

    /**
     * Invoke the private static MediaScanner::summarizeProbe() directly.
     *
     * @param array<string, mixed> $probe
     * @return array{duration_seconds: int|null, source: array<string, mixed>|null, streams: array<int, array<string, mixed>>}
     */
    private function summarize(array $probe): array
    {
        $method = new \ReflectionMethod(MediaScanner::class, 'summarizeProbe');
        $method->setAccessible(true);
        /** @var array{duration_seconds: int|null, source: array<string, mixed>|null, streams: array<int, array<string, mixed>>} $result */
        $result = $method->invoke(null, $probe);
        return $result;
    }

    /**
     * Invoke the private static MediaScanner::isAttachedPic() directly.
     */
    private function invokeIsAttachedPic(mixed $stream): bool
    {
        $method = new \ReflectionMethod(MediaScanner::class, 'isAttachedPic');
        $method->setAccessible(true);
        return (bool) $method->invoke(null, $stream);
    }

    /**
     * Invoke the private static MediaScanner::streamLanguage() directly.
     */
    private function invokeStreamLanguage(mixed $stream): ?string
    {
        $method = new \ReflectionMethod(MediaScanner::class, 'streamLanguage');
        $method->setAccessible(true);
        $result = $method->invoke(null, $stream);
        return is_string($result) ? $result : null;
    }

    // --- S8: bounded concurrent scan probes ---------------------------------

    /**
     * Outside a Swoole coroutine (PHPUnit CLI's default context — exactly
     * like every other test in this file), scanFlat()'s new batch/fan-out
     * path must degrade to the EXACT sequential per-file probing behaviour
     * that existed before S8: each file's probe result attaches to the
     * RIGHT path (no cross-file mixup) with no coroutine machinery involved.
     * This is the regression-safety test for the non-coroutine fallback.
     */
    public function testScanFlatSequentialFallbackAttachesCorrectDurationPerFileOutsideCoroutine(): void
    {
        $repo = $this->makeFakeRepo();
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturnCallback(function (string $path) {
            return [
                'streams' => [],
                'format' => ['duration' => str_contains($path, 'One') ? '100.0' : '200.0'],
            ];
        });

        $scanner = new MediaScanner($this->createMock(Connection::class), $repo, null, null, null, $ffmpeg);

        $this->tmpDir = $this->makeTempDirWith(['Movie One (2020).mkv', 'Movie Two (2021).mkv']);
        $scanner->scan('lib-1', $this->tmpDir, 'movie');

        $items = $repo->items();
        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $expected = str_contains($this->strOf($item, 'path'), 'One') ? 100 : 200;
            $this->assertSame($expected, $this->metaOf($item)['duration_seconds']);
        }
    }

    /**
     * S8 CORE MECHANISM: inside a real Swoole coroutine, {@see
     * MediaScanner::probeManyConcurrently()}'s bounded fan-out genuinely caps
     * concurrency at the configured `maxConcurrentScanProbes` — an
     * instrumented fake probe records the live in-flight count via a shared
     * counter (yielding mid-call with `Swoole\Coroutine::sleep()` so probes
     * actually overlap) and asserts it never exceeds the bound, while still
     * proving real overlap occurred (not accidentally serialized).
     */
    public function testProbeManyConcurrentlyCapsBoundedConcurrencyAtConfiguredLimit(): void
    {
        if (!extension_loaded('swoole') || !class_exists(\Swoole\Coroutine::class)) {
            $this->markTestSkipped('ext-swoole not loaded; coroutine fan-out not exercisable');
        }
        \Swoole\Coroutine::set(['log_level' => SWOOLE_LOG_ERROR, 'trace_flags' => 0]);

        $maxConcurrency = 3;
        $inFlight = 0;
        $maxObservedInFlight = 0;
        $totalCalls = 0;

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturnCallback(
            function (string $path) use (&$inFlight, &$maxObservedInFlight, &$totalCalls) {
                $totalCalls++;
                $inFlight++;
                $maxObservedInFlight = max($maxObservedInFlight, $inFlight);
                // Yield so other fanned-out probes get a chance to start
                // concurrently — without this every call would resolve
                // synchronously and never overlap.
                \Swoole\Coroutine::sleep(0.02);
                $inFlight--;
                return ['streams' => [], 'format' => ['duration' => '10.0']];
            }
        );

        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $this->makeFakeRepo(),
            null,
            null,
            null,
            $ffmpeg,
            null,
            $maxConcurrency
        );

        $paths = array_map(fn (int $i): string => "/movies/movie-{$i}.mkv", range(1, 10));

        $method = new \ReflectionMethod(MediaScanner::class, 'probeManyConcurrently');
        $method->setAccessible(true);

        $results = null;
        \Swoole\Coroutine\run(function () use ($method, $scanner, $paths, &$results): void {
            $results = $method->invoke($scanner, $paths);
        });

        $this->assertSame(10, $totalCalls, 'every path is probed exactly once');
        $this->assertLessThanOrEqual(
            $maxConcurrency,
            $maxObservedInFlight,
            'in-flight probe count must never exceed the configured bound'
        );
        $this->assertGreaterThan(
            1,
            $maxObservedInFlight,
            'the pool must genuinely run probes concurrently, not one at a time'
        );
        $this->assertIsArray($results);
        $this->assertCount(10, $results);
    }

    /**
     * S8: inside a coroutine, each fanned-out probe's result attaches to the
     * RIGHT path — a classic bug site in concurrent-map-collection code. Two
     * files with DIFFERENT durations are probed concurrently, with the file
     * that logically "should" resolve first deliberately made to sleep
     * LONGER, so a naive implementation that mixed up which coroutine wrote
     * which map key would be caught by mismatched durations.
     */
    public function testProbeManyConcurrentlyAttachesEachResultToItsOwnPathInsideACoroutine(): void
    {
        if (!extension_loaded('swoole') || !class_exists(\Swoole\Coroutine::class)) {
            $this->markTestSkipped('ext-swoole not loaded; coroutine fan-out not exercisable');
        }
        \Swoole\Coroutine::set(['log_level' => SWOOLE_LOG_ERROR, 'trace_flags' => 0]);

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturnCallback(function (string $path) {
            // The "A" file sleeps longer than "B", so B resolves first even
            // though A was launched first — proving the result map keys by
            // path, not by completion/launch order.
            \Swoole\Coroutine::sleep(str_contains($path, 'A') ? 0.03 : 0.01);
            return ['streams' => [], 'format' => ['duration' => str_contains($path, 'A') ? '111.0' : '222.0']];
        });

        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $this->makeFakeRepo(),
            null,
            null,
            null,
            $ffmpeg,
            null,
            4
        );

        $paths = ['/movies/A.mkv', '/movies/B.mkv'];

        $method = new \ReflectionMethod(MediaScanner::class, 'probeManyConcurrently');
        $method->setAccessible(true);

        $results = null;
        $cid = null;
        \Swoole\Coroutine\run(function () use ($method, $scanner, $paths, &$results, &$cid): void {
            $cid = \Swoole\Coroutine::getCid();
            $results = $method->invoke($scanner, $paths);
        });

        $this->assertGreaterThan(0, $cid, 'test must genuinely run inside a coroutine');
        $this->assertIsArray($results);
        $this->assertSame(111, $results['/movies/A.mkv']['duration_seconds'] ?? null);
        $this->assertSame(222, $results['/movies/B.mkv']['duration_seconds'] ?? null);
    }

    /**
     * S8: a probe failure (the underlying ffmpeg->probe() throwing) for ONE
     * file in a concurrently-fanned-out batch must never abort the batch or
     * corrupt the results for the OTHER files — the failing path resolves to
     * `null` while its siblings still resolve normally.
     */
    public function testProbeManyConcurrentlyIsolatesOneFailureFromOtherFilesInTheBatch(): void
    {
        if (!extension_loaded('swoole') || !class_exists(\Swoole\Coroutine::class)) {
            $this->markTestSkipped('ext-swoole not loaded; coroutine fan-out not exercisable');
        }
        \Swoole\Coroutine::set(['log_level' => SWOOLE_LOG_ERROR, 'trace_flags' => 0]);

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturnCallback(function (string $path) {
            if (str_contains($path, 'bad')) {
                throw new \RuntimeException('ffprobe boom');
            }
            return ['streams' => [], 'format' => ['duration' => '50.0']];
        });

        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $this->makeFakeRepo(),
            null,
            null,
            null,
            $ffmpeg,
            null,
            4
        );

        $paths = ['/movies/good1.mkv', '/movies/bad.mkv', '/movies/good2.mkv'];

        $method = new \ReflectionMethod(MediaScanner::class, 'probeManyConcurrently');
        $method->setAccessible(true);

        $results = null;
        \Swoole\Coroutine\run(function () use ($method, $scanner, $paths, &$results): void {
            $results = $method->invoke($scanner, $paths);
        });

        $this->assertIsArray($results);
        $this->assertArrayHasKey('/movies/bad.mkv', $results);
        $this->assertNull($results['/movies/bad.mkv'], 'a failed probe resolves to null, not an exception');
        $this->assertSame(50, $results['/movies/good1.mkv']['duration_seconds'] ?? null);
        $this->assertSame(50, $results['/movies/good2.mkv']['duration_seconds'] ?? null);
    }

    /**
     * Reviewer finding (S8, Low): `\Swoole\Coroutine::create()`'s return value
     * was unchecked — if Swoole itself refuses to SCHEDULE a coroutine (e.g.
     * the process-wide `max_coroutine` ceiling is hit) the closure body never
     * runs, so the semaphore `Channel::push()` reserved just before the
     * `create()` call would never be released by that closure's `finally`,
     * and the "done" signal channel would never receive that path's
     * completion push — so the final `pop()` loop in
     * {@see MediaScanner::probeManyConcurrently()} (the `WaitGroup::wait()`-
     * equivalent join, since PHPStan's bundled stub-only environment doesn't
     * recognise `WaitGroup` — see that method's own docblock) would hang
     * forever waiting for a signal that will never arrive.
     *
     * This is forced DETERMINISTICALLY (not merely asserted-by-inspection) by
     * lowering Swoole's own `max_coroutine` to `1` from inside the coroutine
     * this test runs in — that root coroutine already occupies the single
     * permitted slot, so EVERY subsequent `Coroutine::create()` call inside
     * {@see MediaScanner::probeManyConcurrently()} is refused and returns
     * `false`, real production Swoole behaviour (confirmed empirically: a
     * throwaway script reproduced the exact
     * "Swoole\Coroutine::create(): exceed max number of coroutine N" PHP
     * E_WARNING Swoole emits on refusal). That warning is expected and
     * deliberately provoked, so it is swallowed for the duration of this call
     * via a scoped `set_error_handler()` rather than tripping phpunit.xml's
     * `failOnWarning="true"`.
     *
     * Asserts: (1) `probeManyConcurrently()` returns promptly rather than
     * hanging — `$completed` would remain false if the done-channel join
     * blocked forever; (2) every path resolves to `null` (a refused coroutine
     * spawn is treated exactly like any other single-path probe failure);
     * (3) the ffmpeg mock's `probe()` is NEVER invoked, proving the closure
     * body genuinely never ran for any path (this is the `create() === false`
     * branch, not the ordinary in-coroutine-throw branch already covered by
     * the isolation test above).
     */
    public function testProbeManyConcurrentlyReleasesDoneSignalWhenCoroutineCreateFailsToSchedule(): void
    {
        if (!extension_loaded('swoole') || !class_exists(\Swoole\Coroutine::class)) {
            $this->markTestSkipped('ext-swoole not loaded; coroutine fan-out not exercisable');
        }
        \Swoole\Coroutine::set(['log_level' => SWOOLE_LOG_ERROR, 'trace_flags' => 0]);

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        // The closure body (which calls probeSummary() -> ffmpeg->probe())
        // must NEVER run when Coroutine::create() itself is refused.
        $ffmpeg->expects($this->never())->method('probe');

        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $this->makeFakeRepo(),
            null,
            null,
            null,
            $ffmpeg,
            null,
            5
        );

        $paths = ['/movies/a.mkv', '/movies/b.mkv', '/movies/c.mkv'];

        $method = new \ReflectionMethod(MediaScanner::class, 'probeManyConcurrently');
        $method->setAccessible(true);

        $previousHandler = set_error_handler(
            function (int $errno, string $errstr): bool {
                // Swallow ONLY the expected, deliberately-provoked warning;
                // let anything else propagate to phpunit's own handler.
                return str_contains($errstr, 'exceed max number of coroutine');
            },
            E_WARNING
        );

        $results = null;
        $completed = false;
        try {
            \Swoole\Coroutine\run(function () use ($method, $scanner, $paths, &$results, &$completed): void {
                // Force EVERY Coroutine::create() call inside
                // probeManyConcurrently() to fail: this run() root coroutine
                // already occupies the one and only permitted slot.
                \Swoole\Coroutine::set(['max_coroutine' => 1]);
                $results = $method->invoke($scanner, $paths);
                $completed = true;
            });
        } finally {
            set_error_handler($previousHandler);
            // Restore Swoole's default ceiling so it cannot leak into any
            // other test running later in this same process.
            \Swoole\Coroutine::set(['max_coroutine' => 100000]);
        }

        $this->assertTrue($completed, 'wait() must return (not hang) when every Coroutine::create() call fails');
        $this->assertSame(
            ['/movies/a.mkv' => null, '/movies/b.mkv' => null, '/movies/c.mkv' => null],
            $results,
            'a refused coroutine spawn must resolve to null for its path, like any other probe failure'
        );
    }

    /**
     * processFile()'s new optional `$precomputedProbe` parameter, in
     * isolation: supplying a precomputed probe result produces the SAME
     * created item as when processFile() computes the probe itself, and the
     * ffmpeg mock's probe() is NEVER invoked in that path.
     */
    public function testProcessFileWithPrecomputedProbeSkipsInternalProbeAndMatchesSelfProbedResult(): void
    {
        $this->tmpDir = $this->makeTempDirWith(['Inception (2010).mkv']);
        $file = new \SplFileInfo($this->tmpDir . '/Inception (2010).mkv');

        $method = new \ReflectionMethod(MediaScanner::class, 'processFile');
        $method->setAccessible(true);

        // Baseline: processFile() computes its own probe (precomputed = false,
        // the "not supplied" sentinel — today's unmodified behaviour).
        $repoA = $this->makeFakeRepo();
        $ffmpegA = $this->makeFfmpegStub('300.0');
        $scannerA = new MediaScanner($this->createMock(Connection::class), $repoA, null, null, null, $ffmpegA);
        $addedA = $method->invoke($scannerA, 'lib-1', $file, 'movie', null, null, false);
        $this->assertTrue($addedA);

        // Precomputed: the SAME probe summary supplied directly. The
        // ffmpeg mock's probe() must never be called.
        $repoB = $this->makeFakeRepo();
        $ffmpegB = $this->createMock(FfmpegRunner::class);
        $ffmpegB->expects($this->never())->method('probe');
        $scannerB = new MediaScanner($this->createMock(Connection::class), $repoB, null, null, null, $ffmpegB);
        $precomputed = ['duration_seconds' => 300, 'source' => null, 'streams' => []];
        $addedB = $method->invoke($scannerB, 'lib-1', $file, 'movie', null, null, $precomputed);
        $this->assertTrue($addedB);

        $itemA = $repoA->items()[0];
        $itemB = $repoB->items()[0];
        $this->assertSame(300, $this->metaOf($itemA)['duration_seconds']);
        $this->assertSame(
            $this->metaOf($itemA)['duration_seconds'],
            $this->metaOf($itemB)['duration_seconds'],
            'precomputed-probe path must produce the same item as the self-probing path'
        );
    }

    /**
     * A precomputed probe of `null` (the fan-out probe genuinely failed for
     * this file) is a LEGITIMATE, honoured outcome — processFile() must NOT
     * silently re-trigger a second internal probe attempt for it. This is
     * the `false`-vs-`null` sentinel distinction the parameter docblock
     * describes.
     */
    public function testProcessFileWithPrecomputedNullProbeNeverReprobesInternally(): void
    {
        $repo = $this->makeFakeRepo();
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');
        $scanner = new MediaScanner($this->createMock(Connection::class), $repo, null, null, null, $ffmpeg);

        $this->tmpDir = $this->makeTempDirWith(['Movie One (2020).mkv']);
        $file = new \SplFileInfo($this->tmpDir . '/Movie One (2020).mkv');

        $method = new \ReflectionMethod(MediaScanner::class, 'processFile');
        $method->setAccessible(true);
        $added = $method->invoke($scanner, 'lib-1', $file, 'movie', null, null, null);

        $this->assertTrue($added);
        $items = $repo->items();
        $this->assertArrayNotHasKey(
            'duration_seconds',
            $this->metaOf($items[0]),
            'a precomputed null probe must be honoured as-is, not re-probed'
        );
    }

    /**
     * TestEngineer (S8) line-coverage gap: {@see MediaScanner::processScanBatch()}'s
     * `if ($batch === []) { return 0; }` early-return guard is unreachable
     * from {@see MediaScanner::scanFlat()}'s own call site — `array_chunk()`
     * over an empty `$candidates` list yields zero chunks, so the calling
     * `foreach` body (and therefore `processScanBatch()`) never runs at all
     * for an empty candidate set. The guard only protects the method's own
     * contract as a private helper (e.g. against a future direct call with
     * an empty batch), so it is exercised here directly via reflection
     * rather than via `scan()`.
     */
    public function testProcessScanBatchWithEmptyBatchReturnsZeroWithoutAnyLookupOrProbe(): void
    {
        $repo = $this->makeFakeRepo();
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');

        $scanner = new MediaScanner($this->createMock(Connection::class), $repo, null, null, null, $ffmpeg);

        $method = new \ReflectionMethod(MediaScanner::class, 'processScanBatch');
        $method->setAccessible(true);
        $added = $method->invoke($scanner, 'lib-1', [], 'movie', null, null, null);

        $this->assertSame(0, $added);
        $this->assertSame([], $repo->findPathsMapCallSizes, 'an empty batch must never call findPathsMap()');
        $this->assertSame([], $repo->items(), 'no items created for an empty batch');
    }

    /**
     * TestEngineer (S8) gap: `isProbeEligibleLibraryType()` is the gate that
     * decides, PURELY from the library `$type`, whether a batch's brand-new
     * files are fanned out to the concurrent probe pool at all — the chosen
     * design (confirmed by reading {@see MediaScanner::processScanBatch()})
     * is "decide eligibility BEFORE probing", never "probe everything and
     * discard the result for ineligible types". Every `DURATION_PROBE_TYPES`
     * member reachable at the scanFlat() library-type level ('video',
     * 'series', 'movie' — all mapped via {@see isVideoContentLibrary()} —
     * plus 'audio' and 'audiobook') must gate true; every non-probe-eligible
     * type ('image', 'book', an unrecognised/empty string) must gate false.
     */
    public function testIsProbeEligibleLibraryTypeGatesVideoContentAndAudioTrueEverythingElseFalse(): void
    {
        $scanner = new MediaScanner($this->createMock(Connection::class), $this->makeFakeRepo());

        $method = new \ReflectionMethod(MediaScanner::class, 'isProbeEligibleLibraryType');
        $method->setAccessible(true);

        foreach (['video', 'series', 'movie', 'audio', 'audiobook'] as $type) {
            $this->assertTrue($method->invoke($scanner, $type), "'{$type}' must be probe-eligible");
        }
        foreach (['image', 'book', 'unknown-type', ''] as $type) {
            $this->assertFalse($method->invoke($scanner, $type), "'{$type}' must NOT be probe-eligible");
        }
    }

    /**
     * The two halves of the probe gate must agree. `isProbeEligibleLibraryType()`
     * decides whether a library's new files reach the concurrent probe pool at
     * all, while `DURATION_PROBE_TYPES` decides whether the resulting media type
     * keeps its duration — so a type accepted by one and rejected by the other
     * is either probed and discarded, or wanted and never fanned out.
     *
     * Checked through {@see MediaScanner::determineMediaType()} so the mapping
     * from library type to media type is exercised rather than assumed.
     */
    public function testProbeGateAndDurationProbeTypesAgreeForEveryLibraryType(): void
    {
        $scanner = new MediaScanner($this->createMock(Connection::class), $this->makeFakeRepo());

        $gate = new \ReflectionMethod(MediaScanner::class, 'isProbeEligibleLibraryType');
        $gate->setAccessible(true);
        $determine = new \ReflectionMethod(MediaScanner::class, 'determineMediaType');
        $determine->setAccessible(true);

        /** @var list<string> $probeTypes */
        $probeTypes = (new \ReflectionClass(MediaScanner::class))->getConstant('DURATION_PROBE_TYPES');

        $file = new \SplFileInfo(__FILE__);

        foreach (['video', 'series', 'movie', 'audio', 'audiobook', 'image', 'book'] as $libraryType) {
            $mediaType = $determine->invoke($scanner, $file, $libraryType);
            $this->assertIsString($mediaType);

            $this->assertSame(
                in_array($mediaType, $probeTypes, true),
                $gate->invoke($scanner, $libraryType),
                sprintf(
                    'Library type "%s" yields media type "%s"; the probe gate and '
                    . 'DURATION_PROBE_TYPES disagree about whether to probe it.',
                    $libraryType,
                    $mediaType
                )
            );
        }
    }

    /**
     * Audiobook libraries are scanned by THIS scanner
     * (LibraryManager::scanAudiobookLibrary() calls
     * `scan($id, $path, 'audiobook')`), so their files must keep a duration.
     * They were previously typed `audiobook` and then skipped — long-form
     * audio with no runtime.
     */
    public function testAudiobookLibraryTypeYieldsADurationProbedMediaType(): void
    {
        $scanner = new MediaScanner($this->createMock(Connection::class), $this->makeFakeRepo());

        $determine = new \ReflectionMethod(MediaScanner::class, 'determineMediaType');
        $determine->setAccessible(true);

        /** @var list<string> $probeTypes */
        $probeTypes = (new \ReflectionClass(MediaScanner::class))->getConstant('DURATION_PROBE_TYPES');

        $mediaType = $determine->invoke($scanner, new \SplFileInfo(__FILE__), 'audiobook');

        $this->assertSame('audiobook', $mediaType);
        $this->assertContains('audiobook', $probeTypes);
    }

    /**
     * `track` must NOT be listed: music libraries route to
     * MusicLibraryManager/AudioScanner and never reach processFile(), so a
     * `track` entry would be dead config implying a path that does not exist.
     */
    public function testTrackIsNotADurationProbeTypeBecauseThisScannerNeverProducesIt(): void
    {
        /** @var list<string> $probeTypes */
        $probeTypes = (new \ReflectionClass(MediaScanner::class))->getConstant('DURATION_PROBE_TYPES');

        $this->assertNotContains('track', $probeTypes);

        $scanner = new MediaScanner($this->createMock(Connection::class), $this->makeFakeRepo());
        $determine = new \ReflectionMethod(MediaScanner::class, 'determineMediaType');
        $determine->setAccessible(true);

        $file = new \SplFileInfo(__FILE__);
        foreach (['video', 'series', 'movie', 'audio', 'audiobook', 'image', 'book', 'music'] as $libraryType) {
            $this->assertNotSame(
                'track',
                $determine->invoke($scanner, $file, $libraryType),
                'MediaScanner must never type an item `track`.'
            );
        }
    }

    /**
     * TestEngineer (S8) gap: a batch mixing an ALREADY-INDEXED path with a
     * BRAND-NEW path (the realistic incremental-rescan shape — most scans
     * are mostly-unchanged with a few new files) must route each path to the
     * CORRECT one of {@see MediaScanner::processScanBatch()}'s two branches,
     * in the SAME batch — not just in isolation (every pre-existing test
     * scanned either an all-new or an all-already-indexed directory, never
     * both together). Proven via: exactly one `update()` (the existing
     * path's backfill, preserving its prior metadata) and exactly one
     * `create()` (the new path); each path's ffmpeg probe is invoked exactly
     * ONCE and attaches to the CORRECT item (no cross-path mixup, no
     * double-probe, no dropped path).
     */
    public function testProcessScanBatchRoutesExistingPathsToBackfillAndNewPathsToProbePoolInTheSameBatch(): void
    {
        $repo = $this->makeFakeRepo();

        $this->tmpDir = $this->makeTempDirWith(['Existing Movie (2010).mkv', 'New Movie (2020).mkv']);
        $existingPath = $this->tmpDir . '/Existing Movie (2010).mkv';
        $newPath = $this->tmpDir . '/New Movie (2020).mkv';

        $repo->seed([
            'id' => 'existing-1',
            'library_id' => 'lib-1',
            'parent_id' => null,
            'name' => 'Existing Movie',
            'type' => 'movie',
            'path' => $existingPath,
            // No duration/source yet — eligible for backfill (mirrors
            // testRescanBackfillsMissingDurationOnExistingItem's shape).
            'metadata_json' => ['tmdb_id' => 111],
        ]);

        $probedPaths = [];
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturnCallback(function (string $path) use (&$probedPaths) {
            $probedPaths[] = $path;
            return [
                'streams' => [],
                'format' => ['duration' => str_contains($path, 'Existing') ? '999.0' : '555.0'],
            ];
        });

        $scanner = new MediaScanner($this->createMock(Connection::class), $repo, null, null, null, $ffmpeg);
        $scanner->scan('lib-1', $this->tmpDir, 'movie');

        // Each path probed exactly once — no double-probe, no missed probe,
        // regardless of which branch (backfill vs. probe-pool) it went
        // through.
        sort($probedPaths);
        $expectedProbed = [$existingPath, $newPath];
        sort($expectedProbed);
        $this->assertSame($expectedProbed, $probedPaths, 'each path in the mixed batch is probed exactly once');

        $items = $repo->items();
        $this->assertCount(2, $items, 'exactly one NEW item created; the existing item is not duplicated');

        $updates = array_values(array_filter($repo->updates, fn ($u) => $u['id'] === 'existing-1'));
        $this->assertCount(
            1,
            $updates,
            'the already-indexed path routed to backfillItemSourceMetadata() (update), not create()'
        );
        $this->assertSame(999, $this->metaOf($updates[0]['data'])['duration_seconds']);
        $this->assertSame(111, $this->metaOf($updates[0]['data'])['tmdb_id'], 'existing metadata preserved');

        $newItem = null;
        foreach ($items as $item) {
            if ($item['path'] === $newPath) {
                $newItem = $item;
            }
        }
        $this->assertNotNull($newItem, 'the brand-new path routed to processFile()/create()');
        $this->assertSame(555, $this->metaOf($newItem)['duration_seconds']);

        // Load-bearing routing proof: processFile()'s OWN internal
        // findByPath() existence re-check must NEVER be reached for the
        // already-indexed path — batching's whole point (avoiding N+1
        // lookups on a rescan) is defeated if the already-indexed branch
        // falls through to processFile() and lets ITS redundant per-file
        // findByPath() call re-derive the same answer findPathsMap() already
        // gave.
        //
        // With the S8-1 optimization (callerConfirmedAbsent=true passed for
        // batch-proven-absent paths), neither the existing path NOR the new
        // path calls findByPath in processFile — the batch already confirmed
        // absence, so the defensive check is redundant. For the new path,
        // upsertByPath with callerConfirmedAbsent=true relies on the
        // unique-index 1062 catch for race safety instead.
        $this->assertSame(
            [],
            $repo->findByPathCalls,
            'neither existing nor new paths should call findByPath in processFile '
                . 'when processScanBatch already confirmed absence via findPathsMap'
        );
    }

    /**
     * TestEngineer (S8) gap: {@see MediaScanner::SCAN_BATCH_SIZE} (200)
     * chunking must not drop, duplicate, or misattribute files across a
     * batch boundary. 250 candidates (> SCAN_BATCH_SIZE) forces EXACTLY
     * `ceil(250 / SCAN_BATCH_SIZE)` batches; every item's probed duration is
     * derived deterministically from its OWN filename-embedded index, so a
     * cross-batch misattribution bug (a file receiving a neighbour's probe
     * result) is directly observable regardless of filesystem iteration
     * order. Batch sizes are asserted against the ACTUAL SCAN_BATCH_SIZE
     * constant (via reflection) rather than a hardcoded 200, so this test
     * keeps validating chunking correctness even if that constant is ever
     * deliberately retuned — the important invariant is "no file lost or
     * duplicated across chunk boundaries", not the specific chunk size.
     */
    public function testScanFlatChunksCandidatesAcrossMultipleBatchesWithoutDroppingDuplicatingOrMisattributingFiles(): void
    {
        $constant = new \ReflectionClassConstant(MediaScanner::class, 'SCAN_BATCH_SIZE');
        $batchSize = $constant->getValue();
        $this->assertIsInt($batchSize);

        $fileCount = $batchSize + 50; // guarantees at least 2 batches
        $this->assertGreaterThan($batchSize, $fileCount);

        $filenames = [];
        for ($i = 1; $i <= $fileCount; $i++) {
            $filenames[] = sprintf('Movie %04d (2020).mkv', $i);
        }
        $repo = $this->makeFakeRepo();
        $this->tmpDir = $this->makeTempDirWith($filenames);

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturnCallback(function (string $path) {
            // Duration is derived SOLELY from the file's own embedded index,
            // so any cross-batch mixup (wrong duration on the wrong file)
            // is directly observable independent of iteration/batch order.
            preg_match('/Movie (\d{4})/', $path, $m);
            $index = isset($m[1]) ? (int) $m[1] : 0;
            return ['streams' => [], 'format' => ['duration' => (string) ($index * 10) . '.0']];
        });

        $scanner = new MediaScanner($this->createMock(Connection::class), $repo, null, null, null, $ffmpeg);
        $scanner->scan('lib-1', $this->tmpDir, 'movie');

        $items = $repo->items();
        $this->assertCount(
            $fileCount,
            $items,
            'every candidate across ALL batches was created exactly once — none dropped, none duplicated'
        );

        $expectedBatchSizes = [];
        $remaining = $fileCount;
        while ($remaining > 0) {
            $chunk = min($batchSize, $remaining);
            $expectedBatchSizes[] = $chunk;
            $remaining -= $chunk;
        }
        $this->assertSame(
            $expectedBatchSizes,
            $repo->findPathsMapCallSizes,
            'candidates were chunked into the expected number of SCAN_BATCH_SIZE-bounded batches'
        );

        $seenPaths = [];
        foreach ($items as $item) {
            $path = $this->strOf($item, 'path');
            $this->assertArrayNotHasKey($path, $seenPaths, 'no duplicate path created');
            $seenPaths[$path] = true;

            preg_match('/Movie (\d{4})/', $path, $m);
            $index = isset($m[1]) ? (int) $m[1] : 0;
            $this->assertSame(
                $index * 10,
                $this->metaOf($item)['duration_seconds'],
                "file at index {$index} must carry its OWN probed duration, "
                    . "not a neighbour's (batch-boundary misattribution check)"
            );
        }
    }

    /**
     * TestEngineer (S8) gap: item CREATION order must track the ORIGINAL
     * candidate (filesystem-iteration) order, not the completion order of
     * the concurrent probe pool — this is {@see MediaScanner::scanFlat()}'s
     * documented "reproducible scan results" guarantee (see its `@since
     * 0.35.0 (S8)` docblock).
     *
     * Ground truth is computed INDEPENDENTLY of the scanner under test — by
     * walking the same directory with the identical `RecursiveDirectoryIterator`
     * + `RecursiveIteratorIterator` idiom `scanFlat()`'s own Phase 1 uses,
     * entirely outside any `MediaScanner` call — rather than comparing two
     * scanner runs only against EACH OTHER. Comparing two scanner runs
     * against each other cannot catch a reordering bug that both runs share
     * (e.g. a mutation that always processes a batch in reverse would
     * reverse both the "baseline" and "concurrent" run identically and the
     * two would still match); comparing each run against this independently
     * derived ground truth closes that gap.
     *
     * The concurrent run forces the FIRST-in-ground-truth-order file to sleep
     * LONGEST (finishes probing LAST) and the LAST-in-ground-truth-order file
     * to sleep SHORTEST (finishes FIRST) — an aggressive attempt to force
     * completion order to diverge from candidate order. If creation order
     * ever tracked completion order instead of original candidate order,
     * this reversal would surface it.
     */
    public function testItemCreationOrderMatchesOriginalCandidateOrderDespiteReversedProbeCompletionTiming(): void
    {
        if (!extension_loaded('swoole') || !class_exists(\Swoole\Coroutine::class)) {
            $this->markTestSkipped('ext-swoole not loaded; coroutine fan-out not exercisable');
        }
        \Swoole\Coroutine::set(['log_level' => SWOOLE_LOG_ERROR, 'trace_flags' => 0]);

        $this->tmpDir = $this->makeTempDirWith(['A.mkv', 'B.mkv', 'C.mkv', 'D.mkv', 'E.mkv']);

        // Ground truth: an INDEPENDENT walk of the directory (same iterator
        // idiom as scanFlat()'s own Phase 1), computed WITHOUT ever calling
        // into MediaScanner, so a bug shared by every scanner run cannot
        // hide from this comparison.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $groundTruthOrder = [];
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && !$file->isDir() && strtolower($file->getExtension()) === 'mkv') {
                $groundTruthOrder[] = $file->getFilename();
            }
        }
        $this->assertCount(5, $groundTruthOrder, 'sanity: ground-truth walk sees all 5 files');

        // Baseline: no coroutine, uniform/no delay.
        $repoBaseline = $this->makeFakeRepo();
        $ffmpegBaseline = $this->makeFfmpegStub('10.0');
        $scannerBaseline = new MediaScanner(
            $this->createMock(Connection::class),
            $repoBaseline,
            null,
            null,
            null,
            $ffmpegBaseline
        );
        $scannerBaseline->scan('lib-1', $this->tmpDir, 'movie');
        $baselineOrder = array_map(
            fn (array $item): string => basename($this->strOf($item, 'path')),
            $repoBaseline->items()
        );
        $this->assertSame($groundTruthOrder, $baselineOrder, 'baseline (non-coroutine) order matches ground truth');

        // Concurrent run over the SAME (untouched) directory: reverse the
        // probe-completion order relative to the ground-truth candidate
        // order.
        $delayByName = [];
        $total = count($groundTruthOrder);
        foreach ($groundTruthOrder as $i => $name) {
            $delayByName[$name] = ($total - $i) * 0.01; // first-in-order sleeps longest
        }

        $repoConcurrent = $this->makeFakeRepo();
        $ffmpegConcurrent = $this->createMock(FfmpegRunner::class);
        $ffmpegConcurrent->method('probe')->willReturnCallback(function (string $path) use ($delayByName) {
            \Swoole\Coroutine::sleep($delayByName[basename($path)] ?? 0.0);
            return ['streams' => [], 'format' => ['duration' => '10.0']];
        });
        $scannerConcurrent = new MediaScanner(
            $this->createMock(Connection::class),
            $repoConcurrent,
            null,
            null,
            null,
            $ffmpegConcurrent,
            null,
            5 // cap high enough that all 5 probes genuinely overlap in one wave
        );

        \Swoole\Coroutine\run(function () use ($scannerConcurrent): void {
            $scannerConcurrent->scan('lib-1', $this->tmpDir, 'movie');
        });

        $concurrentOrder = array_map(
            fn (array $item): string => basename($this->strOf($item, 'path')),
            $repoConcurrent->items()
        );

        $this->assertSame(
            $groundTruthOrder,
            $concurrentOrder,
            'item creation order must match the INDEPENDENTLY-derived original candidate order, '
                . 'not probe-completion order'
        );
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
        $this->assertEqualsIgnoringCase('Dr. Stone', $this->strOf($series[0], 'name'));
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
                mb_check_encoding($this->strOf($item, 'name'), 'UTF-8'),
                'every stored name must be valid UTF-8'
            );
            $this->assertTrue(
                mb_check_encoding($this->strOf($item, 'path'), 'UTF-8'),
                'every stored path must be valid UTF-8'
            );
        }
    }

    /**
     * In-memory ItemRepository double: records create()s and supports findByPath
     * for the scanner's dedup + find-or-create-container logic.
     */
    private function makeFakeRepo(): InMemoryScannerRepo
    {
        $mockConn = $this->createMock(Connection::class);

        return new InMemoryScannerRepo($mockConn);
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

    /**
     * SV-1.3 / SV-2.9 behaviour: when the media-asset and similarity job stores
     * are wired (as they now are in prod via MediaServicesProvider), a scan
     * enqueues a media-asset job once per NEW chapter-capable file (mkv/mp4/webm)
     * and a similarity job once per NEW media item.
     *
     * The `.avi` file is a processed media file that is NOT chapter-capable, so
     * it must be excluded from the media-asset queue but still enqueue a
     * similarity job — proving the two guards are independent and correct.
     */
    public function testScanEnqueuesBackgroundJobsWhenStoresWired(): void
    {
        $assetQueue = sys_get_temp_dir() . '/phlix_asset_q_' . uniqid();
        $simQueue   = sys_get_temp_dir() . '/phlix_sim_q_' . uniqid();
        $assetStore = new MediaAssetJobStore($assetQueue);
        $simStore   = new SimilarityJobStore($simQueue);

        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $this->makeFakeRepo(),
            null,   // logger
            null,   // eventDispatcher
            null,   // trailerFinder
            $this->createMock(FfmpegRunner::class), // ffmpeg — required for the asset enqueue guard
            null,   // noiseSuffixes
            null,   // maxConcurrentScanProbes
            null,   // similarityService
            null,   // collectionService
            $assetStore,
            $simStore
        );

        $this->tmpDir = $this->makeTempDirWith([
            'A (2020).mkv', // chapter-capable → asset + similarity
            'B (2021).avi', // processed, NOT chapter-capable → similarity only
        ]);

        try {
            $scanner->scan('lib-1', $this->tmpDir, 'movie');

            $this->assertSame(
                1,
                $assetStore->queueSize(),
                'Exactly one media-asset job should be enqueued — only the '
                . 'chapter-capable .mkv file, never the .avi.'
            );
            $this->assertSame(
                2,
                $simStore->queueSize(),
                'A similarity job should be enqueued once per new media item '
                . '(both the .mkv and the .avi).'
            );
        } finally {
            $this->removeDir($assetQueue);
            $this->removeDir($simQueue);
        }
    }

    /**
     * Guard-negative: with NO job stores wired (the legacy / test shape), a scan
     * must not attempt to enqueue anything and must complete cleanly — proving
     * the enqueue is strictly gated on the injected stores.
     */
    public function testScanDoesNotEnqueueWhenStoresUnwired(): void
    {
        $assetQueue = sys_get_temp_dir() . '/phlix_asset_q_' . uniqid();
        $assetStore = new MediaAssetJobStore($assetQueue);

        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $this->makeFakeRepo(),
            null,
            null,
            null,
            $this->createMock(FfmpegRunner::class),
            null,
            null,
            null,
            null,
            null, // mediaAssetJobStore unwired
            null  // similarityJobStore unwired
        );

        $this->tmpDir = $this->makeTempDirWith(['A (2020).mkv']);

        try {
            $scanner->scan('lib-1', $this->tmpDir, 'movie');
            $this->assertSame(0, $assetStore->queueSize());
        } finally {
            $this->removeDir($assetQueue);
        }
    }
}


/**
 * In-memory ItemRepository double for MediaScanner: faithful create/update/
 * findByPath/findPathsMap/media_streams behaviour plus test-inspection spies.
 */
class InMemoryScannerRepo extends ItemRepository
{
            /** @var array<int, array<string, mixed>> */
    private array $store = [];
    private int $seq = 0;
            /** @var array<int, array{id: string, data: array<string, mixed>}> */
    public array $updates = [];
            /** Spy: counts how many times the canonical-key fallback was consulted. */
    public int $canonicalLookupCount = 0;
            /**
             * Faithful in-memory media_streams table (item_id => rows). A
             * deleteStreamsByItem() clears an item's rows; addStream() appends —
             * so a repeated rescan can be asserted to hold exactly the fresh set
             * (no accumulation), proving the delete-then-insert idempotency.
             *
             * @var array<string, list<array<string, mixed>>>
             */
    public array $streamTable = [];
            /**
             * Ordered log of every media_streams mutation so a test can assert
             * the delete-BEFORE-insert ordering (the idempotency contract).
             *
             * @var list<array{op: string, item_id: string, data?: array<string, mixed>}>
             */
    public array $streamOps = [];
            /**
             * Each addStream() payload in call order (video row, then audio row).
             *
             * @var list<array{item_id: string, data: array<string, mixed>}>
             */
    public array $addedStreams = [];
            /**
             * Item ids passed to deleteStreamsByItem(), in call order.
             *
             * @var list<string>
             */
    public array $deletedStreamItems = [];
            /**
             * When set to N (1-based), the Nth addStream() call throws — lets a
             * test simulate a mid-loop media_streams write failure (e.g. the
             * audio insert failing after the video insert) to exercise the
             * repairable 'failed' backfill path.
             */
    public ?int $throwOnAddStreamCall = null;
    private int $addStreamCalls = 0;
            /**
             * When true, update() throws — lets a test drive an UNEXPECTED
             * throwable inside backfillItemSourceMetadata() (i.e. after streams
             * persist cleanly) into the guarded catch → 'failed' path.
             */
    public bool $throwOnUpdate = false;
            /**
             * S8: size (path count) of every findPathsMap() call, in call
             * order — lets a test prove {@see MediaScanner::scanFlat()}'s
             * SCAN_BATCH_SIZE chunking issues exactly the expected number of
             * batched lookups (one per chunk, not one per file and not one
             * giant call for the whole scan).
             *
             * @var list<int>
             */
    public array $findPathsMapCallSizes = [];
            /**
             * S8: every path passed to findByPath(), in call order — lets a
             * test prove {@see MediaScanner::processScanBatch()}'s routing
             * genuinely BYPASSES processFile()'s own (redundant, per-file)
             * findByPath() existence re-check for paths already resolved by
             * the batched findPathsMap() lookup, rather than merely relying
             * on that inner check to reach the same end result by a
             * different, N+1-reintroducing route.
             *
             * @var list<string>
             */
    public array $findByPathCalls = [];

    public function findByPath(string $path, ?string $libraryId = null): ?array
    {
        $this->findByPathCalls[] = $path;
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

            /**
             * S8: batch counterpart to findByPath() — mirrors the real
             * ItemRepository::findPathsMap() (a single lookup for many
             * paths) but reads from the in-memory $store instead of issuing
             * a mocked-Connection query, so scanFlat()'s new batched
             * already-scanned check behaves identically to the old
             * per-file findByPath() loop for every existing test.
             *
             * @param array<int, string> $paths
             * @return array<string, array<string, mixed>>
             */
    public function findPathsMap(array $paths, ?string $libraryId = null): array
    {
        $this->findPathsMapCallSizes[] = count($paths);
        $wanted = array_flip($paths);
        $map = [];
        foreach ($this->store as $item) {
            $path = $item['path'] ?? null;
            if (!is_string($path) || !isset($wanted[$path])) {
                continue;
            }
            // Mirror the real ItemRepository::findPathsMap() library scoping:
            // when a libraryId is supplied, a same-path row in another library
            // must NOT be treated as already-scanned in this one.
            if ($libraryId !== null && ($item['library_id'] ?? null) !== $libraryId) {
                continue;
            }
            $row = $item;
            $row['metadata'] = is_array($item['metadata_json'] ?? null)
                ? $item['metadata_json']
                : [];
            $map[$path] = $row;
        }
        return $map;
    }

    public function findTopLevelByCanonical(string $libraryId, string $type, string $canonicalKey): ?array
    {
        $this->canonicalLookupCount++;
        if ($canonicalKey === '') {
            return null;
        }
        foreach ($this->store as $item) {
            $meta = is_array($item['metadata_json'] ?? null) ? $item['metadata_json'] : [];
            $storedKey = is_string($meta['canonical_key'] ?? null) ? $meta['canonical_key'] : '';
            if (
                ($item['library_id'] ?? null) === $libraryId
                && ($item['type'] ?? null) === $type
                && ($item['parent_id'] ?? null) === null
                && $storedKey === $canonicalKey
            ) {
                $row = $item;
                $row['metadata'] = $meta;
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
        if ($this->throwOnUpdate) {
            throw new \RuntimeException('simulated metadata update failure');
        }
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
             * Spy: item ids stamped by markStreamsProbed(), in call order —
             * the streams_probed_at marker that guards the lazy playback-info
             * backfill (migration 071). Kept OUT of $streamOps so the
             * delete-before-insert ordering assertions stay focused on the
             * media_streams mutations themselves.
             *
             * @var list<string>
             */
    public array $streamsProbedMarks = [];

    public function markStreamsProbed(string $itemId): void
    {
        $this->streamsProbedMarks[] = $itemId;
    }

    public function deleteStreamsByItem(string $itemId): void
    {
        $this->deletedStreamItems[] = $itemId;
        $this->streamOps[] = ['op' => 'delete', 'item_id' => $itemId];
        unset($this->streamTable[$itemId]);
    }

            /**
             * @param array<string, mixed> $streamData
             */
    public function addStream(string $itemId, array $streamData): string
    {
        $this->addStreamCalls++;
        if ($this->throwOnAddStreamCall !== null && $this->addStreamCalls === $this->throwOnAddStreamCall) {
            throw new \RuntimeException('simulated media_streams insert failure');
        }
        $this->addedStreams[] = ['item_id' => $itemId, 'data' => $streamData];
        $this->streamOps[] = ['op' => 'add', 'item_id' => $itemId, 'data' => $streamData];
        $this->streamTable[$itemId][] = $streamData;
        return 'stream-' . $this->addStreamCalls;
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
}

/**
 * A PSR-14 dispatcher that records every event it is handed, for the F2b
 * series-container enrichment consequence tests. Declared in THIS namespace
 * (the Music suite has its own same-named double) to avoid a redeclare fatal.
 */
final class LibraryRecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;
        return $event;
    }

    /** Clear the recorded events between two scans of the same library. */
    public function reset(): void
    {
        $this->events = [];
    }
}
