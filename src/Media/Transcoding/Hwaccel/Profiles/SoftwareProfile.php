<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel\Profiles;

use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;

/**
 * Software encoder fallback profile.
 *
 * Wraps the existing libx264/libx265 logic with consistent preset/bitrate/CRF
 * mapping. This is the reference implementation - its behavior for libx264/libx265
 * must exactly match the existing FfmpegRunner::buildTranscodeCommand() output.
 *
 * @since 0.11.0
 */
final class SoftwareProfile implements HwaccelEncoderProfileInterface
{
    /** @var array<string, array{bitrate: int, crf: int, preset: string}> Quality level mappings */
    private const QUALITY_MAPPINGS = [
        'ultra' => ['bitrate' => 8000000, 'crf' => 18, 'preset' => 'slow'],
        'high' => ['bitrate' => 5000000, 'crf' => 20, 'preset' => 'medium'],
        'medium' => ['bitrate' => 2500000, 'crf' => 23, 'preset' => 'medium'],
        'low' => ['bitrate' => 1000000, 'crf' => 26, 'preset' => 'fast'],
    ];

    /** @var array<string, string> Codec to FFmpeg encoder name mapping */
    private const CODEC_MAP = [
        'h264' => 'libx264',
        'hevc' => 'libx265',
        'av1' => 'libaom-av1',
    ];

    /**
     * {@inheritdoc}
     */
    public function getVendor(): string
    {
        return 'software';
    }

    /**
     * {@inheritdoc}
     */
    public function getEncoderName(string $codec): string
    {
        $codec = strtolower($codec);

        return self::CODEC_MAP[$codec] ?? 'libx264';
    }

    /**
     * {@inheritdoc}
     */
    public function getInputDeviceArgs(HwaccelCapability $capability): string
    {
        return '';
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
        $mapping = self::QUALITY_MAPPINGS[$quality_level] ?? self::QUALITY_MAPPINGS['medium'];

        $args = sprintf(' -preset %s -crf %d', $mapping['preset'], $mapping['crf']);

        if ($target_bitrate > 0) {
            $args = sprintf(' -preset %s -b:v %d', $mapping['preset'], $target_bitrate);
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

        $filterChain = [];

        foreach ($filters as $filter) {
            $filterChain[] = match ($filter) {
                'deinterlace' => 'yadif=0:-1',
                'denoise' => 'hqdn3d=4:3:6:4',
                'scale' => 'scale=-2:720',
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
        return 0;
    }
}
