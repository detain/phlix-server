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
        $this->assertSame('Begins.', $shaped['overview']);
        $this->assertSame('season-1', $shaped['parent_id']);
        $this->assertSame(1, $shaped['season_number']);
        $this->assertSame(1, $shaped['episode_number']);
        $this->assertSame('Pilot', $shaped['episode_title']);
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
