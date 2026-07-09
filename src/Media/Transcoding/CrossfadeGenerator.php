<?php

/**
 * Phlix media server component: Crossfade segment generation.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Transcoding;

use Phlix\Media\Playback\PlaybackPreferences;

/**
 * Handles crossfade segment generation for playlist transitions.
 *
 * Crossfade mixing combines the end of one track with the beginning of the
 * next, creating a smooth audio transition. This class builds FFmpeg commands
 * that generate crossfade segments using the `acrossfade` filter.
 *
 * ## Crossfade modes supported:
 *
 * 1. **Direct crossfade** (`buildCrossfadeCommand`): Two full tracks are mixed
 *    into a single output using FFmpeg's `acrossfade` filter. The duration of
 *    the crossfade is controlled by the `$crossfadeDuration` parameter.
 *
 * 2. **Tail crossfade segment** (`buildTailCrossfadeSegment`): Extracts the
 *    tail of track A (the part that will be mixed) and encodes it with a
 *    fade-out effect. Used when the player is performing crossfade in real-time.
 *
 * 3. **Head crossfade segment** (`buildHeadCrossfadeSegment`): Extracts the
 *    head of track B (the part that will be mixed) and encodes it with a
 *    fade-in effect. Used when the player is performing crossfade in real-time.
 *
 * ## FFmpeg acrossfade filter:
 *
 * The `acrossfade` filter syntax is:
 * ```
 * acrossfade=d=C1:CA1:CI1:C2:CA2:CI2
 * d     - crossfade duration (seconds)
 * CA1   - fade-out track input for first segment
 * CI1   - fade-out input custom start point (pts)
 * CA2   - fade-in track input for first segment
 * CI2   - fade-in input custom start point (pts)
 * ```
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Crossfade segment generation for playlist transitions
 *
 * @see PlaybackPreferences For crossfade configuration
 * @see GaplessTranscoder For gapless transcoding operations
 */
class CrossfadeGenerator
{
    /**
     * Minimum crossfade duration in seconds.
     */
    public const int MIN_CROSSFADE_DURATION = 1;

    /**
     * Maximum crossfade duration in seconds (5 minutes).
     */
    public const int MAX_CROSSFADE_DURATION = 300;

    /**
     * Default crossfade duration in seconds.
     */
    public const int DEFAULT_CROSSFADE_DURATION = 5;

    /**
     * @var FfmpegRunner FFmpeg runner for command execution
     */
    private FfmpegRunner $ffmpegRunner;

    /**
     * Create a new CrossfadeGenerator.
     *
     * @param FfmpegRunner $ffmpegRunner FFmpeg runner instance
     */
    public function __construct(FfmpegRunner $ffmpegRunner)
    {
        $this->ffmpegRunner = $ffmpegRunner;
    }

    /**
     * Build a direct crossfade command mixing two tracks.
     *
     * Takes two input tracks and produces a single output where the end of
     * track A is crossfaded into the beginning of track B. The crossfade
     * duration is taken from the `$crossfadeDuration` parameter.
     *
     * @param string               $trackAPath         Path to the first (outgoing) track
     * @param string               $trackBPath         Path to the second (incoming) track
     * @param string               $outputPath          Path for the crossfaded output
     * @param int                  $crossfadeDuration  Duration of the crossfade in seconds
     * @param array<string, mixed> $params             Additional encoding parameters
     *
     * @return string Complete FFmpeg acrossfade command
     */
    public function buildCrossfadeCommand(
        string $trackAPath,
        string $trackBPath,
        string $outputPath,
        int $crossfadeDuration,
        array $params = []
    ): string {
        $duration = max(self::MIN_CROSSFADE_DURATION, min(self::MAX_CROSSFADE_DURATION, $crossfadeDuration));

        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error',
            escapeshellarg($this->ffmpegRunner->getFfmpegPath())
        );

        // First input: outgoing track
        $cmd .= ' -i ' . escapeshellarg($trackAPath);
        // Second input: incoming track
        $cmd .= ' -i ' . escapeshellarg($trackBPath);

