<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\Exception\TmdbUnconfiguredException;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Media\Metadata\SeriesMetadataResolver;
use Phlix\Media\Metadata\TmdbProvider;

/**
 * Unit tests for the S5 interactive per-item match surface of
 * {@see LibraryMetadataMatcher}: {@see LibraryMetadataMatcher::modeForType()},
 * {@see LibraryMetadataMatcher::searchCandidates()} and
 * {@see LibraryMetadataMatcher::applyMatch()}.
 *
 * @covers \Phlix\Media\Metadata\LibraryMetadataMatcher
 */
class LibraryMetadataMatcherApplyTest extends TestCase
{
    private function logger(): StructuredLogger
    {
        return $this->createMock(StructuredLogger::class);
    }

    public function testModeForTypeMapsHierarchyToTvAndElseToMovie(): void
    {
        $this->assertSame('tv', LibraryMetadataMatcher::modeForType('series'));
        $this->assertSame('tv', LibraryMetadataMatcher::modeForType('season'));
        $this->assertSame('tv', LibraryMetadataMatcher::modeForType('episode'));
        $this->assertSame('movie', LibraryMetadataMatcher::modeForType('movie'));
        $this->assertSame('movie', LibraryMetadataMatcher::modeForType('video'));
        $this->assertSame('movie', LibraryMetadataMatcher::modeForType(null));
    }

    public function testSearchCandidatesMapsTvResultsToStableShape(): void
    {
        $tmdb = $this->configuredTmdb();
        $tmdb->expects($this->once())
            ->method('searchTv')
            ->with('24', ['first_air_date_year' => 2001])
            ->willReturn([
                [
                    'id' => '1968',
                    'name' => '24',
                    'overview' => 'Jack Bauer.',
                    'poster_path' => '/poster.jpg',
                    'backdrop_path' => '/back.jpg',
                    'first_air_date' => '2001-11-06',
                    'vote_average' => 7.8,
                ],
                ['id' => '', 'name' => 'junk'], // dropped: no usable id
            ]);

        $matcher = $this->makeMatcher($this->createMock(ItemRepository::class), $tmdb);
        $results = $matcher->searchCandidates('24', 'tv', 2001, 20);

        $this->assertCount(1, $results);
        $this->assertSame([
            'tmdb_id' => '1968',
            'type' => 'tv',
            'title' => '24',
            'year' => 2001,
            'overview' => 'Jack Bauer.',
            'poster_url' => 'https://image.tmdb.org/t/p/w500/poster.jpg',
            'backdrop_url' => 'https://image.tmdb.org/t/p/w500/back.jpg',
            'vote_average' => 7.8,
        ], $results[0]);
    }

    public function testSearchCandidatesMapsMovieResultsAndCaps(): void
    {
        $tmdb = $this->configuredTmdb();
        $rows = [];
        for ($i = 0; $i < 25; $i++) {
            $rows[] = [
                'id' => (string) $i,
                'title' => "Movie {$i}",
                'overview' => '',
                'poster_path' => null,
                'backdrop_path' => null,
                'release_date' => '1999-03-31',
                'vote_average' => 8.0,
            ];
        }
        $tmdb->method('search')->with('The Matrix', ['year' => 1999])->willReturn($rows);

        $matcher = $this->makeMatcher($this->createMock(ItemRepository::class), $tmdb);
        $results = $matcher->searchCandidates('The Matrix', 'movie', 1999, 20);

        $this->assertCount(20, $results);
        $this->assertSame('movie', $results[0]['type']);
        $this->assertSame(1999, $results[0]['year']);
        $this->assertNull($results[0]['poster_url']);
    }

    public function testSearchCandidatesEmptyQueryReturnsEmpty(): void
    {
        $tmdb = $this->configuredTmdb();
        $tmdb->expects($this->never())->method('search');
        $tmdb->expects($this->never())->method('searchTv');

        $matcher = $this->makeMatcher($this->createMock(ItemRepository::class), $tmdb);
        $this->assertSame([], $matcher->searchCandidates('   ', 'movie'));
    }

    public function testSearchWithoutTmdbThrowsUnconfigured(): void
    {
        $matcher = $this->makeMatcher($this->createMock(ItemRepository::class), null);
        $this->expectException(TmdbUnconfiguredException::class);
        $matcher->searchCandidates('foo', 'movie');
    }

