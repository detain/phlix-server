<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel\ToneMapping;

/**
 * Value object representing HDR (High Dynamic Range) source metadata.
 *
 * Encapsulates the color space, transfer characteristics, primaries,
 * and luminance information extracted from ffprobe.
 *
 * @since 0.11.0
 */
final class HdrMetadata
{
    /**
     * @param string $color_space Color space (e.g., 'bt2020nc', 'bt709')
     * @param string $color_transfer Color transfer function (e.g., 'smpte2084' (PQ), 'arib-std-b67' (HLG))
     * @param string $color_primaries Color primaries (e.g., 'bt2020', 'bt709')
     * @param float $max_luminance Maximum luminance in nits (default: 1000.0)
     * @param float $avg_luminance Average luminance in nits (default: 200.0)
     */
    public function __construct(
        public readonly string $color_space,
        public readonly string $color_transfer,
        public readonly string $color_primaries,
        public readonly float $max_luminance = 1000.0,
        public readonly float $avg_luminance = 200.0,
    ) {
    }

    /**
     * Checks if this metadata represents an HDR source.
     *
     * HDR sources use PQ (SMPTE ST 2084) or HLG (ARIB STD-B67) transfer functions.
     *
     * @return bool True if the source is HDR
     *
     * @since 0.11.0
     */
    public function isHdr(): bool
    {
        return in_array($this->color_transfer, ['smpte2084', 'arib-std-b67'], true);
    }

    /**
     * Checks if this metadata represents a PQ (Perceptual Quantizer) HDR source.
     *
     * @return bool True if PQ transfer function
     *
     * @since 0.11.0
     */
    public function isPq(): bool
    {
        return $this->color_transfer === 'smpte2084';
    }

    /**
     * Checks if this metadata represents an HLG (Hybrid Log-Gamma) HDR source.
     *
     * @return bool True if HLG transfer function
     *
     * @since 0.11.0
     */
    public function isHlg(): bool
    {
        return $this->color_transfer === 'arib-std-b67';
    }

    /**
     * Gets the desaturation parameter for tone mapping.
     *
     * Based on max luminance, used to prevent over-saturation of very bright colors.
     *
     * @return float Desaturation value (0.0 - 1.0)
     *
     * @since 0.11.0
     */
    public function getDesaturation(): float
    {
        if ($this->max_luminance <= 1000.0) {
            return 0.0;
        }

        return min(1.0, ($this->max_luminance - 1000.0) / 9000.0);
    }
}
