<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use Phlix\Media\Metadata\SeriesMetadataResolver;
use Phlix\Media\Metadata\TmdbProvider;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end series-identification behaviour of {@see SeriesMetadataResolver}.
 *
 * Every fixture reproduces a REAL defect measured against the live library
 * (2026-07-28), including the search-call budget: the guards must not turn one
 * `/search/tv` request into two on the common path.
 */
final class SeriesMetadataResolverIdentityTest extends TestCase
{
    /** @var TmdbCallLog Per-test record of the provider requests the resolver issued. */
    private TmdbCallLog $calls;

    protected function setUp(): void
    {
        $this->calls = new TmdbCallLog();
    }

    /**
     * Build a TmdbProvider whose `searchTv()` answers year-scoped and year-less
     * queries separately and records every request on {@see $calls}.
     *
     * @param list<array<string, mixed>>          $yearScoped Results when a year filter is sent.
     * @param list<array<string, mixed>>          $yearLess   Results when no year filter is sent.
     * @param array<string, array<string, mixed>> $details    tmdb id => getTvDetails() payload.
     */
    private function provider(array $yearScoped, array $yearLess, array $details): TmdbProvider
    {
        $log = $this->calls;
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('searchTv')->willReturnCallback(
            /**
             * @param array<string, mixed> $options
             * @return list<array<string, mixed>>
             */
            static function (string $query, array $options = []) use ($yearScoped, $yearLess, $log): array {
                $log->searches++;
                return isset($options['first_air_date_year']) ? $yearScoped : $yearLess;
            }
        );
        $tmdb->method('getTvDetails')->willReturnCallback(
            /** @return array<string, mixed> */
            static function (string $id) use ($details, $log): array {
                $log->details[] = $id;
                return $details[$id] ?? [];
            }
        );

        return $tmdb;
    }

    /**
     * A `getTvDetails()` payload. The production-origin fields default to the
     * `en`/`US` pair so a fixture only has to name them when the test is ABOUT
     * origin; `alternative_titles` defaults to empty, i.e. "TMDB knows this
     * entity by its own name only".
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function details(string $id, string $name, int $seasons, array $extra = []): array
    {
        return array_merge([
            'name' => $name,
            'original_name' => $name,
            'tmdb_id' => $id,
            'number_of_seasons' => $seasons,
            'original_language' => 'en',
            'origin_country' => ['US'],
            'alternative_titles' => [],
        ], $extra);
    }

    // -------------------------------------------------- guard 1: spurious year

    /**
     * `The Big O` + 2001 → the year filter returns only an unrelated WW2
     * documentary. The unfiltered search knows the real show, so it wins.
     */
    public function testDiscardsAYearFabricatedMatch(): void
    {
        $tmdb = $this->provider(
            [['id' => '101843', 'name' => 'The Big Battles Of World War II']],
            [['id' => '18241', 'name' => 'The Big O'], ['id' => '73106', 'name' => 'Big Brother']],
            [
                '18241' => self::details('18241', 'The Big O', 1),
                '101843' => self::details('101843', 'The Big Battles Of World War II', 1),
            ],
        );

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('The Big O', 2001);

        $this->assertNotNull($resolved);
        $this->assertSame('18241', $resolved['tmdb_id']);
        $this->assertSame(2, $this->calls->searches, 'the inexact winner must trigger the year-less retry');
    }

    /** A second, differently-shaped instance of the same defect. */
    public function testDiscardsAYearFabricatedMatchForADifferentTitleShape(): void
    {
        $tmdb = $this->provider(
            [['id' => '40895', 'name' => 'Sincerely Yours in Cold Blood']],
            [['id' => '19849', 'name' => 'Blood+'], ['id' => '10545', 'name' => 'True Blood']],
            [
                '19849' => self::details('19849', 'Blood+', 1),
                '40895' => self::details('40895', 'Sincerely Yours in Cold Blood', 3),
            ],
        );

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('Blood+', 2000);

        $this->assertNotNull($resolved);
        $this->assertSame('19849', $resolved['tmdb_id']);
    }

