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

    public function testSeasonLabel(): void
    {
        $this->assertSame('Season 1', SeriesContainerNaming::seasonLabel(1));
        $this->assertSame('Season 12', SeriesContainerNaming::seasonLabel(12));
        $this->assertSame('Specials', SeriesContainerNaming::seasonLabel(0));
        $this->assertSame('Specials', SeriesContainerNaming::seasonLabel(-1));
    }
}
