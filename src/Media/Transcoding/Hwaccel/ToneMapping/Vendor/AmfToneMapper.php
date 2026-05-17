<?php

declare(strict_types=1);

namespace Phlex\Media\Transcoding\Hwaccel\ToneMapping\Vendor;

use Phlex\Media\Transcoding\Hwaccel\ToneMapping\HdrMetadata;
use Phlex\Media\Transcoding\Hwaccel\ToneMapping\HwaccelToneMapperInterface;
use Phlex\Media\Transcoding\Hwaccel\ToneMapping\ToneMapFilterChain;

/**
 * AMD AMF (Advanced Media Framework) tone mapper.
 *
 * Uses AMD's hardware tone-mapping via the TONEMAP_Hardware parameter
 * in the encoder initialization, or falls back to zscale.
 *
 * @since 0.11.0
 */
final class AmfToneMapper implements HwaccelToneMapperInterface
{
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
    public function supportsHardwareToneMapping(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getFilterChain(HdrMetadata $hdr): ToneMapFilterChain
    {
        $desat = $hdr->getDesaturation();

        // AMF hardware upload for preprocessing
        $inputFilter = 'hwupload=extra_hw_frames=64';

        // AMF tone mapping via filter - convert HDR to SDR
        // AMF's approach uses the encoder's tone mapping capability
        $outputFilter = sprintf(
            'tonemap_amf=transfer=bt2020:primaries=bt2020:tonemap=hable:desat=%.2f',
            $desat
        );

        // Scale to target output
        $scaleFilter = 'scale=format=nv12';

        // Fallback zscale chain
        $metadataFilter = 'zscale=transfer=bt709:param1=0.18:param2=0.14';

        return new ToneMapFilterChain(
            input_filtergraph: $inputFilter,
            output_filtergraph: $outputFilter . ',' . $scaleFilter,
            metadata_filter: $metadataFilter,
            ffmpeg_args: ['-hwaccel', 'amf'],
        );
    }
}
