<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming\Dash;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\Dash\SegmentTemplate;

/**
 * S58 reworked this class: `@duration` is now in `@timescale` units and the
 * timescale is emitted, because MPD's default timescale is 1 and the pre-S58
 * `duration = seconds * 1000` therefore declared a SIX THOUSAND SECOND segment.
 */
class SegmentTemplateTest extends TestCase
{
    public function testToXmlEmitsTheTimescaleItsDurationIsExpressedIn(): void
    {
        $template = new SegmentTemplate(
            duration: 6000,
            timescale: 1000,
            startNumber: 0,
            media: 'seg-v$RepresentationID$-$Number%05d$.m4s',
            initialization: 'init-v$RepresentationID$.m4s'
        );

        $element = $template->toXml();

        $this->assertEquals('SegmentTemplate', $element->nodeName);
        $this->assertEquals('1000', $element->getAttribute('timescale'));
        $this->assertEquals('6000', $element->getAttribute('duration'));
        $this->assertEquals('0', $element->getAttribute('startNumber'));
        $this->assertEquals('seg-v$RepresentationID$-$Number%05d$.m4s', $element->getAttribute('media'));
        $this->assertEquals('init-v$RepresentationID$.m4s', $element->getAttribute('initialization'));
    }

    /**
     * An undeclared `@timescale` defaults to 1 in the MPD schema, so leaving it
     * out is not a cosmetic omission — it multiplies every segment length by a
     * thousand. It must be present even when it happens to be the class default.
     */
    public function testTheTimescaleIsAlwaysEmittedNeverLeftToTheSchemaDefault(): void
    {
        $element = (new SegmentTemplate(6000))->toXml();

        $this->assertTrue($element->hasAttribute('timescale'));
        $this->assertEquals('1000', $element->getAttribute('timescale'));
    }

    /**
     * `fromSeconds()` is the constructor a caller holding seconds must use;
     * passing seconds into `$duration` is the exact confusion it exists to stop.
     */
    public function testFromSecondsConvertsToTicks(): void
    {
        $template = SegmentTemplate::fromSeconds(4, 0, 'seg-$Number%05d$.m4s', 'init.m4s');

        $this->assertSame(4000, $template->getDuration());
        $this->assertSame(1000, $template->getTimescale());
        $this->assertSame(4.0, (float) $template->getDuration() / (float) $template->getTimescale());
        $this->assertSame(0, $template->getStartNumber());
        $this->assertSame('seg-$Number%05d$.m4s', $template->getMediaTemplate());
        $this->assertSame('init.m4s', $template->getInitializationTemplate());
    }

    /**
     * DASH's own default `startNumber` is 1; every index this codebase produces
     * is 0-based, so a client taking the DASH default would never fetch segment
     * 0 — the only segment that exists at play-start.
     */
    public function testTheDefaultStartNumberIsZeroNotDashsOwnDefaultOfOne(): void
    {
        $this->assertSame(0, (new SegmentTemplate(6000))->getStartNumber());
        $this->assertEquals('0', (new SegmentTemplate(6000))->toXml()->getAttribute('startNumber'));
    }

    public function testToXmlWithoutInitialization(): void
    {
        $template = new SegmentTemplate(
            duration: 10000,
            timescale: 1000,
            startNumber: 0,
            media: '$RepresentationID$_$Number%05d$.m4s'
        );

        $element = $template->toXml();

        $this->assertEquals('10000', $element->getAttribute('duration'));
        $this->assertFalse($element->hasAttribute('initialization'));
    }

    public function testInitializationUrlReturnsNullWhenNotSet(): void
    {
        $this->assertNull((new SegmentTemplate(6000))->getInitializationTemplate());
    }

    public function testStartNumber(): void
    {
        $template = new SegmentTemplate(6000, 1000, 5);

        $this->assertEquals(5, $template->getStartNumber());
    }

    /**
     * A non-millisecond timescale still round-trips: the class must not assume
     * its own default anywhere.
     */
    public function testANonDefaultTimescaleSurvivesToTheXml(): void
    {
        $element = (new SegmentTemplate(540000, 90000, 0))->toXml();

        $this->assertEquals('90000', $element->getAttribute('timescale'));
        $this->assertEquals('540000', $element->getAttribute('duration'));
    }
}