    public function testApplyMatchMoviePersistsMergedMetadata(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->with('m1')->willReturn([
            'id' => 'm1',
            'type' => 'movie',
            'name' => 'Old Name',
            'metadata' => ['custom' => 'keep', 'external_ids' => ['imdb' => 'tt9']],
        ]);

        $tmdb = $this->configuredTmdb();
        $tmdb->expects($this->once())->method('getDetails')->with('603')->willReturn([
            'name' => 'The Matrix',
            'overview' => 'A hacker.',
            'poster_path' => '/p.jpg',
            'backdrop_path' => '/b.jpg',
            'genres' => ['Action', 'Sci-Fi'],
            'year' => 1999,
            'runtime_ticks' => 81600000000, // 136 minutes
            'director' => 'The Wachowskis',
            'actors' => [['name' => 'Keanu Reeves', 'role' => 'Neo', 'order' => 0]],
            'imdb_id' => 'tt0133093',
            'tmdb_id' => '603',
        ]);

        $captured = null;
        $items->expects($this->once())->method('update')->with(
            'm1',
            $this->callback(function (mixed $data) use (&$captured): bool {
                $captured = $data;
                return is_array($data);
            }),
        );

        $matcher = $this->makeMatcher($items, $tmdb);
        $result = $matcher->applyMatch('m1', '603', 'movie');

        $this->assertTrue($result['matched']);
        $this->assertSame('movie', $result['mode']);
        $this->assertIsArray($captured);
        /** @var array<string, mixed> $meta */
        $meta = $captured['metadata_json'];
        $this->assertSame('keep', $meta['custom']); // existing key preserved
        $this->assertSame('The Matrix', $meta['title']);
        $this->assertSame('https://image.tmdb.org/t/p/w500/p.jpg', $meta['poster_url']);
        $this->assertSame(136, $meta['runtime']);
        $this->assertSame(['Action', 'Sci-Fi'], $meta['genres']);
        $externalIds = $meta['external_ids'];
        $this->assertIsArray($externalIds);
        $this->assertSame('603', $externalIds['tmdb']);
        $this->assertSame('tt0133093', $externalIds['imdb']);
        $this->assertArrayHasKey('metadata_refreshed_at', $captured);
    }

    public function testApplyMatchSeriesEnrichesChildren(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->with('s1')->willReturn([
            'id' => 's1',
            'type' => 'series',
            'name' => 'Some Show',
            'metadata' => [],
        ]);
        // series → one season child → one episode child
        $items->method('findByParent')->willReturnCallback(static function (string $parent): array {
            if ($parent === 's1') {
                return [['id' => 'se1', 'type' => 'season', 'metadata' => ['season' => 1]]];
            }
            if ($parent === 'se1') {
                return [['id' => 'ep1', 'type' => 'episode', 'metadata' => ['season' => 1, 'episode' => 1]]];
            }
            return [];
        });

        $tmdb = $this->configuredTmdb();
        $tmdb->method('getTvDetails')->with('1399')->willReturn([
            'name' => 'Some Show',
            'overview' => 'Synopsis.',
            'poster_path' => '/sp.jpg',
            'genres' => ['Drama'],
            'year' => 2011,
            'imdb_id' => 'tt0944947',
        ]);

        $series = $this->createMock(SeriesMetadataResolver::class);
        $series->method('resolveSeasonEpisodes')->with('1399', 1)->willReturn([
            'poster_url' => 'https://image.tmdb.org/t/p/w500/season.jpg',
            'overview' => 'Season one.',
            'episodes' => [
                1 => [
                    'episode_title' => 'Winter Is Coming',
                    'overview' => 'Pilot.',
                    'poster_url' => 'https://image.tmdb.org/t/p/w500/still.jpg',
                    'air_date' => '2011-04-17',
                    'runtime' => 62,
                ],
            ],
        ]);

        // series + season + episode = 3 persists.
        $items->expects($this->exactly(3))->method('update');

        $matcher = $this->makeMatcher($items, $tmdb, $series);
        $result = $matcher->applyMatch('s1', '1399', 'tv');

        $this->assertTrue($result['matched']);
        $this->assertSame('tv', $result['mode']);
        $this->assertSame(2, $result['children_enriched']);
    }

    public function testApplyMatchMovieCarriesTrailerFields(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->with('m1')->willReturn([
            'id' => 'm1',
            'type' => 'movie',
            'name' => 'Old Name',
            'metadata' => [],
        ]);

        $tmdb = $this->configuredTmdb();
        $tmdb->method('getDetails')->with('603')->willReturn([
            'name' => 'The Matrix',
            'trailer_url' => 'https://www.youtube.com/watch?v=OFFICIAL1',
            'trailer_key' => 'OFFICIAL1',
            'trailer_site' => 'YouTube',
        ]);

        $captured = null;
        $items->expects($this->once())->method('update')->with(
            'm1',
            $this->callback(function (mixed $data) use (&$captured): bool {
                $captured = $data;
                return is_array($data);
            }),
        );

        $matcher = $this->makeMatcher($items, $tmdb);
        $matcher->applyMatch('m1', '603', 'movie');

        $this->assertIsArray($captured);
        /** @var array<string, mixed> $meta */
        $meta = $captured['metadata_json'];
        $this->assertSame('https://www.youtube.com/watch?v=OFFICIAL1', $meta['trailer_url']);
        $this->assertSame('OFFICIAL1', $meta['trailer_key']);
        $this->assertSame('YouTube', $meta['trailer_site']);
    }

