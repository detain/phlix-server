<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel;

/**
 * Hardware acceleration capability value object.
 *
 * Represents the encoding/decoding capabilities of a hardware accelerator
 * (e.g., NVIDIA NVENC, Intel VAAPI, Apple VideoToolbox).
 *
 * @since 0.11.0
 */
final class HwaccelCapability
{
    /**
     * @param string $vendor Hardware vendor identifier (e.g., 'nvenc', 'vaapi', 'qsv', 'videotoolbox', 'amf', 'v4l2')
     * @param string $encoder FFmpeg encoder name (e.g., 'h264_nvenc', 'hevc_vaapi')
     * @param string $decoder FFmpeg decoder name (e.g., 'hevc_cuvid', 'hevc_vaapi')
     * @param bool $supports_hdr_tone_mapping Whether the hardware supports HDR tone mapping
     * @param array<string> $supported_codecs List of supported codec names (e.g., ['h264', 'hevc', 'av1'])
     * @param array<string> $supported_profiles List of supported encoder profiles (e.g., ['baseline', 'main', 'high'])
     * @param int $max_resolution_w Maximum supported width in pixels
     * @param int $max_resolution_h Maximum supported height in pixels
     * @param int $max_bitrate Maximum supported bitrate in bits per second
     * @param array<string, mixed> $extra_args Vendor-specific additional arguments
     */
    public function __construct(
        public readonly string $vendor,
        public readonly string $encoder,
        public readonly string $decoder,
        public readonly bool $supports_hdr_tone_mapping,
        public readonly array $supported_codecs,
        public readonly array $supported_profiles,
        public readonly int $max_resolution_w,
        public readonly int $max_resolution_h,
        public readonly int $max_bitrate,
        public readonly array $extra_args = [],
    ) {
    }

    /**
     * Checks if a specific codec is supported by this hardware.
     *
     * @param string $codec Codec name to check (e.g., 'h264', 'hevc', 'av1')
     *
     * @return bool True if the codec is supported
     *
     * @since 0.11.0
     */
    public function supportsCodec(string $codec): bool
    {
        return in_array(strtolower($codec), $this->supported_codecs, true);
    }

    /**
     * Checks if a specific profile is supported.
     *
     * @param string $profile Profile name to check (e.g., 'high', 'main')
     *
     * @return bool True if the profile is supported
     *
     * @since 0.11.0
     */
    public function supportsProfile(string $profile): bool
    {
        return in_array(strtolower($profile), $this->supported_profiles, true);
    }

    /**
     * Checks if a given resolution is supported.
     *
     * @param int $width Width in pixels
     * @param int $height Height in pixels
     *
     * @return bool True if the resolution fits within maximums
     *
     * @since 0.11.0
     */
    public function supportsResolution(int $width, int $height): bool
    {
        return $width <= $this->max_resolution_w && $height <= $this->max_resolution_h;
    }
}
