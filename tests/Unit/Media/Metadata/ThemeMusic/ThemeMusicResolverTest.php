<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\ThemeMusic;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Metadata\ThemeMusic\ThemeMusicConfig;
use Phlix\Media\Metadata\ThemeMusic\ThemeMusicFetcherInterface;
use Phlix\Media\Metadata\ThemeMusic\ThemeMusicResolver;
use Phlix\Theming\ThemeMediaFinder;

/**
 * Unit tests for {@see ThemeMusicResolver} (M3 producer).
 *
 * Covers: local theme hit → stream url; Plex fallback (mocked HTTP 200) → caches
 * + returns url + is idempotent; 404/timeout → null; disabled/off config → null
 * (and no fetch); non-integer tvdb → null with no fetch; movie type never uses
 * the Plex fallback.
 */
final class ThemeMusicResolverTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/theme_music_resolver_' . uniqid();
        mkdir($this->tmpRoot, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpRoot);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        /** @var \SplFileInfo $f */
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    private function config(string $source, ?string $cacheDir = null): ThemeMusicConfig
    {
        return ThemeMusicConfig::fromArray([
            'enabled' => true,
            'source' => $source,
            'plex_archive_base' => 'https://tvthemes.plexapp.com',
            'cache_dir' => $cacheDir ?? ($this->tmpRoot . '/cache'),
            'fetch_timeout_seconds' => 5,
        ]);
    }

    private function logger(): StructuredLogger
    {
        return $this->createMock(StructuredLogger::class);
    }

    public function testLocalThemeHitReturnsItemStreamUrl(): void
    {
        // theme.mp3 sits in the item's folder; findForMediaItem scans the PARENT
        // of the passed path, so pass a file INSIDE the folder holding theme.mp3.
        $folder = $this->tmpRoot . '/The Show';
        mkdir($folder, 0o775, true);
        file_put_contents($folder . '/theme.mp3', 'AUDIO');
        $itemPath = $folder . '/The Show S01E01.mkv';

        $fetcher = $this->createMock(ThemeMusicFetcherInterface::class);
        $fetcher->expects($this->never())->method('fetch');

        $resolver = new ThemeMusicResolver(
            $this->config(ThemeMusicConfig::SOURCE_LOCAL_THEN_PLEX),
            new ThemeMediaFinder(),
            $fetcher,
            $this->logger(),
        );

        $url = $resolver->resolveForItem([
            'item_id' => 'item-1',
            'type' => 'series',
            'path' => $itemPath,
            'tvdb_id' => 81797,
        ]);

        $this->assertSame('/stream/theme-media/item/item-1', $url);
    }

    public function testPlexFallbackCachesAndReturnsUrl(): void
    {
        $cacheDir = $this->tmpRoot . '/cache';
        $fetcher = $this->createMock(ThemeMusicFetcherInterface::class);
        $fetcher->expects($this->once())
            ->method('fetch')
            ->with('https://tvthemes.plexapp.com/81797.mp3', 5)
            ->willReturn('MP3-BYTES');

        $resolver = new ThemeMusicResolver(
            $this->config(ThemeMusicConfig::SOURCE_LOCAL_THEN_PLEX, $cacheDir),
            new ThemeMediaFinder(),
            $fetcher,
            $this->logger(),
        );

        $url = $resolver->resolveForItem([
            'item_id' => 'series-9',
            'type' => 'series',
            'path' => null,
            'tvdb_id' => 81797,
        ]);

        $this->assertSame('/stream/theme-media/item/series-9', $url);
        $this->assertFileExists($cacheDir . '/81797.mp3');
        $this->assertSame('MP3-BYTES', file_get_contents($cacheDir . '/81797.mp3'));
    }

    public function testPlexFallbackIsIdempotentWhenAlreadyCached(): void
    {
        $cacheDir = $this->tmpRoot . '/cache';
        mkdir($cacheDir, 0o775, true);
        file_put_contents($cacheDir . '/81797.mp3', 'ALREADY');

        // A cache hit must NOT re-fetch.
        $fetcher = $this->createMock(ThemeMusicFetcherInterface::class);
        $fetcher->expects($this->never())->method('fetch');

        $resolver = new ThemeMusicResolver(
            $this->config(ThemeMusicConfig::SOURCE_LOCAL_THEN_PLEX, $cacheDir),
            new ThemeMediaFinder(),
            $fetcher,
            $this->logger(),
        );

        $url = $resolver->resolveForItem([
            'item_id' => 'series-9',
            'type' => 'series',
            'tvdb_id' => '81797',
        ]);

        $this->assertSame('/stream/theme-media/item/series-9', $url);
        $this->assertSame('ALREADY', file_get_contents($cacheDir . '/81797.mp3'));
    }

    public function testPlexFetchFailureReturnsNull(): void
    {
        $fetcher = $this->createMock(ThemeMusicFetcherInterface::class);
        $fetcher->expects($this->once())->method('fetch')->willReturn(null);

        $resolver = new ThemeMusicResolver(
            $this->config(ThemeMusicConfig::SOURCE_LOCAL_THEN_PLEX),
            new ThemeMediaFinder(),
            $fetcher,
            $this->logger(),
        );

        $url = $resolver->resolveForItem([
            'item_id' => 'series-9',
            'type' => 'series',
            'tvdb_id' => 81797,
        ]);

        $this->assertNull($url);
        $this->assertFileDoesNotExist($this->tmpRoot . '/cache/81797.mp3');
    }

    public function testDisabledConfigReturnsNullWithoutFetch(): void
    {
        $fetcher = $this->createMock(ThemeMusicFetcherInterface::class);
        $fetcher->expects($this->never())->method('fetch');

        $config = ThemeMusicConfig::fromArray([
            'enabled' => false,
            'source' => ThemeMusicConfig::SOURCE_LOCAL_THEN_PLEX,
            'cache_dir' => $this->tmpRoot . '/cache',
        ]);

        $resolver = new ThemeMusicResolver($config, new ThemeMediaFinder(), $fetcher, $this->logger());

        $this->assertNull($resolver->resolveForItem([
            'item_id' => 'series-9',
            'type' => 'series',
            'tvdb_id' => 81797,
        ]));
    }

    public function testSourceOffReturnsNullWithoutFetch(): void
    {
        $fetcher = $this->createMock(ThemeMusicFetcherInterface::class);
        $fetcher->expects($this->never())->method('fetch');

        $resolver = new ThemeMusicResolver(
            $this->config(ThemeMusicConfig::SOURCE_OFF),
            new ThemeMediaFinder(),
            $fetcher,
            $this->logger(),
        );

        $this->assertNull($resolver->resolveForItem([
            'item_id' => 'series-9',
            'type' => 'series',
            'tvdb_id' => 81797,
        ]));
    }

    public function testLocalOnlySourceNeverFetchesPlex(): void
    {
        $fetcher = $this->createMock(ThemeMusicFetcherInterface::class);
        $fetcher->expects($this->never())->method('fetch');

        $resolver = new ThemeMusicResolver(
            $this->config(ThemeMusicConfig::SOURCE_LOCAL_ONLY),
            new ThemeMediaFinder(),
            $fetcher,
            $this->logger(),
        );

        // Series with a valid tvdb but NO local theme → local_only yields null.
        $this->assertNull($resolver->resolveForItem([
            'item_id' => 'series-9',
            'type' => 'series',
            'path' => $this->tmpRoot . '/nope/file.mkv',
            'tvdb_id' => 81797,
        ]));
    }

    public function testNonIntegerTvdbSkipsFetchAndReturnsNull(): void
    {
        $fetcher = $this->createMock(ThemeMusicFetcherInterface::class);
        $fetcher->expects($this->never())->method('fetch');

        $resolver = new ThemeMusicResolver(
            $this->config(ThemeMusicConfig::SOURCE_LOCAL_THEN_PLEX),
            new ThemeMediaFinder(),
            $fetcher,
            $this->logger(),
        );

        foreach (['abc', '12a', '-5', '', '0', null] as $bad) {
            $this->assertNull($resolver->resolveForItem([
                'item_id' => 'series-9',
                'type' => 'series',
                'tvdb_id' => $bad,
            ]), 'tvdb=' . var_export($bad, true));
        }
    }

    public function testMovieTypeNeverUsesPlexFallback(): void
    {
        $fetcher = $this->createMock(ThemeMusicFetcherInterface::class);
        $fetcher->expects($this->never())->method('fetch');

        $resolver = new ThemeMusicResolver(
            $this->config(ThemeMusicConfig::SOURCE_LOCAL_THEN_PLEX),
            new ThemeMediaFinder(),
            $fetcher,
            $this->logger(),
        );

        // Movie with a tvdb id but no local theme → no fetch, null.
        $this->assertNull($resolver->resolveForItem([
            'item_id' => 'movie-1',
            'type' => 'movie',
            'path' => $this->tmpRoot . '/nope/movie.mkv',
            'tvdb_id' => 81797,
        ]));
    }

    public function testMissingItemIdReturnsNull(): void
    {
        $fetcher = $this->createMock(ThemeMusicFetcherInterface::class);
        $fetcher->expects($this->never())->method('fetch');

        $resolver = new ThemeMusicResolver(
            $this->config(ThemeMusicConfig::SOURCE_LOCAL_THEN_PLEX),
            new ThemeMediaFinder(),
            $fetcher,
            $this->logger(),
        );

        $this->assertNull($resolver->resolveForItem(['type' => 'series', 'tvdb_id' => 81797]));
    }
}