    /**
     * `Blood-C`: the winner is inexact (Chinese primary title) and absent from
     * the unfiltered list, but nothing in that list is an exact match either —
     * so the correct match is KEPT. Fail closed.
     */
    public function testKeepsAnInexactWinnerWhenNoUnfilteredHitIsExact(): void
    {
        $tmdb = $this->provider(
            [['id' => '43270', 'name' => '血战-C']],
            [['id' => '74531', 'name' => "Crow's Blood"]],
            [
                '43270' => self::details('43270', '血战-C', 1),
                '74531' => self::details('74531', "Crow's Blood", 1),
            ],
        );

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('Blood-C', 2011);

        $this->assertNotNull($resolved);
        $this->assertSame('43270', $resolved['tmdb_id']);
    }

    /**
     * The common path — an exact-title year-scoped winner — must cost exactly ONE
     * search, the same as before the guards existed. This is the call-budget
     * property that keeps a full refresh from doubling its request count.
     */
    public function testAnExactYearScopedWinnerCostsASingleSearch(): void
    {
        $tmdb = $this->provider(
            [['id' => '2875', 'name' => 'MacGyver']],
            [['id' => '67133', 'name' => 'MacGyver'], ['id' => '2875', 'name' => 'MacGyver']],
            ['2875' => self::details('2875', 'MacGyver', 7)],
        );

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('MacGyver', 1985);

        $this->assertNotNull($resolved);
        $this->assertSame('2875', $resolved['tmdb_id']);
        $this->assertSame(1, $this->calls->searches);
    }

    /**
     * The trust gate is EQUALITY, not a prefix. A winner whose title merely
     * extends the query is not title-identical, so the guard still evaluates it —
     * and here everything else lines up, so the swap happens. Relaxing
     * {@see SeriesCandidateSelector::isExactTitleMatch()} to a prefix test would
     * silently disarm guard 1 for every franchise-suffix title.
     */
    public function testAWinnerWhoseTitleMerelyExtendsTheQueryIsNotTrusted(): void
    {
        $tmdb = $this->provider(
            [['id' => '900', 'name' => 'The Big O Show']],
            [['id' => '18241', 'name' => 'The Big O']],
            [
                '900' => self::details('900', 'The Big O Show', 1),
                '18241' => self::details('18241', 'The Big O', 1),
            ],
        );

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('The Big O', 2001);

        $this->assertNotNull($resolved);
        $this->assertSame('18241', $resolved['tmdb_id']);
        $this->assertSame(2, $this->calls->searches, 'an extended title must not take the fast path');
    }

    /** A year-less caller keeps the plain first-result behaviour. */
    public function testAYearLessSearchStillTakesTheFirstResult(): void
    {
        $tmdb = $this->provider(
            [],
            [['id' => '1668', 'name' => '24'], ['id' => '9999', 'name' => '24: Legacy']],
            ['1668' => self::details('1668', '24', 9)],
        );

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('24', null);

        $this->assertNotNull($resolved);
        $this->assertSame('1668', $resolved['tmdb_id']);
        $this->assertSame(1, $this->calls->searches);
    }

    public function testAnEmptyYearScopedSearchStillRetriesWithoutTheYear(): void
    {
        $tmdb = $this->provider(
            [],
            [['id' => '1668', 'name' => '24']],
            ['1668' => self::details('1668', '24', 9)],
        );

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('24', 2001);

        $this->assertNotNull($resolved);
        $this->assertSame('1668', $resolved['tmdb_id']);
        $this->assertSame(2, $this->calls->searches);
    }

    // ------------------------------------------------ guard 2: season coverage

    /**
     * `Battlestar Galactica (2003)` — the year filter picks the title-identical
     * 2-episode 2003 MINISERIES. A 4-season local tree cannot live inside a
     * 1-season entity, and a same-titled 4-season entity exists, so it wins.
     */
    public function testRejectsAMiniseriesThatCannotHoldTheLocalTree(): void
    {
        $tmdb = $this->provider(
            [['id' => '71365', 'name' => 'Battlestar Galactica']],
            [
                ['id' => '1972', 'name' => 'Battlestar Galactica'],
                ['id' => '71365', 'name' => 'Battlestar Galactica'],
                ['id' => '501', 'name' => 'Battlestar Galactica'],
            ],
            [
                '71365' => self::details('71365', 'Battlestar Galactica', 1),
                '1972' => self::details('1972', 'Battlestar Galactica', 4),
                '501' => self::details('501', 'Battlestar Galactica', 1),
            ],
        );

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('Battlestar Galactica', 2003, null, false, 4);

        $this->assertNotNull($resolved);
        $this->assertSame('1972', $resolved['tmdb_id']);
    }

