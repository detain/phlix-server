<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel\Profiles;

use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;

/**
 * VAAPI (Video Acceleration API) hardware encoder profile.
 *
 * Uses Intel/AMD GPUs via VA-API for hardware-accelerated encoding.
 * Supports Linux VAAPI driver with various rate control modes.
 *
 * @since 0.11.0
 */
final class VaapiProfile extends AbstractHwaccelProfile
{
    /** @inheritdoc */
    protected array $qualityMappings = [
        'ultra' => ['bitrate' => 8000000, 'preset' => 'fast', 'bframes' => 0],
        'high' => ['bitrate' => 5000000, 'preset' => 'fast', 'bframes' => 0],
        'medium' => ['bitrate' => 2500000, 'preset' => 'medium', 'bframes' => 0],
        'low' => ['bitrate' => 1000000, 'preset' => 'slow', 'bframes' => 0],
    ];

    /** @var array<string, string> */
    protected array $codecMap = [
        'h264' => 'h264_vaapi',
        'hevc' => 'hevc_vaapi',
    ];

    /**
     * {@inheritdoc}
     */
    public function getVendor(): string
    {
        return 'vaapi';
    }

    /**
     * {@inheritdoc}
     */
    public function getInputDeviceArgs(HwaccelCapability $capability): string
    {
        $device = isset($capability->extra_args['device']) && is_string($capability->extra_args['device'])
            ? $capability->extra_args['device']
            : '/dev/dri/renderD128';

        return sprintf(' -vaapi_device %s', $device);
    }

    /**
     * {@inheritdoc}
     */
    public function getCodecArg(HwaccelCapability $capability, string $codec): string
    {
        $encoder = $this->getEncoderName($codec);

        return sprintf(' -c:v %s -rc_mode CQP', $encoder);
    }

    /**
     * {@inheritdoc}
     */
    public function getQualityArgs(string $quality_level, int $target_bitrate): string
    {
        $args = ' -global_quality 23';

        if ($target_bitrate > 0) {
            $args = sprintf(' -rc_mode VBR -maxrate %d -bufsize %d', $target_bitrate, (int) ($target_bitrate * 2));
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

        $parts = ['format=nv12', 'hwupload'];

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
        return 4;
    }
}
