<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel\ToneMapping;

/**
 * Interface for hardware-specific tone mappers.
 *
 * Each vendor (NVENC, VAAPI, QSV, etc.) implements this interface
 * to provide vendor-specific HDR to SDR tone-mapping filter chains.
 *
 * @since 0.11.0
 */
interface HwaccelToneMapperInterface
{
    /**
     * Returns the vendor identifier.
     *
     * @return string Vendor name (e.g., 'nvenc', 'vaapi', 'qsv', 'videotoolbox', 'amf', 'v4l2', 'software')
     *
     * @since 0.11.0
     */
    public function getVendor(): string;

    /**
     * Checks if this vendor supports hardware-accelerated tone-mapping.
     *
     * @return bool True if hardware tone-mapping is supported
     *
     * @since 0.11.0
     */
    public function supportsHardwareToneMapping(): bool;

    /**
     * Generates the tone-mapping filter chain for the given HDR metadata.
     *
     * @param HdrMetadata $hdr HDR source metadata
     *
     * @return ToneMapFilterChain Filter chain to apply
     *
     * @since 0.11.0
     */
    public function getFilterChain(HdrMetadata $hdr): ToneMapFilterChain;
}
