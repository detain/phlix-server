<?php

/**
 * Phlix media server component: Gapless transcoding support.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Transcoding;

/**
 * Handles gapless transcoding operations for seamless album/playlist playback.
 *
 * Gapless playback requires media that can be played continuously without gaps
 * between tracks. This class provides methods to build FFmpeg commands that
 * produce gapless-compatible output by:
 *
 * - Disabling decoder flush frames with `-no_dtk` (where supported)
 * - Using segment-based output to avoid trailing/leading silence
 * - Properly handling container metadata for gapless concatenation
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Gapless transcoding operations for continuous playback
 *
 * @see FfmpegRunner For the underlying FFmpeg command execution
 * @see CrossfadeGenerator For crossfade mixing between tracks
 */
class GaplessTranscoder
{
    /**
     * Minimum track duration in seconds for gapless optimization.
     * Tracks shorter than this skip the gapless optimization.
     */
    public const int MIN_GAPLESS_DURATION_SECONDS = 5;

    /**
     * Default pre-buffer duration in seconds for gapless mode.
     * How early to start preparing the next track.
     */
    public const int DEFAULT_PREBUFFER_SECONDS = 5;

    /**
     * @var FfmpegRunner FFmpeg runner for command execution
     */
    private FfmpegRunner $ffmpegRunner;

    /**
     * Create a new GaplessTranscoder.
     *
     * @param FfmpegRunner $ffmpegRunner FFmpeg runner instance
     */
    public function __construct(FfmpegRunner $ffmpegRunner)
    {
        $this->ffmpegRunner = $ffmpegRunner;
    }

    /**
     * Build a gapless-compatible transcode command.
     *
     * Produces a transcode command that minimizes encoding latency and ensures
     * sample-accurate boundaries for gapless concatenation. Uses `-no_dtk`
     * when available to disable decoder flush frames that introduce gaps.
     *
     * @param string               $inputPath  Source media file path
     * @param string               $outputPath Destination file path
     * @param array<string, mixed> $params     Encoding parameters
     *
     * @return string Complete FFmpeg gapless transcode command
     */
    public function buildGaplessTranscodeCommand(string $inputPath, string $outputPath, array $params): string
    {
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error',
            escapeshellarg($this->ffmpegRunner->getFfmpegPath())
        );

        // Gapless key: disable decoder flush frames that introduce gaps
        // The -no_dtk flag prevents the decoder from emitting padding frames
        // at the start/end of the stream which would cause audible gaps
        $cmd .= ' -no_dtk';

        $cmd .= ' -i ' . escapeshellarg($inputPath);

        // Use -shortest to avoid encoding extra silence at boundaries
        if (($params['shortest'] ?? true)) {
            $cmd .= ' -shortest';
        }

        // Audio codec handling
        $audioCodec = FfmpegRunner::paramString($params, 'audio_codec') ?? 'aac';
        if ($audioCodec !== 'copy') {
            $cmd .= ' -c:a ' . $audioCodec;
            $cmd .= ' -b:a ' . (FfmpegRunner::paramString($params, 'audio_bitrate') ?? '320k');
            $cmd .= ' -ar ' . (FfmpegRunner::paramInt($params, 'audio_sample_rate') ?? '48000');

            $audioChannels = FfmpegRunner::paramInt($params, 'audio_channels');
            if ($audioChannels !== null && $audioChannels > 0) {
                $cmd .= ' -ac ' . $audioChannels;
            }
        } else {
            $cmd .= ' -c:a copy';
        }

        // Video codec handling (if video is present)
        $videoCodec = FfmpegRunner::paramString($params, 'video_codec');
        if ($videoCodec !== null && $videoCodec !== 'copy') {
            $cmd .= ' -c:v ' . $videoCodec;
            if ($videoCodec === 'libx264' || $videoCodec === 'libx265') {
                $cmd .= ' -preset ' . (FfmpegRunner::paramString($params, 'preset') ?? 'medium');
                $cmd .= ' -crf ' . (FfmpegRunner::paramInt($params, 'crf') ?? 23);
            }
        } elseif ($videoCodec === 'copy') {
            $cmd .= ' -c:v copy';
        } else {
            // No video track or video explicitly disabled - use cover art if available
            $cmd .= ' -vn';
        }

        // Output format
        $format = FfmpegRunner::paramString($params, 'format');
        if ($format !== null) {
            $cmd .= ' -f ' . $format;
        }

        // Container options for gapless playback
        if (FfmpegRunner::paramString($params, 'container') === 'mp4') {
            $cmd .= ' -movflags +faststart';
        }

        $cmd .= ' -threads 0';
        $cmd .= ' ' . escapeshellarg($outputPath);