    /**
     * `Avatar - The Last Airbender` — a DIFFERENT shape: no year/title anomaly at
     * all. TMDB's own ranking puts the 2024 live-action remake first in BOTH
     * searches (it is far more popular). Only the season count separates them, so
     * this case proves the coverage guard is not a restatement of guard 1.
     */
    public function testRejectsARemakeThatCannotHoldTheLocalTree(): void
    {
        $tmdb = $this->provider(
            [['id' => '82452', 'name' => 'Avatar: The Last Airbender']],
            [
                ['id' => '82452', 'name' => 'Avatar: The Last Airbender'],
                ['id' => '246', 'name' => 'Avatar: The Last Airbender'],
            ],
            [
                '82452' => self::details('82452', 'Avatar: The Last Airbender', 2),
                '246' => self::details('246', 'Avatar: The Last Airbender', 3),
            ],
        );

        $resolved = (new SeriesMetadataResolver($tmdb))
            ->resolve('Avatar: The Last Airbender', 2024, null, false, 3);

        $this->assertNotNull($resolved);
        $this->assertSame('246', $resolved['tmdb_id']);
    }

    /** Without the local hint the guard is off — today's behaviour, unchanged. */
    public function testCoverageGuardIsOffWhenTheLocalSeasonSpanIsUnknown(): void
    {
        $tmdb = $this->provider(
            [['id' => '71365', 'name' => 'Battlestar Galactica']],
            [['id' => '1972', 'name' => 'Battlestar Galactica']],
            [
                '71365' => self::details('71365', 'Battlestar Galactica', 1),
                '1972' => self::details('1972', 'Battlestar Galactica', 4),
            ],
        );

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('Battlestar Galactica', 2003);

        $this->assertNotNull($resolved);
        $this->assertSame('71365', $resolved['tmdb_id']);
        $this->assertSame(1, $this->calls->searches, 'a one-season tree must not provoke an extra search');
    }

    /**
     * A genuine one-season miniseries under a one-season folder is covered, so
     * nothing is swapped. This is the control for the Battlestar case: 9 of the
     * 10 miniseries in the live library have exactly this shape.
     */
    public function testKeepsAMiniseriesThatDoesCoverTheLocalTree(): void
    {
        $tmdb = $this->provider(
            [['id' => '4613', 'name' => 'Band of Brothers']],
            [['id' => '4613', 'name' => 'Band of Brothers']],
            ['4613' => self::details('4613', 'Band of Brothers', 1)],
        );

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('Band of Brothers', 2001, null, false, 1);

        $this->assertNotNull($resolved);
        $this->assertSame('4613', $resolved['tmdb_id']);
    }

    /**
     * `Blood+` after guard 1: the correct entity has ONE season while the local
     * tree carries four (its 50 episodes were split into four folders). No
     * same-titled entity covers four seasons, so the correct match is KEPT
     * rather than traded for a worse one. That local-numbering defect belongs to
     * a different bucket.
     */
    public function testKeepsTheCorrectEntityWhenNoAlternativeCoversTheTree(): void
    {
        $tmdb = $this->provider(
            [['id' => '40895', 'name' => 'Sincerely Yours in Cold Blood']],
            [['id' => '19849', 'name' => 'Blood+'], ['id' => '10545', 'name' => 'True Blood']],
            [
                '19849' => self::details('19849', 'Blood+', 1),
                '40895' => self::details('40895', 'Sincerely Yours in Cold Blood', 3),
            ],
        );

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('Blood+', 2000, null, false, 4);

        $this->assertNotNull($resolved);
        $this->assertSame('19849', $resolved['tmdb_id']);
    }

