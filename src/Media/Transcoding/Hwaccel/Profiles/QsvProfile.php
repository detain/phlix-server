<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel\Profiles;

use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;

/**
 * Intel Quick Sync Video (QSV) hardware encoder profile.
 *
 * Uses Intel integrated GPUs for hardware-accelerated encoding.
 * Supports look-ahead, B-frame, and various rate control modes.
 *
 * @since 0.11.0
 */
final class QsvProfile extends AbstractHwaccelProfile
{
    /** @inheritdoc */
    protected array $qualityMappings = [
        'ultra' => ['bitrate' => 8000000, 'preset' => 'veryfast', 'bframes' => 0],
        'high' => ['bitrate' => 5000000, 'preset' => 'faster', 'bframes' => 0],
        'medium' => ['bitrate' => 2500000, 'preset' => 'fast', 'bframes' => 0],
        'low' => ['bitrate' => 1000000, 'preset' => 'medium', 'bframes' => 0],
    ];

    /** @var array<string, string> */
    protected array $codecMap = [
        'h264' => 'h264_qsv',
        'hevc' => 'hevc_qsv',
        'av1' => 'av1_qsv',
    ];

    /**
     * {@inheritdoc}
     */
    public function getVendor(): string
    {
        return 'qsv';
    }

    /**
     * {@inheritdoc}
     */
    public function getInputDeviceArgs(HwaccelCapability $capability): string
    {
        $device = isset($capability->extra_args['device']) && is_string($capability->extra_args['device'])
            ? $capability->extra_args['device']
            : '/dev/dri/renderD128';

        return sprintf(' -qsv_device %s', $device);
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

        if ($mapping['bframes'] === 0) {
            $args .= ' -bf 0';
        }

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
                'vpp' => 'vpp',
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
        return 6;
    }
}
