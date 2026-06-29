<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\SceneFilenameNormalizer;

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
}
