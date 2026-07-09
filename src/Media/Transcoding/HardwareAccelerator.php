<?php

/**
 * Phlix media server component: Transcoding.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Media\Transcoding;

/**
 * Hardware accelerator DTO representing a detected FFmpeg hardware accelerator.
 *
 * This is a lightweight data-transfer object returned by the hardware detection
 * APIs. It differs from {@see HwaccelCapability} which carries detailed
 * per-vendor capability information (encoders, decoders, HDR support, etc.).
 * This DTO exposes only the accelerator name, its available encoders, and a
 * flag indicating whether it is a true hardware accelerator.
 *
 * @since 0.36.0
 */
final readonly class HardwareAccelerator
{
    /**
     * @param string        $name       Accelerator name (e.g., 'cuda', 'qsv', 'vaapi', 'videotoolbox')
     * @param array<string> $encoders   Available FFmpeg encoder names (e.g., ['h264_nvenc', 'hevc_nvenc'])
     * @param bool          $isHardware Always true for hardware accelerators; false for software fallbacks
     */
    public function __construct(
        public string $name,
        public array $encoders,
        public bool $isHardware = true,
    ) {
    }

    /**
     * Checks whether this accelerator provides a specific encoder.
     *
     * @param string $encoder Encoder name to check (e.g., 'h264_nvenc', 'hevc_vaapi')
     *
     * @return bool True if the encoder is available on this accelerator
     *
     * @since 0.36.0
     */
    public function hasEncoder(string $encoder): bool
    {
        return in_array($encoder, $this->encoders, true);
    }

    /**
     * Checks whether this accelerator supports a given codec.
     *
     * A codec is considered supported if any of the accelerator's encoders
     * encode that codec (inferred from the encoder name prefix).
     *
     * @param string $codec Codec name (e.g., 'h264', 'hevc', 'av1')
     *
     * @return bool True if the codec is supported
     *
     * @since 0.36.0
     */
    public function supportsCodec(string $codec): bool
    {
        $prefix = $codec . '_';
        foreach ($this->encoders as $encoder) {
            if (str_starts_with($encoder, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the accelerator name as a FFmpeg hwaccel device argument.
     *
     * For most accelerators this returns '-hwaccel <name>'. For qsv and vaapi
     * which require a device path, it appends the default device.
     *
     * @return string FFmpeg hwaccel flag argument(s)
     *
     * @since 0.36.0
     */
    public function toFfFlag(): string
    {
        return match ($this->name) {
            'qsv'    => '-hwaccel qsv -qsv_device /dev/dri/renderD128',
            'vaapi'  => '-hwaccel vaapi -hwaccel_device /dev/dri/renderD128',
            'videotoolbox' => '-hwaccel videotoolbox',
            default  => '-hwaccel ' . $this->name,
        };
    }

    /**
     * Returns a debug-friendly array representation.
     *
     * @return array{name: string, encoders: array<string>, isHardware: bool}
     *
     * @since 0.36.0
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'encoders' => $this->encoders,
            'isHardware' => $this->isHardware,
        ];
    }
}
