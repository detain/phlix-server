<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal\Controllers;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\MusicLibraryManager;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\Controllers\MusicPageController;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see MusicPageController}.
 *
 * Render-path tests require the Smarty runtime and the real templates under
 * `public/templates/music/`; they are skipped when Smarty is unavailable. The
 * 400/404 branches are exercised regardless.
 *
 * @covers \Phlix\Server\WebPortal\Controllers\MusicPageController
 */
final class MusicPageControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_album_returns_400_when_name_blank(): void
    {
        $music = Mockery::mock(MusicLibraryManager::class);
        $library = Mockery::mock(LibraryManager::class);
        $library->shouldNotReceive('getAllLibraries');

        $controller = new MusicPageController($music, $library, $this->noSmartyDir());
        $response = $controller->album($this->makeRequest(), ['name' => '']);

        $this->assertSame(400, $response->statusCode);
    }

    public function test_album_returns_404_when_not_found(): void
    {
        $music = Mockery::mock(MusicLibraryManager::class);
        $music->shouldReceive('getAlbums')->with('lib1')->andReturn([]);
        $library = Mockery::mock(LibraryManager::class);
        $library->shouldReceive('getAllLibraries')->andReturn([['id' => 'lib1', 'type' => 'music']]);

        $controller = new MusicPageController($music, $library, $this->noSmartyDir());
        $response = $controller->album($this->makeRequest(), ['name' => 'Nope']);

        $this->assertSame(404, $response->statusCode);
    }

    public function test_artist_returns_404_when_not_found(): void
    {
        $music = Mockery::mock(MusicLibraryManager::class);
        $music->shouldReceive('getAlbums')->with('lib1')->andReturn([]);
        $library = Mockery::mock(LibraryManager::class);
        $library->shouldReceive('getAllLibraries')->andReturn([['id' => 'lib1', 'type' => 'music']]);

        $controller = new MusicPageController($music, $library, $this->noSmartyDir());
        $response = $controller->artist($this->makeRequest(), ['name' => 'Ghost']);

        $this->assertSame(404, $response->statusCode);
    }

    /**
     * @group integration
     */
    public function test_albums_renders_grid(): void
    {
        $this->skipWithoutSmarty();
        $controller = $this->controllerWithFixtures();

        $response = $controller->albums($this->makeRequest(), []);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('Greatest Hits', $response->body);
        $this->assertStringNotContainsString('{$album', $response->body);
    }

    /**
     * @group integration
     */
    public function test_album_detail_renders_tracks(): void
    {
        $this->skipWithoutSmarty();
        $controller = $this->controllerWithFixtures();

        $response = $controller->album($this->makeRequest(), ['name' => 'Greatest Hits']);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('Song One', $response->body);
    }

    /**
     * @group integration
     */
    public function test_artist_detail_renders_albums_and_tracks(): void
    {
        $this->skipWithoutSmarty();
        $controller = $this->controllerWithFixtures();

        $response = $controller->artist($this->makeRequest(), ['name' => 'The Band']);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('The Band', $response->body);
        $this->assertStringContainsString('Song One', $response->body);
    }

    /**
     * @group integration
     */
    public function test_tracks_renders_listing(): void
    {
        $this->skipWithoutSmarty();
        $controller = $this->controllerWithFixtures();

        $response = $controller->tracks($this->makeRequest(), []);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('Song One', $response->body);
    }

    /**
     * @group integration
     */
    public function test_player_renders(): void
    {
        $this->skipWithoutSmarty();
        $controller = $this->controllerWithFixtures();

        $response = $controller->player($this->makeRequest(), []);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('player-page', $response->body);
    }

    private function controllerWithFixtures(): MusicPageController
    {
        $track = [
            'id' => 't1',
            'name' => 'Song One',
            'path' => '/music/song-one.mp3',
            'metadata' => [
                'title' => 'Song One',
                'artist' => 'The Band',
                'album' => 'Greatest Hits',
                'track_number' => 1,
                'duration_secs' => 185,
            ],
        ];
        $album = [
            'name' => 'Greatest Hits',
            'artist' => 'The Band',
            'year' => 2001,
            'track_count' => 1,
            'tracks' => [$track],
        ];
        $artist = [
            'name' => 'The Band',
            'album_count' => 1,
            'track_count' => 1,
            'albums' => ['Greatest Hits'],
        ];

        $music = Mockery::mock(MusicLibraryManager::class);
        $music->shouldReceive('getAlbums')->with('lib1')->andReturn([$album]);
        $music->shouldReceive('getArtists')->with('lib1')->andReturn([$artist]);
        $music->shouldReceive('getTracks')->with('lib1', Mockery::any(), Mockery::any())->andReturn([$track]);

        $library = Mockery::mock(LibraryManager::class);
        $library->shouldReceive('getAllLibraries')->andReturn([['id' => 'lib1', 'type' => 'music']]);

        return new MusicPageController($music, $library, $this->realTemplateDir());
    }

    private function makeRequest(): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/music';
        $request->headers = [];
        $request->query = [];
        $request->body = [];
        $request->files = [];
        $request->remoteIp = '127.0.0.1';
        $request->remotePort = 0;
        $request->protocol = 'HTTP/1.1';
        $request->queryString = '';
        return $request;
    }

    private function realTemplateDir(): string
    {
        return dirname(__DIR__, 5) . '/public/templates';
    }

    private function noSmartyDir(): string
    {
        return sys_get_temp_dir() . '/phlix_music_no_smarty_' . uniqid('', true);
    }

    private function skipWithoutSmarty(): void
    {
        if (!class_exists('Smarty')) {
            $this->markTestSkipped('Smarty runtime class not available; skipping render test.');
        }
    }
}
