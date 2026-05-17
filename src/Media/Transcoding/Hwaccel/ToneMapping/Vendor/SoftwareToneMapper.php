<?php

declare(strict_types=1);

namespace Phlex\Media\Transcoding\Hwaccel\ToneMapping\Vendor;

use Phlex\Media\Transcoding\Hwaccel\ToneMapping\HdrMetadata;
use Phlex\Media\Transcoding\Hwaccel\ToneMapping\HwaccelToneMapperInterface;
use Phlex\Media\Transcoding\Hwaccel\ToneMapping\ToneMapFilterChain;

/**
 * Software (CPU-based) tone mapper.
 *
 * Uses zscale filter chain for HDR to SDR conversion.
 * This is the fallback for vendors without hardware tone-mapping support.
 *
 * @since 0.11.0
 */
final class SoftwareToneMapper implements HwaccelToneMapperInterface
{
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
    public function supportsHardwareToneMapping(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getFilterChain(HdrMetadata $hdr): ToneMapFilterChain
    {
        $maxLuminance = $hdr->max_luminance;
        $avgLuminance = $hdr->avg_luminance;

        // Software tone mapping using zscale
        // Transfer function to BT.709 (SDR)
        // Uses hable tone mapping curve for natural-looking results
        $metadataFilter = sprintf(
            'zscale=transfer=bt709:min_luminance=%.2f:max_luminance=%.2f:param1=0.18:param2=0.14',
            $avgLuminance / 100.0,
            $maxLuminance / 100.0
        );

        // Output format conversion
        $outputFilter = 'format=nv12';

        // No hardware upload needed for software
        $inputFilter = '';

        return new ToneMapFilterChain(
            input_filtergraph: $inputFilter,
            output_filtergraph: $outputFilter,
            metadata_filter: $metadataFilter,
            ffmpeg_args: [],
        );
    }
}
