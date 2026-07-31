<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\SceneFilenameNormalizer;
use Phlix\Media\Metadata\TitleSuffixStripper;

/**
 * Unit tests for {@see SceneFilenameNormalizer}: parsing dirty release filenames
 * into clean {title, year} tuples.
 *
 * @since 0.21.0
 */
final class SceneFilenameNormalizerTest extends TestCase
{
    public function testNormalizeStripsExtension(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.2022.1080p.WEBRip.mp4');
        $this->assertSame('Movie', $result['title']);
        $this->assertSame(2022, $result['year']);
    }

    public function testNormalizeStripsAllVideoExtensions(): void
    {
        $extensions = ['mkv', 'avi', 'mov', 'wmv', 'flv', 'webm', 'm4v', 'mpg', 'mpeg', 'ts'];

        foreach ($extensions as $ext) {
            $result = SceneFilenameNormalizer::normalize("Movie.2022.1080p.{$ext}");
            $this->assertArrayHasKey('title', $result);
            $this->assertArrayHasKey('year', $result);
        }
    }

    public function testNormalizeReplacesDotsAndUnderscoresWithSpaces(): void
    {
        $result = SceneFilenameNormalizer::normalize('Three.Wise.Men.And.A.Baby.2022.1080p.WEBRip.x264.AAC5.1-[YTS.MX].mp4');

        $this->assertSame('Three Wise Men And A Baby', $result['title']);
        $this->assertSame(2022, $result['year']);
    }

    public function testNormalizeCollapsesWhitespace(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie  .  2022  .  1080p.mp4');

        $this->assertSame('Movie', $result['title']);
        $this->assertSame(2022, $result['year']);
    }

    public function testNormalizeExtractsYearAsFirstStandalone19xx(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.1999.1080p.BluRay.x264-YIFY.mp4');

        $this->assertSame('Movie', $result['title']);
        $this->assertSame(1999, $result['year']);
    }

    public function testNormalizeExtractsYearAsFirstStandalone20xx(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.2023.1080p.WEBRip.YTS.mp4');

        $this->assertSame('Movie', $result['title']);
        $this->assertSame(2023, $result['year']);
    }

    public function testNormalizeTruncatesTitleAtYear(): void
    {
        $result = SceneFilenameNormalizer::normalize('Three.Wise.Men.And.A.Baby.2022.1080p.WEBRip.x264.AAC5.1-[YTS.MX].mp4');

        $this->assertSame('Three Wise Men And A Baby', $result['title']);
        $this->assertSame(2022, $result['year']);
        $this->assertStringNotContainsString('1080p', $result['title']);
        $this->assertStringNotContainsString('WEBRip', $result['title']);
        $this->assertStringNotContainsString('YTS', $result['title']);
    }

    public function testNormalizeStripsBracketedTags(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.2022.[YTS.MX].mp4');

        $this->assertStringNotContainsString('[', $result['title']);
        $this->assertStringNotContainsString(']', $result['title']);
    }

    public function testNormalizeStripsParenthesizedTags(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.2022.(YTS).mp4');

        $this->assertStringNotContainsString('(', $result['title']);
        $this->assertStringNotContainsString(')', $result['title']);
    }

    public function testNormalizeStripsTrailingGroup(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.2022.1080p.BluRay-RARBG.mp4');

        $this->assertStringNotContainsString('RARBG', $result['title']);
    }

    public function testNormalizeStripsQualityTokensWhenNoYear(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.Title.1080p.x264.mp4');

        $this->assertSame('Movie Title', $result['title']);
        $this->assertNull($result['year']);
        $this->assertStringNotContainsString('1080p', $result['title']);
        $this->assertStringNotContainsString('x264', $result['title']);
    }

    public function testNormalizeStripsAllQualityTokensWhenNoYear(): void
    {
        $result = SceneFilenameNormalizer::normalize(
            'Movie.Title.720p.2160p.4k.UHD.WEBRip.WEB-DL.BluRay.BRRip.HDRip.DVDRip.x264.x265.HEVC.H264.H265.AAC.AC3.DTS.DDP5.10bit.REMUX.PROPER.REPACK.EXTENDED.mp4'
        );

        $this->assertSame('Movie Title', $result['title']);
        $this->assertNull($result['year']);
    }

    public function testNormalizeHandlesAlreadyCleanTitleWithYear(): void
    {
        $result = SceneFilenameNormalizer::normalize('The Matrix (1999)');

        $this->assertSame('The Matrix', $result['title']);
        $this->assertSame(1999, $result['year']);
    }

    public function testNormalizeHandlesTitleWithYearInParentheses(): void
    {
        $result = SceneFilenameNormalizer::normalize('The Matrix (1999).mp4');

        $this->assertSame('The Matrix', $result['title']);
        $this->assertSame(1999, $result['year']);
    }

    public function testNormalizeHandlesTitleWithYearInBrackets(): void
    {
        $result = SceneFilenameNormalizer::normalize('The Matrix [1999].mp4');

        $this->assertSame('The Matrix', $result['title']);
        $this->assertSame(1999, $result['year']);
    }

    public function testNormalizeReturnsOriginalAsRaw(): void
    {
        $original = 'Three.Wise.Men.And.A.Baby.2022.1080p.WEBRip.x264.AAC5.1-[YTS.MX].mp4';
        $result = SceneFilenameNormalizer::normalize($original);

        $this->assertSame($original, $result['raw']);
    }

    public function testNormalizeBladeRunner2049EdgeCase(): void
    {
        $result = SceneFilenameNormalizer::normalize('Blade.Runner.2049.2017.2160p.UHD.BluRay.x265.HEVC.10bit-Hi10p.mkv');

        $this->assertSame('Blade Runner 2049', $result['title']);
        $this->assertSame(2017, $result['year']);
    }

    public function testNormalizeYearInTitleAsFirstToken(): void
    {
        $result = SceneFilenameNormalizer::normalize('2012.2009.DVDRip.XviD-EXViD.avi');

        $this->assertSame('2012', $result['title']);
        $this->assertSame(2009, $result['year']);
    }

    public function testNormalizeMovieTitleWithYearInTitleFollowedByReleaseYear(): void
    {
        // "2012" is part of the title (e.g. "Movie Title 2012" is the movie name).
        // "2024" is the release year (followed by REMASTERED quality token).
        // Example: The.War.of.1812.2019.1080p → title "The War of 1812", year 2019.
        $result = SceneFilenameNormalizer::normalize('Movie.Title.2012.2024.REMASTERED.1080p.BluRay.mkv');

        $this->assertSame('Movie Title 2012', $result['title']);
        $this->assertSame(2024, $result['year']);
    }

    public function testNormalizeMultiYearMovieTitleEdgeCase(): void
    {
        $result = SceneFilenameNormalizer::normalize('2012.2009.DVDRip.XviD.mkv');

        $this->assertSame('2012', $result['title']);
        $this->assertSame(2009, $result['year']);
    }

    public function testNormalizeYtsExample(): void
    {
        $result = SceneFilenameNormalizer::normalize('Three.Wise.Men.And.A.Baby.2022.1080p.WEBRip.x264.AAC5.1-[YTS.MX].mp4');

        $this->assertSame('Three Wise Men And A Baby', $result['title']);
        $this->assertSame(2022, $result['year']);
        $this->assertSame('Three.Wise.Men.And.A.Baby.2022.1080p.WEBRip.x264.AAC5.1-[YTS.MX].mp4', $result['raw']);
    }

