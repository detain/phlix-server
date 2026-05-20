<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel\ToneMapping\Vendor;

use Phlix\Media\Transcoding\Hwaccel\ToneMapping\HdrMetadata;
use Phlix\Media\Transcoding\Hwaccel\ToneMapping\HwaccelToneMapperInterface;
use Phlix\Media\Transcoding\Hwaccel\ToneMapping\ToneMapFilterChain;

/**
 * VAAPI (Video Acceleration API) tone mapper.
 *
 * Uses VAAPI's built-in TONEMAP filter or falls back to zscale + format conversion.
 * VAAPI supports hardware-accelerated tone mapping on Intel and AMD GPUs.
 *
 * @since 0.11.0
 */
final class VaapiToneMapper implements HwaccelToneMapperInterface
{
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

        // VAAPI hardware tonemap - uses the vaapi tonemap filter
        // Scale and convert format
        $outputFilter = sprintf(
            'scale_vaapi=format=nv12|vaapi_upload,hwdownload,format=nv12'
        );

        // VAAPI tonemap filter (if available in FFmpeg build)
        $metadataFilter = sprintf(
            'tonemap_vaapi=transfer=bt2020:primaries=bt2020:tonemap=hable:desat=%.2f',
            $desat
        );

        // Input: upload to VAAPI surface
        $inputFilter = 'hwupload=extra_hw_frames=64';

        // Alternative zscale fallback path
        $zscaleFallback = sprintf(
            'zscale=transfer=bt709:param1=0.18:param2=0.14,format=nv12,hwupload'
        );

        return new ToneMapFilterChain(
            input_filtergraph: $inputFilter,
            output_filtergraph: $outputFilter,
            metadata_filter: $metadataFilter,
            ffmpeg_args: ['-hwaccel', 'vaapi', '-hwaccel_device', '/dev/dri/renderD128'],
        );
    }
}
