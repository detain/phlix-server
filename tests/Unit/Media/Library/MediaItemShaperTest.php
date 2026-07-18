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
    /**
     * Narrow a shaped-response list key (cast/crew/files/production_companies)
     * — MediaItemShaper::shapeDetail() is typed array<string, mixed>, so these
     * nested list-of-rows values arrive as mixed — into a list of array rows
     * for offset-based assertions, preserving element count.
     *
     * @param array<string, mixed> $shaped
     * @return array<int, array<array-key, mixed>>
     */
    private function rows(array $shaped, string $key): array
    {
        $value = $shaped[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $row) {
            $rows[] = is_array($row) ? $row : [];
        }
        return $rows;
    }

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

    public function testShapeExposesAirDateFromTopLevelOrProviderDetails(): void
    {
        // Top-level key.
        $top = MediaItemShaper::shape([
            'id' => 'ep-1',
            'name' => 'Pilot',
            'type' => 'episode',
            'metadata' => ['air_date' => '2020-01-15'],
        ]);
        $this->assertSame('2020-01-15', $top['air_date']);

        // Nested provider block (TVDB first_aired).
        $nested = MediaItemShaper::shape([
            'id' => 'ep-2',
            'name' => 'Two',
            'type' => 'episode',
            'metadata' => ['details' => ['tvdb' => ['first_aired' => '2020-02-20']]],
        ]);
        $this->assertSame('2020-02-20', $nested['air_date']);

        // Absent → null.
        $none = MediaItemShaper::shape([
            'id' => 'ep-3',
            'name' => 'Three',
            'type' => 'episode',
            'metadata' => [],
        ]);
        $this->assertNull($none['air_date']);
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

    public function testShapeSurfacesMovieOfficialRatingAsRating(): void
    {
        // Phase C: the resolver stores the movie cert under `official_rating`
        // (parsed from TMDB release_dates); the shaper surfaces it as `rating`.
        $shaped = MediaItemShaper::shape([
            'id' => 'm-2',
            'name' => 'Film',
            'type' => 'movie',
            'metadata' => ['official_rating' => 'PG-13'],
        ]);

        $this->assertSame('PG-13', $shaped['rating']);
    }

    public function testShapeSurfacesTvOfficialRatingAsRating(): void
    {
        // TV content_ratings (e.g. TV-14) reach `rating` too, now that the TV
        // ratings are canonical values.
        $shaped = MediaItemShaper::shape([
            'id' => 'ep-2',
            'name' => 'Pilot',
            'type' => 'episode',
            'metadata' => ['official_rating' => 'TV-14'],
        ]);

        $this->assertSame('TV-14', $shaped['rating']);
    }

    public function testShapeOfficialRatingWinsOverLegacyRatingKey(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm-3',
            'name' => 'Film',
            'type' => 'movie',
            'metadata' => ['official_rating' => 'R', 'rating' => 'G'],
        ]);

        $this->assertSame('R', $shaped['rating']);
    }

    public function testShapeNormalizesNrAliasAndDropsUnknownRating(): void
    {
        $nr = MediaItemShaper::shape([
            'id' => 'm-4',
            'name' => 'Film',
            'type' => 'movie',
            'metadata' => ['official_rating' => 'NR'],
        ]);
        $this->assertSame('UNRATED', $nr['rating']);

        $unknown = MediaItemShaper::shape([
            'id' => 'm-5',
            'name' => 'Film',
            'type' => 'movie',
            'metadata' => ['rating' => 'BOGUS'],
        ]);
        $this->assertNull($unknown['rating']);
    }

    public function testShapeRatingNullWhenNoCert(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm-6',
            'name' => 'Film',
            'type' => 'movie',
            'metadata' => ['year' => 2020],
        ]);

        $this->assertNull($shaped['rating']);
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
        $this->assertCount(1, $this->rows($shaped, 'cast'));
        $this->assertSame([
            'name' => 'Keanu Reeves',
            'role' => 'Neo',
            'profile_url' => 'https://i/w185/k.jpg',
        ], $this->rows($shaped, 'cast')[0]);
        $this->assertSame('Director', $this->rows($shaped, 'crew')[0]['job']);
        $this->assertNull($this->rows($shaped, 'crew')[0]['profile_url']);
        $this->assertSame('Warner Bros.', $this->rows($shaped, 'production_companies')[0]['name']);
        $this->assertSame('US', $this->rows($shaped, 'production_companies')[0]['origin_country']);
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

        $this->assertCount(1, $this->rows($shaped, 'cast'));
        $this->assertSame('Old Actor', $this->rows($shaped, 'cast')[0]['name']);
        $this->assertSame('Hero', $this->rows($shaped, 'cast')[0]['role']);
        $this->assertNull($this->rows($shaped, 'cast')[0]['profile_url']);
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
        ], $this->rows($shaped, 'cast'));
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

        $this->assertSame([], $this->rows($shaped, 'cast'));
        $this->assertSame([], $this->rows($shaped, 'crew'));
        $this->assertSame([], $this->rows($shaped, 'production_companies'));
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

    public function testShapeDetailExposesLargeBackdropAndSrcsetForTmdbBackdrop(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'Backdrop Film',
            'type' => 'movie',
            'metadata' => [
                // Backdrops are stored at /w500; the shaper width-swaps to large sizes.
                'backdrop_url' => 'https://image.tmdb.org/t/p/w500/bg.jpg',
            ],
        ], []);

        // Original `backdrop_url` is preserved unchanged.
        $this->assertSame('https://image.tmdb.org/t/p/w500/bg.jpg', $shaped['backdrop_url']);
        // Full-resolution page-background variant.
        $this->assertSame('https://image.tmdb.org/t/p/original/bg.jpg', $shaped['backdrop_url_large']);
        // Responsive srcset advertises the large widths + original.
        $this->assertIsString($shaped['backdrop_srcset']);
        $this->assertStringContainsString('/w1280/bg.jpg 1280w', $shaped['backdrop_srcset']);
        $this->assertStringContainsString('/original/bg.jpg 1920w', $shaped['backdrop_srcset']);
    }

    public function testShapeDetailNullsLargeBackdropForNonTmdbBackdrop(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'Local Backdrop',
            'type' => 'movie',
            'metadata' => [
                'backdrop_url' => 'https://example.com/bg.jpg',
            ],
        ], []);

        // Non-TMDB backdrop is kept as-is; no large/srcset is synthesized.
        $this->assertSame('https://example.com/bg.jpg', $shaped['backdrop_url']);
        $this->assertNull($shaped['backdrop_url_large']);
        $this->assertNull($shaped['backdrop_srcset']);
    }

    public function testShapeDetailExposesEpisodeCastCrewAndInheritedTags(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'ep-1',
            'name' => '12:00 A.M.',
            'type' => 'episode',
            'parent_id' => 'season-1',
            'metadata' => [
                'season' => 1,
                'episode' => 1,
                'genres' => ['Drama'],
                'tags' => ['terrorism', 'counter terrorism'],
                'cast' => [
                    ['name' => 'Kiefer Sutherland', 'role' => 'Jack Bauer', 'profile_url' => 'https://i/w185/k.jpg'],
                ],
                'crew' => [
                    ['name' => 'Stephen Hopkins', 'job' => 'Director', 'profile_url' => null],
                ],
            ],
        ], []);

        // Episode cast/crew flow through the generic detail shaper unchanged.
        $this->assertCount(1, $this->rows($shaped, 'cast'));
        $this->assertSame('Kiefer Sutherland', $this->rows($shaped, 'cast')[0]['name']);
        $this->assertSame('Jack Bauer', $this->rows($shaped, 'cast')[0]['role']);
        $this->assertSame('Director', $this->rows($shaped, 'crew')[0]['job']);
        // Inherited genres (list shape) + tags (detail-only).
        $this->assertSame(['Drama'], $shaped['genres']);
        $this->assertSame(['terrorism', 'counter terrorism'], $shaped['tags']);
    }

    public function testShapeDetailReturnsEmptyTagsWhenNoneSet(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'No Tags Film',
            'type' => 'movie',
            'metadata' => [],
        ], []);

        $this->assertSame([], $shaped['tags']);
    }

    public function testListShapeDoesNotExposeTags(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'M',
            'type' => 'movie',
            'metadata' => ['tags' => ['a', 'b']],
        ]);

        $this->assertArrayNotHasKey('tags', $shaped);
        $this->assertArrayNotHasKey('backdrop_url_large', $shaped);
        $this->assertArrayNotHasKey('backdrop_srcset', $shaped);
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

    /**
     * The theme-music producer (M3) stores the canonical item-level stream route
     * `/stream/theme-media/item/{id}` in `metadata_json.theme_audio_url`; the
     * detail shaper must surface that exact value so the web player plays it
     * directly (no derived/legacy `/api/v1/media/{id}/theme-audio` endpoint).
     */
    public function testShapeDetailExposesM3StreamRouteAsThemeAudioUrl(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'series-1',
            'name' => 'Firefly',
            'type' => 'series',
            'metadata' => [
                'theme_audio_url' => '/stream/theme-media/item/series-1',
            ],
        ], []);

        $this->assertSame('/stream/theme-media/item/series-1', $shaped['theme_audio_url']);
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

    public function testShapeDetailExposesExternalIdsFromNestedMap(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'Linked Film',
            'type' => 'movie',
            'metadata' => [
                'external_ids' => [
                    'tmdb' => '603',
                    'imdb' => 'tt0133093',
                    'tvdb' => 70327,
                    'anidb' => '',      // blank → dropped
                ],
            ],
        ], []);

        $this->assertSame([
            'tmdb' => '603',
            'imdb' => 'tt0133093',
            'tvdb' => '70327',          // int coerced to string
        ], $shaped['external_ids']);
    }

    public function testShapeDetailMergesTopLevelIdKeysIntoExternalIds(): void
    {
        // Top-level `<provider>_id` scalars are merged; the nested `external_ids`
        // map wins on collision.
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'Mixed IDs',
            'type' => 'series',
            'metadata' => [
                'tmdb_id' => 1399,
                'imdb_id' => 'tt0944947',
                'external_ids' => [
                    'imdb' => 'tt-override',   // nested wins over top-level imdb_id
                    'tvdb' => '121361',
                ],
            ],
        ], []);

        $this->assertSame([
            'tmdb' => '1399',              // from top-level tmdb_id
            'imdb' => 'tt-override',       // nested map wins on collision
            'tvdb' => '121361',            // from nested map
        ], $shaped['external_ids']);
    }

    public function testShapeDetailReturnsEmptyExternalIdsWhenNonePresent(): void
    {
        // The key is always present (stable shape) even with no ids — an empty map.
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'No Links',
            'type' => 'movie',
            'metadata' => [],
        ], []);

        $this->assertArrayHasKey('external_ids', $shaped);
        $this->assertSame([], $shaped['external_ids']);
    }

    public function testListShapeDoesNotExposeExternalIds(): void
    {
        // external_ids is a detail-only field — never on the lean list shape.
        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'M',
            'type' => 'movie',
            'metadata' => [
                'external_ids' => ['tmdb' => '603'],
                'tmdb_id' => 603,
            ],
        ]);

        $this->assertArrayNotHasKey('external_ids', $shaped);
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
        $this->assertCount(2, $this->rows($shaped, 'files'));

        // First file — video with full path, size, container, codec, resolution.
        $this->assertSame('/mnt/media/movie.mkv', $this->rows($shaped, 'files')[0]['path']);
        $this->assertSame(1_500_000_000, $this->rows($shaped, 'files')[0]['size_bytes']);
        $this->assertSame('mkv', $this->rows($shaped, 'files')[0]['container']);
        $this->assertSame('h264', $this->rows($shaped, 'files')[0]['codec']);
        $this->assertSame('1920x1080', $this->rows($shaped, 'files')[0]['resolution']);

        // Second file — subtitle; no stream so codec/resolution null.
        $this->assertSame('/mnt/media/movie.srt', $this->rows($shaped, 'files')[1]['path']);
        $this->assertSame(5000, $this->rows($shaped, 'files')[1]['size_bytes']);
        $this->assertSame('srt', $this->rows($shaped, 'files')[1]['container']);
        $this->assertNull($this->rows($shaped, 'files')[1]['codec']);
        $this->assertNull($this->rows($shaped, 'files')[1]['resolution']);
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
        $this->assertSame([], $this->rows($shaped, 'files'));
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

        $this->assertCount(1, $this->rows($shaped, 'files'));
        $this->assertSame('/valid/path.mkv', $this->rows($shaped, 'files')[0]['path']);
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

        $this->assertCount(4, $this->rows($shaped, 'files'));
        $this->assertSame('mp4', $this->rows($shaped, 'files')[0]['container']);
        $this->assertSame('avi', $this->rows($shaped, 'files')[1]['container']);
        $this->assertSame('mkv', $this->rows($shaped, 'files')[2]['container']);
        $this->assertNull($this->rows($shaped, 'files')[3]['container']);
    }

    // -------------------------------------------------------------------------
    // nonemptyString() helper (private static) — tested via reflection
    // -------------------------------------------------------------------------

    public function testNonemptyStringReturnsNullForEmptyString(): void
    {
        $result = $this->invokeNonemptyString('');
        $this->assertNull($result);
    }

    public function testNonemptyStringReturnsNullForWhitespaceOnlyString(): void
    {
        $result = $this->invokeNonemptyString('   ');
        $this->assertNull($result);
    }

    public function testNonemptyStringReturnsNullForTabWhitespace(): void
    {
        $result = $this->invokeNonemptyString("\t\n  ");
        $this->assertNull($result);
    }

    public function testNonemptyStringReturnsOriginalStringForNonEmptyStrings(): void
    {
        $this->assertSame('hello', $this->invokeNonemptyString('hello'));
        $this->assertSame('  trimmed  ', $this->invokeNonemptyString('  trimmed  '));
        $this->assertSame('a', $this->invokeNonemptyString('a'));
        $this->assertSame('0', $this->invokeNonemptyString('0'));
    }

    public function testNonemptyStringReturnsNullForNullInput(): void
    {
        $result = $this->invokeNonemptyString(null);
        $this->assertNull($result);
    }

    public function testNonemptyStringReturnsNullForNonStringTypes(): void
    {
        $this->assertNull($this->invokeNonemptyString(42));
        $this->assertNull($this->invokeNonemptyString(0));
        $this->assertNull($this->invokeNonemptyString(false));
        $this->assertNull($this->invokeNonemptyString(['array']));
    }

    /**
     * Invokes the private static nonemptyString() method via reflection.
     */
    private function invokeNonemptyString(mixed $value): ?string
    {
        $ref = new \ReflectionMethod(MediaItemShaper::class, 'nonemptyString');
        $ref->setAccessible(true);
        /** @var string|null */
        return $ref->invoke(null, $value);
    }

    // -------------------------------------------------------------------------
    // poster_url fallback chain (SV-FIX: AniList blank cover fallback)
    // -------------------------------------------------------------------------

    /**
     * poster_url takes precedence over cover_image_large.
     */
    public function testShapePosterUrlPrecedenceOverCoverImageLarge(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'T',
            'type' => 'movie',
            'metadata' => [
                'poster_url' => 'https://poster.jpg',
                'cover_image_large' => 'https://large.jpg',
            ],
        ]);

        $this->assertSame('https://poster.jpg', $shaped['poster_url']);
    }

    /**
     * cover_image_large is used when poster_url is empty string (AniList case).
     */
    public function testShapePosterUrlFallsBackToCoverImageLargeWhenPosterUrlIsBlank(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'T',
            'type' => 'movie',
            'metadata' => [
                'poster_url' => '',  // blank — should fall through
                'cover_image_large' => 'https://large.jpg',
            ],
        ]);

        $this->assertSame('https://large.jpg', $shaped['poster_url']);
    }

    /**
     * cover_image_extralarge is the final fallback for cover_image_large.
     */
    public function testShapePosterUrlFallsBackToCoverImageExtralarge(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'T',
            'type' => 'movie',
            'metadata' => [
                'cover_image_extralarge' => 'https://extralarge.jpg',
            ],
        ]);

        $this->assertSame('https://extralarge.jpg', $shaped['poster_url']);
    }

    /**
     * Whitespace-only poster_url falls through to cover_image_large.
     */
    public function testShapePosterUrlFallsBackWhenPosterUrlIsWhitespaceOnly(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'T',
            'type' => 'movie',
            'metadata' => [
                'poster_url' => '   ',
                'cover_image_large' => 'https://large.jpg',
            ],
        ]);

        $this->assertSame('https://large.jpg', $shaped['poster_url']);
    }

    /**
     * All null yields null poster_url.
     */
    public function testShapePosterUrlReturnsNullWhenAllSourcesAreEmpty(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'T',
            'type' => 'movie',
            'metadata' => [
                'poster_url' => '',
                'cover_image_large' => '',
                'cover_image_extralarge' => '',
            ],
        ]);

        $this->assertNull($shaped['poster_url']);
    }

    /**
     * Full chain: poster_url > cover_image_large > cover_image_extralarge > null.
     */
    public function testShapePosterUrlFullFallbackChain(): void
    {
        // poster_url wins
        $a = MediaItemShaper::shape([
            'id' => 'm', 'name' => 'T', 'type' => 'movie',
            'metadata' => ['poster_url' => 'p.jpg', 'cover_image_large' => 'l.jpg', 'cover_image_extralarge' => 'e.jpg'],
        ]);
        $this->assertSame('p.jpg', $a['poster_url']);

        // cover_image_large wins when poster_url is blank
        $b = MediaItemShaper::shape([
            'id' => 'm', 'name' => 'T', 'type' => 'movie',
            'metadata' => ['poster_url' => '', 'cover_image_large' => 'l.jpg', 'cover_image_extralarge' => 'e.jpg'],
        ]);
        $this->assertSame('l.jpg', $b['poster_url']);

        // cover_image_extralarge wins when both above are blank
        $c = MediaItemShaper::shape([
            'id' => 'm', 'name' => 'T', 'type' => 'movie',
            'metadata' => ['poster_url' => '', 'cover_image_large' => '', 'cover_image_extralarge' => 'e.jpg'],
        ]);
        $this->assertSame('e.jpg', $c['poster_url']);
    }
}