        // Build the acrossfade filter
        // d=N: duration of crossfade
        // ds=0: start the crossfade at the end of track A (position 0 from end)
        // of=N: overlap duration factor (1.0 = full overlap)
        $crossfadeFilter = sprintf(
            'acrossfade=d=%d:ds=0:of=1',
            $duration
        );

        // Apply video processing if present (typically just copy for crossfade)
        $videoCodec = FfmpegRunner::paramString($params, 'video_codec') ?? 'copy';
        if ($videoCodec !== 'copy') {
            $cmd .= ' -c:v ' . $videoCodec;
            if ($videoCodec === 'libx264' || $videoCodec === 'libx265') {
                $cmd .= ' -preset ' . (FfmpegRunner::paramString($params, 'preset') ?? 'fast');
            }
        } else {
            $cmd .= ' -c:v copy';
        }

        // Apply audio with the crossfade filter
        $audioCodec = FfmpegRunner::paramString($params, 'audio_codec') ?? 'aac';
        if ($audioCodec === 'copy') {
            // Cannot use acrossfade with copy, must re-encode
            $audioCodec = 'aac';
        }

        $cmd .= ' -c:a ' . $audioCodec;
        $cmd .= ' -af "' . $crossfadeFilter . '"';
        $cmd .= ' -b:a ' . (FfmpegRunner::paramString($params, 'audio_bitrate') ?? '320k');
        $cmd .= ' -ar ' . (FfmpegRunner::paramInt($params, 'audio_sample_rate') ?? '48000');

        // Output format
        $format = FfmpegRunner::paramString($params, 'format') ?? 'mp3';
        $cmd .= ' -f ' . $format;

        $cmd .= ' ' . escapeshellarg($outputPath);

