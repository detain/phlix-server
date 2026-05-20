<?php

/**
 * Marker detection configuration.
 *
 * @since 0.12.0
 */

return [
    /*
     * Start time in seconds for intro detection window.
     * Usually 0 for episodes that start with the intro.
     */
    'intro_start_seconds' => 0,

    /*
     * Maximum duration in seconds to consider for intro detection.
     * Episodes with intros longer than this will be trimmed.
     */
    'intro_max_duration' => 180,

    /*
     * Maximum duration in seconds to consider for outro detection.
     * Episodes with outros longer than this will be trimmed.
     */
    'outro_max_duration' => 180,

    /*
     * Jaccard similarity threshold (0.0–1.0) to declare a match.
     * 0.85 means 85% similar fingerprints are considered the same.
     */
    'similarity_threshold' => 0.85,

    /*
     * Minimum number of fingerprinted episodes required before
     * running detection. Need at least 3 episodes to reliably
     * detect shared intro/outro segments.
     */
    'min_episodes_for_detection' => 3,

    /*
     * Directory for the file-based job queue.
     * Each show being processed is represented by a lock file.
     */
    'job_queue_dir' => '/tmp/phlix_marker_jobs',

    /*
     * Sleep interval in seconds when the queue is empty.
     * The worker polls at this interval for new jobs.
     */
    'worker_interval' => 30,
];
