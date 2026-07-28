<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata;

/**
 * Translate an ABSOLUTE episode ordinal into the provider's (season, episode).
 *
 * ## The defect this repairs
 *
 * {@see LibraryMetadataMatcher::enrichEpisode()} resolves an episode by numeric
 * array index — `$seasonData['episodes'][$episodeNumber]`. When a library numbers
 * a long-running show CONTINUOUSLY (`Naruto Shippuden - 368`, `Hunter x Hunter
 * (2011) - S01E131`) that index simply runs past the end of the provider's season
 * and the episode silently gets no title, no still, no air date.
 *
 * Measured on the production estate 2026-07-28: **742 of 1,328** unmatched
 * episodes (55.9%) are exactly this shape.
 *
 * ## TWO provider shapes, two strategies — measured, not assumed
 *
 * TMDB does not use one numbering convention for TV. Both of these are real and
 * present in the estate, and they need different handling:
 *
 * 1. **The provider numbers the series absolutely too.** `Naruto Shippuuden`
 *    (31910) lists season 1 as episodes 1–32, season 2 as **33–53**, … season 20
 *    as **414–500**; `Hunter x Hunter` (46298) is 1–62 / 63–136 / 137–148. Here
 *    the translation is a LOOKUP, not arithmetic: episode 368 is the provider's
 *    own episode 368 and it says which season holds it. Nothing is inferred, so
 *    nothing can be mis-inferred. See {@see providerChain()} + {@see locate()}.
 * 2. **The provider numbers per season.** `Turn A Gundam` (37546) is 1–25 / 1–25
 *    for one continuous 50-episode show. Only here is a prefix sum needed, and
 *    only here do the strict guards below carry the whole safety burden.
 *    See {@see contiguousRun()} + {@see isAbsoluteNumbering()} + {@see map()}.
 *
 * A prefix sum applied to shape 1 produces a season that is right and an episode
 * number that does not exist (368 → "season 17, episode 7", where season 17 runs
 * 362–372). That is why shape 1 is tried FIRST and why the caller must verify the
 * translated slot really carries a title.
 *
 * ## Why this class refuses far more often than it acts
 *
 * A wrong absolute→(season, episode) translation does NOT fail loudly — it
 * attaches a confident, wrong title to a file, and the user does not find out
 * until they press play. **Leaving a file unmatched is strictly better.** So the
 * mapper is built to FAIL CLOSED: it answers `null`/`[]` unless the library's
 * numbering for the series is provably the provider's absolute ordering.
 *
 * Measured against the real estate, the guards below admit **3 of 434** series.
 * These rejected shapes are all real, all present in production, and every one of
 * them would have been silently mis-assigned by a naive prefix sum:
 *
 * | Rejected shape                                          | Real example (prod)                        |
 * |---------------------------------------------------------|--------------------------------------------|
 * | Numbering is per-season, provider splits it differently  | `Initial D S04E20`, `Knight Rider S02E23`  |
 * | Wrong series matched (bucket B), range absurdly short    | `Hajime no Ippo` → a 25-episode TMDB entry |
 * | Provider simply lacks the tail of a season               | `Firefly S01E12..E14` (TMDB season 1 has 11) |
 * | Numbers re-used by several rips of one show              | `Nurarihyon no Mago` — 74 files, 25 numbers  |
 * | Absolute numbers behind a per-season label               | `My Hero Academia S02E33` (S2 is 1–25)     |
 *
 * ## Purity
 *
 * No I/O, no state, no logging. The caller supplies the provider's per-season
 * episode-number lists (which it already fetches and caches) and the library's
 * own stored numbering. That keeps every guard unit-testable offline.
 *
 * @package Phlix\Media\Metadata
 * @since   0.44.0
 */
final class AbsoluteEpisodeMapper
{
    /**
     * Hard ceiling on how many provider seasons a caller may walk while building
     * the season run. A resident Workerman worker must never be able to spin on a
     * malformed provider response, and no real series approaches this.
     */
    public const MAX_SEASONS = 100;

