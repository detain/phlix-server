<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Transcoding\Subtitles;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Transcoding\FfmpegRunner;
use Phlex\Media\Transcoding\Subtitles\SubtitleBurner;
use Phlex\Media\Transcoding\Subtitles\SubtitleBurnerFactory;
use Phlex\Media\Transcoding\Subtitles\SubtitleFormat;
use Phlex\Media\Transcoding\Subtitles\SubtitleStyleOptions;
use Phlex\Media\Transcoding\Subtitles\SubtitleTrack;

class SubtitleBurnerTest extends TestCase
{
    private function createMockFfmpegRunner(): FfmpegRunner
    {
        return $this->createMock(FfmpegRunner::class);
    }

    public function test_detect_subtitle_tracks(): void
    {
        $ffmpeg = $this->createMockFfmpegRunner();
        $burner = new SubtitleBurner($ffmpeg);

        $probeResult = [
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264'],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac'],
                [
                    'index' => 2,
                    'codec_type' => 'subtitle',
                    'codec_name' => 'srt',
                    'tags' => ['language' => 'eng', 'title' => 'English']
                ],
                [
                    'index' => 3,
                    'codec_type' => 'subtitle',
                    'codec_name' => 'ass',
                    'tags' => ['language' => 'fra']
                ],
            ],
        ];

        $tracks = $burner->detectSubtitleTracks($probeResult);

        $this->assertCount(2, $tracks);

        // First stream: title tag is 'English' so label is 'English' directly
        $this->assertSame('2', $tracks[0]->index);
        $this->assertSame('eng', $tracks[0]->language);
        $this->assertSame('English', $tracks[0]->label);
        $this->assertSame(SubtitleFormat::SRT, $tracks[0]->format);

        // Second stream: no title tag, uses formatLabel with language 'fra'
        $this->assertSame('3', $tracks[1]->index);
        $this->assertSame('fra', $tracks[1]->language);
        $this->assertSame('French', $tracks[1]->label);
        $this->assertSame(SubtitleFormat::ASS, $tracks[1]->format);
    }

    public function test_detect_subtitle_tracks_empty(): void
    {
        $ffmpeg = $this->createMockFfmpegRunner();
        $burner = new SubtitleBurner($ffmpeg);

        $probeResult = [
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264'],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
        ];

        $tracks = $burner->detectSubtitleTracks($probeResult);

        $this->assertCount(0, $tracks);
    }

    public function test_detect_subtitle_tracks_no_streams(): void
    {
        $ffmpeg = $this->createMockFfmpegRunner();
        $burner = new SubtitleBurner($ffmpeg);

        $tracks = $burner->detectSubtitleTracks([]);

        $this->assertCount(0, $tracks);
    }

    public function test_get_burn_in_filter_ass(): void
    {
        $ffmpeg = $this->createMockFfmpegRunner();
        $burner = new SubtitleBurner($ffmpeg);

        $track = new SubtitleTrack(
            index: '2',
            language: 'eng',
            label: 'English',
            format: SubtitleFormat::ASS,
            path: '/var/subtitles/movie.ass'
        );

        $filter = $burner->getBurnInFilter($track);

        // ASS format uses 'ass=' filter directly
        $this->assertStringContainsString('ass=', $filter);
        $this->assertStringContainsString('/var/subtitles/movie.ass', $filter);
    }

    public function test_get_burn_in_filter_srt(): void
    {
        $ffmpeg = $this->createMockFfmpegRunner();
        $burner = new SubtitleBurner($ffmpeg);

        $track = new SubtitleTrack(
            index: '2',
            language: 'eng',
            label: 'English',
            format: SubtitleFormat::SRT,
            path: '/var/subtitles/movie.srt'
        );

        $filter = $burner->getBurnInFilter($track);

        $this->assertStringContainsString('subtitles=', $filter);
        $this->assertStringContainsString('/var/subtitles/movie.srt', $filter);
    }

    public function test_get_burn_in_filter_vtt(): void
    {
        $ffmpeg = $this->createMockFfmpegRunner();
        $burner = new SubtitleBurner($ffmpeg);

        $track = new SubtitleTrack(
            index: '2',
            language: 'eng',
            label: 'English',
            format: SubtitleFormat::VTT,
            path: '/var/subtitles/movie.vtt'
        );

        $filter = $burner->getBurnInFilter($track);

        $this->assertStringContainsString('subtitles=', $filter);
        $this->assertStringContainsString('/var/subtitles/movie.vtt', $filter);
    }

    public function test_get_burn_in_args_vaapi(): void
    {
        $ffmpeg = $this->createMockFfmpegRunner();
        $burner = new SubtitleBurner($ffmpeg);

        $track = new SubtitleTrack(
            index: '2',
            language: 'eng',
            label: 'English',
            format: SubtitleFormat::SRT,
            path: '/var/subtitles/movie.srt'
        );

        $args = $burner->getBurnInArgs($track, 'vaapi');

        $this->assertContains('-vf', $args);
        $this->assertStringContainsString('overlay_vaapi', implode(' ', $args));
        $this->assertContains('-vaapi_device', $args);
    }

    public function test_get_burn_in_args_nvenc_software_fallback(): void
    {
        $ffmpeg = $this->createMockFfmpegRunner();
        $burner = new SubtitleBurner($ffmpeg);

        $track = new SubtitleTrack(
            index: '2',
            language: 'eng',
            label: 'English',
            format: SubtitleFormat::ASS,
            path: '/var/subtitles/movie.ass'
        );

        $args = $burner->getBurnInArgs($track, 'nvenc');

        $this->assertContains('-vf', $args);
        $this->assertStringContainsString('subtitles=', implode(' ', $args));
        $this->assertStringContainsString('hwupload', implode(' ', $args));
    }

    public function test_get_burn_in_args_qsv(): void
    {
        $ffmpeg = $this->createMockFfmpegRunner();
        $burner = new SubtitleBurner($ffmpeg);

        $track = new SubtitleTrack(
            index: '2',
            language: 'eng',
            label: 'English',
            format: SubtitleFormat::SRT,
            path: '/var/subtitles/movie.srt'
        );

        $args = $burner->getBurnInArgs($track, 'qsv');

        $this->assertContains('-vf', $args);
        $this->assertStringContainsString('vpp=', implode(' ', $args));
        $this->assertContains('-qsv_device', $args);
    }

    public function test_get_burn_in_args_software(): void
    {
        $ffmpeg = $this->createMockFfmpegRunner();
        $burner = new SubtitleBurner($ffmpeg);

        $track = new SubtitleTrack(
            index: '2',
            language: 'eng',
            label: 'English',
            format: SubtitleFormat::SRT,
            path: '/var/subtitles/movie.srt'
        );

        $args = $burner->getBurnInArgs($track, 'software');

        $this->assertContains('-vf', $args);
        $this->assertStringContainsString('subtitles=', implode(' ', $args));
    }

    public function test_get_burn_in_args_videotoolbox_fallback(): void
    {
        $ffmpeg = $this->createMockFfmpegRunner();
        $burner = new SubtitleBurner($ffmpeg);

        $track = new SubtitleTrack(
            index: '2',
            language: 'eng',
            label: 'English',
            format: SubtitleFormat::SRT,
            path: '/var/subtitles/movie.srt'
        );

        $args = $burner->getBurnInArgs($track, 'videotoolbox');

        // VideoToolbox doesn't support hardware subtitle - should use software
        $this->assertContains('-vf', $args);
        $this->assertStringContainsString('subtitles=', implode(' ', $args));
    }

    public function test_extract_subtitle(): void
    {
        $ffmpeg = $this->createMockFfmpegRunner();
        $ffmpeg->expects($this->once())
            ->method('extractSubtitle')
            ->with('/input.mkv', '/output.srt', 2)
            ->willReturn(true);

        $burner = new SubtitleBurner($ffmpeg);

        $result = $burner->extractSubtitle('/input.mkv', 2, '/output.srt');

        $this->assertTrue($result);
    }

    public function test_extract_subtitle_failure(): void
    {
        $ffmpeg = $this->createMockFfmpegRunner();
        $ffmpeg->expects($this->once())
            ->method('extractSubtitle')
            ->willReturn(false);

        $burner = new SubtitleBurner($ffmpeg);

        $result = $burner->extractSubtitle('/input.mkv', 0, '/output.srt');

        $this->assertFalse($result);
    }

    public function test_factory_creates_correct_burner(): void
    {
        $ffmpeg = $this->createMockFfmpegRunner();
        $factory = new SubtitleBurnerFactory();

        $burner = $factory->createForVendor('nvenc', $ffmpeg);

        $this->assertInstanceOf(SubtitleBurner::class, $burner);

        // Different vendors should return same burner type (internal logic differs)
        $burnerVaapi = $factory->createForVendor('vaapi', $ffmpeg);
        $this->assertInstanceOf(SubtitleBurner::class, $burnerVaapi);

        $burnerSoftware = $factory->createForVendor('software', $ffmpeg);
        $this->assertInstanceOf(SubtitleBurner::class, $burnerSoftware);
    }
}
