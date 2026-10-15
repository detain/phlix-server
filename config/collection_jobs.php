<?php

/**
 * TMDB box-set collection sync job configuration (S215).
 *
 * The scanner enqueues one collection job per newly indexed, already
 * tmdb-matched media item into the file-based queue below (instead of running
 * the blocking-HTTPS collection sync inline on the scan path). The
 * CollectionWorker drains this queue.
 *
 * Mirrors config/similarity_jobs.php.
 *
 * @since 0.38.0
 */

declare(strict_types=1);

return [
    /*
     * Directory for the file-based job queue. Each media item awaiting
     * collection sync is represented by a JSON job file. Must match the
     * directory the CollectionJobStore is constructed with so the scanner
     * (producer) and the CollectionWorker (consumer) share the same queue.
     *
     * R14-14: resolved by \Phlix\Config\JobQueuePaths (was hardcoded
     * /tmp/phlix_collection_jobs — lost across restarts under systemd's
     * PrivateTmp=true). Default lands in <repo>/var/jobs/collection.
     * Override with PHLIX_COLLECTION_JOBS_DIR or PHLIX_JOB_QUEUE_ROOT.
     */
    'job_queue_dir' => \Phlix\Config\JobQueuePaths::dir('collection'),

    /*
     * Sleep interval in seconds when the queue is empty.
     * The worker polls at this interval for new jobs.
     */
    'worker_interval' => 30,

    /*
     * Maximum concurrent collection syncs.
     *
     * Each sync issues blocking HTTPS requests to TMDB (/movie/{id} +
     * /collection/{id}) and two small writes. A cap of 1 keeps TMDB request
     * volume modest (the API rate-limits per key) while still draining a
     * backlog one job per tick; raise only with the rate budget in mind.
     */
    'max_concurrent' => 1,
];
