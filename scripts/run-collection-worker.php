#!/usr/bin/env php
<?php

/**
 * Collection Worker CLI
 *
 * Runs the CollectionWorker in a loop to drain the TMDB box-set collection sync
 * queue the scanner enqueues into (S215). This is the standalone alternative to
 * the start.php-supervised 'collection' managed worker
 * (config/managed_workers.php); running both is safe because
 * CollectionJobStore::dequeue() atomically removes each job file, so at most
 * one drainer processes a given job.
 *
 * Usage: php scripts/run-collection-worker.php
 *
 * @since 0.38.0
 */

declare(strict_types=1);

$baseDir = dirname(__DIR__);
require_once $baseDir . '/vendor/autoload.php';
require_once __DIR__ . '/bootstrap_env.php';

use Phlix\Admin\SettingsRepository;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Media\CollectionJobStore;
use Phlix\Media\CollectionService;
use Phlix\Media\CollectionWorker;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\TmdbApiKeyResolver;
use Phlix\Media\Metadata\TmdbProvider;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Level;

$config = require $baseDir . '/config/collection_jobs.php';

$db = ConnectionPool::getConnection('mysql');
$itemRepo = new ItemRepository($db);

// Effective TMDB API key: admin-managed `server_settings` override wins, then
// config/tmdb.php, then TMDB_API_KEY — same resolution order as the DI-wired
// provider, so the standalone drainer authenticates exactly like the server.
$tmdbApiKey = TmdbApiKeyResolver::resolve(
    new SettingsRepository($db, $baseDir . '/config'),
    $baseDir . '/config/tmdb.php'
);

$jobStore = new CollectionJobStore($config['job_queue_dir']);
$service = new CollectionService($db, $itemRepo, new TmdbProvider($tmdbApiKey));

$logger = new Logger('collection_worker');
$logger->pushHandler(new StreamHandler('php://stdout', Level::Debug->value));

$worker = new CollectionWorker(
    $jobStore,
    $service,
    $logger,
    $config['max_concurrent'],
);

if ($tmdbApiKey === '') {
    echo "WARNING: TMDB API key not configured — syncs will skip cleanly (no-op) until it is.\n";
    echo "Set it in the admin settings, config/tmdb.php or TMDB_API_KEY env var.\n\n";
}

echo "Collection Worker started.\n";
echo "Queue directory: {$config['job_queue_dir']}\n";
echo "Worker interval: {$config['worker_interval']}s\n";
echo "Max concurrent: {$config['max_concurrent']}\n";
echo "Press Ctrl+C to stop.\n\n";

$worker->runLoop($config['worker_interval']);
