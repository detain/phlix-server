<?php

/**
 * Media asset (chapter-thumbnail + trickplay) generation job configuration.
 *
 * @since 0.36.0
 */

return [
    /*
     * Directory for the file-based job queue.
     * Each media item awaiting generation is represented by a JSON job file.
     */
    'job_queue_dir' => '/tmp/phlix_media_asset_jobs',

    /*
     * Sleep interval in seconds when the queue is empty.
     * The worker polls at this interval for new jobs.
     */
    'worker_interval' => 30,

    /*
     * Maximum concurrent ffmpeg thumbnail/sprite generations.
     *
     * Each concurrent slot holds one ffmpeg process. Higher values = faster
     * generation but more CPU/disk I/O. The probeManyConcurrently default is 4;
     * thumbnails and sprites are heavier than probes, so we default to 2.
     */
    'max_concurrent' => 2,
];
