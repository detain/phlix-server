<?php

/**
 * Hardware acceleration configuration.
 *
 * @since 0.11.0
 */

return [
    /**
     * Enable hardware acceleration probing.
     * When false, only software encoding will be used.
     */
    'enabled' => true,

    /**
     * Prefer hardware acceleration over software encoding when available.
     */
    'prefer_hardware' => true,

    /**
     * Vendor priority order for fallback selection.
     * Lower values = higher priority. Software is always last (100).
     */
    'vendor_priority' => [
        'nvenc' => 0,
        'vaapi' => 1,
        'qsv' => 2,
        'videotoolbox' => 3,
        'amf' => 4,
        'v4l2' => 5,
    ],

    /**
     * Timeout for hardware probe in seconds.
     */
    'probe_timeout' => 30,

    /**
     * Path to a test clip for hardware acceptance testing.
     * If empty, acceptance tests will be skipped.
     */
    'test_clip_path' => '/tmp/hwaccel_probe_test.mp4',

    /**
     * Fallback to software encoding if no hardware acceleration is available.
     * When false, getEncoder() may return null.
     */
    'fallback_to_software' => true,
];
