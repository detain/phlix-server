<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Metadata\Provider;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Metadata\MetadataHttpClient;
use Phlex\Media\Metadata\Provider\AudioDbProvider;
use Phlex\Common\Logger\LoggerFactory;

class AudioDbProviderTest extends TestCase
{
    private MetadataHttpClient $httpClient;

    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../../config/logger.php');
        $this->httpClient = $this->createMock(MetadataHttpClient::class);
    }

    public function test_supports_music_types(): void
    {
        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');

        $this->assertTrue($provider->supports('artist'));
        $this->assertTrue($provider->supports('album'));
        $this->assertTrue($provider->supports('track'));
        $this->assertFalse($provider->supports('movie'));
        $this->assertFalse($provider->supports('series'));
    }

    public function test_search_returns_array(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturn([
                'artists' => [
                    [
                        'idArtist' => '123456',
                        'strArtist' => 'Test Artist',
                        'strArtistThumb' => 'https://example.com/artist.jpg',
                    ],
                ],
            ]);

        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');
        $results = $provider->search('test query');

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertEquals('123456', $results[0]['id']);
        $this->assertEquals('Test Artist', $results[0]['title']);
    }

    public function test_search_returns_empty_without_api_key(): void
    {
        $provider = new AudioDbProvider($this->httpClient, '');
        $results = $provider->search('test query');

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function test_get_artist_returns_array(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturn([
                'artists' => [
                    [
                        'idArtist' => '123456',
                        'strArtist' => 'Test Artist',
                        'strCountry' => 'US',
                        'strGenre' => 'Rock',
                        'strBiographyEN' => 'Test biography',
                        'strArtistThumb' => 'https://example.com/artist.jpg',
                        'strArtistFanart' => 'https://example.com/fanart.jpg',
                    ],
                ],
            ]);

        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');
        $artist = $provider->getArtist('123456');

        $this->assertIsArray($artist);
        $this->assertEquals('123456', $artist['id']);
        $this->assertEquals('Test Artist', $artist['name']);
        $this->assertEquals('US', $artist['country']);
        $this->assertEquals('Rock', $artist['genre']);
        $this->assertEquals('Test biography', $artist['biography']);
    }

    public function test_get_album_returns_array(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                [
                    'album' => [
                        [
                            'idAlbum' => 'album-123',
                            'strAlbum' => 'Test Album',
                            'idArtist' => 'artist-123',
                            'strArtist' => 'Test Artist',
                            'intYearReleased' => '2020',
                            'strGenre' => 'Rock',
                            'strAlbumThumb' => 'https://example.com/album.jpg',
                        ],
                    ],
                ],
                [
                    'track' => [
                        ['idTrack' => 'track-1', 'strTrack' => 'Track 1', 'intDuration' => 180000, 'intTrackNumber' => 1],
                        ['idTrack' => 'track-2', 'strTrack' => 'Track 2', 'intDuration' => 240000, 'intTrackNumber' => 2],
                    ],
                ]
            );

        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');
        $album = $provider->getAlbum('album-123');

        $this->assertIsArray($album);
        $this->assertEquals('album-123', $album['id']);
        $this->assertEquals('Test Album', $album['title']);
        $this->assertEquals('artist-123', $album['artist_id']);
        $this->assertEquals(2020, $album['year']);
        $this->assertCount(2, $album['tracks']);
        $this->assertEquals('Track 1', $album['tracks'][0]['title']);
    }

    public function test_rate_limit_applied(): void
    {
        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');

        // Use reflection to check lastRequestTime
        $reflection = new \ReflectionClass($provider);
        $property = $reflection->getProperty('lastRequestTime');
        $property->setAccessible(true);

        // First call should set lastRequestTime
        $startTime = microtime(true);
        $provider->search('test');

        // After search, lastRequestTime should be set
        $this->assertGreaterThanOrEqual($startTime, $property->getValue($provider));
    }

    public function test_get_source_name(): void
    {
        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');
        $this->assertEquals('audiodb', $provider->getSourceName());
    }

    public function test_get_providers(): void
    {
        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');
        $providers = $provider->getProviders();

        $this->assertContains('audiodb', $providers);
    }

    public function test_get_track_returns_array(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturn([
                'track' => [
                    [
                        'idTrack' => 'track-123',
                        'strTrack' => 'Test Track',
                        'intDuration' => 200000,
                        'strArtist' => 'Test Artist',
                        'strAlbum' => 'Test Album',
                        'intTrackNumber' => 5,
                    ],
                ],
            ]);

        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');
        $track = $provider->getTrack('track-123');

        $this->assertIsArray($track);
        $this->assertEquals('track-123', $track['id']);
        $this->assertEquals('Test Track', $track['title']);
        $this->assertEquals(200000, $track['duration']);
        $this->assertEquals('Test Artist', $track['artist_name']);
    }

    public function test_search_empty_without_api_key(): void
    {
        $provider = new AudioDbProvider($this->httpClient, '');

        $result = $provider->search('test');
        $this->assertEmpty($result);

        $result = $provider->getArtist('123');
        $this->assertNull($result);

        $result = $provider->getAlbum('123');
        $this->assertNull($result);

        $result = $provider->getTrack('123');
        $this->assertNull($result);
    }

    public function test_get_details_delegates_to_get_artist(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturn([
                'artists' => [
                    [
                        'idArtist' => '123456',
                        'strArtist' => 'Test Artist',
                    ],
                ],
            ]);

        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');
        $details = $provider->getDetails('123456', ['entity' => 'artist']);

        $this->assertIsArray($details);
        $this->assertEquals('Test Artist', $details['name']);
    }

    public function test_get_details_delegates_to_get_album(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                [
                    'album' => [
                        [
                            'idAlbum' => 'album-123',
                            'strAlbum' => 'Test Album',
                            'idArtist' => 'artist-123',
                            'strArtist' => 'Test Artist',
                        ],
                    ],
                ],
                ['track' => []]
            );

        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');
        $details = $provider->getDetails('album-123', ['entity' => 'album']);

        $this->assertIsArray($details);
        $this->assertEquals('Test Album', $details['title']);
    }

    public function test_get_details_delegates_to_get_track(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturn([
                'track' => [
                    [
                        'idTrack' => 'track-123',
                        'strTrack' => 'Test Track',
                        'intDuration' => 200000,
                        'strArtist' => 'Test Artist',
                    ],
                ],
            ]);

        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');
        $details = $provider->getDetails('track-123', ['entity' => 'track']);

        $this->assertIsArray($details);
        $this->assertEquals('Test Track', $details['title']);
    }

    public function test_get_images_returns_empty_arrays(): void
    {
        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');
        $images = $provider->getImages('123456');

        $this->assertIsArray($images);
        $this->assertArrayHasKey('posters', $images);
        $this->assertArrayHasKey('backdrops', $images);
        $this->assertArrayHasKey('logos', $images);
        $this->assertEmpty($images['posters']);
        $this->assertEmpty($images['backdrops']);
        $this->assertEmpty($images['logos']);
    }

    public function test_get_artist_returns_null_on_empty_response(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturn([]);

        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');
        $artist = $provider->getArtist('123456');

        $this->assertNull($artist);
    }

    public function test_get_album_returns_null_on_empty_response(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturn([]);

        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');
        $album = $provider->getAlbum('album-123');

        $this->assertNull($album);
    }

    public function test_get_track_returns_null_on_empty_response(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturn([]);

        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');
        $track = $provider->getTrack('track-123');

        $this->assertNull($track);
    }

    public function test_search_throws_and_returns_empty_on_exception(): void
    {
        $this->httpClient
            ->method('get')
            ->willThrowException(new \RuntimeException('Network error'));

        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');
        $results = $provider->search('test query');

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function test_get_artist_tracks_empty_when_no_tracks(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                [
                    'album' => [
                        [
                            'idAlbum' => 'album-123',
                            'strAlbum' => 'Test Album',
                            'idArtist' => 'artist-123',
                            'strArtist' => 'Test Artist',
                        ],
                    ],
                ],
                ['track' => []]
            );

        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');
        $album = $provider->getAlbum('album-123');

        $this->assertIsArray($album);
        $this->assertEmpty($album['tracks']);
    }

    public function test_get_album_with_non_numeric_year(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                [
                    'album' => [
                        [
                            'idAlbum' => 'album-123',
                            'strAlbum' => 'Test Album',
                            'idArtist' => 'artist-123',
                            'strArtist' => 'Test Artist',
                            'intYearReleased' => 'not-a-year',
                        ],
                    ],
                ],
                ['track' => []]
            );

        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');
        $album = $provider->getAlbum('album-123');

        $this->assertIsArray($album);
        $this->assertNull($album['year']);
    }

    public function test_get_album_tracks_with_non_numeric_duration(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                [
                    'album' => [
                        [
                            'idAlbum' => 'album-123',
                            'strAlbum' => 'Test Album',
                            'idArtist' => 'artist-123',
                            'strArtist' => 'Test Artist',
                        ],
                    ],
                ],
                [
                    'track' => [
                        ['idTrack' => 'track-1', 'strTrack' => 'Track 1', 'intDuration' => 'bad', 'intTrackNumber' => 1],
                    ],
                ]
            );

        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');
        $album = $provider->getAlbum('album-123');

        $this->assertIsArray($album);
        $this->assertCount(1, $album['tracks']);
        $this->assertEquals(0, $album['tracks'][0]['duration']);
    }

    public function test_get_album_tracks_throws_exception(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                [
                    'album' => [
                        [
                            'idAlbum' => 'album-123',
                            'strAlbum' => 'Test Album',
                            'idArtist' => 'artist-123',
                            'strArtist' => 'Test Artist',
                        ],
                    ],
                ],
                $this->throwException(new \RuntimeException('Track fetch failed'))
            );

        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');
        $album = $provider->getAlbum('album-123');

        $this->assertIsArray($album);
        $this->assertEmpty($album['tracks']);
    }

    public function test_get_track_throws_and_returns_null(): void
    {
        $this->httpClient
            ->method('get')
            ->willThrowException(new \RuntimeException('Network error'));

        $provider = new AudioDbProvider($this->httpClient, 'test-api-key');
        $track = $provider->getTrack('track-123');

        $this->assertNull($track);
    }
}
