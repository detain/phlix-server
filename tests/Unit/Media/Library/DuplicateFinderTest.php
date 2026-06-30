<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\DuplicateFinder;
use Phlix\Media\Library\ItemRepository;
use Workerman\MySQL\Connection;

/**
 * @covers \Phlix\Media\Library\DuplicateFinder
 */
class DuplicateFinderTest extends TestCase
{
    /**
     * In-memory ItemRepository double for DuplicateFinder: serves a seeded set
     * of top-level rows through getTopLevelByLibrary() (honouring limit/offset
     * paging) and counts descendants from a parent_id map. Mirrors the real
     * repo's hydrated-row shape (decoded 'metadata').
     *
     * @param list<array<string, mixed>> $topLevel       Seeded top-level rows.
     * @param array<string, int>         $descendantCount id => descendant count.
     */
    private function makeRepo(array $topLevel, array $descendantCount = []): ItemRepository
    {
        $mockConn = $this->createMock(Connection::class);

        return new class ($mockConn, $topLevel, $descendantCount) extends ItemRepository {
            /** @var list<array<string, mixed>> */
            private array $rows;
            /** @var array<string, int> */
            private array $descendants;
            /** Spy: number of getTopLevelByLibrary() pages requested. */
            public int $pageCalls = 0;
            /** Spy: number of countDescendants() calls. */
            public int $countCalls = 0;

            /**
             * @param list<array<string, mixed>> $rows
             * @param array<string, int>         $descendants
             */
            public function __construct(Connection $db, array $rows, array $descendants)
            {
                parent::__construct($db);
                // Ensure each row exposes a decoded 'metadata' array like the
                // real hydrateItem() does.
                $this->rows = array_map(static function (array $row): array {
                    if (!isset($row['metadata']) || !is_array($row['metadata'])) {
                        $row['metadata'] = [];
                    }
                    return $row;
                }, $rows);
                $this->descendants = $descendants;
            }

            public function getTopLevelByLibrary(string $libraryId, int $limit = 500, int $offset = 0): array
            {
                $this->pageCalls++;
                $matching = array_values(array_filter(
                    $this->rows,
                    static fn (array $r): bool => ($r['library_id'] ?? null) === $libraryId
                ));

                return array_slice($matching, $offset, $limit);
            }

            public function countDescendants(string $itemId): int
            {
                $this->countCalls++;
                return $this->descendants[$itemId] ?? 0;
            }
        };
    }

