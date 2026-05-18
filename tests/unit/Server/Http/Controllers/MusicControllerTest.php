<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlex\Server\Http\Controllers\MusicController;
use Phlex\Server\Http\Request;
use Phlex\Media\Library\MusicLibraryManager;
use Phlex\Media\Library\LibraryManager;
use Phlex\Session\SessionManager;

/**
 * Unit tests for MusicController.
 *
 * @covers \Phlex\Server\Http\Controllers\MusicController
 */
class MusicControllerTest extends TestCase
{
    private MusicController $controller;
    private MusicLibraryManager $musicManager;
    private LibraryManager $libraryManager;
    private SessionManager $sessionManager;

    protected function setUp(): void
    {
        $this->musicManager = $this->createMock(MusicLibraryManager::class);
        $this->libraryManager = $this->createMock(LibraryManager::class);
        $this->sessionManager = $this->createMock(SessionManager::class);

        $this->controller = new MusicController(
            $this->musicManager,
            $this->libraryManager,
            $this->sessionManager
        );
    }

    /**
     * @test
     */
    public function testListArtistsReturnsJson(): void
    {
        $request = new Request();

        $this->libraryManager->method('getAllLibraries')->willReturn([
            ['id' => 'lib-1', 'name' => 'Music', 'type' => 'music'],
        ]);

        $this->musicManager->method('getArtists')->willReturn([
            [
                'name' => 'Test Artist',
                'album_count' => 2,
                'track_count' => 10,
                'albums' => ['Album 1', 'Album 2'],
            ],
        ]);

        $response = $this->controller->listArtists($request, []);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('artists', $body);
        $this->assertCount(1, $body['artists']);
        $this->assertEquals('Test Artist', $body['artists'][0]['name']);
    }

