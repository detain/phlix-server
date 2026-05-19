<?php

declare(strict_types=1);

namespace Phlex\Media\Transcoding;

use Phlex\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlex\Media\Transcoding\Hwaccel\HwaccelCommandBuilder;
use Phlex\Media\Transcoding\Hwaccel\HwaccelProfileFactory;
use Phlex\Media\Transcoding\Hwaccel\HwaccelRegistry;
use Phlex\Media\Transcoding\Hwaccel\Profiles\HwaccelEncoderProfileInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * FFmpeg Runner - Executes FFmpeg and FFprobe commands for media transcoding.
 *
 * Provides a clean interface for probing media files and running transcode
 * operations with proper process management and error handling.
 *
 * @author Phlex Media Server Team
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
}
