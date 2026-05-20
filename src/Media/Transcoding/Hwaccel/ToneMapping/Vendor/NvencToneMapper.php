<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel\ToneMapping\Vendor;

use Phlix\Media\Transcoding\Hwaccel\ToneMapping\HdrMetadata;
use Phlix\Media\Transcoding\Hwaccel\ToneMapping\HwaccelToneMapperInterface;
use Phlix\Media\Transcoding\Hwaccel\ToneMapping\ToneMapFilterChain;

/**
 * NVIDIA NVENC/NVDecode tone mapper.
 *
 * Uses NVIDIA's CUDA-based tone mapping via scale_cuda and tonemap_cuda filters.
 * Falls back to zscale if hardware tone-mapping is unavailable.
 *
 * @since 0.11.0
 */
final class NvencToneMapper implements HwaccelToneMapperInterface
{
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
        $maxLuminance = $hdr->max_luminance;
        $avgLuminance = $hdr->avg_luminance;

        // Primary path: use hardware tonemap_cuda
        // Format: tonemap_cuda=pq=t=bt2020:tonemap=hable:desat=0
        $outputFilter = sprintf(
            'tonemap_cuda=transfer=smpte2084:primaries=bt2020:tonemap=hable:desat=%.2f:peak=%.1f',
            $desat,
            $maxLuminance / 100.0
        );

        // Input filter: upload to CUDA for processing
        $inputFilter = 'hwupload=extra_hw_frames=3';

        // Fallback zscale chain if hardware tonemap unavailable
        // zscale=transfer=bt709:madin=0.0:pad=1:param1=0.18:param2=0.14
        $metadataFilter = sprintf(
            'zscale=transfer=bt709:min_luminance=%.2f:max_luminance=%.2f:param1=0.18:param2=0.14',
            $avgLuminance / 100.0,
            $maxLuminance / 100.0
        );

        // Scale for CUDA output
        $scaleFilter = 'scale_cuda=format=nv12';

        return new ToneMapFilterChain(
            input_filtergraph: $inputFilter,
            output_filtergraph: $outputFilter . ',' . $scaleFilter,
            metadata_filter: $metadataFilter,
            ffmpeg_args: ['-extra_hw_frames', '3'],
        );
    }
}
