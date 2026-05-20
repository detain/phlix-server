<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Subtitles;

use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * Burns subtitles into video streams during transcoding.
 *
 * Detects subtitle streams from ffprobe output, extracts subtitle files,
 * and generates FFmpeg filter arguments for hardware-accelerated and
 * software subtitle burn-in across all supported vendors (NVENC, VAAPI,
 * QSV, VideoToolbox, AMF, V4L2, software).
 *
 * Not all vendors support hardware-accelerated subtitle rendering:
 * - NVENC: no native support - uses software subtitles filter then hwupload
 * - VAAPI: limited support via overlay_vaapi
 * - QSV: limited via vpp submodule=subtitle
 * - Others: software fallback only
 *
 * @since 0.11.0
 */
class SubtitleBurner
{
    /**
     * Creates a new SubtitleBurner.
     *
     * @param FfmpegRunner $ffmpeg FFmpeg runner for extraction commands
     *
     * @since 0.11.0
     */
    public function __construct(
        private readonly FfmpegRunner $ffmpeg
    ) {
    }

    /**
     * Detects all subtitle streams from an ffprobe result.
     *
     * Parses the ffprobe JSON output and returns an array of SubtitleTrack
     * objects for each subtitle stream found in the media file.
     *
     * @param array{
     *     streams?: array<int, array{
     *         index?: int|string,
     *         codec_type?: string,
     *         tags?: array<string, string>
     *     }>
     * } $probe_result ffprobe JSON result
     *
     * @return SubtitleTrack[] Array of detected subtitle tracks (empty if none)
     *
     * @since 0.11.0
     */
    public function detectSubtitleTracks(array $probe_result): array
    {
        $tracks = [];
        $streams = $probe_result['streams'] ?? [];

        if (!is_array($streams)) {
            return [];
        }

        foreach ($streams as $stream) {
            if (!is_array($stream)) {
                continue;
            }

            if (($stream['codec_type'] ?? '') !== 'subtitle') {
                continue;
            }

            $streamIndex = $stream['index'] ?? '0';
            $index = is_string($streamIndex) || is_int($streamIndex) ? (string) $streamIndex : '0';
            $rawTags = $stream['tags'] ?? null;
            /** @var array<string, string> $tags */
            $tags = is_array($rawTags) ? array_filter($rawTags, 'is_string') : [];
            $language = $tags['language'] ?? ($tags['LANGUAGE'] ?? 'und');
            $label = is_string($tags['title'] ?? null) && $tags['title'] !== ''
                ? $tags['title']
                : $this->formatLabel($language, $tags);
            $codecName = is_string($stream['codec_name'] ?? null) ? $stream['codec_name'] : 'srt';
            $format = $this->detectFormatFromCodec($codecName);

            $tracks[] = new SubtitleTrack(
                index: $index,
                language: $language,
                label: $label,
                format: $format,
                path: '' // Path not yet extracted
            );
        }

        return $tracks;
    }

    /**
     * Extracts a subtitle stream to a file on disk.
     *
     * Uses FFmpeg to copy the specified subtitle stream from the input
     * file and save it to the output path in the appropriate format.
     *
     * @param string $input_path Source video path
     * @param int $stream_index Subtitle stream index to extract
     * @param string $output_path Destination subtitle file path
     *
     * @return bool True if extraction succeeded
     *
     * @since 0.11.0
     */
    public function extractSubtitle(string $input_path, int $stream_index, string $output_path): bool
    {
        return $this->ffmpeg->extractSubtitle($input_path, $output_path, $stream_index);
    }

    /**
     * Returns the FFmpeg filter string for burning subtitles into video.
     *
     * Generates the appropriate filter graph based on subtitle format,
     * vendor capabilities, and style options. Different vendors require
     * different filter chains.
     *
     * @param SubtitleTrack $track Subtitle track to burn
     * @param array{
     *     font_name?: string,
     *     font_size?: int,
     *     primary_color?: string,
     *     outline_color?: string,
     *     outline_thickness?: int,
     *     position?: string,
     *     margin?: int
     * } $style_options Style overrides (uses defaults if not provided)
     *
     * @return string FFmpeg filter string (e.g., "subtitles='file.ass':force_style='...'")
     *
     * @since 0.11.0
     */
    public function getBurnInFilter(SubtitleTrack $track, array $style_options = []): string
    {
        $style = new SubtitleStyleOptions(
            font_name: $style_options['font_name'] ?? 'Arial',
            font_size: $style_options['font_size'] ?? 24,
            primary_color: $style_options['primary_color'] ?? '&H00FFFFFF',
            outline_color: $style_options['outline_color'] ?? '&H00000000',
            outline_thickness: $style_options['outline_thickness'] ?? 2,
            position: $style_options['position'] ?? 'bottom',
            margin: $style_options['margin'] ?? 10,
        );

        $escaped_path = escapeshellarg($track->path);

        // For ASS/SSA with advanced styles, use the ass filter
        if ($track->format === SubtitleFormat::ASS || $track->format === SubtitleFormat::SSA) {
            return sprintf("ass=%s", $escaped_path);
        }

        // For SRT and VTT, use the subtitles filter with optional force_style
        $filter = sprintf("subtitles=%s", $escaped_path);

        // Add force_style for SRT if we have style options (limited support)
        if ($track->format === SubtitleFormat::SRT) {
            $ass_style = $style->toAssStyle();
            if ($ass_style !== '') {
                $filter .= sprintf(":force_style='%s'", $ass_style);
            }
        }

        return $filter;
    }