    /**
     * Build a top-level row in the hydrated shape the finder consumes.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function row(string $id, string $name, string $type, array $metadata, string $libraryId = 'lib-1'): array
    {
        return [
            'id' => $id,
            'library_id' => $libraryId,
            'parent_id' => null,
            'name' => $name,
            'type' => $type,
            'metadata' => $metadata,
        ];
    }

    public function testHxhHundredEpAndOneEpFormOneGroupPrimaryIsHundredEp(): void
    {
        // Two series rows that slug differently but share the canonical key,
        // one with 100 descendants and one with 1.
        $rows = [
            $this->row('series-big', 'Hunter x Hunter', 'series', ['canonical_key' => 'hunterxhunter']),
            $this->row('series-thin', 'HunterxHunter', 'series', ['canonical_key' => 'hunterxhunter']),
        ];
        $repo = $this->makeRepo($rows, ['series-big' => 100, 'series-thin' => 1]);

        $groups = (new DuplicateFinder($repo))->findForLibrary('lib-1');

        $this->assertCount(1, $groups, 'the two HxH rows must form exactly ONE group');
        $group = $groups[0];
        $this->assertSame('hunterxhunter', $group['canonical_key']);
        $this->assertSame('series', $group['type']);
        $this->assertSame('lib-1', $group['library_id']);
        $this->assertSame('series-big', $group['primary']['id'], 'primary must be the 100-ep item');
        $this->assertSame(100, $group['primary']['descendant_count']);
        $this->assertCount(1, $group['duplicates']);
        $this->assertSame('series-thin', $group['duplicates'][0]['id']);
        $this->assertSame(1, $group['duplicates'][0]['descendant_count']);
    }

    public function testTwoMoviesSharingTmdbExternalIdAreGrouped(): void
    {
        // Two movies with totally different titles but the SAME tmdb id —
        // CanonicalKey::forItem() keys both on tmdb:<id> (no stored key, so the
        // finder recomputes from metadata.external_ids).
        $rows = [
            $this->row('movie-a', 'Blade Runner', 'movie', ['external_ids' => ['tmdb' => 78]]),
            $this->row('movie-b', 'Blade Runner Final Cut', 'movie', ['external_ids' => ['tmdb' => 78]]),
        ];
        $repo = $this->makeRepo($rows);

        $groups = (new DuplicateFinder($repo))->findForLibrary('lib-1');

        $this->assertCount(1, $groups);
        $this->assertSame('tmdb:78', $groups[0]['canonical_key']);
        $this->assertSame('movie', $groups[0]['type']);
        // Movies have no descendants → both 0; deterministic id tiebreak picks
        // the lexicographically-smallest id as primary.
        $this->assertSame('movie-a', $groups[0]['primary']['id']);
        $this->assertCount(1, $groups[0]['duplicates']);
        $this->assertSame('movie-b', $groups[0]['duplicates'][0]['id']);
    }

    public function testSingletonsAreExcluded(): void
    {
        // A lone series and a lone movie — neither has a partner → no groups.
        $rows = [
            $this->row('s1', 'Firefly', 'series', ['canonical_key' => 'firefly']),
            $this->row('m1', 'Serenity', 'movie', ['canonical_key' => 'serenity']),
        ];
        $repo = $this->makeRepo($rows, ['s1' => 14]);

        $groups = (new DuplicateFinder($repo))->findForLibrary('lib-1');

        $this->assertSame([], $groups, 'singleton groups (size 1) must be excluded');
    }

    public function testDistinctKeysAreNotGrouped(): void
    {
        // Hunter x Hunter 1999 vs 2011 — same title-key but different YEAR, so
        // CanonicalKey produces hunterxhunter:1999 vs hunterxhunter:2011. They
        // must NOT be grouped.
        $rows = [
            $this->row('hxh-1999', 'Hunter x Hunter', 'series', ['canonical_key' => 'hunterxhunter:1999']),
            $this->row('hxh-2011', 'Hunter x Hunter', 'series', ['canonical_key' => 'hunterxhunter:2011']),
        ];
        $repo = $this->makeRepo($rows, ['hxh-1999' => 62, 'hxh-2011' => 148]);

        $groups = (new DuplicateFinder($repo))->findForLibrary('lib-1');

        $this->assertSame([], $groups, 'distinct canonical keys (1999 vs 2011) must stay separate');
    }

    public function testDistinctKeysRecomputedFromYearStaySeparate(): void
    {
        // No stored canonical_key — the finder must recompute from name + year
        // using the SAME extraction the scanner uses, so the years still split.
        $rows = [
            $this->row('hxh-1999', 'Hunter x Hunter', 'series', ['year' => 1999]),
            $this->row('hxh-2011', 'Hunter.x.Hunter', 'series', ['year' => 2011]),
        ];
        $repo = $this->makeRepo($rows, ['hxh-1999' => 62, 'hxh-2011' => 148]);

        $groups = (new DuplicateFinder($repo))->findForLibrary('lib-1');

        $this->assertSame([], $groups);
    }

    public function testRecomputesKeyWhenNoStoredCanonicalKeyAndGroupsSlugVariants(): void
    {
        // Rows predating Step 1.2 carry no metadata.canonical_key — the finder
        // recomputes from name + year and still collapses slug variants.
        $rows = [
            $this->row('a', 'Hunter x Hunter', 'series', ['year' => 2011]),
            $this->row('b', 'Hunter.x.Hunter', 'series', ['year' => 2011]),
            $this->row('c', 'HunterxHunter', 'series', ['year' => 2011]),
        ];
        $repo = $this->makeRepo($rows, ['a' => 148, 'b' => 1, 'c' => 5]);

        $groups = (new DuplicateFinder($repo))->findForLibrary('lib-1');

        $this->assertCount(1, $groups);
        $this->assertSame('hunterxhunter:2011', $groups[0]['canonical_key']);
        $this->assertSame('a', $groups[0]['primary']['id'], 'primary = the 148-ep row');
        $this->assertCount(2, $groups[0]['duplicates']);
    }

    public function testMovieAndSeriesWithCollidingKeyAreNotGroupedTogether(): void
    {
        // A movie and a series that happen to share a canonical key must NOT be
        // grouped — grouping is per-type. Here each type is a singleton, so no
        // group at all.
        $rows = [
            $this->row('the-movie', 'Westworld', 'movie', ['canonical_key' => 'westworld']),
            $this->row('the-series', 'Westworld', 'series', ['canonical_key' => 'westworld']),
        ];
        $repo = $this->makeRepo($rows, ['the-series' => 30]);

        $groups = (new DuplicateFinder($repo))->findForLibrary('lib-1');

        $this->assertSame([], $groups, 'a movie and a series sharing a key must never group together');
    }

    public function testPerTypeGroupingKeepsMovieAndSeriesDupesSeparate(): void
    {
        // Two movies AND two series, all sharing the same canonical key string —
        // must yield TWO groups, one per type, never one mixed group.
        $rows = [
            $this->row('mv-1', 'Dune', 'movie', ['canonical_key' => 'dune']),
            $this->row('mv-2', 'DUNE', 'movie', ['canonical_key' => 'dune']),
            $this->row('sr-1', 'Dune', 'series', ['canonical_key' => 'dune']),
            $this->row('sr-2', 'Dune ', 'series', ['canonical_key' => 'dune']),
        ];
        $repo = $this->makeRepo($rows, ['sr-1' => 6, 'sr-2' => 2]);

        $groups = (new DuplicateFinder($repo))->findForLibrary('lib-1');

        $this->assertCount(2, $groups);
        $types = array_map(static fn (array $g): string => $g['type'], $groups);
        sort($types);
        $this->assertSame(['movie', 'series'], $types);
        foreach ($groups as $group) {
            $this->assertSame('dune', $group['canonical_key']);
            $this->assertCount(1, $group['duplicates']);
        }
    }

    public function testTypeFilterRestrictsToOneType(): void
    {
        $rows = [
            $this->row('mv-1', 'Dune', 'movie', ['canonical_key' => 'dune']),
            $this->row('mv-2', 'DUNE', 'movie', ['canonical_key' => 'dune']),
            $this->row('sr-1', 'Dune', 'series', ['canonical_key' => 'dune']),
            $this->row('sr-2', 'Dune ', 'series', ['canonical_key' => 'dune']),
        ];
        $repo = $this->makeRepo($rows, ['sr-1' => 6, 'sr-2' => 2]);

        $groups = (new DuplicateFinder($repo))->findForLibrary('lib-1', 'series');

        $this->assertCount(1, $groups, 'type filter must restrict to series only');
        $this->assertSame('series', $groups[0]['type']);
        $this->assertSame('sr-1', $groups[0]['primary']['id']);
    }

    public function testEmptyCanonicalKeyRowsAreNeverGrouped(): void
    {
        // Two rows whose title is non-alphanumeric only, no year, no ids — both
        // produce an empty key and must NOT collapse together.
        $rows = [
            $this->row('x1', '!!!', 'movie', []),
            $this->row('x2', '???', 'movie', []),
        ];
        $repo = $this->makeRepo($rows);

        $groups = (new DuplicateFinder($repo))->findForLibrary('lib-1');

        $this->assertSame([], $groups);
    }

    public function testPagesInFixedBatchesAcrossTheWholeLibrary(): void
    {
        // 5 rows, batch size 2 → must take 3 pages (2 + 2 + 1) and still find
        // the duplicate that straddles page boundaries. Proves the finder never
        // loads the whole library in one query.
        $rows = [
            $this->row('a1', 'Alpha', 'series', ['canonical_key' => 'alpha']),
            $this->row('b1', 'Bravo', 'series', ['canonical_key' => 'bravo']),
            $this->row('c1', 'Charlie', 'series', ['canonical_key' => 'charlie']),
            $this->row('d1', 'Delta', 'series', ['canonical_key' => 'delta']),
            $this->row('a2', 'Alpha (dup)', 'series', ['canonical_key' => 'alpha']),
        ];
        $repo = $this->makeRepo($rows, ['a1' => 20, 'a2' => 1]);

        $finder = new DuplicateFinder($repo, 2);
        $groups = $finder->findForLibrary('lib-1');

        // 5 rows / batch 2 = pages at offset 0, 2, 4 (returns 2,2,1) then the
        // loop stops because the last page (1) was shorter than the batch.
        $this->assertSame(3, $repo->pageCalls, 'must page in fixed batches, not one big read');
        $this->assertCount(1, $groups, 'the cross-page Alpha duplicate must still be found');
        $this->assertSame('alpha', $groups[0]['canonical_key']);
        $this->assertSame('a1', $groups[0]['primary']['id']);
    }

    public function testEmptyLibraryYieldsNoGroups(): void
    {
        $repo = $this->makeRepo([]);

        $groups = (new DuplicateFinder($repo))->findForLibrary('lib-1');

        $this->assertSame([], $groups);
        $this->assertSame(1, $repo->pageCalls, 'an empty library is one (empty) page read');
    }

    public function testBatchSizeIsCoercedToAtLeastOne(): void
    {
        $rows = [
            $this->row('a', 'Alpha', 'series', ['canonical_key' => 'alpha']),
            $this->row('b', 'Alpha2', 'series', ['canonical_key' => 'alpha']),
        ];
        $repo = $this->makeRepo($rows, ['a' => 3, 'b' => 1]);

        // A non-positive batch size must not stall (offset would never advance);
        // it is coerced to 1.
        $groups = (new DuplicateFinder($repo, 0))->findForLibrary('lib-1');

        $this->assertCount(1, $groups);
        $this->assertSame('a', $groups[0]['primary']['id']);
    }

    public function testThreeWayGroupKeepsRichestAsPrimary(): void
    {
        $rows = [
            $this->row('s-thin', 'Show', 'series', ['canonical_key' => 'show']),
            $this->row('s-rich', 'SHOW', 'series', ['canonical_key' => 'show']),
            $this->row('s-mid', 'Show.', 'series', ['canonical_key' => 'show']),
        ];
        $repo = $this->makeRepo($rows, ['s-thin' => 1, 's-rich' => 50, 's-mid' => 12]);

        $groups = (new DuplicateFinder($repo))->findForLibrary('lib-1');

        $this->assertCount(1, $groups);
        $this->assertSame('s-rich', $groups[0]['primary']['id']);
        $this->assertSame(50, $groups[0]['primary']['descendant_count']);
        $duplicateIds = array_map(static fn (array $d): string => $d['id'], $groups[0]['duplicates']);
        sort($duplicateIds);
        $this->assertSame(['s-mid', 's-thin'], $duplicateIds);
    }

    public function testRowsWithBlankOrMissingTypeAreSkippedNotGrouped(): void
    {
        // Two rows that would share a canonical key BUT carry a blank/missing
        // 'type' — the finder skips them (a typeless row can't be classified into
        // a per-type bucket), so they never collapse into a group.
        $rows = [
            $this->row('t-blank', 'Hunter x Hunter', '', ['canonical_key' => 'hunterxhunter']),
            [
                'id' => 't-missing',
                'library_id' => 'lib-1',
                'parent_id' => null,
                'name' => 'HunterxHunter',
                'metadata' => ['canonical_key' => 'hunterxhunter'],
                // no 'type' key at all
            ],
        ];
        $repo = $this->makeRepo($rows, ['t-blank' => 10, 't-missing' => 10]);

        $groups = (new DuplicateFinder($repo))->findForLibrary('lib-1');

        $this->assertSame([], $groups, 'rows with a blank/missing type must never be grouped');
    }

    public function testPrimaryTiebreakPicksSmallerIdEvenWhenItAppearsLater(): void
    {
        // Equal descendant counts: the deterministic tiebreak must pick the
        // lexicographically-smallest id REGARDLESS of bucket order. Here the
        // smaller id ('aaa') is the SECOND row, so the tiebreak must REASSIGN the
        // primary away from the first row ('zzz') — exercising the equal-count,
        // smaller-id reassignment branch.
        $rows = [
            $this->row('zzz', 'Show', 'series', ['canonical_key' => 'show']),
            $this->row('aaa', 'SHOW', 'series', ['canonical_key' => 'show']),
        ];
        $repo = $this->makeRepo($rows, ['zzz' => 7, 'aaa' => 7]);

        $groups = (new DuplicateFinder($repo))->findForLibrary('lib-1');

        $this->assertCount(1, $groups);
        $this->assertSame('aaa', $groups[0]['primary']['id'], 'tie → smallest id wins even when it is later');
        $this->assertSame('zzz', $groups[0]['duplicates'][0]['id']);
    }
}
