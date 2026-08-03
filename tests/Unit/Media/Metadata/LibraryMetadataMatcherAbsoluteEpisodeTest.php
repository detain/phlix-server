<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Media\Metadata\SeriesMetadataResolver;

/**
 * Unit tests for the absolute-episode-numbering rescue pass in
 * {@see LibraryMetadataMatcher} (bucket C).
 *
 * The numeric index at the heart of `enrichEpisode()` —
 * `$seasonData['episodes'][$episodeNumber]` — simply runs off the end of a
 * season when the library numbers a show continuously. Measured on production
 * 2026-07-28 that is 742 of 1,328 unmatched episodes (55.9%).
 *
 * Each fixture reproduces a real production series' shape, and the refuse cases
 * are the ones that would have been silently mis-assigned by a naive prefix sum.
 */
class LibraryMetadataMatcherAbsoluteEpisodeTest extends TestCase
{
    private function makeLogger(): StructuredLogger
    {
        return $this->createMock(StructuredLogger::class);
    }

    /**
     * Build a season episode-list in the shape
     * {@see SeriesMetadataResolver::resolveSeasonEpisodes()} returns.
     *
     * @param list<int> $numbers
     * @param list<int> $untitled Numbers the provider LISTS but has no title for —
     *     the bucket-D shape (a genuine hole inside a covered range).
     * @param bool      $rich     Also supply the per-episode `overview`/`poster_url`
     *     and the season poster, so the idempotence test can observe whether a
     *     second refresh reverts them to the series/season fallbacks.
     * @return array<string, mixed>
     */
    private function season(array $numbers, string $prefix, array $untitled = [], bool $rich = false): array
    {
        $episodes = [];
        foreach ($numbers as $n) {
            $episodes[$n] = [
                'episode_title' => in_array($n, $untitled, true) ? null : $prefix . ' ' . $n,
                'overview' => $rich ? 'EPISODE OVERVIEW ' . $n : null,
                'poster_url' => $rich ? 'https://img/still-' . $n . '.jpg' : null,
                'still_url' => 'https://img/still-' . $n . '.jpg',
                'air_date' => null,
                'runtime' => null,
                'vote_average' => null,
                'cast' => [],
                'crew' => [],
            ];
        }
        return [
            'poster_url' => $rich ? 'https://img/season.jpg' : null,
            'overview' => '',
            'episodes' => $episodes,
        ];
    }

    /**
     * Wire a one-series library whose single season container holds $stored
     * episode numbers, and whose provider seasons are $providerSeasons
     * (season number => list of episode numbers, per that season's own numbering).
     *
     * The captured updates are handed back in an ArrayObject: the matcher writes
     * them from a callback long after this method returns, and a by-value array
     * would snapshot an empty one.
     *
     * @param list<int>              $stored
     * @param array<int, list<int>>  $providerSeasons
     * @param array<int, string>     $existingTitles  Stored episode number => an already-persisted title.
     * @param array<int, list<int>>  $providerUntitled Provider season => numbers it lists but has no title for.
     *
     * @return array{0: LibraryMetadataMatcher, 1: \ArrayObject<string, array<string, mixed>>}
     */
    private function makeMatcher(
        array $stored,
        array $providerSeasons,
        array $existingTitles = [],
        array $providerUntitled = []
    ): array {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls(
            [['id' => 'series-1', 'type' => 'series', 'name' => 'Show', 'metadata' => []]],
            []
        );

        $episodes = [];
        foreach ($stored as $n) {
            $meta = ['season' => 1, 'episode' => $n];
            if (isset($existingTitles[$n])) {
                $meta['episode_title'] = $existingTitles[$n];
            }
            $episodes[] = [
                'id' => 'ep-' . $n,
                'type' => 'episode',
                'name' => 'Show ' . $n,
                'metadata' => $meta,
            ];
        }
        $items->method('findByParent')->willReturnCallback(
            static function (string $parentId) use ($episodes): array {
                if ($parentId === 'series-1') {
                    return [[
                        'id' => 'season-1',
                        'type' => 'season',
                        'name' => 'Season 1',
                        'metadata' => ['season' => 1],
                    ]];
                }
                return $parentId === 'season-1' ? $episodes : [];
            }
        );

        /** @var \ArrayObject<string, array<string, mixed>> $updates */
        $updates = new \ArrayObject();
        $items->method('update')->willReturnCallback(
            static function (string $id, array $data) use ($updates): void {
                $updates[$id] = is_array($data['metadata_json'] ?? null) ? $data['metadata_json'] : [];
            }
        );

        $seriesResolver = $this->createMock(SeriesMetadataResolver::class);
        $seriesResolver->method('resolve')->willReturn([
            'external_ids' => ['tmdb' => '31910'],
            'tmdb_id' => '31910',
            'poster_url' => 'https://img/series.jpg',
            'overview' => 'A long show.',
            'sources' => ['tmdb'],
        ]);
        $seasonFixtures = [];
        foreach ($providerSeasons as $number => $numbers) {
            $seasonFixtures[$number] = $this->season($numbers, 'Title', $providerUntitled[$number] ?? []);
        }
        $seriesResolver->method('resolveSeasonEpisodes')->willReturnCallback(
            static function (string $tmdbId, int $season) use ($seasonFixtures): array {
                return $seasonFixtures[$season] ?? ['poster_url' => null, 'overview' => '', 'episodes' => []];
            }
        );

        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $seriesResolver,
            $this->makeLogger()
        );

