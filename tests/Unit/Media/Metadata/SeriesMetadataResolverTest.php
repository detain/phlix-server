<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use Phlix\Media\Metadata\Resolution\PriorityConfig;
use Phlix\Media\Metadata\Resolution\PriorityFieldResolver;
use Phlix\Media\Metadata\SeriesMetadataResolver;
use Phlix\Media\Metadata\TmdbProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Media\Metadata\SeriesMetadataResolver
 */
final class SeriesMetadataResolverTest extends TestCase
{
    public function testResolveReturnsSeriesMetadataShapedForTheShaper(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('searchTv')->willReturn([['id' => '1668', 'name' => '24']]);
        $tmdb->method('getTvDetails')->with('1668')->willReturn([
            'name' => '24',
            'overview' => 'Real-time thriller.',
            'year' => 2001,
            'genres' => ['Drama', 'Action & Adventure'],
            'poster_path' => '/poster.jpg',
            'backdrop_path' => '/back.jpg',
            'imdb_id' => 'tt0285331',
            'official_rating' => 'TV-14',
            'tmdb_id' => '1668',
        ]);

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('24', 2001);

        $this->assertNotNull($resolved);
        $this->assertSame('https://image.tmdb.org/t/p/w500/poster.jpg', $resolved['poster_url']);
        $this->assertSame('https://image.tmdb.org/t/p/w500/back.jpg', $resolved['backdrop_url']);
        $this->assertSame('Real-time thriller.', $resolved['overview']);
        $this->assertSame(['Drama', 'Action & Adventure'], $resolved['genres']);
        $this->assertSame(2001, $resolved['year']);
        $this->assertSame('1668', $resolved['tmdb_id']);
        $externalIds = $resolved['external_ids'];
        $this->assertIsArray($externalIds);
        $this->assertSame('1668', $externalIds['tmdb']);
        $this->assertSame('tt0285331', $externalIds['imdb']);
        $this->assertSame(['tmdb'], $resolved['sources']);
        // No tvdb_id supplied by getTvDetails → the external_ids has no tvdb key.
        $this->assertArrayNotHasKey('tvdb', $externalIds);
    }

    public function testResolveThreadsTvdbIdIntoExternalIds(): void
    {
        // M3: when getTvDetails carries a `tvdb_id`, resolve() must expose it under
        // external_ids.tvdb so the theme-music resolver can key the Plex fallback.
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('searchTv')->willReturn([['id' => '1668', 'name' => '24']]);
        $tmdb->method('getTvDetails')->with('1668')->willReturn([
            'name' => '24',
            'tmdb_id' => '1668',
            'imdb_id' => 'tt0285331',
            'tvdb_id' => '76290',
        ]);

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('24', 2001);

        $this->assertNotNull($resolved);
        $externalIds = $resolved['external_ids'];
        $this->assertIsArray($externalIds);
        $this->assertSame('76290', $externalIds['tvdb']);
    }

    /**
     * resolve() accepts the optional per-library `$priorityOverride` arg (item 5)
     * and threads its genres mode into shaping. The series path stays TMDB-only,
     * so the metadata shape is unchanged, but the call must succeed exactly as it
     * does without an override (backward-compatible new param).
     */
    public function testResolveAcceptsPerLibraryPriorityOverride(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('searchTv')->willReturn([['id' => '1668', 'name' => '24']]);
        $tmdb->method('getTvDetails')->with('1668')->willReturn([
            'name' => '24',
            'overview' => 'Real-time thriller.',
            'year' => 2001,
            'genres' => ['Drama', 'Action & Adventure'],
            'poster_path' => '/poster.jpg',
            'tmdb_id' => '1668',
        ]);

        $override = new PriorityConfig(['series' => ['tmdb']], PriorityFieldResolver::GENRES_UNION);
        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('24', 2001, $override);

        $this->assertNotNull($resolved);
        $this->assertSame('1668', $resolved['tmdb_id']);
        $this->assertSame(['Drama', 'Action & Adventure'], $resolved['genres']);
        $this->assertSame(['tmdb'], $resolved['sources']);
    }

