<?php

declare(strict_types=1);

/**
 * Comskip post-processing configuration.
 *
 * Comskip is a third-party commercial detection tool. When installed on the
 * system, Phlex can automatically run it after Live TV recordings complete
 * to detect and store commercial break chapters.
 *
 * @since 0.12.0
 */

return [
    /**
     * Enable or disable automatic comskip processing.
     *
     * @since 0.12.0
     */
    'enabled' => true,

    /**
     * Path to the comskip binary.
     *
     * On Linux/macOS: '/usr/bin/comskip'
     * On Windows: 'C:\\Program Files\\Comskip\\comskip.exe'
     *
     * @since 0.12.0
     */
    'comskip_path' => '/usr/bin/comskip',

    /**
     * Minimum commercial length in seconds.
     *
     *Segments shorter than this are ignored when parsing EDL output.
     *
     * @since 0.12.0
     */
    'min_commercial_length' => 30,

    /**
     * Minimum confidence threshold (0.0 - 1.0).
     *
     * Commercial detections below this confidence score are ignored.
     * Comskip outputs a confidence value; segments below this threshold
     * will not be stored as chapters.
     *
     * @since 0.12.0
     */
    'require_confidence' => 0.7,

    /**
     * Run comskip immediately after recording completes.
     *
     * If false, comskip will only run when explicitly triggered via API.
     *
     * @since 0.12.0
     */
    'post_process_immediately' => true,

    /**
     * Output directory for EDL files.
     *
     * null = same directory as the recording file.
     * Set to a specific path to store all EDL files in one location.
     *
     * @since 0.12.0
     */
    'edl_output_dir' => null,
];