        return $cmd;
    }

    /**
     * Build a crossfade transition using two-track filter graph.
     *
     * This method provides more control over the crossfade than buildCrossfadeCommand,
     * allowing separate fade curves for the out track and in track.
     *
     * @param string               $trackAPath        Path to the first (outgoing) track
     * @param string               $trackBPath        Path to the second (incoming) track
     * @param string               $outputPath        Path for the crossfaded output
     * @param PlaybackPreferences  $preferences       Crossfade preferences
     * @param array<string, mixed> $params            Additional encoding parameters
     *
     * @return string Complete FFmpeg filter graph crossfade command
     */
    public function buildCrossfadeWithPreferences(
        string $trackAPath,
        string $trackBPath,
        string $outputPath,
        PlaybackPreferences $preferences,
        array $params = []
    ): string {
        if (!$preferences->isCrossfadeEnabled()) {
            // No crossfade needed - just copy both tracks sequentially
            return $this->buildConcatCommand($trackAPath, $trackBPath, $outputPath, $params);
        }

        $duration = $preferences->crossfadeDuration;
        $fadeOutDuration = $preferences->fadeOutDuration();
        $fadeInDuration = $preferences->fadeInDuration();

        // Duration must be at least 1 second for meaningful crossfade
        if ($duration < self::MIN_CROSSFADE_DURATION) {
            return $this->buildConcatCommand($trackAPath, $trackBPath, $outputPath, $params);
        }

        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error',
            escapeshellarg($this->ffmpegRunner->getFfmpegPath())
        );

        // Input tracks
        $cmd .= ' -i ' . escapeshellarg($trackAPath);
        $cmd .= ' -i ' . escapeshellarg($trackBPath);

        // Build filter complex for crossfade
        // Track A gets fade-out over its final fadeOutDuration seconds
        // Track B gets fade-in over its first fadeInDuration seconds
        // The two are mixed together during the overlap period
        $filterComplex = $this->buildCrossfadeFilterComplex($duration, $fadeOutDuration, $fadeInDuration);
        $cmd .= ' -filter_complex ' . escapeshellarg($filterComplex);

        // Video handling (typically copy)
        $videoCodec = FfmpegRunner::paramString($params, 'video_codec') ?? 'copy';
        if ($videoCodec !== 'copy') {
            $cmd .= ' -c:v ' . $videoCodec;
        } else {
            $cmd .= ' -c:v copy';
        }

        // Audio encoding
        $audioCodec = FfmpegRunner::paramString($params, 'audio_codec') ?? 'aac';
        if ($audioCodec === 'copy') {
            $audioCodec = 'aac';
        }
        $cmd .= ' -c:a ' . $audioCodec;
        $cmd .= ' -b:a ' . (FfmpegRunner::paramString($params, 'audio_bitrate') ?? '320k');
        $cmd .= ' -ar ' . (FfmpegRunner::paramInt($params, 'audio_sample_rate') ?? '48000');

        // Output format
        $format = FfmpegRunner::paramString($params, 'format') ?? 'mp3';
        $cmd .= ' -f ' . $format;

        $cmd .= ' ' . escapeshellarg($outputPath);

        return $cmd;
    }

    /**
     * Build a tail crossfade segment for track A (fade-out portion).
     *
     * Extracts the final portion of track A that will be used in the crossfade
     * and applies a fade-out effect. This is used when the player performs
     * crossfade in real-time by playing the pre-generated segments.
     *
     * @param string               $inputPath         Path to track A
     * @param string               $outputPath        Path for the tail segment
     * @param int                  $crossfadeDuration Duration of the crossfade in seconds
     * @param float                $fadeOutFraction   Fraction of crossfade for fade-out (0.0-1.0)
     * @param array<string, mixed> $params            Encoding parameters
     *
     * @return string Complete FFmpeg tail segment command
     */
    public function buildTailCrossfadeSegment(
        string $inputPath,
        string $outputPath,
        int $crossfadeDuration,
        float $fadeOutFraction,
        array $params = []
    ): string {
        $duration = max(self::MIN_CROSSFADE_DURATION, min(self::MAX_CROSSFADE_DURATION, $crossfadeDuration));
        $fadeOutSeconds = $duration * max(0.0, min(1.0, $fadeOutFraction));

        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error',
            escapeshellarg($this->ffmpegRunner->getFfmpegPath())
        );

        // Get duration of input for accurate tail extraction
        $probe = $this->ffmpegRunner->probe($inputPath);
        $inputDuration = 0.0;
        if (is_array($probe) && is_numeric($probe['format']['duration'] ?? null)) {
            $inputDuration = (float) $probe['format']['duration'];
        }

        // Seek to start of crossfade region (duration before track end)
        $seekPosition = max(0.0, $inputDuration - $duration);
        $cmd .= ' -ss ' . self::formatSeconds($seekPosition);
        $cmd .= ' -i ' . escapeshellarg($inputPath);

        // Encode the tail portion
        $cmd .= ' -t ' . self::formatSeconds($duration);

        // Map streams
        $cmd .= ' -map 0:v:0? -map 0:a:0? -dn -sn';

        // Video codec
        $videoCodec = FfmpegRunner::paramString($params, 'video_codec') ?? 'copy';
        $cmd .= ' -c:v ' . $videoCodec;

        // Audio with fade-out filter
        $audioCodec = FfmpegRunner::paramString($params, 'audio_codec') ?? 'aac';
        if ($audioCodec === 'copy') {
            $audioCodec = 'aac';
        }
        $cmd .= ' -c:a ' . $audioCodec;

        // Apply fade-out filter over the fadeOutSeconds portion
        // The fade starts at the beginning and completes before crossfade end
        $fadeFilter = sprintf('afade=t=out:st=0:d=%.3f', $fadeOutSeconds);
        $cmd .= ' -af ' . escapeshellarg($fadeFilter);

        $cmd .= ' -b:a ' . (FfmpegRunner::paramString($params, 'audio_bitrate') ?? '320k');
        $cmd .= ' -ar ' . (FfmpegRunner::paramInt($params, 'audio_sample_rate') ?? '48000');

        // Output
        $cmd .= ' -f mpegts ' . escapeshellarg($outputPath);

        return $cmd;
    }

    /**
     * Build a head crossfade segment for track B (fade-in portion).
     *
     * Extracts the initial portion of track B that will be used in the crossfade
     * and applies a fade-in effect. This is used when the player performs
     * crossfade in real-time by playing the pre-generated segments.
     *
     * @param string               $inputPath         Path to track B
     * @param string               $outputPath        Path for the head segment
     * @param int                  $crossfadeDuration Duration of the crossfade in seconds
     * @param float                $fadeInFraction    Fraction of crossfade for fade-in (0.0-1.0)
     * @param array<string, mixed> $params            Encoding parameters
     *
     * @return string Complete FFmpeg head segment command
     */
    public function buildHeadCrossfadeSegment(
        string $inputPath,
        string $outputPath,
        int $crossfadeDuration,
        float $fadeInFraction,
        array $params = []
    ): string {
        $duration = max(self::MIN_CROSSFADE_DURATION, min(self::MAX_CROSSFADE_DURATION, $crossfadeDuration));
        $fadeInSeconds = $duration * max(0.0, min(1.0, $fadeInFraction));

        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error',
            escapeshellarg($this->ffmpegRunner->getFfmpegPath())
        );

        $cmd .= ' -i ' . escapeshellarg($inputPath);

        // Take only the first crossfadeDuration seconds
        $cmd .= ' -t ' . self::formatSeconds($duration);

        // Map streams
        $cmd .= ' -map 0:v:0? -map 0:a:0? -dn -sn';

        // Video codec
        $videoCodec = FfmpegRunner::paramString($params, 'video_codec') ?? 'copy';
        $cmd .= ' -c:v ' . $videoCodec;

        // Audio with fade-in filter
        $audioCodec = FfmpegRunner::paramString($params, 'audio_codec') ?? 'aac';
        if ($audioCodec === 'copy') {
            $audioCodec = 'aac';
        }
        $cmd .= ' -c:a ' . $audioCodec;

        // Apply fade-in filter over the fadeInSeconds portion
        // The fade starts at the beginning and completes within the crossfade window
        $fadeFilter = sprintf('afade=t=in:st=0:d=%.3f', $fadeInSeconds);
        $cmd .= ' -af ' . escapeshellarg($fadeFilter);

        $cmd .= ' -b:a ' . (FfmpegRunner::paramString($params, 'audio_bitrate') ?? '320k');
        $cmd .= ' -ar ' . (FfmpegRunner::paramInt($params, 'audio_sample_rate') ?? '48000');

        // Output
        $cmd .= ' -f mpegts ' . escapeshellarg($outputPath);

        return $cmd;
    }

    /**
     * Build a simple concatenation command for sequential track playback.
     *
     * Used when crossfade is disabled or too short to be meaningful.
     *
     * @param string               $trackAPath  Path to track A
     * @param string               $trackBPath  Path to track B
     * @param string               $outputPath  Path for concatenated output
     * @param array<string, mixed> $params      Encoding parameters
     *
     * @return string Complete FFmpeg concat command
     */
    public function buildConcatCommand(
        string $trackAPath,
        string $trackBPath,
        string $outputPath,
        array $params = []
    ): string {
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error',
            escapeshellarg($this->ffmpegRunner->getFfmpegPath())
        );

        $cmd .= ' -i ' . escapeshellarg($trackAPath);
        $cmd .= ' -i ' . escapeshellarg($trackBPath);

        // Use concat filter with implicit stream duplication
        $cmd .= ' -filter_complex "[0:a][1:a]concat=n=2:v=0:a=1[outa]"';
        $cmd .= ' -map "[outa]"';

        $audioCodec = FfmpegRunner::paramString($params, 'audio_codec') ?? 'aac';
        if ($audioCodec === 'copy') {
            $audioCodec = 'aac';
        }
        $cmd .= ' -c:a ' . $audioCodec;
        $cmd .= ' -b:a ' . (FfmpegRunner::paramString($params, 'audio_bitrate') ?? '320k');
        $cmd .= ' -ar ' . (FfmpegRunner::paramInt($params, 'audio_sample_rate') ?? '48000');

        $format = FfmpegRunner::paramString($params, 'format') ?? 'mp3';
        $cmd .= ' -f ' . $format;

        $cmd .= ' ' . escapeshellarg($outputPath);

        return $cmd;
    }

    /**
     * Build the crossfade filter complex for use with -filter_complex.
     *
     * Creates a filter graph that:
     * 1. Applies fade-out to track A over the fadeOutDuration
     * 2. Applies fade-in to track B over the fadeInDuration
     * 3. Mixes both during the overlap period
     *
     * @param int    $crossfadeDuration Total crossfade duration in seconds
     * @param float  $fadeOutDuration   Duration of fade-out for track A
     * @param float  $fadeInDuration    Duration of fade-in for track B
     *
     * @return string Filter complex string
     */
    private function buildCrossfadeFilterComplex(int $crossfadeDuration, float $fadeOutDuration, float $fadeInDuration): string
    {
        $filters = [];

        // Track A: apply fade-out
        // The fade starts at the beginning and completes before crossfade ends
        if ($fadeOutDuration > 0) {
            $filters[] = sprintf('[0:a]afade=t=out:st=0:d=%.3f[a_out]', $fadeOutDuration);
        } else {
            $filters[] = '[0:a][a_out]';
        }

        // Track B: apply fade-in
        // The fade starts at the beginning and completes within crossfade window
        if ($fadeInDuration > 0) {
            $filters[] = sprintf('[1:a]afade=t=in:st=0:d=%.3f[a_in]', $fadeInDuration);
        } else {
            $filters[] = '[1:a][a_in]';
        }

        // Mix both tracks during the crossfade window
        $overlapDuration = min($fadeOutDuration, $fadeInDuration);
        if ($overlapDuration > 0) {
            // Mix with adjustable overlap duration
            $filters[] = sprintf('[a_out][a_in]amix=inputs=2:duration=first:dropout_transition=2[outa]');
        } else {
            // No overlap - just concatenate
            $filters[] = '[a_out][a_in]concat=n=2:v=0:a=1[outa]';
        }

        return implode(';', $filters);
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

    /**
     * Build a crossfade segment from two pre-encoded segments.
     *
     * When both the tail segment of track A and head segment of track B
     * are already encoded (with appropriate fades), this command mixes
     * them together into a single crossfade segment.
     *
     * @param string               $segmentAPath  Path to track A's crossfade segment
     * @param string               $segmentBPath  Path to track B's crossfade segment
     * @param string               $outputPath    Path for the mixed crossfade output
     * @param array<string, mixed> $params        Encoding parameters
     *
     * @return string Complete FFmpeg mix command
     */
    public function buildSegmentMixCommand(
        string $segmentAPath,
        string $segmentBPath,
        string $outputPath,
        array $params = []
    ): string {
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error',
            escapeshellarg($this->ffmpegRunner->getFfmpegPath())
        );

        $cmd .= ' -i ' . escapeshellarg($segmentAPath);
        $cmd .= ' -i ' . escapeshellarg($segmentBPath);

        // Mix the two segments
        $cmd .= ' -filter_complex "[0:a][1:a]amix=inputs=2:duration=first:dropout_transition=2[outa]"';
        $cmd .= ' -map "[outa]"';

        $audioCodec = FfmpegRunner::paramString($params, 'audio_codec') ?? 'aac';
        if ($audioCodec === 'copy') {
            $audioCodec = 'aac';
        }
        $cmd .= ' -c:a ' . $audioCodec;
        $cmd .= ' -b:a ' . (FfmpegRunner::paramString($params, 'audio_bitrate') ?? '320k');
        $cmd .= ' -ar ' . (FfmpegRunner::paramInt($params, 'audio_sample_rate') ?? '48000');

        $cmd .= ' -f mpegts ' . escapeshellarg($outputPath);

        return $cmd;
    }
}
