<?php

/**
 * Phlix media server component: Transcoding.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Transcoding;

use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\HwaccelCommandBuilder;
use Phlix\Media\Transcoding\Hwaccel\HwaccelProfileFactory;
use Phlix\Media\Transcoding\Hwaccel\HwaccelRegistry;
use Phlix\Media\Transcoding\Hwaccel\Profiles\HwaccelEncoderProfileInterface;
use Phlix\Media\Transcoding\HardwareAccelerator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * FFmpeg Runner - Executes FFmpeg and FFprobe commands for media transcoding.
 *
 * Provides a clean interface for probing media files and running transcode
 * operations with proper process management and error handling.
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @description FFmpeg/FFprobe process execution with command building and error handling
 * @see https://ffmpeg.org/documentation.html
 */
class FfmpegRunner
{
    /** @var string Path to FFmpeg binary */
    private string $ffmpegPath;

    /** @var string Path to FFprobe binary */
    private string $ffprobePath;

    /** @var string Default directory for transcoded output */
    private string $transcodeDir;

    /** @var LoggerInterface Logger instance */
    private LoggerInterface $logger;

    /** @var HwaccelRegistry|null Hardware acceleration registry */
    private ?HwaccelRegistry $hwaccelRegistry = null;

    /** @var bool Whether hardware acceleration has been probed */
    private bool $hwaccelProbed = false;

    /** @var array<string, HardwareAccelerator>|null Cached hardware accelerators */
    private ?array $hardwareAccelerators = null;

    /** @var string|null Preferred accelerator name from config (e.g., 'cuda', 'qsv') */
    private ?string $preferredAccelerator = null;

    /** @var array<mixed, mixed> Transcoding configuration */
    private array $config = [];

    /**
     * Creates a new FFmpegRunner instance.
     *
     * @param string $ffmpegPath Path to FFmpeg binary (default: /usr/bin/ffmpeg)
     * @param string $ffprobePath Path to FFprobe binary (default: /usr/bin/ffprobe)
     * @param string $transcodeDir Default output directory (default: /var/transcodes)
     * @param LoggerInterface|null $logger Optional PSR logger
     *
     * @example
     * ```php
     * $runner = new FfmpegRunner('/usr/local/bin/ffmpeg', '/usr/local/bin/ffprobe', '/tmp/transcodes');
     * ```
     */
    public function __construct(
        string $ffmpegPath = '/usr/bin/ffmpeg',
        string $ffprobePath = '/usr/bin/ffprobe',
        string $transcodeDir = '/var/transcodes',
        ?LoggerInterface $logger = null
    ) {
        $this->ffmpegPath = $ffmpegPath;
        $this->ffprobePath = $ffprobePath;
        $this->transcodeDir = $transcodeDir;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Returns the configured FFprobe binary path.
     *
     * @return string Absolute path to the ffprobe binary.
     *
     * @since 0.13.0
     */
    public function getFfprobePath(): string
    {
        return $this->ffprobePath;
    }

    /**
     * Probes a media file for technical information.
     *
     * Uses FFprobe to extract stream details and format information. The actual
     * command execution is delegated to {@see runProbeCommand()}, which is
     * coroutine-aware (non-blocking under Swoole, S6) — this method's parsing and
     * return shape are unaffected by that and have not changed.
     *
     * @param string $inputPath Path to the media file to probe
     *
     * @return array{
     *     streams: array<int, array<string, mixed>>,
     *     format: array<string, mixed>
     * }|null Probe results or null if probing fails
     *
     * @example
     * ```php
     * $info = $runner->probe('/path/to/video.mkv');
     * $videoStream = $info['streams'][0] ?? null;
     * ```
     */
    public function probe(string $inputPath): ?array
    {
        $cmd = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams %s 2>/dev/null',
            escapeshellarg($this->ffprobePath),
            escapeshellarg($inputPath)
        );

        $output = $this->runProbeCommand($cmd);
        if ($output === null || $output === '') {
            return null;
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            return null;
        }

        $rawStreams = $data['streams'] ?? [];
        $rawFormat = $data['format'] ?? [];
        if (!is_array($rawStreams) || !is_array($rawFormat)) {
            return null;
        }

        $streams = [];
        foreach ($rawStreams as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $normalized = [];
            foreach ($stream as $key => $value) {
                if (is_string($key)) {
                    $normalized[$key] = $value;
                }
            }
            $streams[] = $normalized;
        }

        $format = [];
        foreach ($rawFormat as $key => $value) {
            if (is_string($key)) {
                $format[$key] = $value;
            }
        }

        return [
            'streams' => $streams,
            'format' => $format,
        ];
    }

    /**
     * Executes an ffprobe command, coroutine-friendly under the Swoole runtime.
     *
     * The phlix server runs on Workerman with a Swoole coroutine event loop and a
     * deliberately curated hook mask ({@see \Phlix\Server\Runtime\SwooleRuntime})
     * that intentionally does NOT hook `proc_open`/`exec`/`shell_exec` — so a plain
     * `shell_exec()` here would block the whole worker (every concurrent connection)
     * for the full duration of the ffprobe process. That stall is exactly what S6
     * removes: on the hot paths (`ensureHlsJob` on play-start, the scanner per file)
     * ffprobe can take tens to hundreds of milliseconds.
     *
     * When running inside a coroutine, {@see \Swoole\Coroutine\System::exec()} is
     * used instead. It drives the child process through Swoole's async scheduler and
     * yields to the event loop while ffprobe runs, so other connections keep being
     * served. It is a native coroutine primitive and therefore works regardless of
     * the runtime hook mask (it does not rely on the `proc_open` hook being enabled).
     *
     * Outside a coroutine — the CLI scanner/backfill, unit tests, or any non-Swoole
     * runtime — it falls back to a blocking `shell_exec()`, which is correct there
     * (no event loop to stall). Both paths run the command via `/bin/sh -c`, so the
     * `escapeshellarg()`-quoted arguments and the `2>/dev/null` redirect behave
     * identically; only stdout (the ffprobe JSON) is captured.
     *
     * @param string $cmd Fully built, shell-escaped ffprobe command line.
     *
     * @return string|null Captured stdout, or null when execution failed.
     */
    private function runProbeCommand(string $cmd): ?string
    {
        if (
            extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0
        ) {
            /** @var array{code?: int, signal?: int, output?: string}|false $result */
            $result = \Swoole\Coroutine\System::exec($cmd);
            if (is_array($result) && isset($result['output']) && is_string($result['output'])) {
                return $result['output'];
            }
            return null;
        }

        $output = shell_exec($cmd);

        return is_string($output) ? $output : null;
    }

    /**
     * Builds a FFmpeg transcode command from parameters.
     *
     * Constructs a complete FFmpeg command with input, output, video codec,
     * audio codec, filters, and format options.
     *
     * @param string $inputPath Source file path
     * @param string $outputPath Destination file path
     * @param array<string, mixed> $params Encoding parameters
     *
     * @return string Complete FFmpeg command
     *
     * @example
     * ```php
     * $cmd = $runner->buildTranscodeCommand('/input.mkv', '/output.mp4', ['video_codec' => 'libx264']);
     * ```
     */
    public function buildTranscodeCommand(string $inputPath, string $outputPath, array $params): string
    {
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error',
            escapeshellarg($this->ffmpegPath)
        );

        $cmd .= ' -i ' . escapeshellarg($inputPath);

        $videoCodec = self::paramString($params, 'video_codec');
        if ($videoCodec !== null) {
            $cmd .= ' -c:v ' . $videoCodec;

            switch ($videoCodec) {
                case 'libx264':
                    $cmd .= ' -preset ' . (self::paramString($params, 'preset') ?? 'medium');
                    $cmd .= ' -crf ' . (self::paramInt($params, 'crf') ?? 23);
                    break;
                case 'libx265':
                    $cmd .= ' -preset ' . (self::paramString($params, 'preset') ?? 'medium');
                    $cmd .= ' -crf ' . (self::paramInt($params, 'crf') ?? 28);
                    break;
            }
        }

        $width = self::paramInt($params, 'width');
        $height = self::paramInt($params, 'height');

        // Build video filter chain: tone mapping + scaling
        $filters = [];

        // Check if tone mapping is needed and get the filter chain
        $videoCodecForTm = self::paramString($params, 'video_codec') ?? 'libx264';
        $toneMapFilter = $this->getToneMappingProfile($inputPath, $outputPath, $videoCodecForTm);
        if ($toneMapFilter !== null && $toneMapFilter !== '') {
            $filters[] = $toneMapFilter;
        }

        // Add scale filter if resolution is specified
        if ($width !== null && $height !== null) {
            $filters[] = "scale={$width}:{$height}:force_original_aspect_ratio=decrease";
        }

        if (!empty($filters)) {
            $cmd .= ' -vf "' . implode(',', $filters) . '"';
        }

        $audioCodec = self::paramString($params, 'audio_codec');
        if ($audioCodec !== null) {
            $cmd .= ' -c:a ' . $audioCodec;
            $cmd .= ' -b:a ' . (self::paramString($params, 'audio_bitrate') ?? '128k');
            $cmd .= ' -ar ' . (self::paramInt($params, 'audio_sample_rate') ?? 48000);

            $audioChannels = self::paramInt($params, 'audio_channels');
            if ($audioChannels !== null) {
                $cmd .= ' -ac ' . $audioChannels;
            }
        } else {
            $cmd .= ' -c:a copy';
        }

        $format = self::paramString($params, 'format');
        if ($format !== null) {
            $cmd .= ' -f ' . $format;
        }

        if (self::paramString($params, 'container') === 'mp4') {
            $cmd .= ' -movflags +faststart';
        }

        $cmd .= ' -threads 0';

        $cmd .= ' ' . escapeshellarg($outputPath);

