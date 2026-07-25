<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\SignedUrl;
use Phlix\Media\Music\MusicArtist;
use Phlix\Media\Music\MusicArtistWithAlbums;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Server\Http\Controllers\MusicController;
use Phlix\Server\Http\Request;
use Phlix\Session\SessionManager;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for MusicController.
 *
 * S99: the controller reads the normalized `music_*` tables through
 * {@see MusicLibraryService}, so these tests mock that service. They pin the
 * RESPONSE SHAPE every client depends on (name-keyed artist/album identity, the
 * `media_items` UUID as the track id, the signed `stream_url`); the proof that the
 * underlying SQL returns real artist/album/year values against real MySQL — which
 * a mocked connection structurally cannot show — lives in
 * {@see \Phlix\Tests\Integration\Media\MusicApiReadPathIntegrationTest}.
 *
 * @covers \Phlix\Server\Http\Controllers\MusicController
 */
class MusicControllerTest extends TestCase
{
    private MusicController $controller;
    /** @var MusicLibraryService&MockObject */
    private MusicLibraryService $musicLibrary;
    /** @var SessionManager&MockObject */
    private SessionManager $sessionManager;

    protected function setUp(): void
    {
        $this->musicLibrary = $this->createMock(MusicLibraryService::class);
        $this->sessionManager = $this->createMock(SessionManager::class);

        $this->controller = new MusicController(
            $this->musicLibrary,
            $this->sessionManager
        );
    }