    public function testNormalizeRarbGStyle(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.Name.2023.PROPER.1080p.BluRay.x264-RARBG.mp4');

        $this->assertSame('Movie Name', $result['title']);
        $this->assertSame(2023, $result['year']);
    }

    public function testNormalizeEvolveStyle(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.Name.2022.1080p.WEB-DL.EVOLVE.mp4');

        $this->assertSame('Movie Name', $result['title']);
        $this->assertSame(2022, $result['year']);
    }

    public function testNormalizeSparksStyle(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.Name.2021.720p.WEBRip.SPARKS.mkv');

        $this->assertSame('Movie Name', $result['title']);
        $this->assertSame(2021, $result['year']);
    }

    public function testNormalizeHandlesNoYearNoQualityTokens(): void
    {
        $result = SceneFilenameNormalizer::normalize('Some.Movie.Name.mkv');

        $this->assertSame('Some Movie Name', $result['title']);
        $this->assertNull($result['year']);
    }

    public function testNormalizePreservesTitleWithoutYearWhenOnlyQualityTokens(): void
    {
        $result = SceneFilenameNormalizer::normalize('Some.Movie.Name.1080p.mkv');

        $this->assertSame('Some Movie Name', $result['title']);
        $this->assertNull($result['year']);
    }

    public function testNormalizeTruncatesJunkAfterYear(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.2022.RERIP.NFO.FIX.1080p.BluRay.x264-YIFY.mp4');

        $this->assertSame('Movie', $result['title']);
        $this->assertSame(2022, $result['year']);
        $this->assertStringNotContainsString('RERIP', $result['title']);
        $this->assertStringNotContainsString('NFO', $result['title']);
        $this->assertStringNotContainsString('FIX', $result['title']);
    }

    public function testNormalizeHandlesUnderscoreDelimited(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie_Title_2022_1080p_WEBRip_x264.mp4');

        $this->assertSame('Movie Title', $result['title']);
        $this->assertSame(2022, $result['year']);
    }

    public function testNormalizeHandlesMixedDelimiter(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.Title_2022.1080p_WEBRip.mp4');

        $this->assertSame('Movie Title', $result['title']);
        $this->assertSame(2022, $result['year']);
    }

    public function testNormalizeHandlesFullwidthBrackets(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.2022.1080p.【YTS.MX】.mp4');

        $this->assertSame('Movie', $result['title']);
        $this->assertSame(2022, $result['year']);
        $this->assertStringNotContainsString('【', $result['title']);
        $this->assertStringNotContainsString('】', $result['title']);
    }

    public function testNormalizeHandlesChineseBracketStyle(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.2022.【YTS.MX】.mp4');

        $this->assertSame('Movie', $result['title']);
        $this->assertSame(2022, $result['year']);
    }

    public function testNormalizeHandlesRemasteredTag(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.Title.2010.REMASTERED.1080p.BluRay.x264.mkv');

        $this->assertSame('Movie Title', $result['title']);
        $this->assertSame(2010, $result['year']);
    }

    public function testNormalizeHandlesExtendedTag(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.Title.2021.EXTENDED.1080p.BluRay.mkv');

        $this->assertSame('Movie Title', $result['title']);
        $this->assertSame(2021, $result['year']);
    }

    public function testNormalizeHandlesUnratedTag(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.Title.2022.UNRATED.1080p.WEBRip.mkv');

        $this->assertSame('Movie Title', $result['title']);
        $this->assertSame(2022, $result['year']);
    }

    public function testNormalizeHandlesTheatricalTag(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.Title.2019.THEATRICAL.1080p.WEB-DL.mkv');

        $this->assertSame('Movie Title', $result['title']);
        $this->assertSame(2019, $result['year']);
    }

    public function testNormalizeHandlesFinalRipTag(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.Title.2020.FINAL.RIP.1080p.BluRay.mkv');

        $this->assertSame('Movie Title', $result['title']);
        $this->assertSame(2020, $result['year']);
    }

    public function testNormalizeHandlesLimitedTag(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.Title.2021.LIMITED.1080p.WEBRip.mkv');

        $this->assertSame('Movie Title', $result['title']);
        $this->assertSame(2021, $result['year']);
    }

    public function testNormalizeEmptyStringReturnsEmptyTitleAndNullYear(): void
    {
        $result = SceneFilenameNormalizer::normalize('');

        $this->assertSame('', $result['title']);
        $this->assertNull($result['year']);
        $this->assertSame('', $result['raw']);
    }

    public function testNormalizeBareYearNameKeepsNumericTitle(): void
    {
        // A name that is ONLY a 4-digit year (a film literally titled "2022") must keep
        // the numeric title — the old behavior stripped it to an empty title that then
        // failed lookup (phlix_ui_missing.md #4).
        $result = SceneFilenameNormalizer::normalize('2022.mp4');

        $this->assertSame('2022', $result['title']);
        $this->assertNull($result['year']);
    }

    public function testNormalizeYearOnlyFollowedByQuality(): void
    {
        $result = SceneFilenameNormalizer::normalize('2022.1080p.x264.mp4');

        $this->assertSame('', $result['title']);
        $this->assertSame(2022, $result['year']);
    }

    public function testNormalizePreservesDashGroupTokens(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.2022.1080p.BluRay-DASH.mkv');
        $this->assertSame('Movie', $result['title']);

        $result2 = SceneFilenameNormalizer::normalize('Movie.2022.1080p.BluRay-RAWS.mkv');
        $this->assertSame('Movie', $result2['title']);
    }

    public function testNormalizeHandlesDDPTags(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.2022.1080p.BluRay.DDP5.1.x264.mkv');

        $this->assertSame('Movie', $result['title']);
        $this->assertSame(2022, $result['year']);
    }

    public function testNormalizeHandlesTrueHDTags(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.2022.1080p.BluRay.TRUEHD.MA.x264.mkv');

        $this->assertSame('Movie', $result['title']);
        $this->assertSame(2022, $result['year']);
    }

    public function testNormalizeHandles8bitAnd12bit(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.2022.1080p.BluRay.8bit.x264.mkv');
        $this->assertSame('Movie', $result['title']);
        $this->assertSame(2022, $result['year']);

        $result2 = SceneFilenameNormalizer::normalize('Movie.2022.1080p.BluRay.12bit.x265.mkv');
        $this->assertSame('Movie', $result2['title']);
        $this->assertSame(2022, $result2['year']);
    }

    public function testNormalizeHandlesHDTVPattern(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.2022.HDTV.x264-RGB.mp4');

        $this->assertSame('Movie', $result['title']);
        $this->assertSame(2022, $result['year']);
    }

    public function testNormalizeHandlesUHDTag(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.2022.UHD.2160p.WEB-DL.x265.mkv');

        $this->assertSame('Movie', $result['title']);
        $this->assertSame(2022, $result['year']);
        $this->assertStringNotContainsString('UHD', $result['title']);
    }

