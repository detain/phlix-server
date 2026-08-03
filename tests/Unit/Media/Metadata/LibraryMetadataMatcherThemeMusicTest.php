<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Media\Metadata\SeriesMetadataResolver;
use Phlix\Media\Metadata\TmdbProvider;
use Phlix\Media\Metadata\ThemeMusic\ThemeMusicConfig;
use Phlix\Media\Metadata\ThemeMusic\ThemeMusicFetcherInterface;
use Phlix\Media\Metadata\ThemeMusic\ThemeMusicResolver;
use Phlix\Theming\ThemeMediaFinder;

/**
 * Verifies {@see LibraryMetadataMatcher} writes `metadata_json.theme_audio_url`
 * (M3) via the injected {@see ThemeMusicResolver}.
 *
 */
final class LibraryMetadataMatcherThemeMusicTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/matcher_theme_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->cacheDir);
        }
        parent::tearDown();
    }

    private function logger(): StructuredLogger
    {
        return $this->createMock(StructuredLogger::class);
    }

    private function themeResolver(ThemeMusicFetcherInterface $fetcher): ThemeMusicResolver
    {
        $config = ThemeMusicConfig::fromArray([
            'enabled' => true,
            'source' => ThemeMusicConfig::SOURCE_LOCAL_THEN_PLEX,
            'plex_archive_base' => 'https://tvthemes.plexapp.com',
            'cache_dir' => $this->cacheDir,
            'fetch_timeout_seconds' => 5,
        ]);
        return new ThemeMusicResolver($config, new ThemeMediaFinder(), $fetcher, $this->logger());
    }

    public function testSeriesMatchWritesThemeAudioUrlFromPlexFallback(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('query')->willReturn(['total' => 1]);
        $items->expects($this->once())
            ->method('getByLibrary')
            ->with('lib-1', 100, 0)
            ->willReturn([
                [
                    'id' => 'series-1',
                    'type' => 'series',
                    'name' => 'Firefly',
                    'path' => '/media/tv/Firefly',
                    'metadata_json' => '{}',
                    'metadata' => [],
                ],
            ]);
        // No season/episode children.
        $items->method('findByParent')->willReturn([]);

        // The series resolver returns metadata carrying the TVDB id in external_ids.
        $seriesResolver = $this->createMock(SeriesMetadataResolver::class);
        $seriesResolver->method('resolve')->willReturn([
            'external_ids' => ['tmdb' => '1437', 'tvdb' => '78874'],
            'tmdb_id' => '1437',
            'title' => 'Firefly',
            'overview' => 'Space western.',
            'poster_url' => 'https://image.tmdb.org/t/p/w500/p.jpg',
            'sources' => ['tmdb'],
        ]);

        // Plex archive returns audio → the resolver caches + yields the item URL.
        $fetcher = $this->createMock(ThemeMusicFetcherInterface::class);
        $fetcher->expects($this->once())
            ->method('fetch')
            ->with('https://tvthemes.plexapp.com/78874.mp3', 5)
            ->willReturn('THEME-BYTES');

        // Capture the persisted metadata for the series root.
        $persisted = null;
        $items->method('update')->willReturnCallback(
            function (string $id, array $data) use (&$persisted): void {
                if ($id === 'series-1' && isset($data['metadata_json'])) {
                    $persisted = $data['metadata_json'];
                }
            }
        );

        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $seriesResolver,
            $this->logger(),
            null,
            null,
            null,
            null,
            $this->themeResolver($fetcher),
        );

        $result = $matcher->matchLibrary('lib-1');

        $this->assertSame(1, $result['matched']);
        $this->assertIsArray($persisted);
        $this->assertArrayHasKey('theme_audio_url', $persisted);
        $this->assertSame('/stream/theme-media/item/series-1', $persisted['theme_audio_url']);
        $this->assertFileExists($this->cacheDir . '/78874.mp3');
    }

    public function testSeriesMatchWithoutThemeResolverLeavesUrlUnset(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('query')->willReturn(['total' => 1]);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls([
            [
                'id' => 'series-1',
                'type' => 'series',
                'name' => 'Firefly',
                'path' => '/media/tv/Firefly',
                'metadata_json' => '{}',
                'metadata' => [],
            ],
        ], []);
        $items->method('findByParent')->willReturn([]);

        $seriesResolver = $this->createMock(SeriesMetadataResolver::class);
        $seriesResolver->method('resolve')->willReturn([
            'external_ids' => ['tmdb' => '1437', 'tvdb' => '78874'],
            'tmdb_id' => '1437',
            'title' => 'Firefly',
            'sources' => ['tmdb'],
        ]);

        $persisted = null;
        $items->method('update')->willReturnCallback(
            function (string $id, array $data) use (&$persisted): void {
                if ($id === 'series-1' && isset($data['metadata_json'])) {
                    $persisted = $data['metadata_json'];
                }
            }
        );

        // No ThemeMusicResolver injected (last ctor arg omitted).
        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $seriesResolver,
            $this->logger(),
        );

        $matcher->matchLibrary('lib-1');

        $this->assertIsArray($persisted);
        $this->assertArrayNotHasKey('theme_audio_url', $persisted);
    }

    /**
     * Interactive re-match on a `series` item must thread the resolved theme into
     * the child inheritance so episodes inherit `theme_audio_url` — mirroring the
     * whole-library matchSeries() path (regression for the code-review finding
     * that applyMatch() themed only the series root, not its children).
     */
    public function testInteractiveSeriesApplyPropagatesThemeToChildEpisode(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->with('series-1')->willReturn([
            'id' => 'series-1',
            'type' => 'series',
            'name' => 'Firefly',
            'path' => '/media/tv/Firefly',
            'metadata_json' => '{}',
            'metadata' => [],
        ]);
        // Series root has one direct episode child (season number 1, episode 1).
        $items->method('findByParent')->willReturnCallback(
            function (string $parentId): array {
                if ($parentId === 'series-1') {
                    return [[
                        'id' => 'ep-1',
                        'type' => 'episode',
                        'name' => 'Serenity',
                        'metadata_json' => '{"season":1,"episode":1}',
                        'metadata' => ['season' => 1, 'episode' => 1],
                    ]];
                }
                return [];
            }
        );

        // Season resolver returns an (empty) season so cachedSeason() succeeds; the
        // episode still inherits the series theme regardless of season episode data.
        $seriesResolver = $this->createMock(SeriesMetadataResolver::class);
        $seriesResolver->method('resolveSeasonEpisodes')->willReturn([
            'poster_path' => null,
            'overview' => '',
            'episodes' => [],
        ]);

        // Direct TMDB provider drives the interactive apply; details carry the
        // TVDB id so the theme resolver's Plex fallback fires.
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->method('getTvDetails')->with('1437')->willReturn([
            'name' => 'Firefly',
            'overview' => 'Space western.',
            'tvdb_id' => '78874',
        ]);

        // Plex archive returns audio → theme resolver caches + yields the item URL.
        $fetcher = $this->createMock(ThemeMusicFetcherInterface::class);
        $fetcher->method('fetch')
            ->with('https://tvthemes.plexapp.com/78874.mp3', 5)
            ->willReturn('THEME-BYTES');

        // Capture the persisted metadata for BOTH the series root and its episode.
        $persisted = [];
        $items->method('update')->willReturnCallback(
            function (string $id, array $data) use (&$persisted): void {
                if (isset($data['metadata_json'])) {
                    $persisted[$id] = $data['metadata_json'];
                }
            }
        );

        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $seriesResolver,
            $this->logger(),
            $tmdb,
            null,
            null,
            null,
            $this->themeResolver($fetcher),
        );

        $result = $matcher->applyMatch('series-1', '1437', 'tv');

        $this->assertTrue($result['matched']);
        // Series root themed.
        $this->assertArrayHasKey('series-1', $persisted);
        $this->assertSame('/stream/theme-media/item/series-1', $persisted['series-1']['theme_audio_url']);
        // Child episode inherited the SERIES theme on interactive re-match.
        $this->assertArrayHasKey('ep-1', $persisted);
        $this->assertArrayHasKey('theme_audio_url', $persisted['ep-1']);
        $this->assertSame('/stream/theme-media/item/series-1', $persisted['ep-1']['theme_audio_url']);
    }

    /**
     * Interactive re-match on a `season` item must thread the resolved theme into
     * the child inheritance so the season's episodes inherit `theme_audio_url`.
     */
    public function testInteractiveSeasonApplyPropagatesThemeToChildEpisode(): void
    {
        $seriesResolver = $this->createMock(SeriesMetadataResolver::class);
        $seriesResolver->method('resolveSeasonEpisodes')->willReturn([
            'poster_path' => null,
            'overview' => '',
            'episodes' => [],
        ]);

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->method('getTvDetails')->with('1437')->willReturn([
            'name' => 'Firefly',
            'overview' => 'Space western.',
            'tvdb_id' => '78874',
        ]);

        // A season item is not series-typed, so resolveForItem() never fires the
        // Plex fallback for it — its theme must come from a LOCAL Emby/Kodi
        // theme.mp3 next to the season. Stand up a real one on disk.
        $seasonDir = sys_get_temp_dir() . '/matcher_season_' . uniqid();
        @mkdir($seasonDir, 0o775, true);
        file_put_contents($seasonDir . '/theme.mp3', 'LOCAL-THEME');
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->with('season-1')->willReturn([
            'id' => 'season-1',
            'type' => 'season',
            'name' => 'Season 1',
            'path' => $seasonDir . '/S01E01.mkv',
            'metadata_json' => '{"season":1}',
            'metadata' => ['season' => 1],
        ]);
        $items->method('findByParent')->willReturnCallback(
            function (string $parentId): array {
                if ($parentId === 'season-1') {
                    return [[
                        'id' => 'ep-1',
                        'type' => 'episode',
                        'name' => 'Serenity',
                        'metadata_json' => '{"season":1,"episode":1}',
                        'metadata' => ['season' => 1, 'episode' => 1],
                    ]];
                }
                return [];
            }
        );

        $persisted = [];
        $items->method('update')->willReturnCallback(
            function (string $id, array $data) use (&$persisted): void {
                if (isset($data['metadata_json'])) {
                    $persisted[$id] = $data['metadata_json'];
                }
            }
        );

        $fetcher = $this->createMock(ThemeMusicFetcherInterface::class);

        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $seriesResolver,
            $this->logger(),
            $tmdb,
            null,
            null,
            null,
            $this->themeResolver($fetcher),
        );

        $result = $matcher->applyMatch('season-1', '1437', 'tv');

        // Clean up the temp season theme dir.
        @unlink($seasonDir . '/theme.mp3');
        @rmdir($seasonDir);

        $this->assertTrue($result['matched']);
        // The season's episode inherited the season theme (item URL of the season).
        $this->assertArrayHasKey('ep-1', $persisted);
        $this->assertArrayHasKey('theme_audio_url', $persisted['ep-1']);
        $this->assertSame('/stream/theme-media/item/season-1', $persisted['ep-1']['theme_audio_url']);
    }
}
