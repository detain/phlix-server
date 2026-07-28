<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use Phlix\Media\Metadata\SeriesCandidateSelector;
use PHPUnit\Framework\TestCase;

/**
 * Every fixture in this file is a REAL `/search/tv` payload shape captured from
 * the live library's own titles, so the guards are pinned against the data that
 * produced the defect rather than against invented strings.
 *
 * @covers \Phlix\Media\Metadata\SeriesCandidateSelector
 */
final class SeriesCandidateSelectorTest extends TestCase
{
    private SeriesCandidateSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new SeriesCandidateSelector();
    }

    // ---------------------------------------------------------------- normalize

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function normalizeCases(): array
    {
        return [
            'plus sign is punctuation' => ['Blood+', 'blood'],
            'case folds' => ['The Big O', 'the big o'],
            'colon and spacing collapse' => ['Nura:   Rise of the Yokai Clan', 'nura rise of the yokai clan'],
            'ampersand spells out' => ['Battlestar Galactica: Blood & Chrome', 'battlestar galactica blood and chrome'],
            'digits survive' => ['AKB0048', 'akb0048'],
            'leading/trailing punctuation trims' => ['  -- 24 --  ', '24'],
            'cjk folds away entirely' => ['血战-C', 'c'],
            'empty stays empty' => ['', ''],
            'punctuation only stays empty' => ['---', ''],
        ];
    }

    /**
     * @dataProvider normalizeCases
     */
    public function testNormalizeFoldsToAComparisonKey(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->selector->normalize($input));
    }

    /**
     * Accent folding is best-effort (ext-intl), but the fold must be applied to
     * BOTH sides identically, so an accented pair still compares equal to itself.
     */
    public function testNormalizeIsStableForAnAccentedTitle(): void
    {
        $this->assertSame(
            $this->selector->normalize('Æon Flux'),
            $this->selector->normalize('Æon Flux'),
        );
    }

    // -------------------------------------------------------- isExactTitleMatch

    public function testExactMatchIgnoresPunctuationAndCase(): void
    {
        $this->assertTrue($this->selector->isExactTitleMatch('Blood+', ['id' => '19849', 'name' => 'Blood+']));
        $this->assertTrue($this->selector->isExactTitleMatch('The Big O', ['id' => '18241', 'name' => 'The Big O']));
    }

    public function testExactMatchRejectsADifferentShow(): void
    {
        $this->assertFalse($this->selector->isExactTitleMatch(
            'Blood+',
            ['id' => '40895', 'name' => 'Sincerely Yours in Cold Blood'],
        ));
        $this->assertFalse($this->selector->isExactTitleMatch(
            'The Big O',
            ['id' => '101843', 'name' => 'The Big Battles Of World War II'],
        ));
    }

    public function testExactMatchRejectsASupersetTitle(): void
    {
        // A prefix/superset is NOT exact: `The Office (US)` must not silently
        // bind to `The Office`, and `XIII` must not bind to `Appleseed XIII`.
        $this->assertFalse($this->selector->isExactTitleMatch('The Office (US)', ['id' => '2316', 'name' => 'The Office']));
        $this->assertFalse($this->selector->isExactTitleMatch('XIII', ['id' => '45348', 'name' => 'Appleseed XIII']));
    }

    public function testExactMatchIsFalseForAnEmptyQueryOrMissingName(): void
    {
        $this->assertFalse($this->selector->isExactTitleMatch('', ['id' => '1', 'name' => '']));
        $this->assertFalse($this->selector->isExactTitleMatch('---', ['id' => '1', 'name' => '---']));
        $this->assertFalse($this->selector->isExactTitleMatch('24', ['id' => '1']));
    }

    // -------------------------------------------- spuriousYearMatchReplacement

    /**
     * `The Big O` + first_air_date_year=2001 returns ONE result — an unrelated
     * WW2 documentary — while the unfiltered search puts the real show first.
     */
    public function testReplacesAYearFabricatedWinner(): void
    {
        $winner = ['id' => '101843', 'name' => 'The Big Battles Of World War II'];
        $yearLess = [
            ['id' => '18241', 'name' => 'The Big O'],
            ['id' => '73106', 'name' => "Celebrity Big Brother's Bit on the Side"],
        ];

        $replacement = $this->selector->spuriousYearMatchReplacement('The Big O', $winner, $yearLess);

        $this->assertNotNull($replacement);
        $this->assertSame('18241', $replacement['id']);
    }

    /** Same shape, different title/era — `Blood+` + 2000 lands on a Finnish drama. */
    public function testReplacesASecondYearFabricatedWinnerOfADifferentShape(): void
    {
        $winner = ['id' => '40895', 'name' => 'Sincerely Yours in Cold Blood'];
        $yearLess = [
            ['id' => '19849', 'name' => 'Blood+'],
            ['id' => '10545', 'name' => 'True Blood'],
        ];

        $replacement = $this->selector->spuriousYearMatchReplacement('Blood+', $winner, $yearLess);

        $this->assertNotNull($replacement);
        $this->assertSame('19849', $replacement['id']);
    }

    /**
     * The Battlestar shape: the year filter picked a title-IDENTICAL entity (the
     * 2003 miniseries). Wrong incarnation, but this guard must stay silent — it
     * is the season-coverage guard's job, and swapping here on popularity alone
     * would break the correctly-disambiguated `MacGyver (1985)` / `(2016)` pair.
     */
    public function testLeavesATitleIdenticalWinnerAlone(): void
    {
        $winner = ['id' => '71365', 'name' => 'Battlestar Galactica'];
        $yearLess = [
            ['id' => '1972', 'name' => 'Battlestar Galactica'],
            ['id' => '71365', 'name' => 'Battlestar Galactica'],
            ['id' => '501', 'name' => 'Battlestar Galactica'],
        ];

        $this->assertNull(
            $this->selector->spuriousYearMatchReplacement('Battlestar Galactica', $winner, $yearLess),
        );
    }

    /**
     * The romaji/English shape: `Nurarihyon no Mago` resolves — correctly — to
     * `Nura: Rise of the Yokai Clan` via TMDB's alternative-title index. The
     * winner is inexact, but it IS in the unfiltered list, so it is trusted.
     * This is the case a similarity threshold would have destroyed.
     */
    public function testLeavesAnInexactButUnfilteredRankedWinnerAlone(): void
    {
        $winner = ['id' => '37471', 'name' => 'Nura: Rise of the Yokai Clan'];
        $yearLess = [
            ['id' => '37471', 'name' => 'Nura: Rise of the Yokai Clan'],
            ['id' => '99999', 'name' => 'Nurarihyon no Mago'],
        ];

        $this->assertNull(
            $this->selector->spuriousYearMatchReplacement('Nurarihyon no Mago', $winner, $yearLess),
        );
    }

    /**
     * The `XIII (2011)` shape, and the ONLY thing standing between this guard and
     * a real regression: the winner is inexact (`XIII: The Series`) but IS ranked
     * by the unfiltered search, while that search's top hit — a *different* show
     * also called `XIII`, already bound to its own folder — is exact. Dropping the
     * presence check would hand the 2011 series the 2008 film's identity.
     */
    public function testKeepsAnInexactWinnerThatIsRankedBelowAnExactImpostor(): void
    {
        $winner = ['id' => '34639', 'name' => 'XIII: The Series'];
        $yearLess = [
            ['id' => '6971', 'name' => 'XIII'],
            ['id' => '34639', 'name' => 'XIII: The Series'],
        ];

        $this->assertNull($this->selector->spuriousYearMatchReplacement('XIII', $winner, $yearLess));
    }

    /**
     * The `Blood-C` shape: the winner is inexact (its TMDB entry carries a
     * Chinese primary title) AND absent from the unfiltered list — yet the
     * unfiltered top hit is not exact either, so nothing is swapped. Without this
     * clause the guard would replace a CORRECT match with `Crow's Blood`.
     */
    public function testKeepsTheWinnerWhenNoUnfilteredHitIsExact(): void
    {
        $winner = ['id' => '43270', 'name' => '血战-C'];
        $yearLess = [
            ['id' => '74531', 'name' => "Crow's Blood"],
            ['id' => '36829', 'name' => 'Cold Blood'],
        ];

        $this->assertNull($this->selector->spuriousYearMatchReplacement('Blood-C', $winner, $yearLess));
    }

    public function testKeepsTheWinnerWhenThereIsNoUnfilteredResultAtAll(): void
    {
        $winner = ['id' => '101843', 'name' => 'The Big Battles Of World War II'];

        $this->assertNull($this->selector->spuriousYearMatchReplacement('The Big O', $winner, []));
    }

    public function testKeepsTheWinnerWhenTheUnfilteredTopHitHasNoId(): void
    {
        $winner = ['id' => '101843', 'name' => 'The Big Battles Of World War II'];
        $yearLess = [['name' => 'The Big O']];

        $this->assertNull($this->selector->spuriousYearMatchReplacement('The Big O', $winner, $yearLess));
    }

    // ------------------------------------------------- exactTitleAlternatives

    public function testAlternativesKeepOnlyExactTitlesAndDropTheChosenId(): void
    {
        $results = [
            ['id' => '1972', 'name' => 'Battlestar Galactica'],
            ['id' => '71365', 'name' => 'Battlestar Galactica'],
            ['id' => '501', 'name' => 'Battlestar Galactica'],
            ['id' => '33240', 'name' => 'Battlestar Galactica: Blood & Chrome'],
            ['id' => '4621', 'name' => 'Galactica 1980'],
        ];

        $alternatives = $this->selector->exactTitleAlternatives('Battlestar Galactica', $results, '71365');

        $this->assertSame(['1972', '501'], array_column($alternatives, 'id'));
    }

    public function testAlternativesAreEmptyWhenNoOtherEntitySharesTheTitle(): void
    {
        // `Dragon Ball`'s unfiltered search is full of franchise siblings; none of
        // them is title-identical, so the coverage guard finds nothing to swap to.
        $results = [
            ['id' => '12971', 'name' => 'Dragon Ball Z'],
            ['id' => '12609', 'name' => 'Dragon Ball'],
            ['id' => '62715', 'name' => 'Dragon Ball Super'],
        ];

        $this->assertSame([], $this->selector->exactTitleAlternatives('Dragon Ball', $results, '12609'));
    }

    public function testAlternativesAreCapped(): void
    {
        $results = [];
        for ($i = 0; $i < 10; $i++) {
            $results[] = ['id' => (string) (100 + $i), 'name' => 'Kingdom'];
        }

        $alternatives = $this->selector->exactTitleAlternatives('Kingdom', $results, '999');

        $this->assertCount(SeriesCandidateSelector::MAX_ALTERNATIVES, $alternatives);
    }

    public function testAlternativesSkipRowsWithoutAnId(): void
    {
        $results = [
            ['name' => 'Battlestar Galactica'],
            ['id' => '', 'name' => 'Battlestar Galactica'],
            ['id' => '1972', 'name' => 'Battlestar Galactica'],
        ];

        $this->assertSame(
            ['1972'],
            array_column($this->selector->exactTitleAlternatives('Battlestar Galactica', $results, '71365'), 'id'),
        );
    }
}
