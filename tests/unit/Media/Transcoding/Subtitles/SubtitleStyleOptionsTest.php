<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Transcoding\Subtitles;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Transcoding\Subtitles\SubtitleStyleOptions;

class SubtitleStyleOptionsTest extends TestCase
{
    public function test_defaults(): void
    {
        $style = new SubtitleStyleOptions();

        $this->assertSame('Arial', $style->font_name);
        $this->assertSame(24, $style->font_size);
        $this->assertSame('&H00FFFFFF', $style->primary_color);
        $this->assertSame('&H00000000', $style->outline_color);
        $this->assertSame(2, $style->outline_thickness);
        $this->assertSame('bottom', $style->position);
        $this->assertSame(10, $style->margin);
    }

    public function test_custom_values(): void
    {
        $style = new SubtitleStyleOptions(
            font_name: 'Verdana',
            font_size: 32,
            primary_color: '&H00FFFF00',
            outline_color: '&H000000FF',
            outline_thickness: 3,
            position: 'top',
            margin: 20
        );

        $this->assertSame('Verdana', $style->font_name);
        $this->assertSame(32, $style->font_size);
        $this->assertSame('&H00FFFF00', $style->primary_color);
        $this->assertSame('&H000000FF', $style->outline_color);
        $this->assertSame(3, $style->outline_thickness);
        $this->assertSame('top', $style->position);
        $this->assertSame(20, $style->margin);
    }

    public function test_to_ass_style(): void
    {
        $style = new SubtitleStyleOptions(
            font_name: 'Arial',
            font_size: 24,
            primary_color: '&H00FFFFFF',
            outline_color: '&H00000000',
            outline_thickness: 2,
            position: 'bottom',
            margin: 10
        );

        $assStyle = $style->toAssStyle();

        $this->assertStringContainsString('FontName=Arial', $assStyle);
        $this->assertStringContainsString('FontSize=24', $assStyle);
        $this->assertStringContainsString('PrimaryColour=&H00FFFFFF', $assStyle);
        $this->assertStringContainsString('OutlineColour=&H00000000', $assStyle);
        $this->assertStringContainsString('Outline=2', $assStyle);
        $this->assertStringContainsString('MarginV=10', $assStyle);
        $this->assertStringContainsString('Alignment=2', $assStyle); // Bottom center
    }

    public function test_to_ass_style_top_position(): void
    {
        $style = new SubtitleStyleOptions(
            font_name: 'Times New Roman',
            font_size: 28,
            primary_color: '&H00FF0000',
            outline_color: '&H0000FF00',
            outline_thickness: 1,
            position: 'top',
            margin: 15
        );

        $assStyle = $style->toAssStyle();

        $this->assertStringContainsString('FontName=Times New Roman', $assStyle);
        $this->assertStringContainsString('Alignment=5', $assStyle); // Top center
    }

    public function test_to_ass_style_absolute_position(): void
    {
        $style = new SubtitleStyleOptions(
            position: 'absolute',
            margin: 5
        );

        $assStyle = $style->toAssStyle();

        // 'absolute' position falls to else branch which defaults to bottom center
        $this->assertStringContainsString('Alignment=2', $assStyle);
    }

    public function test_to_srt_style_returns_empty(): void
    {
        $style = new SubtitleStyleOptions();

        // SRT doesn't support style via FFmpeg
        $this->assertSame('', $style->toSrtStyle());
    }

    public function test_immutability(): void
    {
        $style = new SubtitleStyleOptions(font_name: 'Courier New');

        // Properties are readonly - verify we can still read them
        $this->assertSame('Courier New', $style->font_name);
    }
}
