<?php

/**
 * Phlix media server component: Hwaccel.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

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
     * @param string $encoder Primary FFmpeg encoder name (e.g., 'h264_nvenc', 'h264_vaapi') — the
     *                        codec-correct H.264 encoder when the probe detected one, else the
     *                        first probed name. Per-rung selection goes through
     *                        {@see encoderForCodec()}, never through this field directly.
     * @param string $decoder FFmpeg decoder name (e.g., 'hevc_cuvid', 'hevc_vaapi')
     * @param bool $supports_hdr_tone_mapping Whether the hardware supports HDR tone mapping
     * @param array<string> $supported_codecs List of supported codec names (e.g., ['h264', 'hevc', 'av1'])
     * @param array<string> $supported_profiles List of supported encoder profiles (e.g., ['baseline', 'main', 'high'])
     * @param int $max_resolution_w Maximum supported width in pixels
     * @param int $max_resolution_h Maximum supported height in pixels
     * @param int $max_bitrate Maximum supported bitrate in bits per second
     * @param array<string, mixed> $extra_args Vendor-specific additional arguments
     * @param array<string, string> $encoders_by_codec Map of generic codec name to the exact FFmpeg
     *                        encoder name parsed for it at the probe boundary
     *                        (e.g. ['h264' => 'h264_vaapi', 'hevc' => 'hevc_vaapi']). Empty means the
     *                        capability carries only its primary encoder — every lookup then
     *                        resolves to $encoder, byte-identical to pre-lookup behaviour.
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
        public readonly array $encoders_by_codec = [],
    ) {
    }

    /**
     * Resolves the FFmpeg encoder name to splice for a target codec.
     *
     * WHY this exists: `ffmpeg -encoders` lists encoder names ALPHABETICALLY,
     * so a vendor probe keying its capability off the FIRST match picked the
     * wrong codec — on production ffmpeg 8.0.1-3ubuntu2 a full-featured VAAPI
     * box lists `av1_vaapi h264_vaapi hevc_vaapi mjpeg_vaapi mpeg2_vaapi
     * vp8_vaapi vp9_vaapi`, so `av1_vaapi` led and was spliced verbatim as
     * `-c:v` into EVERY rung, handing browsers an AV1 stream behind an
     * avc1-advertising master playlist. The probe boundary now parses each
     * `<codec>_<vendor>` name it saw into $encoders_by_codec, so an H.264
     * rung resolves `h264_vaapi` and an HEVC rung resolves `hevc_vaapi`.
     *
     * Codecs with no entry (hand-seeded capabilities, the software vendor,
     * fallback-claimed codec lists) resolve to the primary $encoder — the
     * pre-lookup behaviour, unchanged.
     *
     * @param string $codec Target codec name, case-insensitive (e.g., 'h264', 'hevc')
     *
     * @return string The codec-correct encoder when probed, else the primary encoder
     *
     * @since 1.2.4
     */
    public function encoderForCodec(string $codec): string
    {
        return $this->encoders_by_codec[strtolower($codec)] ?? $this->encoder;
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
