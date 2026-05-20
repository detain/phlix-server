<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel\VendorProbe;

use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\VendorProbeInterface;
use Psr\Log\LoggerInterface;

/**
 * Intel Quick Sync Video (QSV) hardware acceleration probe.
 *
 * Detects Intel QSV support via libva and VAAPI Intel driver.
 *
 * @since 0.11.0
 */
class QsvProbe implements VendorProbeInterface
{
    private const VENDOR_NAME = 'qsv';

    public function getVendorName(): string
    {
        return self::VENDOR_NAME;
    }

    public function isAvailable(): bool
    {
        $output = shell_exec('vainfo 2>/dev/null | grep -i "Intel" || true');

        return is_string($output) && trim($output) !== '';
    }

    public function probe(string $ffmpeg_path, ?LoggerInterface $logger = null): ?HwaccelCapability
    {
        if (!$this->isAvailable()) {
            $logger?->debug('QSV not available: no Intel GPU found via vainfo');
            return null;
        }

        $encoders = $this->getEncoders($ffmpeg_path);
        if ($encoders === []) {
            $logger?->debug('QSV encoders not found in ffmpeg');
            return null;
        }

        $supported_codecs = $this->detectSupportedCodecs($encoders);
        $hdr_tone_mapping = $this->checkQsvHdrSupport();

        return new HwaccelCapability(
            vendor: self::VENDOR_NAME,
            encoder: $encoders[0],
            decoder: $encoders[0],
            supports_hdr_tone_mapping: $hdr_tone_mapping,
            supported_codecs: $supported_codecs,
            supported_profiles: ['main', 'high'],
            max_resolution_w: 3840,
            max_resolution_h: 2160,
            max_bitrate: 30000000,
            extra_args: ['-device' => '/dev/dri/renderD128'],
        );
    }

    public function runAcceptanceTest(string $ffmpeg_path, string $test_clip_path, ?LoggerInterface $logger = null): bool
    {
        if (!file_exists($test_clip_path)) {
            $logger?->warning('Test clip not found', ['path' => $test_clip_path]);
            return false;
        }

        $output_file = sys_get_temp_dir() . '/qsv_test_' . uniqid() . '.mp4';

        try {
            $cmd = sprintf(
                '%s -y -vaapi_device /dev/dri/renderD128 -i %s -vf "format=nv12,hwupload=extra_hw_frames=64" -c:v h264_qsv -preset fast -t 1 -frames 1 %s 2>&1',
                escapeshellarg($ffmpeg_path),
                escapeshellarg($test_clip_path),
                escapeshellarg($output_file)
            );

            $logger?->debug('Running QSV acceptance test', ['command' => $cmd]);

            exec($cmd, $output, $exit_code);
            $success = $exit_code === 0 && file_exists($output_file);

            if (file_exists($output_file)) {
                unlink($output_file);
            }

            return $success;
        } catch (\Throwable $e) {
            $logger?->error('QSV acceptance test failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @return array<string>
     */
    private function getEncoders(string $ffmpeg_path): array
    {
        $output = shell_exec(sprintf('%s -encoders 2>/dev/null | grep qsv', escapeshellarg($ffmpeg_path)));
        $encoders = [];

        if (is_string($output) && preg_match_all('/([\w]+_qsv)\s/', $output, $matches)) {
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
            if (str_starts_with($encoder, 'h264_qsv')) {
                $codecs[] = 'h264';
            }
            if (str_starts_with($encoder, 'hevc_qsv')) {
                $codecs[] = 'hevc';
            }
            if (str_starts_with($encoder, 'vp9_qsv')) {
                $codecs[] = 'vp9';
            }
            if (str_starts_with($encoder, 'av1_qsv')) {
                $codecs[] = 'av1';
            }
            if (str_starts_with($encoder, 'mpeg2_qsv')) {
                $codecs[] = 'mpeg2';
            }
        }

        return $codecs !== [] ? $codecs : ['h264', 'hevc'];
    }

    private function checkQsvHdrSupport(): bool
    {
        $vainfo = shell_exec('vainfo 2>/dev/null | grep -i "10-bit\|10bit\|main10" || true');

        return is_string($vainfo) && trim($vainfo) !== '';
    }
}
