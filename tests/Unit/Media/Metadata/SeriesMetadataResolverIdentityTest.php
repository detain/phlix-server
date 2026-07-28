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
 *
 * @covers \Phlix\Media\Metadata\SeriesMetadataResolver
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
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function details(string $id, string $name, int $seasons, array $extra = []): array
    {
        return array_merge(['name' => $name, 'tmdb_id' => $id, 'number_of_seasons' => $seasons], $extra);
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
}
