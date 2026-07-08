<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Media\Metadata\Resolution\LibraryPriorityResolver;
use Phlix\Media\Metadata\Resolution\PriorityConfig;
use RuntimeException;

/**
 * Unit tests for {@see LibraryMetadataMatcher}.
 *
 * Mocks {@see ItemRepository} + {@see MovieMetadataResolver} and asserts:
 *  - movie-typed items get resolved and their metadata_json merged + persisted
 *    (with metadata_refreshed_at stamped, existing keys preserved);
 *  - non-movie items are skipped (not counted, not resolved, not persisted);
 *  - a resolver returning null leaves the item unchanged (no update);
 *  - a per-item exception is swallowed and the run continues (one bad item does
 *    not abort the whole library).
 *
 * @covers \Phlix\Media\Metadata\LibraryMetadataMatcher
 */
class LibraryMetadataMatcherTest extends TestCase
{
    /**
     * A throwaway mock logger so the matcher's log calls do not hit disk.
     */
    private function makeLogger(): StructuredLogger
    {
        return $this->createMock(StructuredLogger::class);
    }

    /**
     * Movie items are resolved and their metadata merged + persisted; non-movie
     * items are skipped entirely.
     */
    public function testMatchesMovieItemsAndSkipsNonMovies(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->once())
            ->method('getByLibrary')
            ->with('lib-1', 100, 0)
            ->willReturn([
                [
                    'id' => 'item-movie',
                    'type' => 'movie',
                    'name' => 'The Matrix',
                    'metadata_json' => '{}',
                    'metadata' => ['custom_flag' => true, 'year' => 1999],
                ],
                [
                    'id' => 'item-track',
                    'type' => 'track',
                    'name' => 'Some Song',
                    'metadata_json' => '{}',
                    'metadata' => [],
                ],
            ]);

        $resolved = [
            'external_ids' => ['tmdb' => '603', 'imdb' => 'tt0133093'],
            'overview' => 'A hacker learns the truth.',
            'poster_url' => 'https://image.tmdb.org/t/p/w500/poster.jpg',
            'genres' => ['Action'],
            'year' => 1999,
            'imdb_rating' => 8.7,
            'imdb_votes' => 1900000,
            'sources' => ['tmdb', 'imdb'],
        ];

        $resolver = $this->createMock(MovieMetadataResolver::class);
        // Only the movie item is resolved.
        $resolver->expects($this->once())
            ->method('resolve')
            ->with('The Matrix', 1999, [])
            ->willReturn($resolved);

        // Persisted with the merge of existing metadata + resolved details and a
        // metadata_refreshed_at stamp.
        $items->expects($this->once())
            ->method('update')
            ->with(
                'item-movie',
                $this->callback(static function (mixed $data): bool {
                    if (!is_array($data)) {
                        return false;
                    }
                    $meta = $data['metadata_json'] ?? null;
                    if (!is_array($meta)) {
                        return false;
                    }
                    // Existing custom key preserved, resolver keys merged in.
                    return ($meta['custom_flag'] ?? null) === true
                        && ($meta['overview'] ?? null) === 'A hacker learns the truth.'
                        && ($meta['external_ids'] ?? null) === ['tmdb' => '603', 'imdb' => 'tt0133093']
                        && isset($data['metadata_refreshed_at'])
                        && is_string($data['metadata_refreshed_at']);
                })
            );

        $matcher = new LibraryMetadataMatcher($items, $resolver, null, $this->makeLogger());

        $result = $matcher->matchLibrary('lib-1');

