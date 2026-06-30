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

    public function testShapeDetailExposesNormalizedCastCrewCompaniesAndStudio(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'The Matrix',
            'type' => 'movie',
            'metadata' => [
                'actors' => ['Keanu Reeves', 'Carrie-Anne Moss'],
                'director' => 'Lana Wachowski',
                'cast' => [
                    ['name' => 'Keanu Reeves', 'role' => 'Neo', 'profile_url' => 'https://i/w185/k.jpg'],
                    ['name' => '', 'role' => 'Extra'], // dropped (no name)
                ],
                'crew' => [
                    ['name' => 'Lana Wachowski', 'job' => 'Director', 'profile_url' => null],
                ],
                'production_companies' => [
                    ['name' => 'Warner Bros.', 'logo_url' => 'https://i/w185/wb.png', 'origin_country' => 'US'],
                ],
                'studio' => 'Warner Bros.',
            ],
        ], []);

        // actors stays a flat list of names; director stays a string.
        $this->assertSame(['Keanu Reeves', 'Carrie-Anne Moss'], $shaped['actors']);
        $this->assertSame('Lana Wachowski', $shaped['director']);

        // Rich blocks normalized, nameless entries dropped.
        $this->assertCount(1, $shaped['cast']);
        $this->assertSame([
            'name' => 'Keanu Reeves',
            'role' => 'Neo',
            'profile_url' => 'https://i/w185/k.jpg',
        ], $shaped['cast'][0]);
        $this->assertSame('Director', $shaped['crew'][0]['job']);
        $this->assertNull($shaped['crew'][0]['profile_url']);
        $this->assertSame('Warner Bros.', $shaped['production_companies'][0]['name']);
        $this->assertSame('US', $shaped['production_companies'][0]['origin_country']);
        $this->assertSame('Warner Bros.', $shaped['studio']);
    }

    public function testShapeDetailFallsBackToActorObjectsWhenNoCastKey(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'Legacy',
            'type' => 'movie',
            'metadata' => [
                // No `cast` key — fall back to object-form actors.
                'actors' => [
                    ['name' => 'Old Actor', 'character' => 'Hero'],
                ],
            ],
        ], []);

        $this->assertCount(1, $shaped['cast']);
        $this->assertSame('Old Actor', $shaped['cast'][0]['name']);
        $this->assertSame('Hero', $shaped['cast'][0]['role']);
        $this->assertNull($shaped['cast'][0]['profile_url']);
    }

    public function testShapeDetailFallsBackToFlatActorNamesForCast(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'Flat',
            'type' => 'movie',
            'metadata' => ['actors' => ['Solo Name']],
        ], []);

        $this->assertSame([
            ['name' => 'Solo Name', 'role' => '', 'profile_url' => null],
        ], $shaped['cast']);
    }

    public function testShapeDetailDefensivelyHandlesMalformedRichMetadata(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'Bad',
            'type' => 'movie',
            'metadata' => [
                'cast' => 'not-an-array',
                'crew' => [['no_name' => true], 'scalar'],
                'production_companies' => ['nope'],
                'studio' => 42,
            ],
        ], []);

        $this->assertSame([], $shaped['cast']);
        $this->assertSame([], $shaped['crew']);
        $this->assertSame([], $shaped['production_companies']);
        $this->assertNull($shaped['studio']);
    }

    public function testListShapeDoesNotExposeRichDetailFields(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'M',
            'type' => 'movie',
            'metadata' => [
                'cast' => [['name' => 'A', 'role' => 'B', 'profile_url' => null]],
                'crew' => [['name' => 'C', 'job' => 'Director', 'profile_url' => null]],
                'production_companies' => [['name' => 'D', 'logo_url' => null, 'origin_country' => null]],
                'studio' => 'D',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/bg.jpg',
                'theme_audio_url' => 'https://example.com/theme.mp3',
                'actors' => ['A'],
            ],
        ]);

        // The lean list shape carries flat actors but NONE of the heavy blocks.
        $this->assertSame(['A'], $shaped['actors']);
        $this->assertArrayNotHasKey('cast', $shaped);
        $this->assertArrayNotHasKey('crew', $shaped);
        $this->assertArrayNotHasKey('production_companies', $shaped);
        $this->assertArrayNotHasKey('studio', $shaped);
        $this->assertArrayNotHasKey('backdrop_url', $shaped);
        $this->assertArrayNotHasKey('theme_audio_url', $shaped);
    }

    public function testShapeDetailExposesBackdropUrlWhenSet(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'Backdrop Film',
            'type' => 'movie',
            'metadata' => [
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/bg.jpg',
            ],
        ], []);

        $this->assertSame('https://image.tmdb.org/t/p/original/bg.jpg', $shaped['backdrop_url']);
    }

    public function testShapeDetailReturnsNullBackdropUrlWhenNotSet(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'No Backdrop Film',
            'type' => 'movie',
            'metadata' => [],
        ], []);

        $this->assertNull($shaped['backdrop_url']);
    }

    public function testShapeDetailExposesThemeAudioUrlWhenSet(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 's',
            'name' => 'Theme Series',
            'type' => 'series',
            'metadata' => [
                'theme_audio_url' => 'https://example.com/theme.mp3',
            ],
        ], []);

        $this->assertSame('https://example.com/theme.mp3', $shaped['theme_audio_url']);
    }

    public function testShapeDetailReturnsNullThemeAudioUrlWhenNotSet(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 's',
            'name' => 'Silent Series',
            'type' => 'series',
            'metadata' => [],
        ], []);

        $this->assertNull($shaped['theme_audio_url']);
    }

    public function testShapeDetailExposesFilesBlockWhenAdmin(): void
    {
        $streams = [
            ['stream_index' => 0, 'stream_type' => 'video', 'codec' => 'h264', 'width' => 1920, 'height' => 1080],
            ['stream_index' => 1, 'stream_type' => 'audio', 'codec' => 'aac'],
        ];
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'Multi File Film',
            'type' => 'movie',
            'metadata' => [
                'files' => [
                    ['path' => '/mnt/media/movie.mkv', 'size' => 1_500_000_000, 'stream_index' => 0],
                    ['path' => '/mnt/media/movie.srt', 'size' => 5000, 'stream_index' => null],
                ],
            ],
        ], $streams, true);

        $this->assertArrayHasKey('files', $shaped);
        $this->assertCount(2, $shaped['files']);

        // First file — video with full path, size, container, codec, resolution.
        $this->assertSame('/mnt/media/movie.mkv', $shaped['files'][0]['path']);
        $this->assertSame(1_500_000_000, $shaped['files'][0]['size_bytes']);
        $this->assertSame('mkv', $shaped['files'][0]['container']);
        $this->assertSame('h264', $shaped['files'][0]['codec']);
        $this->assertSame('1920x1080', $shaped['files'][0]['resolution']);

        // Second file — subtitle; no stream so codec/resolution null.
        $this->assertSame('/mnt/media/movie.srt', $shaped['files'][1]['path']);
        $this->assertSame(5000, $shaped['files'][1]['size_bytes']);
        $this->assertSame('srt', $shaped['files'][1]['container']);
        $this->assertNull($shaped['files'][1]['codec']);
        $this->assertNull($shaped['files'][1]['resolution']);
    }

    public function testShapeDetailDoesNotExposeFilesBlockWhenNotAdmin(): void
    {
        $streams = [['stream_index' => 0, 'stream_type' => 'video', 'codec' => 'h264']];
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'Private Film',
            'type' => 'movie',
            'metadata' => [
                'files' => [
                    ['path' => '/mnt/media/secret.mkv', 'size' => 2_000_000_000],
                ],
            ],
        ], $streams, false);

        // `files` key must not be present when the user is not an admin.
        $this->assertArrayNotHasKey('files', $shaped);
    }

    public function testFilesBlockGracefullyHandlesMissingFilesKey(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'No Files Film',
            'type' => 'movie',
            'metadata' => [],
        ], [], true);

        $this->assertArrayHasKey('files', $shaped);
        $this->assertSame([], $shaped['files']);
    }

    public function testFilesBlockExcludesEntriesWithMissingOrEmptyPath(): void
    {
        $streams = [];
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'Bad Files Film',
            'type' => 'movie',
            'metadata' => [
                'files' => [
                    ['path' => '/valid/path.mkv', 'size' => 100],
                    ['path' => '', 'size' => 200],       // empty path — excluded
                    ['size' => 300],                      // missing path — excluded
                    null,                                 // not an array — excluded
                ],
            ],
        ], $streams, true);

        $this->assertCount(1, $shaped['files']);
        $this->assertSame('/valid/path.mkv', $shaped['files'][0]['path']);
    }

    public function testListShapeDoesNotExposeFiles(): void
    {
        // The list shape must never include `files` — it is a detail-only field.
        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'M',
            'type' => 'movie',
            'metadata' => [
                'files' => [
                    ['path' => '/mnt/media/secret.mkv', 'size' => 2_000_000_000],
                ],
            ],
        ]);

        $this->assertArrayNotHasKey('files', $shaped);
    }

    public function testFilesBlockDerivesContainerFromExtension(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'Container Test',
            'type' => 'movie',
            'metadata' => [
                'files' => [
                    ['path' => '/media/video.mp4', 'size' => 100],
                    ['path' => '/media/video.avi', 'size' => 200],
                    ['path' => '/media/video.MKV', 'size' => 300],   // uppercase — lowercased
                    ['path' => '/media/namedot', 'size' => 400],     // no extension
                ],
            ],
        ], [], true);

        $this->assertCount(4, $shaped['files']);
        $this->assertSame('mp4', $shaped['files'][0]['container']);
        $this->assertSame('avi', $shaped['files'][1]['container']);
        $this->assertSame('mkv', $shaped['files'][2]['container']);
        $this->assertNull($shaped['files'][3]['container']);
    }
}
