<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel\Profiles;

use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;

/**
 * Apple VideoToolbox hardware encoder profile.
 *
 * Uses macOS VideoToolbox framework for hardware-accelerated encoding.
 * Supports H.264 and HEVC encoding with hardware frame management.
 *
 * @since 0.11.0
 */
final class VideoToolboxProfile extends AbstractHwaccelProfile
{
    /** @inheritdoc */
    protected array $qualityMappings = [
        'ultra' => ['bitrate' => 8000000, 'preset' => 'fast', 'bframes' => 0],
        'high' => ['bitrate' => 5000000, 'preset' => 'balanced', 'bframes' => 0],
        'medium' => ['bitrate' => 2500000, 'preset' => 'quality', 'bframes' => 0],
        'low' => ['bitrate' => 1000000, 'preset' => 'real-time', 'bframes' => 0],
    ];

    /** @var array<string, string> */
    protected array $codecMap = [
        'h264' => 'h264_videotoolbox',
        'hevc' => 'hevc_videotoolbox',
    ];

    /**
     * {@inheritdoc}
     */
    public function getVendor(): string
    {
        return 'videotoolbox';
    }

    /**
     * {@inheritdoc}
     */
    public function getInputDeviceArgs(HwaccelCapability $capability): string
    {
        return ' -hwaccel videotoolbox';
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

        $args = sprintf(' -preset %s', $mapping['preset']);

        if ($target_bitrate > 0) {
            $args .= sprintf(' -b:v %d', $target_bitrate);
        } else {
            $args .= ' -q:v 70';
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
                'deinterlace' => 'deinterlace=1',
                'denoise' => 'hqdn3d',
                'scale' => 'scale=-2:720',
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
        return 0;
    }
}
