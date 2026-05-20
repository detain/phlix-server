<?php

namespace Phlix\Tests\Unit\Media\Streaming;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\QualitySelector;

class QualitySelectorTest extends TestCase
{
    public function testCanCreateQualitySelector(): void
    {
        $selector = new QualitySelector();
        $this->assertInstanceOf(QualitySelector::class, $selector);
    }

    public function testSelectsDirectPlayForCompatibleSource(): void
    {
        $selector = new QualitySelector();
        
        $sourceInfo = [
            'streams' => [
                ['codec_type' => 'video', 'codec' => 'h264', 'width' => 1920, 'height' => 1080, 'bitrate' => 5000000],
                ['codec_type' => 'audio', 'codec' => 'aac', 'channels' => 2],
            ],
        ];
        
        $result = $selector->selectQuality($sourceInfo, 'generic');
        
        $this->assertEquals('direct', $result['method']);
    }

    public function testSelectsTranscodeForIncompatibleSource(): void
    {
        $selector = new QualitySelector();
        
        $sourceInfo = [
            'streams' => [
                ['codec_type' => 'video', 'codec' => 'hevc', 'width' => 3840, 'height' => 2160, 'bitrate' => 100000000],
                ['codec_type' => 'audio', 'codec' => 'truehd', 'channels' => 8],
            ],
        ];
        
        $result = $selector->selectQuality($sourceInfo, 'mobile-low');
        
        $this->assertEquals('transcode', $result['method']);
    }
}