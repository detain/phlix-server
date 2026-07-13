#!/usr/bin/env php
<?php

/**
 * Similarity Worker CLI
 *
 * Runs the SimilarityWorker in a loop to drain the similarity computation queue
 * the scanner enqueues into (SV-2.9). This is the standalone alternative to the
 * start.php-supervised 'similarity' managed worker (config/managed_workers.php);
 * running both is safe because SimilarityJobStore::dequeue() atomically removes
 * each job file, so at most one drainer processes a given job.
 *
 * Usage: php scripts/run-similarity-worker.php
 *
 * @since 0.38.0
 */

declare(strict_types=1);

$baseDir = dirname(__DIR__);
require_once $baseDir . '/vendor/autoload.php';
require_once __DIR__ . '/bootstrap_env.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\SimilarityJobStore;
use Phlix\Media\SimilarityService;
use Phlix\Media\SimilarityWorker;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Level;

$config = require $baseDir . '/config/similarity_jobs.php';

$db = ConnectionPool::getConnection('mysql');
$itemRepo = new ItemRepository($db);

$jobStore = new SimilarityJobStore($config['job_queue_dir']);
$service = new SimilarityService($db, $itemRepo);

$logger = new Logger('similarity_worker');
$logger->pushHandler(new StreamHandler('php://stdout', Level::Debug->value));

$worker = new SimilarityWorker(
    $jobStore,
    $service,
    $logger,
    $config['max_concurrent'],
);

echo "Similarity Worker started.\n";
echo "Queue directory: {$config['job_queue_dir']}\n";
echo "Worker interval: {$config['worker_interval']}s\n";
echo "Max concurrent: {$config['max_concurrent']}\n";
echo "Press Ctrl+C to stop.\n\n";

$worker->runLoop($config['worker_interval']);
