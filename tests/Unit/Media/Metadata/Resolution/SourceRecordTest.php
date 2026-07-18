<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Resolution;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\Resolution\SourceRecord;

/**
 * Unit tests for {@see SourceRecord}: the immutable per-provider canonical DTO.
 *
 * The central contract is "present vs. absent": a field the provider never
 * supplied must be ABSENT (so the Step 3.2 resolver can tell it apart from a
 * present-but-empty value). These tests pin presence reporting, typed
 * accessors, and the canonical field vocabulary.
 *
 * @since Feature 3 (metadata source priority)
 */
final class SourceRecordTest extends TestCase
{
    public function testSourceNameIsExposed(): void
    {
        $record = new SourceRecord('tmdb', ['title' => 'The Matrix']);
        $this->assertSame('tmdb', $record->source);
    }

    public function testHasReportsPresenceForSuppliedField(): void
    {
        $record = new SourceRecord('tmdb', ['title' => 'The Matrix']);
        $this->assertTrue($record->has('title'));
    }

    public function testHasReportsAbsenceForMissingField(): void
    {
        $record = new SourceRecord('tmdb', ['title' => 'The Matrix']);
        $this->assertFalse($record->has('overview'));
        $this->assertFalse($record->has('runtime'));
        $this->assertFalse($record->has('imdb_rating'));
    }

    public function testHasReportsPresentButEmptyValueAsPresent(): void
    {
        // The DTO must NOT conflate "supplied as empty" with "not supplied":
        // an explicit empty string / empty list still counts as present.
        $record = new SourceRecord('local', [
            'title' => '',
            'genres' => [],
        ]);
        $this->assertTrue($record->has('title'), 'empty string is still present');
        $this->assertTrue($record->has('genres'), 'empty list is still present');
        // ...and absent fields remain absent.
        $this->assertFalse($record->has('overview'));
    }

    public function testTypedAccessorsReturnNullWhenAbsent(): void
    {
        $record = new SourceRecord('imdb', []);
        $this->assertNull($record->title());
        $this->assertNull($record->overview());
        $this->assertNull($record->posterUrl());
        $this->assertNull($record->backdropUrl());
        $this->assertNull($record->genres());
        $this->assertNull($record->tags());
        $this->assertNull($record->year());
        $this->assertNull($record->runtime());
        $this->assertNull($record->officialRating());
        $this->assertNull($record->imdbRating());
        $this->assertNull($record->imdbVotes());
        $this->assertNull($record->actors());
        $this->assertNull($record->director());
        $this->assertNull($record->cast());
        $this->assertNull($record->crew());
        $this->assertNull($record->productionCompanies());
        $this->assertNull($record->studio());
        $this->assertNull($record->externalIds());
    }

    public function testTypedAccessorsReturnSuppliedValues(): void
    {
        $record = new SourceRecord('tmdb', [
            'title' => 'The Matrix',
            'overview' => 'A hacker learns the truth.',
            'poster_url' => 'https://image.tmdb.org/t/p/w500/poster.jpg',
            'backdrop_url' => 'https://image.tmdb.org/t/p/w500/back.jpg',
            'genres' => ['Action', 'Science Fiction'],
            'tags' => ['dystopia', 'artificial intelligence'],
            'year' => 1999,
            'runtime' => 136,
            'official_rating' => 'R',
            'imdb_rating' => 8.7,
            'imdb_votes' => 1900000,
            'actors' => ['Keanu Reeves', 'Carrie-Anne Moss'],
            'director' => 'Lana Wachowski',
            'cast' => [['name' => 'Keanu Reeves', 'role' => 'Neo']],
            'crew' => [['name' => 'Lana Wachowski', 'job' => 'Director']],
            'production_companies' => [['name' => 'Warner Bros.']],
            'studio' => 'Warner Bros.',
            'external_ids' => ['tmdb' => '603', 'imdb' => 'tt0133093'],
        ]);

        $this->assertSame('The Matrix', $record->title());
        $this->assertSame('A hacker learns the truth.', $record->overview());
        $this->assertSame('https://image.tmdb.org/t/p/w500/poster.jpg', $record->posterUrl());
        $this->assertSame('https://image.tmdb.org/t/p/w500/back.jpg', $record->backdropUrl());
        $this->assertSame(['Action', 'Science Fiction'], $record->genres());
        $this->assertSame(['dystopia', 'artificial intelligence'], $record->tags());
        $this->assertSame(1999, $record->year());
        $this->assertSame(136, $record->runtime());
        $this->assertSame('R', $record->officialRating());
        $this->assertSame(8.7, $record->imdbRating());
        $this->assertSame(1900000, $record->imdbVotes());
        $this->assertSame(['Keanu Reeves', 'Carrie-Anne Moss'], $record->actors());
        $this->assertSame('Lana Wachowski', $record->director());
        $this->assertSame([['name' => 'Keanu Reeves', 'role' => 'Neo']], $record->cast());
        $this->assertSame([['name' => 'Lana Wachowski', 'job' => 'Director']], $record->crew());
        $this->assertSame([['name' => 'Warner Bros.']], $record->productionCompanies());
        $this->assertSame('Warner Bros.', $record->studio());
        $this->assertSame(['tmdb' => '603', 'imdb' => 'tt0133093'], $record->externalIds());
    }

    public function testGetReturnsRawValueOrNull(): void
    {
        $record = new SourceRecord('tmdb', ['year' => 1999]);
        $this->assertSame(1999, $record->get('year'));
        $this->assertNull($record->get('runtime'));
    }

    public function testToArrayContainsOnlyPresentFields(): void
    {
        $fields = ['title' => 'The Matrix', 'year' => 1999];
        $record = new SourceRecord('tmdb', $fields);
        $this->assertSame($fields, $record->toArray());
        $this->assertArrayNotHasKey('overview', $record->toArray());
    }

    public function testCanonicalFieldsListIsComplete(): void
    {
        $expected = [
            'title',
            'overview',
            'poster_url',
            'backdrop_url',
            'genres',
            'tags',
            'year',
            'runtime',
            'official_rating',
            'imdb_rating',
            'imdb_votes',
            'actors',
            'director',
            'cast',
            'crew',
            'production_companies',
            'studio',
            'external_ids',
            'trailer_url',
            'trailer_key',
            'trailer_site',
        ];
        $this->assertSame($expected, SourceRecord::CANONICAL_FIELDS);
    }
}
