<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\ThemeMusic\ThemeMusicConfig;
use Phlix\Server\Http\Controllers\ThemeMusicStreamController;
use Phlix\Server\Http\Request;
use Phlix\Theming\ThemeMediaFinder;

/**
 * Unit tests for {@see ThemeMusicStreamController} — the item-level theme-audio
 * stream route `GET /stream/theme-media/item/{mediaItemId}`.
 *
 * Covers: serving a cached Plex theme (audio/mpeg) full + ranged; serving a local
 * Emby/Kodi theme; 400 empty id; 404 unknown item; 404 no theme available.
 *
 * @covers \Phlix\Server\Http\Controllers\ThemeMusicStreamController
 */
final class ThemeMusicStreamControllerTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/theme_music_ctrl_' . uniqid();
        mkdir($this->tmpRoot, 0o775, true);
        unset($_SERVER['HTTP_RANGE']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_RANGE']);
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
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    private function config(string $cacheDir): ThemeMusicConfig
    {
        return ThemeMusicConfig::fromArray(['cache_dir' => $cacheDir]);
    }

    public function testServesCachedPlexThemeAsAudioMpeg(): void
    {
        $cacheDir = $this->tmpRoot . '/cache';
        mkdir($cacheDir, 0o775, true);
        file_put_contents($cacheDir . '/81797.mp3', 'MP3-DATA');

        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->with('series-1')->willReturn([
            'id' => 'series-1',
            'type' => 'series',
            'path' => $this->tmpRoot . '/no-local/series',
            'metadata' => ['external_ids' => ['tvdb' => '81797']],
        ]);

        $controller = new ThemeMusicStreamController($items, new ThemeMediaFinder(), $this->config($cacheDir));
        $response = $controller->streamItemTheme(new Request(), ['mediaItemId' => 'series-1']);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('audio/mpeg', $response->headers['Content-Type']);
        $this->assertSame('MP3-DATA', $response->body);
        $this->assertSame('bytes', $response->headers['Accept-Ranges']);
    }

    public function testServesRangeRequest(): void
    {
        $cacheDir = $this->tmpRoot . '/cache';
        mkdir($cacheDir, 0o775, true);
        file_put_contents($cacheDir . '/81797.mp3', '0123456789');

        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn([
            'id' => 'series-1',
            'type' => 'series',
            'path' => null,
            'metadata' => ['external_ids' => ['tvdb' => 81797]],
        ]);

        $_SERVER['HTTP_RANGE'] = 'bytes=2-5';

        $controller = new ThemeMusicStreamController($items, new ThemeMediaFinder(), $this->config($cacheDir));
        $response = $controller->streamItemTheme(new Request(), ['mediaItemId' => 'series-1']);

        $this->assertSame(206, $response->statusCode);
        $this->assertSame('audio/mpeg', $response->headers['Content-Type']);
        $this->assertSame('2345', $response->body);
        $this->assertSame('bytes 2-5/10', $response->headers['Content-Range']);
    }

    public function testServesLocalThemeWhenPresent(): void
    {
        // Local theme.mp3 in the item's folder; findForMediaItem scans the PARENT
        // of the item path, so put theme.mp3 in the folder holding the item file.
        $folder = $this->tmpRoot . '/The Show';
        mkdir($folder, 0o775, true);
        file_put_contents($folder . '/theme.mp3', 'LOCAL-THEME');
        $itemPath = $folder . '/The Show S01E01.mkv';

        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn([
            'id' => 'series-1',
            'type' => 'series',
            'path' => $itemPath,
            'metadata' => [],
        ]);

        $controller = new ThemeMusicStreamController(
            $items,
            new ThemeMediaFinder(),
            $this->config($this->tmpRoot . '/cache')
        );
        $response = $controller->streamItemTheme(new Request(), ['mediaItemId' => 'series-1']);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('audio/mpeg', $response->headers['Content-Type']);
        $this->assertSame('LOCAL-THEME', $response->body);
    }

    public function testEmptyIdReturns400(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->never())->method('findById');

        $controller = new ThemeMusicStreamController($items, new ThemeMediaFinder(), $this->config($this->tmpRoot));
        $response = $controller->streamItemTheme(new Request(), ['mediaItemId' => '']);

        $this->assertSame(400, $response->statusCode);
    }

    public function testUnknownItemReturns404(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(null);

        $controller = new ThemeMusicStreamController($items, new ThemeMediaFinder(), $this->config($this->tmpRoot));
        $response = $controller->streamItemTheme(new Request(), ['mediaItemId' => 'nope']);

        $this->assertSame(404, $response->statusCode);
    }

    public function testNoThemeAvailableReturns404(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn([
            'id' => 'series-1',
            'type' => 'series',
            'path' => $this->tmpRoot . '/no-local/x.mkv',
            'metadata' => ['external_ids' => ['tvdb' => '99999']], // no cached file
        ]);

        $controller = new ThemeMusicStreamController(
            $items,
            new ThemeMediaFinder(),
            $this->config($this->tmpRoot . '/cache')
        );
        $response = $controller->streamItemTheme(new Request(), ['mediaItemId' => 'series-1']);

        $this->assertSame(404, $response->statusCode);
    }
}
