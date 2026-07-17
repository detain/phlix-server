<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Resolution;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\Resolution\FieldMappers;
use Phlix\Media\Metadata\Resolution\SourceRecord;

/**
 * Unit tests for {@see FieldMappers}: per-provider raw→canonical normalization.
 *
 * Payloads here mirror the REAL provider output shapes:
 *  - TMDB:  {@see \Phlix\Media\Metadata\TmdbProvider::formatMovieDetails()} /
 *           `formatTvDetails()` (`name`, `poster_path`, `runtime_ticks`, …).
 *  - IMDb:  {@see \Phlix\Media\Metadata\Imdb\ImdbLookup} row
 *           (`title`, `average_rating`, `num_votes`, `runtime_minutes`) and the
 *           {@see \Phlix\Media\Metadata\ImdbProvider::getDetails()} shape
 *           (`rating`, `runtime`).
 *  - TVDB:  {@see \Phlix\Media\Metadata\TvdbProvider::getDetails()}
 *           (`name`, `genre`, `runtime`, `rating`, `network`).
 *  - NFO:   {@see \Phlix\Media\Metadata\LocalNfoProvider} (`name`, `mpaa`,
 *           `studios[]`, `directors[]`, `external_ids{}`).
 *
 * The recurring assertion is "missing raw key stays ABSENT" (asserted via
 * `has()`, NOT `=== null`), plus the unit conversions (ticks→minutes).
 *
 * @since Feature 3 (metadata source priority)
 */
final class FieldMappersTest extends TestCase
{
    public function testFromTmdbMovieNormalizesCanonicalKeys(): void
    {
        // Shape from TmdbProvider::formatMovieDetails().
        $raw = [
            'name' => 'The Matrix',
            'original_name' => 'The Matrix',
            'overview' => 'A hacker learns the truth.',
            'official_rating' => null,
            'vote_average' => 8.2,
            'year' => '1999',
            'runtime_ticks' => 136 * 600000000,
            'genres' => ['Action', 'Science Fiction'],
            'tags' => ['dystopia', 'artificial intelligence'],
            'studio' => 'Warner Bros.',
            'imdb_id' => 'tt0133093',
            'tmdb_id' => '603',
            'poster_path' => '/poster.jpg',
            'backdrop_path' => '/back.jpg',
            'actors' => [
                ['name' => 'Keanu Reeves', 'role' => 'Neo', 'order' => 0],
                ['name' => 'Carrie-Anne Moss', 'role' => 'Trinity', 'order' => 1],
            ],
            'cast' => [['name' => 'Keanu Reeves', 'role' => 'Neo', 'profile_url' => null]],
            'crew' => [['name' => 'Lana Wachowski', 'job' => 'Director', 'profile_url' => null]],
            'production_companies' => [['name' => 'Warner Bros.', 'logo_url' => null]],
            'director' => 'Lana Wachowski',
        ];

        $record = FieldMappers::fromTmdb($raw);

        $this->assertSame('tmdb', $record->source);
        $this->assertSame('The Matrix', $record->title());
        $this->assertSame('A hacker learns the truth.', $record->overview());
        $this->assertSame('https://image.tmdb.org/t/p/w500/poster.jpg', $record->posterUrl());
        $this->assertSame('https://image.tmdb.org/t/p/w500/back.jpg', $record->backdropUrl());
        $this->assertSame(['Action', 'Science Fiction'], $record->genres());
        $this->assertSame(['dystopia', 'artificial intelligence'], $record->tags());
        $this->assertSame(1999, $record->year());
        // ticks → minutes
        $this->assertSame(136, $record->runtime());
        $this->assertSame(['Keanu Reeves', 'Carrie-Anne Moss'], $record->actors());
        $this->assertSame('Lana Wachowski', $record->director());
        $this->assertSame('Warner Bros.', $record->studio());
        $this->assertSame(['tmdb' => '603', 'imdb' => 'tt0133093'], $record->externalIds());
        $this->assertNotNull($record->cast());
        $this->assertNotNull($record->crew());
        $this->assertNotNull($record->productionCompanies());

        // official_rating was null in a movie payload → ABSENT (not present-null).
        $this->assertFalse($record->has('official_rating'));
        // imdb_rating/imdb_votes are not TMDB-supplied → absent.
        $this->assertFalse($record->has('imdb_rating'));
        $this->assertFalse($record->has('imdb_votes'));
    }

    public function testTagsIsCanonicalAndRoundTripsThroughFromTmdb(): void
    {
        // `tags` must be part of the fixed canonical vocabulary.
        $this->assertContains('tags', SourceRecord::CANONICAL_FIELDS);

        // De-duplicated, non-empty tags survive the TMDB → canonical mapping.
        $record = FieldMappers::fromTmdb([
            'name' => 'Movie',
            'tags' => ['dystopia', 'dystopia', 'ai', ''],
        ]);

        $this->assertTrue($record->has('tags'));
        $this->assertSame(['dystopia', 'ai'], $record->tags());
    }

