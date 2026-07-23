<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Media\Library\NextUpSelector;
use PHPUnit\Framework\TestCase;

/**
 * Pure (DB-free) unit tests for the "Next Up" ordering + next-episode selection
 * logic (S36 · updates.md #43).
 *
 * These lock down the ordering rules ported from phlix-ui `episode-order.ts` /
 * `series-grouping.ts` and the watch-state classification derived from
 * `playback_state`, in isolation from MySQL. The real-DB wiring is proven by
 * {@see \Phlix\Tests\Integration\Auth\NextUpIntegrationTest}.
 *
 * @covers \Phlix\Media\Library\NextUpSelector
 */
final class NextUpSelectorTest extends TestCase
{
    // ---- classify() -------------------------------------------------------

    public function testClassifyNoRowIsFresh(): void
    {
        $this->assertSame(NextUpSelector::STATE_FRESH, NextUpSelector::classify(null, 0, 0));
        $this->assertSame(NextUpSelector::STATE_FRESH, NextUpSelector::classify('', 0, 0));
    }

    public function testClassifyStoppedAtZeroIsWatched(): void
    {
        // The explicit S30 finish signal.
        $this->assertSame(
            NextUpSelector::STATE_WATCHED,
            NextUpSelector::classify('stopped', 0, 100000),
        );
    }

    public function testClassifyPastNinetyFivePercentIsWatched(): void
    {
        // Abandoned near the end — mirrors the CW rail's 0.95 threshold.
        $this->assertSame(
            NextUpSelector::STATE_WATCHED,
            NextUpSelector::classify('paused', 96000, 100000),
        );
        $this->assertSame(
            NextUpSelector::STATE_WATCHED,
            NextUpSelector::classify('playing', 95000, 100000),
        );
    }

    public function testClassifyInProgress(): void
    {
        $this->assertSame(
            NextUpSelector::STATE_IN_PROGRESS,
            NextUpSelector::classify('playing', 30000, 100000),
        );
        $this->assertSame(
            NextUpSelector::STATE_IN_PROGRESS,
            NextUpSelector::classify('paused', 1, 100000),
        );
    }

    public function testClassifyStoppedMidwayIsFreshNotWatched(): void
    {
        // 'stopped' with a non-zero position that is not near the end is NOT the
        // S30 finish signal and is not in-progress → treated as a fresh candidate.
        $this->assertSame(
            NextUpSelector::STATE_FRESH,
            NextUpSelector::classify('stopped', 40000, 100000),
        );
    }

    public function testClassifyUnknownDurationDoesNotFalselyMarkWatched(): void
    {
        // duration 0 must not make `position >= duration*0.95` always-true.
        $this->assertSame(
            NextUpSelector::STATE_FRESH,
            NextUpSelector::classify('playing', 5000, 0),
        );
    }

    // ---- orderForPlayback() ----------------------------------------------

    public function testOrderExcludesSpecialsAndSortsBySeasonThenEpisode(): void
    {
        $episodes = [
            $this->ep('s2e1', 2, 1),
            $this->ep('special', 0, 1),
            $this->ep('s1e2', 1, 2),
            $this->ep('s1e1', 1, 1),
            $this->ep('missing-season', null, 5),
        ];

        $ordered = NextUpSelector::orderForPlayback($episodes);
        $ids = array_map(static fn (array $e): string => $e['id'], $ordered);

        // Specials (season 0) and missing-season rows are excluded; numbered
        // seasons ascend, episodes ascend within a season.
        $this->assertSame(['s1e1', 's1e2', 's2e1'], $ids);
    }

    public function testOrderMissingEpisodeNumberSortsLastThenByTitle(): void
    {
        $episodes = [
            $this->ep('no-num-b', 1, null, 'Bravo'),
            $this->ep('e1', 1, 1),
            $this->ep('no-num-a', 1, null, 'Alpha'),
        ];

        $ordered = NextUpSelector::orderForPlayback($episodes);
        $ids = array_map(static fn (array $e): string => $e['id'], $ordered);

        // e1 first (has a number); the two number-less episodes sort last, ordered
        // by title (Alpha before Bravo).
        $this->assertSame(['e1', 'no-num-a', 'no-num-b'], $ids);
    }

    // ---- pickNext() -------------------------------------------------------

    public function testPickNextBingeSkipAheadReturnsEpisodeFour(): void
    {
        // Watched eps 1-3, ep4 fresh; most-recent touched = ep3 → returns ep4.
        $episodes = [
            $this->ep('e1', 1, 1, 'E1', NextUpSelector::STATE_WATCHED),
            $this->ep('e2', 1, 2, 'E2', NextUpSelector::STATE_WATCHED),
            $this->ep('e3', 1, 3, 'E3', NextUpSelector::STATE_WATCHED),
            $this->ep('e4', 1, 4, 'E4', NextUpSelector::STATE_FRESH),
            $this->ep('e5', 1, 5, 'E5', NextUpSelector::STATE_FRESH),
        ];

        $next = NextUpSelector::pickNext($episodes, 'e3');
        $this->assertNotNull($next);
        $this->assertSame('e4', $next['id']);
    }