        return $cmd;
    }

    /**
     * Checks if a media file requires HDR tone mapping.
     *
     * Probes the file's color metadata to determine if it contains
     * HDR content (HLG or HDR10) that needs tone mapping for SDR displays.
     *
     * @param string $inputPath Path to the media file to check
     *
     * @return bool True if the input is HDR (HLG or HDR10) and needs tone mapping
     *
     * @example
     * ```php
     * if ($runner->needsToneMapping('/path/to/hdr-video.mkv')) {
     *     // Apply tone mapping filter
     * }
     * ```
     *
     * @since 0.36.0
     */
    public function needsToneMapping(string $inputPath): bool
    {
        $probe = $this->probe($inputPath);
        if ($probe === null) {
            return false;
        }

        $colorMeta = $this->extractColorMetadata($probe);

        // HDR content is identified by bt2020 color space with HDR transfer functions
        // HLG: arib-std-b67 transfer
        // HDR10: smpte2084 (PQ) transfer
        $isHdr = in_array($colorMeta['color_transfer'], ['smpte2084', 'arib-std-b67'], true);
        $isBt2020 = $colorMeta['color_primaries'] === 'bt2020'
            || $colorMeta['color_space'] === 'bt2020nc'
            || $colorMeta['color_space'] === 'bt2020_ncl';

        return $isHdr && $isBt2020;
    }

    /**
     * Generates the HDR tone mapping filter profile for a transcode operation.
     *
     * For HDR content (HLG or HDR10), generates the appropriate zscale/tonemap
     * filter chain to convert to SDR. Returns null if no tone mapping is needed
     * or if tone mapping mode is disabled.
     *
     * The filter chain is determined by the configured tone_mapping_mode:
     *  - 'zscale': Uses zscale for CPU-based HDR→SDR conversion
     *  - 'libplacebo': Uses libplacebo for high-quality tone mapping (if available)
     *
     * @param string $inputPath  Source file path
     * @param string $outputPath Destination file path
     * @param string $codec      Video codec being used for encoding
     *
     * @return string|null FFmpeg video filter chain for tone mapping, or null if not needed
     *
     * @example
     * ```php
     * $toneMapFilter = $runner->getToneMappingProfile($input, $output, 'libx264');
     * if ($toneMapFilter !== null) {
     *     $cmd .= ' -vf "' . $toneMapFilter . '"';
     * }
     * ```
     *
     * @since 0.36.0
     */
    public function getToneMappingProfile(string $inputPath, string $outputPath, string $codec): ?string
    {
        if (!$this->needsToneMapping($inputPath)) {
            return null;
        }

        $probe = $this->probe($inputPath);
        if ($probe === null) {
            return null;
        }

        $colorMeta = $this->extractColorMetadata($probe);

        // Determine tone mapping mode from config (default: zscale)
        $toneMapMode = $this->config['tone_mapping_mode'] ?? 'zscale';

        // If prefer_hdr_output is true and codec supports HDR, return null
        // (output will be HDR10 instead of SDR tone mapping)
        if (($this->config['prefer_hdr_output'] ?? false) === true) {
            // Check if codec can handle HDR (hevc Main 10 or similar)
            if (in_array($codec, ['libx265', 'hevc_nvenc', 'hevc_vaapi', 'hevc_qsv'], true)) {
                return null;
            }
        }

        if ($toneMapMode === 'none') {
            return null;
        }

        // Generate the appropriate tone mapping filter chain
        return match ($toneMapMode) {
            'zscale' => $this->buildZscaleToneMapFilter($colorMeta),
            'libplacebo' => $this->buildLibplaceboToneMapFilter($colorMeta),
            default => null,
        };
    }

    /**
     * Builds a zscale-based tone mapping filter chain.
     *
     * @param array<string, mixed> $colorMeta Color metadata from extractColorMetadata()
     *
     * @return string FFmpeg filter chain for zscale tone mapping
     *
     * @since 0.36.0
     */
    private function buildZscaleToneMapFilter(array $colorMeta): string
    {
        // zscale transfer=bt709:matrix=bt709:primaries=bt709 converts HDR to SDR
        // Format=yuv420p ensures 8-bit 4:2:0 output for browser compatibility
        $filter = 'zscale=transfer=bt709:matrix=bt709:primaries=bt709,format=yuv420p';

        return $filter;
    }

    /**
     * Builds a libplacebo-based tone mapping filter chain.
     *
     * @param array<string, mixed> $colorMeta Color metadata from extractColorMetadata()
     *
     * @return string FFmpeg filter chain for libplacebo tone mapping
     *
     * @since 0.36.0
     */
    private function buildLibplaceboToneMapFilter(array $colorMeta): string
    {
        // libplacebo tonemap with hable curve for natural-looking tone mapping
        // Uses BT.2020 to BT.709 conversion with proper gamut mapping
        $filter = 'scale_vaapi=format=nv12,hwupload,tonemap_vaapi=transfer=bt2020:primaries=bt2020:tonemap=hable:desat=0.5';

        return $filter;
    }

    /**
     * Builds an FFmpeg command that muxes the input straight to HLS.
     *
     * Unlike {@see self::buildTranscodeCommand()} (single opaque output file),
     * this uses FFmpeg's native HLS muxer to write a variant playlist
     * (`stream_{variant}.m3u8`) and its mpegts segments
     * (`segment_{variant}_NNN.ts`) into $outDir — exactly what
     * {@see \Phlix\Server\Http\Controllers\HlsController} serves.
     *
     * Per-stream copy vs encode is the caller's decision via `video_codec` /
     * `audio_codec`: pass `'copy'` to remux a browser-compatible stream (e.g.
     * h264/aac already, just wrong container — fast, no CPU) or an encoder name
     * (`libx264`, `libx265`, `h264_nvenc`, …) to transcode.
     *
     * Recognized $params keys: variant_index, video_codec, audio_codec, width,
     * height, preset, crf, audio_bitrate, audio_channels, audio_sample_rate,
     * segment_seconds, playlist_type, start_number. All are read through the
     * type-safe paramString()/paramInt() accessors, so unknown or wrongly-typed
     * values are simply ignored.
     *
     * @param string               $inputPath Source media file path.
     * @param string               $outDir    Directory the playlist + segments are written to.
     * @param array<string, mixed> $params    Encoding / segmenting parameters (see above).
     *
     * @return string Complete FFmpeg HLS command.
     *
     * @since 0.23.0
     */
    public function buildHlsCommand(string $inputPath, string $outDir, array $params): string
    {
        $variant = self::paramInt($params, 'variant_index') ?? 0;
        $segSeconds = self::paramInt($params, 'segment_seconds') ?? 6;
        if ($segSeconds < 1) {
            $segSeconds = 6;
        }
        // Default to VOD: callers transcode fixed-length files, so the playlist
        // should be a closed VOD playlist (EXT-X-ENDLIST), not an open 'event'
        // stream that a player reads as live with an ever-growing duration.
        $playlistType = self::paramString($params, 'playlist_type') ?? 'vod';
        $startNumber = self::paramInt($params, 'start_number') ?? 0;

        $cmd = sprintf('%s -y -hide_banner -loglevel error', escapeshellarg($this->ffmpegPath));
        $cmd .= ' -i ' . escapeshellarg($inputPath);

        // Map ONLY the first video + first audio; drop data/subtitle/attachment
        // streams so font attachments and `bin_data` can't break the HLS mux
        // (see buildCmafCommand()).
        $cmd .= ' -map 0:v:0 -map 0:a:0? -map -0:t? -dn -sn';

        // Video: copy a compatible stream as-is, otherwise encode.
        $videoCodec = self::paramString($params, 'video_codec') ?? 'libx264';
        if ($videoCodec === 'copy') {
            $cmd .= ' -c:v copy';
        } else {
            $cmd .= ' -c:v ' . $videoCodec;
            if ($videoCodec === 'libx264' || $videoCodec === 'libx265') {
                $cmd .= ' -preset ' . (self::paramString($params, 'preset') ?? 'veryfast');
                $defaultCrf = $videoCodec === 'libx265' ? 28 : 23;
                $cmd .= ' -crf ' . (self::paramInt($params, 'crf') ?? $defaultCrf);
            }
            $width = self::paramInt($params, 'width');
            $height = self::paramInt($params, 'height');
            if ($width !== null && $height !== null) {
                $cmd .= ' -vf "scale=' . $width . ':' . $height . ':force_original_aspect_ratio=decrease"';
            }
            // Closed, fixed-size GOP so every segment can start on a keyframe and
            // play independently (independent_segments). sc_threshold 0 stops
            // scene-cut keyframes from producing uneven segment boundaries.
            $cmd .= ' -g 48 -keyint_min 48 -sc_threshold 0';
            // Force an 8-bit 4:2:0 H.264 profile so a 10-bit (High 10) source is
            // re-encoded into a stream browsers can actually decode.
            $cmd .= self::browserSafeVideoFlags($videoCodec, $params);
        }

        // Audio: copy AAC as-is, otherwise encode to AAC.
        $audioCodec = self::paramString($params, 'audio_codec') ?? 'aac';
        if ($audioCodec === 'copy') {
            $cmd .= ' -c:a copy';
        } else {
            $cmd .= ' -c:a ' . $audioCodec;
            $cmd .= ' -b:a ' . (self::paramString($params, 'audio_bitrate') ?? '128k');
            $cmd .= ' -ar ' . (self::paramInt($params, 'audio_sample_rate') ?? 48000);
            $audioChannels = self::paramInt($params, 'audio_channels');
            if ($audioChannels !== null) {
                $cmd .= ' -ac ' . $audioChannels;
            }
        }

        // HLS muxer — write the variant playlist + segments directly.
        $cmd .= ' -f hls';
        $cmd .= ' -hls_time ' . $segSeconds;
        $cmd .= ' -hls_playlist_type ' . escapeshellarg($playlistType);
        $cmd .= ' -hls_flags independent_segments';
        $cmd .= ' -hls_segment_type mpegts';
        $cmd .= ' -start_number ' . $startNumber;
        $cmd .= ' -hls_segment_filename ' . escapeshellarg($outDir . '/segment_' . $variant . '_%03d.ts');
        $cmd .= ' ' . escapeshellarg($outDir . '/stream_' . $variant . '.m3u8');

        return $cmd;
    }

    /**
     * Starts an HLS transcode as a detached background process.
     *
     * Convenience wrapper: builds the HLS command and launches it via
     * {@see self::startDetached()} so the caller returns immediately.
     *
     * @param string               $inputPath Source media file path.
     * @param string               $outDir    Output directory.
     * @param array<string, mixed> $params    Parameters for {@see self::buildHlsCommand()}.
     *
     * @return int OS process id of the launched job (0 if launch failed).
     *
     * @since 0.23.0
     */
    public function startHlsTranscode(string $inputPath, string $outDir, array $params): int
    {
        return $this->startDetached($this->buildHlsCommand($inputPath, $outDir, $params), $outDir);
    }

    /**
     * Builds an FFmpeg command that muxes the input to CMAF (fMP4) output that
     * serves BOTH DASH and HLS from a single encode.
     *
     * Uses FFmpeg's DASH muxer with `-hls_playlist 1`, so one pass writes:
     *   - `manifest.mpd`              (DASH manifest)
     *   - `master.m3u8` + `media_N.m3u8` (HLS master + media playlists, HLS v7 fMP4)
     *   - `init-N.m4s` + `chunk-N-NNNNN.m4s` (shared CMAF init + media segments)
     * All playlists/manifest reference segments by relative filename, so a generic
     * per-job file server delivers them with no URI rewriting.
     *
     * Per-stream copy vs encode follows the same `video_codec` / `audio_codec`
     * convention as {@see self::buildHlsCommand()} (`'copy'` to remux, an encoder
     * name to transcode).
     *
     * @param string               $inputPath Source media file path.
     * @param string               $outDir    Directory the manifest/playlists/segments are written to.
     * @param array<string, mixed> $params    Encoding / segmenting parameters (see buildHlsCommand()).
     *
     * @return string Complete FFmpeg CMAF command.
     *
     * @since 0.24.0
     */
    public function buildCmafCommand(string $inputPath, string $outDir, array $params): string
    {
        $segSeconds = self::paramInt($params, 'segment_seconds') ?? 6;
        if ($segSeconds < 1) {
            $segSeconds = 6;
        }

        $cmd = sprintf('%s -y -hide_banner -loglevel error', escapeshellarg($this->ffmpegPath));
        $cmd .= ' -i ' . escapeshellarg($inputPath);

        // Explicit mapping: ONLY the first video + the first audio track. Anime/TV
        // MKVs often carry embedded subtitle streams AND font ATTACHMENT / data
        // (`bin_data`) streams; if the DASH/CMAF muxer pulls any of those into the
        // A/V encode it produces extra representations or fails the mux outright.
        // `-dn -sn` drop data + subtitle streams and `-map -0:t?` excludes any
        // attachment so only the playable video+audio reach the CMAF output.
        $cmd .= ' -map 0:v:0 -map 0:a:0? -map -0:t? -dn -sn';

        // Video: copy a compatible stream as-is, otherwise encode.
        $videoCodec = self::paramString($params, 'video_codec') ?? 'libx264';
        if ($videoCodec === 'copy') {
            $cmd .= ' -c:v copy';
        } else {
            $cmd .= ' -c:v ' . $videoCodec;
            if ($videoCodec === 'libx264' || $videoCodec === 'libx265') {
                $cmd .= ' -preset ' . (self::paramString($params, 'preset') ?? 'veryfast');
                $defaultCrf = $videoCodec === 'libx265' ? 28 : 23;
                $cmd .= ' -crf ' . (self::paramInt($params, 'crf') ?? $defaultCrf);
            }
            $width = self::paramInt($params, 'width');
            $height = self::paramInt($params, 'height');
            if ($width !== null && $height !== null) {
                $cmd .= ' -vf "scale=' . $width . ':' . $height . ':force_original_aspect_ratio=decrease"';
            }
            // Closed, fixed-size GOP so segments are keyframe-aligned across both protocols.
            $cmd .= ' -g 48 -keyint_min 48 -sc_threshold 0';
            // Force an 8-bit 4:2:0 H.264 profile so a 10-bit (High 10) source is
            // re-encoded into a stream browsers can actually decode.
            $cmd .= self::browserSafeVideoFlags($videoCodec, $params);
        }

        // Audio: copy AAC as-is, otherwise encode to AAC.
        $audioCodec = self::paramString($params, 'audio_codec') ?? 'aac';
        if ($audioCodec === 'copy') {
            $cmd .= ' -c:a copy';
        } else {
            $cmd .= ' -c:a ' . $audioCodec;
            $cmd .= ' -b:a ' . (self::paramString($params, 'audio_bitrate') ?? '128k');
            $cmd .= ' -ar ' . (self::paramInt($params, 'audio_sample_rate') ?? 48000);
            $audioChannels = self::paramInt($params, 'audio_channels');
            if ($audioChannels !== null) {
                $cmd .= ' -ac ' . $audioChannels;
            }
        }

        // DASH muxer with HLS playlist generation — one encode, both protocols.
        $cmd .= ' -f dash';
        $cmd .= ' -seg_duration ' . $segSeconds;
        $cmd .= ' -use_template 1 -use_timeline 1';
        $cmd .= ' -init_seg_name ' . escapeshellarg('init-$RepresentationID$.m4s');
        $cmd .= ' -media_seg_name ' . escapeshellarg('chunk-$RepresentationID$-$Number%05d$.m4s');
        $cmd .= ' -hls_playlist 1 -hls_master_name master.m3u8';
        $cmd .= ' ' . escapeshellarg($outDir . '/manifest.mpd');

        return $cmd;
    }

    /**
     * Starts a CMAF transcode (DASH + HLS) as a detached background process.
     *
     * @param string               $inputPath Source media file path.
     * @param string               $outDir    Output directory.
     * @param array<string, mixed> $params    Parameters for {@see self::buildCmafCommand()}.
     *
     * @return int OS process id of the launched job (0 if launch failed).
     *
     * @since 0.24.0
     */
    public function startCmafTranscode(string $inputPath, string $outDir, array $params): int
    {
        return $this->startDetached($this->buildCmafCommand($inputPath, $outDir, $params), $outDir);
    }

    /**
     * Starts a CMAF transcode and, in the same detached job, extracts the given
     * text subtitle tracks to cleaned `sub-{index}.vtt` sidecars.
     *
     * The subtitle extraction commands are appended to the CMAF command with
     * `&&` so they run AFTER the A/V encode (each is internally `|| true` so a
     * failed track never aborts the job — the video is the hard requirement).
     * The whole chain is launched once via {@see self::startDetached()}, so the
     * Workerman worker never blocks on FFmpeg.
     *
     * @param string                       $inputPath     Source media file path.
     * @param string                       $outDir        Output directory.
     * @param array<string, mixed>         $params        Parameters for {@see self::buildCmafCommand()}.
     * @param array<int, string>           $extractCmds   Extra `&&`-chainable extraction commands
     *                                                    (built by {@see \Phlix\Media\Transcoding\Subtitles\SubtitleExtractor::buildExtractCommand()}).
     *
     * @return int OS process id of the launched job (0 if launch failed).
     *
     * @since 0.25.0
     */
    public function startCmafTranscodeWithSubtitles(
        string $inputPath,
        string $outDir,
        array $params,
        array $extractCmds = []
    ): int {
        $command = $this->buildCmafCommand($inputPath, $outDir, $params);

        // Subtitle extraction is a TRAILING step that runs ONLY when the CMAF
        // encode succeeded (see startDetached() — these go inside the `then`
        // branch, AFTER `.complete` is written). Each extract group is itself
        // `|| true`, so a failed track can never alter the already-decided job
        // status, and the `|| true` can never bridge back to a failed encode.
        $trailing = [];
        foreach ($extractCmds as $extract) {
            if (is_string($extract) && $extract !== '') {
                $trailing[] = $extract;
            }
        }

        return $this->startDetached($command, $outDir, $trailing);
    }

    /**
     * Launches an FFmpeg command fully detached from the PHP process.
     *
     * Phlix runs on a long-lived Workerman event loop, so the synchronous
     * {@see self::transcode()} (proc_open + stream_get_contents + proc_close)
     * MUST NOT be used in the request path — it would block the worker for the
     * entire encode. This backgrounds the command with `nohup ... &`, captures
     * output to `$outDir/ffmpeg.log`, and writes a `.complete` / `.failed`
     * marker on exit so readiness can be polled from disk (surviving worker
     * reloads, which an in-memory process handle would not).
     *
     * The marker decision uses an unambiguous `if <command>; then ...; else ...; fi`
     * form so it reflects ONLY the primary command's exit status, regardless of
     * `set -e` state. `$trailingCmds` (e.g. subtitle extraction) run inside the
     * `then` branch AFTER `.complete` is written — so they execute only on a
     * successful primary command and their own exit status can never flip the
     * job to `.complete`/`.failed`. (Contrast the old `cmd && extract || true &&
     * touch .complete` chain, where a left-associative `|| true` after the
     * extract group could mask a FAILED primary command and still touch
     * `.complete`.)
     *
     * @param string             $command      The full primary FFmpeg command to run.
     * @param string             $outDir       Directory for the log and completion markers.
     * @param array<int, string> $trailingCmds Commands to run after a SUCCESSFUL primary
     *                                          command (each should be internally `|| true`
     *                                          so it cannot abort the post-success sequence).
     *
     * @return int OS process id of the background job (0 if launch failed).
     *
     * @since 0.23.0
     */
    public function startDetached(string $command, string $outDir, array $trailingCmds = []): int
    {
        $full = $this->buildDetachedCommand($command, $outDir, $trailingCmds);

        $this->logger->debug('Starting detached HLS transcode', ['command' => $command]);

        $pid = shell_exec($full);
        if (!is_string($pid)) {
            $this->logger->error('Failed to launch detached transcode');
            return 0;
        }

        return (int) trim($pid);
    }

    /**
     * Builds the full backgrounded launch string used by {@see self::startDetached()}.
     *
     * Factored out so the unambiguous marker structure can be asserted in tests
     * without actually spawning a process. The marker decision is an
     * `if <command>; then touch .complete; <trailing...>; else touch .failed; fi`
     * form: `.complete` is written ONLY when `$command` exits 0, and the
     * `$trailingCmds` (subtitle extraction) run inside `then` AFTER `.complete`,
     * so neither a `|| true` extract wrapper nor a failing extract can ever bridge
     * a FAILED primary command to `.complete`, nor flip a success to `.failed`.
     *
     * @param string             $command      The primary command.
     * @param string             $outDir       Marker / log directory.
     * @param array<int, string> $trailingCmds Post-success commands (each internally `|| true`).
     *
     * @return string The full `nohup sh -c ... & echo $!` launch string.
     *
     * @since 0.25.0
     */
    public function buildDetachedCommand(string $command, string $outDir, array $trailingCmds = []): string
    {
        $then = 'touch ' . escapeshellarg($outDir . '/.complete');
        foreach ($trailingCmds as $trailing) {
            if (is_string($trailing) && $trailing !== '') {
                $then .= '; ' . $trailing;
            }
        }
        $inner = 'if ' . $command . '; then ' . $then
            . '; else touch ' . escapeshellarg($outDir . '/.failed') . '; fi';

        return sprintf(
            'nohup sh -c %s > %s 2>&1 & echo $!',
            escapeshellarg($inner),
            escapeshellarg($outDir . '/ffmpeg.log')
        );
    }

    /**
     * Checks whether a process id is still running.
     *
     * Uses `posix_kill($pid, 0)` when ext-posix is present, otherwise probes
     * `/proc/{pid}`. Returns false for non-positive pids.
     *
     * @param int $pid Process id to check.
     *
     * @return bool True if the process appears to be alive.
     *
     * @since 0.23.0
     */
    public function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        return is_dir('/proc/' . $pid);
    }

    /**
     * Generates a thumbnail image from a video.
     *
     * @param string $inputPath Source video path
     * @param string $outputPath Destination image path
     * @param int|array<int> $timeSeconds Timestamp(s) to capture frame (default: 10)
     *
     * @return bool True if thumbnail generation succeeded
     *
     * @example
     * ```php
     * $success = $runner->generateThumbnail('/video.mkv', '/thumb.jpg', 30);
     * ```
     *
     * @since 0.11.0 Supports array of timestamps for batch extraction
     */
    public function generateThumbnail(string $inputPath, string $outputPath, int|array $timeSeconds = 10): bool
    {
        if (is_array($timeSeconds)) {
            return $this->generateThumbnailBatch($inputPath, $timeSeconds, $outputPath);
        }

        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -i %s -ss %d -vframes 1 -q:v 2 -f image2 %s',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($inputPath),
            $timeSeconds,
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Generates multiple thumbnails at different timestamps in a single command.
     *
     * Uses FFmpeg's capability to output multiple files from a single input
     * with multiple -ss and -vframes pairs for efficient batch extraction.
     *
     * @param string $inputPath Source video path
     * @param array<int> $timestamps Array of timestamps to capture
     * @param string $outputDir Directory for output images (images named frame_00000.ext, frame_00001.ext, etc.)
     *
     * @return bool True if batch extraction succeeded
     *
     * @since 0.11.0
     */
    public function generateThumbnailBatch(string $inputPath, array $timestamps, string $outputDir): bool
    {
        if (empty($timestamps)) {
            return true;
        }

        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -i %s',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($inputPath)
        );

        foreach ($timestamps as $index => $timestamp) {
            $framePath = $outputDir . '/frame_' . str_pad((string) $index, 5, '0', STR_PAD_LEFT) . '.jpg';
            $cmd .= sprintf(
                ' -ss %d -vframes 1 %s',
                escapeshellarg((string) $timestamp),
                escapeshellarg($framePath)
            );
        }

        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Gets the configured FFmpeg binary path.
     *
     * @return string Path to the FFmpeg binary
     *
     * @since 0.11.0
     */
    public function getFfmpegPath(): string
    {
        return $this->ffmpegPath;
    }

    /**
     * Extracts a subtitle stream to a file.
     *
     * @param string $inputPath Source video path
     * @param string $outputPath Destination subtitle file path
     * @param int $streamIndex Subtitle stream index (default: 0)
     *
     * @return bool True if extraction succeeded
     *
     * @example
     * ```php
     * $success = $runner->extractSubtitle('/video.mkv', '/subs.srt', 0);
     * ```
     */
    public function extractSubtitle(string $inputPath, string $outputPath, int $streamIndex = 0): bool
    {
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -i %s -map 0:s:%d -c:s copy %s',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($inputPath),
            $streamIndex,
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Extracts one embedded TEXT subtitle stream to a WebVTT file, transcoding
     * the subtitle codec (ASS/SRT/mov_text → WebVTT) rather than copying it.
     *
     * Used by the on-demand subtitle endpoint so a direct-play client can fetch
     * a selectable caption track without a full transcode. Bitmap subtitles
     * (PGS/VobSub) have no text and will fail (return false).
     *
     * @param string $inputPath   Source media path
     * @param string $outputPath  Destination .vtt path
     * @param int    $streamIndex Per-type subtitle ordinal (the `0:s:{index}` selector)
     *
     * @return bool True when ffmpeg exits cleanly
     */
    public function extractSubtitleVtt(string $inputPath, string $outputPath, int $streamIndex = 0): bool
    {
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -i %s -map 0:s:%d -c:s webvtt -f webvtt %s',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($inputPath),
            $streamIndex,
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Checks if FFmpeg is available and executable.
     *
     * @return bool True if FFmpeg binary exists and is executable
     *
     * @example
     * ```php
     * if (!$runner->isAvailable()) {
     *     throw new RuntimeException('FFmpeg not installed');
     * }
     * ```
     */
    public function isAvailable(): bool
    {
        return file_exists($this->ffmpegPath) && is_executable($this->ffmpegPath);
    }

    /**
     * Gets the FFmpeg version string.
     *
     * @return string|null Version string or null if unavailable
     *
     * @example
     * ```php
     * $version = $runner->getVersion(); // "6.1"
     * ```
     */
    public function getVersion(): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $output = shell_exec(escapeshellarg($this->ffmpegPath) . ' -version 2>/dev/null');
        if (!is_string($output)) {
            return null;
        }
        if (preg_match('/ffmpeg version (\S+)/', $output, $matches) === 1) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Probes hardware acceleration capabilities and populates the registry.
     *
     * This method should be called at startup to detect available hardware
     * acceleration. It initializes the HwaccelRegistry singleton with the
     * detected capabilities.
     *
     * @param HwaccelRegistry|null $registry Optional custom registry (uses singleton if null)
     *
     * @return array<string, HwaccelCapability> Map of vendor name to capability
     *
     * @since 0.11.0
     */
    public function probeHardwareAcceleration(?HwaccelRegistry $registry = null): array
    {
        if ($this->hwaccelProbed) {
            return $this->hwaccelRegistry?->getAll() ?? [];
        }

        $this->hwaccelRegistry = $registry ?? HwaccelRegistry::getInstance();
        $capabilities = $this->hwaccelRegistry->getAll();

        // Set preferred accelerator from merged config if specified.
        // This affects getBestAcceleratorForCodec() selection order.
        $preferredAccelerator = $this->config['preferred_accelerator'] ?? null;
        if (is_string($preferredAccelerator) && $preferredAccelerator !== '') {
            $this->setPreferredAccelerator($preferredAccelerator);
        }

        $this->logger->info('Hardware acceleration probed', [
            'vendors' => array_keys($capabilities),
            'preferred' => $preferredAccelerator,
        ]);

        $this->hwaccelProbed = true;

        return $capabilities;
    }

    /**
     * Gets the hardware acceleration registry.
     *
     * @return HwaccelRegistry|null
     *
     * @since 0.11.0
     */
    public function getHwaccelRegistry(): ?HwaccelRegistry
    {
        return $this->hwaccelRegistry;
    }

    /**
     * Extracts color metadata from a probe result.
     *
     * Parses the ffprobe JSON output to extract HDR-related color
     * information from the video stream.
     *
     * @param array<string, mixed> $probeResult Result from probe()
     *
     * @return array{
     *     color_space: string,
     *     color_transfer: string,
     *     color_primaries: string,
     *     max_luminance: float,
     *     avg_luminance: float
     * } Color metadata or defaults if not present
     *
     * @since 0.11.0
     */
    public function extractColorMetadata(array $probeResult): array
    {
        // Find the video stream
        $videoStream = null;
        $streams = $probeResult['streams'] ?? [];
        if (is_array($streams)) {
            foreach ($streams as $stream) {
                if (!is_array($stream)) {
                    continue;
                }
                if (($stream['codec_type'] ?? '') === 'video') {
                    $videoStream = $stream;
                    break;
                }
            }
        }

        if ($videoStream === null) {
            return [
                'color_space' => 'bt2020nc',
                'color_transfer' => 'bt709',
                'color_primaries' => 'bt2020',
                'max_luminance' => 1000.0,
                'avg_luminance' => 200.0,
            ];
        }

        $colorSpace = is_string($videoStream['color_space'] ?? null)
            ? $videoStream['color_space']
            : 'bt2020nc';
        $colorTransfer = is_string($videoStream['color_transfer'] ?? null)
            ? $videoStream['color_transfer']
            : 'bt709';
        $colorPrimaries = is_string($videoStream['color_primaries'] ?? null)
            ? $videoStream['color_primaries']
            : 'bt2020';

        // Default luminance values
        $maxLuminance = 1000.0;
        $avgLuminance = 200.0;

        // Try to extract luminance from side data or tags
        $tags = $videoStream['tags'] ?? null;
        if (is_array($tags)) {
            $masteringLuminance = $tags['mastering_display_luminance'] ?? null;
            if (is_string($masteringLuminance)) {
                if (preg_match('/max:(\d+(\.\d+)?)/', $masteringLuminance, $matches)) {
                    $maxLuminance = (float) $matches[1];
                }
            }

            $ambientLuminance = $tags['ambient_luminance'] ?? null;
            if (is_string($ambientLuminance)) {
                if (preg_match('/avg:(\d+(\.\d+)?)/', $ambientLuminance, $matches)) {
                    $avgLuminance = (float) $matches[1];
                }
            }
        }

        // Also check for max_luminance directly (some FFmpeg versions)
        if (isset($videoStream['max_luminance']) && is_numeric($videoStream['max_luminance'])) {
            $maxLuminance = (float) $videoStream['max_luminance'];
        }

        if (isset($videoStream['avg_luminance']) && is_numeric($videoStream['avg_luminance'])) {
            $avgLuminance = (float) $videoStream['avg_luminance'];
        }

        return [
            'color_space' => $colorSpace,
            'color_transfer' => $colorTransfer,
            'color_primaries' => $colorPrimaries,
            'max_luminance' => $maxLuminance,
            'avg_luminance' => $avgLuminance,
        ];
    }

    /**
     * Builds a transcode command using a hardware encoder profile.
     *
     * This method delegates to HwaccelCommandBuilder to construct a complete
     * FFmpeg command with hardware-specific flags in the correct order.
     *
     * @param string $inputPath Source file path
     * @param string $outputPath Destination file path
     * @param HwaccelEncoderProfileInterface $profile Encoder profile to use
     * @param HwaccelCapability $capability Hardware capability
     * @param string $codec Codec to encode (e.g., 'h264', 'hevc')
     * @param array<string, mixed> $params Additional encoding parameters
     * @param string $quality Quality level (e.g., 'ultra', 'high', 'medium', 'low')
     *
     * @return string Complete FFmpeg command
     *
     * @since 0.11.0
     */
    public function buildTranscodeCommandWithProfile(
        string $inputPath,
        string $outputPath,
        HwaccelEncoderProfileInterface $profile,
        HwaccelCapability $capability,
        string $codec,
        array $params = [],
        string $quality = 'medium'
    ): string {
        $builder = (new HwaccelCommandBuilder($profile, $capability, $quality))
            ->setFfmpegPath($this->ffmpegPath)
            ->setInput($inputPath)
            ->setOutput($outputPath)
            ->setVideoCodec($codec);

        $audioCodec = self::paramString($params, 'audio_codec');
        if ($audioCodec !== null) {
            $builder->setAudioCodec($audioCodec);
        }

        $bitrate = self::paramInt($params, 'bitrate');
        if ($bitrate !== null) {
            $builder->setBitrate($bitrate);
        }

        $width = self::paramInt($params, 'width');
        $height = self::paramInt($params, 'height');
        if ($width !== null && $height !== null) {
            $builder->setResolution($width, $height);
        }

        $filters = $params['filters'] ?? null;
        if (is_array($filters)) {
            foreach ($filters as $filter) {
                if (is_string($filter)) {
                    $builder->addFilter($filter);
                }
            }
        }

        return $builder->build();
    }

    /**
     * Builds a hardware-accelerated FFmpeg transcode command.
     *
     * Uses the hwaccel registry to select the best encoder for the codec,
     * and builds the appropriate command with hardware-specific flags.
     *
     * @param string $inputPath Source file path
     * @param string $outputPath Destination file path
     * @param string $codec Codec to encode (e.g., 'h264', 'hevc')
     * @param array<string, mixed> $params Additional encoding parameters
     * @param bool $require_hdr_tone_map Require HDR tone mapping support
     *
     * @return string|null Complete FFmpeg command or null if no hwaccel available
     *
     * @since 0.11.0
     */
    public function buildHwaccelCommand(
        string $inputPath,
        string $outputPath,
        string $codec,
        array $params = [],
        bool $require_hdr_tone_map = false
    ): ?string {
        if (!$this->hwaccelProbed) {
            $this->probeHardwareAcceleration();
        }

        $capability = $this->hwaccelRegistry?->getEncoder($codec, $require_hdr_tone_map);

        if ($capability === null) {
            $this->logger->warning('No hardware encoder found', ['codec' => $codec]);
            return null;
        }

        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error',
            escapeshellarg($this->ffmpegPath)
        );

        $cmd .= $this->buildHwaccelInputFlags($capability);

        $cmd .= ' -i ' . escapeshellarg($inputPath);

        $cmd .= ' -c:v ' . $capability->encoder;

        switch ($capability->vendor) {
            case 'nvenc':
                $cmd .= ' -preset:v p4';
                break;
            case 'vaapi':
            case 'qsv':
                $cmd .= ' -preset:v fast';
                break;
            default:
                $cmd .= ' -preset:v medium';
        }

        $crf = self::paramInt($params, 'crf');
        if ($crf !== null) {
            $cmd .= ' -crf ' . $crf;
        }

        $width = self::paramInt($params, 'width');
        $height = self::paramInt($params, 'height');
        if ($width !== null && $height !== null) {
            $scaleFilter = "scale={$width}:{$height}:force_original_aspect_ratio=decrease";
            $cmd .= ' -vf "' . $scaleFilter . '"';
        }

        $audioCodec = self::paramString($params, 'audio_codec');
        if ($audioCodec !== null) {
            $cmd .= ' -c:a ' . $audioCodec;
            $cmd .= ' -b:a ' . (self::paramString($params, 'audio_bitrate') ?? '128k');
            $cmd .= ' -ar ' . (self::paramInt($params, 'audio_sample_rate') ?? 48000);
        } else {
            $cmd .= ' -c:a copy';
        }

        $format = self::paramString($params, 'format');
        if ($format !== null) {
            $cmd .= ' -f ' . $format;
        }

        $cmd .= ' -threads 0';

        $cmd .= ' ' . escapeshellarg($outputPath);

        return $cmd;
    }

    /**
     * Builds the input hardware acceleration flags for a capability.
     *
     * @param HwaccelCapability $capability
     *
     * @return string
     *
     * @since 0.11.0
     */
    private function buildHwaccelInputFlags(HwaccelCapability $capability): string
    {
        return match ($capability->vendor) {
            'nvenc' => ' -hwaccel cuda -hwaccel_device 0',
            'vaapi' => ' -hwaccel vaapi -hwaccel_device /dev/dri/renderD128',
            'qsv' => ' -hwaccel qsv -qsv_device /dev/dri/renderD128',
            'videotoolbox' => ' -hwaccel videotoolbox',
            'amf' => ' -hwaccel amf',
            'v4l2' => ' -hwaccel v4l2m2m',
            default => '',
        };
    }

    /**
     * Gets the configured default transcode output directory.
     *
     * @return string Transcode output directory
     *
     * @since 0.11.0
     */
    public function getTranscodeDir(): string
    {
        return $this->transcodeDir;
    }

    /**
     * Browser-safe pixel-format / profile flags for a software H.264/H.265 encode.
     *
     * Browser Media Source Extensions (and hls.js) can only decode 8-bit 4:2:0
     * H.264 (Baseline/Main/High). A 10-bit (High 10) or 4:2:2/4:4:4 source —
     * common for HEVC "Main 10" remuxes — would otherwise produce an output the
     * player loads but cannot decode, surfacing as "couldn't prepare a playable
     * version". Forcing `-pix_fmt yuv420p` (8-bit 4:2:0) plus an H.264
     * `-profile:v`/`-level` guarantees a decodable stream. Callers may override
     * any of `pix_fmt` / `profile` / `level` via $params (e.g. an HDR profile).
     *
     * @param array<string, mixed> $params
     */
    private static function browserSafeVideoFlags(string $videoCodec, array $params): string
    {
        if ($videoCodec === 'libx264') {
            return ' -pix_fmt ' . (self::paramString($params, 'pix_fmt') ?? 'yuv420p')
                . ' -profile:v ' . (self::paramString($params, 'profile') ?? 'high')
                . ' -level ' . (self::paramString($params, 'level') ?? '4.1');
        }
        if ($videoCodec === 'libx265') {
            $pixFmt = self::paramString($params, 'pix_fmt');
            return $pixFmt !== null ? ' -pix_fmt ' . $pixFmt : '';
        }
        return '';
    }

    /**
     * Extracts a string value from a mixed parameter array.
     *
     * Returns the value when it is a non-empty string; otherwise null. Numeric
     * scalars are coerced to their string form to remain backwards-compatible
     * with callers that pass numbers as strings (e.g. '128k').
     *
     * @param array<string, mixed> $params
     */
    public static function paramString(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;
        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return null;
    }

    /**
     * Extracts an integer value from a mixed parameter array.
     *
     * Returns the value when it is an int or a numeric string; otherwise null.
     *
     * @param array<string, mixed> $params
     */
    public static function paramInt(array $params, string $key): ?int
    {
        $value = $params[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        return null;
    }

    /**
     * Builds an FFmpeg command that produces ONE on-demand HLS segment.
     *
     * This backs the seek-aware VOD pipeline: instead of a single linear encode
     * that grows a live playlist, the media playlist is published complete up front
     * and each MPEG-TS segment is produced only when the player fetches it. Any
     * segment — including one far past what has been produced so far — is produced
     * directly by fast-seeking the source, so the user can seek anywhere.
     *
     * Two segment shapes are produced, selected per-stream by the codec params:
     *
     * 1. TRANSCODED RUNG (the ABR-ladder default; validated on 10-bit HEVC MKVs).
     *    Video is re-encoded with a capped-CRF recipe:
     *      - `-ss {start} -i input` — accurate fast input seek to the segment start.
     *      - `-t {duration}` — encode exactly this segment's window.
     *      - `-preset`/`-crf` — quality-driven encode (libx264/libx265 defaults kept
     *        when the caller omits them, so legacy callers are unaffected).
     *      - `-maxrate`/`-bufsize` — a hard VBV ceiling derived by A2's
     *        `Rendition::maxrate()` (≈1.07×target) / `Rendition::bufsize()` (2×maxrate)
     *        so the stream never exceeds the rung's advertised `BANDWIDTH`. Emitted
     *        ONLY when the caller passes `maxrate`/`bufsize`; absent them the encode
     *        stays CRF-only (unchanged legacy behaviour). A bare `-b:v` is
     *        deliberately NOT set — it would disable CRF mode; the cap is the VBV pair.
     *      - `-vf scale=…` — the per-rung downscale (`width`/`height`).
     *      - `-force_key_frames expr:gte(t,0)` — the first output frame is an IDR, so
     *        every segment is independently decodable (EXT-X-INDEPENDENT-SEGMENTS).
     *      - `browserSafeVideoFlags()` — `-pix_fmt`/`-profile:v`/`-level` (the `level`
     *        is honoured per-rung from `params['level']`).
     *    The `-force_key_frames`, `-t`, `-output_ts_offset`, and `-muxdelay`/
     *    `-muxpreload` framing are IDENTICAL across every transcoded rung — only the
     *    scale/bitrate/level differ — so hls.js can ABR-switch between rungs at any
     *    segment boundary seamlessly.
     *
     * 2. STREAM-COPY "Original" (plan §1 D4). When `video_codec` is `copy` the segment
     *    is a genuine `-c:v copy` (near-zero CPU): no `-preset`/`-crf`/scale/`-maxrate`/
     *    `-bufsize`/`browserSafeVideoFlags()`, and — critically — NO `-force_key_frames`
     *    (a stream copy cannot synthesise a keyframe at an arbitrary point). Because
     *    `-ss` before `-i` fast-seeks to the nearest PRECEDING source keyframe, a copy
     *    segment's actual start may drift up to one source GOP length from the nominal
     *    boundary. That is acceptable for a manually-pinned "Original" variant (the
     *    source is already HLS-safe H.264 + AAC) but is exactly why copy is NOT used
     *    for the ABR-switching rungs, which must stay frame-aligned. `-output_ts_offset`
     *    and `-muxdelay 0 -muxpreload 0` still apply to anchor the segment's PTS for
     *    playlist concatenation.
     *
     * Audio mirrors the same split: `audio_codec = copy` → `-c:a copy` (no
     * `-b:a`/`-ac`); otherwise re-encode to AAC (re-encoding avoids priming/alignment
     * artefacts at the boundary a fast-seek copy would introduce). The video and audio
     * codec decisions are INDEPENDENT, so mixed segments are fully supported — an
     * H.264 source with non-AAC audio yields video copy + audio re-encode, and the
     * reverse yields video re-encode + audio copy.
     *
     * @param string               $inputPath Source media file.
     * @param string               $outFile   Absolute path to write the .ts segment to.
     * @param float                $start     Segment start offset in seconds.
     * @param float                $duration  Segment length in seconds.
     * @param array<string, mixed> $params    Encode params: `video_codec`
     *                                         (`libx264`/`libx265`/`copy`), `preset`,
     *                                         `crf`, `video_bitrate`/`maxrate`/`bufsize`
     *                                         (bps; capped-CRF VBV ceiling — `-maxrate`/
     *                                         `-bufsize` are emitted, never a bare
     *                                         `-b:v`), `pix_fmt`/`profile`/`level`,
     *                                         `width`/`height` (downscale),
     *                                         `audio_codec` (`aac`/…/`copy`),
     *                                         `audio_bitrate`, `audio_channels`.
     *
     * @return string The complete FFmpeg segment command.
     */
    public function buildSegmentCommand(
        string $inputPath,
        string $outFile,
        float $start,
        float $duration,
        array $params
    ): string {
        $startArg = self::seconds($start);
        $durArg = self::seconds($duration);

        // P3B multi-audio: when the master carries a shared AUDIO group, every video
        // variant segment is VIDEO-ONLY (`-an`) — sound travels in the audio-only
        // renditions instead, so muxing a (possibly wrong) audio track here would
        // duplicate and desync it.
        $videoOnly = ($params['video_only'] ?? false) === true;

        // Accurate input seek BEFORE -i so the decode starts at the segment boundary.
        $cmd = sprintf('%s -nostdin -y -hide_banner -loglevel error', escapeshellarg($this->ffmpegPath));
        $cmd .= ' -ss ' . $startArg;
        $cmd .= ' -i ' . escapeshellarg($inputPath);
        $cmd .= ' -t ' . $durArg;

        // Video: first video track always. Audio: either a specific AUDIO-RELATIVE
        // stream index (P3B-S3 multi-audio, via audio_stream_index param — the N of
        // `-map 0:a:N`, NOT the global ffprobe index) or the first audio track.
        $audioStreamIndex = self::paramInt($params, 'audio_stream_index');
        $cmd .= ' -map 0:v:0';
        if (!$videoOnly) {
            if ($audioStreamIndex !== null && $audioStreamIndex >= 0) {
                // Map the Nth AUDIO stream (audio-relative, e.g. -map 0:a:1 = second audio track).
                $cmd .= sprintf(' -map 0:a:%d', $audioStreamIndex);
            } else {
                // Default: first audio track (backward compatible).
                $cmd .= ' -map 0:a:0?';
            }
        }
        $cmd .= ' -dn -sn';

        // Video: a transcoded rung (capped-CRF) OR a genuine stream copy for "Original".
        $videoCodec = self::paramString($params, 'video_codec') ?? 'libx264';
        if ($videoCodec === '') {
            $videoCodec = 'libx264';
        }
        if ($videoCodec === 'copy') {
            // Genuine passthrough: no encoder/scale flags and NO force_key_frames
            // (a copy cannot synthesise a keyframe mid-GOP). See the method docblock
            // for why this is Original-only and never used for ABR-switching rungs.
            $cmd .= ' -c:v copy';
        } else {
            $cmd .= ' -c:v ' . $videoCodec;
            if ($videoCodec === 'libx264' || $videoCodec === 'libx265') {
                $cmd .= ' -preset ' . (self::paramString($params, 'preset') ?? 'veryfast');
                $defaultCrf = $videoCodec === 'libx265' ? 28 : 23;
                $cmd .= ' -crf ' . (self::paramInt($params, 'crf') ?? $defaultCrf);
            }
            // Per-rung VBV ceiling (capped CRF): a quality-driven encode that still
            // never exceeds the rung's advertised BANDWIDTH. Emitted only when the
            // caller supplies the rung cap; no bare -b:v (it would disable CRF mode).
            $maxrate = self::paramInt($params, 'maxrate');
            if ($maxrate !== null && $maxrate > 0) {
                $cmd .= ' -maxrate ' . $maxrate;
            }
            $bufsize = self::paramInt($params, 'bufsize');
            if ($bufsize !== null && $bufsize > 0) {
                $cmd .= ' -bufsize ' . $bufsize;
            }
            $width = self::paramInt($params, 'width');
            $height = self::paramInt($params, 'height');

            // P6: HDR tone-mapping — apply when hwaccel is enabled (passed in params) and
            // the source is HDR. Software encoders (libx264/libx265) output 8-bit 4:2:0 and
            // cannot encode HDR directly, so HDR sources always need tone-mapping to SDR.
            // This mirrors the logic in buildHwaccelSegmentCommand for the fallback path
            // when hwaccel is enabled but temporarily unavailable (returns null).
            $require_hdr_tone_map = ($params['require_hdr_tone_map'] ?? false) === true;
            $filters = [];
            if ($require_hdr_tone_map || $this->needsToneMapping($inputPath)) {
                $toneMapFilter = $this->getToneMappingProfile($inputPath, $outFile, $videoCodec);
                if ($toneMapFilter !== null && $toneMapFilter !== '') {
                    $filters[] = $toneMapFilter;
                }
            }
            if ($width !== null && $height !== null) {
                $filters[] = "scale={$width}:{$height}:force_original_aspect_ratio=decrease";
            }
            if (!empty($filters)) {
                $cmd .= ' -vf "' . implode(',', $filters) . '"';
            }
            // IDR at the segment start → independently decodable, frame-aligned segment.
            $cmd .= ' -force_key_frames ' . escapeshellarg('expr:gte(t,0)');
            $cmd .= self::browserSafeVideoFlags($videoCodec, $params);
        }

        // Audio: dropped entirely for a video-only (audio-group) variant segment,
        // else re-encode to AAC by default, or a genuine stream copy when asked.
        if ($videoOnly) {
            $cmd .= ' -an';
        } else {
            $audioCodec = self::paramString($params, 'audio_codec') ?? 'aac';
            if ($audioCodec === '') {
                $audioCodec = 'aac';
            }
            if ($audioCodec === 'copy') {
                $cmd .= ' -c:a copy';
            } else {
                $cmd .= ' -c:a ' . $audioCodec;
                $cmd .= ' -b:a ' . (self::paramString($params, 'audio_bitrate') ?? '128k');
                $audioChannels = self::paramInt($params, 'audio_channels');
                if ($audioChannels !== null && $audioChannels > 0) {
                    $cmd .= ' -ac ' . $audioChannels;
                }
            }
        }

        // Anchor PTS to the absolute timeline position; no mux pre-roll.
        $cmd .= ' -muxdelay 0 -muxpreload 0';
        $cmd .= ' -output_ts_offset ' . $startArg;
        $cmd .= ' -f mpegts ' . escapeshellarg($outFile);

        return $cmd;
    }

    /**
     * Builds an FFmpeg command that produces ONE on-demand AUDIO-ONLY HLS segment
     * (P3B multi-audio: the `seg-a{N}-NNNNN.ts` renditions behind each
     * `#EXT-X-MEDIA:TYPE=AUDIO` entry).
     *
     * Contract: `-vn` (no video decode/encode at all — an audio segment must be
     * near-free to produce), `-map 0:a:{n}` where `n` is the AUDIO-RELATIVE stream
     * index (`audio_stream_index` param, default 0), AAC at `audio_bitrate`
     * (default 128k), muxed as MPEG-TS with the same `-ss`/`-t`/`-output_ts_offset`
     * segment framing as {@see buildSegmentCommand()} so audio and video segments
     * stay aligned on the shared VOD timeline.
     *
     * @param string               $inputPath Source media file.
     * @param string               $outFile   Absolute path to write the .ts segment to.
     * @param float                $start     Segment start offset in seconds.
     * @param float                $duration  Segment length in seconds.
     * @param array<string, mixed> $params    `audio_stream_index` (audio-relative),
     *                                        `audio_codec` (default aac),
     *                                        `audio_bitrate` (default 128k),
     *                                        `audio_channels` (optional).
     *
     * @return string The complete FFmpeg audio-only segment command.
     */
    public function buildAudioSegmentCommand(
        string $inputPath,
        string $outFile,
        float $start,
        float $duration,
        array $params
    ): string {
        $startArg = self::seconds($start);
        $durArg = self::seconds($duration);
        $audioStreamIndex = self::paramInt($params, 'audio_stream_index') ?? 0;
        if ($audioStreamIndex < 0) {
            $audioStreamIndex = 0;
        }

        $cmd = sprintf('%s -nostdin -y -hide_banner -loglevel error', escapeshellarg($this->ffmpegPath));
        $cmd .= ' -ss ' . $startArg;
        $cmd .= ' -i ' . escapeshellarg($inputPath);
        $cmd .= ' -t ' . $durArg;
        // No video at all; map exactly the requested audio track (audio-relative N).
        $cmd .= ' -vn -dn -sn';
        $cmd .= sprintf(' -map 0:a:%d', $audioStreamIndex);

        $audioCodec = self::paramString($params, 'audio_codec') ?? 'aac';
        if ($audioCodec === '' || $audioCodec === 'copy') {
            // Audio renditions must stay AAC so every #EXT-X-MEDIA entry matches the
            // advertised mp4a.40.2 — a copy could smuggle a non-HLS codec through.
            $audioCodec = 'aac';
        }
        $cmd .= ' -c:a ' . $audioCodec;
        $cmd .= ' -b:a ' . (self::paramString($params, 'audio_bitrate') ?? '128k');
        $audioChannels = self::paramInt($params, 'audio_channels');
        if ($audioChannels !== null && $audioChannels > 0) {
            $cmd .= ' -ac ' . $audioChannels;
        }

        // Same timeline anchoring as the video segments.
        $cmd .= ' -muxdelay 0 -muxpreload 0';
        $cmd .= ' -output_ts_offset ' . $startArg;
        $cmd .= ' -f mpegts ' . escapeshellarg($outFile);

        return $cmd;
    }

    /**
     * Builds a hardware-accelerated segment encode command.
     *
     * Uses the hwaccel registry to select the best encoder for the codec,
     * and builds the appropriate command with hardware-specific flags for
     * segment production. Falls back to null if no hardware encoder is
     * available.
     *
     * HDR tone-mapping is applied when:
     * - The source content is HDR (HLG or HDR10)
     * - AND either `require_hdr_tone_map` is set in params, OR the hardware
     *   encoder does not natively support HDR output (all current hwaccel
     *   encoders output 8-bit 4:2:0, so HDR content always needs tone-map)
     *
     * @param string $inputPath Source media file path
     * @param string $outFile Absolute final segment path
     * @param float $start Segment start offset in seconds
     * @param float $duration Segment length in seconds
     * @param array<string, mixed> $params Encode params (same as buildSegmentCommand)
     *
     * @return string|null The complete FFmpeg segment command, or null if hwaccel unavailable
     *
     * @since 0.36.0
     */
    public function buildHwaccelSegmentCommand(
        string $inputPath,
        string $outFile,
        float $start,
        float $duration,
        array $params
    ): ?string {
        if (!$this->hwaccelProbed) {
            $this->probeHardwareAcceleration();
        }

        $videoCodec = self::paramString($params, 'video_codec') ?? 'libx264';

        // Map software codec name to generic codec name for registry lookup
        $codecMap = [
            'libx264' => 'h264',
            'libx265' => 'hevc',
        ];
        $codec = $codecMap[$videoCodec] ?? 'h264';

        $require_hdr_tone_map = ($params['require_hdr_tone_map'] ?? false) === true;

        $capability = $this->hwaccelRegistry?->getEncoder($codec, $require_hdr_tone_map);
        if ($capability === null) {
            $this->logger->warning('No hardware encoder found', ['codec' => $codec]);
            return null;
        }

        $startArg = self::seconds($start);
        $durArg = self::seconds($duration);

        // Base FFmpeg invocation with hardware input flags
        $cmd = sprintf(
            '%s -nostdin -y -hide_banner -loglevel error',
            escapeshellarg($this->ffmpegPath)
        );
        $cmd .= $this->buildHwaccelInputFlags($capability);
        $cmd .= ' -ss ' . $startArg;
        $cmd .= ' -i ' . escapeshellarg($inputPath);
        $cmd .= ' -t ' . $durArg;

        // Map video (first video track) and audio (first audio track, optional).
        // A video-only (audio-group) variant segment maps/encodes NO audio at all —
        // sound travels in the separate audio-only renditions.
        $videoOnly = ($params['video_only'] ?? false) === true;
        $audioStreamIndex = self::paramInt($params, 'audio_stream_index');
        $cmd .= ' -map 0:v:0';
        if (!$videoOnly) {
            if ($audioStreamIndex !== null && $audioStreamIndex >= 0) {
                // Audio-relative index: -map 0:a:N selects the Nth AUDIO stream.
                $cmd .= sprintf(' -map 0:a:%d', $audioStreamIndex);
            } else {
                $cmd .= ' -map 0:a:0?';
            }
        }
        $cmd .= ' -dn -sn';

        // Hardware video encoder
        $cmd .= ' -c:v ' . $capability->encoder;

        // Vendor-specific preset tuning
        switch ($capability->vendor) {
            case 'nvenc':
                $cmd .= ' -preset:v p4';
                break;
            case 'vaapi':
            case 'qsv':
                $cmd .= ' -preset:v fast';
                break;
            default:
                $cmd .= ' -preset:v medium';
        }

        // Rate control: CRF when supplied, and VBV ceiling when supplied
        $crf = self::paramInt($params, 'crf');
        if ($crf !== null) {
            $cmd .= ' -crf ' . $crf;
        }
        $maxrate = self::paramInt($params, 'maxrate');
        if ($maxrate !== null && $maxrate > 0) {
            $cmd .= ' -maxrate ' . $maxrate;
        }
        $bufsize = self::paramInt($params, 'bufsize');
        if ($bufsize !== null && $bufsize > 0) {
            $cmd .= ' -bufsize ' . $bufsize;
        }

        // Determine if HDR tone-mapping is needed:
        // All current hardware encoders (nvenc/vaapi/qsv/videotoolbox/amf/v4l2)
        // output 8-bit 4:2:0, so HDR content always requires tone-mapping to SDR.
        $hdrCapableEncoders = [
            'hevc_nvenc',
            'hevc_vaapi',
            'hevc_qsv',
            'hevc_videotoolbox',
            'hevc_amf',
        ];
        $needsToneMap = $require_hdr_tone_map || (
            $this->needsToneMapping($inputPath)
            && !in_array($capability->encoder, $hdrCapableEncoders, true)
        );

        // Build filter chain: tone-mapping (if needed) + scale (if dimensions supplied)
        $filters = [];

        if ($needsToneMap) {
            $toneMapFilter = $this->getToneMappingProfile($inputPath, $outFile, $videoCodec);
            if ($toneMapFilter !== null && $toneMapFilter !== '') {
                $filters[] = $toneMapFilter;
            }
        }

        $width = self::paramInt($params, 'width');
        $height = self::paramInt($params, 'height');
        if ($width !== null && $height !== null) {
            $filters[] = "scale={$width}:{$height}:force_original_aspect_ratio=decrease";
        }

        if (!empty($filters)) {
            $cmd .= ' -vf "' . implode(',', $filters) . '"';
        }

        // IDR at the segment start for independently decodable segments
        $cmd .= ' -force_key_frames ' . escapeshellarg('expr:gte(t,0)');

        // Audio: dropped for a video-only (audio-group) variant segment, else
        // re-encode to AAC by default, or stream copy when requested
        if ($videoOnly) {
            $cmd .= ' -an';
        } else {
            $audioCodec = self::paramString($params, 'audio_codec') ?? 'aac';
            if ($audioCodec === 'copy') {
                $cmd .= ' -c:a copy';
            } else {
                $cmd .= ' -c:a ' . $audioCodec;
                $cmd .= ' -b:a ' . (self::paramString($params, 'audio_bitrate') ?? '128k');
                $audioChannels = self::paramInt($params, 'audio_channels');
                if ($audioChannels !== null && $audioChannels > 0) {
                    $cmd .= ' -ac ' . $audioChannels;
                }
            }
        }

        // Anchor PTS to the absolute timeline position; no mux pre-roll.
        $cmd .= ' -muxdelay 0 -muxpreload 0';
        $cmd .= ' -output_ts_offset ' . $startArg;
        $cmd .= ' -f mpegts ' . escapeshellarg($outFile);

        return $cmd;
    }

    /**
     * Launches an on-demand segment encode as a detached background process,
     * writing atomically so a reader never sees a half-written segment.
     *
     * FFmpeg writes to a unique `.part` temp file; on success it is `mv`'d to the
     * final path (an atomic rename on the same filesystem), on failure the temp is
     * removed. The caller therefore treats `is_file($outFile)` as "segment ready".
     * The launch itself returns immediately (the `& echo $!` backgrounds ffmpeg),
     * so the worker never blocks on the encode — the caller polls for the file with
     * a coroutine-yielding sleep.
     *
     * @param string               $inputPath Source media file.
     * @param string               $outFile   Absolute final segment path.
     * @param float                $start     Segment start offset in seconds.
     * @param float                $duration  Segment length in seconds.
     * @param array<string, mixed> $params    Encode params (see buildSegmentCommand()).
     *
     * @return int OS process id of the launched job (0 if launch failed).
     */
    public function startSegmentEncode(
        string $inputPath,
        string $outFile,
        float $start,
        float $duration,
        array $params
    ): int {
        $tmp = $outFile . '.part-' . bin2hex(random_bytes(4));

        // P3B multi-audio: an audio-only rendition segment never touches the video
        // pipeline (no hwaccel, no -c:v) — it is a cheap -vn AAC extract/encode.
        if (($params['audio_only'] ?? false) === true) {
            $encode = $this->buildAudioSegmentCommand($inputPath, $tmp, $start, $duration, $params);
            return $this->launchDetachedSegment($encode, $tmp, $outFile);
        }

        // Try hardware acceleration first if enabled AND preferred in config.
        // prefer_hardware=false means prefer software even if hwaccel is available.
        // The merged HwAccelConfig is flat (enabled, prefer_hardware at root level).
        $hwaccelEnabled = ($this->config['enabled'] ?? false) === true;
        $preferHardware = ($this->config['prefer_hardware'] ?? true) === true;
        $encode = null;

        if ($hwaccelEnabled && $preferHardware) {
            $encode = $this->buildHwaccelSegmentCommand($inputPath, $tmp, $start, $duration, $params);
        }

        // Fall back to software encoding if hwaccel is disabled, unavailable, or returned null
        if ($encode === null) {
            $encode = $this->buildSegmentCommand($inputPath, $tmp, $start, $duration, $params);
        }

        return $this->launchDetachedSegment($encode, $tmp, $outFile);
    }

    /**
     * Launches a built segment encode command detached, with the shared
     * atomic-publish tail (mv temp → final on success, rm temp on failure).
     *
     * @param string $encode  The complete FFmpeg command writing to `$tmp`.
     * @param string $tmp     The `.part-*` temp path the command writes.
     * @param string $outFile The final segment path published on success.
     *
     * @return int OS process id of the launched job (0 if launch failed).
     */
    private function launchDetachedSegment(string $encode, string $tmp, string $outFile): int
    {
        // Atomic publish: rename on success, clean the temp on failure.
        $inner = $encode
            . ' && mv -f ' . escapeshellarg($tmp) . ' ' . escapeshellarg($outFile)
            . ' || rm -f ' . escapeshellarg($tmp);
        $log = dirname($outFile) . '/ffmpeg-segments.log';
        $full = sprintf('nohup sh -c %s >> %s 2>&1 & echo $!', escapeshellarg($inner), escapeshellarg($log));

        $pid = shell_exec($full);
        if (!is_string($pid)) {
            $this->logger->error('Failed to launch on-demand segment encode', ['segment' => $outFile]);
            return 0;
        }
        return (int) trim($pid);
    }

    /**
     * Formats a seconds value for an FFmpeg time argument (millisecond precision,
     * no scientific notation, trimmed trailing zeros). Negative inputs clamp to 0.
     */
    private static function seconds(float $value): string
    {
        if ($value < 0) {
            $value = 0.0;
        }
        $formatted = rtrim(rtrim(sprintf('%.3f', $value), '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * Generates trickplay sprite sheets for chapter-based scrubbing preview.
     *
     * Produces a 6-column sprite sheet grid (default 60 thumbs = 6x10) at 160x90
     * each, plus a JSON timeline mapping each thumbnail index to its (x, y)
     * position within the sprite and the video timestamp it represents.
     *
     * @param string $videoPath  Source video file path.
     * @param string $outputDir  Directory to write sprite.jpg and timeline.json.
     * @param int    $count      Number of thumbnails to generate (default 60).
     *
     * @return array{0: string, 1: string}|null Absolute paths to [sprite, timeline]
     *         on success, or null on failure (missing FFmpeg, probe error, encode error).
     *
     * @since 0.35.0
     */
    public function generateTrickplaySprites(string $videoPath, string $outputDir, int $count = 60): ?array
    {
        $probe = $this->probe($videoPath);
        if (!is_array($probe)) {
            return null;
        }

        $format = $probe['format'] ?? null;
        if (!is_array($format)) {
            return null;
        }

        $rawDuration = $format['duration'] ?? null;
        if (!is_numeric($rawDuration)) {
            return null;
        }

        $duration = (float) $rawDuration;
        if ($duration <= 0) {
            return null;
        }

        $interval = $duration / $count;
        $cols = 6;
        $rows = (int) ceil($count / $cols);
        $thumbW = 160;
        $thumbH = 90;
        $margin = 2;
        $padding = 1;

        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
                return null;
            }
        }

        $spritePath = $outputDir . '/sprite.jpg';
        $timelinePath = $outputDir . '/timeline.json';

        // Use the midpoint timestamp so the first frame capture is not black
        // (often an opening slate/blank) when seeking to time 0.
        $captureTime = self::seconds($duration / 2.0);

        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -ss %s -i %s -vf "fps=1/%s,scale=%d:%d,tile=%d:%d:margin=%d:padding=%d" -frames:v 1 %s 2>&1',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($captureTime),
            escapeshellarg($videoPath),
            escapeshellarg(self::seconds($interval)),
            $thumbW,
            $thumbH,
            $cols,
            $rows,
            $margin,
            $padding,
            escapeshellarg($spritePath)
        );

        $output = shell_exec($cmd);
        if (!is_file($spritePath)) {
            $this->logger->warning('Trickplay sprite generation failed', [
                'video' => $videoPath,
                'output' => $output,
            ]);
            return null;
        }

        // Build timeline JSON: each entry maps thumbnail index to pixel offset
        // within the sprite sheet and the corresponding video timestamp.
        $timeline = [];
        for ($i = 0; $i < $count; $i++) {
            $row = (int) floor($i / $cols);
            $col = $i % $cols;
            $x = $col * ($thumbW + $margin + $padding);
            $y = $row * ($thumbH + $margin + $padding);
            $time = $i * $interval;
            $timeline[] = ['time' => $time, 'x' => $x, 'y' => $y];
        }

        $json = json_encode($timeline, JSON_THROW_ON_ERROR);
        file_put_contents($timelinePath, $json);

        return [$spritePath, $timelinePath];
    }

    /**
     * Sets the preferred hardware accelerator from configuration.
     *
     * When set, {@see getBestAcceleratorForCodec()} will prefer this accelerator
     * over others when it supports the requested codec.
     *
     * @param string|null $name Accelerator name (e.g., 'cuda', 'qsv', 'vaapi') or null to clear
     *
     * @since 0.36.0
     */
    public function setPreferredAccelerator(?string $name): void
    {
        $this->preferredAccelerator = $name;
    }

    /**
     * Sets the transcoding configuration.
     *
     * Configuration should include:
     *  - tone_mapping_mode: 'none' | 'zscale' | 'libplacebo'
     *  - prefer_hdr_output: bool
     *
     * @param array<mixed, mixed> $config Transcoding configuration array
     *
     * @since 0.36.0
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * Loads transcoding configuration from the config file.
     *
     * @param string $configPath Path to the transcoding.php config file
     *
     * @return void
     *
     * @since 0.36.0
     */
    public function loadConfig(string $configPath): void
    {
        if (is_file($configPath) && is_readable($configPath)) {
            $config = include $configPath;
            if (is_array($config)) {
                $this->config = $config;
            }
        }
    }

    /**
     * Returns the current transcoding configuration.
     *
     * @return array<string, mixed>
     *
     * @since 0.36.0
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Returns the preferred accelerator name from configuration.
     *
     * @return string|null
     *
     * @since 0.36.0
     */
    public function getPreferredAccelerator(): ?string
    {
        return $this->preferredAccelerator;
    }

    /**
     * Detects and returns all available hardware accelerators on this machine.
     *
     * Runs `ffmpeg -hide_banner -hwaccels` to discover available hwaccel methods,
     * then probes each for specific encoders using `ffmpeg -hide_banner -encoders`.
     *
     * Results are cached after the first call.
     *
     * @return array<string, HardwareAccelerator> Map of accelerator name to accelerator object
     *
     * @since 0.36.0
     */
    public function getHardwareAccelerators(): array
    {
        if ($this->hardwareAccelerators !== null) {
            return $this->hardwareAccelerators;
        }

        $accelerators = [];

        // Get list of available hwaccels from ffmpeg
        $cmd = sprintf(
            '%s -hide_banner -hwaccels 2>/dev/null',
            escapeshellarg($this->ffmpegPath)
        );

        $output = shell_exec($cmd);
        if (!is_string($output)) {
            $this->hardwareAccelerators = [];
            return [];
        }

        $lines = explode("\n", trim($output));
        // First line is "Hardware accelerator methods:" or empty, skip it
        $hwaccelNames = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line === 'Hardware accelerator methods:') {
                continue;
            }
            $hwaccelNames[] = $line;
        }

        // Get all available encoders in one shot
        $encodersCmd = sprintf(
            '%s -hide_banner -encoders 2>/dev/null',
            escapeshellarg($this->ffmpegPath)
        );
        $encodersOutput = shell_exec($encodersCmd);
        $allEncoders = is_string($encodersOutput) ? $encodersOutput : '';

        // Map hwaccel name → encoder suffixes to check
        $encoderMap = [
            'cuda'          => ['h264_nvenc', 'hevc_nvenc'],
            'qsv'           => ['h264_qsv', 'hevc_qsv'],
            'vaapi'         => ['h264_vaapi', 'hevc_vaapi'],
            'opencl'        => ['hevc_cl'],
            'videotoolbox'  => ['h264_videotoolbox', 'hevc_videotoolbox'],
            'amf'           => ['h264_amf', 'hevc_amf'],
            'd3d11va'       => ['h264_d3d11va', 'hevc_d3d11va'],
            'dxva2'         => ['h264_dxva2', 'hevc_dxva2'],
            'v4l2m2m'       => ['h264_v4l2m2m', 'hevc_v4l2m2m'],
        ];

        foreach ($hwaccelNames as $name) {
            if (!isset($encoderMap[$name])) {
                // Unknown hwaccel — still expose it with no known encoders
                $accelerators[$name] = new HardwareAccelerator($name, [], true);
                continue;
            }

            $encoderSuffixes = $encoderMap[$name];
            $foundEncoders = [];

            foreach ($encoderSuffixes as $suffix) {
                if (str_contains($allEncoders, $suffix)) {
                    $foundEncoders[] = $suffix;
                }
            }

            $accelerators[$name] = new HardwareAccelerator($name, $foundEncoders, true);
        }

        $this->hardwareAccelerators = $accelerators;
        $this->logger->info('Hardware accelerators detected', [
            'accelerators' => array_keys($accelerators),
        ]);

        return $accelerators;
    }

    /**
     * Returns all available hardware accelerators as a flat array.
     *
     * Alias for {@see getHardwareAccelerators()} for callers that need a list.
     *
     * @return array<string, HardwareAccelerator>
     *
     * @since 0.36.0
     */
    public function getAvailableHardwareAccelerators(): array
    {
        return $this->getHardwareAccelerators();
    }

    /**
     * Returns the accelerator with the given name, or null if not available.
     *
     * @param string $name Accelerator name (e.g., 'cuda', 'qsv', 'vaapi')
     *
     * @return HardwareAccelerator|null
     *
     * @since 0.36.0
     */
    public function getAcceleratorByName(string $name): ?HardwareAccelerator
    {
        $accelerators = $this->getHardwareAccelerators();

        return $accelerators[$name] ?? null;
    }

    /**
     * Builds a gapless-compatible FFmpeg HLS segment command.
     *
     * Gapless playback requires sample-accurate boundaries and no decoder
     * flush frames. This method extends buildSegmentCommand() with:
     * - `-no_dtk` flag to disable decoder flush (gapless key)
     * - Accurate timestamp anchoring for concatenation
     * - Uses -muxdelay 0 -muxpreload 0 for precise timing
     *
     * The resulting segments are designed to be concatenated without gaps
     * or overlaps in the audio timeline.
     *
     * @param string               $inputPath Source media file.
     * @param string               $outFile   Absolute path to write the .ts segment to.
     * @param float                $start     Segment start offset in seconds.
     * @param float                $duration  Segment length in seconds.
     * @param array<string, mixed> $params    Encode params (same as buildSegmentCommand).
     *
     * @return string The complete FFmpeg gapless segment command.
     *
     * @see self::buildSegmentCommand() For the standard (non-gapless) segment command
     *
     * @since 0.37.0
     */
    public function buildGaplessSegmentCommand(
        string $inputPath,
        string $outFile,
        float $start,
        float $duration,
        array $params
    ): string {
        $startArg = self::seconds($start);
        $durArg = self::seconds($duration);

        // Gapless: no decoder flush frames + accurate seeking
        $cmd = sprintf('%s -nostdin -y -hide_banner -loglevel error', escapeshellarg($this->ffmpegPath));
        $cmd .= ' -no_dtk';
        $cmd .= ' -ss ' . $startArg;
        $cmd .= ' -i ' . escapeshellarg($inputPath);
        $cmd .= ' -t ' . $durArg;

        // Only the first video + first audio track
        $cmd .= ' -map 0:v:0 -map 0:a:0? -dn -sn';

        // Video: same as buildSegmentCommand
        $videoCodec = self::paramString($params, 'video_codec') ?? 'libx264';
        if ($videoCodec === '') {
            $videoCodec = 'libx264';
        }
        if ($videoCodec === 'copy') {
            $cmd .= ' -c:v copy';
        } else {
            $cmd .= ' -c:v ' . $videoCodec;
            if ($videoCodec === 'libx264' || $videoCodec === 'libx265') {
                $cmd .= ' -preset ' . (self::paramString($params, 'preset') ?? 'veryfast');
                $defaultCrf = $videoCodec === 'libx265' ? 28 : 23;
                $cmd .= ' -crf ' . (self::paramInt($params, 'crf') ?? $defaultCrf);
            }
            $width = self::paramInt($params, 'width');
            $height = self::paramInt($params, 'height');
            if ($width !== null && $height !== null) {
                $cmd .= ' -vf "scale=' . $width . ':' . $height . ':force_original_aspect_ratio=decrease"';
            }
            $cmd .= ' -force_key_frames ' . escapeshellarg('expr:gte(t,0)');
            $cmd .= self::browserSafeVideoFlags($videoCodec, $params);
        }

        // Audio: re-encode for gapless (no priming issues with copy)
        $audioCodec = self::paramString($params, 'audio_codec') ?? 'aac';
        if ($audioCodec === '') {
            $audioCodec = 'aac';
        }
        if ($audioCodec === 'copy') {
            $cmd .= ' -c:a copy';
        } else {
            $cmd .= ' -c:a ' . $audioCodec;
            $cmd .= ' -b:a ' . (self::paramString($params, 'audio_bitrate') ?? '128k');
            $audioChannels = self::paramInt($params, 'audio_channels');
            if ($audioChannels !== null && $audioChannels > 0) {
                $cmd .= ' -ac ' . $audioChannels;
            }
        }

        // Gapless timestamp anchoring: zero mux delay for sample-accurate concatenation
        $cmd .= ' -muxdelay 0 -muxpreload 0';
        $cmd .= ' -output_ts_offset ' . $startArg;
        $cmd .= ' -f mpegts ' . escapeshellarg($outFile);

        return $cmd;
    }

    /**
     * Returns the best accelerator for a given codec.
     *
     * Preference order:
     *  1. Configured preferred accelerator (if it supports the codec)
     *  2. First available accelerator that has an encoder for the codec
     *
     * @param string $codec Codec name (e.g., 'h264', 'hevc', 'av1')
     *
     * @return HardwareAccelerator|null Best matching accelerator or null if none support the codec
     *
     * @since 0.36.0
     */
    public function getBestAcceleratorForCodec(string $codec): ?HardwareAccelerator
    {
        $accelerators = $this->getHardwareAccelerators();

        // Prefer configured preferred accelerator first
        if ($this->preferredAccelerator !== null) {
            $preferred = $accelerators[$this->preferredAccelerator] ?? null;
            if ($preferred !== null && $preferred->supportsCodec($codec)) {
                return $preferred;
            }
        }

        // Fall back to first accelerator that has an encoder for this codec
        foreach ($accelerators as $accelerator) {
            if ($accelerator->supportsCodec($codec)) {
                return $accelerator;
            }
        }

        return null;
    }
}
