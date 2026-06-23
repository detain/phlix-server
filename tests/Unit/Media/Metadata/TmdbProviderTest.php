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

    public function testGetDetailsEmitsRichCastCrewAndCompaniesForMovie(): void
    {
        $http = $this->createMock(MetadataHttpClient::class);
        $http->method('get')->willReturn([
            'id' => 603,
            'title' => 'The Matrix',
            'release_date' => '1999-03-30',
            'production_companies' => [
                ['name' => 'Warner Bros.', 'logo_path' => '/wb.png', 'origin_country' => 'US'],
                ['name' => 'Village Roadshow', 'logo_path' => null, 'origin_country' => 'AU'],
                ['name' => ''], // skipped (empty name)
            ],
            'credits' => [
                'cast' => [
                    ['name' => 'Keanu Reeves', 'character' => 'Neo', 'order' => 0, 'profile_path' => '/keanu.jpg'],
                    ['name' => 'Carrie-Anne Moss', 'character' => 'Trinity', 'order' => 1, 'profile_path' => null],
                    ['name' => '', 'character' => 'Extra', 'order' => 2], // skipped (no name)
                ],
                'crew' => [
                    ['name' => 'Lana Wachowski', 'job' => 'Director', 'profile_path' => '/lana.jpg'],
                    ['name' => 'Lana Wachowski', 'job' => 'Director'], // dup name+job → de-duped
                    ['name' => 'Lilly Wachowski', 'job' => 'Writer', 'profile_path' => null],
                    ['name' => 'Joel Silver', 'job' => 'Producer'],
                    ['name' => 'Bill Pope', 'job' => 'Director of Photography'], // not a key job → dropped
                ],
            ],
        ]);

        $details = (new TmdbProvider('k', $http))->getDetails('603');

        // Flat `actors` (objects with order) UNCHANGED.
        $this->assertSame('Keanu Reeves', $details['actors'][0]['name']);
        $this->assertSame('Neo', $details['actors'][0]['role']);

        // Rich cast with profile photos.
        $this->assertCount(2, $details['cast']);
        $this->assertSame([
            'name' => 'Keanu Reeves',
            'role' => 'Neo',
            'profile_url' => 'https://image.tmdb.org/t/p/w185/keanu.jpg',
        ], $details['cast'][0]);
        $this->assertNull($details['cast'][1]['profile_url']);

        // Crew: key jobs only, deduped by name+job.
        $crewKeys = array_map(static fn(array $c): string => $c['name'] . '/' . $c['job'], $details['crew']);
        $this->assertSame(
            ['Lana Wachowski/Director', 'Lilly Wachowski/Writer', 'Joel Silver/Producer'],
            $crewKeys,
        );
        $this->assertSame('https://image.tmdb.org/t/p/w185/lana.jpg', $details['crew'][0]['profile_url']);
        $this->assertNull($details['crew'][1]['profile_url']);
        // Non-key job (Director of Photography) excluded — assert it is absent.
        $this->assertNotContains('Bill Pope/Director of Photography', $crewKeys);

        // Production companies with logo URLs; empty name skipped.
        $this->assertCount(2, $details['production_companies']);
        $this->assertSame([
            'name' => 'Warner Bros.',
            'logo_url' => 'https://image.tmdb.org/t/p/w185/wb.png',
            'origin_country' => 'US',
        ], $details['production_companies'][0]);
        $this->assertNull($details['production_companies'][1]['logo_url']);

        // `studio` (first company) is still present, unchanged.
        $this->assertSame('Warner Bros.', $details['studio']);
    }

    public function testGetTvDetailsAppendsProductionCompanies(): void
    {
        $http = $this->createMock(MetadataHttpClient::class);
        $http->expects($this->once())
            ->method('get')
            ->with('/tv/1668', $this->callback(static function (array $p): bool {
                $append = is_string($p['append_to_response'] ?? null) ? $p['append_to_response'] : '';
                return str_contains($append, 'production_companies')
                    && str_contains($append, 'aggregate_credits');
            }))
            ->willReturn(['id' => 1668, 'name' => '24']);

        (new TmdbProvider('k', $http))->getTvDetails('1668');
    }

    public function testGetTvDetailsEmitsRichCastCrewFromAggregateCreditsAndNetworks(): void
    {
        $http = $this->createMock(MetadataHttpClient::class);
        $http->method('get')->willReturn([
            'id' => 1668,
            'name' => '24',
            'first_air_date' => '2001-11-06',
            'created_by' => [
                ['name' => 'Joel Surnow', 'profile_path' => '/surnow.jpg'],
                ['name' => 'Robert Cochran', 'profile_path' => null],
            ],
            'production_companies' => [
                ['name' => 'Imagine Television', 'logo_path' => '/imagine.png', 'origin_country' => 'US'],
            ],
            'networks' => [
                ['name' => 'FOX', 'logo_path' => '/fox.png', 'origin_country' => 'US'],
            ],
            'aggregate_credits' => [
                'cast' => [
                    [
                        'name' => 'Kiefer Sutherland',
                        'profile_path' => '/kiefer.jpg',
                        'roles' => [['character' => 'Jack Bauer']],
                        'order' => 0,
                    ],
                    ['name' => '', 'roles' => [['character' => 'X']]], // skipped
                ],
                'crew' => [
                    ['name' => 'Jon Cassar', 'jobs' => [['job' => 'Director']], 'profile_path' => '/cassar.jpg'],
                    ['name' => 'Some Gaffer', 'jobs' => [['job' => 'Gaffer']]], // dropped
                ],
            ],
        ]);

        $details = (new TmdbProvider('k', $http))->getTvDetails('1668');

        // Flat actors stays a list of names.
        $this->assertSame(['Kiefer Sutherland'], $details['actors']);

        // Rich cast pulls role from roles[0].character + profile.
        $this->assertCount(1, $details['cast']);
        $this->assertSame([
            'name' => 'Kiefer Sutherland',
            'role' => 'Jack Bauer',
            'profile_url' => 'https://image.tmdb.org/t/p/w185/kiefer.jpg',
        ], $details['cast'][0]);

        // Crew: created_by mapped to Creator + key-job crew from aggregate_credits.
        $crewKeys = array_map(static fn(array $c): string => $c['name'] . '/' . $c['job'], $details['crew']);
        $this->assertSame(
            ['Joel Surnow/Creator', 'Robert Cochran/Creator', 'Jon Cassar/Director'],
            $crewKeys,
        );

        // Companies: production_companies AND networks both feed the list.
        $names = array_map(static fn(array $c): string => $c['name'], $details['production_companies']);
        $this->assertSame(['Imagine Television', 'FOX'], $names);
        $this->assertSame('Imagine Television', $details['studio']);
    }
}