        $this->assertSame(['matched' => 1, 'processed' => 1], $result);
    }

    public function testMatchLibraryReportsProgressToTheCallback(): void
    {
        $items = $this->createMock(ItemRepository::class);
        // countMatchable() denominator.
        $items->method('query')->willReturn(['items' => [], 'total' => 5, 'limit' => 1, 'offset' => 0]);
        $items->method('getByLibrary')->willReturn([
            ['id' => 'm1', 'type' => 'movie', 'name' => 'A', 'metadata_json' => '{}', 'metadata' => []],
        ]);
        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->method('resolve')->willReturn(['external_ids' => ['tmdb' => '1'], 'sources' => ['tmdb']]);

        $matcher = new LibraryMetadataMatcher($items, $resolver, null, $this->makeLogger());

        $calls = [];
        $matcher->matchLibrary('lib-1', function (int $processed, int $total, int $matched) use (&$calls): void {
            $calls[] = [$processed, $total, $matched];
        });

        // 1 movie processed, denominator 5 from countMatchable, 1 matched.
        $this->assertNotEmpty($calls);
        $this->assertSame([1, 5, 1], $calls[array_key_last($calls)]);
    }

    /**
     * A `video`-typed item is also treated as a movie.
     */
    public function testMatchesVideoTypedItems(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturn([
            ['id' => 'item-1', 'type' => 'video', 'name' => 'Inception', 'metadata' => []],
        ]);

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->expects($this->once())
            ->method('resolve')
            ->with('Inception', null, [])
            ->willReturn(['external_ids' => ['tmdb' => '27205'], 'sources' => ['tmdb']]);

        $items->expects($this->once())->method('update');

        $matcher = new LibraryMetadataMatcher($items, $resolver, null, $this->makeLogger());

        $this->assertSame(['matched' => 1, 'processed' => 1], $matcher->matchLibrary('lib-1'));
    }

    /**
     * Existing external ids are passed through to the resolver.
     */
    public function testPassesExistingExternalIdsToResolver(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturn([
            [
                'id' => 'item-1',
                'type' => 'movie',
                'name' => 'The Matrix',
                'metadata' => ['external_ids' => ['imdb' => 'tt0133093']],
            ],
        ]);

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->expects($this->once())
            ->method('resolve')
            ->with('The Matrix', null, ['imdb' => 'tt0133093'])
            ->willReturn(['external_ids' => ['imdb' => 'tt0133093', 'tmdb' => '603'], 'sources' => ['tmdb']]);

        $items->expects($this->once())->method('update');

        $matcher = new LibraryMetadataMatcher($items, $resolver, null, $this->makeLogger());

        $this->assertSame(['matched' => 1, 'processed' => 1], $matcher->matchLibrary('lib-1'));
    }

    /**
     * When the resolver returns null (no match), the item is counted as
     * processed but NOT updated.
     */
    public function testResolverNullLeavesItemUnchanged(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturn([
            ['id' => 'item-1', 'type' => 'movie', 'name' => 'Unknown Film', 'metadata' => []],
        ]);

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->expects($this->once())
            ->method('resolve')
            ->willReturn(null);

        // No persistence on a miss.
        $items->expects($this->never())->method('update');

        $matcher = new LibraryMetadataMatcher($items, $resolver, null, $this->makeLogger());

        $this->assertSame(['matched' => 0, 'processed' => 1], $matcher->matchLibrary('lib-1'));
    }

    /**
     * A per-item exception (resolver throws) is swallowed and the run continues
     * with the next item — one bad item does not abort the library.
     */
    public function testPerItemExceptionDoesNotAbortRun(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturn([
            ['id' => 'item-bad', 'type' => 'movie', 'name' => 'Boom', 'metadata' => []],
            ['id' => 'item-good', 'type' => 'movie', 'name' => 'The Matrix', 'metadata' => []],
        ]);

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->method('resolve')->willReturnCallback(
            static function (string $title): array {
                if ($title === 'Boom') {
                    throw new RuntimeException('resolver exploded');
                }
                return ['external_ids' => ['tmdb' => '603'], 'sources' => ['tmdb']];
            }
        );

        // Only the good item is persisted; the bad one is logged + skipped.
        $items->expects($this->once())->method('update')->with('item-good', $this->anything());

        $matcher = new LibraryMetadataMatcher($items, $resolver, null, $this->makeLogger());

        $result = $matcher->matchLibrary('lib-1');

        // Both movie items were processed; only one matched.
        $this->assertSame(['matched' => 1, 'processed' => 2], $result);
    }

    /**
     * An empty library yields zero counts and never calls the resolver.
     */
    public function testEmptyLibraryReturnsZeroCounts(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->once())
            ->method('getByLibrary')
            ->with('lib-empty', 100, 0)
            ->willReturn([]);

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->expects($this->never())->method('resolve');

        $matcher = new LibraryMetadataMatcher($items, $resolver, null, $this->makeLogger());

        $this->assertSame(['matched' => 0, 'processed' => 0], $matcher->matchLibrary('lib-empty'));
    }

    /**
     * A movie item with no usable title is processed but not resolved/persisted.
     */
    public function testItemWithoutTitleIsSkipped(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturn([
            ['id' => 'item-1', 'type' => 'movie', 'name' => '', 'metadata' => []],
        ]);

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->expects($this->never())->method('resolve');
        $items->expects($this->never())->method('update');

        $matcher = new LibraryMetadataMatcher($items, $resolver, null, $this->makeLogger());

        $this->assertSame(['matched' => 0, 'processed' => 1], $matcher->matchLibrary('lib-1'));
    }

    /**
     * The run emits progress log entries as it goes — a start marker, a per-item
     * line (so the log shows items being processed instead of nothing until the
     * end), a per-batch progress summary, and a completion line — rather than a
     * single line written only when the whole run finishes.
     */
    public function testEmitsProgressLogEntriesAsItRuns(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls(
            [
                ['id' => 'm1', 'type' => 'movie', 'name' => 'The Matrix', 'metadata' => []],
                ['id' => 'm2', 'type' => 'movie', 'name' => 'Unknown Film', 'metadata' => []],
            ],
            [],
        );

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->method('resolve')->willReturnCallback(
            static fn (string $title): ?array =>
                $title === 'The Matrix' ? ['external_ids' => ['tmdb' => '603']] : null,
        );

        $infoMessages = [];
        $debugMessages = [];
        $logger = $this->createMock(StructuredLogger::class);
        $logger->method('info')->willReturnCallback(
            static function (string $message) use (&$infoMessages): void {
                $infoMessages[] = $message;
            }
        );
        $logger->method('debug')->willReturnCallback(
            static function (string $message) use (&$debugMessages): void {
                $debugMessages[] = $message;
            }
        );

        $matcher = new LibraryMetadataMatcher($items, $resolver, null, $logger);
        $matcher->matchLibrary('lib-1');

        // Start + per-batch progress + completion are all logged at INFO.
        $this->assertContains('LibraryMetadataMatcher: library match started', $infoMessages);
        $this->assertContains('LibraryMetadataMatcher: library match progress', $infoMessages);
        $this->assertContains('LibraryMetadataMatcher: library match complete', $infoMessages);

        // Each processed item produces a per-item DEBUG line as it happens.
        $this->assertContains('LibraryMetadataMatcher: item matched', $debugMessages);
        $this->assertContains('LibraryMetadataMatcher: item not matched', $debugMessages);
    }

    /**
     * A `series` item is resolved AND its season/episode subtree is enriched:
     * the series gets its poster/overview, the season inherits the series poster,
     * and the episode gets its TMDB title + still.
     */
    public function testMatchesSeriesAndEnrichesSeasonAndEpisodes(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls(
            [['id' => 'series-1', 'type' => 'series', 'name' => '24', 'metadata' => []]],
            [] // second page empty → stop
        );
        // series → [season], season → [episode]
        $items->method('findByParent')->willReturnCallback(static function (string $parentId): array {
            if ($parentId === 'series-1') {
                return [['id' => 'season-1', 'type' => 'season', 'name' => 'Season 1', 'metadata' => ['season' => 1]]];
            }
            if ($parentId === 'season-1') {
                return [['id' => 'ep-1', 'type' => 'episode', 'name' => '24', 'metadata' => ['season' => 1, 'episode' => 1]]];
            }
            return [];
        });

        $movieResolver = $this->createMock(MovieMetadataResolver::class);
        $movieResolver->expects($this->never())->method('resolve');

        $seriesResolver = $this->createMock(\Phlix\Media\Metadata\SeriesMetadataResolver::class);
        $seriesResolver->expects($this->once())
            ->method('resolve')
            ->with('24', null)
            ->willReturn([
                'external_ids' => ['tmdb' => '1668'],
                'tmdb_id' => '1668',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/series.jpg',
                'overview' => 'Real-time thriller.',
                'genres' => ['Drama'],
                'sources' => ['tmdb'],
            ]);
        $seriesResolver->method('resolveSeasonEpisodes')->with('1668', 1)->willReturn([
            'poster_url' => 'https://image.tmdb.org/t/p/w500/s1.jpg',
            'overview' => 'Season one.',
            'episodes' => [
                1 => [
                    'episode_title' => '12:00 A.M. - 1:00 A.M.',
                    'overview' => 'Jack Bauer.',
                    'poster_url' => 'https://image.tmdb.org/t/p/w500/e1.jpg',
                    'air_date' => '2001-11-06',
                    'runtime' => 44,
                ],
            ],
        ]);

        $updates = [];
        $items->method('update')->willReturnCallback(
            static function (string $id, array $data) use (&$updates): void {
                $updates[$id] = is_array($data['metadata_json'] ?? null) ? $data['metadata_json'] : [];
            }
        );

        $matcher = new LibraryMetadataMatcher($items, $movieResolver, $seriesResolver, $this->makeLogger());
        $result = $matcher->matchLibrary('lib-1');

        // Series resolved (counts as the one processed/matched flat-loop item).
        $this->assertSame(['matched' => 1, 'processed' => 1], $result);

        // Series got its TMDB poster + overview.
        $this->assertSame('https://image.tmdb.org/t/p/w500/series.jpg', $updates['series-1']['poster_url']);
        $this->assertSame('Real-time thriller.', $updates['series-1']['overview']);

        // Season got the season poster + overview.
        $this->assertSame('https://image.tmdb.org/t/p/w500/s1.jpg', $updates['season-1']['poster_url']);
        $this->assertSame('Season one.', $updates['season-1']['overview']);

        // Episode got its title + still, preserving the scanner's season/episode.
        $this->assertSame('12:00 A.M. - 1:00 A.M.', $updates['ep-1']['episode_title']);
        $this->assertSame('https://image.tmdb.org/t/p/w500/e1.jpg', $updates['ep-1']['poster_url']);
        $this->assertSame(44, $updates['ep-1']['runtime']);
        $this->assertSame(1, $updates['ep-1']['season']);
        $this->assertSame(1, $updates['ep-1']['episode']);
    }

    /**
     * Episodes get their own cast/crew + per-episode vote average AND inherit the
     * parent series' genres, tags and backdrop (episodes carry none of their own).
     */
    public function testEpisodeGetsCastCrewVoteAndInheritsGenresTagsBackdrop(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls(
            [['id' => 'series-1', 'type' => 'series', 'name' => '24', 'metadata' => []]],
            []
        );
        $items->method('findByParent')->willReturnCallback(static function (string $parentId): array {
            if ($parentId === 'series-1') {
                return [['id' => 'ep-1', 'type' => 'episode', 'name' => '24', 'metadata' => ['season' => 1, 'episode' => 1]]];
            }
            return [];
        });

        $seriesResolver = $this->createMock(\Phlix\Media\Metadata\SeriesMetadataResolver::class);
        $seriesResolver->method('resolve')->willReturn([
            'external_ids' => ['tmdb' => '1668'],
            'tmdb_id' => '1668',
            'poster_url' => 'https://image.tmdb.org/t/p/w500/series.jpg',
            'backdrop_url' => 'https://image.tmdb.org/t/p/w500/back.jpg',
            'overview' => 'Real-time thriller.',
            'genres' => ['Drama', 'Action & Adventure'],
            'tags' => ['terrorism', 'counter terrorism'],
            'sources' => ['tmdb'],
        ]);
        $seriesResolver->method('resolveSeasonEpisodes')->with('1668', 1)->willReturn([
            'poster_url' => 'https://image.tmdb.org/t/p/w500/s1.jpg',
            'overview' => 'Season one.',
            'episodes' => [
                1 => [
                    'episode_title' => '12:00 A.M.',
                    'overview' => 'Jack Bauer.',
                    'poster_url' => 'https://image.tmdb.org/t/p/w500/e1.jpg',
                    'air_date' => '2001-11-06',
                    'runtime' => 44,
                    'vote_average' => 7.8,
                    'cast' => [
                        ['name' => 'Kiefer Sutherland', 'role' => 'Jack Bauer', 'profile_url' => 'https://i/w185/k.jpg'],
                    ],
                    'crew' => [
                        ['name' => 'Stephen Hopkins', 'job' => 'Director', 'profile_url' => null],
                    ],
                ],
            ],
        ]);

        $updates = [];
        $items->method('update')->willReturnCallback(
            static function (string $id, array $data) use (&$updates): void {
                $updates[$id] = is_array($data['metadata_json'] ?? null) ? $data['metadata_json'] : [];
            }
        );

        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $seriesResolver,
            $this->makeLogger()
        );
        $matcher->matchLibrary('lib-1');

        $ep = $updates['ep-1'];
        // Episode-level cast/crew in the canonical people shape + per-episode rating.
        $cast = $ep['cast'];
        $this->assertIsArray($cast);
        $this->assertIsArray($cast[0]);
        $this->assertSame('Kiefer Sutherland', $cast[0]['name']);
        $this->assertSame('Jack Bauer', $cast[0]['role']);
        $this->assertSame('https://i/w185/k.jpg', $cast[0]['profile_url']);
        $crew = $ep['crew'];
        $this->assertIsArray($crew);
        $this->assertIsArray($crew[0]);
        $this->assertSame('Stephen Hopkins', $crew[0]['name']);
        $this->assertSame('Director', $crew[0]['job']);
        $this->assertSame(7.8, $ep['vote_average']);
        // Inherited series-level fields.
        $this->assertSame(['Drama', 'Action & Adventure'], $ep['genres']);
        $this->assertSame(['terrorism', 'counter terrorism'], $ep['tags']);
        $this->assertSame('https://image.tmdb.org/t/p/w500/back.jpg', $ep['backdrop_url']);
        // The `actors` flat-array landmine is not touched by episode enrichment.
        $this->assertArrayNotHasKey('actors', $ep);
    }

    /**
     * An episode whose TMDB still is missing falls back to the series poster so it
     * never renders blank.
     */
    public function testEpisodeFallsBackToSeriesPosterWhenNoStill(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls(
            [['id' => 'series-1', 'type' => 'series', 'name' => 'Show', 'metadata' => []]],
            []
        );
        $items->method('findByParent')->willReturnCallback(static function (string $parentId): array {
            if ($parentId === 'series-1') {
                return [['id' => 'ep-9', 'type' => 'episode', 'name' => 'Show', 'metadata' => ['season' => 2, 'episode' => 9]]];
            }
            return [];
        });

        $seriesResolver = $this->createMock(\Phlix\Media\Metadata\SeriesMetadataResolver::class);
        $seriesResolver->method('resolve')->willReturn([
            'external_ids' => ['tmdb' => '50'],
            'tmdb_id' => '50',
            'poster_url' => 'https://image.tmdb.org/t/p/w500/series.jpg',
            'sources' => ['tmdb'],
        ]);
        // Season known but episode 9 absent / no still.
        $seriesResolver->method('resolveSeasonEpisodes')->willReturn([
            'poster_url' => null,
            'overview' => '',
            'episodes' => [],
        ]);

        $updates = [];
        $items->method('update')->willReturnCallback(
            static function (string $id, array $data) use (&$updates): void {
                $updates[$id] = is_array($data['metadata_json'] ?? null) ? $data['metadata_json'] : [];
            }
        );

        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $seriesResolver,
            $this->makeLogger()
        );
        $matcher->matchLibrary('lib-1');

        $this->assertSame('https://image.tmdb.org/t/p/w500/series.jpg', $updates['ep-9']['poster_url']);
    }

    /**
     * When the series container carries the scanner's folder-derived
     * series_title (+ year) hint, the TMDB TV search is driven by THAT title and
     * year — not by the (noisy) item name / filename guess.
     */
    public function testMatchSeriesPrefersFolderDerivedTitleAndYearHint(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls(
            [[
                'id' => 'series-1',
                'type' => 'series',
                // The item NAME is the clean folder title; but the decisive bit
                // is the hint in metadata, which must win regardless.
                'name' => 'Whatever Filename Guess',
                'metadata' => [
                    'series_title' => 'Assassination Classroom',
                    'year' => 2013,
                ],
            ]],
            []
        );
        $items->method('findByParent')->willReturn([]);

        $seriesResolver = $this->createMock(\Phlix\Media\Metadata\SeriesMetadataResolver::class);
        // The hint title + year drive the search — NOT 'Whatever Filename Guess'.
        $seriesResolver->expects($this->once())
            ->method('resolve')
            ->with('Assassination Classroom', 2013)
            ->willReturn([
                'external_ids' => ['tmdb' => '42'],
                'tmdb_id' => '42',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/ac.jpg',
                'sources' => ['tmdb'],
            ]);

        $items->method('update');

        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $seriesResolver,
            $this->makeLogger()
        );

        $this->assertSame(['matched' => 1, 'processed' => 1], $matcher->matchLibrary('lib-1'));
    }

    /**
     * The folder hint title is used verbatim (no scene-normalisation that would
     * mangle a clean title), and a missing year hint passes null to the resolver.
     */
    public function testMatchSeriesUsesHintTitleVerbatimWithNullYearWhenAbsent(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls(
            [[
                'id' => 'series-1',
                'type' => 'series',
                'name' => 'noise',
                'metadata' => ['series_title' => 'Cowboy Bebop'], // no year hint
            ]],
            []
        );
        $items->method('findByParent')->willReturn([]);

        $seriesResolver = $this->createMock(\Phlix\Media\Metadata\SeriesMetadataResolver::class);
        $seriesResolver->expects($this->once())
            ->method('resolve')
            ->with('Cowboy Bebop', null)
            ->willReturn(['external_ids' => ['tmdb' => '30991'], 'tmdb_id' => '30991', 'sources' => ['tmdb']]);

        $items->method('update');

        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $seriesResolver,
            $this->makeLogger()
        );

        $this->assertSame(['matched' => 1, 'processed' => 1], $matcher->matchLibrary('lib-1'));
    }

    /**
     * Falls back to the legacy name + scene-normalisation path when NO folder
     * hint is present on the series container.
     */
    public function testMatchSeriesFallsBackToNameWhenNoHint(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls(
            [[
                'id' => 'series-1',
                'type' => 'series',
                // No series_title hint → legacy path; name carries a scene-style
                // year that normalisation should extract.
                'name' => 'Some.Show.2016',
                'metadata' => [],
            ]],
            []
        );
        $items->method('findByParent')->willReturn([]);

        $seriesResolver = $this->createMock(\Phlix\Media\Metadata\SeriesMetadataResolver::class);
        $seriesResolver->expects($this->once())
            ->method('resolve')
            ->with('Some Show', 2016)
            ->willReturn(['external_ids' => ['tmdb' => '7'], 'tmdb_id' => '7', 'sources' => ['tmdb']]);

        $items->method('update');

        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $seriesResolver,
            $this->makeLogger()
        );

        $this->assertSame(['matched' => 1, 'processed' => 1], $matcher->matchLibrary('lib-1'));
    }

    /**
     * Without a series resolver the matcher is movie-only: series items are skipped.
     */
    public function testSeriesSkippedWhenNoSeriesResolver(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls(
            [['id' => 'series-1', 'type' => 'series', 'name' => '24', 'metadata' => []]],
            []
        );
        $items->expects($this->never())->method('update');

        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            null,
            $this->makeLogger()
        );

        $this->assertSame(['matched' => 0, 'processed' => 0], $matcher->matchLibrary('lib-1'));
    }

    /**
     * When the LibraryManager + LibraryPriorityResolver deps are present, the
     * matcher loads the library's `options.metadata_priority` override, builds
     * the EFFECTIVE per-library PriorityConfig, and passes it as the resolver's
     * override argument for every movie in the library (item 5).
     */
    public function testMatchLibraryPassesEffectivePriorityToResolver(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls(
            [['id' => 'm1', 'type' => 'movie', 'name' => 'The Matrix', 'metadata' => []]],
            []
        );
        $items->method('update');

        // The library carries a per-library override (already surfaced as a
        // top-level key by LibraryRow::toArray()).
        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->once())
            ->method('getLibrary')
            ->with('lib-1')
            ->willReturn([
                'id' => 'lib-1',
                'type' => 'movie',
                'metadata_priority' => ['movie' => ['imdb', 'tmdb']],
            ]);

        // Effective config built by the real resolver over a TMDB-first global.
        $global = new PriorityConfig(['movie' => ['tmdb', 'imdb']]);
        $priorityResolver = new LibraryPriorityResolver($global);
        $expectedEffective = $priorityResolver->effectiveFor(['movie' => ['imdb', 'tmdb']]);

        $resolver = $this->createMock(MovieMetadataResolver::class);
        // The 4th arg must be the effective (IMDb-first) per-library config.
        $resolver->expects($this->once())
            ->method('resolve')
            ->with(
                'The Matrix',
                null,
                [],
                $this->callback(static function (mixed $override) use ($expectedEffective): bool {
                    return $override instanceof PriorityConfig
                        && $override->orderFor('movie') === $expectedEffective->orderFor('movie')
                        && $override->orderFor('movie') === ['imdb', 'tmdb'];
                })
            )
            ->willReturn(['external_ids' => ['tmdb' => '603'], 'sources' => ['tmdb']]);

        $matcher = new LibraryMetadataMatcher(
            $items,
            $resolver,
            null,
            $this->makeLogger(),
            null,
            null,
            $libraries,
            $priorityResolver
        );

        $this->assertSame(['matched' => 1, 'processed' => 1], $matcher->matchLibrary('lib-1'));
    }

    /**
     * A library WITHOUT an override yields the global config unchanged: the
     * effective config passed to the resolver is the same global instance.
     */
    public function testMatchLibraryUsesGlobalWhenNoOverride(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls(
            [['id' => 'm1', 'type' => 'movie', 'name' => 'A', 'metadata' => []]],
            []
        );
        $items->method('update');

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->method('getLibrary')->with('lib-1')->willReturn([
            'id' => 'lib-1',
            'type' => 'movie',
            'metadata_priority' => null,
        ]);

        $global = new PriorityConfig(['movie' => ['tmdb', 'imdb']]);
        $priorityResolver = new LibraryPriorityResolver($global);

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->expects($this->once())
            ->method('resolve')
            ->with(
                'A',
                null,
                [],
                // No override → the SAME global instance is forwarded.
                $this->identicalTo($global)
            )
            ->willReturn(['external_ids' => ['tmdb' => '1'], 'sources' => ['tmdb']]);

        $matcher = new LibraryMetadataMatcher(
            $items,
            $resolver,
            null,
            $this->makeLogger(),
            null,
            null,
            $libraries,
            $priorityResolver
        );

        $this->assertSame(['matched' => 1, 'processed' => 1], $matcher->matchLibrary('lib-1'));
    }

    /**
     * Backward-compat: with the per-library deps ABSENT (legacy construction),
     * the resolver is called with a null override — behaviour is exactly as
     * before and no library is loaded.
     */
    public function testMatchLibraryNullOverrideWhenDepsAbsent(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls(
            [['id' => 'm1', 'type' => 'movie', 'name' => 'A', 'metadata' => []]],
            []
        );
        $items->method('update');

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->expects($this->once())
            ->method('resolve')
            ->with('A', null, [], null)
            ->willReturn(['external_ids' => ['tmdb' => '1'], 'sources' => ['tmdb']]);

        // No LibraryManager / LibraryPriorityResolver injected.
        $matcher = new LibraryMetadataMatcher($items, $resolver, null, $this->makeLogger());

        $this->assertSame(['matched' => 1, 'processed' => 1], $matcher->matchLibrary('lib-1'));
    }
}
