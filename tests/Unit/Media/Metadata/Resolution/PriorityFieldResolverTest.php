<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Resolution;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\Resolution\FieldMappers;
use Phlix\Media\Metadata\Resolution\PriorityFieldResolver;
use Phlix\Media\Metadata\Resolution\SourceRecord;

/**
 * Unit tests for {@see PriorityFieldResolver}: configurable per-field
 * first-non-empty merge across canonical {@see SourceRecord}s.
 *
 * The headline guarantee is behavior-preservation: under the default
 * `['tmdb','imdb']` order the resolver reproduces the live
 * {@see \Phlix\Media\Metadata\MovieMetadataResolver::merge()} per-field source
 * choices. Reordering to `['imdb','tmdb']` flips the shared fields to the imdb
 * source. external_ids unions; genres support first/union modes; the
 * present-vs-absent contract distinguishes "missing" from "present-but-empty".
 *
 * @since Feature 3 (metadata source priority)
 */
final class PriorityFieldResolverTest extends TestCase
{
    private PriorityFieldResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PriorityFieldResolver();
    }

    /**
     * A representative pair of records modelling the SAME shapes the live movie
     * resolver merges: a formatted TMDB details payload + an offline IMDb row.
     *
     * @return array{tmdb: SourceRecord, imdb: SourceRecord}
     */
    private function moviePair(): array
    {
        $tmdb = FieldMappers::fromTmdb([
            'name' => 'The Matrix',
            'overview' => 'A hacker learns the truth.',
            'poster_path' => '/matrix-poster.jpg',
            'backdrop_path' => '/matrix-backdrop.jpg',
            'genres' => ['Action', 'Science Fiction'],
            'year' => 1999,
            'runtime_ticks' => 136 * 600000000, // 136 minutes
            'actors' => [['name' => 'Keanu Reeves'], ['name' => 'Laurence Fishburne']],
            'director' => 'Lana Wachowski',
            'cast' => [['name' => 'Keanu Reeves', 'character' => 'Neo']],
            'crew' => [['name' => 'Lana Wachowski', 'job' => 'Director']],
            'production_companies' => [['name' => 'Warner Bros.']],
            'studio' => 'Warner Bros.',
            'tmdb_id' => '603',
            'imdb_id' => 'tt0133093',
        ]);

        $imdb = FieldMappers::fromImdb([
            'title' => 'The Matrix (IMDb)',
            'year' => 1998, // intentionally different to prove tmdb-first
            'genres' => ['Sci-Fi'],
            'runtime_minutes' => 150, // intentionally different
            'average_rating' => 8.7,
            'num_votes' => 1900000,
            'imdb_id' => 'tt0133093',
        ]);

        return ['tmdb' => $tmdb, 'imdb' => $imdb];
    }

    // ----- behavior preservation under the default [tmdb, imdb] order -----

    public function testDefaultOrderReproducesLiveMovieMergePerField(): void
    {
        $pair = $this->moviePair();
        $result = $this->resolver->resolve($pair, ['tmdb', 'imdb']);

        // tmdb-first scalars / lists.
        $this->assertSame('The Matrix', $result['title']);
        $this->assertSame('A hacker learns the truth.', $result['overview']);
        $this->assertSame('https://image.tmdb.org/t/p/w500/matrix-poster.jpg', $result['poster_url']);
        $this->assertSame('https://image.tmdb.org/t/p/w500/matrix-backdrop.jpg', $result['backdrop_url']);
        $this->assertSame(['Action', 'Science Fiction'], $result['genres']);
        $this->assertSame(1999, $result['year']); // tmdb wins, not imdb 1998
        $this->assertSame(136, $result['runtime']); // tmdb ticks, not imdb 150
        $this->assertSame(['Keanu Reeves', 'Laurence Fishburne'], $result['actors']);
        $this->assertSame('Lana Wachowski', $result['director']);
        $this->assertSame('Warner Bros.', $result['studio']);
        $this->assertSame([['name' => 'Keanu Reeves', 'character' => 'Neo']], $result['cast']);
        $this->assertSame([['name' => 'Lana Wachowski', 'job' => 'Director']], $result['crew']);
        $this->assertSame([['name' => 'Warner Bros.']], $result['production_companies']);

        // imdb-only ratings.
        $this->assertSame(8.7, $result['imdb_rating']);
        $this->assertSame(1900000, $result['imdb_votes']);

        // external_ids union, sources provenance.
        $this->assertSame(['tmdb' => '603', 'imdb' => 'tt0133093'], $result['external_ids']);
        $this->assertSame(['tmdb', 'imdb'], $result['sources']);
    }

    public function testReorderToImdbFirstFlipsSharedFields(): void
    {
        $pair = $this->moviePair();
        $result = $this->resolver->resolve($pair, ['imdb', 'tmdb']);

        // Shared fields now come from imdb.
        $this->assertSame('The Matrix (IMDb)', $result['title']);
        $this->assertSame(1998, $result['year']);
        $this->assertSame(150, $result['runtime']);
        $this->assertSame(['Sci-Fi'], $result['genres']);

        // tmdb-only fields still present (imdb lacks them → fall through).
        $this->assertSame('A hacker learns the truth.', $result['overview']);
        $this->assertSame('https://image.tmdb.org/t/p/w500/matrix-poster.jpg', $result['poster_url']);
        $this->assertSame('Lana Wachowski', $result['director']);

        // imdb-only ratings unchanged.
        $this->assertSame(8.7, $result['imdb_rating']);

        // sources reflect the new order.
        $this->assertSame(['imdb', 'tmdb'], $result['sources']);
        // external_ids still the union; imdb-first so imdb's id wins a collision (none here).
        $this->assertSame(['imdb' => 'tt0133093', 'tmdb' => '603'], $result['external_ids']);
    }

    // ----- present-vs-absent: field only in source #2 used when #1 lacks it -----

    public function testFieldOnlyInSecondSourceIsUsedWhenFirstLacksIt(): void
    {
        // tmdb record HAS no studio; the second source supplies it.
        $first = new SourceRecord('tmdb', ['title' => 'X']);
        $second = new SourceRecord('imdb', ['title' => 'X', 'studio' => 'Fallback Studio']);

        $result = $this->resolver->resolve([$first, $second], ['tmdb', 'imdb']);

        $this->assertSame('Fallback Studio', $result['studio']);
    }

    public function testPresentButEmptyStringIsSkippedInFavourOfNextSource(): void
    {
        // First source has the key but it is an empty string — must be skipped,
        // distinguishing present-but-empty from a real value (not just absent).
        $first = new SourceRecord('tmdb', ['title' => '']);
        $second = new SourceRecord('imdb', ['title' => 'Real Title']);

        $result = $this->resolver->resolve([$first, $second], ['tmdb', 'imdb']);

        $this->assertSame('Real Title', $result['title']);
    }

    public function testPresentButEmptyListIsSkippedInFavourOfNextSource(): void
    {
        $first = new SourceRecord('tmdb', ['genres' => []]);
        $second = new SourceRecord('imdb', ['genres' => ['Drama']]);

        $result = $this->resolver->resolve([$first, $second], ['tmdb', 'imdb']);

        $this->assertSame(['Drama'], $result['genres']);
    }

    public function testWhitespaceStringIsNotTreatedAsEmpty(): void
    {
        // asNullableString does NOT trim, so a whitespace title is a real value;
        // the resolver mirrors that to stay behavior-preserving.
        $first = new SourceRecord('tmdb', ['title' => '   ']);
        $second = new SourceRecord('imdb', ['title' => 'Should Not Win']);

        $result = $this->resolver->resolve([$first, $second], ['tmdb', 'imdb']);

        $this->assertSame('   ', $result['title']);
    }

    public function testGenuineZeroNumericIsKeptNotTreatedAsEmpty(): void
    {
        // A present 0 year/runtime is a legitimate value, not "empty".
        $first = new SourceRecord('tmdb', ['year' => 0, 'runtime' => 0]);
        $second = new SourceRecord('imdb', ['year' => 1999, 'runtime' => 120]);

        $result = $this->resolver->resolve([$first, $second], ['tmdb', 'imdb']);

        $this->assertSame(0, $result['year']);
        $this->assertSame(0, $result['runtime']);
    }

    public function testGenuineZeroFloatRatingIsKept(): void
    {
        $first = new SourceRecord('imdb', ['imdb_rating' => 0.0]);
        $second = new SourceRecord('tmdb', ['imdb_rating' => 7.5]);

        $result = $this->resolver->resolve([$first, $second], ['imdb', 'tmdb']);

        $this->assertSame(0.0, $result['imdb_rating']);
    }

    // ----- external_ids union + collision -----

    public function testExternalIdsUnionFromBothSources(): void
    {
        $first = new SourceRecord('tmdb', ['external_ids' => ['tmdb' => '603']]);
        $second = new SourceRecord('imdb', ['external_ids' => ['imdb' => 'tt0133093']]);

        $result = $this->resolver->resolve([$first, $second], ['tmdb', 'imdb']);

        $this->assertSame(['tmdb' => '603', 'imdb' => 'tt0133093'], $result['external_ids']);
    }

    public function testExternalIdsCollisionEarlierSourceWins(): void
    {
        $first = new SourceRecord('tmdb', ['external_ids' => ['imdb' => 'tt-from-tmdb']]);
        $second = new SourceRecord('imdb', ['external_ids' => ['imdb' => 'tt-from-imdb', 'tvdb' => '42']]);

        $result = $this->resolver->resolve([$first, $second], ['tmdb', 'imdb']);

        // tmdb is earlier → its imdb id wins; tvdb only in second → added.
        $this->assertSame(['imdb' => 'tt-from-tmdb', 'tvdb' => '42'], $result['external_ids']);
    }

    public function testExternalIdsBlankValueDropped(): void
    {
        $first = new SourceRecord('tmdb', ['external_ids' => ['imdb' => '', 'tmdb' => '603']]);
        $second = new SourceRecord('imdb', ['external_ids' => ['imdb' => 'tt0133093']]);

        $result = $this->resolver->resolve([$first, $second], ['tmdb', 'imdb']);

        // blank tmdb.imdb skipped → imdb's value fills it.
        $this->assertSame(['tmdb' => '603', 'imdb' => 'tt0133093'], $result['external_ids']);
    }

    // ----- genres modes -----

    public function testGenresFirstModeIsDefaultAndTakesFirstNonEmpty(): void
    {
        $first = new SourceRecord('tmdb', ['genres' => ['Action', 'Sci-Fi']]);
        $second = new SourceRecord('imdb', ['genres' => ['Drama']]);

        $result = $this->resolver->resolve([$first, $second], ['tmdb', 'imdb']);

        $this->assertSame(['Action', 'Sci-Fi'], $result['genres']);
    }

    public function testGenresUnionModeMergesAndDedupes(): void
    {
        $first = new SourceRecord('tmdb', ['genres' => ['Action', 'Sci-Fi']]);
        $second = new SourceRecord('imdb', ['genres' => ['Drama', 'Sci-Fi']]);

        $result = $this->resolver->resolve(
            [$first, $second],
            ['tmdb', 'imdb'],
            PriorityFieldResolver::GENRES_UNION
        );

        $this->assertSame(['Action', 'Sci-Fi', 'Drama'], $result['genres']);
    }

    public function testGenresUnionModeRespectsOrder(): void
    {
        $first = new SourceRecord('tmdb', ['genres' => ['Action', 'Sci-Fi']]);
        $second = new SourceRecord('imdb', ['genres' => ['Drama', 'Sci-Fi']]);

        $result = $this->resolver->resolve(
            [$first, $second],
            ['imdb', 'tmdb'],
            PriorityFieldResolver::GENRES_UNION
        );

        $this->assertSame(['Drama', 'Sci-Fi', 'Action'], $result['genres']);
    }

    public function testGenresUnionOmittedWhenNoSourceHasGenres(): void
    {
        $first = new SourceRecord('tmdb', ['title' => 'X']);
        $second = new SourceRecord('imdb', ['title' => 'X']);

        $result = $this->resolver->resolve(
            [$first, $second],
            ['tmdb', 'imdb'],
            PriorityFieldResolver::GENRES_UNION
        );

        $this->assertArrayNotHasKey('genres', $result);
    }

    // ----- order handling / graceful skips -----

    public function testSourceNameInOrderWithNoRecordIsSkippedGracefully(): void
    {
        // Order names tvdb + tmdb; only tmdb supplied.
        $tmdb = new SourceRecord('tmdb', ['title' => 'Only TMDB']);

        $result = $this->resolver->resolve([$tmdb], ['tvdb', 'tmdb', 'imdb']);

        $this->assertSame('Only TMDB', $result['title']);
        $this->assertSame(['tmdb'], $result['sources']);
    }

    public function testRecordWithSourceNotInOrderIsIgnored(): void
    {
        $tmdb = new SourceRecord('tmdb', ['title' => 'Kept']);
        $stray = new SourceRecord('mystery', ['title' => 'Ignored', 'studio' => 'Ignored Studio']);

        $result = $this->resolver->resolve([$tmdb, $stray], ['tmdb', 'imdb']);

        $this->assertSame('Kept', $result['title']);
        $this->assertArrayNotHasKey('studio', $result);
        $this->assertSame(['tmdb'], $result['sources']);
    }

    public function testEmptyRecordsProduceOnlyEmptySources(): void
    {
        $result = $this->resolver->resolve([], ['tmdb', 'imdb']);

        $this->assertSame(['sources' => []], $result);
    }

    public function testFieldlessRecordIsNotCountedAsContributingSource(): void
    {
        $empty = new SourceRecord('tmdb', []);
        $real = new SourceRecord('imdb', ['title' => 'Has Data']);

        $result = $this->resolver->resolve([$empty, $real], ['tmdb', 'imdb']);

        $this->assertSame(['imdb'], $result['sources']);
        $this->assertSame('Has Data', $result['title']);
    }

    public function testIteratorRecordsAreAccepted(): void
    {
        $records = (static function () {
            yield new SourceRecord('tmdb', ['title' => 'From Generator']);
            yield new SourceRecord('imdb', ['imdb_rating' => 9.0]);
        })();

        $result = $this->resolver->resolve($records, ['tmdb', 'imdb']);

        $this->assertSame('From Generator', $result['title']);
        $this->assertSame(9.0, $result['imdb_rating']);
    }

    public function testAbsentFieldsAreOmittedFromResult(): void
    {
        $record = new SourceRecord('tmdb', ['title' => 'Sparse']);

        $result = $this->resolver->resolve([$record], ['tmdb']);

        $this->assertArrayHasKey('title', $result);
        $this->assertArrayNotHasKey('overview', $result);
        $this->assertArrayNotHasKey('runtime', $result);
        $this->assertArrayNotHasKey('external_ids', $result);
        $this->assertArrayHasKey('sources', $result);
    }
}
