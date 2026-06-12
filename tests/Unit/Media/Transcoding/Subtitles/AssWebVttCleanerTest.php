<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding\Subtitles;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\Subtitles\AssWebVttCleaner;

/**
 * Unit tests for {@see AssWebVttCleaner}.
 *
 * Verified against the real ffmpeg `-c:s webvtt` output for the ASS tracks in the
 * live-server failing file (Assassination Classroom S01E01): override blocks like
 * `{*\fax0.392}` and ASS vector-drawing paths leak into cue text and must be
 * stripped while `<b>`/`<i>` and real dialogue survive.
 */
final class AssWebVttCleanerTest extends TestCase
{
    public function test_strips_ass_override_blocks_keeps_bold(): void
    {
        // The exact garbage ffmpeg produced for the karaoke title line.
        $line = '<b>A{*\\fax0.392}s{*\\fax0.385}s{*\\fax0.377}a{*\\fax0.369}'
            . 's{*\\fax0.362}s{*\\fax0.354}i{*\\fax0.346}n{*\\fax0.338}a'
            . '{*\\fax0.331}t{*\\fax0.323}i{*\\fax0.315}o{*\\fax0.308}n</b>';

        self::assertSame('<b>Assassination</b>', AssWebVttCleaner::cleanCueText($line));
    }

    public function test_strips_positioning_override(): void
    {
        self::assertSame('Hello there', AssWebVttCleaner::cleanCueText('{\\an8}Hello there'));
    }

    public function test_converts_hard_line_breaks(): void
    {
        self::assertSame("Line one\nLine two", AssWebVttCleaner::cleanCueText('Line one\\NLine two'));
        self::assertSame("a\nb", AssWebVttCleaner::cleanCueText('a\\nb'));
    }

    public function test_converts_nbsp(): void
    {
        self::assertSame('a b', AssWebVttCleaner::cleanCueText('a\\hb'));
    }

    public function test_drops_font_tags_keeps_inner_text(): void
    {
        self::assertSame(
            'colored text',
            AssWebVttCleaner::cleanCueText('<font color="#FF0000">colored text</font>')
        );
    }

    public function test_drops_pure_drawing_path_cue(): void
    {
        self::assertSame('', AssWebVttCleaner::cleanCueText('m 0 0 l 150 0 150 150 0 150'));
        self::assertSame('', AssWebVttCleaner::cleanCueText('m 0 0 b 1 2 3 4 5 6'));
    }

    public function test_keeps_plain_dialogue(): void
    {
        self::assertSame('Roll Book', AssWebVttCleaner::cleanCueText('Roll Book'));
        self::assertSame(
            "Young people's discourse on bloodthirst",
            AssWebVttCleaner::cleanCueText("Young people's discourse on bloodthirst")
        );
    }

    public function test_does_not_eat_real_text_that_looks_drawing_ish(): void
    {
        // "l" followed by a word is NOT a drawing path (needs a number after).
        self::assertSame('l is for love', AssWebVttCleaner::cleanCueText('l is for love'));
    }

    public function test_clean_full_document_preserves_structure(): void
    {
        $raw = "WEBVTT\n\n"
            . "00:23.000 --> 00:23.040\n"
            . "<b>A{*\\fax0.392}s{*\\fax0.385}sassination</b>\n\n"
            . "00:47.110 --> 00:47.190\n"
            . "Roll Book\n\n"
            . "03:23.220 --> 03:24.050\n"
            . "m 0 0 l 150 0 150 150 0 150\n";

        $out = AssWebVttCleaner::clean($raw);

        self::assertStringStartsWith('WEBVTT', $out);
        self::assertStringContainsString('<b>Assassination</b>', $out);
        self::assertStringContainsString('Roll Book', $out);
        self::assertStringContainsString('00:23.000 --> 00:23.040', $out);
        // The drawing path line is gone from cue text.
        self::assertStringNotContainsString('150 150', $out);
        // No leaked override braces.
        self::assertStringNotContainsString('{', $out);
    }

    public function test_clean_crlf_normalised_and_header_added_when_missing(): void
    {
        $raw = "00:00.000 --> 00:01.000\r\nHello\r\n";
        $out = AssWebVttCleaner::clean($raw);
        self::assertStringStartsWith('WEBVTT', $out);
        self::assertStringContainsString('Hello', $out);
        self::assertStringNotContainsString("\r", $out);
    }
}
