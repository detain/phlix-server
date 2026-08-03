<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Subtitles;

use Phlix\Media\Subtitles\WebVttConverter;
use PHPUnit\Framework\TestCase;

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

    /**
     * A numeric-only cue-index line is dropped ONLY when it precedes a timing
     * line; a legitimate cue whose text is nothing but a number (a countdown)
     * must be preserved. Regression for the review finding where any all-digit
     * line was dropped unconditionally.
     */
    public function testNumericOnlyCueTextIsPreservedWhenNotACueIndex(): void
    {
        $srt = "1\r\n00:00:01,000 --> 00:00:02,000\r\n3\r\n\r\n"
            . "2\r\n00:00:03,000 --> 00:00:04,000\r\n2\r\n";

        $vtt = WebVttConverter::toWebVtt($srt, 'srt');

        // The real cue indexes (1, 2) sit directly before a timing line → dropped.
        // The countdown cue TEXT "3" and "2" follow a timing line → preserved.
        $this->assertMatchesRegularExpression('/-->.*\n3\b/s', $vtt, 'countdown "3" cue text kept');
        $this->assertStringContainsString("\n2\n", $vtt . "\n");
        $this->assertStringContainsString('00:00:01.000 --> 00:00:02.000', $vtt);
    }

    /**
     * Payloads beyond the defensive cap are truncated (bounded transient memory)
     * while still producing valid, header-led WebVTT.
     */
    public function testOversizePayloadIsCappedButStillValid(): void
    {
        $huge = "1\n00:00:01,000 --> 00:00:02,000\nHi\n\n" . str_repeat('x', 9 * 1024 * 1024);

        $vtt = WebVttConverter::toWebVtt($huge, 'srt');

        $this->assertStringStartsWith('WEBVTT', $vtt);
        $this->assertLessThanOrEqual(9 * 1024 * 1024, strlen($vtt), 'output bounded by the cap');
    }
}