        return [$matcher, $updates];
    }

    /**
     * `Naruto Shippuuden` shape: 500 files in one stored season, and TMDB numbers
     * the show continuously too (season 1 = 1–32, season 2 = 33–53, …). The
     * overflowing episodes are looked up in the season that really holds them.
     */
    public function testProviderChainRescuesContinuouslyNumberedEpisodes(): void
    {
        [$matcher, $updates] = $this->makeMatcher(
            range(1, 71),
            [1 => range(1, 32), 2 => range(33, 53), 3 => range(54, 71)]
        );
        $matcher->matchLibrary('lib-1');

        // In range already — resolved by the ordinary numeric index.
        $this->assertSame('Title 32', $updates['ep-32']['episode_title']);
        $this->assertArrayNotHasKey('absolute_number', $updates['ep-32']);

        // Beyond season 1 — rescued from the season the provider really holds it in.
        $this->assertSame('Title 33', $updates['ep-33']['episode_title']);
        $this->assertSame('Title 71', $updates['ep-71']['episode_title']);
        $this->assertSame('https://img/still-71.jpg', $updates['ep-71']['still_url']);
    }

    /**
     * The rescue records provenance and leaves the scanner-owned identity fields
     * exactly as they were — re-numbering and re-parenting belong to the reindex
     * step, not here.
     */
    public function testRescueRecordsProvenanceAndNeverRenumbersTheRow(): void
    {
        [$matcher, $updates] = $this->makeMatcher(
            range(1, 71),
            [1 => range(1, 32), 2 => range(33, 53), 3 => range(54, 71)]
        );
        $matcher->matchLibrary('lib-1');

        $this->assertSame(60, $updates['ep-60']['absolute_number']);
        $this->assertSame(3, $updates['ep-60']['matched_season']);
        $this->assertSame(60, $updates['ep-60']['matched_episode']);
        // The row still says what the scanner said.
        $this->assertSame(1, $updates['ep-60']['season']);
        $this->assertSame(60, $updates['ep-60']['episode']);
    }

    /**
     * `Turn A Gundam` shape: one continuous 50-episode run stored under season 1
     * while TMDB numbers per season (1–25 / 1–25). Here the number really is an
     * ordinal and has to be translated arithmetically.
     */
    public function testPrefixSumRescuesWhenTheProviderNumbersPerSeason(): void
    {
        [$matcher, $updates] = $this->makeMatcher(
            range(1, 50),
            [1 => range(1, 25), 2 => range(1, 25)]
        );
        $matcher->matchLibrary('lib-1');

        $this->assertSame('Title 25', $updates['ep-25']['episode_title']);
        $this->assertSame('Title 1', $updates['ep-26']['episode_title']);
        $this->assertSame('Title 19', $updates['ep-44']['episode_title']);
        $this->assertSame(44, $updates['ep-44']['absolute_number']);
        $this->assertSame(2, $updates['ep-44']['matched_season']);
        $this->assertSame(19, $updates['ep-44']['matched_episode']);
    }

    /**
     * `Nurarihyon no Mago` shape: the stored maximum (25) does not equal the
     * provider's total (48), so the numbering is not provably absolute. The
     * overflow stays unmatched rather than being attached to season 2.
     */
    public function testRefusesWhenTheStoredMaximumDoesNotEqualTheProviderTotal(): void
    {
        [$matcher, $updates] = $this->makeMatcher(
            range(1, 25),
            [1 => range(1, 24), 2 => range(1, 24)]
        );
        $matcher->matchLibrary('lib-1');

        $this->assertSame('Title 24', $updates['ep-24']['episode_title']);
        $this->assertArrayNotHasKey('episode_title', $updates['ep-25']);
        $this->assertArrayNotHasKey('absolute_number', $updates['ep-25']);
    }

    /**
     * `Hajime no Ippo` shape: a single-season provider entry with far fewer
     * episodes than the library holds — a wrong-entity match wearing bucket C's
     * clothes. Nothing to translate into, so nothing is translated.
     */
    public function testRefusesASingleSeasonProvider(): void
    {
        [$matcher, $updates] = $this->makeMatcher(range(1, 75), [1 => range(1, 25)]);
        $matcher->matchLibrary('lib-1');

        $this->assertSame('Title 25', $updates['ep-25']['episode_title']);
        $this->assertArrayNotHasKey('episode_title', $updates['ep-26']);
        $this->assertArrayNotHasKey('episode_title', $updates['ep-75']);
    }

    /**
     * `Battlestar Galactica (1978)` shape: the stored run has holes, so the
     * library is not enumerating the show end to end and no reading is safe.
     */
    public function testRefusesWhenTheStoredRunHasHoles(): void
    {
        $stored = array_values(array_diff(range(1, 50), [5, 12, 19]));
        [$matcher, $updates] = $this->makeMatcher($stored, [1 => range(1, 25), 2 => range(1, 25)]);
        $matcher->matchLibrary('lib-1');

        $this->assertArrayNotHasKey('episode_title', $updates['ep-44']);
        $this->assertArrayNotHasKey('absolute_number', $updates['ep-44']);
    }

    /**
     * A gap INSIDE the provider's range for the stored season is a genuine
     * provider hole (bucket D, 5 rows estate-wide). Only numbers past the END of
     * the season's list are ever re-read, so episode 17 — which the provider lists
     * but has no title for — is left exactly where it is while the genuine
     * overflow above it is still rescued.
     */
    public function testNeverRemapsAGapInsideTheProviderRange(): void
    {
        [$matcher, $updates] = $this->makeMatcher(
            range(1, 71),
            [1 => range(1, 32), 2 => range(33, 53), 3 => range(54, 71)],
            [],
            [1 => [17]]
        );
        $matcher->matchLibrary('lib-1');

        $this->assertArrayNotHasKey('episode_title', $updates['ep-17']);
        $this->assertArrayNotHasKey('absolute_number', $updates['ep-17']);
        $this->assertSame('Title 40', $updates['ep-40']['episode_title']);
    }

    /**
     * A hole in the provider's own season NUMBERING (not just a missing title)
     * breaks the proof that the provider numbers the show continuously, so the
     * rescue is disabled for the WHOLE series. Strict on purpose: the alternative
     * is trusting a season range the provider only partly filled in.
     */
    public function testAHoleInTheProviderNumberingDisablesTheWholeSeries(): void
    {
        $holed = array_values(array_diff(range(1, 32), [17]));
        [$matcher, $updates] = $this->makeMatcher(
            range(1, 71),
            [1 => $holed, 2 => range(33, 53), 3 => range(54, 71)]
        );
        $matcher->matchLibrary('lib-1');

        $this->assertArrayNotHasKey('episode_title', $updates['ep-40']);
        $this->assertArrayNotHasKey('absolute_number', $updates['ep-40']);
        $this->assertArrayNotHasKey('episode_title', $updates['ep-71']);
    }

    /**
     * An episode that already carries a title is never re-read, even when its
     * number overflows — moving a correct row is a regression, not a fix.
     */
    public function testNeverRereadsAnEpisodeThatAlreadyHasATitle(): void
    {
        [$matcher, $updates] = $this->makeMatcher(
            range(1, 71),
            [1 => range(1, 32), 2 => range(33, 53), 3 => range(54, 71)],
            [40 => 'A title from somewhere else']
        );
        $matcher->matchLibrary('lib-1');

        $this->assertSame('A title from somewhere else', $updates['ep-40']['episode_title']);
        $this->assertArrayNotHasKey('absolute_number', $updates['ep-40']);
        $this->assertSame('Title 41', $updates['ep-41']['episode_title']);
    }

    /**
     * A translated slot the provider has no title for leaves the row unmatched
     * rather than stamping a blank — and its neighbours are still rescued.
     */
    public function testRefusesWhenTheTranslatedSlotHasNoProviderTitle(): void
    {
        [$matcher, $updates] = $this->makeMatcher(
            range(1, 50),
            [1 => range(1, 25), 2 => range(1, 25)],
            [],
            [2 => [19]] // the target of stored episode 44
        );
        $matcher->matchLibrary('lib-1');

        $this->assertArrayNotHasKey('episode_title', $updates['ep-44']);
        $this->assertArrayNotHasKey('absolute_number', $updates['ep-44']);
        $this->assertSame('Title 20', $updates['ep-45']['episode_title']);
    }

    /**
     * The pass costs nothing when nothing overflows: no season beyond the ones the
     * ordinary path needs is ever fetched.
     */
    public function testMakesNoExtraProviderCallsWhenNothingOverflows(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls(
            [['id' => 'series-1', 'type' => 'series', 'name' => 'Show', 'metadata' => []]],
            []
        );
        $items->method('findByParent')->willReturnCallback(static function (string $parentId): array {
            if ($parentId === 'series-1') {
                return [[
                    'id' => 'season-1',
                    'type' => 'season',
                    'name' => 'Season 1',
                    'metadata' => ['season' => 1],
                ]];
            }
            return $parentId === 'season-1'
                ? [['id' => 'ep-1', 'type' => 'episode', 'name' => 'S', 'metadata' => ['season' => 1, 'episode' => 1]]]
                : [];
        });

        $seriesResolver = $this->createMock(SeriesMetadataResolver::class);
        $seriesResolver->method('resolve')->willReturn([
            'external_ids' => ['tmdb' => '31910'],
            'tmdb_id' => '31910',
            'sources' => ['tmdb'],
        ]);
        $fixture = $this->season(range(1, 32), 'Title');
        $seriesResolver->expects($this->once())
            ->method('resolveSeasonEpisodes')
            ->with('31910', 1)
            ->willReturn($fixture);

        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $seriesResolver,
            $this->makeLogger()
        );
        $matcher->matchLibrary('lib-1');
    }

    // =====================================================================
    // Reviewer findings 1–3, 2026-07-28. Everything below reproduces a shape
    // the first implementation got wrong, or pins a guard that survived the
    // first mutation round.
    // =====================================================================

    /**
     * Wire a one-series library across ARBITRARY stored seasons, with a STATEFUL
     * repository: what one `matchLibrary()` run writes is what the next one reads,
     * which is what makes the idempotence test possible at all.
     *
     * Episode ids are `ep-{storedSeason}-{number}`; season container ids are
     * `season-{storedSeason}`. Provider seasons the fixture does not name resolve
     * to an EMPTY episode list — exactly what `SeriesMetadataResolver::
     * resolveSeasonEpisodes()` returns when TMDB fails or the season is unknown.
     *
     * Kept separate from {@see makeMatcher()} on purpose: that helper's one-season,
     * `ep-{n}` id scheme is baked into the tests above it, and rewriting them to
     * prove a different point would be churn.
     *
     * @param array<int, list<int>>              $storedSeasons    Stored season => episode numbers held.
     * @param array<int, list<int>>              $providerSeasons  Provider season => episode numbers.
     * @param array<int, list<int>>              $providerUntitled Provider season => numbers it lists untitled.
     * @param bool                               $rich             Give the provider per-episode overview/poster.
     * @param array<string, array<string, mixed>> $seedMeta        `"season:number"` => metadata already on the row.
     *
     * The third element is the log of provider season fetches, in call order, so a
     * test can pin the class's documented "zero extra provider calls" cost contract.
     * Callers that do not need it simply destructure two elements.
     *
     * @return array{
     *     0: LibraryMetadataMatcher,
     *     1: \ArrayObject<string, array<string, mixed>>,
     *     2: \ArrayObject<int, int>
     * }
     */
    private function makeSeriesMatcher(
        array $storedSeasons,
        array $providerSeasons,
        array $providerUntitled = [],
        bool $rich = false,
        array $seedMeta = []
    ): array {
        /** @var array<string, array<string, mixed>> $rows */
        $rows = ['series-1' => ['id' => 'series-1', 'type' => 'series', 'name' => 'Show', 'metadata' => []]];
        /** @var array<string, list<string>> $children */
        $children = ['series-1' => []];

        foreach ($storedSeasons as $season => $numbers) {
            $seasonId = 'season-' . $season;
            $rows[$seasonId] = [
                'id' => $seasonId,
                'type' => 'season',
                'name' => 'Season ' . $season,
                'metadata' => ['season' => $season],
            ];
            $children['series-1'][] = $seasonId;
            $children[$seasonId] = [];
            foreach ($numbers as $n) {
                $id = 'ep-' . $season . '-' . $n;
                $rows[$id] = [
                    'id' => $id,
                    'type' => 'episode',
                    'name' => 'Show ' . $season . 'x' . $n,
                    'metadata' => array_merge(
                        ['season' => $season, 'episode' => $n],
                        $seedMeta[$season . ':' . $n] ?? []
                    ),
                ];
                $children[$seasonId][] = $id;
            }
        }

        /** @var \ArrayObject<string, array<string, mixed>> $updates */
        $updates = new \ArrayObject();

        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnCallback(
            static function () use (&$rows): array {
                return [$rows['series-1']];
            }
        );
        $items->method('findByParent')->willReturnCallback(
            static function (string $parentId) use (&$rows, &$children): array {
                $out = [];
                foreach ($children[$parentId] ?? [] as $id) {
                    $out[] = $rows[$id];
                }
                return $out;
            }
        );
        $items->method('update')->willReturnCallback(
            static function (string $id, array $data) use (&$rows, $updates): void {
                $meta = is_array($data['metadata_json'] ?? null) ? $data['metadata_json'] : [];
                $updates[$id] = $meta;
                if (isset($rows[$id])) {
                    $rows[$id]['metadata'] = $meta;
                    $rows[$id]['metadata_refreshed_at'] = $data['metadata_refreshed_at'] ?? null;
                }
            }
        );

        $seriesResolver = $this->createMock(SeriesMetadataResolver::class);
        $seriesResolver->method('resolve')->willReturn([
            'external_ids' => ['tmdb' => '31910'],
            'tmdb_id' => '31910',
            'poster_url' => 'https://img/series.jpg',
            'overview' => 'SERIES OVERVIEW',
            'sources' => ['tmdb'],
        ]);
        $seasonFixtures = [];
        foreach ($providerSeasons as $number => $numbers) {
            $seasonFixtures[$number] = $this->season($numbers, 'Title', $providerUntitled[$number] ?? [], $rich);
        }
        /** @var \ArrayObject<int, int> $seasonCalls */
        $seasonCalls = new \ArrayObject();
        $seriesResolver->method('resolveSeasonEpisodes')->willReturnCallback(
            static function (string $tmdbId, int $season) use ($seasonFixtures, $seasonCalls): array {
                $seasonCalls[] = $season;
                return $seasonFixtures[$season] ?? ['poster_url' => null, 'overview' => '', 'episodes' => []];
            }
        );

        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $seriesResolver,
            $this->makeLogger()
        );

        return [$matcher, $updates, $seasonCalls];
    }

    /**
     * The REAL `Naruto Shippuuden` (TMDB 31910) season shape, read off the cached
     * fixtures on 2026-07-28: 20 seasons, numbered continuously 1–500.
     *
     * @return array<int, list<int>> Provider season => episode numbers.
     */
    private function narutoChain(): array
    {
        $sizes = [32, 21, 18, 17, 24, 31, 8, 24, 21, 25, 21, 33, 20, 25, 28, 13, 11, 21, 20, 87];
        $seasons = [];
        $next = 1;
        foreach ($sizes as $i => $size) {
            $seasons[$i + 1] = range($next, $next + $size - 1);
            $next += $size;
        }
        return $seasons;
    }

    /**
     * How many rows the absolute pass stamped.
     *
     * @param \ArrayObject<string, array<string, mixed>> $updates
     */
    private function rescuedCount(\ArrayObject $updates): int
    {
        $n = 0;
        foreach ($updates as $meta) {
            if (isset($meta['absolute_number'])) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Every row the absolute pass stamped, and WHAT it stamped:
     * `row id => [matched_season, matched_episode, episode_title]`.
     *
     * Asserted against `[]` rather than counted, so a regression prints the exact
     * mis-assignments instead of just a number.
     *
     * @param \ArrayObject<string, array<string, mixed>> $updates
     *
     * @return array<string, array{0: mixed, 1: mixed, 2: mixed}>
     */
    private function rescuedStamps(\ArrayObject $updates): array
    {
        $out = [];
        foreach ($updates as $id => $meta) {
            if (isset($meta['absolute_number'])) {
                $out[$id] = [
                    $meta['matched_season'] ?? null,
                    $meta['matched_episode'] ?? null,
                    $meta['episode_title'] ?? null,
                ];
            }
        }
        return $out;
    }

    /**
     * FINDING 1(a). A library holding ONE per-season-numbered season that is not
     * the first one.
     *
     * Stored season 2 holds the complete run 1..30; the provider numbers the show
     * continuously (1–12 / 13–24 / 25–36 / 37–48). A chain exists, so before the
     * chain-extent relation was added every out-of-range stored number was stamped
     * with `locate()`'s answer: stored `S02E25` was written with the provider's
     * SEASON 3 title and `matched_season => 3`. Under the provider's own ordering
     * the library's "season 2 episode 25" is absolute 37, not 25.
     *
     * The relation refuses it by construction: 30 !== 48.
     */
    public function testRefusesAPerSeasonNumberedSeasonWhoseChainRunsLonger(): void
    {
        [$matcher, $updates] = $this->makeSeriesMatcher(
            [2 => range(1, 30)],
            [1 => range(1, 12), 2 => range(13, 24), 3 => range(25, 36), 4 => range(37, 48)]
        );
        $matcher->matchLibrary('lib-1');

        // In range for the stored season — the ordinary numeric index, untouched.
        $this->assertSame('Title 24', $updates['ep-2-24']['episode_title']);

        // Out of range — must stay unmatched, NOT acquire season 3's title.
        $this->assertArrayNotHasKey('episode_title', $updates['ep-2-25']);
        $this->assertArrayNotHasKey('matched_season', $updates['ep-2-25']);
        $this->assertArrayNotHasKey('episode_title', $updates['ep-2-30']);
        $this->assertSame(0, $this->rescuedCount($updates));
    }

    /**
     * FINDING 1(b). Wrong-entity amplification.
     *
     * 220 absolutely-numbered files bound to the real 500-episode, 20-season
     * `Naruto Shippuuden` chain. Without the relation, 188 rows (33..220) that are
     * unmatched today were newly stamped with that entity's titles — e.g. absolute
     * 220 became `matched_season => 10`. The arithmetic branch would have refused
     * the identical series outright (220 !== 500); the chain branch has to as well.
     */
    public function testRefusesAChainNumberedEntityWhoseTotalDisagreesWithTheLibrary(): void
    {
        [$matcher, $updates] = $this->makeSeriesMatcher([1 => range(1, 220)], $this->narutoChain());
        $matcher->matchLibrary('lib-1');

        $this->assertSame('Title 32', $updates['ep-1-32']['episode_title']); // in range
        $this->assertArrayNotHasKey('episode_title', $updates['ep-1-33']);
        $this->assertArrayNotHasKey('episode_title', $updates['ep-1-220']);
        $this->assertArrayNotHasKey('matched_season', $updates['ep-1-220']);
        $this->assertSame(0, $this->rescuedCount($updates));
    }

    /**
     * The relation is a RELATION, not a rubber stamp: the same 20-season chain with
     * a library that really does hold all 500 episodes is still accepted. Together
     * with the two refusals above this pins the boundary exactly at
     * `storedMax === chain extent`.
     */
    public function testStillRescuesWhenTheLibraryRunAndTheChainEndTogether(): void
    {
        [$matcher, $updates] = $this->makeSeriesMatcher([1 => range(1, 500)], $this->narutoChain());
        $matcher->matchLibrary('lib-1');

        $this->assertSame('Title 368', $updates['ep-1-368']['episode_title']);
        $this->assertSame(17, $updates['ep-1-368']['matched_season']);
        $this->assertSame('Title 500', $updates['ep-1-500']['episode_title']);
        $this->assertSame(20, $updates['ep-1-500']['matched_season']);
        $this->assertSame(468, $this->rescuedCount($updates)); // 500 stored - 32 in range
    }

    /**
     * FINDING 2. The rescue must be IDEMPOTENT.
     *
     * A rescued row keeps its title, so it is never re-queued; but the ordinary
     * pass still runs for it with an empty `$info`, and the series-level fallbacks
     * used to overwrite what the rescue wrote. Measured before the fix, over two
     * real passes: `overview` "EPISODE OVERVIEW 60" -> "SERIES OVERVIEW" and
     * `poster_url` ".../still-60.jpg" -> ".../season.jpg", i.e. the episode
     * overview and image lasted exactly one refresh cycle.
     *
     * The second pass sets force-refresh because that is literally what the next
     * `metadata_refresh` does — without it the batch pre-skip drops every item that
     * already carries a `metadata_refreshed_at` stamp and the row is never revisited.
     */
    public function testARescuedEpisodeSurvivesTheNextRefreshUnchanged(): void
    {
        [$matcher, $updates] = $this->makeSeriesMatcher(
            [1 => range(1, 71)],
            [1 => range(1, 32), 2 => range(33, 53), 3 => range(54, 71)],
            [],
            true
        );

        $matcher->matchLibrary('lib-1');
        $this->assertSame('Title 60', $updates['ep-1-60']['episode_title']);
        $this->assertSame('EPISODE OVERVIEW 60', $updates['ep-1-60']['overview']);
        $this->assertSame('https://img/still-60.jpg', $updates['ep-1-60']['poster_url']);

        $matcher->setForceRefresh(true); // the next metadata_refresh
        $matcher->matchLibrary('lib-1');

        $this->assertSame('Title 60', $updates['ep-1-60']['episode_title']);
        $this->assertSame('EPISODE OVERVIEW 60', $updates['ep-1-60']['overview']);
        $this->assertSame('https://img/still-60.jpg', $updates['ep-1-60']['poster_url']);
        $this->assertSame('https://img/still-60.jpg', $updates['ep-1-60']['still_url']);
        $this->assertSame(3, $updates['ep-1-60']['matched_season']);
        $this->assertSame(60, $updates['ep-1-60']['matched_episode']);
    }

    /**
     * The idempotence guard is scoped by PROVENANCE, not by "the index missed".
     *
     * Stored season 2 holds 1..30 while the provider's season 2 starts at 13, so
     * episodes 1..12 miss the index exactly like a rescued row does — but they were
     * never rescued (the relation refused this series, see the finding-1 test above)
     * and so they must still inherit the series overview and the season poster. A
     * guard that keyed off the empty `$info` alone would render them blank.
     */
    public function testAnUnrescuedEpisodeStillInheritsTheSeriesFallbacks(): void
    {
        [$matcher, $updates] = $this->makeSeriesMatcher(
            [2 => range(1, 30)],
            [1 => range(1, 12), 2 => range(13, 24), 3 => range(25, 36), 4 => range(37, 48)],
            [],
            true
        );

        $matcher->matchLibrary('lib-1');
        $matcher->setForceRefresh(true);
        $matcher->matchLibrary('lib-1');

        $this->assertArrayNotHasKey('absolute_number', $updates['ep-2-5']);
        $this->assertArrayNotHasKey('episode_title', $updates['ep-2-5']);
        $this->assertSame('SERIES OVERVIEW', $updates['ep-2-5']['overview']);
        $this->assertSame('https://img/season.jpg', $updates['ep-2-5']['poster_url']);
    }

    /**
     * …and the guard is scoped to provenance that is still CURRENT. A row whose
     * `absolute_number` names a different ordinal than the one it now stores has
     * been renumbered since it was rescued — which is exactly what the reindex step
     * (SM-0.6) will do to these rows — so the provenance is stale and must not
     * freeze the row's overview/image against the series fallbacks.
     */
    public function testStaleProvenanceFromAnotherOrdinalDoesNotFreezeTheRow(): void
    {
        [$matcher, $updates] = $this->makeSeriesMatcher(
            [2 => range(1, 30)],
            [1 => range(1, 12), 2 => range(13, 24), 3 => range(25, 36), 4 => range(37, 48)],
            [],
            true,
            ['2:5' => ['absolute_number' => 60, 'matched_season' => 3, 'matched_episode' => 60]]
        );

        $matcher->matchLibrary('lib-1');

        $this->assertSame('SERIES OVERVIEW', $updates['ep-2-5']['overview'] ?? null);
        $this->assertSame('https://img/season.jpg', $updates['ep-2-5']['poster_url'] ?? null);
    }

    /**
     * FINDING 3(a) — pins the stored-season filter in `enrichAbsoluteNumbered()`.
     *
     * A season-0 special numbered 27 sits beside a complete aired run 1..71 whose
     * chain the pass DOES act on. The special's ordinal is not part of the aired
     * run, so it must be left alone. Dropping the `$r['season'] === $season` filter
     * makes the mutant stamp it with the aired `Title 27` and `matched_season => 1`.
     *
     * Live shape: `Fairy Tail S00E10/E11`, `Sword Art Online S00E27` (worklog §6.2).
     */
    public function testNeverRescuesASeasonZeroSpecial(): void
    {
        [$matcher, $updates] = $this->makeSeriesMatcher(
            [0 => [27], 1 => range(1, 71)],
            [0 => [1, 2, 3], 1 => range(1, 32), 2 => range(33, 53), 3 => range(54, 71)]
        );
        $matcher->matchLibrary('lib-1');

        // The aired run IS rescued — so the pass definitely ran on this series.
        $this->assertSame('Title 71', $updates['ep-1-71']['episode_title']);

        // The special is not part of that run and must not be touched by it.
        $this->assertArrayNotHasKey('episode_title', $updates['ep-0-27']);
        $this->assertArrayNotHasKey('absolute_number', $updates['ep-0-27']);
        $this->assertArrayNotHasKey('matched_season', $updates['ep-0-27']);
    }

    /**
     * ROUND-2 FINDING 1. A chain TRUNCATED BELOW the stored season by a transient
     * provider failure can still match `storedMax` by coincidence.
     *
     * `providerSeasonNumbers()` walks the provider's seasons from 1 and `break`s at
     * the first one that returns no episodes — and an empty list is exactly what a
     * transient TMDB failure produces, indistinguishable from a genuinely absent
     * season (the same premise the `$highest > 0` test below rests on). So the walk
     * can stop *below* the stored season while that season's own list is already
     * cached from the children loop, and the resulting SHORTER chain extent can land
     * on `storedMax` by accident.
     *
     * The shape, measured: stored season 5 holds the complete run 1..24; the
     * provider's season 1 is 1–12, season 2 is 13–24, **season 3's fetch fails**,
     * and season 5 is 1–10. The walk yields `chain = {1, 2}` whose extent is 24,
     * `24 === storedMax 24` passes, and all 14 out-of-range rows (11..24) were
     * stamped with the chain's ABSOLUTE 11–24 titles — the library's season-5
     * episodes given a different show-position's titles.
     *
     * The sibling arithmetic branch was already immune via
     * `isAbsoluteNumbering()`'s `isset($run[$season])`; the chain branch now carries
     * the same containment test. Pre-fix this test reported 14 rescues; post-fix, 0.
     */
    public function testRefusesAChainTruncatedBelowTheStoredSeasonByAFailedFetch(): void
    {
        [$matcher, $updates] = $this->makeSeriesMatcher(
            [5 => range(1, 24)],                    // one aired season, complete 1..24
            [
                1 => range(1, 12),
                2 => range(13, 24),
                // season 3 resolves to NO episodes — the transient failure
                5 => range(1, 10),
            ]
        );
        $matcher->matchLibrary('lib-1');

        // In range for the stored season — the ordinary numeric index, untouched.
        $this->assertSame('Title 10', $updates['ep-5-10']['episode_title']);

        // Nothing may be rescued off a chain that never reached the stored season.
        // Asserted as the full stamp map so a regression names the mis-assignments.
        $this->assertSame([], $this->rescuedStamps($updates));
        $this->assertArrayNotHasKey('episode_title', $updates['ep-5-11']);
        $this->assertArrayNotHasKey('matched_season', $updates['ep-5-11']);
        $this->assertArrayNotHasKey('episode_title', $updates['ep-5-13']);
        $this->assertArrayNotHasKey('episode_title', $updates['ep-5-24']);
        $this->assertArrayNotHasKey('matched_season', $updates['ep-5-24']);
    }

    /**
     * FINDING 3(b) — pins the `$highest > 0` precondition in `recordEpisodeSlot()`.
     *
     * An EMPTY provider episode list is what a TMDB failure returns
     * (`SeriesMetadataResolver::resolveSeasonEpisodes()` answers `['episodes' => []]`
     * on any Throwable, and `TmdbProvider::getTvSeason()` does the same on a null
     * body). An empty list says nothing about whether a number overflows, so
     * nothing may be queued from it. Dropping the precondition turns every episode
     * of the stored season into an "overflow" and re-reads the WHOLE season into
     * the earlier seasons the chain does cover.
     */
    public function testAnEmptyProviderSeasonNeverQueuesTheStoredSeason(): void
    {
        [$matcher, $updates] = $this->makeSeriesMatcher(
            [3 => range(1, 24)],                              // one aired season, complete 1..24
            [1 => range(1, 12), 2 => range(13, 24)]           // season 3 resolves to NO episodes
        );
        $matcher->matchLibrary('lib-1');

        $this->assertSame(0, $this->rescuedCount($updates));
        $this->assertArrayNotHasKey('episode_title', $updates['ep-3-1']);
        $this->assertArrayNotHasKey('episode_title', $updates['ep-3-24']);
        $this->assertArrayNotHasKey('matched_season', $updates['ep-3-24']);
    }

    /**
     * …and pins what `$highest > 0` still buys once the chain branch carries the
     * `isset($chain[$season])` containment test: COST.
     *
     * On correctness that precondition is now a KNOWN EQUIVALENT MUTANT. Dropping
     * it queues every episode of the stored season, but `providerSeasonNumbers()`
     * shares the same cached empty result, so its walk breaks at or before the
     * stored season and neither `isset($chain[$season])` nor `isset($run[$season])`
     * can hold — the series is refused whole and no row is stamped either way.
     *
     * What is NOT equivalent is the provider traffic. `enrichAbsoluteNumbered()`
     * documents "zero extra provider calls unless an overflow exists AND the series
     * passes `candidateSeason()`", and a transient failure fabricates an overflow
     * that is certain to be refused. Here the stored season 5 fetch comes back
     * empty while seasons 1–4 are real, so the mutant drags a resident worker
     * through four pointless season fetches. Pinning the call log keeps the guard
     * killable instead of leaving it to be "discovered" as dead code and deleted.
     */
    public function testAFailedSeasonFetchTriggersNoProviderWalk(): void
    {
        [$matcher, $updates, $seasonCalls] = $this->makeSeriesMatcher(
            [5 => range(1, 24)],                    // one aired season, complete 1..24
            [
                1 => range(1, 12),
                2 => range(13, 24),
                3 => range(25, 36),
                4 => range(37, 48),
                // season 5 resolves to NO episodes — the transient failure
            ]
        );
        $matcher->matchLibrary('lib-1');

        // The stored season is fetched once by the ordinary pass, and that is ALL.
        $this->assertSame([5], iterator_to_array($seasonCalls));
        $this->assertSame([], $this->rescuedStamps($updates));
    }
}