    public function testApplyMatchSeriesCarriesTrailerFields(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->with('s1')->willReturn([
            'id' => 's1',
            'type' => 'series',
            'name' => 'Some Show',
            'metadata' => [],
        ]);
        $items->method('findByParent')->willReturn([]);

        $tmdb = $this->configuredTmdb();
        $tmdb->method('getTvDetails')->with('1399')->willReturn([
            'name' => 'Some Show',
            'trailer_url' => 'https://www.youtube.com/watch?v=TVOFFICIAL',
            'trailer_key' => 'TVOFFICIAL',
            'trailer_site' => 'YouTube',
        ]);

        $captured = null;
        $items->expects($this->once())->method('update')->with(
            's1',
            $this->callback(function (mixed $data) use (&$captured): bool {
                $captured = $data;
                return is_array($data);
            }),
        );

        $matcher = $this->makeMatcher($items, $tmdb);
        $matcher->applyMatch('s1', '1399', 'tv');

        $this->assertIsArray($captured);
        /** @var array<string, mixed> $meta */
        $meta = $captured['metadata_json'];
        $this->assertSame('https://www.youtube.com/watch?v=TVOFFICIAL', $meta['trailer_url']);
        $this->assertSame('TVOFFICIAL', $meta['trailer_key']);
        $this->assertSame('YouTube', $meta['trailer_site']);
    }

    public function testApplyMatchUnknownItemReturnsNotMatched(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(null);
        $items->expects($this->never())->method('update');

        $tmdb = $this->configuredTmdb();
        $tmdb->expects($this->never())->method('getDetails');

        $matcher = $this->makeMatcher($items, $tmdb);
        $result = $matcher->applyMatch('missing', '603', 'movie');

        $this->assertFalse($result['matched']);
    }

    public function testApplyMatchNoTmdbDetailsReturnsNotMatched(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'type' => 'movie', 'metadata' => []]);
        $items->expects($this->never())->method('update');

        $tmdb = $this->configuredTmdb();
        $tmdb->method('getDetails')->willReturn([]); // no usable details

        $matcher = $this->makeMatcher($items, $tmdb);
        $result = $matcher->applyMatch('m1', '0', 'movie');

        $this->assertFalse($result['matched']);
    }

    public function testApplyWithoutTmdbThrowsUnconfigured(): void
    {
        $matcher = $this->makeMatcher($this->createMock(ItemRepository::class), null);
        $this->expectException(TmdbUnconfiguredException::class);
        $matcher->applyMatch('m1', '603', 'movie');
    }

    public function testSearchWithEmptyApiKeyThrowsUnconfigured(): void
    {
        // A TmdbProvider IS wired (as in production via the container) but has
        // an empty API key — must still surface tmdb_unconfigured, not an empty
        // result from an unauthenticated TMDB call.
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(false);
        $tmdb->expects($this->never())->method('search');
        $tmdb->expects($this->never())->method('searchTv');

        $matcher = $this->makeMatcher($this->createMock(ItemRepository::class), $tmdb);
        $this->expectException(TmdbUnconfiguredException::class);
        $matcher->searchCandidates('foo', 'movie');
    }

    public function testApplyWithEmptyApiKeyThrowsUnconfigured(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(false);
        $tmdb->expects($this->never())->method('getDetails');
        $tmdb->expects($this->never())->method('getTvDetails');

        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->never())->method('update');

        $matcher = $this->makeMatcher($items, $tmdb);
        $this->expectException(TmdbUnconfiguredException::class);
        $matcher->applyMatch('m1', '603', 'movie');
    }

    /**
     * A TmdbProvider mock whose {@see TmdbProvider::hasApiKey()} reports a
     * configured key, so the matcher proceeds to the real search/apply path.
     */
    private function configuredTmdb(): TmdbProvider&MockObject
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        return $tmdb;
    }

    private function makeMatcher(
        ItemRepository $items,
        ?TmdbProvider $tmdb,
        ?SeriesMetadataResolver $series = null
    ): LibraryMetadataMatcher {
        return new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $series ?? $this->createMock(SeriesMetadataResolver::class),
            $this->logger(),
            $tmdb,
        );
    }
}
