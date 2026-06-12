<?php

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\MetadataHttpClient;
use Phlix\Media\Metadata\TmdbProvider;
use Phlix\Common\Logger\LoggerFactory;

class TmdbProviderTest extends TestCase
{
    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    public function testCanCreateTmdbProvider(): void
    {
        // Use a mock or test API key
        $provider = new TmdbProvider('test-api-key');
        $this->assertInstanceOf(TmdbProvider::class, $provider);
    }

    public function testGetProvidersReturnsTmdb(): void
    {
        $provider = new TmdbProvider('test-api-key');
        $providers = $provider->getProviders();

        $this->assertContains('tmdb', $providers);
    }

    public function testHasApiKeyTrueWhenKeyPresent(): void
    {
        $this->assertTrue((new TmdbProvider('test-api-key'))->hasApiKey());
    }

    public function testHasApiKeyFalseWhenKeyEmpty(): void
    {
        $this->assertFalse((new TmdbProvider(''))->hasApiKey());
    }

    public function testSearchTvMapsResultsAndForwardsYear(): void
    {
        $http = $this->createMock(MetadataHttpClient::class);
        $http->expects($this->once())
            ->method('get')
            ->with('/search/tv', $this->callback(static fn(array $p): bool => ($p['first_air_date_year'] ?? null) === '2001'))
            ->willReturn(['results' => [
                ['id' => 1668, 'name' => '24', 'overview' => 'Jack Bauer', 'poster_path' => '/p.jpg', 'first_air_date' => '2001-11-06'],
            ]]);

        $results = (new TmdbProvider('k', $http))->searchTv('24', ['first_air_date_year' => 2001]);

        $this->assertCount(1, $results);
        $this->assertSame('1668', $results[0]['id']);
        $this->assertSame('24', $results[0]['name']);
        $this->assertSame('/p.jpg', $results[0]['poster_path']);
    }

    public function testGetTvDetailsFormatsSeriesWithGenresYearAndRating(): void
    {
        $http = $this->createMock(MetadataHttpClient::class);
        $http->method('get')->willReturn([
            'id' => 1668,
            'name' => '24',
            'overview' => 'Real-time thriller.',
            'first_air_date' => '2001-11-06',
            'poster_path' => '/poster.jpg',
            'backdrop_path' => '/back.jpg',
            'number_of_seasons' => 9,
            'genres' => [['name' => 'Drama'], ['name' => 'Action & Adventure']],
            'external_ids' => ['imdb_id' => 'tt0285331'],
            'content_ratings' => ['results' => [
                ['iso_3166_1' => 'GB', 'rating' => '15'],
                ['iso_3166_1' => 'US', 'rating' => 'TV-14'],
            ]],
        ]);

        $details = (new TmdbProvider('k', $http))->getTvDetails('1668');

        $this->assertSame('24', $details['name']);
        $this->assertSame(2001, $details['year']);
        $this->assertSame(['Drama', 'Action & Adventure'], $details['genres']);
        $this->assertSame('/poster.jpg', $details['poster_path']);
        $this->assertSame('tt0285331', $details['imdb_id']);
        $this->assertSame('TV-14', $details['official_rating']);
        $this->assertSame('1668', $details['tmdb_id']);
    }

    public function testGetTvSeasonMapsEpisodesAndPoster(): void
    {
        $http = $this->createMock(MetadataHttpClient::class);
        $http->method('get')->willReturn([
            'poster_path' => '/s1.jpg',
            'overview' => 'Season one.',
            'episodes' => [
                ['episode_number' => 1, 'name' => '12:00 A.M.', 'overview' => 'O1', 'still_path' => '/e1.jpg', 'air_date' => '2001-11-06', 'runtime' => 44],
                ['episode_number' => 2, 'name' => '1:00 A.M.', 'overview' => 'O2', 'still_path' => null, 'air_date' => '2001-11-13', 'runtime' => 43],
            ],
        ]);

        $season = (new TmdbProvider('k', $http))->getTvSeason('1668', 1);

        $this->assertSame('/s1.jpg', $season['poster_path']);
        $this->assertCount(2, $season['episodes']);
        $this->assertSame('12:00 A.M.', $season['episodes'][0]['name']);
        $this->assertSame(44, $season['episodes'][0]['runtime']);
    }

    public function testGetTvDetailsReturnsEmptyOnNullResponse(): void
    {
        $http = $this->createMock(MetadataHttpClient::class);
        $http->method('get')->willReturn(null);

        $this->assertSame([], (new TmdbProvider('k', $http))->getTvDetails('1668'));
    }
}
