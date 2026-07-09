<?php

/**
 * Transcoding configuration.
 *
 * @since 0.36.0
 */

return [
    /**
     * Preferred hardware accelerator to use for transcoding.
     *
     * Set to null to auto-select based on availability and priority order.
     * Supported values: 'cuda', 'qsv', 'vaapi', 'videotoolbox', 'amf', 'opencl'
     *
     * Example env: PREFERRED_ACCELERATOR=cuda
     */
    'preferred_accelerator' => getenv('PREFERRED_ACCELERATOR') ?: null,

    /**
     * Timeout for hardware accelerator probing in seconds.
     */
    'probe_timeout' => 30,

    /**
     * Path to a test clip used for hardware accelerator acceptance testing.
     * If empty, acceptance tests will be skipped.
     */
    'test_clip_path' => '/tmp/hwaccel_probe_test.mp4',

    /**
     * Whether to include software encoding as a fallback option in accelerator lists.
     */
    'include_software_fallback' => true,

    /**
     * HDR tone mapping mode for transcoding HDR content.
     *
     * When set to 'none' (default), no tone mapping is applied.
     * When set to 'zscale', uses the zscale filter for CPU-based HDR→SDR conversion.
     * When set to 'libplacebo', uses libplacebo for high-quality tone mapping.
     *
     * Supported values: 'none', 'zscale', 'libplacebo'
     *
     * Example env: TONE_MAPPING_MODE=zscale
     */
    'tone_mapping_mode' => getenv('TONE_MAPPING_MODE') ?: 'none',

    /**
     * Whether to prefer HDR output over SDR tone mapping.
     *
     * When true, outputs HDR10 instead of tone-mapping to SDR.
     * This is useful for displays that support HDR content directly.
     *
     * Example env: PREFER_HDR_OUTPUT=true
     */
    'prefer_hdr_output' => filter_var(getenv('PREFER_HDR_OUTPUT') ?: 'false', FILTER_VALIDATE_BOOLEAN),
];
