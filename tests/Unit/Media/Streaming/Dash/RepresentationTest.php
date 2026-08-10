<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming\Dash;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\Dash\Representation;

/**
 * S58 — the class that makes an AdaptationSet adaptive.
 */
class RepresentationTest extends TestCase
{
    public function testAVideoRepresentationCarriesItsFrameSize(): void
    {
        $element = (new Representation('1080p', 'avc1.640029', 5128000, 1920, 1080))->toXml();

        $this->assertSame('Representation', $element->nodeName);
        $this->assertSame('1080p', $element->getAttribute('id'));
        $this->assertSame('avc1.640029', $element->getAttribute('codecs'));
        $this->assertSame('5128000', $element->getAttribute('bandwidth'));
        $this->assertSame('1920', $element->getAttribute('width'));
        $this->assertSame('1080', $element->getAttribute('height'));
    }

    /**
     * `width="0"` is schema-valid and false. An audio representation has no
     * frame size, so the attributes must be absent rather than zeroed.
     */
    public function testAnAudioRepresentationOmitsWidthAndHeightRatherThanZeroingThem(): void
    {
        $element = (new Representation('a0', 'mp4a.40.2', 128000))->toXml();

        $this->assertFalse($element->hasAttribute('width'));
        $this->assertFalse($element->hasAttribute('height'));
        $this->assertSame('128000', $element->getAttribute('bandwidth'));
    }

    /**
     * `@bandwidth` is `use="required"` in the schema, so it is emitted even when
     * the persisted ladder had nothing to put in it — omitting it would make the
     * whole manifest invalid rather than merely under-informed.
     */
    public function testBandwidthIsEmittedEvenWhenItIsZero(): void
    {
        $element = (new Representation('720p', 'avc1.640029', 0, 1280, 720))->toXml();

        $this->assertTrue($element->hasAttribute('bandwidth'));
        $this->assertSame('0', $element->getAttribute('bandwidth'));
    }

    /**
     * A partial frame size is not a frame size: DASH's `@width`/`@height` are
     * only meaningful together.
     */
    public function testAHalfSpecifiedFrameSizeIsOmitted(): void
    {
        $element = (new Representation('x', 'avc1.640029', 100, 1920, 0))->toXml();

        $this->assertFalse($element->hasAttribute('width'));
        $this->assertFalse($element->hasAttribute('height'));
    }
}
