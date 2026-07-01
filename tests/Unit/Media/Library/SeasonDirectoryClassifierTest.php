<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\SeasonDirectoryClassifier;

/**
 * @covers \Phlix\Media\Library\SeasonDirectoryClassifier
 */
class SeasonDirectoryClassifierTest extends TestCase
{
    /**
     * SEASON directories → ['type' => 'season', 'season' => N] with N extracted,
     * ignoring trailing year ranges / subtitles / letter suffixes ("3b" → 3).
     *
     * @dataProvider seasonCases
     */
    public function testClassifiesSeasonDirectories(string $dirName, int $expectedSeason): void
    {
        // hasMedia = true so a non-season name could never be forced to 'skip';
        // season names must classify as 'season' on the name alone regardless.
        $result = SeasonDirectoryClassifier::classify($dirName, true);

        $this->assertSame('season', $result['type'], "should be a season dir: {$dirName}");
        $this->assertSame($expectedSeason, $result['season'] ?? null, "season number for: {$dirName}");
    }

    /** @return array<string, array{0:string,1:int}> */
    public static function seasonCases(): array
    {
        return [
            'Season 1'                      => ['Season 1', 1],
            'Season 01'                     => ['Season 01', 1],
            'Season 1 with year range'      => ['Season 1 (1987-88)', 1],
            'Season 3b - Movie (1990)'      => ['Season 3b - Movie (1990)', 3],
            'Season 3a (1989)'              => ['Season 3a (1989)', 3],
            'Season 01 The Substitute Arc'  => ['Season 01 The Substitute Arc', 1],
            'Season 02 - Baby Saga'         => ['Season 02 - Baby Saga', 2],
            'S01'                           => ['S01', 1],
            'S1'                            => ['S1', 1],
            'Pokemon S18 XY Kalos Quest'    => ['Pokémon S18 XY Kalos Quest', 18],
            'Pokemon S03 The Johto'         => ['Pokémon S03 The Johto Journeys', 3],
            'Law And Order S01 COMPLETE'    => ['Law.And.Order.Organized.Crime.S01.COMPLETE.720p.WEB.h264', 1],
            'Season 13 Zanpakuto'           => ['Season 13 Zanpakutō The Alternate Tale', 13],
        ];
    }

    /**
     * SPECIALS directories → ['type' => 'specials'] (season 0). Covers the word
     * forms plus explicit Season 0 / S00, and OVA/Extras treated as specials.
     *
     * @dataProvider specialsCases
     */
    public function testClassifiesSpecialsDirectories(string $dirName): void
    {
        $result = SeasonDirectoryClassifier::classify($dirName, true);

        $this->assertSame('specials', $result['type'], "should be specials: {$dirName}");
        $this->assertArrayNotHasKey('season', $result, 'specials carries no explicit season key');
    }

    /** @return array<string, array{0:string}> */
    public static function specialsCases(): array
    {
        return [
            'Special'   => ['Special'],
            'Specials'  => ['Specials'],
            'Season 0'  => ['Season 0'],
            'Season 00' => ['Season 00'],
            'S00'       => ['S00'],
            'Extras'    => ['Extras'],
            'OVA'       => ['OVA'],
            'OVAs'      => ['OVAs'],
        ];
    }

    /**
     * SKIP directories → ['type' => 'skip'] — junk "you might also like" / pointer
     * folders. Classified as junk on the NAME alone (hasMedia = true proves the
     * junk heuristic, not the empty-dir shortcut, is what skips them).
     *
     * @dataProvider skipCases
     */
    public function testClassifiesJunkDirectoriesAsSkipEvenWithMedia(string $dirName): void
    {
        $result = SeasonDirectoryClassifier::classify($dirName, true);

        $this->assertSame('skip', $result['type'], "should be skipped as junk: {$dirName}");
    }

    /** @return array<string, array{0:string}> */
    public static function skipCases(): array
    {
        return [
            'Other Shows'          => ['Other Shows'],
            'Others'               => ['Others'],
            'Probably LIKE these'  => ['Cartoons You Would Probably LIKE'],
            "You'd Like, HERE"     => ["Other Shows You'd Like, HERE"],
            'Cartoons You'         => ['Cartoons You Might Enjoy'],
            'Shows with'           => ['Shows with Similar Vibes'],
            'Themes, HERE'         => ['Themes, HERE'],
            'Related Cartoons'     => ['Related Cartoons, Here'],
            'trailing here'        => ['Bonus, here'],
        ];
    }

    /**
     * An empty (media-less) non-season directory is skipped via the empty-dir
     * shortcut even when its name is not obviously junk.
     */
    public function testEmptyNonSeasonDirectoryIsSkipped(): void
    {
        $result = SeasonDirectoryClassifier::classify('Artwork', false);
        $this->assertSame('skip', $result['type']);
    }

    /**
     * LOOSE directories → ['type' => 'loose'] — hold standalone media but are not
     * a season and not junk. Scanned without a forced season.
     *
     * @dataProvider looseCases
     */
    public function testClassifiesLooseMediaDirectories(string $dirName): void
    {
        $result = SeasonDirectoryClassifier::classify($dirName, true);

        $this->assertSame('loose', $result['type'], "should be loose media: {$dirName}");
    }

    /** @return array<string, array{0:string}> */
    public static function looseCases(): array
    {
        return [
            'Movies year range'   => ['Movies (1993-98)'],
            'The Movie - Stewie'  => ['The Movie - Stewie Griffin The Untold Story'],
            'Fight The Future'    => ['Fight The Future (1998)'],
            'plain release group' => ['REPACK-GROUP'],
        ];
    }

    /**
     * A non-season name with an UNKNOWN media state (null) defaults to 'loose'
     * (conservative: attempt a scan rather than silently drop a real dir).
     */
    public function testUnknownMediaStateNonSeasonDefaultsToLoose(): void
    {
        $result = SeasonDirectoryClassifier::classify('Bonus Content', null);
        $this->assertSame('loose', $result['type']);
    }
}