    public function testPickNextLastEpisodeOfSeasonReturnsFirstOfNextSeason(): void
    {
        $episodes = [
            $this->ep('s1e1', 1, 1, 'S1E1', NextUpSelector::STATE_WATCHED),
            $this->ep('s1e2', 1, 2, 'S1E2', NextUpSelector::STATE_WATCHED),
            $this->ep('s2e1', 2, 1, 'S2E1', NextUpSelector::STATE_FRESH),
            $this->ep('s2e2', 2, 2, 'S2E2', NextUpSelector::STATE_FRESH),
        ];

        $next = NextUpSelector::pickNext($episodes, 's1e2');
        $this->assertNotNull($next);
        $this->assertSame('s2e1', $next['id']);
    }

    public function testPickNextSingleSeason(): void
    {
        $episodes = [
            $this->ep('e1', 1, 1, 'E1', NextUpSelector::STATE_WATCHED),
            $this->ep('e2', 1, 2, 'E2', NextUpSelector::STATE_FRESH),
        ];

        $next = NextUpSelector::pickNext($episodes, 'e1');
        $this->assertNotNull($next);
        $this->assertSame('e2', $next['id']);
    }

    public function testPickNextFinaleWatchedYieldsNoNext(): void
    {
        $episodes = [
            $this->ep('e1', 1, 1, 'E1', NextUpSelector::STATE_WATCHED),
            $this->ep('e2', 1, 2, 'E2', NextUpSelector::STATE_WATCHED),
            $this->ep('e3', 1, 3, 'E3', NextUpSelector::STATE_WATCHED),
        ];

        $this->assertNull(NextUpSelector::pickNext($episodes, 'e3'));
    }

    public function testPickNextSkipsInProgressEpisode(): void
    {
        // The most-recent episode is in-progress → it lives on the CW rail, not
        // Next Up. Next Up walks forward to the first fresh episode.
        $episodes = [
            $this->ep('e1', 1, 1, 'E1', NextUpSelector::STATE_WATCHED),
            $this->ep('e2', 1, 2, 'E2', NextUpSelector::STATE_IN_PROGRESS),
            $this->ep('e3', 1, 3, 'E3', NextUpSelector::STATE_FRESH),
        ];

        $next = NextUpSelector::pickNext($episodes, 'e2');
        $this->assertNotNull($next);
        $this->assertSame('e3', $next['id']);
    }

    public function testPickNextSpecialTouchedFallsBackToFirstUnwatchedNumbered(): void
    {
        // Most-recent touched episode is a Special (excluded from the numbered
        // ordering) → scan from the start; eps 1-2 watched → return ep3.
        $episodes = [
            $this->ep('special', 0, 1, 'Special', NextUpSelector::STATE_WATCHED),
            $this->ep('e1', 1, 1, 'E1', NextUpSelector::STATE_WATCHED),
            $this->ep('e2', 1, 2, 'E2', NextUpSelector::STATE_WATCHED),
            $this->ep('e3', 1, 3, 'E3', NextUpSelector::STATE_FRESH),
        ];

        $next = NextUpSelector::pickNext($episodes, 'special');
        $this->assertNotNull($next);
        $this->assertSame('e3', $next['id']);
    }

    public function testPickNextNeverReturnsASpecial(): void
    {
        // Only a Special is unwatched — it must never be surfaced as "next".
        $episodes = [
            $this->ep('e1', 1, 1, 'E1', NextUpSelector::STATE_WATCHED),
            $this->ep('special', 0, 1, 'Special', NextUpSelector::STATE_FRESH),
        ];

        $this->assertNull(NextUpSelector::pickNext($episodes, 'e1'));
    }

    public function testPickNextEpisodeWithNoPlaybackRowIsAnUnwatchedCandidate(): void
    {
        // ep2 has no playback_state row (STATE_FRESH) and is returned as next.
        $episodes = [
            $this->ep('e1', 1, 1, 'E1', NextUpSelector::STATE_WATCHED),
            $this->ep('e2', 1, 2, 'E2', NextUpSelector::STATE_FRESH),
        ];

        $next = NextUpSelector::pickNext($episodes, 'e1');
        $this->assertNotNull($next);
        $this->assertSame('e2', $next['id']);
    }

    public function testPickNextNoMostRecentScansFromStart(): void
    {
        $episodes = [
            $this->ep('e1', 1, 1, 'E1', NextUpSelector::STATE_FRESH),
            $this->ep('e2', 1, 2, 'E2', NextUpSelector::STATE_FRESH),
        ];

        $next = NextUpSelector::pickNext($episodes, null);
        $this->assertNotNull($next);
        $this->assertSame('e1', $next['id']);
    }

    /**
     * @return array{id: string, season_number: int|null, episode_number: int|null, title: string, state: string}
     */
    private function ep(
        string $id,
        ?int $season,
        ?int $episode,
        string $title = '',
        string $state = NextUpSelector::STATE_FRESH,
    ): array {
        return [
            'id' => $id,
            'season_number' => $season,
            'episode_number' => $episode,
            'title' => $title !== '' ? $title : $id,
            'state' => $state,
        ];
    }
}
