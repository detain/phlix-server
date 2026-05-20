<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel\ToneMapping\Vendor;

use Phlix\Media\Transcoding\Hwaccel\ToneMapping\HdrMetadata;
use Phlix\Media\Transcoding\Hwaccel\ToneMapping\HwaccelToneMapperInterface;
use Phlix\Media\Transcoding\Hwaccel\ToneMapping\ToneMapFilterChain;

/**
 * V4L2 (Video4Linux2) tone mapper.
 *
 * The V4L2 request API does not support hardware tone-mapping.
 * Uses zscale for CPU-based tone mapping before encoding.
 *
 * @since 0.11.0
 */
final class V4L2ToneMapper implements HwaccelToneMapperInterface
{
    /**
     * {@inheritdoc}
     */
    public function getVendor(): string
    {
        return 'v4l2';
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

        // V4L2 has no HW tonemap - use zscale on CPU
        $metadataFilter = sprintf(
            'zscale=transfer=bt709:min_luminance=%.2f:max_luminance=%.2f:param1=0.18:param2=0.14',
            $avgLuminance / 100.0,
            $maxLuminance / 100.0
        );

        // Output format for V4L2
        $outputFilter = 'format=nv12';

        // V4L2 hwaccel
        $inputFilter = 'hwupload';

        return new ToneMapFilterChain(
            input_filtergraph: $inputFilter,
            output_filtergraph: $outputFilter,
            metadata_filter: $metadataFilter,
            ffmpeg_args: ['-hwaccel', 'v4l2m2m'],
        );
    }
}