    /**
     * Returns FFmpeg command arguments for burning a specific subtitle track.
     *
     * Handles both software (libass) and hardware (VAAPI/QSV/NVENC) subtitle
     * rendering. Hardware vendors have different capabilities:
     * - NVENC: software burn-in then hwupload to GPU
     * - VAAPI: overlay_vaapi in filter graph
     * - QSV: vpp submodule=subtitle (limited)
     * - Others: software fallback only
     *
     * @param SubtitleTrack $track Subtitle track to burn
     * @param string $vendor Hardware vendor ('nvenc', 'vaapi', 'qsv', 'videotoolbox', 'amf', 'v4l2', 'software')
     * @param array{
     *     font_name?: string,
     *     font_size?: int,
     *     primary_color?: string,
     *     outline_color?: string,
     *     outline_thickness?: int,
     *     position?: string,
     *     margin?: int
     * } $style_options Style overrides
     *
     * @return array<string> FFmpeg argument array for subtitle burn-in
     *
     * @since 0.11.0
     */
    public function getBurnInArgs(SubtitleTrack $track, string $vendor, array $style_options = []): array
    {
        $filter = $this->getBurnInFilter($track, $style_options);
        $escaped_path = escapeshellarg($track->path);

        return match ($vendor) {
            'nvenc' => [
                // NVENC has no native subtitle support - use software then upload
                '-vf', sprintf("subtitles=%s,hwupload=extra_hw_frames=4", $escaped_path),
            ],
            'vaapi' => [
                // VAAPI: overlay_vaapi for subtitle burn-in
                '-vf', sprintf("overlay_vaapi,format=nv12"),
                '-vaapi_device', '/dev/dri/renderD128',
            ],
            'qsv' => [
                // QSV: limited subtitle support via vpp submodule
                '-vf', sprintf("vpp=subtitle=%s", $escaped_path),
                '-qsv_device', '/dev/dri/renderD128',
            ],
            'videotoolbox', 'amf', 'v4l2' => [
                // These vendors don't support hardware subtitle - use software
                '-vf', $filter,
            ],
            default => [
                // Software fallback - full libass support
                '-vf', $filter,
            ],
        };
    }

    /**
     * Formats a display label for a subtitle track.
     *
     * @param string $language Language code
     * @param array<string, string> $tags Stream tags
     *
     * @return string Formatted label
     */
    private function formatLabel(string $language, array $tags): string
    {
        $language_names = [
            'eng' => 'English',
            'fra' => 'French',
            'spa' => 'Spanish',
            'deu' => 'German',
            'ita' => 'Italian',
            'por' => 'Portuguese',
            'rus' => 'Russian',
            'jpn' => 'Japanese',
            'kor' => 'Korean',
            'chi' => 'Chinese',
            'und' => 'Unknown',
        ];

        $lang_name = $language_names[$language] ?? strtoupper($language);
        $title = $tags['title'] ?? '';

        if ($title !== '') {
            return sprintf('%s (%s)', $lang_name, $title);
        }

        return $lang_name;
    }

    /**
     * Detects the subtitle format from a codec name.
     *
     * @param string $codec Codec name from ffprobe
     *
     * @return SubtitleFormat Detected format (defaults to SRT)
     *
     * @since 0.11.0
     */
    private function detectFormatFromCodec(string $codec): SubtitleFormat
    {
        return match (strtolower($codec)) {
            'srt' => SubtitleFormat::SRT,
            'ass', 'ssa' => SubtitleFormat::ASS,
            'webvtt', 'vtt' => SubtitleFormat::VTT,
            'hdmv_pgs_subtitle', 'pgs' => SubtitleFormat::HDMV,
            default => SubtitleFormat::SRT,
        };
    }
}