    /**
     * @test
     */
    public function testGetArtistReturns404WhenNotFound(): void
    {
        $request = new Request();

        $this->libraryManager->method('getAllLibraries')->willReturn([
            ['id' => 'lib-1', 'name' => 'Music', 'type' => 'music'],
        ]);

        $this->musicManager->method('getArtists')->willReturn([]);

        $response = $this->controller->getArtist($request, ['mbid' => 'NonExistent']);

        $this->assertEquals(404, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertEquals('Artist not found', $body['error']);
    }

    /**
     * @test
     */
    public function testGetArtistReturnsJsonWhenFound(): void
    {
        $request = new Request();

        $this->libraryManager->method('getAllLibraries')->willReturn([
            ['id' => 'lib-1', 'name' => 'Music', 'type' => 'music'],
        ]);

        $this->musicManager->method('getArtists')->willReturn([
            [
                'name' => 'Found Artist',
                'album_count' => 3,
                'track_count' => 25,
                'albums' => ['Album A', 'Album B', 'Album C'],
            ],
        ]);

        $response = $this->controller->getArtist($request, ['mbid' => 'Found Artist']);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('artist', $body);
        $this->assertEquals('Found Artist', $body['artist']['name']);
    }

    /**
     * @test
     */
    public function testListAlbumsReturnsJson(): void
    {
        $request = new Request();

        $this->libraryManager->method('getAllLibraries')->willReturn([
            ['id' => 'lib-1', 'name' => 'Music', 'type' => 'music'],
        ]);

        $this->musicManager->method('getAlbums')->willReturn([
            [
                'name' => 'Test Album',
                'artist' => 'Test Artist',
                'year' => 2021,
                'track_count' => 12,
                'tracks' => [],
            ],
        ]);

        $response = $this->controller->listAlbums($request, []);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('albums', $body);
        $this->assertCount(1, $body['albums']);
        $this->assertEquals('Test Album', $body['albums'][0]['name']);
    }

    /**
     * @test
     */
    public function testGetAlbumReturnsJsonWithTracks(): void
    {
        $request = new Request();

        $this->libraryManager->method('getAllLibraries')->willReturn([
            ['id' => 'lib-1', 'name' => 'Music', 'type' => 'music'],
        ]);

        $albumTracks = [
            ['id' => 'track-1', 'name' => 'Track 1', 'path' => '/music/track1.mp3', 'metadata' => []],
            ['id' => 'track-2', 'name' => 'Track 2', 'path' => '/music/track2.mp3', 'metadata' => []],
        ];

        $this->musicManager->method('getAlbums')->willReturn([
            [
                'name' => 'My Album',
                'artist' => 'My Artist',
                'year' => 2022,
                'track_count' => 2,
                'tracks' => $albumTracks,
            ],
        ]);

        $response = $this->controller->getAlbum($request, ['mbid' => 'My Album']);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('album', $body);
        $this->assertEquals('My Album', $body['album']['name']);
        $this->assertCount(2, $body['album']['tracks']);
    }

    /**
     * @test
     */
    public function testListTracksReturnsJson(): void
    {
        $request = new Request();

        $this->libraryManager->method('getAllLibraries')->willReturn([
            ['id' => 'lib-1', 'name' => 'Music', 'type' => 'music'],
        ]);

        $this->musicManager->method('getTracks')->willReturn([
            [
                'id' => 'track-1',
                'name' => 'First Track',
                'path' => '/music/track1.mp3',
                'metadata' => [
                    'title' => 'First Track',
                    'artist' => 'Test Artist',
                    'album' => 'Test Album',
                    'duration_secs' => 180,
                    'track_number' => 1,
                ],
            ],
        ]);

        $response = $this->controller->listTracks($request, []);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('tracks', $body);
        $this->assertArrayHasKey('limit', $body);
        $this->assertArrayHasKey('offset', $body);
    }

    /**
     * @test
     */
    public function testListTracksRespectsPaginationParams(): void
    {
        $request = new Request();
        $request->query['limit'] = '50';
        $request->query['offset'] = '100';

        $this->libraryManager->method('getAllLibraries')->willReturn([
            ['id' => 'lib-1', 'name' => 'Music', 'type' => 'music', 'item_count' => 200],
        ]);

        $this->musicManager->expects($this->once())
            ->method('getTracks')
            ->with('lib-1', 50, 100);

        $response = $this->controller->listTracks($request, []);

        $body = json_decode($response->body, true);
        $this->assertEquals(50, $body['limit']);
        $this->assertEquals(100, $body['offset']);
    }

    /**
     * @test
     */
    public function testNowPlayingReturnsCurrentSession(): void
    {
        $request = new Request();
        $request->userId = 'user-123';

        $this->sessionManager->method('getUserSessions')->willReturn([
            [
                'id' => 'session-456',
                'user_id' => 'user-123',
                'current_media_id' => 'track-1',
                'position_ticks' => 450000000,
                'playback_state' => 'playing',
            ],
        ]);

        // Mock track lookup
        $this->libraryManager->method('getAllLibraries')->willReturn([
            ['id' => 'lib-1', 'name' => 'Music', 'type' => 'music'],
        ]);

        $this->musicManager->method('getTracks')->willReturn([
            [
                'id' => 'track-1',
                'name' => 'Playing Now',
                'path' => '/music/playing.mp3',
                'metadata' => [
                    'title' => 'Playing Now',
                    'artist' => 'Current Artist',
                    'duration_secs' => 200,
                ],
            ],
        ]);

        $response = $this->controller->nowPlaying($request, []);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('now_playing', $body);
    }

    /**
     * @test
     */
    public function testNowPlayingReturnsNullWhenNoUser(): void
    {
        $request = new Request();
        // No userId set

        $response = $this->controller->nowPlaying($request, []);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertNull($body['now_playing']);
    }

    /**
     * @test
     */
    public function testNowPlayingReturnsNullWhenNoSession(): void
    {
        $request = new Request();
        $request->userId = 'user-no-session';

        $this->sessionManager->method('getUserSessions')->willReturn([]);

        $response = $this->controller->nowPlaying($request, []);

        $body = json_decode($response->body, true);
        $this->assertNull($body['now_playing']);
    }

    /**
     * @test
     */
    public function testGetArtistReturns400WhenMbidMissing(): void
    {
        $request = new Request();

        $response = $this->controller->getArtist($request, []);

        $this->assertEquals(400, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertEquals('Artist name is required', $body['error']);
    }

    /**
     * @test
     */
    public function testGetAlbumReturns400WhenMbidMissing(): void
    {
        $request = new Request();

        $response = $this->controller->getAlbum($request, []);

        $this->assertEquals(400, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertEquals('Album name is required', $body['error']);
    }

    /**
     * @test
     */
    public function testGetTrackReturns404WhenNotFound(): void
    {
        $request = new Request();

        $this->libraryManager->method('getAllLibraries')->willReturn([
            ['id' => 'lib-1', 'name' => 'Music', 'type' => 'music'],
        ]);

        $this->musicManager->method('getTracks')->willReturn([]);

        $response = $this->controller->getTrack($request, ['id' => 'non-existent-id']);

        $this->assertEquals(404, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertEquals('Track not found', $body['error']);
    }

    /**
     * @test
     */
    public function testGetTrackReturns400WhenIdMissing(): void
    {
        $request = new Request();

        $response = $this->controller->getTrack($request, []);

        $this->assertEquals(400, $response->statusCode);
    }
}