    public function testResolveRetriesWithoutYearWhenYearScopedSearchMisses(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        // First call (year-scoped) returns nothing; second (year-less) finds it.
        $tmdb->method('searchTv')->willReturnOnConsecutiveCalls([], [['id' => '99', 'name' => 'Show']]);
        $tmdb->method('getTvDetails')->with('99')->willReturn(['name' => 'Show', 'tmdb_id' => '99']);

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('Show', 2010);

        $this->assertNotNull($resolved);
        $this->assertSame('99', $resolved['tmdb_id']);
    }

    public function testResolveReturnsNullWhenNoSearchMatch(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('searchTv')->willReturn([]);

        $this->assertNull((new SeriesMetadataResolver($tmdb))->resolve('Nonexistent', null));
    }

    public function testResolveReturnsNullForBlankTitle(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->expects($this->never())->method('searchTv');

        $this->assertNull((new SeriesMetadataResolver($tmdb))->resolve('   ', null));
    }

    public function testResolveSeasonEpisodesMapsByEpisodeNumberWithImageUrls(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('getTvSeason')->with('1668', 1)->willReturn([
            'poster_path' => '/s1.jpg',
            'overview' => 'Season one.',
            'episodes' => [
                [
                    'episode_number' => 1, 'name' => '12:00 A.M.', 'overview' => 'O1',
                    'still_path' => '/e1.jpg', 'air_date' => '2001-11-06', 'runtime' => 44,
                ],
                [
                    'episode_number' => 2, 'name' => '1:00 A.M.', 'overview' => '',
                    'still_path' => null, 'air_date' => '', 'runtime' => 0,
                ],
            ],
        ]);

        $season = (new SeriesMetadataResolver($tmdb))->resolveSeasonEpisodes('1668', 1);

        $this->assertSame('https://image.tmdb.org/t/p/w500/s1.jpg', $season['poster_url']);
        $this->assertSame('12:00 A.M.', $season['episodes'][1]['episode_title']);
        // Episodes carry their own still (from TMDB still_path) as poster_url +
        // still_url; enrichEpisode() falls through to the season/series poster
        // only when the episode has no still.
        $this->assertSame('https://image.tmdb.org/t/p/w500/e1.jpg', $season['episodes'][1]['poster_url']);
        $this->assertSame('https://image.tmdb.org/t/p/w500/e1.jpg', $season['episodes'][1]['still_url']);
        $this->assertSame(44, $season['episodes'][1]['runtime']);
        // Episode 2: empty/zero fields normalize to null (no still → no image).
        $this->assertNull($season['episodes'][2]['poster_url']);
        $this->assertNull($season['episodes'][2]['still_url']);
        $this->assertNull($season['episodes'][2]['overview']);
        $this->assertNull($season['episodes'][2]['runtime']);
    }

    public function testResolveSeasonEpisodesCarriesCastCrewAndVoteAverage(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('getTvSeason')->with('1668', 1)->willReturn([
            'poster_path' => '/s1.jpg',
            'overview' => 'Season one.',
            'episodes' => [
                [
                    'episode_number' => 1,
                    'name' => '12:00 A.M.',
                    'overview' => 'O1',
                    'still_path' => '/e1.jpg',
                    'air_date' => '2001-11-06',
                    'runtime' => 44,
                    'vote_average' => 7.8,
                    'cast' => [
                        [
                            'name' => 'Kiefer Sutherland', 'role' => 'Jack Bauer',
                            'profile_url' => 'https://i/w185/k.jpg',
                        ],
                        ['name' => '', 'role' => 'X', 'profile_url' => null], // nameless dropped
                    ],
                    'crew' => [
                        ['name' => 'Stephen Hopkins', 'job' => 'Director', 'profile_url' => null],
                    ],
                ],
                [
                    'episode_number' => 2,
                    'name' => '1:00 A.M.',
                    'overview' => '',
                    'still_path' => null,
                    'air_date' => '',
                    'runtime' => 0,
                    'vote_average' => 0.0,
                    'cast' => [],
                    'crew' => [],
                ],
            ],
        ]);

        $season = (new SeriesMetadataResolver($tmdb))->resolveSeasonEpisodes('1668', 1);

        // Episode 1: rich cast/crew + vote average carried through in canonical shape.
        $this->assertSame(7.8, $season['episodes'][1]['vote_average']);
        $this->assertCount(1, $season['episodes'][1]['cast']);
        $this->assertSame('Kiefer Sutherland', $season['episodes'][1]['cast'][0]['name']);
        $this->assertSame('Jack Bauer', $season['episodes'][1]['cast'][0]['role']);
        $this->assertSame('https://i/w185/k.jpg', $season['episodes'][1]['cast'][0]['profile_url']);
        $this->assertSame('Director', $season['episodes'][1]['crew'][0]['job']);

        // Episode 2: empty cast/crew + zero vote normalize to []/null.
        $this->assertSame([], $season['episodes'][2]['cast']);
        $this->assertSame([], $season['episodes'][2]['crew']);
        $this->assertNull($season['episodes'][2]['vote_average']);
    }

