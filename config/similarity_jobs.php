<?php

/**
 * Similarity computation job configuration (SV-2.9).
 *
 * The scanner enqueues one similarity job per new media item into the
 * file-based queue below (instead of running the O(N^2) similarity computation
 * inline on the scan path). The SimilarityWorker drains this queue.
 *
 * @since 0.38.0
 */

declare(strict_types=1);

return [
    /*
     * Directory for the file-based job queue. Each media item awaiting
     * similarity computation is represented by a JSON job file. Must match the
     * directory the SimilarityJobStore is constructed with so the scanner
     * (producer) and the SimilarityWorker (consumer) share the same queue.
     */
    'job_queue_dir' => '/tmp/phlix_similarity_jobs',

    /*
     * Sleep interval in seconds when the queue is empty.
     * The worker polls at this interval for new jobs.
     */
    'worker_interval' => 30,

    /*
     * Maximum concurrent similarity computations.
     *
     * Similarity is DB-bound (a bounded per-library candidate scan plus a
     * DELETE/INSERT of item_similar rows) rather than ffmpeg-bound; a low
     * default keeps DB contention modest while still draining a backlog. The
     * shared MySQL connection serialises each query, so concurrent jobs for
     * distinct items interleave safely.
     */
    'max_concurrent' => 2,
];
