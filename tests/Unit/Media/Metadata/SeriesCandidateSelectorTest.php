<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use Phlix\Media\Metadata\SeriesCandidateSelector;
use PHPUnit\Framework\TestCase;

/**
 * Every fixture in this file is a REAL `/search/tv` payload shape captured from
 * the live library's own titles, so the guards are pinned against the data that
 * produced the defect rather than against invented strings.
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

    /**
     * …and the OTHER direction, which the pair above cannot see: a candidate
     * whose title EXTENDS the query is not exact either. Equality, not prefix.
     *
     * Both folds are pinned because both are load-bearing in opposite senses —
     * relaxing the permissive one to a prefix test silently disarms guard 1
     * (`Naruto` would trust a `Naruto Shippūden` winner) and makes
     * {@see SeriesCandidateSelector::knowsTitle()} corroborate anything.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function extendedTitleCases(): array
    {
        return [
            'franchise sequel' => ['Naruto', 'Naruto Shippūden'],
            'series suffix' => ['XIII', 'XIII: The Series'],
            'subtitle suffix' => ['Magi', 'Magi: The Labyrinth of Magic'],
            'the big o' => ['The Big O', 'The Big O Show'],
        ];
    }

    /**
     * @dataProvider extendedTitleCases
     */
    public function testNeitherFoldAcceptsACandidateThatExtendsTheQuery(string $query, string $name): void
    {
        $candidate = ['id' => '1', 'name' => $name];

        $this->assertFalse($this->selector->isExactTitleMatch($query, $candidate));
        $this->assertFalse($this->selector->isStrictTitleMatch($query, $candidate));
        $this->assertFalse($this->selector->knowsTitle($query, ['name' => $name]));
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

    // --------------------------------------------------------- normalizeStrict

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function strictNormalizeCases(): array
    {
        return [
            'plus survives as its own token' => ['Blood+', 'blood +'],
            'bang survives' => ['Eureka!', 'eureka'  . ' !'],
            'ellipsis survives' => ['Once Upon a Time...', 'once upon a time . . .'],
            'separators still collapse' => ['Avatar - The Last Airbender', 'avatar the last airbender'],
            'colon still collapses' => ['Avatar: The Last Airbender', 'avatar the last airbender'],
            'apostrophe still collapses' => ["Crow's Blood", 'crow s blood'],
            'case still folds' => ['The Big O', 'the big o'],
            'ampersand still spells out' => ['Blood & Chrome', 'blood and chrome'],
            'empty stays empty' => ['', ''],
            'separators only stay empty' => ['---', ''],
        ];
    }

    /**
     * @dataProvider strictNormalizeCases
     */
    public function testStrictNormalizeKeepsDistinguishingMarks(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->selector->normalizeStrict($input));
    }

    /**
     * THE finding this fold exists for. `Blood+` and `Blood` collapse to the same
     * permissive key, and TMDB 84768 `Blood` (an unrelated 2018 Irish drama) is
     * really offered as a same-title alternative for the live `Blood+` folder.
     * The permissive fold must keep saying yes (it is only a trust test); the
     * strict fold — the one every swap gates on — must say no.
     */
    public function testStrictMatchSeparatesBloodPlusFromBlood(): void
    {
        $candidate = ['id' => '84768', 'name' => 'Blood'];

        $this->assertTrue(
            $this->selector->isExactTitleMatch('Blood+', $candidate),
            'the permissive fold is deliberately unchanged',
        );
        $this->assertFalse($this->selector->isStrictTitleMatch('Blood+', $candidate));
        $this->assertTrue($this->selector->isStrictTitleMatch('Blood+', ['id' => '19849', 'name' => 'Blood+']));
    }

    /**
     * The other live fold collisions the same defect covers. None of them trips
     * the guards today; each must stay separated even if one ever does.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function foldCollisionCases(): array
    {
        return [
            'Food Wars' => ['Food Wars!', 'Food Wars'],
            'Eureka' => ['Eureka', 'Eureka!'],
            'Once Upon a Time' => ['Once Upon a Time', 'Once Upon a Time...'],
            'Full Metal Panic' => ['Full Metal Panic', 'Full Metal Panic!'],
            'Gangsta' => ['Gangsta', 'Gangsta.'],
            'Soul Eater Not' => ['Soul Eater Not', 'Soul Eater Not!'],
        ];
    }

    /**
     * @dataProvider foldCollisionCases
     */
    public function testStrictMatchSeparatesTheLiveFoldCollisions(string $query, string $other): void
    {
        $candidate = ['id' => '1', 'name' => $other];

        $this->assertTrue($this->selector->isExactTitleMatch($query, $candidate));
        $this->assertFalse($this->selector->isStrictTitleMatch($query, $candidate));
    }

    /** The two live corrections must still pass the strict test. */
    public function testStrictMatchStillAcceptsTheLiveCorrections(): void
    {
        $this->assertTrue($this->selector->isStrictTitleMatch(
            'Avatar - The Last Airbender',
            ['id' => '246', 'name' => 'Avatar: The Last Airbender'],
        ));
        $this->assertTrue($this->selector->isStrictTitleMatch(
            'Battlestar Galactica',
            ['id' => '1972', 'name' => 'Battlestar Galactica'],
        ));
        $this->assertTrue($this->selector->isStrictTitleMatch('The Big O', ['id' => '18241', 'name' => 'The Big O']));
    }

    public function testStrictMatchIsFalseForAnEmptyQuery(): void
    {
        $this->assertFalse($this->selector->isStrictTitleMatch('', ['id' => '1', 'name' => '']));
        $this->assertFalse($this->selector->isStrictTitleMatch('---', ['id' => '1', 'name' => '---']));
    }

    /**
     * The guard must not act on a permissive-only match: `Blood+`'s year-less top
     * hit being `Blood` is NOT grounds to re-point 50 files.
     */
    public function testReplacementRejectsAPermissiveOnlyTopHit(): void
    {
        $winner = ['id' => '40895', 'name' => 'Sincerely Yours in Cold Blood'];
        $yearLess = [['id' => '84768', 'name' => 'Blood']];

        $this->assertNull($this->selector->spuriousYearMatchReplacement('Blood+', $winner, $yearLess));
    }

    // ---------------------------------------------------------------- knowsTitle

    /**
     * `Kaze no Stigma` is TMDB 61333 `Stigma of the Wind`, whose
     * `alternative_titles` really do carry `Kaze no Stigma`. That is a POSITIVE
     * fact about the entity — the bounded replacement for the unverifiable claim
     * that a winner "appears nowhere" in a truncated search ranking.
     */
    public function testKnowsTitleFindsAnAlternativeTitle(): void
    {
        $details = [
            'name' => 'Stigma of the Wind',
            'original_name' => '風のスティグマ',
            'alternative_titles' => ['Kaze no Sutiguma', 'Kaze no Stigma'],
        ];

        $this->assertTrue($this->selector->knowsTitle('Kaze no Stigma', $details));
    }

    public function testKnowsTitleFindsThePrimaryAndOriginalName(): void
    {
        $this->assertTrue($this->selector->knowsTitle('The Big O', ['name' => 'The Big O']));
        $this->assertTrue($this->selector->knowsTitle('Blood+', ['name' => 'x', 'original_name' => 'Blood+']));
    }

    /**
     * The two live corrections: TMDB carries no title resembling the query for
     * either winner, in any language, so neither is corroborated.
     */
    public function testKnowsTitleIsFalseForTheYearFabricatedWinners(): void
    {
        $this->assertFalse($this->selector->knowsTitle('Blood+', [
            'name' => 'Sincerely Yours in Cold Blood',
            'original_name' => 'Kylmäverisesti sinun',
            'alternative_titles' => [],
        ]));
        $this->assertFalse($this->selector->knowsTitle('The Big O', [
            'name' => 'The Big Battles Of World War II',
            'original_name' => 'The Big Battles Of World War II',
            'alternative_titles' => [],
        ]));
    }

    public function testKnowsTitleIsFalseForAnEmptyQueryOrEmptyDetails(): void
    {
        $this->assertFalse($this->selector->knowsTitle('', ['name' => 'anything']));
        $this->assertFalse($this->selector->knowsTitle('The Big O', []));
    }

    /**
     * The corroboration deliberately uses the PERMISSIVE fold: a loose "yes" must
     * win, because a "yes" makes the caller stand down (fail closed).
     */
    public function testKnowsTitleUsesThePermissiveFold(): void
    {
        $this->assertTrue($this->selector->knowsTitle('Blood+', ['name' => 'Blood']));
    }

    // ------------------------------------------------- sharesProductionOrigin

    public function testProductionOriginMatchesTheLiveSwaps(): void
    {
        // Avatar: 2024 remake vs 2005 original — same language, same country.
        $this->assertTrue($this->selector->sharesProductionOrigin(
            ['original_language' => 'en', 'origin_country' => ['US']],
            ['original_language' => 'en', 'origin_country' => ['US']],
        ));
        // Battlestar: the miniseries is CA-only, the series is CA+US+GB.
        $this->assertTrue($this->selector->sharesProductionOrigin(
            ['original_language' => 'en', 'origin_country' => ['CA']],
            ['original_language' => 'en', 'origin_country' => ['CA', 'US', 'GB']],
        ));
    }

    /**
     * The live `Blood+` collision on its second, independent axis: 19849 is
     * `ja`/`JP`, 84768 is `en`/`IE`. Either the strict fold or this check rejects
     * it on its own — deliberately, so a sibling step cannot remove the only
     * protection.
     */
    public function testProductionOriginRejectsADifferentLanguage(): void
    {
        $this->assertFalse($this->selector->sharesProductionOrigin(
            ['original_language' => 'ja', 'origin_country' => ['JP']],
            ['original_language' => 'en', 'origin_country' => ['IE']],
        ));
    }

    public function testProductionOriginRejectsDisjointCountries(): void
    {
        $this->assertFalse($this->selector->sharesProductionOrigin(
            ['original_language' => 'en', 'origin_country' => ['US']],
            ['original_language' => 'en', 'origin_country' => ['IE']],
        ));
    }

    /** Fail closed: an unknown language or an unknown country is not a match. */
    public function testProductionOriginIsFalseWhenEitherSideIsUnknown(): void
    {
        $known = ['original_language' => 'en', 'origin_country' => ['US']];

        $this->assertFalse($this->selector->sharesProductionOrigin($known, []));
        $this->assertFalse($this->selector->sharesProductionOrigin([], $known));
        $this->assertFalse($this->selector->sharesProductionOrigin(
            $known,
            ['original_language' => 'en', 'origin_country' => []],
        ));
        $this->assertFalse($this->selector->sharesProductionOrigin(
            ['original_language' => '', 'origin_country' => ['US']],
            $known,
        ));
    }

    public function testProductionOriginIgnoresCaseAndPadding(): void
    {
        $this->assertTrue($this->selector->sharesProductionOrigin(
            ['original_language' => 'EN', 'origin_country' => [' us ']],
            ['original_language' => 'en', 'origin_country' => ['US']],
        ));
    }
}
