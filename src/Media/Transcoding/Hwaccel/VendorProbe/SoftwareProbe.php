<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel\VendorProbe;

use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\VendorProbeInterface;
use Psr\Log\LoggerInterface;

/**
 * Software encoding fallback probe.
 *
 * Always returns a capability for software encoding (libx264/libx265).
 * This serves as the fallback when no hardware acceleration is available.
 *
 * @since 0.11.0
 */
class SoftwareProbe implements VendorProbeInterface
{
    private const VENDOR_NAME = 'software';

    public function getVendorName(): string
    {
        return self::VENDOR_NAME;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function probe(string $ffmpeg_path, ?LoggerInterface $logger = null): ?HwaccelCapability
    {
        $encoders = $this->getEncoders($ffmpeg_path);

        if ($encoders === []) {
            $logger?->warning('No software encoders found in ffmpeg');
            return null;
        }

        return new HwaccelCapability(
            vendor: self::VENDOR_NAME,
            encoder: 'libx264',
            decoder: 'libx264',
            supports_hdr_tone_mapping: false,
            supported_codecs: ['h264', 'hevc', 'vp8', 'vp9', 'av1', 'mpeg2', 'mpeg4'],
            supported_profiles: ['baseline', 'main', 'high', 'pro'],
            max_resolution_w: 7680,
            max_resolution_h: 4320,
            max_bitrate: 100000000,
            extra_args: [],
        );
    }

    public function runAcceptanceTest(string $ffmpeg_path, string $test_clip_path, ?LoggerInterface $logger = null): bool
    {
        if (!file_exists($test_clip_path)) {
            $logger?->warning('Test clip not found', ['path' => $test_clip_path]);
            return false;
        }

        $output_file = sys_get_temp_dir() . '/software_test_' . uniqid() . '.mp4';

        try {
            $cmd = sprintf(
                '%s -y -i %s -c:v libx264 -preset ultrafast -t 1 -frames 1 %s 2>&1',
                escapeshellarg($ffmpeg_path),
                escapeshellarg($test_clip_path),
                escapeshellarg($output_file)
            );

            $logger?->debug('Running software encoding acceptance test', ['command' => $cmd]);

            exec($cmd, $output, $exit_code);
            $success = $exit_code === 0 && file_exists($output_file);

            if (file_exists($output_file)) {
                unlink($output_file);
            }

            return $success;
        } catch (\Throwable $e) {
            $logger?->error('Software encoding acceptance test failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @return array<string>
     */
    private function getEncoders(string $ffmpeg_path): array
    {
        $output = shell_exec(sprintf('%s -encoders 2>/dev/null | grep -E "libx264|libx265|libvpx"', escapeshellarg($ffmpeg_path)));
        $encoders = [];

        if (is_string($output) && preg_match_all('/(libx264|libx265|libvpx|libvpx-vp9)\s/', $output, $matches)) {
            $encoders = $matches[1];
        }

        return $encoders;
    }
}