    public function testFromTmdbWithoutTagsLeavesTagsAbsent(): void
    {
        $record = FieldMappers::fromTmdb(['name' => 'Movie']);

        $this->assertFalse($record->has('tags'), 'missing tags stays absent (never null-filled)');
        $this->assertNull($record->tags());
    }

    public function testFromImdbAndTvdbDoNotSupplyTags(): void
    {
        $imdb = FieldMappers::fromImdb(['title' => 'Movie', 'imdb_id' => 'tt1']);
        $tvdb = FieldMappers::fromTvdb(['name' => 'Show', 'tvdb_id' => '1']);

        $this->assertFalse($imdb->has('tags'), 'IMDb does not supply tags');
        $this->assertFalse($tvdb->has('tags'), 'TVDB does not supply tags');
    }

    public function testFromTmdbTvUsesOfficialRatingAndRuntimeFallback(): void
    {
        // Shape from TmdbProvider::formatTvDetails(): official_rating present,
        // no runtime_ticks, actors already flat names.
        $raw = [
            'name' => 'Breaking Bad',
            'overview' => 'A chemistry teacher turns to crime.',
            'official_rating' => 'TV-MA',
            'year' => 2008,
            'runtime' => 49,
            'genres' => ['Drama', 'Crime'],
            'actors' => ['Bryan Cranston', 'Aaron Paul'],
            'studio' => 'AMC',
            'tmdb_id' => '1396',
            'imdb_id' => 'tt0903747',
            'poster_path' => '/bb.jpg',
        ];

        $record = FieldMappers::fromTmdb($raw);

        $this->assertSame('Breaking Bad', $record->title());
        $this->assertSame('TV-MA', $record->officialRating());
        // No runtime_ticks → falls back to plain `runtime` minutes.
        $this->assertSame(49, $record->runtime());
        $this->assertSame(['Bryan Cranston', 'Aaron Paul'], $record->actors());
        $this->assertSame(['tmdb' => '1396', 'imdb' => 'tt0903747'], $record->externalIds());
        $this->assertFalse($record->has('backdrop_url'));
    }

    public function testFromTmdbZeroTicksLeavesRuntimeAbsent(): void
    {
        $record = FieldMappers::fromTmdb(['name' => 'X', 'runtime_ticks' => 0]);
        $this->assertFalse($record->has('runtime'));
    }

    public function testFromTmdbMissingTitleIsAbsent(): void
    {
        $record = FieldMappers::fromTmdb(['overview' => 'just an overview']);
        $this->assertFalse($record->has('title'));
        $this->assertTrue($record->has('overview'));
    }

    public function testFromImdbDatasetRowNormalizesKeys(): void
    {
        // Shape from ImdbLookup::lookup()/getByImdbId().
        $raw = [
            'imdb_id' => 'tt0133093',
            'title' => 'The Matrix',
            'year' => 1999,
            'genres' => ['Action', 'Sci-Fi'],
            'average_rating' => 8.7,
            'num_votes' => 1900000,
            'runtime_minutes' => 136,
        ];

        $record = FieldMappers::fromImdb($raw);

        $this->assertSame('imdb', $record->source);
        $this->assertSame('The Matrix', $record->title());
        $this->assertSame(1999, $record->year());
        $this->assertSame(['Action', 'Sci-Fi'], $record->genres());
        // average_rating → imdb_rating (float)
        $this->assertSame(8.7, $record->imdbRating());
        $this->assertSame(1900000, $record->imdbVotes());
        // runtime_minutes → runtime
        $this->assertSame(136, $record->runtime());
        $this->assertSame(['imdb' => 'tt0133093'], $record->externalIds());

        // IMDb supplies no overview/poster/cast → absent.
        $this->assertFalse($record->has('overview'));
        $this->assertFalse($record->has('poster_url'));
        $this->assertFalse($record->has('director'));
    }

    public function testFromImdbProviderGetDetailsShape(): void
    {
        // Shape from ImdbProvider::getDetails(): `rating`/`runtime` keys.
        $raw = [
            'imdb_id' => 'tt0133093',
            'title' => 'The Matrix',
            'external_ids' => ['imdb' => 'tt0133093'],
            'rating' => 8.7,
            'num_votes' => 1900000,
            'genres' => ['Action'],
            'year' => 1999,
            'runtime' => 136,
        ];

        $record = FieldMappers::fromImdb($raw);

        // `rating` (not average_rating) → imdb_rating; `runtime` → runtime.
        $this->assertSame(8.7, $record->imdbRating());
        $this->assertSame(136, $record->runtime());
        $this->assertSame(['imdb' => 'tt0133093'], $record->externalIds());
    }

