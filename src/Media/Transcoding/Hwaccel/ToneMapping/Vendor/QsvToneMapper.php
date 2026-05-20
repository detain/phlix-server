<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel\ToneMapping\Vendor;

use Phlix\Media\Transcoding\Hwaccel\ToneMapping\HdrMetadata;
use Phlix\Media\Transcoding\Hwaccel\ToneMapping\HwaccelToneMapperInterface;
use Phlix\Media\Transcoding\Hwaccel\ToneMapping\ToneMapFilterChain;

/**
 * Intel Quick Sync Video (QSV) tone mapper.
 *
 * Uses Intel's Video Processing Proxy (VPP) for hardware-accelerated tone-mapping
 * via the vpp tone_mapping filter. Falls back to zscale if needed.
 *
 * @since 0.11.0
 */
final class QsvToneMapper implements HwaccelToneMapperInterface
{
    /**
     * {@inheritdoc}
     */
    public function getVendor(): string
    {
        return 'qsv';
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

        // QSV VPP tone mapping - mode 1 = filmic tone mapping curve
        $outputFilter = sprintf(
            'vpp_tonemap=mode=1:desat=%.2f:peak=%.1f,scale_qsv=format=nv12',
            $desat,
            $maxLuminance / 100.0
        );

        // Input: QSV hardware upload
        $inputFilter = 'hwupload=extra_hw_frames=32';

        // Alternative: scale_qsv for resolution change
        $scaleFilter = 'scale_qsv=format=nv12';

        // Fallback zscale chain
        $metadataFilter = sprintf(
            'zscale=transfer=bt709:min_luminance=%.2f:max_luminance=%.2f',
            $hdr->avg_luminance / 100.0,
            $maxLuminance / 100.0
        );

        return new ToneMapFilterChain(
            input_filtergraph: $inputFilter,
            output_filtergraph: $outputFilter,
            metadata_filter: $metadataFilter,
            ffmpeg_args: ['-hwaccel', 'qsv', '-qsv_device', '/dev/dri/renderD128'],
        );
    }
}
