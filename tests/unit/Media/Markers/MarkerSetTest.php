<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Markers;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Markers\ChapterMarker;
use Phlex\Media\Markers\IntroMarker;
use Phlex\Media\Markers\MarkerSet;
use Phlex\Media\Markers\OutroMarker;

class MarkerSetTest extends TestCase
{
    public function testEmptyWhenNoMarkers(): void
    {
        $markerSet = MarkerSet::empty();

        $this->assertNull($markerSet->intro);
        $this->assertNull($markerSet->outro);
        $this->assertEmpty($markerSet->chapters);
        $this->assertFalse($markerSet->hasMarkers());
    }

    public function testIntroAndOutroAccessible(): void
    {
        $intro = new IntroMarker(0, 90, 85);
        $outro = new OutroMarker(2310, 2400, 80);

        $markerSet = new MarkerSet($intro, $outro, []);

        $this->assertSame($intro, $markerSet->intro);
        $this->assertSame($outro, $markerSet->outro);
        $this->assertTrue($markerSet->hasMarkers());
    }

    public function testChaptersArray(): void
    {
        $chapters = [
            new ChapterMarker(0, 90, 'Intro'),
            new ChapterMarker(90, 300, 'Chapter 1'),
            new ChapterMarker(300, 600, 'Chapter 2'),
        ];

        $markerSet = new MarkerSet(null, null, $chapters);

        $this->assertCount(3, $markerSet->chapters);
        $this->assertSame($chapters, $markerSet->chapters);
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $intro = new IntroMarker(0, 90, 85);
        $outro = new OutroMarker(2310, 2400, 80);
        $chapters = [
            new ChapterMarker(0, 90, 'Intro'),
            new ChapterMarker(90, 300, 'Chapter 1'),
        ];

        $markerSet = new MarkerSet($intro, $outro, $chapters);
        $arr = $markerSet->toArray();

        $this->assertArrayHasKey('intro', $arr);
        $this->assertArrayHasKey('outro', $arr);
        $this->assertArrayHasKey('chapters', $arr);

        $this->assertEquals(['start' => 0, 'end' => 90, 'confidence' => 85], $arr['intro']);
        $this->assertEquals(['start' => 2310, 'end' => 2400, 'confidence' => 80], $arr['outro']);
        $this->assertCount(2, $arr['chapters']);
    }

    public function testToArrayWithNullMarkers(): void
    {
        $markerSet = MarkerSet::empty();
        $arr = $markerSet->toArray();

        $this->assertNull($arr['intro']);
        $this->assertNull($arr['outro']);
        $this->assertEmpty($arr['chapters']);
    }

    public function testHasMarkersReturnsTrueWhenIntroPresent(): void
    {
        $markerSet = new MarkerSet(new IntroMarker(0, 90, 85), null, []);

        $this->assertTrue($markerSet->hasMarkers());
    }

    public function testHasMarkersReturnsTrueWhenOutroPresent(): void
    {
        $markerSet = new MarkerSet(null, new OutroMarker(2310, 2400, 80), []);

        $this->assertTrue($markerSet->hasMarkers());
    }

    public function testHasMarkersReturnsTrueWhenChaptersPresent(): void
    {
        $markerSet = new MarkerSet(null, null, [new ChapterMarker(0, 90, 'Test')]);

        $this->assertTrue($markerSet->hasMarkers());
    }

    public function testChapterWithoutTitle(): void
    {
        $chapter = new ChapterMarker(90, 300);

        $this->assertEquals(90, $chapter->start_seconds);
        $this->assertEquals(300, $chapter->end_seconds);
        $this->assertNull($chapter->title);
    }

    public function testChapterToArrayExcludesTitleWhenNull(): void
    {
        $chapter = new ChapterMarker(90, 300);
        $arr = $chapter->toArray();

        $this->assertArrayNotHasKey('title', $arr);
    }

    public function testChapterToArrayIncludesTitleWhenSet(): void
    {
        $chapter = new ChapterMarker(90, 300, 'Chapter One');
        $arr = $chapter->toArray();

        $this->assertEquals('Chapter One', $arr['title']);
    }
}