    /**
     * `Dragon Ball` — 6 local seasons against a 1-season TMDB entity, but every
     * other candidate is a franchise sibling with a DIFFERENT title. Nothing is
     * swapped: absolute-numbering trees must not be dragged onto `Dragon Ball Z`.
     */
    public function testKeepsTheEntityWhenNoCandidateSharesTheTitle(): void
    {
        $tmdb = $this->provider(
            [['id' => '12609', 'name' => 'Dragon Ball']],
            [
                ['id' => '12971', 'name' => 'Dragon Ball Z'],
                ['id' => '12609', 'name' => 'Dragon Ball'],
                ['id' => '62715', 'name' => 'Dragon Ball Super'],
            ],
            [
                '12609' => self::details('12609', 'Dragon Ball', 1),
                '12971' => self::details('12971', 'Dragon Ball Z', 9),
            ],
        );

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('Dragon Ball', 1986, null, false, 6);

        $this->assertNotNull($resolved);
        $this->assertSame('12609', $resolved['tmdb_id']);
        $this->assertNotContains('12971', $this->calls->details, 'a non-title-identical sibling is never fetched');
    }

    /** A candidate that also fails to cover the tree is rejected too. */
    public function testSkipsAnAlternativeThatAlsoCannotCoverTheTree(): void
    {
        $tmdb = $this->provider(
            [['id' => '71365', 'name' => 'Battlestar Galactica']],
            [
                ['id' => '501', 'name' => 'Battlestar Galactica'],
                ['id' => '1972', 'name' => 'Battlestar Galactica'],
            ],
            [
                '71365' => self::details('71365', 'Battlestar Galactica', 1),
                '501' => self::details('501', 'Battlestar Galactica', 1),
                '1972' => self::details('1972', 'Battlestar Galactica', 4),
            ],
        );

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('Battlestar Galactica', 2003, null, false, 4);

        $this->assertNotNull($resolved);
        $this->assertSame('1972', $resolved['tmdb_id']);
        $this->assertContains('501', $this->calls->details, 'the 1-season alternative is probed and rejected first');
    }

    // ------------------------------------- guard 2: the coverage BOUNDARY itself

    /**
     * Build the boundary fixture: one chosen entity with `$providerSeasons`
     * seasons and one title-identical, same-origin alternative with FIVE, both
     * reachable through the year-less list.
     *
     * Everything except the season counts is arranged so that a swap WOULD
     * happen; the only thing that may stop it is the coverage comparison. That is
     * what makes these tests able to see the boundary move.
     */
    private function boundaryProvider(int $providerSeasons): TmdbProvider
    {
        return $this->provider(
            [['id' => '100', 'name' => 'Boundary Show']],
            [
                ['id' => '100', 'name' => 'Boundary Show'],
                ['id' => '200', 'name' => 'Boundary Show'],
            ],
            [
                '100' => self::details('100', 'Boundary Show', $providerSeasons),
                '200' => self::details('200', 'Boundary Show', 5),
            ],
        );
    }

    /**
     * ⚠ THE boundary. `$providerSeasons >= $localHighestSeason` is the single
     * comparison that keeps the coverage guard silent on ~79% of the library, and
     * mutating its `>=` to `>` left every other test in this file green while
     * re-pointing three currently-correct reboots on the live corpus
     * (`Battlestar Galactica (1978)` 501→1972, `Clone High (2002)` 2342→118546,
     * `MacGyver (2016)` 67133→2875) and taking the coverage test's trip count
     * from 18 to 341.
     *
     * A provider that covers the tree EXACTLY is covered. Nothing may move — and
     * the alternative must not even be fetched.
     */
    public function testCoverageGuardStandsDownWhenTheProviderExactlyCoversTheTree(): void
    {
        $tmdb = $this->boundaryProvider(3);

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('Boundary Show', 2001, null, false, 3);

        $this->assertNotNull($resolved);
        $this->assertSame('100', $resolved['tmdb_id']);
        $this->assertSame(['100'], $this->calls->details, 'an exactly-covered tree probes no alternative');
        $this->assertSame(1, $this->calls->searches, 'and provokes no extra search');
    }

    /**
     * The other side of the same boundary: a provider with MORE seasons than the
     * local tree is covered too. This is what dies if `>=` becomes `==`.
     */
    public function testCoverageGuardStandsDownWhenTheProviderExceedsTheTree(): void
    {
        $tmdb = $this->boundaryProvider(4);

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('Boundary Show', 2001, null, false, 3);

        $this->assertNotNull($resolved);
        $this->assertSame('100', $resolved['tmdb_id']);
        $this->assertSame(['100'], $this->calls->details);
    }

