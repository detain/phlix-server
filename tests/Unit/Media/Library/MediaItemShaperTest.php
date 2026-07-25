<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Auth\SignedUrl;
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

    /**
     * Every member of the real `media_items.type` column ENUM (migrations 001 →
     * 011 → 034) must survive shaping UNCHANGED. Regression: VALID_TYPES listed
     * only 6 of the 13 members (plus a non-existent `image`), so a genuine
     * `photo`/`book`/`audiobook`/`track`/… row was silently relabelled `movie`
     * on its way to API clients.
     *
     * @dataProvider mediaItemTypeEnumProvider
     */
    public function testShapePreservesEveryMemberOfTheTypeColumnEnum(string $type): void
    {
        $shaped = MediaItemShaper::shape(['id' => 'x', 'name' => 'X', 'type' => $type]);

        $this->assertSame($type, $shaped['type'], "type '$type' must not be coerced");
    }

    /**
     * The exact ENUM members of `media_items.type`, mirroring migration 034's
     * final `MODIFY COLUMN type ENUM(...)`.
     *
     * @return iterable<string, array{string}>
     */
    public static function mediaItemTypeEnumProvider(): iterable
    {
        foreach (
            [
                'movie', 'series', 'season', 'episode', 'track', 'music', 'album',
                'artist', 'video', 'audio', 'book', 'photo', 'audiobook',
            ] as $type
        ) {
            yield $type => [$type];
        }
    }

    /**
     * `image` is a scanner-side label (the extension-set key), NOT a member of
     * the `media_items.type` ENUM — the column calls that concept `photo`. It
     * must therefore NOT be accepted as a media-item type.
     */
    public function testShapeRejectsTheNonSchemaImageType(): void
    {
        $shaped = MediaItemShaper::shape(['id' => 'x', 'name' => 'X', 'type' => 'image']);

        $this->assertSame('movie', $shaped['type'], "'image' is not a media_items.type member");
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

    public function testShapeDetailExposesTrailerFieldsWhenPresent(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'The Matrix',
            'type' => 'movie',
            'metadata' => [
                'trailer_url' => 'https://www.youtube.com/watch?v=KEY1',
                'trailer_key' => 'KEY1',
                'trailer_site' => 'YouTube',
            ],
        ], []);

        $this->assertSame('https://www.youtube.com/watch?v=KEY1', $shaped['trailer_url']);
        $this->assertSame('KEY1', $shaped['trailer_key']);
        $this->assertSame('YouTube', $shaped['trailer_site']);
    }

    public function testShapeDetailTrailerFieldsNullWhenAbsent(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'No Trailer',
            'type' => 'movie',
            'metadata' => [],
        ], []);

        $this->assertNull($shaped['trailer_url']);
        $this->assertNull($shaped['trailer_key']);
        $this->assertNull($shaped['trailer_site']);
    }

    public function testShapeListShapeOmitsTrailerFields(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'The Matrix',
            'type' => 'movie',
            'metadata' => ['trailer_url' => 'https://www.youtube.com/watch?v=KEY1'],
        ]);

        $this->assertArrayNotHasKey('trailer_url', $shaped);
    }

    public function testShapeDetailReMintsExpiredInternalLogoUrl(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=shaper-logo-secret');
        SignedUrl::resetSharedForTesting();
        $signer = SignedUrl::fromEnv();

        // A locally-cached TMDB PNG logo is served at `?size=logo`, signed at scan
        // time — hours later the token is expired. The shaper must re-mint it so an
        // authless <img> can still fetch it.
        $expiredExp = time() - 3600;
        $expiredSig = $signer->signature('/api/v1/artwork/m', $expiredExp);
        $stale = '/api/v1/artwork/m?size=logo&exp=' . $expiredExp . '&sig=' . $expiredSig;

        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'The Matrix',
            'type' => 'movie',
            'metadata' => ['logo_url' => $stale],
        ], []);

        $this->assertIsString($shaped['logo_url']);
        $this->assertNotSame($stale, $shaped['logo_url'], 'the stale signature is re-minted');
        parse_str((string) parse_url($shaped['logo_url'], PHP_URL_QUERY), $q);
        /** @var array<string, string> $q */
        $this->assertSame('logo', $q['size'], 'the logo size descriptor is preserved');
        $this->assertGreaterThan(time(), (int) $q['exp']);
        $this->assertTrue(
            $signer->verify('/api/v1/artwork/m', $q['exp'], $q['sig']),
            'the re-minted logo_url verifies with a fresh signature'
        );

        putenv('PHLIX_SIGNED_URL_SECRET');
        SignedUrl::resetSharedForTesting();
    }

    public function testShapeDetailLeavesExternalLogoUrlUnchanged(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'The Matrix',
            'type' => 'movie',
            'metadata' => [
                // A remote (non-localized) SVG/PNG logo is never signed.
                'logo_url' => 'https://image.tmdb.org/t/p/original/logo.png',
            ],
        ], []);

        $this->assertSame('https://image.tmdb.org/t/p/original/logo.png', $shaped['logo_url']);
    }

    public function testShapeDetailLogoUrlNullWhenAbsent(): void
    {
        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'No Logo',
            'type' => 'movie',
            'metadata' => [],
        ], []);

        $this->assertNull($shaped['logo_url']);
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
        $this->assertArrayNotHasKey('theme_audio_url', $shaped);
        // S101: `backdrop_url`/`backdrop_srcset` ARE now on the list shape (a
        // wide-backdrop row renderer needs them) — but the `/original` hero asset
        // is still detail-only. A 100-row page must never advertise it.
        $this->assertArrayHasKey('backdrop_url', $shaped);
        $this->assertArrayHasKey('backdrop_srcset', $shaped);
        $this->assertArrayNotHasKey('backdrop_url_large', $shaped);
    }

    /**
     * S101 — the LIST shape carries a ROW-sized backdrop so a wide-backdrop row
     * renderer has something to paint. Backdrops are STORED at TMDB `/w500`
     * (LibraryMetadataMatcher::imageUrl()), which is narrower than a row strip
     * needs, so the base steps up to `/w780`.
     */
    public function testShapeExposesRowSizedBackdropOnTheListShape(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'Backdrop Film',
            'type' => 'movie',
            'metadata' => ['backdrop_url' => 'https://image.tmdb.org/t/p/w500/bg.jpg'],
        ]);

        $this->assertSame('https://image.tmdb.org/t/p/w780/bg.jpg', $shaped['backdrop_url']);
        // Exactly TWO candidates — TMDB's whole row-range ladder.
        $this->assertSame(
            'https://image.tmdb.org/t/p/w780/bg.jpg 780w, '
            . 'https://image.tmdb.org/t/p/w1280/bg.jpg 1280w',
            $shaped['backdrop_srcset'],
        );
    }

    /**
     * The payload guard this step exists for: `GET /api/v1/media` returns up to
     * PageLimit::MAX (100) rows, so the list srcset must NEVER advertise TMDB
     * `/original` (1.5–4 MB per image → 150–400 MB for one page) and must NEVER
     * carry the detail-only `backdrop_url_large`. Copying shapeDetail()'s fields
     * verbatim fails this test.
     */
    public function testShapeListBackdropNeverAdvertisesOriginalOrTheHeroKey(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'Backdrop Film',
            'type' => 'movie',
            'metadata' => ['backdrop_url' => 'https://image.tmdb.org/t/p/w500/bg.jpg'],
        ]);

        $srcset = $shaped['backdrop_srcset'];
        $this->assertIsString($srcset);
        $this->assertStringNotContainsString('/original/', $srcset, 'list rows must not advertise /original');
        $this->assertStringNotContainsString('1920w', $srcset, 'the /original width step must be absent');
        $this->assertStringNotContainsString('/original/', (string) $shaped['backdrop_url']);
        // The `/original` hero asset stays detail-only.
        $this->assertArrayNotHasKey('backdrop_url_large', $shaped);
        // Short candidate list — two entries, no more.
        $this->assertCount(2, explode(',', $srcset));
    }

    /**
     * Types with no landscape art (music/photo/book families) carry no
     * `metadata_json.backdrop_url`, so both keys degrade to null — never a
     * broken/synthesised URL. The keys stay PRESENT so the response shape is
     * stable for every row.
     *
     * @dataProvider backdroplessTypeProvider
     */
    public function testShapeYieldsNullBackdropForTypesWithoutLandscapeArt(string $type): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'x',
            'name' => 'X',
            'type' => $type,
            'metadata' => ['poster_url' => 'https://image.tmdb.org/t/p/w500/p.jpg'],
        ]);

        $this->assertArrayHasKey('backdrop_url', $shaped, "'$type' must still carry the key");
        $this->assertNull($shaped['backdrop_url'], "'$type' has no backdrop → null");
        $this->assertNull($shaped['backdrop_srcset'], "'$type' has no backdrop → null srcset");
    }

    /**
     * The `media_items.type` members that never carry a landscape backdrop.
     *
     * @return iterable<string, array{string}>
     */
    public static function backdroplessTypeProvider(): iterable
    {
        foreach (['track', 'music', 'album', 'artist', 'photo', 'book', 'audiobook'] as $type) {
            yield $type => [$type];
        }
    }

    /**
     * There is deliberately NO `type` allowlist on the backdrop keys: fanart.tv
     * genuinely supplies artist/album backgrounds, so an `album` row that HAS a
     * backdrop must keep it. Gating on type would throw away real artwork.
     */
    public function testShapeKeepsABackdropOnAMusicTypeThatActuallyHasOne(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'al',
            'name' => 'Kind of Blue',
            'type' => 'album',
            'metadata' => ['backdrop_url' => 'https://assets.fanart.tv/fanart/music/abc/bg.jpg'],
        ]);

        $this->assertSame('https://assets.fanart.tv/fanart/music/abc/bg.jpg', $shaped['backdrop_url']);
    }

    /**
     * A non-TMDB backdrop (fanart.tv, a locally-cached file) has no width ladder,
     * so it passes through EXACTLY as stored and the srcset is null — the client
     * then uses the single `backdrop_url`.
     */
    public function testShapeKeepsNonTmdbListBackdropAsStoredWithNullSrcset(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'Local Backdrop',
            'type' => 'movie',
            'metadata' => ['backdrop_url' => 'https://example.com/bg.jpg'],
        ]);

        $this->assertSame('https://example.com/bg.jpg', $shaped['backdrop_url']);
        $this->assertNull($shaped['backdrop_srcset']);
        // A spoofed TMDB host must not be width-swapped either.
        $spoofed = MediaItemShaper::shape([
            'id' => 'm2',
            'name' => 'Spoofed',
            'type' => 'movie',
            'metadata' => ['backdrop_url' => 'https://evil.com/image.tmdb.org/t/p/w500/bg.jpg'],
        ]);
        $this->assertSame('https://evil.com/image.tmdb.org/t/p/w500/bg.jpg', $spoofed['backdrop_url']);
        $this->assertNull($spoofed['backdrop_srcset']);
    }

    /**
     * A blank/non-string stored backdrop must not become an empty-string URL that
     * a client would render as a broken image.
     */
    public function testShapeListBackdropIsNullForBlankOrNonStringMetadata(): void
    {
        foreach ([['backdrop_url' => ''], ['backdrop_url' => '   '], ['backdrop_url' => 42], []] as $metadata) {
            $shaped = MediaItemShaper::shape([
                'id' => 'm',
                'name' => 'M',
                'type' => 'movie',
                'metadata' => $metadata,
            ]);

            $this->assertNull($shaped['backdrop_url']);
            $this->assertNull($shaped['backdrop_srcset']);
        }
    }

    /**
     * Signed-URL freshness on the LIST shape. Once backdrops are cached locally
     * (S72) `metadata_json.backdrop_url` is a signed `/api/v1/artwork/{id}?size=…`
     * URL minted at SCAN time — hours later that signature is expired, and an
     * authless `<img>` 401s on it (the 2026-07-19 production incident). The list
     * shape must therefore re-mint at RESPONSE time, exactly like poster_url.
     */
    public function testShapeReMintsExpiredInternalBackdropUrlOnTheListShape(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=shaper-list-backdrop-secret');
        SignedUrl::resetSharedForTesting();
        $signer = SignedUrl::fromEnv();

        $expiredExp = time() - 3600;
        $expiredSig = $signer->signature('/api/v1/artwork/m', $expiredExp);
        $stale = '/api/v1/artwork/m?size=w780&exp=' . $expiredExp . '&sig=' . $expiredSig;

        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'Local Backdrop Film',
            'type' => 'movie',
            'metadata' => ['backdrop_url' => $stale],
        ]);

        $this->assertIsString($shaped['backdrop_url']);
        $this->assertNotSame($stale, $shaped['backdrop_url'], 'the stale signature is re-minted');
        parse_str((string) parse_url($shaped['backdrop_url'], PHP_URL_QUERY), $q);
        /** @var array<string, string> $q */
        $this->assertSame('w780', $q['size'], 'the size descriptor is preserved');
        $this->assertGreaterThan(time(), (int) $q['exp'], 'the re-minted token is in the future');
        $this->assertTrue(
            $signer->verify('/api/v1/artwork/m', $q['exp'], $q['sig']),
            'the re-minted list backdrop_url verifies with a fresh signature'
        );
        // An internal artwork URL has no TMDB width ladder, so nothing can be
        // DERIVED for it — and this row stores no `backdrop_srcset`, so there is
        // nothing to prefer either. Null is the correct answer here; when S72
        // stores local variants they are honoured, see
        // testShapeKeepsAStoredBackdropSrcsetForACachedArtworkBackdrop().
        $this->assertNull($shaped['backdrop_srcset']);

        putenv('PHLIX_SIGNED_URL_SECRET');
        SignedUrl::resetSharedForTesting();
    }

    /**
     * The responsive ladder must SURVIVE S72. Once backdrops are cached locally the
     * stored `metadata_json.backdrop_url` is `/api/v1/artwork/{id}?size=…`, which is
     * not a TMDB URL, so nothing can be derived from it — the shape must therefore
     * prefer the STORED `metadata_json.backdrop_srcset` ArtworkStorage wrote, exactly
     * like `poster_srcset` does. Without that preference `backdrop_srcset` is
     * permanently null for every cached backdrop and the ladder this step adds
     * silently vanishes. Each candidate is re-signed, the descriptors survive.
     */
    public function testShapeKeepsAStoredBackdropSrcsetForACachedArtworkBackdrop(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=shaper-stored-backdrop-srcset-secret');
        SignedUrl::resetSharedForTesting();
        $signer = SignedUrl::fromEnv();

        $expiredExp = time() - 3600;
        $staleSig = $signer->signature('/api/v1/artwork/m', $expiredExp);
        $stale = static fn(string $size): string =>
            '/api/v1/artwork/m?size=' . $size . '&exp=' . $expiredExp . '&sig=' . $staleSig;

        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'Cached Backdrop Film',
            'type' => 'movie',
            'metadata' => [
                'backdrop_url' => $stale('w780'),
                'backdrop_srcset' => $stale('w780') . ' 780w, ' . $stale('w1280') . ' 1280w',
            ],
        ]);

        $srcset = $shaped['backdrop_srcset'];
        $this->assertIsString($srcset, 'a cached backdrop must NOT lose its responsive ladder');
        $candidates = explode(', ', $srcset);
        $this->assertCount(2, $candidates);
        // Descriptors intact, both sizes preserved, every signature freshly minted.
        $this->assertStringEndsWith(' 780w', $candidates[0]);
        $this->assertStringEndsWith(' 1280w', $candidates[1]);
        $this->assertStringNotContainsString($staleSig, $srcset, 'every stale signature is re-minted');
        foreach (['w780' => $candidates[0], 'w1280' => $candidates[1]] as $size => $candidate) {
            $url = substr($candidate, 0, (int) strrpos($candidate, ' '));
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            /** @var array<string, string> $q */
            $this->assertSame($size, $q['size'], 'the size descriptor is preserved');
            $this->assertGreaterThan(time(), (int) $q['exp']);
            $this->assertTrue(
                $signer->verify('/api/v1/artwork/m', $q['exp'], $q['sig']),
                'each re-minted candidate verifies with a fresh signature'
            );
        }

        putenv('PHLIX_SIGNED_URL_SECRET');
        SignedUrl::resetSharedForTesting();
    }

    /**
     * A stored `backdrop_srcset` WINS over one derivable from a TMDB URL — the
     * stored value is what ArtworkStorage actually cached, so it is the truth.
     * Mirrors the `poster_srcset` precedence.
     */
    public function testShapePrefersAStoredBackdropSrcsetOverTheDerivedTmdbLadder(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'M',
            'type' => 'movie',
            'metadata' => [
                'backdrop_url' => 'https://image.tmdb.org/t/p/w500/bg.jpg',
                'backdrop_srcset' => '/artwork/local/bg-780.jpg 780w',
            ],
        ]);

        $this->assertSame('/artwork/local/bg-780.jpg 780w', $shaped['backdrop_srcset']);
        // …while `backdrop_url` still comes from `backdrop_url` (width-swapped).
        $this->assertSame('https://image.tmdb.org/t/p/w780/bg.jpg', $shaped['backdrop_url']);
    }

    /**
     * A stored `backdrop_srcset` is emitted verbatim on up to PageLimit::MAX rows,
     * so it gets the same scheme allowlist as the single URL: ONE unsafe candidate
     * rejects the whole stored value, and the shape falls back to the derived
     * ladder (or null) rather than shipping a half-sanitised srcset.
     */
    public function testShapeRejectsAStoredBackdropSrcsetCarryingAnUnsafeCandidate(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'M',
            'type' => 'movie',
            'metadata' => [
                'backdrop_url' => 'https://image.tmdb.org/t/p/w500/bg.jpg',
                'backdrop_srcset' => '/artwork/local/bg-780.jpg 780w, javascript:alert(1) 1280w',
            ],
        ]);

        // Falls back to the DERIVED TMDB ladder — the poisoned value never ships.
        $this->assertSame(
            'https://image.tmdb.org/t/p/w780/bg.jpg 780w, '
            . 'https://image.tmdb.org/t/p/w1280/bg.jpg 1280w',
            $shaped['backdrop_srcset'],
        );

        // With nothing derivable either, it degrades to null.
        $nonTmdb = MediaItemShaper::shape([
            'id' => 'm2',
            'name' => 'M2',
            'type' => 'movie',
            'metadata' => [
                'backdrop_url' => 'https://assets.fanart.tv/fanart/movies/1/bg.jpg',
                'backdrop_srcset' => 'https://assets.fanart.tv/1.jpg 780w, data:image/png;base64,AAA 1280w',
            ],
        ]);
        $this->assertNull($nonTmdb['backdrop_srcset']);
    }

    /**
     * URL scheme allowlist on the new list keys. `metadata_json.backdrop_url` is
     * provider-, `.nfo`- or plugin-supplied, and this step emits it (plus a srcset
     * built FROM it) on up to PageLimit::MAX rows, so a non-`http(s)`/non-relative
     * value must become null rather than being echoed — and a TMDB URL carrying an
     * attribute-breakout payload must never be width-swapped INTO the srcset.
     *
     * @dataProvider unsafeBackdropUrlProvider
     */
    public function testShapeRejectsUnsafeBackdropUrlSchemes(string $stored, string $why): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'M',
            'type' => 'movie',
            'metadata' => ['backdrop_url' => $stored],
        ]);

        $this->assertNull($shaped['backdrop_url'], $why);
        $this->assertNull($shaped['backdrop_srcset'], $why . ' (and no srcset is built from it)');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsafeBackdropUrlProvider(): iterable
    {
        yield 'javascript scheme' => ['javascript:alert(1)', 'javascript: URIs are never emitted'];
        yield 'javascript uppercase' => ['JAVASCRIPT:alert(1)', 'the scheme check is case-insensitive'];
        yield 'javascript newline-obfuscated' => [
            "jav\nascript:alert(1)",
            'browsers strip control bytes before parsing the scheme',
        ];
        yield 'data uri' => ['data:image/png;base64,AAAA', 'data: URIs are never emitted'];
        yield 'vbscript scheme' => ['vbscript:msgbox(1)', 'only http/https are allowed'];
        yield 'file scheme' => ['file:///etc/passwd', 'only http/https are allowed'];
        yield 'protocol-relative' => ['//image.tmdb.org/t/p/w500/bg.jpg', 'protocol-relative URLs are rejected'];
        yield 'attribute breakout on a real TMDB url' => [
            'https://image.tmdb.org/t/p/w500/bg.jpg"><script>alert(1)</script>',
            'a quote/angle-bracket payload must not be width-swapped into the srcset',
        ];
        yield 'single-quote breakout' => [
            "https://image.tmdb.org/t/p/w500/bg.jpg' onerror='alert(1)",
            'single quotes break out of an attribute too',
        ];
        yield 'backtick' => ['https://example.com/`bg.jpg', 'backticks are an attribute delimiter in old IE'];
        yield 'backslash' => ['https://example.com\\bg.jpg', 'backslashes are normalised to / by browsers'];
        yield 'tab inside the url' => ["https://example.com/\tbg.jpg", 'control bytes are stripped by browsers'];
        yield 'no scheme at all' => ['image.tmdb.org/t/p/w500/bg.jpg', 'a bare host is not a usable image URL'];
    }

    /**
     * The allowlist must not break a single legitimate backdrop shape: TMDB,
     * fanart.tv, thetvdb, plain `http://`, a server-relative artwork path, and the
     * `/api/v1/artwork/{id}` form S72 introduces.
     *
     * @dataProvider legitimateBackdropUrlProvider
     */
    public function testShapeKeepsEveryLegitimateBackdropUrlShape(string $stored, string $expected): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'M',
            'type' => 'movie',
            'metadata' => ['backdrop_url' => $stored],
        ]);

        $this->assertSame($expected, $shaped['backdrop_url']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function legitimateBackdropUrlProvider(): iterable
    {
        // TMDB is the only shape that gets width-swapped; the rest pass through.
        yield 'tmdb https' => [
            'https://image.tmdb.org/t/p/w500/bg.jpg',
            'https://image.tmdb.org/t/p/w780/bg.jpg',
        ];
        yield 'tmdb http' => [
            'http://image.tmdb.org/t/p/w500/bg.jpg',
            'http://image.tmdb.org/t/p/w780/bg.jpg',
        ];
        yield 'fanart.tv' => [
            'https://assets.fanart.tv/fanart/movies/550/moviebackground/fight-club-5234.jpg',
            'https://assets.fanart.tv/fanart/movies/550/moviebackground/fight-club-5234.jpg',
        ];
        yield 'thetvdb' => [
            'https://artworks.thetvdb.com/banners/series/81189/backgrounds/61027.jpg',
            'https://artworks.thetvdb.com/banners/series/81189/backgrounds/61027.jpg',
        ];
        yield 'server-relative artwork path' => [
            '/artwork/abc/backdrop.jpg',
            '/artwork/abc/backdrop.jpg',
        ];
        yield 'query string preserved' => [
            'https://example.com/bg.jpg?v=2',
            'https://example.com/bg.jpg?v=2',
        ];
    }

    /**
     * The future S72 shape — `/api/v1/artwork/{id}?size=…` — must pass the allowlist
     * AND still be re-signed (it is the one shape that gets a fresh token).
     */
    public function testShapeAllowsAndSignsTheInternalArtworkBackdropShape(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=shaper-allowlist-artwork-secret');
        SignedUrl::resetSharedForTesting();
        $signer = SignedUrl::fromEnv();

        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'M',
            'type' => 'movie',
            'metadata' => ['backdrop_url' => '/api/v1/artwork/m?size=w780'],
        ]);

        $url = $shaped['backdrop_url'];
        $this->assertIsString($url);
        $this->assertStringStartsWith('/api/v1/artwork/m?size=w780&exp=', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        /** @var array<string, string> $q */
        $this->assertTrue($signer->verify('/api/v1/artwork/m', $q['exp'], $q['sig']));

        putenv('PHLIX_SIGNED_URL_SECRET');
        SignedUrl::resetSharedForTesting();
    }

    /**
     * A whitespace-padded stored URL must be TRIMMED before parsing. Untrimmed, the
     * `^`-anchored TMDB regex rejects the leading space, so the client got the
     * padded URL verbatim AND lost the whole width ladder (`backdrop_srcset` null)
     * — an invisible regression, because browsers trim `src` themselves.
     *
     * @dataProvider paddedBackdropUrlProvider
     */
    public function testShapeTrimsAPaddedBackdropUrlAndKeepsItsSrcset(string $stored): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'M',
            'type' => 'movie',
            'metadata' => ['backdrop_url' => $stored],
        ]);

        $this->assertSame('https://image.tmdb.org/t/p/w780/bg.jpg', $shaped['backdrop_url']);
        $this->assertSame(
            'https://image.tmdb.org/t/p/w780/bg.jpg 780w, '
            . 'https://image.tmdb.org/t/p/w1280/bg.jpg 1280w',
            $shaped['backdrop_srcset'],
            'the width ladder must survive the padding',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paddedBackdropUrlProvider(): iterable
    {
        yield 'leading space' => [' https://image.tmdb.org/t/p/w500/bg.jpg'];
        yield 'trailing space' => ['https://image.tmdb.org/t/p/w500/bg.jpg '];
        yield 'both' => ["  https://image.tmdb.org/t/p/w500/bg.jpg\t"];
        yield 'trailing newline' => ["https://image.tmdb.org/t/p/w500/bg.jpg\n"];
        yield 'trailing CRLF' => ["https://image.tmdb.org/t/p/w500/bg.jpg\r\n"];
    }

    /**
     * The same trim applies to `poster_url` (a shared helper), so a padded poster
     * keeps its srcset too.
     */
    public function testShapeTrimsAPaddedPosterUrlAndKeepsItsSrcset(): void
    {
        $shaped = MediaItemShaper::shape([
            'id' => 'm',
            'name' => 'M',
            'type' => 'movie',
            'metadata' => ['poster_url' => "  https://image.tmdb.org/t/p/w500/p.jpg\n"],
        ]);

        $this->assertSame('https://image.tmdb.org/t/p/w500/p.jpg', $shaped['poster_url']);
        $this->assertIsString($shaped['poster_srcset']);
        $this->assertStringContainsString('/w780/p.jpg 780w', $shaped['poster_srcset']);
    }

    /**
     * The detail shape keeps its HERO budget: shape() now emits row-sized backdrop
     * values, and shapeDetail() must OVERWRITE them with the stored URL + the
     * `/original` variant. Guards the array_merge/override ordering.
     */
    public function testShapeDetailOverridesTheRowSizedBackdropWithTheHeroBudget(): void
    {
        $row = [
            'id' => 'm',
            'name' => 'Backdrop Film',
            'type' => 'movie',
            'metadata' => ['backdrop_url' => 'https://image.tmdb.org/t/p/w500/bg.jpg'],
        ];

        $list = MediaItemShaper::shape($row);
        $detail = MediaItemShaper::shapeDetail($row, []);

        // List = row budget (w780 base, no /original).
        $this->assertSame('https://image.tmdb.org/t/p/w780/bg.jpg', $list['backdrop_url']);
        // Detail = hero budget (stored URL untouched + /original + the 3-step srcset).
        $this->assertSame('https://image.tmdb.org/t/p/w500/bg.jpg', $detail['backdrop_url']);
        $this->assertSame('https://image.tmdb.org/t/p/original/bg.jpg', $detail['backdrop_url_large']);
        $this->assertIsString($detail['backdrop_srcset']);
        $this->assertStringContainsString('/original/bg.jpg 1920w', $detail['backdrop_srcset']);
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

    public function testShapeDetailReMintsExpiredInternalBackdropUrl(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=shaper-backdrop-secret');
        SignedUrl::resetSharedForTesting();
        $signer = SignedUrl::fromEnv();

        // A locally-cached backdrop is served at `/api/v1/artwork/{id}?size=…`; its
        // scan-time signature is expired hours later and must be re-minted.
        $expiredExp = time() - 3600;
        $expiredSig = $signer->signature('/api/v1/artwork/m', $expiredExp);
        $stale = '/api/v1/artwork/m?size=w780&exp=' . $expiredExp . '&sig=' . $expiredSig;

        $shaped = MediaItemShaper::shapeDetail([
            'id' => 'm',
            'name' => 'Local Backdrop Film',
            'type' => 'movie',
            'metadata' => ['backdrop_url' => $stale],
        ], []);

        $this->assertIsString($shaped['backdrop_url']);
        $this->assertNotSame($stale, $shaped['backdrop_url'], 'the stale signature is re-minted');
        parse_str((string) parse_url($shaped['backdrop_url'], PHP_URL_QUERY), $q);
        /** @var array<string, string> $q */
        $this->assertSame('w780', $q['size']);
        $this->assertTrue(
            $signer->verify('/api/v1/artwork/m', $q['exp'], $q['sig']),
            'the re-minted backdrop_url verifies with a fresh signature'
        );

        putenv('PHLIX_SIGNED_URL_SECRET');
        SignedUrl::resetSharedForTesting();
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
        // The `/original` hero asset stays detail-only. S101 DID add a row-sized
        // `backdrop_srcset` (w780/w1280) to the list shape — see
        // testShapeExposesRowSizedBackdropOnTheListShape() — so only the large key
        // is asserted absent here.
        $this->assertArrayNotHasKey('backdrop_url_large', $shaped);
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

    /**
     * The helper now returns the value TRIMMED, not verbatim. It previously pinned
     * the padded string, which is exactly how a whitespace-padded stored URL kept
     * reaching clients while silently losing its `srcset` — the `^`-anchored TMDB
     * regexes reject the leading space (see
     * testShapeTrimsAPaddedBackdropUrlAndKeepsItsSrcset()).
     */
    public function testNonemptyStringReturnsTheTrimmedStringForNonEmptyStrings(): void
    {
        $this->assertSame('hello', $this->invokeNonemptyString('hello'));
        $this->assertSame('trimmed', $this->invokeNonemptyString('  trimmed  '));
        $this->assertSame('trimmed', $this->invokeNonemptyString("trimmed\n"));
        $this->assertSame('a', $this->invokeNonemptyString('a'));
        $this->assertSame('0', $this->invokeNonemptyString('0'));
        // Interior whitespace is untouched — only the ends are trimmed.
        $this->assertSame('two words', $this->invokeNonemptyString('  two words  '));
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
