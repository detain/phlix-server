<?php

namespace Phlix\Tests\Unit\LiveTv;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\ComskipEdlParser;
use Phlix\Media\Markers\ChapterMarker;

/**
 * @since 0.12.0
 */
class ComskipEdlParserTest extends TestCase
{
    public function testParseReturnsChapterMarkers(): void
    {
        $parser = new ComskipEdlParser(30);
        $edlContent = "0.0\t30.0\t3\n60.0\t90.0\t3\n";

        $chapters = $parser->parseString($edlContent);

        $this->assertCount(2, $chapters);
        $this->assertInstanceOf(ChapterMarker::class, $chapters[0]);
        $this->assertInstanceOf(ChapterMarker::class, $chapters[1]);
        $this->assertEquals(0, $chapters[0]->start_seconds);
        $this->assertEquals(30, $chapters[0]->end_seconds);
        $this->assertEquals(60, $chapters[1]->start_seconds);
        $this->assertEquals(90, $chapters[1]->end_seconds);
    }

    public function testParseIgnoresShortSegments(): void
    {
        $parser = new ComskipEdlParser(30); // min length 30 seconds
        $edlContent = "0.0\t15.0\t3\n"; // Only 15 seconds - should be ignored

        $chapters = $parser->parseString($edlContent);

        $this->assertCount(0, $chapters);
    }

    public function testParseIncludesSegmentsAtExactMinLength(): void
    {
        $parser = new ComskipEdlParser(30); // min length 30 seconds
        $edlContent = "0.0\t30.0\t3\n"; // Exactly 30 seconds - should be included

        $chapters = $parser->parseString($edlContent);

        $this->assertCount(1, $chapters);
    }

    public function testParseStringFromRawContent(): void
    {
        $parser = new ComskipEdlParser(30);
        $edlContent = "0.0\t45.0\t3\n60.0\t120.0\t3\n180.0\t240.0\t0\n";

        $chapters = $parser->parseString($edlContent);

        $this->assertCount(3, $chapters);
        $this->assertEquals(0, $chapters[0]->start_seconds);
        $this->assertEquals(45, $chapters[0]->end_seconds);
        $this->assertEquals(60, $chapters[1]->start_seconds);
        $this->assertEquals(120, $chapters[1]->end_seconds);
        $this->assertEquals(180, $chapters[2]->start_seconds);
        $this->assertEquals(240, $chapters[2]->end_seconds);
    }

    public function testParseIgnoresInvalidRanges(): void
    {
        $parser = new ComskipEdlParser(30);
        $edlContent = "60.0\t30.0\t3\n"; // End before start - should be ignored

        $chapters = $parser->parseString($edlContent);

        $this->assertCount(0, $chapters);
    }

    public function testParseIgnoresEmptyLines(): void
    {
        $parser = new ComskipEdlParser(30);
        $edlContent = "0.0\t45.0\t3\n\n60.0\t90.0\t3\n\n";

        $chapters = $parser->parseString($edlContent);

        $this->assertCount(2, $chapters);
    }

    public function testParseIgnoresMalformedLines(): void
    {
        $parser = new ComskipEdlParser(30);
        $edlContent = "0.0\t45.0\t3\nnot_a_valid_line\n60.0\t90.0\t3\n";

        $chapters = $parser->parseString($edlContent);

        $this->assertCount(2, $chapters);
    }

    public function testParseWithDefaultMinLength(): void
    {
        $parser = new ComskipEdlParser(); // Default 30 seconds
        $edlContent = "0.0\t45.0\t3\n";

        $chapters = $parser->parseString($edlContent);

        $this->assertCount(1, $chapters);
    }

    public function testParseWithDifferentTypes(): void
    {
        $parser = new ComskipEdlParser(30);
        // Type 0 = cut, 1 = mute, 2 = scene change, 3 = commercial
        $edlContent = "0.0\t45.0\t0\n60.0\t90.0\t1\n120.0\t150.0\t2\n180.0\t240.0\t3\n";

        $chapters = $parser->parseString($edlContent);

        $this->assertCount(4, $chapters);
    }

    public function testParseFromFile(): void
    {
        $parser = new ComskipEdlParser(30);
        $edlContent = "0.0\t45.0\t3\n60.0\t90.0\t3\n";
        $tempFile = tempnam(sys_get_temp_dir(), 'comskip_test_') . '.edl';
        file_put_contents($tempFile, $edlContent);

        try {
            $chapters = $parser->parse($tempFile);

            $this->assertCount(2, $chapters);
            $this->assertEquals(0, $chapters[0]->start_seconds);
            $this->assertEquals(45, $chapters[0]->end_seconds);
        } finally {
            unlink($tempFile);
        }
    }

    public function testParseThrowsWhenFileNotFound(): void
    {
        $parser = new ComskipEdlParser(30);
        $nonExistentFile = '/tmp/nonexistent_' . uniqid() . '.edl';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('EDL file not found');
        $parser->parse($nonExistentFile);
    }

    public function testChapterMarkerHasTitle(): void
    {
        $parser = new ComskipEdlParser(30);
        $edlContent = "0.0\t45.0\t3\n";

        $chapters = $parser->parseString($edlContent);

        $this->assertCount(1, $chapters);
        $this->assertNotNull($chapters[0]->title);
        $this->assertStringContainsString('Commercial', $chapters[0]->title);
    }
}
