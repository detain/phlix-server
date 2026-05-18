<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Metadata\Provider;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Metadata\MetadataHttpClient;
use Phlex\Media\Metadata\Provider\MusicBrainzProvider;
use Phlex\Common\Logger\LoggerFactory;

class MusicBrainzProviderTest extends TestCase
{
    private MetadataHttpClient $httpClient;

    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../../config/logger.php');
        $this->httpClient = $this->createMock(MetadataHttpClient::class);
    }

    public function test_supports_music_types(): void
    {
        $provider = new MusicBrainzProvider($this->httpClient);

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
                'entities' => [
                    [
                        'id' => '123456',
                        'name' => 'Test Artist',
                        'type' => 'Person',
                        'life-span' => ['begin' => '2000'],
                        'score' => 100,
                    ],
                ],
            ]);

        $provider = new MusicBrainzProvider($this->httpClient, 'Phlex/1.0 (test@example.com)');
        $results = $provider->search('test query', ['entity' => 'artist']);

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertEquals('123456', $results[0]['id']);
        $this->assertEquals('Test Artist', $results[0]['title']);
    }

    public function test_search_empty_on_error(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturn(null);

        $provider = new MusicBrainzProvider($this->httpClient, 'Phlex/1.0 (test@example.com)');
        $results = $provider->search('test query');

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function test_get_artist_returns_array(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturn([
                'id' => '123456',
                'name' => 'Test Artist',
                'sort-name' => 'Artist, Test',
                'country' => 'US',
                'disambiguation' => 'Test disambiguation',
                'tags' => [
                    ['name' => 'rock'],
                    ['name' => 'pop'],
                ],
            ]);

        $provider = new MusicBrainzProvider($this->httpClient);
        $artist = $provider->getArtist('123456');

        $this->assertIsArray($artist);
        $this->assertEquals('123456', $artist['mbid']);
        $this->assertEquals('Test Artist', $artist['name']);
        $this->assertEquals('Artist, Test', $artist['sort_name']);
        $this->assertEquals('US', $artist['country']);
        $this->assertContains('rock', $artist['tags']);
    }

    public function test_get_album_returns_array_with_tracks(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturn([
                'id' => 'album-123',
                'title' => 'Test Album',
                'artist-credit' => [
                    ['artist' => ['id' => 'artist-123', 'name' => 'Test Artist']],
                ],
                'date' => '2020',
                'media' => [
                    [
                        'tracks' => [
                            ['id' => 'track-1', 'title' => 'Track 1', 'length' => 180000, 'position' => 1],
                            ['id' => 'track-2', 'title' => 'Track 2', 'length' => 240000, 'position' => 2],
                        ],
                    ],
                ],
            ]);

        $provider = new MusicBrainzProvider($this->httpClient);
        $album = $provider->getAlbum('album-123');

        $this->assertIsArray($album);
        $this->assertEquals('album-123', $album['mbid']);
        $this->assertEquals('Test Album', $album['title']);
        $this->assertEquals('artist-123', $album['artist_mbid']);
        $this->assertEquals(2020, $album['year']);
        $this->assertCount(2, $album['tracks']);
        $this->assertEquals('Track 1', $album['tracks'][0]['title']);
    }

    public function test_get_track_returns_array(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturn([
                'id' => 'track-123',
                'title' => 'Test Track',
                'length' => 200000,
                'artist-credit' => [
                    ['artist' => ['id' => 'artist-123', 'name' => 'Test Artist']],
                ],
                'releases' => [
                    ['id' => 'album-123', 'title' => 'Test Album'],
                ],
            ]);

        $provider = new MusicBrainzProvider($this->httpClient);
        $track = $provider->getTrack('track-123');

        $this->assertIsArray($track);
        $this->assertEquals('track-123', $track['mbid']);
        $this->assertEquals('Test Track', $track['title']);
        $this->assertEquals(200000, $track['duration']);
        $this->assertEquals('artist-123', $track['artist_mbid']);
    }

    public function test_rate_limit_backoff(): void
    {
        $provider = new MusicBrainzProvider($this->httpClient);

        // Use reflection to check lastRequestTime
        $reflection = new \ReflectionClass($provider);
        $property = $reflection->getProperty('lastRequestTime');
        $property->setAccessible(true);

        // First call should set lastRequestTime
        $startTime = microtime(true);
        $provider->search('test', ['entity' => 'artist']);

        // After search, lastRequestTime should be set
        $this->assertGreaterThanOrEqual($startTime, $property->getValue($provider));
    }

    public function test_mb_headers_includes_ua(): void
    {
        $provider = new MusicBrainzProvider($this->httpClient, 'Phlex/1.0 (test@example.com)');

        // Use reflection to access mbHeaders method
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('mbHeaders');
        $method->setAccessible(true);

        $headers = $method->invoke($provider, 'Phlex/1.0 (test@example.com)');

        $this->assertArrayHasKey('User-Agent', $headers);
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertEquals('Phlex/1.0 (test@example.com)', $headers['User-Agent']);
    }

    public function test_get_source_name(): void
    {
        $provider = new MusicBrainzProvider($this->httpClient);
        $this->assertEquals('musicbrainz', $provider->getSourceName());
    }

    public function test_get_providers(): void
    {
        $provider = new MusicBrainzProvider($this->httpClient);
        $providers = $provider->getProviders();

        $this->assertContains('musicbrainz', $providers);
    }

    public function test_get_details_delegates_to_get_artist(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturn([
                'id' => '123456',
                'name' => 'Test Artist',
            ]);

        $provider = new MusicBrainzProvider($this->httpClient);
        $details = $provider->getDetails('123456', ['entity' => 'artist']);

        $this->assertIsArray($details);
        $this->assertEquals('Test Artist', $details['name']);
    }

    public function test_get_details_delegates_to_get_album(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturn([
                'id' => 'album-123',
                'title' => 'Test Album',
                'artist-credit' => [
                    ['artist' => ['id' => 'artist-123', 'name' => 'Test Artist']],
                ],
                'date' => '2020',
            ]);

        $provider = new MusicBrainzProvider($this->httpClient);
        $details = $provider->getDetails('album-123', ['entity' => 'album']);

        $this->assertIsArray($details);
        $this->assertEquals('Test Album', $details['title']);
    }

    public function test_get_details_delegates_to_get_track(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturn([
                'id' => 'track-123',
                'title' => 'Test Track',
                'length' => 200000,
                'artist-credit' => [
                    ['artist' => ['id' => 'artist-123', 'name' => 'Test Artist']],
                ],
                'releases' => [
                    ['id' => 'album-123', 'title' => 'Test Album'],
                ],
            ]);

        $provider = new MusicBrainzProvider($this->httpClient);
        $details = $provider->getDetails('track-123', ['entity' => 'track']);

        $this->assertIsArray($details);
        $this->assertEquals('Test Track', $details['title']);
    }

    public function test_get_images_returns_empty_arrays(): void
    {
        $provider = new MusicBrainzProvider($this->httpClient);
        $images = $provider->getImages('123456');

        $this->assertIsArray($images);
        $this->assertArrayHasKey('posters', $images);
        $this->assertArrayHasKey('backdrops', $images);
        $this->assertArrayHasKey('logos', $images);
        $this->assertEmpty($images['posters']);
        $this->assertEmpty($images['backdrops']);
        $this->assertEmpty($images['logos']);
    }

    public function test_get_artist_returns_null_on_error(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturn(null);

        $provider = new MusicBrainzProvider($this->httpClient);
        $artist = $provider->getArtist('123456');

        $this->assertNull($artist);
    }

    public function test_get_album_returns_null_on_error(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturn(null);

        $provider = new MusicBrainzProvider($this->httpClient);
        $album = $provider->getAlbum('album-123');

        $this->assertNull($album);
    }

    public function test_get_track_returns_null_on_error(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturn(null);

        $provider = new MusicBrainzProvider($this->httpClient);
        $track = $provider->getTrack('track-123');

        $this->assertNull($track);
    }

    public function test_search_throws_and_returns_empty_on_exception(): void
    {
        $this->httpClient
            ->method('get')
            ->willThrowException(new \RuntimeException('Network error'));

        $provider = new MusicBrainzProvider($this->httpClient, 'Phlex/1.0 (test@example.com)');
        $results = $provider->search('test query', ['entity' => 'artist']);

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }
}
