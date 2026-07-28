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
 *
 * @covers \Phlix\Media\Metadata\LibraryMetadataMatcher
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
     * @return array<string, mixed>
     */
    private function season(array $numbers, string $prefix, array $untitled = []): array
    {
        $episodes = [];
        foreach ($numbers as $n) {
            $episodes[$n] = [
                'episode_title' => in_array($n, $untitled, true) ? null : $prefix . ' ' . $n,
                'overview' => null,
                'poster_url' => null,
                'still_url' => 'https://img/still-' . $n . '.jpg',
                'air_date' => null,
                'runtime' => null,
                'vote_average' => null,
                'cast' => [],
                'crew' => [],
            ];
        }
        return ['poster_url' => null, 'overview' => '', 'episodes' => $episodes];
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
}
