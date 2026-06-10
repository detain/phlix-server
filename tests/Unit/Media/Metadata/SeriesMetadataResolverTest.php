<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

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
        $this->assertSame('1668', $resolved['external_ids']['tmdb']);
        $this->assertSame('tt0285331', $resolved['external_ids']['imdb']);
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
                ['episode_number' => 1, 'name' => '12:00 A.M.', 'overview' => 'O1', 'still_path' => '/e1.jpg', 'air_date' => '2001-11-06', 'runtime' => 44],
                ['episode_number' => 2, 'name' => '1:00 A.M.', 'overview' => '', 'still_path' => null, 'air_date' => '', 'runtime' => 0],
            ],
        ]);

        $season = (new SeriesMetadataResolver($tmdb))->resolveSeasonEpisodes('1668', 1);

        $this->assertSame('https://image.tmdb.org/t/p/w500/s1.jpg', $season['poster_url']);
        $this->assertSame('12:00 A.M.', $season['episodes'][1]['episode_title']);
        $this->assertSame('https://image.tmdb.org/t/p/w500/e1.jpg', $season['episodes'][1]['poster_url']);
        $this->assertSame(44, $season['episodes'][1]['runtime']);
        // Episode 2: empty/zero fields normalize to null.
        $this->assertNull($season['episodes'][2]['poster_url']);
        $this->assertNull($season['episodes'][2]['overview']);
        $this->assertNull($season['episodes'][2]['runtime']);
    }

    public function testResolveSeasonEpisodesEmptyForBlankTmdbId(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->expects($this->never())->method('getTvSeason');

        $season = (new SeriesMetadataResolver($tmdb))->resolveSeasonEpisodes('', 1);

        $this->assertSame([], $season['episodes']);
    }
}
