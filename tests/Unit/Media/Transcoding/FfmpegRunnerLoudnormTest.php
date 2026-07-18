<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * SV-3.3 loudness normalization: unit-covers {@see FfmpegRunner::buildLoudnormFilter()}
 * and its wiring into the audio RE-ENCODE branch of
 * {@see FfmpegRunner::buildSegmentCommand()} (video+audio segments) and
 * {@see FfmpegRunner::buildAudioSegmentCommand()} (multi-audio audio-only segments).
 *
 * The filter is single-pass EBU R128: `loudnorm=I=…:LRA=…:TP=…`. It can only apply
 * to a genuine encode — a `-c:a copy` stream cannot be filtered — so these tests
 * also pin that copy audio and the absence of a target both leave the command
 * byte-clean of any `-af loudnorm`.
 */
class FfmpegRunnerLoudnormTest extends TestCase
{
    private function runner(): FfmpegRunner
    {
        return new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
    }

    /**
     * A spy runner whose needsToneMapping() short-circuits so the segment builder
     * never spawns a real ffprobe on the fake input path (the -vf tone-map fallback
     * is orthogonal to what these tests assert).
     */
    private function spyRunner(): ToneMapThreadingSpyRunner
    {
        return new ToneMapThreadingSpyRunner(null, false);
    }

    // ---- buildLoudnormFilter() units -------------------------------------

    public function testBuildLoudnormFilterReturnsNullWhenKeyAbsent(): void
    {
        $this->assertNull($this->runner()->buildLoudnormFilter([]));
        $this->assertNull($this->runner()->buildLoudnormFilter(['video_codec' => 'libx264']));
    }

    public function testBuildLoudnormFilterReturnsNullWhenNotArray(): void
    {
        $this->assertNull($this->runner()->buildLoudnormFilter(['loudnorm' => -16]));
        $this->assertNull($this->runner()->buildLoudnormFilter(['loudnorm' => 'I=-16']));
        $this->assertNull($this->runner()->buildLoudnormFilter(['loudnorm' => true]));
    }

    public function testBuildLoudnormFilterReturnsNullWhenIntegratedMissing(): void
    {
        // No 'I' target → nothing to normalize toward → null (not a bare filter).
        $this->assertNull($this->runner()->buildLoudnormFilter(['loudnorm' => ['LRA' => 11, 'TP' => -1.5]]));
    }

    public function testBuildLoudnormFilterRejectsNonNumericIntegrated(): void
    {
        $this->assertNull($this->runner()->buildLoudnormFilter(['loudnorm' => ['I' => 'loud']]));
        $this->assertNull($this->runner()->buildLoudnormFilter(['loudnorm' => ['I' => null]]));
    }

    public function testBuildLoudnormFilterEmitsIntegratedOnly(): void
    {
        $this->assertSame(
            'loudnorm=I=-16',
            $this->runner()->buildLoudnormFilter(['loudnorm' => ['I' => -16]])
        );
    }

    public function testBuildLoudnormFilterEmitsFullSinglePassTarget(): void
    {
        $this->assertSame(
            'loudnorm=I=-16:LRA=11:TP=-1.5',
            $this->runner()->buildLoudnormFilter(['loudnorm' => ['I' => -16, 'LRA' => 11, 'TP' => -1.5]])
        );
    }

    public function testBuildLoudnormFilterSkipsNonNumericLraAndTp(): void
    {
        // I is valid; a garbage LRA/TP is dropped rather than emitted verbatim.
        $this->assertSame(
            'loudnorm=I=-23',
            $this->runner()->buildLoudnormFilter(['loudnorm' => ['I' => -23, 'LRA' => 'wide', 'TP' => null]])
        );
    }

    public function testBuildLoudnormFilterEmitsSecondPassMeasuredValues(): void
    {
        $this->assertSame(
            'loudnorm=I=-16:LRA=11:TP=-1.5:measured_I=-19.5:measured_LRA=8.2:'
            . 'measured_TP=-2:measured_thresh=-30.1',
            $this->runner()->buildLoudnormFilter([
                'loudnorm' => [
                    'I' => -16,
                    'LRA' => 11,
                    'TP' => -1.5,
                    'measured_I' => -19.5,
                    'measured_LRA' => 8.2,
                    'measured_TP' => -2,
                    'measured_thresh' => -30.1,
                ],
            ])
        );
    }

    // ---- buildSegmentCommand() integration (video+audio segment) ---------

    public function testSegmentCommandEmitsLoudnormOnAudioReencode(): void
    {
        $cmd = $this->spyRunner()->buildSegmentCommand('/in.mkv', '/out/seg-00002.ts', 12.0, 6.0, [
            'video_codec' => 'libx264',
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
            'loudnorm' => ['I' => -16, 'LRA' => 11, 'TP' => -1.5],
        ]);

        $this->assertStringContainsString('-af "loudnorm=I=-16:LRA=11:TP=-1.5"', $cmd);
    }

    public function testSegmentCommandOmitsLoudnormWhenTargetAbsent(): void
    {
        $cmd = $this->spyRunner()->buildSegmentCommand('/in.mkv', '/out/seg-00002.ts', 12.0, 6.0, [
            'video_codec' => 'libx264',
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
        ]);

        $this->assertStringNotContainsString('-af', $cmd);
        $this->assertStringNotContainsString('loudnorm', $cmd);
    }

    public function testSegmentCommandCannotFilterCopiedAudio(): void
    {
        // A `-c:a copy` stream is unfiltered by design — even with a target set,
        // no `-af loudnorm` may be emitted (the copy branch is loudnorm-inert).
        $cmd = $this->spyRunner()->buildSegmentCommand('/in.mkv', '/out/seg-00002.ts', 12.0, 6.0, [
            'video_codec' => 'copy',
            'audio_codec' => 'copy',
            'loudnorm' => ['I' => -16, 'LRA' => 11, 'TP' => -1.5],
        ]);

        $this->assertStringContainsString('-c:a copy', $cmd);
        $this->assertStringNotContainsString('-af', $cmd);
    }

    // ---- buildAudioSegmentCommand() integration (audio-only segment) -----

    public function testAudioSegmentCommandEmitsLoudnorm(): void
    {
        $cmd = $this->runner()->buildAudioSegmentCommand('/in.mkv', '/out/seg-a1-00001.ts', 6.0, 6.0, [
            'audio_only' => true,
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
            'audio_stream_index' => 1,
            'loudnorm' => ['I' => -23, 'LRA' => 7, 'TP' => -2],
        ]);

        $this->assertStringContainsString('-af "loudnorm=I=-23:LRA=7:TP=-2"', $cmd);
        // Still a genuine -vn AAC audio-only encode.
        $this->assertStringContainsString('-vn', $cmd);
        $this->assertStringContainsString('-map 0:a:1', $cmd);
        $this->assertStringContainsString('-c:a aac', $cmd);
    }

    public function testAudioSegmentCommandOmitsLoudnormWhenTargetAbsent(): void
    {
        $cmd = $this->runner()->buildAudioSegmentCommand('/in.mkv', '/out/seg-a1-00001.ts', 6.0, 6.0, [
            'audio_only' => true,
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
            'audio_stream_index' => 1,
        ]);

        $this->assertStringNotContainsString('-af', $cmd);
        $this->assertStringNotContainsString('loudnorm', $cmd);
    }
}