    public function testFromImdbNullRatingAndVotesLeaveFieldsAbsent(): void
    {
        $raw = [
            'imdb_id' => 'tt0000001',
            'title' => 'Obscure',
            'year' => null,
            'genres' => [],
            'average_rating' => null,
            'num_votes' => null,
            'runtime_minutes' => null,
        ];

        $record = FieldMappers::fromImdb($raw);

        $this->assertSame('Obscure', $record->title());
        $this->assertFalse($record->has('year'), 'null year stays absent');
        $this->assertFalse($record->has('genres'), 'empty genres list stays absent');
        $this->assertFalse($record->has('imdb_rating'), 'null rating stays absent (not present-null)');
        $this->assertFalse($record->has('imdb_votes'));
        $this->assertFalse($record->has('runtime'));
    }

    public function testFromTvdbNormalizesKeys(): void
    {
        // Shape from TvdbProvider::getDetails().
        $raw = [
            'name' => 'Breaking Bad',
            'original_name' => 'Breaking Bad',
            'overview' => 'A chemistry teacher turns to crime.',
            'year' => '2008',
            'first_aired' => '2008-01-20',
            'network' => 'AMC',
            'genre' => ['Drama', 'Crime', 'Thriller'],
            'rating' => 9.4,
            'runtime' => 49,
            'status' => 'Ended',
            'imdb_id' => 'tt0903747',
            'tvdb_id' => '81189',
            'actors' => [
                ['name' => 'Bryan Cranston', 'role' => 'Walter White', 'image_url' => null, 'sort_order' => 0],
                ['name' => 'Aaron Paul', 'role' => 'Jesse Pinkman', 'image_url' => null, 'sort_order' => 1],
            ],
            'episodes' => [],
        ];

        $record = FieldMappers::fromTvdb($raw);

        $this->assertSame('tvdb', $record->source);
        $this->assertSame('Breaking Bad', $record->title());
        $this->assertSame('A chemistry teacher turns to crime.', $record->overview());
        // TVDB singular `genre` → canonical genres.
        $this->assertSame(['Drama', 'Crime', 'Thriller'], $record->genres());
        $this->assertSame(2008, $record->year());
        $this->assertSame(49, $record->runtime());
        // TVDB site `rating` → imdb_rating slot (only numeric rating it exposes).
        $this->assertSame(9.4, $record->imdbRating());
        // TVDB `network` → studio.
        $this->assertSame('AMC', $record->studio());
        $this->assertSame(['Bryan Cranston', 'Aaron Paul'], $record->actors());
        $this->assertSame(['tvdb' => '81189', 'imdb' => 'tt0903747'], $record->externalIds());

        // TVDB has no poster_url / director in this shape → absent.
        $this->assertFalse($record->has('poster_url'));
        $this->assertFalse($record->has('director'));
    }

    public function testFromFanartIsArtworkOnly(): void
    {
        // Fanart.tv image-bucket shape (formatImages()).
        $raw = [
            'name' => 'The Matrix',
            'posters' => [
                ['url' => 'https://assets.fanart.tv/poster1.jpg', 'type' => 'poster'],
            ],
            'backdrops' => [
                ['url' => 'https://assets.fanart.tv/back1.jpg', 'type' => 'backdrop'],
            ],
        ];

        $record = FieldMappers::fromFanart($raw);

        $this->assertSame('fanart', $record->source);
        $this->assertSame('The Matrix', $record->title());
        $this->assertSame('https://assets.fanart.tv/poster1.jpg', $record->posterUrl());
        $this->assertSame('https://assets.fanart.tv/back1.jpg', $record->backdropUrl());

        // Fanart carries no descriptive text/ratings → those stay absent.
        $this->assertFalse($record->has('overview'));
        $this->assertFalse($record->has('genres'));
        $this->assertFalse($record->has('imdb_rating'));
        $this->assertFalse($record->has('runtime'));
    }

    public function testFromFanartWithoutImagesLeavesImageFieldsAbsent(): void
    {
        $record = FieldMappers::fromFanart(['name' => 'Lonely']);
        $this->assertSame('Lonely', $record->title());
        $this->assertFalse($record->has('poster_url'));
        $this->assertFalse($record->has('backdrop_url'));
    }

