<?php

declare(strict_types=1);

namespace Phlex\Media\Transcoding\Hwaccel\Profiles;

use Phlex\Media\Transcoding\Hwaccel\HwaccelCapability;

/**
 * Interface for hardware encoder profiles.
 *
 * Defines the contract for per-vendor encoding profiles that map abstract
 * quality levels to concrete FFmpeg encoder flags for each supported
 * hardware accelerator.
 *
 * @since 0.11.0
 */
interface HwaccelEncoderProfileInterface
{
    /**
     * Returns the vendor identifier.
     *
     * @return string Vendor name (e.g., 'nvenc', 'vaapi', 'qsv', 'videotoolbox', 'amf', 'v4l2', 'software')
     *
     * @since 0.11.0
     */
    public function getVendor(): string;

    /**
     * Returns the FFmpeg encoder name for the given codec.
     *
     * @param string $codec Codec name (e.g., 'h264', 'hevc', 'av1')
     *
     * @return string FFmpeg encoder name (e.g., 'h264_nvenc', 'hevc_vaapi')
     *
     * @since 0.11.0
     */
    public function getEncoderName(string $codec): string;

    /**
     * Returns FFmpeg input device flags for this hardware accelerator.
     *
     * @param HwaccelCapability $capability The hardware capability
     *
     * @return string FFmpeg input device flag (e.g., '-vaapi_device /dev/dri/renderD128')
     *
     * @since 0.11.0
     */
    public function getInputDeviceArgs(HwaccelCapability $capability): string;

    /**
     * Returns FFmpeg output codec flag for the given codec.
     *
     * @param HwaccelCapability $capability The hardware capability
     * @param string $codec Codec name (e.g., 'h264', 'hevc')
     *
     * @return string FFmpeg output codec flag (e.g., '-c:v h264_nvenc')
     *
     * @since 0.11.0
     */
    public function getCodecArg(HwaccelCapability $capability, string $codec): string;

    /**
     * Returns vendor-specific quality and preset flags.
     *
     * @param string $quality_level Quality level (e.g., 'ultra', 'high', 'medium', 'low')
     * @param int $target_bitrate Target bitrate in bits per second
     *
     * @return string Quality/preset flags (e.g., '-preset p4 -tune zerolatency')
     *
     * @since 0.11.0
     */
    public function getQualityArgs(string $quality_level, int $target_bitrate): string;

    /**
     * Returns extra encode filters specific to this vendor.
     *
     * @param array<string> $filters List of filter names (e.g., ['deinterlace', 'denoise'])
     *
     * @return string Filter flags (e.g., '-vf "hwupload,deinterlace"')
     *
     * @since 0.11.0
     */
    public function getFilterArgs(array $filters): string;

    /**
     * Returns the maximum concurrent encodes for this hardware.
     *
     * @return int Maximum concurrent encodes (0 = unlimited)
     *
     * @since 0.11.0
     */
    public function getMaxConcurrent(): int;
}