    /**
     * Pick the single stored season whose numbering MIGHT be absolute.
     *
     * Requires — all measured against production data, see the class docblock:
     *
     * 1. Exactly one stored season `>= 1` holds episodes. Two or more seasons
     *    means the library already numbers per season, so a continuous
     *    re-reading is simply wrong (`Initial D`, `Knight Rider`, `Star Trek TNG`).
     *    Season 0 (specials) is ignored for this test and is never a candidate —
     *    a special's ordinal is not part of the aired run.
     * 2. That season's episode numbers are the COMPLETE contiguous run
     *    `1..max`, every number present exactly once as a distinct value. A hole
     *    means the library is not enumerating the show end to end, and a run that
     *    does not start at 1 is an offset we cannot safely interpret.
     * 3. `max >= 2`. A one-episode "run" carries no evidence at all.
     *
     * @param array<int, list<int>> $storedSeasons Stored season number => the episode
     *     numbers the library holds in it (duplicates allowed; they are collapsed).
     *
     * @return array{season: int, max: int}|null The candidate season and its highest
     *     stored episode number, or null when the series' own numbering gives no
     *     safe basis for an absolute reading.
     */
    public function candidateSeason(array $storedSeasons): ?array
    {
        $candidate = null;
        $numbers = [];
        foreach ($storedSeasons as $season => $stored) {
            if ($season < 1 || $stored === []) {
                continue;
            }
            if ($candidate !== null) {
                return null; // more than one aired season stored => per-season numbering
            }
            $candidate = $season;
            $numbers = $stored;
        }
        if ($candidate === null || $numbers === []) {
            return null;
        }

        $distinct = array_values(array_unique($numbers));
        sort($distinct);
        $count = count($distinct);
        $max = $distinct[$count - 1];
        if ($max < 2 || $count !== $max || $distinct[0] !== 1) {
            return null; // hole, offset start, or too little evidence
        }
        // count === max with distinct ascending values starting at 1 means the run
        // is exactly 1..max — a complete enumeration of the show.
        return ['season' => $candidate, 'max' => $max];
    }

    /**
     * Detect that the PROVIDER itself numbers this series continuously, and
     * return the season → number-range chain when it does.
     *
     * This is strategy 1 and it is the one that carries no inference at all. A
     * chain exists only when, walking seasons from 1:
     *
     * - season 1's episode numbers start at 1,
     * - every season's numbers are a contiguous block with no holes,
     * - every later season starts exactly one past the previous season's last.
     *
     * The last condition is what proves continuity, and it is also what rejects
     * ordinary per-season numbering for free: `Turn A Gundam` season 2 starts at
     * 1 where the chain requires 26, so no chain is reported and the caller falls
     * through to the arithmetic strategy.
     *
     * A chain of one season is not a chain — with a single season the numbers are
     * the season's own and there is nothing to look up elsewhere. That is what
     * rejects `Blood+`, whose matched TMDB entry has two 10-episode seasons and is
     * the wrong show entirely.
     *
     * Season 0 (specials) is never part of the chain: its ordinals repeat the
     * aired ones (`Naruto Shippuuden` season 0 is 1–3) and would make lookups
     * ambiguous.
     *
     * @param array<int, list<int>> $providerSeasons Provider season => episode numbers.
     *
     * @return array<int, array{min: int, max: int}> Season => inclusive number range,
     *     or an empty array when the provider does not number this series continuously.
     */
    public function providerChain(array $providerSeasons): array
    {
        $chain = [];
        $expectedFirst = 1;
        for ($season = 1; $season <= self::MAX_SEASONS; $season++) {
            $numbers = $providerSeasons[$season] ?? null;
            if ($numbers === null || $numbers === []) {
                break; // the provider does not know this season — end of the show
            }
            $distinct = array_values(array_unique($numbers));
            sort($distinct);
            $count = count($distinct);
            $min = $distinct[0];
            $max = $distinct[$count - 1];
            if ($min !== $expectedFirst || $max - $min + 1 !== $count) {
                return []; // a hole, or a restart — not a continuous chain
            }
            $chain[$season] = ['min' => $min, 'max' => $max];
            $expectedFirst = $max + 1;
        }
        return count($chain) >= 2 ? $chain : [];
    }

