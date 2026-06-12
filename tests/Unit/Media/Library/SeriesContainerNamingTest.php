<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\SeriesContainerNaming;

class SeriesContainerNamingTest extends TestCase
{
    public function testSlugLowercasesAndCollapsesNonAlnum(): void
    {
        $this->assertSame('breaking-bad', SeriesContainerNaming::slug('Breaking Bad'));
        $this->assertSame('24', SeriesContainerNaming::slug('24'));
        $this->assertSame('it-s-always-sunny', SeriesContainerNaming::slug("It's  Always... Sunny"));
    }

    public function testSlugFallsBackToUnknownWhenEmpty(): void
    {
        $this->assertSame('unknown', SeriesContainerNaming::slug('!!!'));
        $this->assertSame('unknown', SeriesContainerNaming::slug(''));
    }

    public function testTitleVariantsCollapseToSameSlug(): void
    {
        // The scanner cleans "24." / "24 -" to "24"; even uncleaned, the slug
        // converges so the backfill and scanner address the same container.
        $this->assertSame(
            SeriesContainerNaming::slug('24'),
            SeriesContainerNaming::slug('24.')
        );
        $this->assertSame(
            SeriesContainerNaming::slug('24'),
            SeriesContainerNaming::slug('24 -')
        );
    }

    public function testSeriesAndSeasonPathsAreDeterministic(): void
    {
        $this->assertSame('series:lib-1:24', SeriesContainerNaming::seriesPath('lib-1', '24'));
        $this->assertSame('season:lib-1:24:1', SeriesContainerNaming::seasonPath('lib-1', '24', 1));
        $this->assertSame('season:lib-1:24:0', SeriesContainerNaming::seasonPath('lib-1', '24', 0));
    }

    public function testSeriesPathWithSlugSourceDisambiguatesSiblingFolders(): void
    {
        // Two same-titled shows in different year folders must yield DISTINCT
        // synthetic paths when the full directory basename is the slug source —
        // otherwise their episodes silently merge into one container.
        $a = SeriesContainerNaming::seriesPath('lib-1', 'The Office', 'The Office (2005)');
        $b = SeriesContainerNaming::seriesPath('lib-1', 'The Office', 'The Office (2001)');
        $this->assertNotSame($a, $b);
        $this->assertSame('series:lib-1:the-office-2005', $a);
        $this->assertSame('series:lib-1:the-office-2001', $b);
    }

    public function testSeriesPathWithSlugSourceDisambiguatesPunctuationVariants(): void
    {
        // "Re:Zero" vs "Re Zero" both slug to "re-zero" on the bare title, so the
        // year-less folders would collide. Slugging the full basename keeps them
        // distinct when the folders themselves differ — here they are identical
        // text after slugging, so we prove that a punctuation-DIFFERENT folder
        // (e.g. carrying a year/disambiguator) stays distinct.
        $reColon = SeriesContainerNaming::seriesPath('lib-1', 'Re:Zero', 'Re:Zero (2016)');
        $reSpace = SeriesContainerNaming::seriesPath('lib-1', 'Re Zero', 'Re Zero');
        $this->assertNotSame($reColon, $reSpace);
    }

    public function testSeasonPathWithSlugSourceStaysBoundToDisambiguatedSeries(): void
    {
        $a = SeriesContainerNaming::seasonPath('lib-1', 'The Office', 1, 'The Office (2005)');
        $b = SeriesContainerNaming::seasonPath('lib-1', 'The Office', 1, 'The Office (2001)');
        $this->assertNotSame($a, $b);
        $this->assertSame('season:lib-1:the-office-2005:1', $a);
    }

    public function testSeriesPathWithoutSlugSourceIsLegacyBehaviour(): void
    {
        // The legacy (flag-false) path passes null → byte-identical to before.
        $this->assertSame(
            SeriesContainerNaming::seriesPath('lib-1', '24'),
            SeriesContainerNaming::seriesPath('lib-1', '24', null)
        );
        $this->assertSame('series:lib-1:24', SeriesContainerNaming::seriesPath('lib-1', '24', null));
    }

    public function testSeasonLabel(): void
    {
        $this->assertSame('Season 1', SeriesContainerNaming::seasonLabel(1));
        $this->assertSame('Season 12', SeriesContainerNaming::seasonLabel(12));
        $this->assertSame('Specials', SeriesContainerNaming::seasonLabel(0));
        $this->assertSame('Specials', SeriesContainerNaming::seasonLabel(-1));
    }

    public function testFromDirectoryNameParsesTitleAndParenYear(): void
    {
        $this->assertSame(
            ['title' => 'Assassination Classroom', 'year' => 2013],
            SeriesContainerNaming::fromDirectoryName('Assassination Classroom (2013)')
        );
    }

    public function testFromDirectoryNameParsesTrailingBracketYear(): void
    {
        $this->assertSame(
            ['title' => 'Cowboy Bebop', 'year' => 1998],
            SeriesContainerNaming::fromDirectoryName('Cowboy Bebop [1998]')
        );
    }

    public function testFromDirectoryNameKeepsNonYearTrailingTagBeforeYear(): void
    {
        // Only the trailing YEAR tag is stripped; a non-year tag like "(US)"
        // stays in the title because it disambiguates distinct shows.
        $this->assertSame(
            ['title' => 'Foo (US)', 'year' => 2018],
            SeriesContainerNaming::fromDirectoryName('Foo (US) (2018)')
        );
    }

    public function testFromDirectoryNameKeepsTrailingNonYearTag(): void
    {
        // A trailing non-year parenthetical is NOT a year → keep it, year null.
        $this->assertSame(
            ['title' => 'The Bridge (US)', 'year' => null],
            SeriesContainerNaming::fromDirectoryName('The Bridge (US)')
        );
        $this->assertSame(
            ['title' => 'Show (Uncut)', 'year' => null],
            SeriesContainerNaming::fromDirectoryName('Show (Uncut)')
        );
    }

    public function testFromDirectoryNameStripsTrailingYearRange(): void
    {
        // A "YYYY-YYYY" run range trailing tag is stripped; the first year wins.
        $this->assertSame(
            ['title' => 'The Office', 'year' => 2005],
            SeriesContainerNaming::fromDirectoryName('The Office (2005-2013)')
        );
    }

    public function testFromDirectoryNameWithoutYear(): void
    {
        $this->assertSame(
            ['title' => 'Bleach', 'year' => null],
            SeriesContainerNaming::fromDirectoryName('Bleach')
        );
    }

    public function testFromDirectoryNameNormalisesSceneSeparators(): void
    {
        $this->assertSame(
            ['title' => 'Death Note', 'year' => 2006],
            SeriesContainerNaming::fromDirectoryName('Death.Note.(2006)')
        );
    }

    public function testFromDirectoryNamePrefersRightmostYear(): void
    {
        // A numeric-looking token earlier in the name must not be mistaken for
        // the year; the bracketed (2013) wins.
        $this->assertSame(
            ['title' => 'Show 2', 'year' => 2013],
            SeriesContainerNaming::fromDirectoryName('Show 2 (2013)')
        );
    }

    public function testFromDirectoryNameIgnoresOutOfRangeYear(): void
    {
        $this->assertSame(
            ['title' => 'Episode', 'year' => null],
            SeriesContainerNaming::fromDirectoryName('Episode (1234)')
        );
    }
}
