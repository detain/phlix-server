<?php

declare(strict_types=1);

namespace Phlex\Media\Transcoding\Hwaccel\ToneMapping\Vendor;

use Phlex\Media\Transcoding\Hwaccel\ToneMapping\HdrMetadata;
use Phlex\Media\Transcoding\Hwaccel\ToneMapping\HwaccelToneMapperInterface;
use Phlex\Media\Transcoding\Hwaccel\ToneMapping\ToneMapFilterChain;

/**
 * Apple VideoToolbox tone mapper.
 *
 * VideoToolbox does not support hardware HDR tone-mapping directly.
 * Uses zscale for CPU-based tone mapping before hardware encode.
 *
 * @since 0.11.0
 */
final class VideoToolboxToneMapper implements HwaccelToneMapperInterface
{
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

        // VideoToolbox has no HW tonemap - use zscale on CPU
        // Target: convert to BT.709 for SDR output
        $metadataFilter = sprintf(
            'zscale=transfer=bt709:min_luminance=%.2f:max_luminance=%.2f:param1=0.18:param2=0.14',
            $avgLuminance / 100.0,
            $maxLuminance / 100.0
        );

        // Output format conversion
        $outputFilter = 'format=nv12';

        // No special input filter needed for VideoToolbox
        $inputFilter = '';

        return new ToneMapFilterChain(
            input_filtergraph: $inputFilter,
            output_filtergraph: $outputFilter,
            metadata_filter: $metadataFilter,
            ffmpeg_args: ['-hwaccel', 'videotoolbox'],
        );
    }
}
