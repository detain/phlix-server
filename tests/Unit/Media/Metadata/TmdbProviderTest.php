<?php

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\MetadataFailureKind;
use Phlix\Media\Metadata\MetadataHttpClient;
use Phlix\Media\Metadata\MetadataHttpResult;
use Phlix\Media\Metadata\TmdbProvider;
use Phlix\Common\Logger\LoggerFactory;

class TmdbProviderTest extends TestCase
{
    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    /**
     * A MetadataHttpClient mock whose `getResult()` mirrors its `get()` stub.
     *
     * TmdbProvider calls {@see MetadataHttpClient::getResult()} so it can tell
     * an auth failure from an empty result set. The tests below predate that
     * and stub `get()`, so this bridges the two: `getResult()` delegates to
     * `get()` and wraps the outcome. Delegating rather than duplicating keeps
     * every existing `expects()`/`with()` constraint on `get()` meaningful —
     * the provider's single logical call still produces exactly one `get()`
     * invocation with the same arguments.
     *
     * @return MetadataHttpClient&MockObject
     */
    private function httpMock(): MetadataHttpClient
    {
        $http = $this->createMock(MetadataHttpClient::class);

        $http->method('getResult')->willReturnCallback(
            static function (string $endpoint, array $params = [], ?array $headers = null) use ($http) {
                $body = $http->get($endpoint, $params, $headers);

                return $body === null
                    ? MetadataHttpResult::failure(MetadataFailureKind::Transport)
                    : MetadataHttpResult::success(200, $body);
            }
        );

        return $http;
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
        $http = $this->httpMock();
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
        $http = $this->httpMock();
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

    public function testGetDetailsParsesUsMovieCertificationFromReleaseDates(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 27205,
            'title' => 'Inception',
            'overview' => 'Dreams.',
            'release_date' => '2010-07-16',
            'release_dates' => ['results' => [
                ['iso_3166_1' => 'GB', 'release_dates' => [['certification' => '12A', 'type' => 3]]],
                ['iso_3166_1' => 'US', 'release_dates' => [
                    ['certification' => '', 'type' => 1],        // premiere, no cert
                    ['certification' => 'PG-13', 'type' => 3],   // theatrical
                ]],
            ]],
        ]);

        $details = (new TmdbProvider('k', $http))->getDetails('27205');

