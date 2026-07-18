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
            'tags' => ['dystopia', 'artificial intelligence'],
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

    public function testResolvedMovieCarriesTmdbTagsEndToEnd(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $imdb = $this->createMock(ImdbLookup::class);

        $tmdb->method('findByImdbId')
            ->willReturn(['id' => '603', 'title' => 'The Matrix', 'overview' => '', 'year' => 1999]);
        $tmdb->method('getDetails')->willReturn($this->tmdbDetails([
            'tags' => ['dystopia', 'artificial intelligence', 'kung fu'],
        ]));
        $imdb->method('getByImdbId')->willReturn($this->imdbRow());

        $resolver = new MovieMetadataResolver($tmdb, $imdb);
        $result = $resolver->resolve('The Matrix', 1999, ['imdb' => 'tt0133093']);

        $this->assertNotNull($result);
        // tags flow from TMDB through the canonical field pipeline into the merged result.
        $this->assertSame(['dystopia', 'artificial intelligence', 'kung fu'], $result['tags']);
    }

    public function testResolvedMovieCarriesTmdbTrailerEndToEnd(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $imdb = $this->createMock(ImdbLookup::class);

        $tmdb->method('findByImdbId')
            ->willReturn(['id' => '603', 'title' => 'The Matrix', 'overview' => '', 'year' => 1999]);
        $tmdb->method('getDetails')->willReturn($this->tmdbDetails([
            'trailer_url' => 'https://www.youtube.com/watch?v=KEY1',
            'trailer_key' => 'KEY1',
            'trailer_site' => 'YouTube',
        ]));
        $imdb->method('getByImdbId')->willReturn($this->imdbRow());

        $resolver = new MovieMetadataResolver($tmdb, $imdb);
        $result = $resolver->resolve('The Matrix', 1999, ['imdb' => 'tt0133093']);

        $this->assertNotNull($result);
        // trailer flows from TMDB through the canonical field pipeline into the merge.
        $this->assertSame('https://www.youtube.com/watch?v=KEY1', $result['trailer_url']);
        $this->assertSame('KEY1', $result['trailer_key']);
        $this->assertSame('YouTube', $result['trailer_site']);
    }

    public function testResolvedMovieWithoutTmdbTrailerOmitsTrailer(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $imdb = $this->createMock(ImdbLookup::class);

        $tmdb->method('findByImdbId')
            ->willReturn(['id' => '603', 'title' => 'The Matrix', 'overview' => '', 'year' => 1999]);
        $tmdb->method('getDetails')->willReturn($this->tmdbDetails());
        $imdb->method('getByImdbId')->willReturn($this->imdbRow());

        $resolver = new MovieMetadataResolver($tmdb, $imdb);
        $result = $resolver->resolve('The Matrix', 1999, ['imdb' => 'tt0133093']);

        $this->assertNotNull($result);
        $this->assertArrayNotHasKey('trailer_url', $result, 'no source supplied a trailer → key omitted');
    }

    public function testResolvedMovieWithoutTmdbTagsOmitsTags(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $imdb = $this->createMock(ImdbLookup::class);

        // TMDB details carry no tags; IMDb never supplies tags.
        $details = $this->tmdbDetails();
        unset($details['tags']);
        $tmdb->method('findByImdbId')
            ->willReturn(['id' => '603', 'title' => 'The Matrix', 'overview' => '', 'year' => 1999]);
        $tmdb->method('getDetails')->willReturn($details);
        $imdb->method('getByImdbId')->willReturn($this->imdbRow());

        $resolver = new MovieMetadataResolver($tmdb, $imdb);
        $result = $resolver->resolve('The Matrix', 1999, ['imdb' => 'tt0133093']);

        $this->assertNotNull($result);
        $this->assertArrayNotHasKey('tags', $result, 'no source supplied tags → key omitted');
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

    public function testAkaFallbackResolvesWhenPrimaryLookupMisses(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $imdb = $this->createMock(ImdbLookup::class);

        // Primary title lookup misses (the on-disk title is a foreign aka)…
        $imdb->expects($this->once())
            ->method('lookup')
            ->with('Matrix - Die Vollendung', 1999)
            ->willReturn(null);
        // …but the aka fallback resolves it to the canonical Matrix tconst.
        $imdb->expects($this->once())
            ->method('lookupByAka')
            ->with('Matrix - Die Vollendung', 1999)
            ->willReturn($this->imdbRow());

        // TMDB has no key and throws everywhere → imdb-only result.
        $tmdb->method('search')->willThrowException(new RuntimeException('no api key'));

        $resolver = new MovieMetadataResolver($tmdb, $imdb);
        $result = $resolver->resolve('Matrix - Die Vollendung', 1999);

        $this->assertNotNull($result);
        $this->assertIsArray($result['external_ids']);
        $this->assertSame('tt0133093', $result['external_ids']['imdb']);
        $this->assertSame(['imdb'], $result['sources']);
        $this->assertSame('The Matrix', $result['title']);
    }

    public function testAkaFallbackMissYieldsNoFalsePositive(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $imdb = $this->createMock(ImdbLookup::class);

        // Neither primary nor aka lookup match, and TMDB finds nothing → null.
        $imdb->expects($this->once())->method('lookup')->willReturn(null);
        $imdb->expects($this->once())->method('lookupByAka')->willReturn(null);
        $tmdb->method('search')->willReturn([]);

        $resolver = new MovieMetadataResolver($tmdb, $imdb);
        $result = $resolver->resolve('Totally Unknown Title', 2099);

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
        $cast = $result['cast'];
        $this->assertIsArray($cast);
        $this->assertIsArray($cast[0]);
        $this->assertSame('Keanu Reeves', $cast[0]['name']);
        $this->assertSame('https://i/w185/k.jpg', $cast[0]['profile_url']);
        $crew = $result['crew'];
        $this->assertIsArray($crew);
        $this->assertIsArray($crew[0]);
        $this->assertSame('Director', $crew[0]['job']);
        $companies = $result['production_companies'];
        $this->assertIsArray($companies);
        $this->assertIsArray($companies[0]);
        $this->assertSame('Warner Bros.', $companies[0]['name']);
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
        $externalIds = $result['external_ids'];
        $this->assertIsArray($externalIds);
        $this->assertSame('603', $externalIds['tmdb']);
        $this->assertSame('tt0133093', $externalIds['imdb']);
        $this->assertSame(8.7, $result['imdb_rating']);
        $this->assertSame(['tmdb', 'imdb'], $result['sources']);
    }

    /**
     * The optional per-library `$priorityOverride` arg on resolve() drives the
     * source order for THAT call, OVERRIDING the injected global config (item 5).
     * The resolver is built with a TMDB-first global order; passing an IMDb-first
     * override flips the shared-field selection to IMDb — proving the override,
     * not the injected global, is honoured for the call.
     */
    public function testResolveHonoursPerCallPriorityOverride(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $imdb = $this->createMock(ImdbLookup::class);

        $tmdb->method('findByImdbId')
            ->willReturn(['id' => '603', 'title' => 'The Matrix', 'overview' => '', 'year' => 1999]);
        $tmdb->method('getDetails')->willReturn($this->tmdbDetails([
            'name' => 'TMDB Title',
            'overview' => 'TMDB overview (tmdb-only field).',
        ]));
        $imdb->method('getByImdbId')->willReturn($this->imdbRow([
            'title' => 'IMDb Title',
        ]));

        // Global (injected) order is TMDB-first — without the override the title
        // would resolve from TMDB.
        $resolver = new MovieMetadataResolver(
            $tmdb,
            $imdb,
            null,
            new PriorityConfig(['movie' => ['tmdb', 'imdb']]),
        );

        // Per-call IMDb-first override — this is what drives the selection now.
        $override = new PriorityConfig(['movie' => ['imdb', 'tmdb']]);
        $result = $resolver->resolve('The Matrix', 1999, ['imdb' => 'tt0133093'], $override);

        $this->assertNotNull($result);
        // Title comes from IMDb because the OVERRIDE order won (not the global).
        $this->assertSame('IMDb Title', $result['title']);
        // A TMDB-only field still falls through.
        $this->assertSame('TMDB overview (tmdb-only field).', $result['overview']);
    }

    /**
     * With no override arg the resolver falls back to the injected global config,
     * preserving existing behaviour (backward compatibility).
     */
    public function testResolveWithoutOverrideUsesInjectedGlobal(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $imdb = $this->createMock(ImdbLookup::class);

        $tmdb->method('findByImdbId')
            ->willReturn(['id' => '603', 'title' => 'The Matrix', 'overview' => '', 'year' => 1999]);
        $tmdb->method('getDetails')->willReturn($this->tmdbDetails(['name' => 'TMDB Title']));
        $imdb->method('getByImdbId')->willReturn($this->imdbRow(['title' => 'IMDb Title']));

        // Injected global is TMDB-first; no override passed → TMDB title wins.
        $resolver = new MovieMetadataResolver(
            $tmdb,
            $imdb,
            null,
            new PriorityConfig(['movie' => ['tmdb', 'imdb']]),
        );
        $result = $resolver->resolve('The Matrix', 1999, ['imdb' => 'tt0133093']);

        $this->assertNotNull($result);
        $this->assertSame('TMDB Title', $result['title']);
    }
}
