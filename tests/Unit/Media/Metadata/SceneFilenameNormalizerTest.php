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
        $result = SceneFilenameNormalizer::normalize('Movie.Title.2012.2024.REMASTERED.1080p.BluRay.mkv');

        $this->assertSame('Movie Title', $result['title']);
        $this->assertSame(2012, $result['year']);
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

    public function testNormalizeSingleYearOnlyReturnsEmptyTitle(): void
    {
        $result = SceneFilenameNormalizer::normalize('2022.mp4');

        $this->assertSame('', $result['title']);
        $this->assertSame(2022, $result['year']);
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
        $result = SceneFilenameNormalizer::normalize('Movie.2022.720p.BluRay.CD1.mkv');
        $this->assertSame('Movie CD1', $result['title']);

        $result2 = SceneFilenameNormalizer::normalize('Movie.2022.720p.BluRay.CD2.mkv');
        $this->assertSame('Movie CD2', $result2['title']);
    }

    public function testNormalizeHandlesPartSuffix(): void
    {
        $result = SceneFilenameNormalizer::normalize('Movie.2022.720p.BluRay.Part1.mkv');
        $this->assertSame('Movie Part1', $result['title']);
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
}
