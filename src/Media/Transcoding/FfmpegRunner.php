<?php

declare(strict_types=1);

namespace Phlex\Media\Transcoding;

use Phlex\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlex\Media\Transcoding\Hwaccel\HwaccelRegistry;
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
        if (!$output) {
            return null;
        }

        $data = json_decode($output, true);
        return is_array($data) ? $data : null;
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

        if (isset($params['video_codec'])) {
            $cmd .= ' -c:v ' . $params['video_codec'];

            switch ($params['video_codec']) {
                case 'libx264':
                    $cmd .= ' -preset ' . ($params['preset'] ?? 'medium');
                    $cmd .= ' -crf ' . ($params['crf'] ?? 23);
                    break;
                case 'libx265':
                    $cmd .= ' -preset ' . ($params['preset'] ?? 'medium');
                    $cmd .= ' -crf ' . ($params['crf'] ?? 28);
                    break;
            }
        }

        if (isset($params['width']) && isset($params['height'])) {
            $scaleFilter = "scale={$params['width']}:{$params['height']}:force_original_aspect_ratio=decrease";
            $cmd .= ' -vf "' . $scaleFilter . '"';
        }

        if (isset($params['audio_codec'])) {
            $cmd .= ' -c:a ' . $params['audio_codec'];
            $cmd .= ' -b:a ' . ($params['audio_bitrate'] ?? '128k');
            $cmd .= ' -ar ' . ($params['audio_sample_rate'] ?? 48000);

            if (isset($params['audio_channels'])) {
                $cmd .= ' -ac ' . $params['audio_channels'];
            }
        } else {
            $cmd .= ' -c:a copy';
        }

        if (isset($params['format'])) {
            $cmd .= ' -f ' . $params['format'];
        }

        if (($params['container'] ?? '') === 'mp4') {
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
     * @param int $timeSeconds Timestamp to capture frame (default: 10)
     *
     * @return bool True if thumbnail generation succeeded
     *
     * @example
     * ```php
     * $success = $runner->generateThumbnail('/video.mkv', '/thumb.jpg', 30);
     * ```
     */
    public function generateThumbnail(string $inputPath, string $outputPath, int $timeSeconds = 10): bool
    {
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
        if (preg_match('/ffmpeg version (\S+)/', $output, $matches)) {
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

        if (isset($params['crf'])) {
            $cmd .= ' -crf ' . $params['crf'];
        }

        if (isset($params['width']) && isset($params['height'])) {
            $scaleFilter = "scale={$params['width']}:{$params['height']}:force_original_aspect_ratio=decrease";
            $cmd .= ' -vf "' . $scaleFilter . '"';
        }

        if (isset($params['audio_codec'])) {
            $cmd .= ' -c:a ' . $params['audio_codec'];
            $cmd .= ' -b:a ' . ($params['audio_bitrate'] ?? '128k');
            $cmd .= ' -ar ' . ($params['audio_sample_rate'] ?? 48000);
        } else {
            $cmd .= ' -c:a copy';
        }

        if (isset($params['format'])) {
            $cmd .= ' -f ' . $params['format'];
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
}
