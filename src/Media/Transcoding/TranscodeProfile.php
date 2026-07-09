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
 * Value object representing a transcode profile with tone mapping settings.
 *
 * Encapsulates the configuration for a transcode operation including
 * video/audio codec settings, resolution, and HDR tone mapping preferences.
 *
 * @since 0.36.0
 */
final class TranscodeProfile
{
    /**
     * @param string $toneMapMode     Tone mapping mode: 'none', 'zscale', or 'libplacebo'
     * @param bool   $toneMapEnabled  Whether tone mapping is enabled for this profile
     * @param bool   $preferHdrOutput Whether to prefer HDR output instead of tone-mapping
     */
    public function __construct(
        public readonly string $toneMapMode = 'none',
        public readonly bool $toneMapEnabled = false,
        public readonly bool $preferHdrOutput = false,
    ) {
    }

    /**
     * Creates a profile with tone mapping enabled using zscale.
     *
     * @return self New profile with tone mapping enabled
     */
    public function withZscaleToneMapping(): self
    {
        return new self(
            toneMapMode: 'zscale',
            toneMapEnabled: true,
            preferHdrOutput: false,
        );
    }

    /**
     * Creates a profile with tone mapping enabled using libplacebo.
     *
     * @return self New profile with libplacebo tone mapping enabled
     */
    public function withLibplaceboToneMapping(): self
    {
        return new self(
            toneMapMode: 'libplacebo',
            toneMapEnabled: true,
            preferHdrOutput: false,
        );
    }

    /**
     * Creates a profile that prefers HDR output over tone mapping.
     *
     * @return self New profile with HDR output preference
     */
    public function withHdrOutput(): self
    {
        return new self(
            toneMapMode: 'none',
            toneMapEnabled: false,
            preferHdrOutput: true,
        );
    }

    /**
     * Checks if HDR output is preferred over SDR tone mapping.
     *
     * @return bool True if HDR output should be preferred
     */
    public function wantsHdrOutput(): bool
    {
        return $this->preferHdrOutput;
    }

    /**
     * Checks if any tone mapping is enabled.
     *
     * @return bool True if tone mapping is enabled
     */
    public function hasToneMapping(): bool
    {
        return $this->toneMapEnabled && $this->toneMapMode !== 'none';
    }

    /**
     * Gets the FFmpeg filter chain string for the configured tone mapping.
     *
     * Returns the appropriate zscale or libplacebo filter chain based on
     * the configured tone mapping mode.
     *
     * @return string FFmpeg filter chain or empty string if tone mapping is disabled
     */
    public function getToneMapFilterChain(): string
    {
        if (!$this->toneMapEnabled || $this->toneMapMode === 'none') {
            return '';
        }

        return match ($this->toneMapMode) {
            'zscale' => 'zscale=transfer=bt2020ntob709,format=yuv420p',
            'libplacebo' => 'scale_vaapi=format=nv12,hwupload,tonemap_vaapi=transfer=bt2020:primaries=bt2020:tonemap=hable:desat=0.5',
            default => '',
        };
    }
}
