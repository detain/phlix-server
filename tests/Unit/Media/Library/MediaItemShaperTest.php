<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Media\Library\MediaItemShaper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Media\Library\MediaItemShaper
 */
final class MediaItemShaperTest extends TestCase
{
    public function testShapeExposesArticleStrippedSortTitleWhileKeepingDisplayName(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'movie-1',
            'name' => 'The Plot',
            'type' => 'movie',
            'metadata' => [],
        ]);

        // Display name is untouched; sort_title drops the leading article so the
        // client can file it under P.
        $this->assertSame('The Plot', $shaped['name']);
        $this->assertSame('Plot', $shaped['sort_title']);
    }

    public function testShapeSortTitleEqualsNameWhenNoLeadingArticle(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'movie-2',
            'name' => 'Plot Device',
            'type' => 'movie',
            'metadata' => [],
        ]);

        $this->assertSame('Plot Device', $shaped['sort_title']);
    }

    public function testShapeExposesMetadataAndHierarchyFields(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'ep-1',
            'name' => 'Pilot',
            'type' => 'episode',
            'parent_id' => 'season-1',
            'path' => '/tv/s01e01.mkv',
            'metadata' => [
                'poster_url' => 'https://image.tmdb.org/t/p/w500/p.jpg',
                'genres' => ['Drama'],
                'year' => 2001,
                'rating' => 'R',
                'runtime' => 42,
                'duration_seconds' => 2542,
                'overview' => 'Begins.',
                'season' => 1,
                'episode' => 1,
                'episode_title' => 'Pilot',
            ],
        ]);

        $this->assertSame('https://image.tmdb.org/t/p/w500/p.jpg', $shaped['poster_url']);
        $this->assertNotNull($shaped['poster_srcset']);
        $this->assertSame(['Drama'], $shaped['genres']);
        $this->assertSame(2001, $shaped['year']);
        $this->assertSame('R', $shaped['rating']);
        $this->assertSame(42, $shaped['runtime']);
        // Precise probed length in seconds, distinct from TMDB `runtime` minutes.
        $this->assertSame(2542, $shaped['duration']);
        $this->assertSame('Begins.', $shaped['overview']);
        $this->assertSame('season-1', $shaped['parent_id']);
        $this->assertSame(1, $shaped['season_number']);
        $this->assertSame(1, $shaped['episode_number']);
        $this->assertSame('Pilot', $shaped['episode_title']);
    }

    public function testShapeNormalisesActorObjectsToNameStrings(): void
    {
        // Legacy/interactive-match data stores TMDB actor OBJECTS; the shaper
        // must flatten them to names so the SPA cast chips render text, not
        // "[object Object]".
        $shaped = MediaItemShaper::shape([
            'id' => 'm-1',
            'name' => 'Film',
            'type' => 'movie',
            'metadata' => [
                'actors' => [
                    ['name' => 'Tom Hanks', 'role' => 'Woody', 'order' => 0],
                    ['name' => 'Tim Allen', 'role' => 'Buzz', 'order' => 1],
                ],
            ],
        ]);

        $this->assertSame(['Tom Hanks', 'Tim Allen'], $shaped['actors']);
    }

    public function testShapeKeepsActorNameStringsAndDropsBlanksAndDupes(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm-2',
            'name' => 'Film',
            'type' => 'movie',
            'metadata' => ['actors' => ['Sigourney Weaver', '', 'Sigourney Weaver']],
        ]);

        $this->assertSame(['Sigourney Weaver'], $shaped['actors']);
    }

    public function testShapeCoercesMalformedTypeAndRatingToSchemaSafeValues(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'x',
            'name' => '',
            'type' => 'bogus',
            'metadata' => ['rating' => 'Z++'],
        ]);

        $this->assertSame('x', $shaped['name']); // empty name falls back to id
        $this->assertSame('movie', $shaped['type']); // invalid type → movie
        $this->assertNull($shaped['rating']); // invalid rating → null
    }

    public function testShapeYieldsNullSrcsetForNonTmdbPoster(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm', 'name' => 'M', 'type' => 'movie',
            'metadata' => ['poster_url' => 'http://example.com/p.jpg'],
        ]);

        $this->assertSame('http://example.com/p.jpg', $shaped['poster_url']);
        $this->assertNull($shaped['poster_srcset']);
    }

    public function testShapeDetailMergesStreamsAndPreservesRawExtras(): void
    {
        $streams = [['stream_index' => 0, 'stream_type' => 'video']];
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'M',
            'type' => 'movie',
            'library_id' => 'lib-1',
            'intro_start_seconds' => 7,
            'chapters_json' => '[]',
            'metadata' => ['poster_url' => 'https://image.tmdb.org/t/p/w500/p.jpg'],
        ], $streams);

        // Enriched keys present...
        $this->assertSame('https://image.tmdb.org/t/p/w500/p.jpg', $shaped['poster_url']);
        // ...raw extras the list shape omits are preserved...
        $this->assertSame('lib-1', $shaped['library_id']);
        $this->assertSame(7, $shaped['intro_start_seconds']);
        $this->assertSame('[]', $shaped['chapters_json']);
        // ...and streams are attached.
        $this->assertSame($streams, $shaped['streams']);
    }
}
