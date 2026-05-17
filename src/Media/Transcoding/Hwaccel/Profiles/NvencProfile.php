<?php

declare(strict_types=1);

namespace Phlex\Media\Transcoding\Hwaccel\Profiles;

use Phlex\Media\Transcoding\Hwaccel\HwaccelCapability;

/**
 * NVIDIA NVENC hardware encoder profile.
 *
 * Uses NVIDIA GPUs for hardware-accelerated H.264/H.265/AV1 encoding.
 * Supports preset tuning (p1-p7), zerolatency tune, and multipass encoding.
 *
 * @since 0.11.0
 */
final class NvencProfile extends AbstractHwaccelProfile
{
    /** @inheritdoc */
    protected array $qualityMappings = [
        'ultra' => ['bitrate' => 8000000, 'preset' => 'p3', 'bframes' => 0],
        'high' => ['bitrate' => 5000000, 'preset' => 'p4', 'bframes' => 0],
        'medium' => ['bitrate' => 2500000, 'preset' => 'p5', 'bframes' => 0],
        'low' => ['bitrate' => 1000000, 'preset' => 'p6', 'bframes' => 0],
    ];

    /**
     * {@inheritdoc}
     */
    public function getVendor(): string
    {
        return 'nvenc';
    }

    /**
     * {@inheritdoc}
     */
    public function getInputDeviceArgs(HwaccelCapability $capability): string
    {
        $device = isset($capability->extra_args['device_index']) && is_int($capability->extra_args['device_index'])
            ? $capability->extra_args['device_index']
            : 0;

        return sprintf(' -hwaccel cuda -hwaccel_device %d', $device);
    }

    /**
     * {@inheritdoc}
     */
    public function getCodecArg(HwaccelCapability $capability, string $codec): string
    {
        $encoder = $this->getEncoderName($codec);

        return sprintf(' -c:v %s', $encoder);
    }

    /**
     * {@inheritdoc}
     */
    public function getQualityArgs(string $quality_level, int $target_bitrate): string
    {
        $mapping = $this->qualityMappings[$quality_level] ?? $this->qualityMappings['medium'];

        $args = sprintf(' -preset %s -tune zerolatency', $mapping['preset']);

        if ($mapping['bframes'] === 0) {
            $args .= ' -bf 0';
        }

        if ($target_bitrate > 0) {
            $maxrate = (int) ($target_bitrate * 1.5);
            $bufsize = (int) ($target_bitrate * 2);
            $args .= sprintf(' -b:v %d -maxrate %d -bufsize %d', $target_bitrate, $maxrate, $bufsize);
        }

        return $args;
    }

    /**
     * {@inheritdoc}
     */
    public function getFilterArgs(array $filters): string
    {
        if ($filters === []) {
            return '';
        }

        $parts = [];

        foreach ($filters as $filter) {
            $parts[] = match ($filter) {
                'deinterlace' => 'yadif=0:-1',
                'denoise' => 'hqdn3d=4:3:6:4',
                'scale' => 'scale=-2:720',
                'hwupload' => 'hwupload',
                default => $filter,
            };
        }

        return sprintf(' -vf "%s"', implode(',', $parts));
    }

    /**
     * {@inheritdoc}
     */
    public function getMaxConcurrent(): int
    {
        return 3;
    }

    /**
     * Returns the NVENC preset name for a quality level.
     *
     * @param string $quality_level Quality level
     *
     * @return string Preset name (p1-p7)
     *
     * @since 0.11.0
     */
    public function getPresetForQuality(string $quality_level): string
    {
        return $this->qualityMappings[$quality_level]['preset'] ?? 'p4';
    }
}
