<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding\Subtitles;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\Subtitles\SubtitleBurner;
use Phlix\Media\Transcoding\Subtitles\SubtitleBurnerFactory;
use Phlix\Media\Transcoding\Subtitles\SubtitleFormat;
use Phlix\Media\Transcoding\Subtitles\SubtitleStyleOptions;
use Phlix\Media\Transcoding\Subtitles\SubtitleTrack;

class SubtitleBurnerTest extends TestCase
{
    private function createMockFfmpegRunner(): FfmpegRunner&MockObject
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
        $args_str = implode(' ', $args);
        // VAAPI must burn subtitles in software (libass) BEFORE the frame is
        // uploaded to the hardware surface — a VAAPI hw surface cannot be
        // processed by the software subtitles filter. Assert the ORDER, not
        // just substring presence: subtitles=..., THEN format=nv12, THEN
        // hwupload (mirrors the nvenc branch's software-then-hwupload order).
        $this->assertStringContainsString('subtitles=', $args_str);
        $this->assertStringContainsString('format=nv12', $args_str);
        $this->assertStringContainsString('hwupload', $args_str);
        $subtitlesPos = strpos($args_str, 'subtitles=');
        $formatPos = strpos($args_str, 'format=nv12');
        $hwuploadPos = strpos($args_str, 'hwupload');
        $this->assertNotFalse($subtitlesPos);
        $this->assertNotFalse($formatPos);
        $this->assertNotFalse($hwuploadPos);
        $this->assertLessThan($formatPos, $subtitlesPos, 'subtitles= must come before format=nv12');
        $this->assertLessThan($hwuploadPos, $formatPos, 'format=nv12 must come before hwupload');
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
        // QSV uses software subtitles filter (vpp subtitle support is limited)
        $this->assertStringContainsString('subtitles=', implode(' ', $args));
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

    public function test_filtergraph_escaping_no_single_quotes(): void
    {
        $ffmpeg = $this->createMockFfmpegRunner();
        $burner = new SubtitleBurner($ffmpeg);

        // Path with spaces and special chars that would break shell quoting
        $track = new SubtitleTrack(
            index: '2',
            language: 'eng',
            label: 'English',
            format: SubtitleFormat::SRT,
            path: "/var/subtitles/movie's file.srt"
        );

        $filter = $burner->getBurnInFilter($track);

        // The filtergraph-escaped output should NOT contain literal single quotes
        // that would break FFmpeg's filtergraph parser
        $this->assertStringNotContainsString("'movie's file.srt'", $filter);
        // The apostrophe should be escaped as \' in the filtergraph
        $this->assertStringContainsString("\\'", $filter);
    }

    public function test_filtergraph_escaping_backslashes(): void
    {
        $ffmpeg = $this->createMockFfmpegRunner();
        $burner = new SubtitleBurner($ffmpeg);

        // Windows-style path with backslashes AND a colon (drive letter).
        // VTT (not SRT) is used so getBurnInFilter() does not append a
        // `:force_style='...'` suffix, keeping this an exact-match test of
        // ONLY the path-escaping behavior.
        $track = new SubtitleTrack(
            index: '2',
            language: 'eng',
            label: 'English',
            format: SubtitleFormat::VTT,
            path: 'C:\Users\Test\ subtitles\movie.srt'
        );

        $filter = $burner->getBurnInFilter($track);

        // Backslashes should be escaped.
        $this->assertStringContainsString('\\\\', $filter);

        // The drive-letter colon must ALSO be escaped — a bare, unescaped ':'
        // is parsed by FFmpeg's filtergraph tokenizer as the start of the
        // next option, corrupting the whole filter. Verified against real
        // ffmpeg: the pre-fix (bare-colon) filter failed with "Error applying
        // option 'original_size' to filter 'subtitles'" (the colon-bearing
        // path was split into two bogus positional filter options); the
        // fixed (escaped-colon) filter, run through real ffmpeg, reaches the
        // real path unmangled (exit 0 for a real colon-bearing directory).
        //
        // The `subtitles`/`ass` filters parse their filename argument TWICE
        // (once by the general filtergraph tokenizer, once more internally
        // by the filter's own suboption parser), so a correctly round-
        // tripping escape must apply FFmpeg's standard '\\'/"'"/':' escape
        // TWICE — i.e. each literal '\' in the source path becomes 4
        // backslashes, and each literal ':' becomes 3 backslashes + ':' —
        // NOT the naive single-escape shape (2 backslashes / 1 backslash +
        // ':') which still corrupts the filter as shown above. Build the
        // expected value programmatically (not hand-counted) to keep the
        // backslash arithmetic unambiguous.
        $expectedPath = 'C' . str_repeat('\\', 3) . ':'
            . str_repeat('\\', 4) . 'Users'
            . str_repeat('\\', 4) . 'Test'
            . str_repeat('\\', 4) . ' subtitles'
            . str_repeat('\\', 4) . 'movie.srt';
        $this->assertSame('subtitles=' . $expectedPath, $filter);
    }

    public function test_filtergraph_escaping_colon_only_path(): void
    {
        $ffmpeg = $this->createMockFfmpegRunner();
        $burner = new SubtitleBurner($ffmpeg);

        // A path with a bare colon and no backslashes at all (e.g. a Linux
        // directory name that happens to contain a literal ':'). VTT is used
        // (not SRT) so no `:force_style='...'` suffix is appended.
        $track = new SubtitleTrack(
            index: '2',
            language: 'eng',
            label: 'English',
            format: SubtitleFormat::VTT,
            path: '/var/subtitles/colon:dir/movie.srt'
        );

        $filter = $burner->getBurnInFilter($track);

        // A bare (unescaped) colon must not survive in the path segment.
        $this->assertStringNotContainsString('colon:dir', $filter);
        $expectedPath = '/var/subtitles/colon' . str_repeat('\\', 3) . ':dir/movie.srt';
        $this->assertSame('subtitles=' . $expectedPath, $filter);
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
