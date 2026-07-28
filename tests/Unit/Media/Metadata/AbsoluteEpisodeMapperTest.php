<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\AbsoluteEpisodeMapper;

/**
 * Unit tests for {@see AbsoluteEpisodeMapper}.
 *
 * Every fixture in this file is a SHAPE that was measured on the production
 * estate on 2026-07-28, named after the series it came from, so a future change
 * that widens a guard fails against the real data that motivated it rather than
 * against an invented example.
 *
 * The accept cases are the three series the guards admit; the refuse cases are
 * the five that would have been silently mis-assigned.
 *
 * @covers \Phlix\Media\Metadata\AbsoluteEpisodeMapper
 */
class AbsoluteEpisodeMapperTest extends TestCase
{
    private AbsoluteEpisodeMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new AbsoluteEpisodeMapper();
    }

    /** @return list<int> */
    private function range(int $from, int $to): array
    {
        return range($from, $to);
    }

    // ------------------------------------------------------------ candidateSeason

    /**
     * `Naruto Shippuuden`: 500 files in one stored season numbered 1..500. The
     * shape the whole step exists for.
     */
    public function testCandidateSeasonAcceptsOneCompleteAiredRun(): void
    {
        $this->assertSame(
            ['season' => 1, 'max' => 500],
            $this->mapper->candidateSeason([1 => $this->range(1, 500)])
        );
    }

    /**
     * `Turn A Gundam`: a specials season sits alongside the aired one. Season 0 is
     * not part of the aired run and must not disqualify the candidate.
     */
    public function testCandidateSeasonIgnoresSeasonZero(): void
    {
        $this->assertSame(
            ['season' => 1, 'max' => 50],
            $this->mapper->candidateSeason([0 => [1, 2], 1 => $this->range(1, 50)])
        );
    }

    /**
     * `Hunter x Hunter`: 150 files for 148 numbers — two rips of the same episode
     * collide on one slot. Duplicates are collapsed, not treated as holes.
     */
    public function testCandidateSeasonCollapsesDuplicateNumbers(): void
    {
        $numbers = array_merge($this->range(1, 148), [7, 99]);
        $this->assertSame(['season' => 1, 'max' => 148], $this->mapper->candidateSeason([1 => $numbers]));
    }

    /**
     * `Initial D` / `Knight Rider` / `Star Trek The Next Generation`: the library
     * ALREADY numbers per season. Re-reading any of it continuously is wrong.
     */
    public function testCandidateSeasonRefusesMoreThanOneAiredSeason(): void
    {
        $this->assertNull($this->mapper->candidateSeason([
            1 => $this->range(1, 26),
            2 => $this->range(1, 13),
            3 => $this->range(1, 24),
        ]));
    }

    /**
     * `Battlestar Galactica (1978)`: 21 distinct numbers but a maximum of 24 —
     * the library is not enumerating the show end to end.
     */
    public function testCandidateSeasonRefusesAHoleInTheRun(): void
    {
        $numbers = array_values(array_diff($this->range(1, 24), [5, 12, 19]));
        $this->assertNull($this->mapper->candidateSeason([1 => $numbers]));
    }

    /**
     * `My Hero Academia` season 2: files numbered 14..38 — an absolute run behind
     * a per-season label. An offset start is an interpretation we refuse to make.
     */
    public function testCandidateSeasonRefusesARunThatDoesNotStartAtOne(): void
    {
        $this->assertNull($this->mapper->candidateSeason([2 => $this->range(14, 38)]));
    }

    public function testCandidateSeasonRefusesASingleEpisodeAndEmptyInput(): void
    {
        $this->assertNull($this->mapper->candidateSeason([1 => [1]]));
        $this->assertNull($this->mapper->candidateSeason([1 => []]));
        $this->assertNull($this->mapper->candidateSeason([]));
        $this->assertNull($this->mapper->candidateSeason([0 => $this->range(1, 12)]));
    }

    // ------------------------------------------------------------- providerChain

    /**
     * `Naruto Shippuuden` (TMDB 31910): season 1 is 1–32, season 2 is 33–53,
     * season 3 is 54–71. The provider numbers the show absolutely itself.
     */
    public function testProviderChainDetectsContinuousProviderNumbering(): void
    {
        $chain = $this->mapper->providerChain([
            0 => [1, 2, 3],
            1 => $this->range(1, 32),
            2 => $this->range(33, 53),
            3 => $this->range(54, 71),
        ]);

        $this->assertSame([
            1 => ['min' => 1, 'max' => 32],
            2 => ['min' => 33, 'max' => 53],
            3 => ['min' => 54, 'max' => 71],
        ], $chain);
    }

    /**
     * `Turn A Gundam` (TMDB 37546): both seasons restart at 1, so there is no
     * chain and the caller must fall through to the arithmetic strategy.
     */
    public function testProviderChainRefusesPerSeasonNumbering(): void
    {
        $this->assertSame([], $this->mapper->providerChain([
            1 => $this->range(1, 25),
            2 => $this->range(1, 25),
        ]));
    }

    /**
     * `Blood+` (TMDB 40895, the wrong entity): a single provider season is not a
     * chain — there is nowhere else to look the number up.
     */
    public function testProviderChainRefusesASingleSeason(): void
    {
        $this->assertSame([], $this->mapper->providerChain([1 => $this->range(1, 50)]));
    }

    public function testProviderChainRefusesAHoleInsideASeason(): void
    {
        $this->assertSame([], $this->mapper->providerChain([
            1 => $this->range(1, 32),
            2 => [33, 34, 36, 37],
        ]));
    }

    public function testProviderChainRefusesAGapBetweenSeasons(): void
    {
        $this->assertSame([], $this->mapper->providerChain([
            1 => $this->range(1, 32),
            2 => $this->range(34, 53),
        ]));
    }

    public function testProviderChainRefusesWhenSeasonOneDoesNotStartAtOne(): void
    {
        $this->assertSame([], $this->mapper->providerChain([
            1 => $this->range(2, 33),
            2 => $this->range(34, 53),
        ]));
    }

    public function testProviderChainStopsAtTheFirstUnknownSeason(): void
    {
        $chain = $this->mapper->providerChain([
            1 => $this->range(1, 62),
            2 => $this->range(63, 136),
            4 => $this->range(200, 210), // unreachable: season 3 is absent
        ]);
        $this->assertSame([1, 2], array_keys($chain));
    }

    public function testProviderChainIsBoundedBySeasonCeiling(): void
    {
        $seasons = [];
        $next = 1;
        for ($s = 1; $s <= AbsoluteEpisodeMapper::MAX_SEASONS + 5; $s++) {
            $seasons[$s] = $this->range($next, $next + 9);
            $next += 10;
        }
        $this->assertCount(AbsoluteEpisodeMapper::MAX_SEASONS, $this->mapper->providerChain($seasons));
    }

    // -------------------------------------------------------------------- locate

    public function testLocateFindsTheSeasonHoldingAnAbsoluteNumber(): void
    {
        $chain = [
            1 => ['min' => 1, 'max' => 62],
            2 => ['min' => 63, 'max' => 136],
            3 => ['min' => 137, 'max' => 148],
        ];
        $this->assertSame(1, $this->mapper->locate($chain, 62));
        $this->assertSame(2, $this->mapper->locate($chain, 63));
        $this->assertSame(2, $this->mapper->locate($chain, 131));
        $this->assertSame(3, $this->mapper->locate($chain, 148));
    }

    public function testLocateRefusesNumbersOutsideTheChain(): void
    {
        $chain = [1 => ['min' => 1, 'max' => 62], 2 => ['min' => 63, 'max' => 136]];
        $this->assertNull($this->mapper->locate($chain, 137));
        $this->assertNull($this->mapper->locate($chain, 0));
        $this->assertNull($this->mapper->locate([], 5));
    }

    // ------------------------------------------------------------- contiguousRun

    public function testContiguousRunCountsPerSeasonNumberedSeasons(): void
    {
        $this->assertSame([1 => 25, 2 => 25], $this->mapper->contiguousRun([
            0 => [1, 2],
            1 => $this->range(1, 25),
            2 => $this->range(1, 25),
        ]));
    }

    /**
     * A continuously-numbered provider is NOT a per-season run: season 2 starting
     * at 33 truncates it after season 1, which is what makes
     * {@see AbsoluteEpisodeMapper::isAbsoluteNumbering()} refuse for that shape.
     */
    public function testContiguousRunTruncatesAtASeasonThatDoesNotStartAtOne(): void
    {
        $this->assertSame([1 => 32], $this->mapper->contiguousRun([
            1 => $this->range(1, 32),
            2 => $this->range(33, 53),
        ]));
    }

    public function testContiguousRunStopsAtAnAbsentOrEmptySeason(): void
    {
        $this->assertSame([1 => 25], $this->mapper->contiguousRun([1 => $this->range(1, 25), 3 => [1, 2]]));
        $this->assertSame([1 => 25], $this->mapper->contiguousRun([1 => $this->range(1, 25), 2 => []]));
    }

    public function testContiguousRunTruncatesAtASeasonWithAHole(): void
    {
        $this->assertSame([1 => 25], $this->mapper->contiguousRun([
            1 => $this->range(1, 25),
            2 => [1, 2, 4],
        ]));
    }

    // ------------------------------------------------------- isAbsoluteNumbering

    /** `Turn A Gundam`: 50 stored, provider 25 + 25 = 50. The one accepted shape. */
    public function testIsAbsoluteNumberingAcceptsAnExactTotal(): void
    {
        $this->assertTrue($this->mapper->isAbsoluteNumbering([1 => 25, 2 => 25], 1, 50));
    }

    /** `Hajime no Ippo`: 75 stored against a 25-episode single-season entry. */
    public function testIsAbsoluteNumberingRefusesASingleSeasonProvider(): void
    {
        $this->assertFalse($this->mapper->isAbsoluteNumbering([1 => 25], 1, 75));
    }

    /** `Nurarihyon no Mago`: 25 stored, provider total 48 — the totals disagree. */
    public function testIsAbsoluteNumberingRefusesWhenTotalsDisagree(): void
    {
        $this->assertFalse($this->mapper->isAbsoluteNumbering([1 => 24, 2 => 24], 1, 25));
    }

    /**
     * A partially-downloaded show is refused too: 40 of a 50-episode run cannot
     * be told apart from ordinary per-season numbering, so we fail closed.
     */
    public function testIsAbsoluteNumberingRefusesAnIncompleteLibrary(): void
    {
        $this->assertFalse($this->mapper->isAbsoluteNumbering([1 => 25, 2 => 25], 1, 40));
    }

    public function testIsAbsoluteNumberingRefusesWithoutAnOverflow(): void
    {
        $this->assertFalse($this->mapper->isAbsoluteNumbering([1 => 25, 2 => 25], 1, 25));
    }

    public function testIsAbsoluteNumberingRefusesAnUnknownStoredSeason(): void
    {
        $this->assertFalse($this->mapper->isAbsoluteNumbering([1 => 25, 2 => 25], 3, 50));
    }

    // ----------------------------------------------------------------------- map

    public function testMapTranslatesByPrefixSum(): void
    {
        $run = [1 => 25, 2 => 25];
        $this->assertSame([1, 1], $this->mapper->map($run, 1));
        $this->assertSame([1, 25], $this->mapper->map($run, 25));
        $this->assertSame([2, 1], $this->mapper->map($run, 26));
        $this->assertSame([2, 19], $this->mapper->map($run, 44));
        $this->assertSame([2, 25], $this->mapper->map($run, 50));
    }

    public function testMapRefusesOrdinalsOutsideTheRun(): void
    {
        $run = [1 => 25, 2 => 25];
        $this->assertNull($this->mapper->map($run, 51));
        $this->assertNull($this->mapper->map($run, 0));
        $this->assertNull($this->mapper->map($run, -3));
        $this->assertNull($this->mapper->map([], 1));
    }

    /**
     * End-to-end over the real `Naruto Shippuuden` season shape: the prefix sum is
     * the WRONG tool here (it answers season 17 episode 7, which does not exist),
     * and the chain lookup is the right one. This is the case that made the
     * two-strategy split necessary.
     */
    public function testProviderChainAndPrefixSumDisagreeOnAContinuouslyNumberedShow(): void
    {
        $counts = [32, 21, 18, 17, 24, 31, 8, 24, 21, 25, 21, 33, 20, 25, 28, 13, 11, 21, 20, 87];
        $seasons = [];
        $next = 1;
        foreach ($counts as $i => $n) {
            $seasons[$i + 1] = $this->range($next, $next + $n - 1);
            $next += $n;
        }

        $chain = $this->mapper->providerChain($seasons);
        $this->assertSame(17, $this->mapper->locate($chain, 368));

        // The arithmetic strategy is not even offered for this shape …
        $run = $this->mapper->contiguousRun($seasons);
        $this->assertFalse($this->mapper->isAbsoluteNumbering($run, 1, 500));
        // … and had it been, it would have produced an episode number season 17
        // (which runs 362..372) does not contain.
        $this->assertSame([17, 7], $this->mapper->map(array_combine(range(1, 20), $counts), 368));
    }
}