    public function testResolveCarriesSeriesTagsFromKeywords(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('searchTv')->willReturn([['id' => '1668', 'name' => '24']]);
        $tmdb->method('getTvDetails')->with('1668')->willReturn([
            'name' => '24',
            'tmdb_id' => '1668',
            'genres' => ['Drama'],
            'tags' => ['terrorism', 'counter terrorism', 'terrorism'],
        ]);

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('24', 2001);

        $this->assertNotNull($resolved);
        $this->assertSame(['terrorism', 'counter terrorism'], $resolved['tags']);
    }

    public function testResolveOmitsTagsWhenTvDetailsLacksThem(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('searchTv')->willReturn([['id' => '1668', 'name' => '24']]);
        $tmdb->method('getTvDetails')->with('1668')->willReturn([
            'name' => '24',
            'tmdb_id' => '1668',
        ]);

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('24', 2001);

        $this->assertNotNull($resolved);
        $this->assertArrayNotHasKey('tags', $resolved);
    }

    public function testResolveSeasonEpisodesEmptyForBlankTmdbId(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->expects($this->never())->method('getTvSeason');

        $season = (new SeriesMetadataResolver($tmdb))->resolveSeasonEpisodes('', 1);

        $this->assertSame([], $season['episodes']);
    }

    public function testResolvePassesThroughRichCastCrewCompaniesWithFlatActors(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('searchTv')->willReturn([['id' => '1668', 'name' => '24']]);
        $tmdb->method('getTvDetails')->with('1668')->willReturn([
            'name' => '24',
            'tmdb_id' => '1668',
            'actors' => ['Kiefer Sutherland'],
            'cast' => [
                ['name' => 'Kiefer Sutherland', 'role' => 'Jack Bauer', 'profile_url' => 'https://i/w185/k.jpg'],
            ],
            'crew' => [
                ['name' => 'Joel Surnow', 'job' => 'Creator', 'profile_url' => null],
            ],
            'production_companies' => [
                ['name' => 'FOX', 'logo_url' => 'https://i/w185/fox.png', 'origin_country' => 'US'],
            ],
            'studio' => 'FOX',
        ]);

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('24', 2001);

        $this->assertNotNull($resolved);
        // actors stays flat.
        $this->assertSame(['Kiefer Sutherland'], $resolved['actors']);
        $cast = $resolved['cast'];
        $this->assertIsArray($cast);
        $this->assertIsArray($cast[0]);
        $this->assertSame('Jack Bauer', $cast[0]['role']);
        $this->assertSame('https://i/w185/k.jpg', $cast[0]['profile_url']);
        $crew = $resolved['crew'];
        $this->assertIsArray($crew);
        $this->assertIsArray($crew[0]);
        $this->assertSame('Creator', $crew[0]['job']);
        $companies = $resolved['production_companies'];
        $this->assertIsArray($companies);
        $this->assertIsArray($companies[0]);
        $this->assertSame('FOX', $companies[0]['name']);
        $this->assertSame('FOX', $resolved['studio']);
    }

    public function testResolveOmitsRichKeysWhenTvDetailsLacksThem(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('searchTv')->willReturn([['id' => '1668', 'name' => '24']]);
        $tmdb->method('getTvDetails')->with('1668')->willReturn([
            'name' => '24',
            'tmdb_id' => '1668',
        ]);

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('24', 2001);

        $this->assertNotNull($resolved);
        $this->assertArrayNotHasKey('cast', $resolved);
        $this->assertArrayNotHasKey('crew', $resolved);
        $this->assertArrayNotHasKey('production_companies', $resolved);
        $this->assertArrayNotHasKey('studio', $resolved);
        $this->assertArrayNotHasKey('actors', $resolved);
    }
}
