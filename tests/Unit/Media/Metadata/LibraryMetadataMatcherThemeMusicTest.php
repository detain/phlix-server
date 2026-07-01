<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Media\Metadata\SeriesMetadataResolver;
use Phlix\Media\Metadata\ThemeMusic\ThemeMusicConfig;
use Phlix\Media\Metadata\ThemeMusic\ThemeMusicFetcherInterface;
use Phlix\Media\Metadata\ThemeMusic\ThemeMusicResolver;
use Phlix\Theming\ThemeMediaFinder;

/**
 * Verifies {@see LibraryMetadataMatcher} writes `metadata_json.theme_audio_url`
 * (M3) via the injected {@see ThemeMusicResolver}.
 *
 * @covers \Phlix\Media\Metadata\LibraryMetadataMatcher
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
}