    public function testFromLocalNfoNormalizesKeys(): void
    {
        // Shape from LocalNfoProvider::extractMovieFromXml() (after array_filter).
        $raw = [
            'type' => 'movie',
            'name' => 'The Matrix',
            'original_name' => 'The Matrix',
            'overview' => 'A hacker learns the truth.',
            'year' => 1999,
            'rating' => 8.7,
            'votes' => 1900000,
            'runtime' => 136,
            'mpaa' => 'R',
            'genres' => ['Action', 'Sci-Fi'],
            'studios' => ['Warner Bros.', 'Village Roadshow'],
            'directors' => ['Lana Wachowski', 'Lilly Wachowski'],
            'actors' => [
                ['name' => 'Keanu Reeves', 'role' => 'Neo', 'order' => 0],
            ],
            'external_ids' => ['tmdb' => '603', 'imdb' => 'tt0133093'],
        ];

        $record = FieldMappers::fromLocalNfo($raw);

        $this->assertSame('local', $record->source);
        $this->assertSame('The Matrix', $record->title());
        $this->assertSame('A hacker learns the truth.', $record->overview());
        $this->assertSame(1999, $record->year());
        $this->assertSame(136, $record->runtime());
        // mpaa → official_rating
        $this->assertSame('R', $record->officialRating());
        // rating → imdb_rating, votes → imdb_votes
        $this->assertSame(8.7, $record->imdbRating());
        $this->assertSame(1900000, $record->imdbVotes());
        $this->assertSame(['Action', 'Sci-Fi'], $record->genres());
        // studios[0] → studio, directors[0] → director
        $this->assertSame('Warner Bros.', $record->studio());
        $this->assertSame('Lana Wachowski', $record->director());
        $this->assertSame(['Keanu Reeves'], $record->actors());
        $this->assertSame(['tmdb' => '603', 'imdb' => 'tt0133093'], $record->externalIds());
    }

    public function testFromLocalNfoSimpleFormatOnlyHasExternalIds(): void
    {
        // Shape from LocalNfoProvider::parseSimpleNfo() (id-only NFO).
        $raw = [
            'type' => 'movie',
            'external_ids' => ['tmdb' => '603', 'imdb' => 'tt0133093'],
        ];

        $record = FieldMappers::fromLocalNfo($raw);

        $this->assertSame(['tmdb' => '603', 'imdb' => 'tt0133093'], $record->externalIds());
        $this->assertFalse($record->has('title'));
        $this->assertFalse($record->has('runtime'));
        $this->assertFalse($record->has('imdb_rating'));
    }

    public function testFromLocalNfoBlankExternalIdsDropped(): void
    {
        // NFO XML often leaves an empty tmdb/imdb tag; blanks must not appear.
        $raw = [
            'name' => 'X',
            'external_ids' => ['tmdb' => '', 'imdb' => 'tt0000001'],
        ];

        $record = FieldMappers::fromLocalNfo($raw);
        $this->assertSame(['imdb' => 'tt0000001'], $record->externalIds());
    }

    public function testFromGenericPassthroughCanonicalKeys(): void
    {
        $raw = [
            'title' => 'Plugin Movie',
            'overview' => 'From a metadata plugin.',
            'poster_url' => 'https://example.test/p.jpg',
            'backdrop_url' => 'https://example.test/b.jpg',
            'genres' => ['Documentary'],
            'year' => 2020,
            'runtime' => 90,
            'official_rating' => 'PG',
            'imdb_rating' => 7.1,
            'imdb_votes' => 1234,
            'actors' => ['Some Person'],
            'director' => 'A Director',
            'cast' => [['name' => 'Some Person']],
            'crew' => [['name' => 'A Director', 'job' => 'Director']],
            'production_companies' => [['name' => 'Indie Co']],
            'studio' => 'Indie Co',
            'external_ids' => ['custom' => 'abc123'],
        ];

        $record = FieldMappers::fromGeneric('myplugin', $raw);

        $this->assertSame('myplugin', $record->source);
        $this->assertSame('Plugin Movie', $record->title());
        $this->assertSame('https://example.test/p.jpg', $record->posterUrl());
        $this->assertSame(90, $record->runtime());
        $this->assertSame('PG', $record->officialRating());
        $this->assertSame(7.1, $record->imdbRating());
        $this->assertSame(1234, $record->imdbVotes());
        $this->assertSame('A Director', $record->director());
        $this->assertSame(['custom' => 'abc123'], $record->externalIds());
    }

    public function testFromGenericAcceptsNameAliasForTitle(): void
    {
        $record = FieldMappers::fromGeneric('plugin', ['name' => 'Aliased']);
        $this->assertSame('Aliased', $record->title());
    }

    public function testFromGenericOmitsMissingFields(): void
    {
        $record = FieldMappers::fromGeneric('plugin', ['title' => 'Sparse']);
        $this->assertSame('Sparse', $record->title());
        foreach (['overview', 'poster_url', 'genres', 'year', 'runtime', 'imdb_rating', 'external_ids'] as $field) {
            $this->assertFalse($record->has($field), "field {$field} must stay absent");
        }
    }
}
