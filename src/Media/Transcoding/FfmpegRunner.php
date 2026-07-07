<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding;

use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\HwaccelCommandBuilder;
use Phlix\Media\Transcoding\Hwaccel\HwaccelProfileFactory;
use Phlix\Media\Transcoding\Hwaccel\HwaccelRegistry;
use Phlix\Media\Transcoding\Hwaccel\Profiles\HwaccelEncoderProfileInterface;
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
     * Probes a media file for technical information.
     *
     * Uses FFprobe to extract stream details and format information.
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

        $output = shell_exec($cmd);
        if (!is_string($output) || $output === '') {
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
     * Transcodes a media file with the given parameters.
     *
     * Builds the FFmpeg command, executes it with proper process management,
     * and returns success/failure status.
     *
     * @param string $inputPath Source media file path
     * @param string $outputPath Destination file path
     * @param array{
     *     video_codec?: string,
     *     audio_codec?: string,
     *     width?: int,
     *     height?: int,
     *     preset?: string,
     *     crf?: int,
     *     audio_bitrate?: string,
     *     audio_channels?: int,
     *     audio_sample_rate?: int,
     *     format?: string,
     *     container?: string
     * } $params Encoding parameters
     *
     * @return bool True if transcode succeeded (exit code 0)
     *
     * @example
     * ```php
     * $success = $runner->transcode('/input.mkv', '/output.mp4', [
     *     'video_codec' => 'libx264',
     *     'audio_codec' => 'aac',
     *     'width' => 1920,
     *     'height' => 1080,
     * ]);
     * ```
     */
    public function transcode(string $inputPath, string $outputPath, array $params): bool
    {
        $cmd = $this->buildTranscodeCommand($inputPath, $outputPath, $params);

        $this->logger->debug('Starting transcode', ['command' => $cmd]);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            $this->logger->error('Failed to start transcode process');
            return false;
        }

        fclose($pipes[0]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        fclose($pipes[1]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->logger->error('Transcode failed', ['exit_code' => $exitCode, 'stderr' => $stderr]);
            return false;
        }

        return true;
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
        if ($width !== null && $height !== null) {
            $scaleFilter = "scale={$width}:{$height}:force_original_aspect_ratio=decrease";
            $cmd .= ' -vf "' . $scaleFilter . '"';
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

        $this->logger->info('Hardware acceleration probed', [
            'vendors' => array_keys($capabilities),
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
    private static function paramString(array $params, string $key): ?string
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
    private static function paramInt(array $params, string $key): ?int
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

        // Accurate input seek BEFORE -i so the decode starts at the segment boundary.
        $cmd = sprintf('%s -nostdin -y -hide_banner -loglevel error', escapeshellarg($this->ffmpegPath));
        $cmd .= ' -ss ' . $startArg;
        $cmd .= ' -i ' . escapeshellarg($inputPath);
        $cmd .= ' -t ' . $durArg;

        // Only the first video + first audio track; drop data/subtitle/attachment
        // streams (anime MKVs carry font attachments + embedded subs that break the mux).
        $cmd .= ' -map 0:v:0 -map 0:a:0? -dn -sn';

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
            if ($width !== null && $height !== null) {
                $cmd .= ' -vf "scale=' . $width . ':' . $height . ':force_original_aspect_ratio=decrease"';
            }
            // IDR at the segment start → independently decodable, frame-aligned segment.
            $cmd .= ' -force_key_frames ' . escapeshellarg('expr:gte(t,0)');
            $cmd .= self::browserSafeVideoFlags($videoCodec, $params);
        }

        // Audio: re-encode to AAC by default, or a genuine stream copy when asked.
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
        $encode = $this->buildSegmentCommand($inputPath, $tmp, $start, $duration, $params);
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
}