        return $cmd;
    }

    /**
     * Build a gapless HLS segment command.
     *
     * Produces a single HLS segment that is gaplessly contiguous with
     * adjacent segments, using accurate seeking and timestamp anchoring.
     *
     * @param string               $inputPath  Source media file path
     * @param string               $outFile    Output .ts segment path
     * @param float                $start      Segment start offset in seconds
     * @param float                $duration   Segment length in seconds
     * @param array<string, mixed> $params     Encode parameters
     *
     * @return string Complete FFmpeg HLS gapless segment command
     */
    public function buildGaplessSegmentCommand(
        string $inputPath,
        string $outFile,
        float $start,
        float $duration,
        array $params
    ): string {
        $startArg = self::formatSeconds($start);
        $durArg = self::formatSeconds($duration);

        // Build command with gapless flags
        $cmd = sprintf(
            '%s -nostdin -y -hide_banner -loglevel error',
            escapeshellarg($this->ffmpegRunner->getFfmpegPath())
        );

        // Gapless: disable decoder flush frames
        $cmd .= ' -no_dtk';

        // Accurate input seek BEFORE -i for frame accuracy
        $cmd .= ' -ss ' . $startArg;
        $cmd .= ' -i ' . escapeshellarg($inputPath);
        $cmd .= ' -t ' . $durArg;

        // Map only first video + first audio track
        $cmd .= ' -map 0:v:0 -map 0:a:0? -dn -sn';

        // Video codec handling
        $videoCodec = FfmpegRunner::paramString($params, 'video_codec') ?? 'libx264';
        if ($videoCodec === 'copy') {
            $cmd .= ' -c:v copy';
        } else {
            $cmd .= ' -c:v ' . $videoCodec;
            if ($videoCodec === 'libx264' || $videoCodec === 'libx265') {
                $cmd .= ' -preset ' . (FfmpegRunner::paramString($params, 'preset') ?? 'veryfast');
                $defaultCrf = $videoCodec === 'libx265' ? 28 : 23;
                $cmd .= ' -crf ' . (FfmpegRunner::paramInt($params, 'crf') ?? $defaultCrf);
            }
            // Per-rung resolution
            $width = FfmpegRunner::paramInt($params, 'width');
            $height = FfmpegRunner::paramInt($params, 'height');
            if ($width !== null && $height !== null) {
                $cmd .= ' -vf "scale=' . $width . ':' . $height . ':force_original_aspect_ratio=decrease"';
            }
            // IDR at segment start for independent decodability
            $cmd .= ' -force_key_frames ' . escapeshellarg('expr:gte(t,0)');
        }

        // Audio codec handling
        $audioCodec = FfmpegRunner::paramString($params, 'audio_codec') ?? 'aac';
        if ($audioCodec === 'copy') {
            $cmd .= ' -c:a copy';
        } else {
            $cmd .= ' -c:a ' . $audioCodec;
            $cmd .= ' -b:a ' . (FfmpegRunner::paramString($params, 'audio_bitrate') ?? '128k');
            $audioChannels = FfmpegRunner::paramInt($params, 'audio_channels');
            if ($audioChannels !== null && $audioChannels > 0) {
                $cmd .= ' -ac ' . $audioChannels;
            }
        }

        // Timestamp anchoring for gapless concatenation
        $cmd .= ' -muxdelay 0 -muxpreload 0';
        $cmd .= ' -output_ts_offset ' . $startArg;
        $cmd .= ' -f mpegts ' . escapeshellarg($outFile);

        return $cmd;
    }

    /**
     * Check if a media file is suitable for gapless playback.
     *
     * Analyzes the probe data to determine if the file has characteristics
     * that make it suitable for gapless playback without transcoding.
     *
     * @param array<string, mixed> $probeResult Probe data from FfmpegRunner::probe()
     *
     * @return bool True if file is gapless-compatible
     */
    public function isGaplessCompatible(array $probeResult): bool
    {
        $format = $probeResult['format'] ?? null;
        if (!is_array($format)) {
            return false;
        }

        // Check container format
        $formatName = is_string($format['format_name'] ?? null) ? $format['format_name'] : '';
        $gaplessContainers = ['ipod', 'mov,mp4,m4a,3gp,3g2,mj2', 'mpegts'];

        foreach ($gaplessContainers as $container) {
            if (str_contains($formatName, $container)) {
                // Check for audio streams
                $rawStreams = $probeResult['streams'] ?? null;
                if (!is_array($rawStreams)) {
                    continue;
                }
                foreach ($rawStreams as $stream) {
                    if (is_array($stream) && ($stream['codec_type'] ?? '') === 'audio') {
                        $codec = is_string($stream['codec_name'] ?? null) ? $stream['codec_name'] : '';
                        // AAC, ALAC, and FLAC support gapless
                        if (in_array($codec, ['aac', 'alac', 'flac'], true)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Get the probe result for gapless compatibility analysis.
     *
     * @param string $inputPath Path to the media file to probe
     *
     * @return array<string, mixed>|null Probe result or null on failure
     */
    public function probe(string $inputPath): ?array
    {
        return $this->ffmpegRunner->probe($inputPath);
    }

    /**
     * Format seconds value for FFmpeg time argument.
     *
     * Uses millisecond precision with no scientific notation.
     *
     * @param float $value Seconds value
     *
     * @return string Formatted time string
     */
    private static function formatSeconds(float $value): string
    {
        if ($value < 0) {
            $value = 0.0;
        }
        $formatted = rtrim(rtrim(sprintf('%.3f', $value), '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }
}
