<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel\VendorProbe;

use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\VendorProbeInterface;
use Psr\Log\LoggerInterface;

/**
 * Apple VideoToolbox hardware acceleration probe.
 *
 * Detects macOS VideoToolbox support for hardware encoding/decoding.
 *
 * @since 0.11.0
 */
class VideoToolboxProbe implements VendorProbeInterface
{
    private const VENDOR_NAME = 'videotoolbox';

    public function getVendorName(): string
    {
        return self::VENDOR_NAME;
    }

    public function isAvailable(): bool
    {
        if (PHP_OS !== 'Darwin') {
            return false;
        }

        $output = shell_exec('system_profiler SPDisplaysDataType 2>/dev/null | grep -i "Intel\|Apple\|AMD\|NVIDIA" || true');

        return is_string($output) && trim($output) !== '';
    }

    public function probe(string $ffmpeg_path, ?LoggerInterface $logger = null): ?HwaccelCapability
    {
        if (!$this->isAvailable()) {
            $logger?->debug('VideoToolbox not available: not on macOS or no GPU found');
            return null;
        }

        $encoders = $this->getEncoders($ffmpeg_path);
        if ($encoders === []) {
            $logger?->debug('VideoToolbox encoders not found in ffmpeg');
            return null;
        }

        $supported_codecs = $this->detectSupportedCodecs($encoders);

        return new HwaccelCapability(
            vendor: self::VENDOR_NAME,
            encoder: $encoders[0],
            decoder: $encoders[0],
            supports_hdr_tone_mapping: true,
            supported_codecs: $supported_codecs,
            supported_profiles: ['baseline', 'main', 'high'],
            max_resolution_w: 3840,
            max_resolution_h: 2160,
            max_bitrate: 50000000,
            extra_args: [],
        );
    }

    public function runAcceptanceTest(string $ffmpeg_path, string $test_clip_path, ?LoggerInterface $logger = null): bool
    {
        if (!file_exists($test_clip_path)) {
            $logger?->warning('Test clip not found', ['path' => $test_clip_path]);
            return false;
        }

        $output_file = sys_get_temp_dir() . '/videotoolbox_test_' . uniqid() . '.mp4';

        try {
            $cmd = sprintf(
                '%s -y -i %s -c:v h264_videotoolbox -t 1 -frames 1 %s 2>&1',
                escapeshellarg($ffmpeg_path),
                escapeshellarg($test_clip_path),
                escapeshellarg($output_file)
            );

            $logger?->debug('Running VideoToolbox acceptance test', ['command' => $cmd]);

            exec($cmd, $output, $exit_code);
            $success = $exit_code === 0 && file_exists($output_file);

            if (file_exists($output_file)) {
                unlink($output_file);
            }

            return $success;
        } catch (\Throwable $e) {
            $logger?->error('VideoToolbox acceptance test failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @return array<string>
     */
    private function getEncoders(string $ffmpeg_path): array
    {
        $output = shell_exec(sprintf('%s -encoders 2>/dev/null | grep videotoolbox', escapeshellarg($ffmpeg_path)));
        $encoders = [];

        if (is_string($output) && preg_match_all('/([\w]+_videotoolbox)\s/', $output, $matches)) {
            $encoders = $matches[1];
        }

        return array_unique($encoders);
    }

    /**
     * @param array<int, string> $encoders
     * @return array<int, string>
     */
    private function detectSupportedCodecs(array $encoders): array
    {
        $codecs = [];

        foreach ($encoders as $iterableValue) {
            $encoder = $iterableValue;
            if (str_starts_with($encoder, 'h264_videotoolbox')) {
                $codecs[] = 'h264';
            }
            if (str_starts_with($encoder, 'hevc_videotoolbox')) {
                $codecs[] = 'hevc';
            }
            if (str_starts_with($encoder, 'prores_videotoolbox')) {
                $codecs[] = 'prores';
            }
            if (str_starts_with($encoder, 'vp9_videotoolbox')) {
                $codecs[] = 'vp9';
            }
        }

        return $codecs !== [] ? $codecs : ['h264', 'hevc'];
    }
}
