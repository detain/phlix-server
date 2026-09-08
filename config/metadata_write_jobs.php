<?php

/**
 * Metadata write-back job configuration (S87).
 *
 * The scanner enqueues one write-back job per finalized media item (only for
 * libraries whose options.metadataWrite.enabled toggle is on) into the
 * file-based queue below, instead of writing metadata to disk inline on the
 * scan path. The MetadataWriteWorker drains this queue in its own managed
 * process; plugin writers (S88 sidecars, S89 embedded tags) execute there —
 * never in a scan or HTTP worker.
 *
 * @since S87
 */

declare(strict_types=1);

return [
    /*
     * Directory for the file-based job queue. Each media item awaiting a
     * metadata write-back is represented by one JSON job file. Must match the
     * directory the MetadataWriteJobStore is constructed with so the scanner
     * (producer) and the MetadataWriteWorker (consumer) share the same queue.
     */
    'job_queue_dir' => '/tmp/phlix_metadata_write_jobs',

    /*
     * Sleep interval in seconds when the queue is empty.
     * The worker polls at this interval for new jobs.
     */
    'worker_interval' => 30,

    /*
     * Maximum jobs drained per batch.
     *
     * S87 keeps the drain sequential; this only bounds how many queue files
     * one tick consumes. S88/S89 (sidecar + embedded-tag writers) may revisit
     * both the cap and whether the batch itself should fan out.
     */
    'max_concurrent' => 2,
];
