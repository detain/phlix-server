<?php

/**
 * Phlix media server component: Subtitles.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
     *         codec_name?: string,
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
     * Escapes a string for use in an FFmpeg filtergraph argument.
     *
     * FFmpeg filtergraphs use ':' to separate a filter name from its options
     * (and one option from the next) and do not use shell quoting. A bare,
     * unescaped ':' inside an option VALUE (e.g. a Windows-style path like
     * `C:\Users\...`, or any path containing a literal colon) is parsed by
     * FFmpeg as the start of the next `key=value` pair, corrupting the
     * filtergraph. escapeshellarg() is separately inappropriate because it
     * injects literal single quotes into the filtergraph, causing FFmpeg to
     * fail parsing.
     *
     * Per FFmpeg's filtergraph escaping rules, three characters are
     * backslash-escaped in this order (order matters — escaping '\' first
     * avoids double-escaping the backslashes just inserted by the later
     * steps): '\\' itself, then "'", then ':'.
     *
     * The `subtitles`/`ass` filters specifically parse their `filename`
     * argument TWICE: once by the general filtergraph option-value tokenizer
     * (the level this method implements), and a SECOND time internally by
     * the filter's own suboption parser (the same one used for
     * `original_size`/`fontsdir`/`force_style`, which also splits on ':').
     * A value that has only been escaped ONCE therefore still gets
     * mis-tokenized by that second, inner pass (verified empirically on this
     * box: a single escape round left `Unable to parse option value ...` /
     * `Error applying option 'original_size'` errors — the colon-bearing
     * path was split into two bogus positional filter options). Applying
     * this same escape a SECOND time (composing it with itself) round-trips
     * correctly through both parser passes and was confirmed, by actually
     * running `ffmpeg -vf "subtitles=<double-escaped colon/backslash/quote
     * path>"`, to reach the real path unmangled (exit 0, subtitle rendered
     * for a plain colon-bearing path; the expected `Unable to open <path>` /
     * `Could not create a libass track` terminal errors — not a parse error —
     * for a Windows-style/apostrophe path that doesn't exist on this Linux
     * box, i.e. parsing succeeded and only the file-open step legitimately
     * failed). See {@see getBurnInFilter()} / {@see getBurnInArgs()} — both
     * embed the returned value directly into a `subtitles=`/`ass=` argument,
     * so this double round is applied uniformly here rather than at each
     * call site.
     *
     * @param string $path The path to escape
     *
     * @return string The double filtergraph-escaped path (safe for the
     *                 `subtitles=`/`ass=` filename argument)
     *
     * @since 0.11.0
     */
    private function filtergraphEscape(string $path): string
    {
        return $this->filtergraphEscapeOnce($this->filtergraphEscapeOnce($path));
    }

    /**
     * A single round of FFmpeg's generic filtergraph value escaping: escape
     * '\\' then "'" then ':', in that order (escaping '\' first avoids
     * double-escaping the backslashes the later steps insert).
     *
     * @param string $value The raw value to escape
     *
     * @return string The once-escaped value
     *
     * @since 0.11.0
     */
    private function filtergraphEscapeOnce(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace("'", "\\'", $value);
        // ':' separates a filter's key=value options — an unescaped colon in the
        // value (e.g. a Windows drive letter) truncates/corrupts the filtergraph.
        $value = str_replace(':', '\\:', $value);
        return $value;
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
     * @return string FFmpeg filter string (e.g., "subtitles=file.ass:force_style='...'")
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

        $escaped_path = $this->filtergraphEscape($track->path);

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
                // force_style value is already 'escaped' via the ASS style format
                // which uses semicolon-separated key=value pairs with proper quoting
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
     * - VAAPI: subtitles via hwupload + libass + format conversion
     * - QSV: vpp subtitle (limited)
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
        $escaped_path = $this->filtergraphEscape($track->path);

        return match ($vendor) {
            'nvenc' => [
                // NVENC has no native subtitle support - burn subtitles in software
                // using libass, then upload to GPU for encoding
                '-vf', sprintf("subtitles=%s,hwupload=extra_hw_frames=4", $escaped_path),
            ],
            'vaapi' => [
                // VAAPI: burn subtitles in software using libass FIRST (a VAAPI
                // hardware surface cannot be processed by the software subtitles/
                // libass filter — hwupload must come AFTER the software filter, not
                // before it), then convert to NV12 and upload to the GPU surface for
                // VAAPI encoding. Mirrors the nvenc branch's ordering below
                // (software filter, then hwupload).
                '-vf', sprintf("subtitles=%s,format=nv12,hwupload", $escaped_path),
                '-vaapi_device', '/dev/dri/renderD128',
            ],
            'qsv' => [
                // QSV: limited subtitle support via vpp submodule
                // For full subtitle support, use software burn-in
                '-vf', sprintf("subtitles=%s", $escaped_path),
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