    public function testNormalizeYearEdgeCase1800sIsNotMatched(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.1899.1080p.BluRay.mkv');

        $this->assertSame('Movie', $result['title']);
        $this->assertNull($result['year']);
    }

    public function testNormalizeYearEdgeCase2100IsNotMatched(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.2100.1080p.BluRay.mkv');

        $this->assertSame('Movie', $result['title']);
        $this->assertNull($result['year']);
    }

    public function testNormalizeYearEdgeCase1970IsMatched(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.1970.1080p.BluRay.mkv');

        $this->assertSame('Movie', $result['title']);
        $this->assertSame(1970, $result['year']);
    }

    public function testNormalizeYearEdgeCase2050IsMatched(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.2050.1080p.BluRay.mkv');

        $this->assertSame('Movie', $result['title']);
        $this->assertSame(2050, $result['year']);
    }

    public function testNormalizePreservesAVCAndHEVCInTitle(): void
    {
        $result = SceneFilenameNormalizer::normalize('AVC.Heading.2022.1080p.BluRay.mkv');
        $this->assertSame('AVC Heading', $result['title']);

        $result2 = SceneFilenameNormalizer::normalize('HEVC.Test.2022.1080p.BluRay.mkv');
        $this->assertSame('HEVC Test', $result2['title']);
    }

    public function testNormalizeHandlesMultipleSpacesAfterCleanup(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie   .   2022   .   1080p   .   mp4');

        $this->assertSame('Movie', $result['title']);
        $this->assertSame(2022, $result['year']);
    }

    public function testNormalizeHandlesCD1CD2Suffix(): void
    {
        // CD1/CD2 are disc markers, not title components. The code correctly
        // truncates title at the year, so these are excluded.
        $result = SceneFilenameNormalizer::normalize('Movie.2022.720p.BluRay.CD1.mkv');
        $this->assertSame('Movie', $result['title']);
        $this->assertSame(2022, $result['year']);

        $result2 = SceneFilenameNormalizer::normalize('Movie.2022.720p.BluRay.CD2.mkv');
        $this->assertSame('Movie', $result2['title']);
        $this->assertSame(2022, $result2['year']);
    }

    public function testNormalizeHandlesPartSuffix(): void
    {
        // Part1/Part2 are disc markers, not title components. The code correctly
        // truncates title at the year, so these are excluded.
        $result = SceneFilenameNormalizer::normalize('Movie.2022.720p.BluRay.Part1.mkv');
        $this->assertSame('Movie', $result['title']);
        $this->assertSame(2022, $result['year']);
    }

    public function testNormalizeReturnsTitleNotEmptyWhenOnlyQualityTokensAndNoYear(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.Title.x264.mp4');

        $this->assertSame('Movie Title', $result['title']);
        $this->assertNull($result['year']);
    }

    public function testNormalizePreservesAV1InTitle(): void
    {
        $result = SceneFilenameNormalizer::normalize('AV1.Movie.2022.1080p.WEBRip.mkv');
        $this->assertSame('AV1 Movie', $result['title']);
    }

    public function testNormalizePreservesVP9InTitle(): void
    {
        $result = SceneFilenameNormalizer::normalize('VP9.Movie.2022.1080p.WEBRip.mkv');
        $this->assertSame('VP9 Movie', $result['title']);
    }

    // --- gap-report fixes (phlix_ui_missing.md #4) ---------------------------

    /** A bracketed (YYYY) outside 1900–2099 must NOT be taken as the year. */
    public function testNormalizeRejectsOutOfRangeBracketedYear(): void
    {
        $result = SceneFilenameNormalizer::normalize('Avatar (1899).mkv');
        $this->assertSame('Avatar', $result['title']);
        $this->assertNull($result['year']);
    }

    /** A plausible bracketed (YYYY) is still extracted as the year. */
    public function testNormalizeAcceptsInRangeBracketedYear(): void
    {
        $result = SceneFilenameNormalizer::normalize('Avatar (2009).mkv');
        $this->assertSame('Avatar', $result['title']);
        $this->assertSame(2009, $result['year']);
    }

    /** A title that IS a 4-digit year (e.g. "1917") keeps the title, not stripped to ''. */
    public function testNormalizeKeepsNumericTitleWhenItIsTheWholeName(): void
    {
        foreach (['1917', '2012'] as $name) {
            $result = SceneFilenameNormalizer::normalize("{$name}.mkv");
            $this->assertSame($name, $result['title'], "title for {$name}");
            $this->assertNull($result['year'], "year for {$name}");
        }
    }

    /** A dirty YTS-style release filename normalizes to the clean (title, year). */
    public function testNormalizeDirtyYtsFilename(): void
    {
        $result = SceneFilenameNormalizer::normalize(
            'Three.Wise.Men.And.A.Baby.2022.1080p.WEBRip.x264.AAC5.1-[YTS.MX].mp4'
        );
        $this->assertSame('Three Wise Men And A Baby', $result['title']);
        $this->assertSame(2022, $result['year']);
    }

    // --- Step 13.1: configurable noise-suffix stripping ---------------------

    /** A trailing "Directors Cut" edition phrase is peeled off the title. */
    public function testNormalizeStripsDirectorsCutSuffix(): void
    {
        $result = SceneFilenameNormalizer::normalize('Blade Runner Directors Cut');

        $this->assertSame('Blade Runner', $result['title']);
        $this->assertNull($result['year']);
    }

    /** Edition noise after a detected year does not pollute the parsed title. */
    public function testNormalizeStripsExtendedCutAfterYear(): void
    {
        $result = SceneFilenameNormalizer::normalize('Aliens 1986 Extended Cut 1080p');

        $this->assertSame('Aliens', $result['title']);
        $this->assertSame(1986, $result['year']);
    }

    /** A combined "UNCUT & UNRATED" suffix is fully removed. */
    public function testNormalizeStripsUncutAndUnratedSuffix(): void
    {
        $result = SceneFilenameNormalizer::normalize('Dune UNCUT & UNRATED');

        $this->assertSame('Dune', $result['title']);
        $this->assertNull($result['year']);
    }

    /** An "ALTERNATE ENDING" suffix is removed without touching the title number. */
    public function testNormalizeStripsAlternateEndingSuffix(): void
    {
        $result = SceneFilenameNormalizer::normalize('District 9 ALTERNATE ENDING');

        $this->assertSame('District 9', $result['title']);
        $this->assertNull($result['year']);
    }

    /** A bare trailing "YIFY" aggregator tag is removed. */
    public function testNormalizeStripsTrailingYify(): void
    {
        $result = SceneFilenameNormalizer::normalize('Foo YIFY');

        $this->assertSame('Foo', $result['title']);
        $this->assertNull($result['year']);
    }

    /** A single-token noise word that IS the whole title must NOT empty it. */
    public function testNormalizeNoiseTokenDoesNotEmptyTitle(): void
    {
        $result = SceneFilenameNormalizer::normalize('DC');

        $this->assertSame('DC', $result['title']);
        $this->assertNull($result['year']);
    }

    /** Stripping noise suffixes must never mutate the `raw` original filename. */
    public function testNormalizeNoiseStrippingPreservesRaw(): void
    {
        $original = 'Blade Runner Directors Cut';
        $result = SceneFilenameNormalizer::normalize($original);

        $this->assertSame($original, $result['raw']);
    }

