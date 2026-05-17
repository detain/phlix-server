<?php

declare(strict_types=1);

namespace Phlex\Media\Transcoding\Hwaccel\VendorProbe;

use Phlex\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlex\Media\Transcoding\Hwaccel\VendorProbeInterface;
use Psr\Log\LoggerInterface;

/**
 * VAAPI (Video Acceleration API) hardware acceleration probe.
 *
 * Detects VAAPI support via libva /dev nodes and VA-API drivers.
 *
 * @since 0.11.0
 */
class VaapiProbe implements VendorProbeInterface
{
    private const VENDOR_NAME = 'vaapi';
    private const ENCODER_PREFIX = 'hevc_vaapi';
    private const DECODER_PREFIX = 'hevc_vaapi';

    public function getVendorName(): string
    {
        return self::VENDOR_NAME;
    }

    public function isAvailable(): bool
    {
        if (!is_dir('/dev/dri')) {
            return false;
        }

        $devices = glob('/dev/dri/render*');
        if ($devices === false || $devices === []) {
            return false;
        }

        $output = shell_exec('vainfo 2>/dev/null || true');

        return is_string($output) && trim($output) !== '';
    }

    public function probe(string $ffmpeg_path, ?LoggerInterface $logger = null): ?HwaccelCapability
    {
        if (!$this->isAvailable()) {
            $logger?->debug('VAAPI not available: no render devices or vainfo failed');
            return null;
        }

        $encoders = $this->getEncoders($ffmpeg_path);
        if ($encoders === []) {
            $logger?->debug('VAAPI encoders not found in ffmpeg');
            return null;
        }

        $supported_codecs = $this->detectSupportedCodecs($encoders);
        $hdr_tone_mapping = $this->checkHdrSupport($ffmpeg_path);

        return new HwaccelCapability(
            vendor: self::VENDOR_NAME,
            encoder: $encoders[0],
            decoder: $encoders[0],
            supports_hdr_tone_mapping: $hdr_tone_mapping,
            supported_codecs: $supported_codecs,
            supported_profiles: ['main', 'main10', 'main12'],
            max_resolution_w: 7680,
            max_resolution_h: 4320,
            max_bitrate: 40000000,
            extra_args: [],
        );
    }

    public function runAcceptanceTest(string $ffmpeg_path, string $test_clip_path, ?LoggerInterface $logger = null): bool
    {
        if (!file_exists($test_clip_path)) {
            $logger?->warning('Test clip not found', ['path' => $test_clip_path]);
            return false;
        }

        $output_file = sys_get_temp_dir() . '/vaapi_test_' . uniqid() . '.mp4';

        try {
            $cmd = sprintf(
                '%s -y -vaapi_device /dev/dri/renderD128 -i %s -vf "format=nv12,hwupload" -c:v hevc_vaapi -t 1 -frames 1 %s 2>&1',
                escapeshellarg($ffmpeg_path),
                escapeshellarg($test_clip_path),
                escapeshellarg($output_file)
            );

            $logger?->debug('Running VAAPI acceptance test', ['command' => $cmd]);

            exec($cmd, $output, $exit_code);
            $success = $exit_code === 0 && file_exists($output_file);

            if (file_exists($output_file)) {
                unlink($output_file);
            }

            return $success;
        } catch (\Throwable $e) {
            $logger?->error('VAAPI acceptance test failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @return array<int, string>
     */
    private function getEncoders(string $ffmpeg_path): array
    {
        $output = shell_exec(sprintf('%s -encoders 2>/dev/null | grep vaapi', escapeshellarg($ffmpeg_path)));
        $encoders = [];

        if (is_string($output) && preg_match_all('/([\w]+_vaapi)\s/', $output, $matches)) {
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

        foreach ($encoders as $encoder) {
            if (str_starts_with($encoder, 'h264_vaapi')) {
                $codecs[] = 'h264';
            }
            if (str_starts_with($encoder, 'hevc_vaapi')) {
                $codecs[] = 'hevc';
            }
            if (str_starts_with($encoder, 'vp9_vaapi')) {
                $codecs[] = 'vp9';
            }
            if (str_starts_with($encoder, 'vp8_vaapi')) {
                $codecs[] = 'vp8';
            }
            if (str_starts_with($encoder, 'av1_vaapi')) {
                $codecs[] = 'av1';
            }
            if (str_starts_with($encoder, 'mjpeg_vaapi')) {
                $codecs[] = 'mjpeg';
            }
        }

        return $codecs !== [] ? $codecs : ['h264', 'hevc'];
    }

    private function checkHdrSupport(string $ffmpeg_path): bool
    {
        $vainfo = shell_exec('vainfo 2>/dev/null || true');

        if (!is_string($vainfo)) {
            return false;
        }

        $lower = strtolower($vainfo);

        return str_contains($lower, '10bit')
            || str_contains($lower, '10-bit')
            || str_contains($lower, 'main10');
    }
}
