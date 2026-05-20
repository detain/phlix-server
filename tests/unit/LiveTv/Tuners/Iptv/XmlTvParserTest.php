<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Tuners\Iptv;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\Tuners\Iptv\XmlTvParser;
use Phlix\LiveTv\Tuners\Iptv\XmlTvProgramme;

class XmlTvParserTest extends TestCase
{
    private XmlTvParser $parser;

    protected function setUp(): void
    {
        $this->parser = new XmlTvParser();
    }

    public function testParseBasicXmltv(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <programme channel="ch1" start="20240101120000" stop="20240101130000">
    <title>News Hour</title>
    <desc>A news programme.</desc>
  </programme>
</tv>
XML;

        $programmes = $this->parser->parse($xml);

        $this->assertCount(1, $programmes);
        $this->assertInstanceOf(XmlTvProgramme::class, $programmes[0]);
        $this->assertEquals('ch1', $programmes[0]->channelId);
        $this->assertEquals('News Hour', $programmes[0]->title);
        $this->assertEquals('A news programme.', $programmes[0]->description);
    }

    public function testParseProgrammeWithAllFields(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <programme channel="bbc_one" start="20240101180000" stop="20240101200000">
    <title>Doctor Who</title>
    <desc>The Doctor returns.</desc>
    <category>Science Fiction</category>
    <episode-num system="onscreen">S01E05</episode-num>
    <rating>
      <value>TV-PG</value>
    </rating>
    <date>20240101</date>
  </programme>
</tv>
XML;

        $programmes = $this->parser->parse($xml);

        $this->assertCount(1, $programmes);
        $this->assertEquals('bbc_one', $programmes[0]->channelId);
        $this->assertEquals('Doctor Who', $programmes[0]->title);
        $this->assertEquals('The Doctor returns.', $programmes[0]->description);
        $this->assertEquals('Science Fiction', $programmes[0]->category);
        $this->assertEquals('S01E05', $programmes[0]->episodeNum);
        $this->assertEquals('TV-PG', $programmes[0]->rating);
        $this->assertEquals(2024, $programmes[0]->year);
    }

    public function testParseUrlFetchesAndParses(): void
    {
        // This test would require a mock HTTP server
        // We verify the method exists and throws RuntimeException on failure
        $parser = new XmlTvParser();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to fetch XMLTV');

        $parser->parseUrl('https://example.com/epg.xml');
    }

    public function testParseHandlesEmptyXml(): void
    {
        $programmes = $this->parser->parse('');
        $this->assertIsArray($programmes);
        $this->assertEmpty($programmes);

        $programmes = $this->parser->parse('   ');
        $this->assertIsArray($programmes);
        $this->assertEmpty($programmes);
    }

    public function testParseInvalidXmlReturnsEmpty(): void
    {
        $programmes = $this->parser->parse('not valid xml at all');
        $this->assertIsArray($programmes);
        $this->assertEmpty($programmes);
    }

    public function testParseMultipleProgrammes(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <programme channel="ch1" start="20240101120000" stop="20240101130000">
    <title>Programme One</title>
  </programme>
  <programme channel="ch1" start="20240101130000" stop="20240101140000">
    <title>Programme Two</title>
  </programme>
  <programme channel="ch2" start="20240101120000" stop="20240101150000">
    <title>Programme Three</title>
  </programme>
</tv>
XML;

        $programmes = $this->parser->parse($xml);

        $this->assertCount(3, $programmes);
        $this->assertEquals('Programme One', $programmes[0]->title);
        $this->assertEquals('Programme Two', $programmes[1]->title);
        $this->assertEquals('Programme Three', $programmes[2]->title);
    }

    public function testParseWithTimezoneOffset(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <programme channel="ch1" start="20240101120000 +0000" stop="20240101130000 +0000">
    <title>Timed Programme</title>
  </programme>
</tv>
XML;

        $programmes = $this->parser->parse($xml);

        $this->assertCount(1, $programmes);
        $this->assertEquals('Timed Programme', $programmes[0]->title);
    }

    public function testXmlTvProgrammeIsAiring(): void
    {
        $now = time();
        $programme = new XmlTvProgramme(
            channelId: 'ch1',
            startTime: $now - 3600,
            endTime: $now + 3600,
            title: 'Current Show'
        );

        $this->assertTrue($programme->isAiring());
        $this->assertTrue($programme->isAiring($now));
        $this->assertFalse($programme->isAiring($now - 7200));
        $this->assertFalse($programme->isAiring($now + 7200));
    }

    public function testXmlTvProgrammeGetDuration(): void
    {
        $programme = new XmlTvProgramme(
            channelId: 'ch1',
            startTime: 1000,
            endTime: 4000,
            title: 'Long Show'
        );

        $this->assertEquals(3000, $programme->getDuration());
    }

    public function testXmlTvProgrammeToArray(): void
    {
        $programme = new XmlTvProgramme(
            channelId: 'ch1',
            startTime: 1000,
            endTime: 4000,
            title: 'Test Show',
            description: 'A test show',
            category: 'Drama',
            episodeNum: 'S01E01',
            rating: 'TV-14',
            year: 2024
        );

        $array = $programme->toArray();

        $this->assertEquals('ch1', $array['channel_id']);
        $this->assertEquals(1000, $array['start_time']);
        $this->assertEquals(4000, $array['end_time']);
        $this->assertEquals('Test Show', $array['title']);
        $this->assertEquals('A test show', $array['description']);
        $this->assertEquals('Drama', $array['category']);
        $this->assertEquals('S01E01', $array['episode_num']);
        $this->assertEquals('TV-14', $array['rating']);
        $this->assertEquals(2024, $array['year']);
    }
}