    /** Stacked trailing edition suffixes are peeled iteratively. */
    public function testNormalizeStripsStackedNoiseSuffixes(): void
    {
        $result = SceneFilenameNormalizer::normalize('Highlander Remastered Directors Cut');

        $this->assertSame('Highlander', $result['title']);
        $this->assertNull($result['year']);
    }

    // --- Step 13.1 (TestEngineer edge cases) --------------------------------

    /**
     * Three+ stacked trailing edition suffixes are all peeled in one normalize pass.
     */
    public function testNormalizeStripsThreeStackedNoiseSuffixes(): void
    {
        $result = SceneFilenameNormalizer::normalize('Highlander Uncut Remastered Directors Cut');

        $this->assertSame('Highlander', $result['title']);
        $this->assertNull($result['year']);
    }

    /**
     * Noise-suffix matching is case-insensitive regardless of input casing.
     *
     * @dataProvider provideCaseVariantSuffixes
     */
    public function testNormalizeStripsNoiseSuffixCaseInsensitively(string $filename): void
    {
        $result = SceneFilenameNormalizer::normalize($filename);

        $this->assertSame('Blade Runner', $result['title'], "for input: {$filename}");
        $this->assertNull($result['year']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideCaseVariantSuffixes(): array
    {
        return [
            'lowercase'   => ['Blade Runner directors cut'],
            'uppercase'   => ['Blade Runner DIRECTORS CUT'],
            'titlecase'   => ['Blade Runner Directors Cut'],
            'mixed-case'  => ['Blade Runner DiReCtOrS cUt'],
        ];
    }

    /**
     * The separator between title and noise suffix may be a space, dash, dot, or
     * underscore (the ` -._` set the regex peels) — all variants are stripped.
     *
     * Uses "Directors Cut" (a pure NOISE_SUFFIX phrase, NOT a QUALITY_TOKEN) so the
     * assertion isolates the Step 13.1 end-anchored peel from the pre-existing
     * token-level quality stripping in the no-year branch.
     *
     * @dataProvider provideSeparatorVariants
     */
    public function testNormalizeStripsNoiseSuffixAcrossSeparatorVariants(string $filename): void
    {
        $result = SceneFilenameNormalizer::normalize($filename);

        $this->assertSame('Highlander', $result['title'], "for input: {$filename}");
        $this->assertNull($result['year']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideSeparatorVariants(): array
    {
        return [
            // Dotted scene style: "Highlander.Directors.Cut".
            'dot-separated'        => ['Highlander.Directors.Cut'],
            // Underscore-delimited release name.
            'underscore-separated' => ['Highlander_Directors_Cut'],
            // Dash-attached edition tag.
            'dash-separated'       => ['Highlander - Directors Cut'],
            // Plain space.
            'space-separated'      => ['Highlander Directors Cut'],
        ];
    }

    /**
     * A noise suffix that follows a bracketed (YYYY) year is peeled off while the
     * bracketed year is still correctly extracted.
     */
    public function testNormalizeStripsNoiseSuffixAfterBracketedYear(): void
    {
        $result = SceneFilenameNormalizer::normalize('Blade Runner Directors Cut (1982)');

        $this->assertSame('Blade Runner', $result['title']);
        $this->assertSame(1982, $result['year']);
    }

    /**
     * An "Extended" edition tag appearing before a bracketed (YYYY) is also peeled,
     * exercising the bracketed-year branch's stripNoiseSuffixes() call.
     */
    public function testNormalizeStripsExtendedBeforeBracketedYear(): void
    {
        $result = SceneFilenameNormalizer::normalize('Aliens Extended (1986)');

        $this->assertSame('Aliens', $result['title']);
        $this->assertSame(1986, $result['year']);
    }

    /**
     * A legitimate title that merely CONTAINS a noise word mid-string (not at the
     * trailing edge) must NOT be stripped — only end-anchored matches are peeled.
     *
     * @dataProvider provideMidStringNoiseWordTitles
     */
    public function testNormalizeDoesNotStripMidStringNoiseWord(string $filename, string $expectedTitle): void
    {
        $result = SceneFilenameNormalizer::normalize($filename);

        $this->assertSame($expectedTitle, $result['title'], "for input: {$filename}");
    }

    /**
     * Each input uses a noise word that is NOT also a QUALITY_TOKEN (so the
     * pre-existing token-level quality stripping cannot remove it), proving the
     * Step 13.1 end-anchored peel leaves mid-string occurrences untouched.
     *
     * @return array<string, array{string, string}>
     */
    public static function provideMidStringNoiseWordTitles(): array
    {
        return [
            // "Uncut" as a leading title word followed by more title.
            'uncut-leading'   => ['Uncut Gems', 'Uncut Gems'],
            // "Directors Cut" phrase mid-string, with a real word after it.
            'directors-mid'   => ['The Directors Cut Saga', 'The Directors Cut Saga'],
            // "Cut" embedded in a longer non-edition word must not partial-match.
            'cut-substring'   => ['The Cutting Edge', 'The Cutting Edge'],
            // "DC" as a substring of a larger word must not strip.
            'dc-substring'    => ['Abducted', 'Abducted'],
            // "Uncut" embedded mid-word must not partial-match.
            'uncut-substring' => ['Uncuttable Bonds', 'Uncuttable Bonds'],
        ];
    }

    /**
     * A mid-string noise word does not block stripping a genuine trailing one:
     * "Uncut Gems Directors Cut" keeps "Uncut Gems" but drops the trailing edition.
     */
    public function testNormalizeStripsTrailingButKeepsMidStringNoiseWord(): void
    {
        $result = SceneFilenameNormalizer::normalize('Uncut Gems Directors Cut');

        $this->assertSame('Uncut Gems', $result['title']);
        $this->assertNull($result['year']);
    }

    /**
     * A title whose final word legitimately ends with letters that are NOT a
     * word-boundary match for a noise token (e.g. "...Extendedness") is preserved,
     * confirming the `\b` word-boundary anchor.
     */
    public function testNormalizeRespectsWordBoundaryOnTrailingToken(): void
    {
        $result = SceneFilenameNormalizer::normalize('The Great Uncutting');

        $this->assertSame('The Great Uncutting', $result['title']);
    }

    /**
     * Step 13.3: an injected (admin-extended) noise list strips a CUSTOM phrase
     * that is not in the built-in const, end-to-end through normalize().
     */
    public function testNormalizeStripsInjectedCustomSuffix(): void
    {
        $custom = ['imax edition', 'remux'];
        $result = SceneFilenameNormalizer::normalize('Interstellar IMAX Edition', $custom);

        $this->assertSame('Interstellar', $result['title']);
    }

    /**
     * Step 13.3: a CUSTOM suffix peels even after the bracketed-(YYYY) branch,
     * confirming both strip() call sites thread the injected list.
     */
    public function testNormalizeStripsInjectedCustomSuffixWithBracketedYear(): void
    {
        $custom = ['imax edition'];
        $result = SceneFilenameNormalizer::normalize('Interstellar IMAX Edition (2014)', $custom);

        $this->assertSame('Interstellar', $result['title']);
        $this->assertSame(2014, $result['year']);
    }

    /**
     * Step 13.3: a null/empty injected list falls back to the built-in const, so
     * the canonical phrases still strip (an empty override never blanks them).
     */
    public function testNormalizeEmptyInjectedListFallsBackToConst(): void
    {
        $this->assertSame('Blade Runner', SceneFilenameNormalizer::normalize('Blade Runner Directors Cut', null)['title']);
        $this->assertSame('Blade Runner', SceneFilenameNormalizer::normalize('Blade Runner Directors Cut', [])['title']);
    }

    // --- SM-0.1: dangling bracket repair (plan_scanner_matching.md T3) -------

    /**
     * The pinned repro from the plan: the single-year branch slices `$cleaned` at
     * the year's byte offset, cutting INSIDE `(2010 - 720p BluRay)` and leaving an
     * orphan `(` that `stripBracketedTags()` (which needs a closing paren) cannot
     * remove. Before SM-0.1 this returned `1a Gantz (`.
     */
    public function testNormalizeDropsOrphanParenLeftByYearSlice(): void
    {
        $result = SceneFilenameNormalizer::normalize('1a. Gantz (2010 - 720p BluRay)');

        $this->assertSame('1a Gantz', $result['title']);
        $this->assertSame(2010, $result['year']);
    }

    /** recon_live fixture: `Gantz O (2016 DUAL Audio - 720p BluRay)` → `Gantz O`. */
    public function testNormalizeGantzOReconFixture(): void
    {
        $result = SceneFilenameNormalizer::normalize('Gantz O (2016 DUAL Audio - 720p BluRay)');

        $this->assertSame('Gantz O', $result['title']);
        $this->assertSame(2016, $result['year']);
    }

    /**
     * recon_live fixture: `Repo Men (2010) Unrated 1080p BrRip x264 YIFY` → `Repo Men`.
     * The year sits inside a BALANCED `(2010)` group, but the slice still cuts
     * between the `(` and the `)`, orphaning the opener.
     */
    public function testNormalizeRepoMenReconFixture(): void
    {
        $result = SceneFilenameNormalizer::normalize('Repo Men (2010) Unrated 1080p BrRip x264 YIFY');

        $this->assertSame('Repo Men', $result['title']);
        $this->assertSame(2010, $result['year']);
    }

    /**
     * recon_live music fixture. The two paren groups are BALANCED, so the repair
     * must be a no-op here and the pre-existing `stripBracketedTags()` decision
     * (strip both groups) must survive unchanged — while the title still never
     * ends in an orphan opener.
     */
    public function testNormalizeKeepsBalancedParenDecisionForMusicTrack(): void
    {
        $result = SceneFilenameNormalizer::normalize("01 Nas - Life's a Bitch (Arsenal mix) (feat. AZ)");

        $this->assertSame("01 Nas - Life's a Bitch", $result['title']);
        $this->assertNull($result['year']);
    }

    /** The same orphan-after-slice bug for `[` … `]`. */
    public function testNormalizeDropsOrphanSquareBracketLeftByYearSlice(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie [2010 - 720p BluRay]');

        $this->assertSame('Movie', $result['title']);
        $this->assertSame(2010, $result['year']);
    }

    /** The same orphan-after-slice bug for the fullwidth `【` … `】` pair. */
    public function testNormalizeDropsOrphanFullwidthBracketLeftByYearSlice(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie 【2010 - 720p BluRay】');

        $this->assertSame('Movie', $result['title']);
        $this->assertSame(2010, $result['year']);
    }

    /** The symmetric leading-orphan case: an unmatched CLOSER is dropped too. */
    public function testNormalizeDropsOrphanClosingParen(): void
    {
        $result = SceneFilenameNormalizer::normalize('Foo) Bar 2010 1080p BluRay');

        $this->assertSame('Foo Bar', $result['title']);
        $this->assertSame(2010, $result['year']);
    }

    /** An orphan opener with no year at all is still repaired. */
    public function testNormalizeDropsOrphanParenWithoutYear(): void
    {
        $result = SceneFilenameNormalizer::normalize('Some Movie (');

        $this->assertSame('Some Movie', $result['title']);
        $this->assertNull($result['year']);
    }

    /**
     * A balanced group that precedes the orphan is still handed to
     * `stripBracketedTags()` intact — only the orphan is removed.
     */
    public function testNormalizeStripsBalancedGroupAndDropsOrphan(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie (Special Edition) (2010 - 1080p)');

        $this->assertSame('Movie', $result['title']);
        $this->assertSame(2010, $result['year']);
    }

    /**
     * Cross-nested brackets: `stripBracketedTags()` removes `[y) z]` first, which
     * takes the `)` that balanced the `(` with it. The repair must therefore also
     * run AFTER the tag strip, or the output ends in `(`.
     */
    public function testNormalizeRepairsImbalanceCreatedByTagStripping(): void
    {
        $result = SceneFilenameNormalizer::normalize('Alpha (beta [gamma) delta] omega 2010 1080p');

        $this->assertSame('Alpha beta omega', $result['title']);
        $this->assertSame(2010, $result['year']);
    }

    /**
     * The bracketed-(YYYY) branch has its OWN empty-title fallback, and it must
     * repair that fallback exactly like the tail fallback does.
     *
     * `( [2010]` parses as title-part `(`, which the repair empties, so the branch
     * falls back to `$cleaned` — and returning `$cleaned` untouched would store a
     * title containing the very dangling `(` this step exists to remove. This is
     * the only path in `normalize()` that could still emit unbalanced brackets.
     *
     * @dataProvider provideBracketedYearFallbackFilenames
     */
    public function testNormalizeRepairsBracketedYearBranchFallback(
        string $filename,
        string $expected,
        int $year
    ): void {
        $result = SceneFilenameNormalizer::normalize($filename);

        $this->assertSame($expected, $result['title'], "for input: {$filename}");
        $this->assertSame($year, $result['year'], "for input: {$filename}");
        $this->assertBracketsBalanced($result['title'], $filename);
    }

    /**
     * Every case here reaches the bracketed-(YYYY) branch AND empties its title,
     * which is the only way to hit that branch's fallback.
     *
     * @return array<string, array{string, string, int}>
     */
    public static function provideBracketedYearFallbackFilenames(): array
    {
        return [
            // title-part is a bare "(" -> repaired to "" -> falls back to $cleaned.
            'orphan opener before bracketed year' => ['( [2010]', '[2010]', 2010],
            // title-part "[1080p] ]" -> strip leaves "]" -> repaired to "".
            'orphan closer before bracketed year' => ['[1080p] ] (2010)', '[1080p] (2010)', 2010],
            // title-part "[" -> repaired to "" -> falls back to "[[2010)".
            'double opener before bracketed year' => ['[[2010)', '2010', 2010],
            // title-part "【" -> the fullwidth pair takes the same route.
            'fullwidth opener before year'        => ['【 (2010)', '(2010)', 2010],
        ];
    }

    /**
     * Repairing brackets must happen strictly AFTER `stripBracketedTags()`, never
     * before it.
     *
     * Both inputs are real production basenames. Dropping the orphan `[` FIRST
     * shortens the reach of the square-bracket strip pattern (which spans from a
     * `[` to the next `]`): it can then only start at a LATER opener, so the
     * release-tag fragment that sat between the orphan and that closer survives as
     * bare title text. Repairing only on the way out leaves the junk inside a
     * group the strip swallows whole.
     *
     * @dataProvider provideOrphanHeadedReleaseTagRuns
     */
    public function testNormalizeDoesNotPromoteReleaseTagJunkIntoTitle(string $filename, string $expected): void
    {
        $result = SceneFilenameNormalizer::normalize($filename);

        $this->assertSame($expected, $result['title'], "for input: {$filename}");
        $this->assertBracketsBalanced($result['title'], $filename);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideOrphanHeadedReleaseTagRuns(): array
    {
        return [
            // Real prod basename (no-year branch).
            'prod pokemon 0942' => [
                'Pokémon - 0942 - Alola to New Adventure! [DisneyXD Webri [1080p] [x265] [pseudo]',
                'Pokémon - - Alola to New Adventure!',
            ],
            // Real prod basename (no-year branch), shorter orphan run.
            'prod pokemon 0001' => [
                'Pokémon - 0001 - Pokémon, I Choose You [Di [480p] [x265] [pseudo]',
                'Pokémon - - Pokémon, I Choose You',
            ],
            // Same shape on the single-year branch, so the tail path is pinned.
            'orphan run before year' => [
                'Show Name [Group Webrip [1080p] [x265] 2010',
                'Show Name',
            ],
            // ...and on the bracketed-(YYYY) branch, which has its own strip call.
            'orphan run before bracketed year' => [
                'Show Name [Group Webrip [1080p] [x265] (2010)',
                'Show Name',
            ],
        ];
    }

    /**
     * The global invariant: `normalize()` never returns a title that ends in an
     * unbalanced opener, and never returns a title whose brackets are unbalanced.
     *
     * @dataProvider provideBracketSoupFilenames
     */
    public function testNormalizeNeverEmitsUnbalancedBrackets(string $filename): void
    {
        $title = SceneFilenameNormalizer::normalize($filename)['title'];

        foreach (['(', '[', '【'] as $opener) {
            $this->assertFalse(
                str_ends_with($title, $opener),
                "title '{$title}' ends in unbalanced opener '{$opener}' for input: {$filename}"
            );
        }

        $this->assertBracketsBalanced($title, $filename);
    }

    /**
     * Real recon fixtures plus deliberately pathological bracket soup. Every one
     * must come out of `normalize()` with balanced brackets.
     *
     * @return array<string, array{string}>
     */
    public static function provideBracketSoupFilenames(): array
    {
        return [
            'recon gantz numbered'   => ['1a. Gantz (2010 - 720p BluRay)'],
            'recon gantz o'          => ['Gantz O (2016 DUAL Audio - 720p BluRay)'],
            'recon repo men'         => ['Repo Men (2010) Unrated 1080p BrRip x264 YIFY'],
            'recon nas track'        => ["01 Nas - Life's a Bitch (Arsenal mix) (feat. AZ)"],
            'square orphan'          => ['Movie [2010 - 720p BluRay]'],
            'fullwidth orphan'       => ['Movie 【2010 - 720p BluRay】'],
            'leading closer'         => ['Foo) Bar 2010 1080p BluRay'],
            'leading closer no year' => ['Foo] Bar'],
            'orphan only'            => ['('],
            'orphan only closer'     => [')'],
            'year first in parens'   => ['(2010) Movie'],
            'unterminated open'      => ['Some Movie (2011'],
            'unopened close'         => ['Movie 2010) tail'],
            'cross nested'           => ['Alpha (beta [gamma) delta] omega 2010 1080p'],
            'nested balanced'        => ['Alpha (beta (gamma) delta) 2010 1080p'],
            'inner orphan'           => ['Alpha (beta (gamma delta) 2010 1080p'],
            // Unbalanced outer + BALANCED inner: outermost-wins re-cuts the
            // groups (the inner "(gamma)" does not survive as such), which must
            // still leave the emitted title balanced. See the exact-string pins
            // in provideBracketBalanceRepairs().
            'balanced inner orphan'  => ['Alpha (beta (gamma) delta 2010 1080p'],
            'bracket storm'          => ['[[[Movie]] (2010 (('],
            'fullwidth mixed'        => ['Movie 【2010 (720p】 BluRay'],
            'balanced no year'       => ['(Instrumental)'],
            'empty'                  => [''],
            'all quality plus paren' => ['1080p ('],
            // These four reach the bracketed-(YYYY) branch's own fallback, which
            // used to return $cleaned unrepaired — the only path that could still
            // emit an unbalanced title, and previously uncovered by this provider.
            'fallback orphan opener' => ['( [2010]'],
            'fallback orphan closer' => ['[1080p] ] (2010)'],
            'fallback double opener' => ['[[2010)'],
            'fallback fullwidth'     => ['【 (2010)'],
            // Real prod basenames whose orphan "[" heads a release-tag run.
            'prod orphan tag run'    => ['Pokémon - 0942 - Alola to New Adventure! [DisneyXD Webri [1080p] [x265] [pseudo]'],
            'prod orphan tag short'  => ['Pokémon - 0001 - Pokémon, I Choose You [Di [480p] [x265] [pseudo]'],
        ];
    }

    /**
     * The invariant as a CLASS guarantee rather than a sample: exhaustively
     * enumerate every bracket sequence up to length 3 over all three pairs and
     * push each through a set of realistic filename templates. ~2k inputs, and
     * every one must come back with balanced brackets.
     *
     * A per-case assertion would add thousands of assertions for no signal, so
     * violations are collected and asserted once — a non-empty result names every
     * offending input.
     */
    public function testNormalizeKeepsBracketsBalancedAcrossExhaustiveBracketSoup(): void
    {
        $alphabet = ['(', ')', '[', ']', '【', '】'];

        $sequences = [''];
        $frontier = [''];
        for ($length = 0; $length < 3; $length++) {
            $next = [];
            foreach ($frontier as $prefix) {
                foreach ($alphabet as $char) {
                    $next[] = $prefix . $char;
                }
            }
            $sequences = array_merge($sequences, $next);
            $frontier = $next;
        }

        $templates = [
            '%s',
            'Movie %s',
            '%s Movie',
            'Movie %s 2010',
            'Movie 2010 %s',
            'Movie %s 2010 1080p BluRay',
            'Movie %s (2010)',
            'Movie %s [2010]',
            '%s (2010)',
            '%s [2010]',
            'Movie %s 2010 %s 1999',
        ];

        $violations = [];
        foreach ($sequences as $sequence) {
            foreach ($templates as $template) {
                $filename = str_replace('%s', $sequence, $template);
                $title = SceneFilenameNormalizer::normalize($filename)['title'];
                if (!$this->bracketsAreBalanced($title)) {
                    $violations[$filename] = $title;
                }
            }
        }

        $this->assertSame([], $violations, 'normalize() emitted unbalanced titles');
    }

    /**
     * Asserts that every bracket in `$title` is matched: no closer appears before
     * its opener and no opener is left unclosed.
     */
    private function assertBracketsBalanced(string $title, string $input): void
    {
        $pairs = ['(' => ')', '[' => ']', '【' => '】'];
        $closers = [')' => '(', ']' => '[', '】' => '【'];

        $chars = preg_split('//u', $title, -1, PREG_SPLIT_NO_EMPTY);
        $this->assertIsArray($chars, "title '{$title}' is not valid UTF-8 for input: {$input}");

        $depth = ['(' => 0, '[' => 0, '【' => 0];
        foreach ($chars as $char) {
            if (isset($pairs[$char])) {
                $depth[$char]++;
                continue;
            }
            if (!isset($closers[$char])) {
                continue;
            }
            $opener = $closers[$char];
            $this->assertGreaterThan(
                0,
                $depth[$opener],
                "title '{$title}' has a '{$char}' with no opener for input: {$input}"
            );
            $depth[$opener]--;
        }

        foreach ($depth as $opener => $remaining) {
            $this->assertSame(
                0,
                $remaining,
                "title '{$title}' has {$remaining} unclosed '{$opener}' for input: {$input}"
            );
        }
    }

    /**
     * Boolean twin of {@see assertBracketsBalanced()} for loop-driven checks that
     * must not emit one assertion per case. Deliberately an independent second
     * implementation of the same invariant: if the two ever disagree, the
     * exhaustive test and the data-driven test fail against each other.
     */
    private function bracketsAreBalanced(string $title): bool
    {
        $pairs = ['(' => ')', '[' => ']', '【' => '】'];
        $closers = [')' => '(', ']' => '[', '】' => '【'];

        $chars = preg_split('//u', $title, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return false;
        }

        $depth = ['(' => 0, '[' => 0, '【' => 0];
        foreach ($chars as $char) {
            if (isset($pairs[$char])) {
                $depth[$char]++;
                continue;
            }
            if (!isset($closers[$char])) {
                continue;
            }
            $opener = $closers[$char];
            if ($depth[$opener] === 0) {
                return false;
            }
            $depth[$opener]--;
        }

        return $depth['('] === 0 && $depth['['] === 0 && $depth['【'] === 0;
    }

    // --- SM-0.1: the private repairBracketBalance() helper itself ------------

    /**
     * Direct unit coverage of the private helper: it removes ONLY unmatched
     * bracket characters and leaves every matched pair (including nested pairs)
     * in place for `stripBracketedTags()` to handle.
     *
     * @dataProvider provideBracketBalanceRepairs
     */
    public function testRepairBracketBalanceDropsOnlyUnmatchedBrackets(string $input, string $expected): void
    {
        $method = new \ReflectionMethod(SceneFilenameNormalizer::class, 'repairBracketBalance');

        $this->assertSame($expected, $method->invoke(null, $input), "for input: {$input}");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideBracketBalanceRepairs(): array
    {
        return [
            'trailing orphan paren'        => ['Gantz O (', 'Gantz O'],
            'trailing orphan bracket'      => ['Movie [', 'Movie'],
            'trailing orphan fullwidth'    => ['Movie 【', 'Movie'],
            'leading orphan closer'        => ['Foo) Bar', 'Foo Bar'],
            'leading orphan bracket close' => ['Foo] Bar', 'Foo Bar'],
            'leading orphan fullwidth'     => ['Foo】 Bar', 'Foo Bar'],
            'balanced parens preserved'    => ["Life's a Bitch (Arsenal mix)", "Life's a Bitch (Arsenal mix)"],
            'balanced brackets preserved'  => ['Movie [YTS MX]', 'Movie [YTS MX]'],
            'balanced fullwidth preserved' => ['Movie 【YTS MX】', 'Movie 【YTS MX】'],
            'nested balanced preserved'    => ['A (b (c) d)', 'A (b (c) d)'],
            // Outermost-wins: the closer settles the EARLIEST open "(", so the
            // surplus INNER opener is the one dropped. This keeps the run of text
            // after the orphan inside a group that stripBracketedTags() can then
            // swallow, instead of promoting release-tag junk into the title.
            'outer pair wins'              => ['A (b (c d)', 'A (b c d)'],
            'outer pair wins three deep'   => ['A (b (c (d e)', 'A (b c d e)'],
            // The flip side of outermost-wins, pinned so a change to it is a
            // visible test edit: once the title as a whole is unbalanced, an
            // individual balanced group is NOT preserved. The closer settles the
            // EARLIEST open opener, so the balanced inner "(c)" is destroyed and
            // a wider "(b c)" is synthesised. Intended (see the helper docblock)
            // — it keeps the run after the orphan opener inside a group that
            // stripBracketedTags() can swallow. LIFO would have given 'A b (c) d'.
            'unbalanced outer breaks balanced inner'        => ['A (b (c) d', 'A (b c) d'],
            'unbalanced outer breaks balanced inner square' => ['A [b [c] d', 'A [b c] d'],
            'unbalanced outer breaks balanced inner wide'   => ['A (b (c) d (e) f', 'A (b (c) d e) f'],
            'no brackets untouched'        => ['Plain Title', 'Plain Title'],
            'whitespace kept when no drop' => ['  Plain  Title  ', '  Plain  Title  '],
            'whitespace collapsed on drop' => ['Foo ( Bar', 'Foo Bar'],
            'orphan only'                  => ['(', ''],
            'empty string'                 => ['', ''],
            'types are independent'        => ['A (b] c', 'A b c'],
            'balanced across types'        => ['A (b) [c] 【d】', 'A (b) [c] 【d】'],
            // --- NOT valid UTF-8: preg_split('//u') returns false and the scan
            // falls back to bytes. 0x9C is a lone Windows-1252 byte, the exact
            // shape MediaScanner.php:1367-1371 documents as reaching the parser.
            // The fullwidth pair (E3 80 90 / E3 80 91) must still be recognised.
            'non-utf8 fullwidth orphan'    => ["Bad\x9C 【 tail", "Bad\x9C tail"],
            'non-utf8 fullwidth closer'    => ["Bad\x9C 】 tail", "Bad\x9C tail"],
            'non-utf8 fullwidth balanced'  => ["Bad\x9C 【x】", "Bad\x9C 【x】"],
            'non-utf8 ascii orphan'        => ["Bad\x9C (", "Bad\x9C"],
            'non-utf8 mixed orphans'       => ["Bad\x9C 【 (a) [", "Bad\x9C (a)"],
        ];
    }

    /**
     * A filename carrying a stray non-UTF-8 byte still gets its fullwidth orphan
     * repaired end-to-end.
     *
     * This path is live, not theoretical: `MediaScanner.php:1367-1371` documents
     * scene filenames carrying stray bytes (e.g. a Windows-1252 0x9C), and its
     * `toValidUtf8()` coercion runs only AFTER `parseNaming()` — i.e. after
     * `normalize()` has already seen the raw bytes. `preg_split('//u')` fails on
     * them, so the repair scans bytes, where `【` is the three-byte sequence
     * E3 80 90 and would be invisible to a naive per-byte split.
     */
    public function testNormalizeDropsFullwidthOrphanOnNonUtf8Filename(): void
    {
        $result = SceneFilenameNormalizer::normalize("Movie \x9C 【2010 - 720p BluRay】");

        $this->assertStringNotContainsString('【', $result['title']);
        $this->assertSame("Movie \x9C", $result['title']);
        $this->assertSame(2010, $result['year']);
    }

    /** The ASCII pairs keep working on the same non-UTF-8 path. */
    public function testNormalizeDropsAsciiOrphanOnNonUtf8Filename(): void
    {
        $paren = SceneFilenameNormalizer::normalize("Movie \x9C (2010 - 720p BluRay)");
        $this->assertSame("Movie \x9C", $paren['title']);
        $this->assertSame(2010, $paren['year']);

        $square = SceneFilenameNormalizer::normalize("Movie \x9C [2010 - 720p BluRay]");
        $this->assertSame("Movie \x9C", $square['title']);
        $this->assertSame(2010, $square['year']);
    }

    // --- SM-0.1: the ONE documented limit of the balance guarantee -----------

    /**
     * KNOWN LIMIT, pinned so it stays *known* rather than lurking:
     * `TitleSuffixStripper::strip()` runs AFTER the last `repairBracketBalance()`
     * pass, so an admin-authored `matching.noise_suffixes` entry whose TEXT
     * contains a bracket can peel the closing half of a group back off and
     * re-unbalance the title.
     *
     * Pre-existing shape — the strip/repair ordering is not changed by SM-0.1 —
     * and deliberately NOT closed with a further repair pass; see the KNOWN LIMIT
     * note on `SceneFilenameNormalizer::normalize()`. Reaching it requires a
     * bracket-bearing admin override; the shipped default list is bracket-free
     * (`testShippedNoiseSuffixListIsBracketFree()`).
     *
     * This test DOCUMENTS the behaviour, it does not endorse it: if a later
     * change adds a repair after the strip, this test should fail and be updated
     * — that visible edit is the point.
     */
    public function testBracketBearingNoiseSuffixCanReUnbalanceTitle(): void
    {
        // Control — with the shipped (bracket-free) list the title comes out
        // balanced. `Movie 【a]b】` survives stripBracketedTags() at all because
        // that method's fullwidth pattern negates the ASCII `]`, not `】`; the
        // repair pass then drops the stray `]`.
        $shipped = SceneFilenameNormalizer::normalize('Movie 【a]b】');
        $this->assertSame('Movie 【ab】', $shipped['title']);
        $this->assertNull($shipped['year']);

        // The limit itself: a bracket-BEARING override peels `ab】` off after the
        // last repair, leaving a dangling `【`.
        $override = array_merge(['ab】'], TitleSuffixStripper::NOISE_SUFFIXES);
        $overridden = SceneFilenameNormalizer::normalize('Movie 【a]b】', $override);
        $this->assertSame('Movie 【', $overridden['title']);

        // Same limit on the bracketed-(YYYY) branch's own strip call site.
        $withYear = SceneFilenameNormalizer::normalize('Movie 【a]b】 (2010)', $override);
        $this->assertSame('Movie 【', $withYear['title']);
        $this->assertSame(2010, $withYear['year']);
    }

    /**
     * The precondition that keeps the limit above unreachable in practice: no
     * shipped noise suffix carries a bracket character. `config/matching.php` is
     * the admin-facing default and must mirror the const exactly, so both are
     * checked — a bracket added to either would silently void the balance
     * guarantee.
     */
    public function testShippedNoiseSuffixListIsBracketFree(): void
    {
        $brackets = ['(', ')', '[', ']', '【', '】'];

        /** @var array{noise_suffixes: list<string>} $config */
        $config = require \dirname(__DIR__, 4) . '/config/matching.php';

        $this->assertSame(
            TitleSuffixStripper::NOISE_SUFFIXES,
            $config['noise_suffixes'],
            'config/matching.php must mirror TitleSuffixStripper::NOISE_SUFFIXES'
        );

        foreach ($config['noise_suffixes'] as $suffix) {
            foreach ($brackets as $bracket) {
                $this->assertStringNotContainsString(
                    $bracket,
                    $suffix,
                    "shipped noise suffix '{$suffix}' must not contain '{$bracket}': a bracket-bearing "
                    . 'suffix peels after the last repairBracketBalance() pass and can re-unbalance the title'
                );
            }
        }
    }

    /**
     * SM-0.2 widened {@see SceneFilenameNormalizer::stripBracketedTags()} from
     * private to public so
     * {@see \Phlix\Media\Library\EpisodeFilenameParser::extractEpisodeTitle()}
     * can reuse the exact same three patterns instead of growing a second copy
     * that would drift (the fullwidth `【…】` pair is the easy one to forget).
     * Narrowing it again breaks that caller, so the visibility is pinned here.
     *
     * @dataProvider bracketedTagCases
     */
    public function testStripBracketedTagsIsPublicAndKeepsTheSurroundingText(
        string $input,
        string $expected
    ): void {
        $this->assertTrue(
            (new \ReflectionMethod(SceneFilenameNormalizer::class, 'stripBracketedTags'))->isPublic(),
            'stripBracketedTags() is part of the API EpisodeFilenameParser depends on'
        );
        $this->assertSame($expected, SceneFilenameNormalizer::stripBracketedTags($input));
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function bracketedTagCases(): array
    {
        return [
            'leading tag'   => ['[480p] Let It Be Me', 'Let It Be Me'],
            'trailing tag'  => ['Let It Be Me [480p]', 'Let It Be Me'],
            'both sides'    => ['[480p] Let It Be Me [x265]', 'Let It Be Me'],
            'parens'        => ['Q and A (720p - AMZN Web-DL)', 'Q and A'],
            'fullwidth'     => ["\u{3010}720p\u{3011} Real Title", 'Real Title'],
            'only tags'     => ['[720p] [x265]', ''],
            'no tag at all' => ['Plain Title', 'Plain Title'],
        ];
    }

    /**
     * SM-0.2 (reviewer finding F2) widened
     * {@see SceneFilenameNormalizer::repairBracketBalance()} from private to
     * public for the same reason as stripBracketedTags(): it must run
     * IMMEDIATELY AFTER that method at every call site, and
     * {@see \Phlix\Media\Library\EpisodeFilenameParser::extractEpisodeTitle()}
     * is one of them. While it was private the episode path was the only
     * consumer of stripBracketedTags() without the balance guarantee, and an
     * orphan opener went straight into `media_items.name` ("Title [720p").
     *
     * @dataProvider orphanBracketRepairCases
     */
    public function testRepairBracketBalanceIsPublicAndDropsOrphans(
        string $input,
        string $expected
    ): void {
        $this->assertTrue(
            (new \ReflectionMethod(SceneFilenameNormalizer::class, 'repairBracketBalance'))->isPublic(),
            'repairBracketBalance() is part of the API EpisodeFilenameParser depends on'
        );
        $this->assertSame($expected, SceneFilenameNormalizer::repairBracketBalance($input));
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function orphanBracketRepairCases(): array
    {
        return [
            'orphan opener'    => ['Title [720p', 'Title 720p'],
            'orphan paren'     => ['Title (1080p', 'Title 1080p'],
            'orphan closer'    => ['Foo) Bar', 'Foo Bar'],
            'fullwidth orphan' => ["Title \u{3010}720p", 'Title 720p'],
            'balanced is kept' => ['Title [720p] Rest', 'Title [720p] Rest'],
            'no bracket'       => ['Plain Title', 'Plain Title'],
        ];
    }
}
