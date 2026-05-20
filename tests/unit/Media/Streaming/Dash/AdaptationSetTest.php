<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming\Dash;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\Dash\AdaptationSet;

class AdaptationSetTest extends TestCase
{
    public function testToXmlVideo(): void
    {
        $set = new AdaptationSet(
            id: 'video-1080',
            contentType: 'video',
            codecs: 'avc1.64001f',
            width: 1920,
            height: 1080,
            bandwidth: 5000000,
            sampleRate: 0,
            segments: []
        );

        $element = $set->toXml();

        $this->assertEquals('AdaptationSet', $element->nodeName);
        $this->assertEquals('video-1080', $element->getAttribute('id'));
        $this->assertEquals('video', $element->getAttribute('contentType'));
        $this->assertEquals('1920', $element->getAttribute('width'));
        $this->assertEquals('1080', $element->getAttribute('height'));
        $this->assertEquals('5000000', $element->getAttribute('bandwidth'));
    }

    public function testToXmlAudio(): void
    {
        $set = new AdaptationSet(
            id: 'audio-en',
            contentType: 'audio',
            codecs: 'mp4a.40.2',
            width: 0,
            height: 0,
            bandwidth: 128000,
            sampleRate: 48000,
            segments: []
        );

        $element = $set->toXml();

        $this->assertEquals('AdaptationSet', $element->nodeName);
        $this->assertEquals('audio-en', $element->getAttribute('id'));
        $this->assertEquals('audio', $element->getAttribute('contentType'));
        $this->assertEquals('128000', $element->getAttribute('bandwidth'));
        $this->assertEquals('48000', $element->getAttribute('audioSamplingRate'));
    }

    public function testGetId(): void
    {
        $set = new AdaptationSet(
            id: 'video-1080',
            contentType: 'video',
            codecs: 'avc1.64001f',
            width: 1920,
            height: 1080,
            bandwidth: 5000000
        );

        $this->assertEquals('video-1080', $set->getId());
    }

    public function testGetContentType(): void
    {
        $set = new AdaptationSet(
            id: 'audio-en',
            contentType: 'audio',
            codecs: 'mp4a.40.2',
            bandwidth: 128000
        );

        $this->assertEquals('audio', $set->getContentType());
    }

    public function testGetCodecs(): void
    {
        $set = new AdaptationSet(
            id: 'video-1080',
            contentType: 'video',
            codecs: 'avc1.64001f',
            bandwidth: 5000000
        );

        $this->assertEquals('avc1.64001f', $set->getCodecs());
    }

    public function testGetBandwidth(): void
    {
        $set = new AdaptationSet(
            id: 'video-1080',
            contentType: 'video',
            codecs: 'avc1.64001f',
            bandwidth: 5000000
        );

        $this->assertEquals(5000000, $set->getBandwidth());
    }

    public function testGetSegmentCount(): void
    {
        $set = new AdaptationSet(
            id: 'video-1080',
            contentType: 'video',
            codecs: 'avc1.64001f',
            bandwidth: 5000000,
            segments: [
                ['duration' => 6.0],
                ['duration' => 6.0],
                ['duration' => 6.0],
            ]
        );

        $this->assertEquals(3, $set->getSegmentCount());
    }

    public function testGetSegmentCountReturnsZeroForEmptySegments(): void
    {
        $set = new AdaptationSet(
            id: 'video-1080',
            contentType: 'video',
            codecs: 'avc1.64001f',
            bandwidth: 5000000,
            segments: []
        );

        $this->assertEquals(0, $set->getSegmentCount());
    }
}