    /**
     * One joined track row exactly as {@see MusicLibraryService} returns it
     * (`t.*` plus the `artist_name` / `album_name` / `album_year` / `path`
     * aliases).
     *
     * @return array<string, mixed>
     */
    private function trackRow(string $mediaItemId = 'media-uuid-1', int $trackNumber = 1): array
    {
        return [
            'id' => 4242,
            'media_item_id' => $mediaItemId,
            'album_id' => 7,
            'artist_id' => 3,
            'title' => 'Real Track Title',
            'track_number' => $trackNumber,
            'disc_number' => 1,
            'duration_secs' => 245,
            'artist_name' => 'Real Artist',
            'album_name' => 'Real Album',
            'album_year' => 2020,
            'path' => '/music/real.flac',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function albumRow(int $id = 7): array
    {
        return [
            'id' => $id,
            'artist_id' => 3,
            'media_item_id' => 'album-uuid',
            'title' => 'Real Album',
            'sort_title' => 'Real Album',
            'year' => 2020,
            'total_tracks' => 12,
            'total_discs' => 1,
            'album_art_url' => 'https://art.example/cover.jpg',
            'artist_name' => 'Real Artist',
            'track_count' => 12,
        ];
    }

    /**
     * @test
     */
    public function testListArtistsReturnsJson(): void
    {
        $request = new Request();

        $this->musicLibrary->method('getAllArtists')->willReturn([
            new MusicArtistWithAlbums(
                artist: new MusicArtist(id: 3, name: 'Real Artist', imageUrl: 'https://img/a.jpg'),
                albumCount: 2,
                trackCount: 10,
            ),
        ]);
        $this->musicLibrary->method('getAlbumTitlesByArtistIds')->willReturn([
            3 => ['Album 1', 'Album 2'],
        ]);
        $this->musicLibrary->method('getArtistsCount')->willReturn(2197);

        $response = $this->controller->listArtists($request, []);

        $this->assertEquals(200, $response->statusCode);

        /** @var array{artists: list<array<string, mixed>>, total: int} $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('artists', $body);
        $this->assertCount(1, $body['artists']);
        $this->assertSame('Real Artist', $body['artists'][0]['name']);
        $this->assertSame('https://img/a.jpg', $body['artists'][0]['image_url']);
        $this->assertSame(2, $body['artists'][0]['album_count']);
        $this->assertSame(10, $body['artists'][0]['track_count']);
        $this->assertSame(['Album 1', 'Album 2'], $body['artists'][0]['albums']);
        // The permanently-zero `total` is now counted from music_artists.
        $this->assertSame(2197, $body['total']);
    }

    /**
     * The artists listing must resolve album titles for the WHOLE page in one
     * batched call — never one query per artist (N+1 in a resident worker).
     *
     * @test
     */
    public function testListArtistsBatchesAlbumTitlesForThePage(): void
    {
        $this->musicLibrary->method('getAllArtists')->willReturn([
            new MusicArtistWithAlbums(new MusicArtist(id: 1, name: 'A'), 1, 1),
            new MusicArtistWithAlbums(new MusicArtist(id: 2, name: 'B'), 1, 1),
            new MusicArtistWithAlbums(new MusicArtist(id: 3, name: 'C'), 1, 1),
        ]);
        $this->musicLibrary->expects($this->once())
            ->method('getAlbumTitlesByArtistIds')
            ->with([1, 2, 3])
            ->willReturn([]);

        $response = $this->controller->listArtists(new Request(), []);

        $this->assertEquals(200, $response->statusCode);
    }

    /**
     * @test
     */
    public function testListArtistsClampsAndEchoesPagination(): void
    {
        $request = new Request();
        $request->query['limit'] = '5000';
        $request->query['offset'] = '25';

        $this->musicLibrary->expects($this->once())
            ->method('getAllArtists')
            ->with(100, 25)
            ->willReturn([]);

        $response = $this->controller->listArtists($request, []);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame(100, $body['limit']);
        $this->assertSame(25, $body['offset']);
    }

    /**
     * @test
     */
    public function testGetArtistReturns404WhenNotFound(): void
    {
        $this->musicLibrary->method('findArtistByName')->willReturn(null);

        $response = $this->controller->getArtist(new Request(), ['mbid' => 'NonExistent']);

        $this->assertEquals(404, $response->statusCode);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertEquals('Artist not found', $body['error']);
    }

    /**
     * `/artists/{mbid}` is NAME-keyed — the route param is the artist's display
     * name, which is what `phlix-ui` puts in the URL.
     *
     * @test
     */
    public function testGetArtistLooksTheArtistUpByName(): void
    {
        $this->musicLibrary->expects($this->once())
            ->method('findArtistByName')
            ->with('Found Artist')
            ->willReturn([
                'id' => 9,
                'name' => 'Found Artist',
                'image_url' => null,
                'album_count' => 3,
                'track_count' => 25,
            ]);
        $this->musicLibrary->method('getAlbumTitlesByArtistIds')->willReturn([
            9 => ['Album A', 'Album B', 'Album C'],
        ]);

        $response = $this->controller->getArtist(new Request(), ['mbid' => 'Found Artist']);

        $this->assertEquals(200, $response->statusCode);

        /** @var array{artist: array<string, mixed>} $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('artist', $body);
        $this->assertEquals('Found Artist', $body['artist']['name']);
        $this->assertSame(3, $body['artist']['album_count']);
        $this->assertSame(25, $body['artist']['track_count']);
        $this->assertSame(['Album A', 'Album B', 'Album C'], $body['artist']['albums']);
    }

    /**
     * @test
     */
    public function testListAlbumsReturnsJson(): void
    {
        $this->musicLibrary->method('getAllAlbums')->willReturn([$this->albumRow()]);
        $this->musicLibrary->method('getTracksByAlbumIds')->willReturn([7 => [$this->trackRow()]]);
        $this->musicLibrary->method('getAlbumsCount')->willReturn(5091);

        $response = $this->controller->listAlbums(new Request(), []);

        $this->assertEquals(200, $response->statusCode);

        /** @var array{albums: list<array<string, mixed>>, total: int} $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('albums', $body);
        $this->assertCount(1, $body['albums']);
        // `name` (not `title`) is the contract key — it doubles as the drill-down id.
        $this->assertSame('Real Album', $body['albums'][0]['name']);
        $this->assertSame('Real Artist', $body['albums'][0]['artist']);
        $this->assertSame(2020, $body['albums'][0]['year']);
        $this->assertSame(12, $body['albums'][0]['track_count']);
        $this->assertSame('https://art.example/cover.jpg', $body['albums'][0]['album_art_url']);
        $this->assertCount(1, $body['albums'][0]['tracks']);
        $this->assertSame(5091, $body['total']);
    }

    /**
     * @test
     */
    public function testListAlbumsBatchesTrackFetchForThePage(): void
    {
        $this->musicLibrary->method('getAllAlbums')->willReturn([
            $this->albumRow(1),
            $this->albumRow(2),
        ]);
        $this->musicLibrary->expects($this->once())
            ->method('getTracksByAlbumIds')
            ->with([1, 2])
            ->willReturn([]);

        $this->assertEquals(200, $this->controller->listAlbums(new Request(), [])->statusCode);
    }

    /**
     * @test
     */
    public function testGetAlbumReturnsJsonWithTracks(): void
    {
        $this->musicLibrary->expects($this->once())
            ->method('findAlbumByTitle')
            ->with('My Album')
            ->willReturn($this->albumRow(7));
        $this->musicLibrary->method('getTracksByAlbumIds')->willReturn([
            7 => [$this->trackRow('track-1', 1), $this->trackRow('track-2', 2)],
        ]);

        $response = $this->controller->getAlbum(new Request(), ['mbid' => 'My Album']);

        $this->assertEquals(200, $response->statusCode);

        /** @var array{album: array{name: mixed, tracks: array<mixed>}} $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('album', $body);
        $this->assertEquals('Real Album', $body['album']['name']);
        $this->assertCount(2, $body['album']['tracks']);
        // Embedded album tracks carry the media-item UUID as their id.
        $this->assertSame('track-1', $body['album']['tracks'][0]['id']);
        $this->assertSame('Real Track Title', $body['album']['tracks'][0]['name']);
    }

    /**
     * @test
     */
    public function testGetAlbumReturns404WhenNotFound(): void
    {
        $this->musicLibrary->method('findAlbumByTitle')->willReturn(null);

        $response = $this->controller->getAlbum(new Request(), ['mbid' => 'Nope']);

        $this->assertEquals(404, $response->statusCode);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertEquals('Album not found', $body['error']);
    }

    /**
     * @test
     */
    public function testListTracksReturnsJson(): void
    {
        $this->musicLibrary->method('getAllTracks')->willReturn([$this->trackRow()]);
        $this->musicLibrary->method('getTracksCount')->willReturn(29245);

        $response = $this->controller->listTracks(new Request(), []);

        $this->assertEquals(200, $response->statusCode);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('tracks', $body);
        $this->assertArrayHasKey('limit', $body);
        $this->assertArrayHasKey('offset', $body);
        $this->assertCount(1, $body['tracks']);

        // Every field the pre-S99 metadata_json read path served as its default.
        $track = $body['tracks'][0];
        $this->assertSame('media-uuid-1', $track['id']);
        $this->assertSame('Real Track Title', $track['name']);
        $this->assertSame('Real Artist', $track['artist']);
        $this->assertSame('Real Album', $track['album']);
        $this->assertSame(2020, $track['year']);
        $this->assertSame(245, $track['duration_secs']);
        $this->assertSame(1, $track['track_number']);
        $this->assertSame('/music/real.flac', $track['path']);
    }

    /**
     * `total` must be a real count. The pre-S99 handler summed
     * `libraries.item_count`, a column the schema does not have, so `?? 0` fired
     * unconditionally and `total` was 0 for every caller, forever.
     *
     * @test
     */
    public function testListTracksTotalIsCountedFromTheMusicTables(): void
    {
        $this->musicLibrary->method('getAllTracks')->willReturn([$this->trackRow()]);
        $this->musicLibrary->expects($this->once())->method('getTracksCount')->willReturn(29245);

        /** @var array<string, mixed> $body */
        $body = json_decode($this->controller->listTracks(new Request(), [])->body, true);

        $this->assertSame(29245, $body['total']);
        $this->assertNotSame(0, $body['total']);
    }

    /**
     * @test
     */
    public function testListTracksRespectsPaginationParams(): void
    {
        $request = new Request();
        $request->query['limit'] = '50';
        $request->query['offset'] = '100';

        $this->musicLibrary->expects($this->once())
            ->method('getAllTracks')
            ->with(50, 100)
            ->willReturn([$this->trackRow()]);

        $response = $this->controller->listTracks($request, []);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertEquals(50, $body['limit']);
        $this->assertEquals(100, $body['offset']);
        // Rows arrive already paged by SQL; the pre-S99 handler array_slice()d them
        // by $offset a SECOND time, so any non-zero offset returned an empty page.
        $this->assertCount(1, $body['tracks']);
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
                'current_media_id' => 'media-uuid-1',
                'position_ticks' => 450000000,
                'playback_state' => 'playing',
            ],
        ]);

        // `current_media_id` IS a media_items UUID → the keyed track lookup.
        $this->musicLibrary->expects($this->once())
            ->method('findTrackByMediaItemId')
            ->with('media-uuid-1')
            ->willReturn($this->trackRow());

        $response = $this->controller->nowPlaying($request, []);

        $this->assertEquals(200, $response->statusCode);

        /** @var array{now_playing: array<string, mixed>} $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('now_playing', $body);
        $this->assertIsArray($body['now_playing']['track']);
        $this->assertSame('Real Artist', $body['now_playing']['track']['artist']);
        $this->assertSame('session-456', $body['now_playing']['session_id']);
    }

    /**
     * @test
     */
    public function testNowPlayingReturnsNullWhenNoUser(): void
    {
        $response = $this->controller->nowPlaying(new Request(), []);

        $this->assertEquals(200, $response->statusCode);

        /** @var array<string, mixed> $body */
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

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertNull($body['now_playing']);
    }

    /**
     * @test
     */
    public function testGetArtistReturns400WhenMbidMissing(): void
    {
        $response = $this->controller->getArtist(new Request(), []);

        $this->assertEquals(400, $response->statusCode);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertEquals('Artist name is required', $body['error']);
    }

    /**
     * @test
     */
    public function testGetAlbumReturns400WhenMbidMissing(): void
    {
        $response = $this->controller->getAlbum(new Request(), []);

        $this->assertEquals(400, $response->statusCode);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertEquals('Album name is required', $body['error']);
    }

    /**
     * @test
     */
    public function testGetTrackReturns404WhenNotFound(): void
    {
        $this->musicLibrary->method('findTrackByMediaItemId')->willReturn(null);

        $response = $this->controller->getTrack(new Request(), ['id' => 'non-existent-id']);

        $this->assertEquals(404, $response->statusCode);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertEquals('Track not found', $body['error']);
    }

    /**
     * @test
     */
    public function testGetTrackReturns400WhenIdMissing(): void
    {
        $response = $this->controller->getTrack(new Request(), []);

        $this->assertEquals(400, $response->statusCode);
    }

    /**
     * The track lookup must be a KEYED lookup on the media-item UUID. The pre-S99
     * helper paged the first 1,000 rows of each library and compared ids in PHP,
     * so track 1,001+ was unplayable (404).
     *
     * @test
     */
    public function testGetTrackResolvesByMediaItemIdRatherThanScanningAPage(): void
    {
        $this->musicLibrary->expects($this->once())
            ->method('findTrackByMediaItemId')
            ->with('track-42')
            ->willReturn($this->trackRow('track-42'));
        $this->musicLibrary->expects($this->never())->method('getAllTracks');

        $response = $this->controller->getTrack(new Request(), ['id' => 'track-42']);

        $this->assertEquals(200, $response->statusCode);
    }

    /**
     * X8: getTrack (via formatTrack) must mint a signed stream_url pointing at
     * the generic Range-safe GET /media/{trackId}/stream endpoint, so the UI
     * can direct-play music tracks (mirrors the AudiobookController convention).
     * The signed path must carry the MEDIA-ITEM UUID, not music_tracks.id.
     *
     * @test
     */
    public function testGetTrackEmitsSignedStreamUrl(): void
    {
        SignedUrl::resetSharedForTesting();

        $this->musicLibrary->method('findTrackByMediaItemId')->willReturn($this->trackRow('track-42'));

        $response = $this->controller->getTrack(new Request(), ['id' => 'track-42']);

        $this->assertEquals(200, $response->statusCode);

        /** @var array{track: array<string, mixed>} $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('track', $body);
        $this->assertArrayHasKey('stream_url', $body['track']);

        $streamUrl = $body['track']['stream_url'];
        $this->assertIsString($streamUrl);

        // Path must target the generic media-stream endpoint for this track id.
        $parts = parse_url($streamUrl);
        $this->assertSame('/media/track-42/stream', $parts['path'] ?? null);

        // The exp/sig token must verify against SignedUrl::fromEnv().
        parse_str($parts['query'] ?? '', $query);
        $this->assertArrayHasKey('exp', $query);
        $this->assertArrayHasKey('sig', $query);
        $this->assertTrue(
            SignedUrl::fromEnv()->verify(
                (string) ($parts['path'] ?? ''),
                is_string($query['exp'] ?? null) ? $query['exp'] : null,
                is_string($query['sig'] ?? null) ? $query['sig'] : null,
            ),
            'Minted music stream_url signature must verify',
        );

        SignedUrl::resetSharedForTesting();
    }
}
