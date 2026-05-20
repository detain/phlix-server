<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel\ToneMapping;

/**
 * Factory for creating vendor-specific tone mapper instances.
 *
 * Provides access to the appropriate tone mapper implementation
 * for each hardware accelerator vendor.
 *
 * @since 0.11.0
 */
final class ToneMapperFactory
{
    /** @var array<string, class-string<HwaccelToneMapperInterface>> Tone mapper class map */
    private const TONEMAPPER_CLASSES = [
        'nvenc' => \Phlix\Media\Transcoding\Hwaccel\ToneMapping\Vendor\NvencToneMapper::class,
        'vaapi' => \Phlix\Media\Transcoding\Hwaccel\ToneMapping\Vendor\VaapiToneMapper::class,
        'qsv' => \Phlix\Media\Transcoding\Hwaccel\ToneMapping\Vendor\QsvToneMapper::class,
        'videotoolbox' => \Phlix\Media\Transcoding\Hwaccel\ToneMapping\Vendor\VideoToolboxToneMapper::class,
        'amf' => \Phlix\Media\Transcoding\Hwaccel\ToneMapping\Vendor\AmfToneMapper::class,
        'v4l2' => \Phlix\Media\Transcoding\Hwaccel\ToneMapping\Vendor\V4L2ToneMapper::class,
        'software' => \Phlix\Media\Transcoding\Hwaccel\ToneMapping\Vendor\SoftwareToneMapper::class,
    ];

    /**
     * Returns the correct tone mapper for the vendor.
     *
     * Auto-falls back to software tone mapper if the vendor is unknown.
     *
     * @param string $vendor Vendor name (e.g., 'nvenc', 'vaapi', 'qsv', 'videotoolbox', 'amf', 'v4l2', 'software')
     *
     * @return HwaccelToneMapperInterface Tone mapper instance
     *
     * @since 0.11.0
     */
    public function getToneMapper(string $vendor): HwaccelToneMapperInterface
    {
        $vendor = strtolower($vendor);
        $class = self::TONEMAPPER_CLASSES[$vendor] ?? self::TONEMAPPER_CLASSES['software'];

        return new $class();
    }

    /**
     * Returns all registered vendor tone mapper classes.
     *
     * @return array<string, class-string<HwaccelToneMapperInterface>>
     *
     * @since 0.11.0
     */
    public function getRegisteredVendors(): array
    {
        return self::TONEMAPPER_CLASSES;
    }
}
