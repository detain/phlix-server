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
];