    /** One season short IS a miss — the boundary must not have drifted the other way. */
    public function testCoverageGuardFiresOneSeasonShortOfTheTree(): void
    {
        $tmdb = $this->boundaryProvider(2);

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('Boundary Show', 2001, null, false, 3);

        $this->assertNotNull($resolved);
        $this->assertSame('200', $resolved['tmdb_id']);
    }

    /**
     * `number_of_seasons` absent or zero is UNKNOWN, not "fewer than the tree".
     * Pins the `<= 0` half of the same condition.
     */
    public function testCoverageGuardStandsDownWhenTheProviderReportsNoSeasons(): void
    {
        $tmdb = $this->boundaryProvider(0);

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('Boundary Show', 2001, null, false, 3);

        $this->assertNotNull($resolved);
        $this->assertSame('100', $resolved['tmdb_id']);
        $this->assertSame(['100'], $this->calls->details);
    }

    // --------------------------------- guard 2: identity, not just a title fold

    /**
     * The live `Blood+` collision. `normalize()` folds `Blood+` and `Blood` onto
     * the same key, so TMDB **84768 `Blood`** (an unrelated 2018 Irish drama with
     * 2 seasons) really is offered as a same-title alternative — and before this
     * fix the ONLY thing rejecting it was `2 < 4`, i.e. the local season count
     * that bucket C and SM-0.6 re-parse.
     *
     * Here it has FOUR seasons, so the season count no longer rejects it. It must
     * still be refused.
     */
    public function testCoverageGuardRefusesAFoldCollisionThatCoversTheTree(): void
    {
        $tmdb = $this->provider(
            [['id' => '19849', 'name' => 'Blood+']],
            [
                ['id' => '19849', 'name' => 'Blood+'],
                ['id' => '84768', 'name' => 'Blood'],
            ],
            [
                '19849' => self::details('19849', 'Blood+', 1, [
                    'original_language' => 'ja',
                    'origin_country' => ['JP'],
                ]),
                '84768' => self::details('84768', 'Blood', 4, [
                    'original_language' => 'en',
                    'origin_country' => ['IE'],
                ]),
            ],
        );

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('Blood+', 2005, null, false, 4);

        $this->assertNotNull($resolved);
        $this->assertSame('19849', $resolved['tmdb_id']);
        $this->assertNotContains('84768', $this->calls->details, 'a fold collision is never even fetched');
    }

    /**
     * The same shape with a STRICT-identical title, so only the production origin
     * separates them. Pins the corroboration independently of the fold.
     */
    public function testCoverageGuardRefusesAnAlternativeFromADifferentProductionOrigin(): void
    {
        $tmdb = $this->provider(
            [['id' => '19849', 'name' => 'Blood+']],
            [
                ['id' => '19849', 'name' => 'Blood+'],
                ['id' => '84768', 'name' => 'Blood+'],
            ],
            [
                '19849' => self::details('19849', 'Blood+', 1, [
                    'original_language' => 'ja',
                    'origin_country' => ['JP'],
                ]),
                '84768' => self::details('84768', 'Blood+', 4, [
                    'original_language' => 'en',
                    'origin_country' => ['IE'],
                ]),
            ],
        );

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('Blood+', 2005, null, false, 4);

        $this->assertNotNull($resolved);
        $this->assertSame('19849', $resolved['tmdb_id']);
        $this->assertContains('84768', $this->calls->details, 'it is probed, then rejected on origin');
    }

    // ---------------------------- guard 1: corroboration instead of an absence

