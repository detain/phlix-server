<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding\Subtitles;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\Subtitles\SubtitleExtractor;

/**
 * Unit tests for {@see SubtitleExtractor}.
 *
 * The probe fixtures mirror the live-server failing file (2 ASS tracks, both eng,
 * neither default) plus bitmap/multi-language variants.
 */
final class SubtitleExtractorTest extends TestCase
{
    public function test_detects_two_ass_tracks_disambiguates_and_defaults_first(): void
    {
        $probe = [
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'hevc'],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac'],
                ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'aac'],
                ['index' => 3, 'codec_type' => 'subtitle', 'codec_name' => 'ass', 'tags' => ['language' => 'eng']],
                ['index' => 4, 'codec_type' => 'subtitle', 'codec_name' => 'ass', 'tags' => ['language' => 'eng']],
            ],
        ];

        $tracks = (new SubtitleExtractor())->detectTextTracks($probe);

        self::assertCount(2, $tracks);
        // 0:s ordinals are 0 and 1 (not the absolute stream indexes 3/4).
        self::assertSame(0, $tracks[0]['index']);
        self::assertSame(1, $tracks[1]['index']);
        self::assertSame('sub-0.vtt', $tracks[0]['filename']);
        self::assertSame('sub-1.vtt', $tracks[1]['filename']);
        // Same-language repeats are disambiguated.
        self::assertSame('English 1', $tracks[0]['label']);
        self::assertSame('English 2', $tracks[1]['label']);
        // No source default → first promoted.
        self::assertTrue($tracks[0]['default']);
        self::assertFalse($tracks[1]['default']);
    }

    public function test_skips_bitmap_subtitles_but_keeps_ordinal_in_lockstep(): void
    {
        $probe = [
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264'],
                ['index' => 1, 'codec_type' => 'subtitle', 'codec_name' => 'hdmv_pgs_subtitle', 'tags' => ['language' => 'eng']],
                ['index' => 2, 'codec_type' => 'subtitle', 'codec_name' => 'subrip', 'tags' => ['language' => 'fra']],
            ],
        ];

        $tracks = (new SubtitleExtractor())->detectTextTracks($probe);

        self::assertCount(1, $tracks);
        // The bitmap PGS is 0:s:0; the kept subrip is 0:s:1 — ordinal preserved.
        self::assertSame(1, $tracks[0]['index']);
        self::assertSame('French', $tracks[0]['label']);
        self::assertSame('fra', $tracks[0]['language']);
        self::assertTrue($tracks[0]['default']);
    }

    public function test_respects_source_default_disposition(): void
    {
        $probe = [
            'streams' => [
                ['index' => 0, 'codec_type' => 'subtitle', 'codec_name' => 'subrip', 'tags' => ['language' => 'eng']],
                [
                    'index' => 1,
                    'codec_type' => 'subtitle',
                    'codec_name' => 'subrip',
                    'tags' => ['language' => 'spa'],
                    'disposition' => ['default' => 1],
                ],
            ],
        ];

        $tracks = (new SubtitleExtractor())->detectTextTracks($probe);

        self::assertCount(2, $tracks);
        self::assertFalse($tracks[0]['default']);
        self::assertTrue($tracks[1]['default']);
    }

    public function test_uses_track_title_when_present(): void
    {
        $probe = [
            'streams' => [
                [
                    'index' => 0,
                    'codec_type' => 'subtitle',
                    'codec_name' => 'ass',
                    'tags' => ['language' => 'eng', 'title' => 'Signs & Songs'],
                ],
            ],
        ];

        $tracks = (new SubtitleExtractor())->detectTextTracks($probe);

        self::assertSame('Signs & Songs', $tracks[0]['label']);
    }

    public function test_no_subtitles_returns_empty(): void
    {
        $probe = [
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264'],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
        ];

        self::assertSame([], (new SubtitleExtractor())->detectTextTracks($probe));
    }

    public function test_build_extract_command_targets_stream_and_cleans(): void
    {
        $cmd = (new SubtitleExtractor())->buildExtractCommand(
            '/usr/bin/ffmpeg',
            '/usr/bin/php',
            '/app/scripts/clean-vtt.php',
            '/media/in.mkv',
            '/jobs/abc',
            2
        );

        self::assertStringContainsString('-map 0:s:2', $cmd);
        self::assertStringContainsString('-c:s webvtt', $cmd);
        self::assertStringContainsString('sub-2.raw.vtt', $cmd);
        self::assertStringContainsString('clean-vtt.php', $cmd);
        self::assertStringContainsString("'/jobs/abc/sub-2.vtt'", $cmd);
        // Wrapped so a failed track never aborts the whole job.
        self::assertStringContainsString('|| true', $cmd);
    }
}
