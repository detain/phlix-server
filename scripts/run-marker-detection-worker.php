#!/usr/bin/env php
<?php

/**
 * Marker Detection Worker CLI
 *
 * Runs the BackgroundDetectorWorker in a loop to process
 * intro/outro detection jobs from the queue.
 *
 * Usage: php scripts/run-marker-detection-worker.php
 *
 * @since 0.12.0
 */

declare(strict_types=1);

$baseDir = dirname(__DIR__);
require_once $baseDir . '/vendor/autoload.php';

use Phlex\Common\Database\ConnectionPool;
use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Markers\Detection\BackgroundDetectorWorker;
use Phlex\Media\Markers\Detection\IntroDetectionJob;
use Phlex\Media\Markers\Detection\MarkerCandidateRepository;
use Phlex\Media\Markers\Detection\MarkerCandidateStore;
use Phlex\Media\Markers\Fingerprinting\ChromaPrintFactory;
use Phlex\Media\Markers\Fingerprinting\FingerprintRepository;
use Psr\Log\StreamHandler;
use Psr\Log\LoggerFactory;
use Monolog\Logger;
use Monolog\Level;

$config = require $baseDir . '/config/marker_detection.php';

$db = ConnectionPool::getConnection('mysql');

$itemRepo = new ItemRepository($db);
$fingerprintRepo = new FingerprintRepository($itemRepo);
$chromaPrint = (new ChromaPrintFactory())->create();
$candidateStore = new MarkerCandidateStore($config['job_queue_dir']);
$candidateRepo = new MarkerCandidateRepository($itemRepo);

$logger = new Logger('marker_detection');
$logger->pushHandler(new StreamHandler('php://stdout', Level::Debug->value));

$job = new IntroDetectionJob(
    $fingerprintRepo,
    $itemRepo,
    $chromaPrint,
    $logger,
    $config['min_episodes_for_detection'],
);

$worker = new BackgroundDetectorWorker(
    $job,
    $candidateStore,
    $candidateRepo,
    $logger,
);

echo "Marker Detection Worker started.\n";
echo "Queue directory: {$config['job_queue_dir']}\n";
echo "Worker interval: {$config['worker_interval']}s\n";
echo "Press Ctrl+C to stop.\n\n";

$worker->runLoop($config['worker_interval']);
