<?php

declare(strict_types=1);

namespace Phlex\Media\Transcoding\Hwaccel\Profiles;

use Phlex\Media\Transcoding\Hwaccel\HwaccelCapability;

/**
 * AMD AMF (Advanced Media Framework) hardware encoder profile.
 *
 * Uses AMD GPUs via AMF for hardware-accelerated encoding.
 * Supports H.264 and HEVC encoding with quality/preset mapping.
 *
 * @since 0.11.0
 */
final class AmfProfile extends AbstractHwaccelProfile
{
    /** @inheritdoc */
    protected array $qualityMappings = [
        'ultra' => ['bitrate' => 8000000, 'preset' => 'speed', 'bframes' => 0],
        'high' => ['bitrate' => 5000000, 'preset' => 'balanced', 'bframes' => 0],
        'medium' => ['bitrate' => 2500000, 'preset' => 'quality', 'bframes' => 0],
        'low' => ['bitrate' => 1000000, 'preset' => 'speed', 'bframes' => 0],
    ];

    /** @var array<string, string> */
    protected array $codecMap = [
        'h264' => 'h264_amf',
        'hevc' => 'hevc_amf',
    ];

    /**
     * {@inheritdoc}
     */
    public function getVendor(): string
    {
        return 'amf';
    }

    /**
     * {@inheritdoc}
     */
    public function getInputDeviceArgs(HwaccelCapability $capability): string
    {
        return ' -hwaccel d3d11va';
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
            $args .= sprintf(' -b:v %d -maxrate %d', $target_bitrate, (int) ($target_bitrate * 1.5));
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
        return 2;
    }
}