    /**
     * Find the chain season that holds an absolute episode number.
     *
     * The chain's ranges are disjoint by construction, so this is unambiguous or
     * it is nothing.
     *
     * @param array<int, array{min: int, max: int}> $chain From {@see providerChain()}.
     *
     * @return int|null The season number, or null when the ordinal falls outside
     *     everything the provider knows.
     */
    public function locate(array $chain, int $number): ?int
    {
        foreach ($chain as $season => $range) {
            if ($number >= $range['min'] && $number <= $range['max']) {
                return $season;
            }
        }
        return null;
    }

    /**
     * Reduce a provider's per-season episode-number lists to the CONTIGUOUS run
     * of complete seasons starting at season 1.
     *
     * A season only joins the run when it is itself a complete `1..n` enumeration.
     * Anything else (a gap between seasons, an empty season, a season whose own
     * numbering has a hole) truncates the run, which in turn makes
     * {@see isAbsoluteNumbering()} refuse — the prefix sum is only an absolute
     * ordinal when every season before the target is fully known.
     *
     * @param array<int, list<int>> $providerSeasons Provider season number => that
     *     season's episode numbers.
     *
     * @return array<int, int> Season number => episode count, contiguous from 1.
     */
    public function contiguousRun(array $providerSeasons): array
    {
        $run = [];
        for ($season = 1; $season <= self::MAX_SEASONS; $season++) {
            $numbers = $providerSeasons[$season] ?? null;
            if ($numbers === null || $numbers === []) {
                break;
            }
            $distinct = array_values(array_unique($numbers));
            sort($distinct);
            $count = count($distinct);
            if ($distinct[0] !== 1 || $distinct[$count - 1] !== $count) {
                break; // this season is not a complete 1..n enumeration
            }
            $run[$season] = $count;
        }
        return $run;
    }

    /**
     * Decide whether the library's stored numbering for a series IS the provider's
     * absolute ordering.
     *
     * Requires:
     *
     * 1. The run spans at least two seasons — with one season there is nothing to
     *    translate into, and the mismatch has some other cause.
     * 2. The stored season exists in the run.
     * 3. `storedMax === ` the run's total. This is the load-bearing guard and it
     *    is exact, not a tolerance: the library's last episode must be the show's
     *    last episode under absolute numbering. It is what rejects `Hajime no Ippo`
     *    (75 stored vs a 25-episode provider entry), `Vampire Princess Miyu`
     *    (26 vs 4), `Firefly` (14 vs 11) and `Nurarihyon no Mago` (25 vs 48).
     *    A partially-downloaded show is refused too — deliberately, because an
     *    incomplete run cannot distinguish "absolute" from "per-season".
     *
     * There is deliberately NO separate "the stored maximum must overflow the
     * stored season" test: with a run of two or more seasons each holding at least
     * one episode, `storedMax === array_sum($run)` already implies
     * `storedMax > $run[$season]`. Mutation testing surfaced such a test as dead
     * code, so it was removed rather than left to imply a protection it never gave.
     *
     * @param array<int, int> $run       Season => episode count, from {@see contiguousRun()}.
     * @param int             $season    The stored season being re-read.
     * @param int             $storedMax The highest episode number stored in it.
     */
    public function isAbsoluteNumbering(array $run, int $season, int $storedMax): bool
    {
        if (count($run) < 2 || !isset($run[$season])) {
            return false;
        }
        return $storedMax === array_sum($run);
    }

    /**
     * Translate an absolute ordinal to `[season, episode]` by prefix sum over the run.
     *
     * @param array<int, int> $run      Season => episode count, from {@see contiguousRun()}.
     * @param int             $absolute The 1-based absolute episode ordinal.
     *
     * @return array{0: int, 1: int}|null `[season, episode]`, or null when the
     *     ordinal falls outside the run entirely.
     */
    public function map(array $run, int $absolute): ?array
    {
        if ($absolute < 1) {
            return null;
        }
        $remaining = $absolute;
        foreach ($run as $season => $count) {
            if ($remaining <= $count) {
                return [$season, $remaining];
            }
            $remaining -= $count;
        }
        return null;
    }
}
