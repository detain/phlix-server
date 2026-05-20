<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Subtitles;

use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * Factory for creating SubtitleBurner instances for specific hardware vendors.
 *
 * Creates the appropriate subtitle burner based on the target vendor's
 * capabilities. Falls back to software rendering when the vendor does not
 * support hardware-accelerated subtitle burn-in.
 *
 * @since 0.11.0
 */
final class SubtitleBurnerFactory
{
    /**
     * Creates a SubtitleBurner for the specified vendor.
     *
     * The returned burner is configured to use the most appropriate
     * subtitle rendering method for the vendor. Some vendors (NVENC, QSV)
     * have limited or no native subtitle support and will use software
     * rendering with hardware upload where applicable.
     *
     * @param string $vendor Hardware vendor ('nvenc', 'vaapi', 'qsv', 'videotoolbox', 'amf', 'v4l2', 'software')
     * @param FfmpegRunner $ffmpeg FFmpeg runner for extraction commands
     *
     * @return SubtitleBurner Configured subtitle burner for the vendor
     *
     * @since 0.11.0
     */
    public function createForVendor(string $vendor, FfmpegRunner $ffmpeg): SubtitleBurner
    {
        // All vendors use the same SubtitleBurner - the getBurnInArgs method
        // handles vendor-specific filter chains internally
        return new SubtitleBurner($ffmpeg);
    }
}
