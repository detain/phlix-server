<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Metadata\Imdb\ImdbLookup;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Media\Metadata\Resolution\PriorityConfig;
use Phlix\Media\Metadata\TmdbProvider;
use RuntimeException;

/**
 * @covers \Phlix\Media\Metadata\MovieMetadataResolver
 */
class MovieMetadataResolverTest extends TestCase
{
    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    /**
     * Build TMDB-style formatted details as returned by TmdbProvider::getDetails().
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function tmdbDetails(array $overrides = []): array
    {
        return array_merge([
            'name' => 'The Matrix',
            'overview' => 'A hacker learns the truth.',
            'year' => 1999,
            'runtime_ticks' => 136 * 600000000,
            'genres' => ['Action', 'Science Fiction'],
            'poster_path' => '/poster.jpg',
            'backdrop_path' => '/backdrop.jpg',
            'imdb_id' => 'tt0133093',
            'tmdb_id' => '603',
        ], $overrides);
    }

    /**
     * Build an IMDb-style row as returned by ImdbLookup.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function imdbRow(array $overrides = []): array
    {
        return array_merge([
            'imdb_id' => 'tt0133093',
            'title' => 'The Matrix',
            'year' => 1999,
            'genres' => ['Action', 'Sci-Fi'],
            'average_rating' => 8.7,
            'num_votes' => 1900000,
            'runtime_minutes' => 136,
        ], $overrides);
    }

    public function testImdbIdFirstUsesFindByImdbIdAndSkipsSearch(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $imdb = $this->createMock(ImdbLookup::class);

        // Search must NOT be called when an imdb id is already known.
        $tmdb->expects($this->never())->method('search');
        $tmdb->expects($this->once())
            ->method('findByImdbId')
            ->with('tt0133093')
            ->willReturn(['id' => '603', 'title' => 'The Matrix', 'overview' => '', 'year' => 1999]);
        $tmdb->expects($this->once())
            ->method('getDetails')
            ->with('603')
            ->willReturn($this->tmdbDetails());

        // No title lookup because the id was supplied; getByImdbId provides ratings.
        $imdb->expects($this->never())->method('lookup');
        $imdb->expects($this->once())
            ->method('getByImdbId')
            ->with('tt0133093')
            ->willReturn($this->imdbRow());

        $resolver = new MovieMetadataResolver($tmdb, $imdb);
        $result = $resolver->resolve('The Matrix', 1999, ['imdb' => 'tt0133093']);

        $this->assertNotNull($result);
        $this->assertIsArray($result['external_ids']);
        $this->assertSame('603', $result['external_ids']['tmdb']);
        $this->assertSame('tt0133093', $result['external_ids']['imdb']);
        $this->assertSame(8.7, $result['imdb_rating']);
        $this->assertSame(1900000, $result['imdb_votes']);
        $this->assertSame(['tmdb', 'imdb'], $result['sources']);
    }

    public function testTitleSearchPathExtractsImdbIdFromTmdbDetails(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $imdb = $this->createMock(ImdbLookup::class);

        // No imdb id supplied and offline lookup finds nothing → fall through to search.
        $imdb->expects($this->once())
            ->method('lookup')
            ->with('The Matrix', 1999)
            ->willReturn(null);
        $tmdb->expects($this->never())->method('findByImdbId');
        $tmdb->expects($this->once())
            ->method('search')
            ->with('The Matrix', ['year' => 1999])
            ->willReturn([['id' => '603', 'title' => 'The Matrix']]);
        $tmdb->expects($this->once())
            ->method('getDetails')
            ->with('603')
            ->willReturn($this->tmdbDetails());

        // imdb id cross-populated from TMDB details → join ratings by id.
        $imdb->expects($this->once())
            ->method('getByImdbId')
            ->with('tt0133093')
            ->willReturn($this->imdbRow());

        $resolver = new MovieMetadataResolver($tmdb, $imdb);
        $result = $resolver->resolve('The Matrix', 1999);

        $this->assertNotNull($result);
        $this->assertIsArray($result['external_ids']);
        $this->assertSame('603', $result['external_ids']['tmdb']);
        $this->assertSame('tt0133093', $result['external_ids']['imdb']);
        $this->assertSame(8.7, $result['imdb_rating']);
        $this->assertSame(['tmdb', 'imdb'], $result['sources']);
    }

    public function testImdbOnlyWhenTmdbThrows(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $imdb = $this->createMock(ImdbLookup::class);

        // Offline lookup discovers the id; TMDB has no key and throws everywhere.
        $imdb->expects($this->once())
            ->method('lookup')
            ->with('The Matrix', 1999)
            ->willReturn($this->imdbRow());
        $tmdb->method('findByImdbId')
            ->willThrowException(new RuntimeException('no api key'));

        $resolver = new MovieMetadataResolver($tmdb, $imdb);
        $result = $resolver->resolve('The Matrix', 1999);

        $this->assertNotNull($result);
        $this->assertIsArray($result['external_ids']);
        $this->assertArrayNotHasKey('tmdb', $result['external_ids']);
        $this->assertSame('tt0133093', $result['external_ids']['imdb']);
        $this->assertSame(8.7, $result['imdb_rating']);
        $this->assertSame(['imdb'], $result['sources']);
        $this->assertSame('The Matrix', $result['title']);
        $this->assertSame(136, $result['runtime']);
    }

    public function testReturnsNullWhenNothingMatches(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $imdb = $this->createMock(ImdbLookup::class);

        $imdb->method('lookup')->willReturn(null);
        $tmdb->method('search')->willReturn([]);

        $resolver = new MovieMetadataResolver($tmdb, $imdb);
        $result = $resolver->resolve('Nonexistent Film', 2099);

        $this->assertNull($result);
    }

    public function testMergePrecedenceTmdbOverviewWinsImdbRatingPresent(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $imdb = $this->createMock(ImdbLookup::class);

        $tmdb->method('findByImdbId')
            ->willReturn(['id' => '603', 'title' => 'TMDB Title', 'overview' => '', 'year' => 1999]);
        $tmdb->method('getDetails')->willReturn($this->tmdbDetails([
            'name' => 'TMDB Title',
            'overview' => 'TMDB overview wins.',
        ]));
        $imdb->method('getByImdbId')->willReturn($this->imdbRow([
            'title' => 'IMDb Title',
            'average_rating' => 8.5,
        ]));

        $resolver = new MovieMetadataResolver($tmdb, $imdb);
        $result = $resolver->resolve('The Matrix', 1999, ['imdb' => 'tt0133093']);

        $this->assertNotNull($result);
        $this->assertSame('TMDB overview wins.', $result['overview']);
        $this->assertSame('TMDB Title', $result['title']);
        $this->assertSame(8.5, $result['imdb_rating']);
        $this->assertSame(['Action', 'Science Fiction'], $result['genres']);
    }

    public function testPreservesRichCastCrewCompaniesWhileFlatteningActors(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $imdb = $this->createMock(ImdbLookup::class);

        $tmdb->method('findByImdbId')
            ->willReturn(['id' => '603', 'title' => 'The Matrix', 'overview' => '', 'year' => 1999]);
        $tmdb->method('getDetails')->willReturn($this->tmdbDetails([
            // TMDB returns actor OBJECTS under `actors`; the resolver flattens them.
            'actors' => [
                ['name' => 'Keanu Reeves', 'role' => 'Neo', 'order' => 0],
                ['name' => 'Carrie-Anne Moss', 'role' => 'Trinity', 'order' => 1],
            ],
            'director' => 'Lana Wachowski',
            'cast' => [
                ['name' => 'Keanu Reeves', 'role' => 'Neo', 'profile_url' => 'https://i/w185/k.jpg'],
            ],
            'crew' => [
                ['name' => 'Lana Wachowski', 'job' => 'Director', 'profile_url' => null],
            ],
            'production_companies' => [
                ['name' => 'Warner Bros.', 'logo_url' => 'https://i/w185/wb.png', 'origin_country' => 'US'],
            ],
            'studio' => 'Warner Bros.',
        ]));
        $imdb->method('getByImdbId')->willReturn($this->imdbRow());

        $resolver = new MovieMetadataResolver($tmdb, $imdb);
        $result = $resolver->resolve('The Matrix', 1999, ['imdb' => 'tt0133093']);

        $this->assertNotNull($result);
        // actors is still a FLAT list of names (the landmine guard).
        $this->assertSame(['Keanu Reeves', 'Carrie-Anne Moss'], $result['actors']);
        $this->assertSame('Lana Wachowski', $result['director']);
        // Rich objects passed through verbatim.
        $this->assertSame('Keanu Reeves', $result['cast'][0]['name']);
        $this->assertSame('https://i/w185/k.jpg', $result['cast'][0]['profile_url']);
        $this->assertSame('Director', $result['crew'][0]['job']);
        $this->assertSame('Warner Bros.', $result['production_companies'][0]['name']);
        $this->assertSame('Warner Bros.', $result['studio']);
    }

    public function testOmitsRichKeysWhenTmdbHasNoCastCrewOrCompanies(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $imdb = $this->createMock(ImdbLookup::class);

        $tmdb->method('findByImdbId')
            ->willReturn(['id' => '603', 'title' => 'The Matrix', 'overview' => '', 'year' => 1999]);
        // tmdbDetails() default has none of the rich keys.
        $tmdb->method('getDetails')->willReturn($this->tmdbDetails());
        $imdb->method('getByImdbId')->willReturn($this->imdbRow());

        $resolver = new MovieMetadataResolver($tmdb, $imdb);
        $result = $resolver->resolve('The Matrix', 1999, ['imdb' => 'tt0133093']);

        $this->assertNotNull($result);
        $this->assertArrayNotHasKey('cast', $result);
        $this->assertArrayNotHasKey('crew', $result);
        $this->assertArrayNotHasKey('production_companies', $result);
        $this->assertArrayNotHasKey('studio', $result);
    }

    /**
     * Proves the PriorityConfig actually DRIVES per-field selection (Step 3.4):
     * with the source order flipped to `['imdb','tmdb']`, the shared fields
     * (title / year / runtime / genres) resolve from the IMDb record instead of
     * TMDB, while TMDB-only fields (overview) still fall through. If the merge
     * were still hard-coded to "TMDB preferred", title/year/runtime would come
     * from TMDB and this test would fail.
     */
    public function testImdbFirstOrderResolvesSharedFieldsFromImdb(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $imdb = $this->createMock(ImdbLookup::class);

        $tmdb->method('findByImdbId')
            ->willReturn(['id' => '603', 'title' => 'The Matrix', 'overview' => '', 'year' => 1999]);
        // TMDB and IMDb disagree on title/year/runtime/genres so the flip is observable.
        $tmdb->method('getDetails')->willReturn($this->tmdbDetails([
            'name' => 'TMDB Title',
            'overview' => 'TMDB overview (tmdb-only field).',
            'year' => 1999,
            'runtime_ticks' => 136 * 600000000,
            'genres' => ['Action', 'Science Fiction'],
        ]));
        $imdb->method('getByImdbId')->willReturn($this->imdbRow([
            'title' => 'IMDb Title',
            'year' => 1998,
            'runtime_minutes' => 150,
            'genres' => ['Drama', 'Sci-Fi'],
        ]));

        // Inject an IMDb-first priority order — this is what drives the selection.
        $resolver = new MovieMetadataResolver(
            $tmdb,
            $imdb,
            null,
            new PriorityConfig(['movie' => ['imdb', 'tmdb']]),
        );
        $result = $resolver->resolve('The Matrix', 1999, ['imdb' => 'tt0133093']);

        $this->assertNotNull($result);
        // Shared fields now come from IMDb (would be the TMDB values if hard-coded).
        $this->assertSame('IMDb Title', $result['title']);
        $this->assertSame(1998, $result['year']);
        $this->assertSame(150, $result['runtime']);
        $this->assertSame(['Drama', 'Sci-Fi'], $result['genres']);
        // A TMDB-only field still falls through (IMDb has no overview).
        $this->assertSame('TMDB overview (tmdb-only field).', $result['overview']);
        // Provenance/id construction is unchanged regardless of order.
        $this->assertSame('603', $result['external_ids']['tmdb']);
        $this->assertSame('tt0133093', $result['external_ids']['imdb']);
        $this->assertSame(8.7, $result['imdb_rating']);
        $this->assertSame(['tmdb', 'imdb'], $result['sources']);
    }
}
