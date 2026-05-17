<?php

declare(strict_types=1);

namespace Phlex\Media\Transcoding\Hwaccel\Profiles;

use Phlex\Media\Transcoding\Hwaccel\HwaccelCapability;

/**
 * Video4Linux2 (V4L2) hardware encoder profile.
 *
 * Uses Linux kernel V4L2 request API for hardware-accelerated encoding.
 * Supports various V4L2 devices with output format specification.
 *
 * @since 0.11.0
 */
final class V4L2Profile extends AbstractHwaccelProfile
{
    /** @inheritdoc */
    protected array $qualityMappings = [
        'ultra' => ['bitrate' => 8000000, 'preset' => 'fast', 'bframes' => 0],
        'high' => ['bitrate' => 5000000, 'preset' => 'medium', 'bframes' => 0],
        'medium' => ['bitrate' => 2500000, 'preset' => 'slow', 'bframes' => 0],
        'low' => ['bitrate' => 1000000, 'preset' => 'ultrafast', 'bframes' => 0],
    ];

    /** @var array<string, string> */
    protected array $codecMap = [
        'h264' => 'h264_v4l2m2m',
        'hevc' => 'hevc_v4l2m2m',
    ];

    /**
     * {@inheritdoc}
     */
    public function getVendor(): string
    {
        return 'v4l2';
    }

    /**
     * {@inheritdoc}
     */
    public function getInputDeviceArgs(HwaccelCapability $capability): string
    {
        $device = isset($capability->extra_args['device']) && is_string($capability->extra_args['device'])
            ? $capability->extra_args['device']
            : '/dev/video0';

        return sprintf(' -input_format h264 -i %s', $device);
    }

    /**
     * {@inheritdoc}
     */
    public function getCodecArg(HwaccelCapability $capability, string $codec): string
    {
        $encoder = $this->getEncoderName($codec);

        return sprintf(' -c:v %s -output_fmt %s', $encoder, 'yuv420p');
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
        return 1;
    }
}