        $this->assertSame('PG-13', $details['official_rating']);
    }

    public function testGetDetailsPrefersTheatricalCertificationOverOtherReleaseTypes(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 1,
            'title' => 'Film',
            'release_dates' => ['results' => [
                ['iso_3166_1' => 'US', 'release_dates' => [
                    ['certification' => 'NR', 'type' => 4],    // digital
                    ['certification' => 'R', 'type' => 3],     // theatrical wins
                ]],
            ]],
        ]);

        $details = (new TmdbProvider('k', $http))->getDetails('1');

        $this->assertSame('R', $details['official_rating']);
    }

    public function testGetDetailsFallsBackToFirstNonEmptyCertificationWhenNoTheatrical(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 1,
            'title' => 'Film',
            'release_dates' => ['results' => [
                ['iso_3166_1' => 'US', 'release_dates' => [
                    ['certification' => '', 'type' => 1],
                    ['certification' => 'PG', 'type' => 4], // only dated cert available
                ]],
            ]],
        ]);

        $details = (new TmdbProvider('k', $http))->getDetails('1');

        $this->assertSame('PG', $details['official_rating']);
    }

    public function testGetDetailsOfficialRatingNullWhenNoUsCertification(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 1,
            'title' => 'Film',
            'release_dates' => ['results' => [
                ['iso_3166_1' => 'GB', 'release_dates' => [['certification' => '15', 'type' => 3]]],
                ['iso_3166_1' => 'US', 'release_dates' => [['certification' => '', 'type' => 3]]],
            ]],
        ]);

        $details = (new TmdbProvider('k', $http))->getDetails('1');

        $this->assertNull($details['official_rating']);
    }

    public function testGetDetailsScansLaterUsEntryWhenFirstHasNoCert(): void
    {
        // FINDING 4: a first US results[] entry whose certs are all empty must
        // NOT short-circuit the scan — a later US entry's non-empty cert wins.
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 1,
            'title' => 'Film',
            'release_dates' => ['results' => [
                ['iso_3166_1' => 'US', 'release_dates' => [
                    ['certification' => '', 'type' => 1], // empty-only US entry
                ]],
                ['iso_3166_1' => 'US', 'release_dates' => [
                    ['certification' => 'R', 'type' => 3], // theatrical, later entry
                ]],
            ]],
        ]);

        $details = (new TmdbProvider('k', $http))->getDetails('1');

        $this->assertSame('R', $details['official_rating']);
    }

    public function testGetDetailsPrefersTheatricalAcrossMultipleUsEntries(): void
    {
        // A non-theatrical cert in an earlier US entry is only a fallback; a
        // theatrical (type 3) cert in a later US entry still wins.
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 1,
            'title' => 'Film',
            'release_dates' => ['results' => [
                ['iso_3166_1' => 'US', 'release_dates' => [
                    ['certification' => 'PG', 'type' => 4], // digital fallback
                ]],
                ['iso_3166_1' => 'US', 'release_dates' => [
                    ['certification' => 'PG-13', 'type' => 3], // theatrical wins
                ]],
            ]],
        ]);

        $details = (new TmdbProvider('k', $http))->getDetails('1');

        $this->assertSame('PG-13', $details['official_rating']);
    }

    public function testGetDetailsOfficialRatingNullWhenReleaseDatesAbsent(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn(['id' => 1, 'title' => 'Film']);

        $details = (new TmdbProvider('k', $http))->getDetails('1');

        $this->assertNull($details['official_rating']);
    }

    public function testGetTvDetailsThreadsTvdbIdFromExternalIds(): void
    {
        // M3: the TheTVDB id (integer in TMDB's external_ids) must surface as a
        // string `tvdb_id` on the formatted record so the theme-music resolver
        // can build the Plex-archive fallback URL.
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 1668,
            'name' => '24',
            'external_ids' => ['imdb_id' => 'tt0285331', 'tvdb_id' => 76290],
        ]);

        $details = (new TmdbProvider('k', $http))->getTvDetails('1668');

        $this->assertSame('76290', $details['tvdb_id']);
    }

    public function testGetTvDetailsTvdbIdNullWhenAbsent(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 1668,
            'name' => '24',
            'external_ids' => ['imdb_id' => 'tt0285331'],
        ]);

        $details = (new TmdbProvider('k', $http))->getTvDetails('1668');

        $this->assertNull($details['tvdb_id']);
    }

    public function testGetTvSeasonMapsEpisodesAndPoster(): void
    {
        $http = $this->httpMock();
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

    public function testGetTvSeasonRequestsCreditsAndCapturesGuestStarsCrewAndVote(): void
    {
        $http = $this->httpMock();
        $http->expects($this->once())
            ->method('get')
            ->with(
                '/tv/1668/season/1',
                $this->callback(static function (array $p): bool {
                    $append = is_string($p['append_to_response'] ?? null) ? $p['append_to_response'] : '';
                    return str_contains($append, 'credits');
                }),
            )
            ->willReturn([
                'poster_path' => '/s1.jpg',
                'overview' => 'Season one.',
                // Season-level regular cast (appended credits) — a base for every episode.
                'credits' => [
                    'cast' => [
                        ['name' => 'Kiefer Sutherland', 'character' => 'Jack Bauer', 'profile_path' => '/kiefer.jpg'],
                    ],
                ],
                'episodes' => [
                    [
                        'episode_number' => 1,
                        'name' => '12:00 A.M.',
                        'overview' => 'O1',
                        'still_path' => '/e1.jpg',
                        'air_date' => '2001-11-06',
                        'runtime' => 44,
                        'vote_average' => 7.8,
                        'guest_stars' => [
                            ['name' => 'Dennis Haysbert', 'character' => 'David Palmer', 'profile_path' => '/dennis.jpg'],
                            ['name' => 'Kiefer Sutherland', 'character' => 'Jack Bauer'], // dup regular → deduped
                        ],
                        'crew' => [
                            ['name' => 'Stephen Hopkins', 'job' => 'Director', 'profile_path' => '/hopkins.jpg'],
                            ['name' => 'Some Gaffer', 'job' => 'Gaffer'], // non-key job dropped
                        ],
                    ],
                ],
            ]);

        $season = (new TmdbProvider('k', $http))->getTvSeason('1668', 1);

        $ep = $season['episodes'][0];
        $this->assertSame(7.8, $ep['vote_average']);

        // Cast = season regulars ∪ guest stars, de-duplicated by name, canonical shape.
        $castNames = array_map(static fn(array $c): string => $c['name'], $ep['cast']);
        $this->assertSame(['Kiefer Sutherland', 'Dennis Haysbert'], $castNames);
        $this->assertSame('Jack Bauer', $ep['cast'][0]['role']);
        $this->assertSame('https://image.tmdb.org/t/p/w185/kiefer.jpg', $ep['cast'][0]['profile_url']);
        $this->assertSame('David Palmer', $ep['cast'][1]['role']);

        // Crew = episode key crew only (Director kept, Gaffer dropped).
        $this->assertCount(1, $ep['crew']);
        $this->assertSame('Stephen Hopkins', $ep['crew'][0]['name']);
        $this->assertSame('Director', $ep['crew'][0]['job']);
        $this->assertSame('https://image.tmdb.org/t/p/w185/hopkins.jpg', $ep['crew'][0]['profile_url']);
    }

    public function testGetTvDetailsExposesTagsFromKeywords(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 1668,
            'name' => '24',
            'first_air_date' => '2001-11-06',
            // TMDB TV nests keywords under keywords.results[].
            'keywords' => ['results' => [
                ['id' => 1, 'name' => 'terrorism'],
                ['id' => 2, 'name' => 'counter terrorism'],
                ['id' => 1, 'name' => 'terrorism'], // dup name → de-duped
            ]],
        ]);

        $details = (new TmdbProvider('k', $http))->getTvDetails('1668');

        $this->assertSame(['terrorism', 'counter terrorism'], $details['tags']);
    }

    public function testGetTvDetailsRequestsKeywords(): void
    {
        $http = $this->httpMock();
        $http->expects($this->once())
            ->method('get')
            ->with('/tv/1668', $this->callback(static function (array $p): bool {
                $append = is_string($p['append_to_response'] ?? null) ? $p['append_to_response'] : '';
                return str_contains($append, 'keywords');
            }))
            ->willReturn(['id' => 1668, 'name' => '24']);

        (new TmdbProvider('k', $http))->getTvDetails('1668');
    }

    public function testGetTvDetailsReturnsEmptyOnNullResponse(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn(null);

        $this->assertSame([], (new TmdbProvider('k', $http))->getTvDetails('1668'));
    }

    public function testGetDetailsEmitsRichCastCrewAndCompaniesForMovie(): void
    {
        $http = $this->httpMock();
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
        /** @var list<array<string, mixed>> $actors */
        $actors = $details['actors'];
        $this->assertSame('Keanu Reeves', $actors[0]['name']);
        $this->assertSame('Neo', $actors[0]['role']);

        // Rich cast with profile photos.
        /** @var list<array<string, mixed>> $cast */
        $cast = $details['cast'];
        $this->assertCount(2, $cast);
        $this->assertSame([
            'name' => 'Keanu Reeves',
            'role' => 'Neo',
            'profile_url' => 'https://image.tmdb.org/t/p/w185/keanu.jpg',
        ], $cast[0]);
        $this->assertNull($cast[1]['profile_url']);

        // Crew: key jobs only, deduped by name+job.
        /** @var list<array{name: string, job: string, profile_url: string|null}> $crew */
        $crew = $details['crew'];
        $crewKeys = array_map(static fn(array $c): string => $c['name'] . '/' . $c['job'], $crew);
        $this->assertSame(
            ['Lana Wachowski/Director', 'Lilly Wachowski/Writer', 'Joel Silver/Producer'],
            $crewKeys,
        );
        $this->assertSame('https://image.tmdb.org/t/p/w185/lana.jpg', $crew[0]['profile_url']);
        $this->assertNull($crew[1]['profile_url']);
        // Non-key job (Director of Photography) excluded — assert it is absent.
        $this->assertNotContains('Bill Pope/Director of Photography', $crewKeys);

        // Production companies with logo URLs; empty name skipped.
        /** @var list<array<string, mixed>> $companies */
        $companies = $details['production_companies'];
        $this->assertCount(2, $companies);
        $this->assertSame([
            'name' => 'Warner Bros.',
            'logo_url' => 'https://image.tmdb.org/t/p/w185/wb.png',
            'origin_country' => 'US',
        ], $companies[0]);
        $this->assertNull($companies[1]['logo_url']);

        // `studio` (first company) is still present, unchanged.
        $this->assertSame('Warner Bros.', $details['studio']);
    }

    public function testGetDetailsExposesTagsFromKeywordsForMovie(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 603,
            'title' => 'The Matrix',
            'release_date' => '1999-03-30',
            // TMDB movie nests keywords under keywords.keywords[] (TV uses results[]).
            'keywords' => ['keywords' => [
                ['id' => 1, 'name' => 'dystopia'],
                ['id' => 2, 'name' => 'artificial intelligence'],
                ['id' => 1, 'name' => 'dystopia'], // dup name → de-duped
                ['id' => 3, 'name' => ''], // blank → dropped
            ]],
        ]);

        $details = (new TmdbProvider('k', $http))->getDetails('603');

        $this->assertSame(['dystopia', 'artificial intelligence'], $details['tags']);
    }

    public function testGetDetailsRequestsKeywordsForMovie(): void
    {
        $http = $this->httpMock();
        $http->expects($this->once())
            ->method('get')
            ->with('/movie/603', $this->callback(static function (array $p): bool {
                $append = is_string($p['append_to_response'] ?? null) ? $p['append_to_response'] : '';
                return str_contains($append, 'keywords');
            }))
            ->willReturn(['id' => 603, 'title' => 'The Matrix']);

        (new TmdbProvider('k', $http))->getDetails('603');
    }

    public function testGetTvDetailsAppendsProductionCompanies(): void
    {
        $http = $this->httpMock();
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

    /**
     * The series-identification guards corroborate a candidate against TMDB's own
     * `alternative_titles` and production origin, so `getTvDetails()` has to
     * surface them. `alternative_titles` rides on `append_to_response`, i.e. it
     * costs no extra round-trip.
     */
    public function testGetTvDetailsSurfacesIdentityCorroborationFields(): void
    {
        $http = $this->httpMock();
        $http->expects($this->once())
            ->method('get')
            ->with('/tv/61333', $this->callback(static function (array $p): bool {
                $append = is_string($p['append_to_response'] ?? null) ? $p['append_to_response'] : '';
                return str_contains($append, 'alternative_titles');
            }))
            ->willReturn([
                'id' => 61333,
                'name' => 'Stigma of the Wind',
                'original_name' => '風のスティグマ',
                'original_language' => 'ja',
                'origin_country' => ['JP', '', 'JP'],
                'alternative_titles' => [
                    'results' => [
                        ['iso_3166_1' => 'JP', 'title' => 'Kaze no Stigma'],
                        ['iso_3166_1' => 'US', 'title' => ' Kaze no Stigma '],
                        ['iso_3166_1' => 'XX', 'title' => ''],
                    ],
                ],
            ]);

        $details = (new TmdbProvider('k', $http))->getTvDetails('61333');

        $this->assertSame('ja', $details['original_language']);
        $this->assertSame(['JP'], $details['origin_country']);
        $this->assertSame(['Kaze no Stigma'], $details['alternative_titles']);
    }

    /** Absent identity fields degrade to empty rather than to a wrong value. */
    public function testGetTvDetailsIdentityFieldsDefaultToEmpty(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn(['id' => 1668, 'name' => '24']);

        $details = (new TmdbProvider('k', $http))->getTvDetails('1668');

        $this->assertSame('', $details['original_language']);
        $this->assertSame([], $details['origin_country']);
        $this->assertSame([], $details['alternative_titles']);
    }

    public function testGetTvDetailsEmitsRichCastCrewFromAggregateCreditsAndNetworks(): void
    {
        $http = $this->httpMock();
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
        /** @var list<array<string, mixed>> $cast */
        $cast = $details['cast'];
        $this->assertCount(1, $cast);
        $this->assertSame([
            'name' => 'Kiefer Sutherland',
            'role' => 'Jack Bauer',
            'profile_url' => 'https://image.tmdb.org/t/p/w185/kiefer.jpg',
        ], $cast[0]);

        // Crew: created_by mapped to Creator + key-job crew from aggregate_credits.
        /** @var list<array{name: string, job: string, profile_url: string|null}> $crew */
        $crew = $details['crew'];
        $crewKeys = array_map(static fn(array $c): string => $c['name'] . '/' . $c['job'], $crew);
        $this->assertSame(
            ['Joel Surnow/Creator', 'Robert Cochran/Creator', 'Jon Cassar/Director'],
            $crewKeys,
        );

        // Companies: production_companies AND networks both feed the list.
        /** @var list<array{name: string, logo_url: string|null, origin_country: string}> $productionCompanies */
        $productionCompanies = $details['production_companies'];
        $names = array_map(static fn(array $c): string => $c['name'], $productionCompanies);
        $this->assertSame(['Imagine Television', 'FOX'], $names);
        $this->assertSame('Imagine Television', $details['studio']);
    }

    public function testMergeCastDedupIsCaseAndWhitespaceInsensitive(): void
    {
        $ref = new \ReflectionMethod(TmdbProvider::class, 'mergeCast');
        $ref->setAccessible(true);

        $base = [
            ['name' => 'John Smith', 'role' => 'Lead', 'profile_url' => null],
        ];
        $guest = [
            // Same person as the regular, differing only by case + surrounding
            // whitespace — must NOT be duplicated in the merged cast.
            ['name' => ' john smith ', 'role' => 'Guest', 'profile_url' => 'p.jpg'],
            ['name' => 'Jane Doe', 'role' => 'Guest Star', 'profile_url' => null],
        ];

        /** @var list<array{name: string, role: string, profile_url: string|null}> $merged */
        $merged = $ref->invoke(new TmdbProvider('k'), $base, $guest);

        // First occurrence (the regular) kept verbatim; the case/space variant
        // dropped; the genuinely different guest survives.
        $this->assertSame(
            [
                ['name' => 'John Smith', 'role' => 'Lead', 'profile_url' => null],
                ['name' => 'Jane Doe', 'role' => 'Guest Star', 'profile_url' => null],
            ],
            $merged,
        );
    }

    public function testGetDetailsRequestsVideosForMovie(): void
    {
        $http = $this->httpMock();
        $http->expects($this->once())
            ->method('get')
            ->with('/movie/603', $this->callback(static function (array $p): bool {
                $append = is_string($p['append_to_response'] ?? null) ? $p['append_to_response'] : '';
                return str_contains($append, 'videos');
            }))
            ->willReturn(['id' => 603, 'title' => 'The Matrix']);

        (new TmdbProvider('k', $http))->getDetails('603');
    }

    public function testGetDetailsPrefersOfficialYouTubeTrailerForMovie(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 603,
            'title' => 'The Matrix',
            'release_date' => '1999-03-30',
            'videos' => ['results' => [
                // A teaser and a non-official trailer precede the official trailer;
                // the official YouTube Trailer must still win.
                ['site' => 'YouTube', 'type' => 'Teaser', 'key' => 'TEASER1', 'official' => true],
                ['site' => 'YouTube', 'type' => 'Trailer', 'key' => 'UNOFFICIAL', 'official' => false],
                ['site' => 'Vimeo', 'type' => 'Trailer', 'key' => 'VIMEOKEY', 'official' => true],
                ['site' => 'YouTube', 'type' => 'Trailer', 'key' => 'OFFICIAL1', 'official' => true],
            ]],
        ]);

        $details = (new TmdbProvider('k', $http))->getDetails('603');

        $this->assertSame('OFFICIAL1', $details['trailer_key']);
        $this->assertSame('YouTube', $details['trailer_site']);
        $this->assertSame('https://www.youtube.com/watch?v=OFFICIAL1', $details['trailer_url']);
    }

    public function testGetDetailsFallsBackToTeaserWhenNoTrailerForMovie(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 603,
            'title' => 'The Matrix',
            'videos' => ['results' => [
                ['site' => 'YouTube', 'type' => 'Teaser', 'key' => 'TEASERKEY', 'official' => false],
            ]],
        ]);

        $details = (new TmdbProvider('k', $http))->getDetails('603');

        $this->assertSame('https://www.youtube.com/watch?v=TEASERKEY', $details['trailer_url']);
    }

    public function testGetDetailsOmitsTrailerWhenNoUsableVideoForMovie(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 603,
            'title' => 'The Matrix',
            'videos' => ['results' => [
                // Non-YouTube trailer and a YouTube featurette → neither is usable.
                ['site' => 'Vimeo', 'type' => 'Trailer', 'key' => 'VIMEO'],
                ['site' => 'YouTube', 'type' => 'Featurette', 'key' => 'FEAT'],
            ]],
        ]);

        $details = (new TmdbProvider('k', $http))->getDetails('603');

        $this->assertArrayNotHasKey('trailer_url', $details);
        $this->assertArrayNotHasKey('trailer_key', $details);
        $this->assertArrayNotHasKey('trailer_site', $details);
    }

    public function testGetTvDetailsRequestsVideos(): void
    {
        $http = $this->httpMock();
        $http->expects($this->once())
            ->method('get')
            ->with('/tv/1668', $this->callback(static function (array $p): bool {
                $append = is_string($p['append_to_response'] ?? null) ? $p['append_to_response'] : '';
                return str_contains($append, 'videos');
            }))
            ->willReturn(['id' => 1668, 'name' => '24']);

        (new TmdbProvider('k', $http))->getTvDetails('1668');
    }

    public function testGetTvDetailsPrefersOfficialYouTubeTrailer(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 1668,
            'name' => '24',
            'first_air_date' => '2001-11-06',
            'videos' => ['results' => [
                ['site' => 'YouTube', 'type' => 'Teaser', 'key' => 'TZ', 'official' => true],
                ['site' => 'YouTube', 'type' => 'Trailer', 'key' => 'TVOFFICIAL', 'official' => true],
            ]],
        ]);

        $details = (new TmdbProvider('k', $http))->getTvDetails('1668');

        $this->assertSame('TVOFFICIAL', $details['trailer_key']);
        $this->assertSame('YouTube', $details['trailer_site']);
        $this->assertSame('https://www.youtube.com/watch?v=TVOFFICIAL', $details['trailer_url']);
    }

    public function testGetTvDetailsOmitsTrailerWhenNoVideos(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 1668,
            'name' => '24',
            'first_air_date' => '2001-11-06',
        ]);

        $details = (new TmdbProvider('k', $http))->getTvDetails('1668');

        $this->assertArrayNotHasKey('trailer_url', $details);
    }

    public function testGetTrailersStillParsesYouTubeTrailersAndTeasers(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn(['results' => [
            ['site' => 'YouTube', 'type' => 'Trailer', 'key' => 'ABC', 'name' => 'Main Trailer'],
            ['site' => 'YouTube', 'type' => 'Teaser', 'key' => 'DEF', 'name' => 'Teaser'],
            ['site' => 'Vimeo', 'type' => 'Trailer', 'key' => 'ZZZ', 'name' => 'Skip'],
            ['site' => 'YouTube', 'type' => 'Featurette', 'key' => 'GHI', 'name' => 'Skip'],
        ]]);

        $trailers = (new TmdbProvider('k', $http))->getTrailers('603');

        $this->assertCount(2, $trailers);
        $this->assertSame('Trailer (Main Trailer)', $trailers[0]['title']);
        $this->assertSame('https://www.youtube.com/watch?v=ABC', $trailers[0]['url']);
        $this->assertSame('Teaser (Teaser)', $trailers[1]['title']);
        $this->assertSame('https://www.youtube.com/watch?v=DEF', $trailers[1]['url']);
    }

    public function testGetDetailsRejectsTrailerWithMalformedKey(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 603,
            'title' => 'The Matrix',
            'videos' => ['results' => [
                // A key carrying markup must never be interpolated into a URL.
                ['site' => 'YouTube', 'type' => 'Trailer', 'key' => 'abc"><iframe', 'official' => true],
            ]],
        ]);

        $details = (new TmdbProvider('k', $http))->getDetails('603');

        $this->assertArrayNotHasKey('trailer_url', $details);
        $this->assertArrayNotHasKey('trailer_key', $details);
        $this->assertArrayNotHasKey('trailer_site', $details);
    }

    public function testGetTrailersDropsEntriesWithMalformedKey(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn(['results' => [
            // A key smuggling a query param is out of the safe charset → dropped.
            ['site' => 'YouTube', 'type' => 'Trailer', 'key' => 'x&list=PLmalicious', 'name' => 'Evil'],
            ['site' => 'YouTube', 'type' => 'Trailer', 'key' => 'ABC', 'name' => 'Main Trailer'],
        ]]);

        $trailers = (new TmdbProvider('k', $http))->getTrailers('603');

        $this->assertCount(1, $trailers);
        $this->assertSame('https://www.youtube.com/watch?v=ABC', $trailers[0]['url']);
    }

    public function testGetDetailsPrefersPngLogoOverSvg(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 603,
            'title' => 'The Matrix',
            'images' => ['logos' => [
                // Highest vote, but SVG — must lose to the PNG for the raster cache.
                ['file_path' => '/vector.svg', 'iso_639_1' => 'en', 'vote_average' => 9.0],
                ['file_path' => '/raster.png', 'iso_639_1' => 'en', 'vote_average' => 5.0],
            ]],
        ]);

        $details = (new TmdbProvider('k', $http))->getDetails('603');

        $this->assertSame('/raster.png', $details['logo_path']);
        $this->assertSame('https://image.tmdb.org/t/p/original/raster.png', $details['logo_url']);
    }

    public function testGetDetailsPrefersEnglishLogoThenNullLanguage(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 603,
            'title' => 'The Matrix',
            'images' => ['logos' => [
                ['file_path' => '/fr.png', 'iso_639_1' => 'fr', 'vote_average' => 9.0],
                ['file_path' => '/neutral.png', 'iso_639_1' => null, 'vote_average' => 8.0],
                ['file_path' => '/en.png', 'iso_639_1' => 'en', 'vote_average' => 1.0],
            ]],
        ]);

        $details = (new TmdbProvider('k', $http))->getDetails('603');

        // English wins outright despite the lowest vote.
        $this->assertSame('/en.png', $details['logo_path']);
    }

    public function testGetDetailsBreaksLogoTieByVoteAverage(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 603,
            'title' => 'The Matrix',
            'images' => ['logos' => [
                ['file_path' => '/low.png', 'iso_639_1' => 'en', 'vote_average' => 3.2],
                ['file_path' => '/high.png', 'iso_639_1' => 'en', 'vote_average' => 7.7],
            ]],
        ]);

        $details = (new TmdbProvider('k', $http))->getDetails('603');

        $this->assertSame('/high.png', $details['logo_path']);
    }

    public function testGetDetailsSvgOnlyLogoExposesUrlWithoutPngPreference(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 603,
            'title' => 'The Matrix',
            'images' => ['logos' => [
                ['file_path' => '/only.svg', 'iso_639_1' => 'en', 'vote_average' => 6.0],
            ]],
        ]);

        $details = (new TmdbProvider('k', $http))->getDetails('603');

        // With no PNG, the SVG URL is still surfaced (local raster caching is then
        // skipped downstream because the path is not a .png).
        $this->assertSame('/only.svg', $details['logo_path']);
        $this->assertSame('https://image.tmdb.org/t/p/original/only.svg', $details['logo_url']);
    }

    public function testGetDetailsWithoutLogosLeavesLogoFieldsAbsent(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 603,
            'title' => 'The Matrix',
            'images' => ['logos' => []],
        ]);

        $details = (new TmdbProvider('k', $http))->getDetails('603');

        $this->assertArrayNotHasKey('logo_path', $details);
        $this->assertArrayNotHasKey('logo_url', $details);
    }

    public function testGetTvDetailsSelectsPngLogo(): void
    {
        $http = $this->httpMock();
        $http->method('get')->willReturn([
            'id' => 1399,
            'name' => 'Game of Thrones',
            'first_air_date' => '2011-04-17',
            'images' => ['logos' => [
                ['file_path' => '/got.png', 'iso_639_1' => 'en', 'vote_average' => 8.0],
            ]],
        ]);

        $details = (new TmdbProvider('k', $http))->getTvDetails('1399');

        $this->assertSame('/got.png', $details['logo_path']);
        $this->assertSame('https://image.tmdb.org/t/p/original/got.png', $details['logo_url']);
    }
}