    /**
     * The `Kaze no Stigma` shape, made hostile. The winner is inexact and — unlike
     * the live case — is NOT on the year-less page, while an exact-titled
     * impostor sits at rank 0. Under the old rule that is a swap. But TMDB's own
     * `alternative_titles` for the winner carry the queried title, which is
     * positive proof the year filter did not fabricate it, so the winner stands.
     *
     * This is the reviewer's F3 failure mode — "a correct-but-alias-titled winner
     * that falls off page 1 while an exact-titled impostor sits at rank 0" — and
     * it is now decided by a fact, not by a truncated ranking.
     */
    public function testKeepsAWinnerTmdbKnowsByTheQueriedTitle(): void
    {
        $tmdb = $this->provider(
            [['id' => '61333', 'name' => 'Stigma of the Wind']],
            [['id' => '99999', 'name' => 'Kaze no Stigma']],
            [
                '61333' => self::details('61333', 'Stigma of the Wind', 1, [
                    'original_name' => '風のスティグマ',
                    'alternative_titles' => ['Kaze no Sutiguma', 'Kaze no Stigma'],
                ]),
                '99999' => self::details('99999', 'Kaze no Stigma', 1),
            ],
        );

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('Kaze no Stigma', 2007);

        $this->assertNotNull($resolved);
        $this->assertSame('61333', $resolved['tmdb_id']);
        $this->assertNotContains('99999', $this->calls->details, 'the impostor is never fetched');
    }

    /**
     * …and the corroborating probe is REUSED, so keeping the winner costs exactly
     * one details call — the same as if no guard existed.
     */
    public function testTheCorroboratingProbeIsReusedAsTheChosenDetails(): void
    {
        $tmdb = $this->provider(
            [['id' => '61333', 'name' => 'Stigma of the Wind']],
            [['id' => '99999', 'name' => 'Kaze no Stigma']],
            [
                '61333' => self::details('61333', 'Stigma of the Wind', 1, [
                    'alternative_titles' => ['Kaze no Stigma'],
                ]),
                '99999' => self::details('99999', 'Kaze no Stigma', 1),
            ],
        );

        (new SeriesMetadataResolver($tmdb))->resolve('Kaze no Stigma', 2007);

        $this->assertSame(['61333'], $this->calls->details);
    }

    /**
     * Fail closed: when the corroborating probe cannot be answered at all, the
     * guard declines rather than guessing. Leaving a series unswapped is always
     * acceptable; a wrong swap is not.
     */
    public function testKeepsTheWinnerWhenTheCorroboratingProbeFails(): void
    {
        $tmdb = $this->provider(
            [['id' => '101843', 'name' => 'The Big Battles Of World War II']],
            [['id' => '18241', 'name' => 'The Big O']],
            ['18241' => self::details('18241', 'The Big O', 1)],
        );

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('The Big O', 2001);

        $this->assertNull($resolved, 'an unanswerable probe must not become a swap');
        $this->assertNotContains('18241', $this->calls->details, 'the replacement is never fetched');
    }

    /**
     * The whole worst case in one fixture: guard 1 proposes and is corroborated
     * (1 probe), guard 2 then probes {@see SeriesCandidateSelector::MAX_ALTERNATIVES}
     * alternatives. Three searches, five details — the documented HARD bound.
     */
    public function testTheWorstCaseStaysInsideTheDocumentedCallBudget(): void
    {
        $tmdb = $this->provider(
            [['id' => '900', 'name' => 'Totally Unrelated Documentary']],
            [
                ['id' => '901', 'name' => 'Budget Show'],
                ['id' => '902', 'name' => 'Budget Show'],
                ['id' => '903', 'name' => 'Budget Show'],
                ['id' => '904', 'name' => 'Budget Show'],
                ['id' => '905', 'name' => 'Budget Show'],
            ],
            [
                '900' => self::details('900', 'Totally Unrelated Documentary', 1),
                '901' => self::details('901', 'Budget Show', 1),
                '902' => self::details('902', 'Budget Show', 1),
                '903' => self::details('903', 'Budget Show', 1),
                '904' => self::details('904', 'Budget Show', 1),
                '905' => self::details('905', 'Budget Show', 9),
            ],
        );

        $resolved = (new SeriesMetadataResolver($tmdb))->resolve('Budget Show', 1999, null, false, 6);

        $this->assertNotNull($resolved);
        $this->assertSame('901', $resolved['tmdb_id'], 'no alternative may be reached, so the swap target stands');
        $this->assertLessThanOrEqual(3, $this->calls->searches);
        $this->assertLessThanOrEqual(5, count($this->calls->details));
        $this->assertSame(['900', '901', '902', '903', '904'], $this->calls->details);
    }
}
