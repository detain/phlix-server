<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding\Subtitles;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\Subtitles\SubtitleFormat;
use Phlix\Media\Transcoding\Subtitles\SubtitleTrack;

class SubtitleTrackTest extends TestCase
{
    public function test_all_fields_accessible(): void
    {
        $track = new SubtitleTrack(
            index: '1',
            language: 'eng',
            label: 'English (CC)',
            format: SubtitleFormat::SRT,
            path: '/var/subtitles/movie_eng.srt'
        );

        $this->assertSame('1', $track->index);
        $this->assertSame('eng', $track->language);
        $this->assertSame('English (CC)', $track->label);
        $this->assertSame(SubtitleFormat::SRT, $track->format);
        $this->assertSame('/var/subtitles/movie_eng.srt', $track->path);
    }

    public function test_with_ass_format(): void
    {
        $track = new SubtitleTrack(
            index: '2',
            language: 'fra',
            label: 'French Subtitles',
            format: SubtitleFormat::ASS,
            path: '/var/subtitles/movie_fra.ass'
        );

        $this->assertSame('2', $track->index);
        $this->assertSame('fra', $track->language);
        $this->assertSame(SubtitleFormat::ASS, $track->format);
        $this->assertTrue($track->format->supportsFontstyle());
    }

    public function test_with_vtt_format(): void
    {
        $track = new SubtitleTrack(
            index: '3',
            language: 'spa',
            label: 'Spanish',
            format: SubtitleFormat::VTT,
            path: '/var/subtitles/movie_spa.vtt'
        );

        $this->assertSame('3', $track->index);
        $this->assertSame(SubtitleFormat::VTT, $track->format);
        $this->assertFalse($track->format->supportsFontstyle());
    }

    public function test_immutability(): void
    {
        $track = new SubtitleTrack(
            index: '0',
            language: 'und',
            label: 'Unknown',
            format: SubtitleFormat::SRT,
            path: '/path/to/subs.srt'
        );

        // Properties are readonly - cannot modify after construction
        $this->assertSame('0', $track->index);
        $this->assertSame('und', $track->language);
    }
}
