<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding\Subtitles;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\Subtitles\SubtitleFormat;

class SubtitleFormatTest extends TestCase
{
    public function test_get_ffmpeg_format_srt(): void
    {
        $this->assertSame('srt', SubtitleFormat::SRT->getFfmpegFormat());
    }

    public function test_get_ffmpeg_format_ass(): void
    {
        $this->assertSame('ass', SubtitleFormat::ASS->getFfmpegFormat());
    }

    public function test_get_ffmpeg_format_ssa(): void
    {
        // SSA is muxed as ASS
        $this->assertSame('ass', SubtitleFormat::SSA->getFfmpegFormat());
    }

    public function test_get_ffmpeg_format_vtt(): void
    {
        $this->assertSame('webvtt', SubtitleFormat::VTT->getFfmpegFormat());
    }

    public function test_get_ffmpeg_format_hdmv(): void
    {
        // PGS subtitles are copied, not transcoded
        $this->assertSame('copy', SubtitleFormat::HDMV->getFfmpegFormat());
    }

    public function test_supports_fontstyle_ass(): void
    {
        $this->assertTrue(SubtitleFormat::ASS->supportsFontstyle());
    }

    public function test_supports_fontstyle_ssa(): void
    {
        $this->assertTrue(SubtitleFormat::SSA->supportsFontstyle());
    }

    public function test_supports_fontstyle_srt(): void
    {
        $this->assertFalse(SubtitleFormat::SRT->supportsFontstyle());
    }

    public function test_supports_fontstyle_vtt(): void
    {
        $this->assertFalse(SubtitleFormat::VTT->supportsFontstyle());
    }

    public function test_supports_fontstyle_hdmv(): void
    {
        $this->assertFalse(SubtitleFormat::HDMV->supportsFontstyle());
    }

    public function test_enum_values(): void
    {
        $this->assertSame('srt', SubtitleFormat::SRT->value);
        $this->assertSame('ass', SubtitleFormat::ASS->value);
        $this->assertSame('ssa', SubtitleFormat::SSA->value);
        $this->assertSame('vtt', SubtitleFormat::VTT->value);
        $this->assertSame('hdmv', SubtitleFormat::HDMV->value);
    }
}
