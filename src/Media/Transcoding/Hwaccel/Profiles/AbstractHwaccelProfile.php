<?php

declare(strict_types=1);

namespace Phlex\Media\Transcoding\Hwaccel\Profiles;

use Phlex\Media\Transcoding\Hwaccel\HwaccelCapability;

/**
 * Abstract base class for hardware encoder profiles.
 *
 * Provides common functionality shared by all vendor-specific profiles.
 *
 * @since 0.11.0
 */
abstract class AbstractHwaccelProfile implements HwaccelEncoderProfileInterface
{
    /** @var array<string, array{bitrate: int, preset: string, bframes: int}> Quality level mappings */
    protected array $qualityMappings = [];

    /** @var array<string, string> Codec to FFmpeg encoder name mapping */
    protected array $codecMap = [
        'h264' => 'h264_nvenc',
        'hevc' => 'hevc_nvenc',
        'av1' => 'av1_nvenc',
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
    public function getEncoderName(string $codec): string
    {
        $codec = strtolower($codec);

        return $this->codecMap[$codec] ?? 'h264_nvenc';
    }

    /**
     * {@inheritdoc}
     */
    public function getInputDeviceArgs(HwaccelCapability $capability): string
    {
        $device = isset($capability->extra_args['device']) && is_string($capability->extra_args['device'])
            ? $capability->extra_args['device']
            : '0';

        return sprintf(' -hwaccel cuda -hwaccel_device %s', $device);
    }

    /**
     * {@inheritdoc}
     */
    public function getCodecArg(HwaccelCapability $capability, string $codec): string
    {
        return sprintf(' -c:v %s', $this->getEncoderName($codec));
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

        $args .= sprintf(' -b:v %d', $target_bitrate);

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

        $filterChain = [];

        foreach ($filters as $filter) {
            $filterChain[] = match ($filter) {
                'deinterlace' => 'yadif',
                'denoise' => 'hqdn3d',
                'deblock' => 'debend',
                'sharpen' => 'unsharp',
                default => $filter,
            };
        }

        return sprintf(' -vf "%s"', implode(',', $filterChain));
    }

    /**
     * {@inheritdoc}
     */
    public function getMaxConcurrent(): int
    {
        return 3;
    }
}
