<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel\VendorProbe;

use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\HwaccelEncodeFailedException;
use Phlix\Media\Transcoding\Hwaccel\VendorProbeInterface;
use Psr\Log\LoggerInterface;

/**
 * NVIDIA NVENC hardware acceleration probe.
 *
 * Detects NVIDIA GPU acceleration via NVENC/NVDEC encoders.
 *
 * @since 0.11.0
 */
class NvencProbe implements VendorProbeInterface
{
    private const VENDOR_NAME = 'nvenc';
    private const ENCODER_PREFIX = 'h264_nvenc';

    public function getVendorName(): string
    {
        return self::VENDOR_NAME;
    }

    public function isAvailable(): bool
    {
        if (!file_exists('/usr/bin/nvidia-smi') && !file_exists('/usr/bin/nvidia-smi')) {
            return false;
        }

        $output = shell_exec('nvidia-smi --query-gpu=name --format=csv,noheader 2>/dev/null');

        return is_string($output) && trim($output) !== '';
    }

    public function probe(string $ffmpeg_path, ?LoggerInterface $logger = null): ?HwaccelCapability
    {
        if (!$this->isAvailable()) {
            $logger?->debug('NVENC not available: nvidia-smi not found or returned no GPU');
            return null;
        }

        $encoders = $this->getEncoders($ffmpeg_path);
        if (!in_array(self::ENCODER_PREFIX, $encoders, true)) {
            $logger?->debug('NVENC encoder not found in ffmpeg');
            return null;
        }

        $decoders = $this->getDecoders($ffmpeg_path);
        $supported_codecs = $this->detectSupportedCodecs($encoders);
        $hdr_tone_mapping = $this->checkHdrToneMapping($ffmpeg_path);

        return new HwaccelCapability(
            vendor: self::VENDOR_NAME,
            encoder: self::ENCODER_PREFIX,
            decoder: $decoders[0] ?? 'hevc_cuvid',
            supports_hdr_tone_mapping: $hdr_tone_mapping,
            supported_codecs: $supported_codecs,
            supported_profiles: ['baseline', 'main', 'high', 'pro'],
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

        $output_file = sys_get_temp_dir() . '/nvenc_test_' . uniqid() . '.mp4';

        try {
            $cmd = sprintf(
                '%s -y -hwaccel_device 0 -hwaccel cuvid -i %s -c:v h264_nvenc -preset p4 -t 1 -frames 1 %s 2>&1',
                escapeshellarg($ffmpeg_path),
                escapeshellarg($test_clip_path),
                escapeshellarg($output_file)
            );

            $logger?->debug('Running NVENC acceptance test', ['command' => $cmd]);

            exec($cmd, $output, $exit_code);
            $success = $exit_code === 0 && file_exists($output_file);

            if (file_exists($output_file)) {
                unlink($output_file);
            }

            return $success;
        } catch (\Throwable $e) {
            $logger?->error('NVENC acceptance test failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @return array<int, string>
     */
    private function getEncoders(string $ffmpeg_path): array
    {
        $output = shell_exec(sprintf('%s -encoders 2>/dev/null | grep nvenc', escapeshellarg($ffmpeg_path)));
        $encoders = [];

        if (is_string($output) && preg_match_all('/([\w]+_nvenc)\s/', $output, $matches)) {
            $encoders = $matches[1];
        }

        return $encoders;
    }

    /**
     * @return array<int, string>
     */
    private function getDecoders(string $ffmpeg_path): array
    {
        $output = shell_exec(sprintf('%s -decoders 2>/dev/null | grep cuvid', escapeshellarg($ffmpeg_path)));
        $decoders = [];

        if (is_string($output) && preg_match_all('/([\w]+_cuvid)\s/', $output, $matches)) {
            $decoders = $matches[1];
        }

        return $decoders;
    }

    /**
     * @param array<int, string> $encoders
     * @return array<int, string>
     */
    private function detectSupportedCodecs(array $encoders): array
    {
        $codecs = ['h264'];

        foreach ($encoders as $encoder) {
            if (str_starts_with($encoder, 'hevc_nvenc')) {
                $codecs[] = 'hevc';
            }
            if (str_starts_with($encoder, 'av1_nvenc')) {
                $codecs[] = 'av1';
            }
            if (str_starts_with($encoder, 'prores_nvenc')) {
                $codecs[] = 'prores';
            }
        }

        return $codecs;
    }

    private function checkHdrToneMapping(string $ffmpeg_path): bool
    {
        $output = shell_exec(sprintf(
            '%s -help 2>&1 | grep -i "tonemap" || true',
            escapeshellarg($ffmpeg_path)
        ));

        return is_string($output) && str_contains(strtolower($output), 'tonemap');
    }
}
