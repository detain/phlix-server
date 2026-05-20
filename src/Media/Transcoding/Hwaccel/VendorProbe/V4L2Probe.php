<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel\VendorProbe;

use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\VendorProbeInterface;
use Psr\Log\LoggerInterface;

/**
 * V4L2 (Video4Linux2) hardware acceleration probe.
 *
 * Detects V4L2 encode/decode support for various codecs.
 *
 * @since 0.11.0
 */
class V4L2Probe implements VendorProbeInterface
{
    private const VENDOR_NAME = 'v4l2';

    public function getVendorName(): string
    {
        return self::VENDOR_NAME;
    }

    public function isAvailable(): bool
    {
        if (!is_dir('/dev/media0') && !is_dir('/dev/media1')) {
            $devices = glob('/dev/media*');
            if ($devices === false || $devices === []) {
                return false;
            }
        }

        $output = shell_exec('v4l2-ctl --list-devices 2>/dev/null || true');

        return is_string($output) && trim($output) !== '';
    }

    public function probe(string $ffmpeg_path, ?LoggerInterface $logger = null): ?HwaccelCapability
    {
        if (!$this->isAvailable()) {
            $logger?->debug('V4L2 not available: no media devices found');
            return null;
        }

        $encoders = $this->getEncoders($ffmpeg_path);
        if ($encoders === []) {
            $logger?->debug('V4L2 encoders not found in ffmpeg');
            return null;
        }

        $supported_codecs = $this->detectSupportedCodecs($encoders);

        return new HwaccelCapability(
            vendor: self::VENDOR_NAME,
            encoder: $encoders[0],
            decoder: $encoders[0],
            supports_hdr_tone_mapping: false,
            supported_codecs: $supported_codecs,
            supported_profiles: ['main', 'high'],
            max_resolution_w: 1920,
            max_resolution_h: 1080,
            max_bitrate: 20000000,
            extra_args: [],
        );
    }

    public function runAcceptanceTest(string $ffmpeg_path, string $test_clip_path, ?LoggerInterface $logger = null): bool
    {
        if (!file_exists($test_clip_path)) {
            $logger?->warning('Test clip not found', ['path' => $test_clip_path]);
            return false;
        }

        $output_file = sys_get_temp_dir() . '/v4l2_test_' . uniqid() . '.mp4';

        try {
            $cmd = sprintf(
                '%s -y -i %s -c:v h264_v4l2m2m -t 1 -frames 1 %s 2>&1',
                escapeshellarg($ffmpeg_path),
                escapeshellarg($test_clip_path),
                escapeshellarg($output_file)
            );

            $logger?->debug('Running V4L2 acceptance test', ['command' => $cmd]);

            exec($cmd, $output, $exit_code);
            $success = $exit_code === 0 && file_exists($output_file);

            if (file_exists($output_file)) {
                unlink($output_file);
            }

            return $success;
        } catch (\Throwable $e) {
            $logger?->error('V4L2 acceptance test failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @return array<string>
     */
    private function getEncoders(string $ffmpeg_path): array
    {
        $output = shell_exec(sprintf('%s -encoders 2>/dev/null | grep v4l2m2m', escapeshellarg($ffmpeg_path)));
        $encoders = [];

        if (is_string($output) && preg_match_all('/([\w]+_v4l2m2m)\s/', $output, $matches)) {
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
            if (str_starts_with($encoder, 'h264_v4l2m2m')) {
                $codecs[] = 'h264';
            }
            if (str_starts_with($encoder, 'hevc_v4l2m2m')) {
                $codecs[] = 'hevc';
            }
            if (str_starts_with($encoder, 'vp8_v4l2m2m')) {
                $codecs[] = 'vp8';
            }
            if (str_starts_with($encoder, 'vp9_v4l2m2m')) {
                $codecs[] = 'vp9';
            }
            if (str_starts_with($encoder, 'mpeg4_v4l2m2m')) {
                $codecs[] = 'mpeg4';
            }
            if (str_starts_with($encoder, 'mpeg2_v4l2m2m')) {
                $codecs[] = 'mpeg2';
            }
        }

        return $codecs !== [] ? $codecs : ['h264'];
    }
}
