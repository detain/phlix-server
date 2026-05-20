<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming\Dash;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\Dash\SegmentTemplate;

class SegmentTemplateTest extends TestCase
{
    public function testToXml(): void
    {
        $template = new SegmentTemplate(
            duration: 6,
            startNumber: 1,
            media: '$RepresentationID$_$Number%05d$.m4s',
            initialization: '$RepresentationID$_init.m4s'
        );

        $element = $template->toXml();

        $this->assertEquals('SegmentTemplate', $element->nodeName);
        $this->assertEquals('6000', $element->getAttribute('duration'));
        $this->assertEquals('1', $element->getAttribute('startNumber'));
        $this->assertEquals('$RepresentationID$_$Number%05d$.m4s', $element->getAttribute('media'));
        $this->assertEquals('$RepresentationID$_init.m4s', $element->getAttribute('initialization'));
    }

    public function testToXmlWithoutInitialization(): void
    {
        $template = new SegmentTemplate(
            duration: 10,
            startNumber: 1,
            media: '$RepresentationID$_$Number%05d$.m4s'
        );

        $element = $template->toXml();

        $this->assertEquals('SegmentTemplate', $element->nodeName);
        $this->assertEquals('10000', $element->getAttribute('duration'));
        $this->assertEquals('', $element->getAttribute('initialization'));
    }

    public function testInitializationUrl(): void
    {
        $template = new SegmentTemplate(
            duration: 6,
            startNumber: 1,
            media: '$RepresentationID$_$Number%05d$.m4s',
            initialization: '$RepresentationID$_init.m4s'
        );

        $this->assertEquals('$RepresentationID$_init.m4s', $template->getInitializationTemplate());
    }

    public function testInitializationUrlReturnsNullWhenNotSet(): void
    {
        $template = new SegmentTemplate(
            duration: 6,
            startNumber: 1,
            media: '$RepresentationID$_$Number%05d$.m4s'
        );

        $this->assertNull($template->getInitializationTemplate());
    }

    public function testMediaTemplate(): void
    {
        $template = new SegmentTemplate(
            duration: 6,
            startNumber: 1,
            media: '$RepresentationID$_$Number%05d$.m4s',
            initialization: '$RepresentationID$_init.m4s'
        );

        $this->assertEquals('$RepresentationID$_$Number%05d$.m4s', $template->getMediaTemplate());
    }

    public function testStartNumber(): void
    {
        $template = new SegmentTemplate(
            duration: 6,
            startNumber: 5,
            media: '$RepresentationID$_$Number%05d$.m4s'
        );

        $this->assertEquals(5, $template->getStartNumber());
    }

    public function testDuration(): void
    {
        $template = new SegmentTemplate(
            duration: 6,
            startNumber: 1,
            media: '$RepresentationID$_$Number%05d$.m4s'
        );

        $this->assertEquals(6, $template->getDuration());
    }
}
