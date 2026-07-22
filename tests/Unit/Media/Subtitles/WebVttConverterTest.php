<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Subtitles;

use Phlix\Media\Subtitles\WebVttConverter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Media\Subtitles\WebVttConverter
 */
final class WebVttConverterTest extends TestCase
{
    public function testSubRipIsConvertedToWebVtt(): void
    {
        $srt = "1\r\n00:00:01,000 --> 00:00:04,000\r\nHello world\r\n\r\n"
            . "2\r\n00:00:05,500 --> 00:00:06,750\r\nSecond line\r\n";

        $vtt = WebVttConverter::toWebVtt($srt, 'srt');

        $this->assertStringStartsWith('WEBVTT', $vtt);
        // Millisecond separator rewritten comma -> dot.
        $this->assertStringContainsString('00:00:01.000 --> 00:00:04.000', $vtt);
        $this->assertStringContainsString('00:00:05.500 --> 00:00:06.750', $vtt);
        $this->assertStringNotContainsString(',000 -->', $vtt);
        // SubRip numeric cue-index lines are dropped.
        $this->assertStringNotContainsString("\n1\n", "\n" . $vtt);
        $this->assertStringContainsString('Hello world', $vtt);
        $this->assertStringContainsString('Second line', $vtt);
    }

    public function testWebVttPassesThroughWithHeaderPreserved(): void
    {
        $input = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nKeep me\n";

        $vtt = WebVttConverter::toWebVtt($input, 'vtt');

        $this->assertStringStartsWith('WEBVTT', $vtt);
        $this->assertStringContainsString('Keep me', $vtt);
        // A dot-separated VTT timestamp must NOT be corrupted into a comma.
        $this->assertStringContainsString('00:00:01.000 --> 00:00:02.000', $vtt);
    }

    public function testUnknownFormatIsWrappedWithHeader(): void
    {
        $vtt = WebVttConverter::toWebVtt("no header here\n", 'sub');

        $this->assertStringStartsWith("WEBVTT\n", $vtt);
        $this->assertStringContainsString('no header here', $vtt);
    }
}
