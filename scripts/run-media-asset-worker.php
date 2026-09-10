#!/usr/bin/env php
<?php

/**
 * Media Asset Worker CLI
 *
 * Runs the MediaAssetWorker in a loop to process chapter-thumbnail and
 * trickplay sprite generation jobs from the queue.
 *
 * Usage: php scripts/run-media-asset-worker.php
 *
 * @since 0.36.0
 */

declare(strict_types=1);

$baseDir = dirname(__DIR__);
require_once $baseDir . '/vendor/autoload.php';
require_once __DIR__ . '/bootstrap_env.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\MediaAsset\MediaAssetGenerationJob;
use Phlix\Media\MediaAsset\MediaAssetJobStore;
use Phlix\Media\MediaAsset\MediaAssetWorker;
use Phlix\Media\Transcoding\FfmpegRunner;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Level;

$config = require $baseDir . '/config/media_asset_jobs.php';

// Same init the sibling backfill scripts do: ConnectionPool::getConnection() throws
// RuntimeException when the pool has no config path, and this file — uniquely among
// the DB-connected scripts/ — never set one. PHPStan/Psalm cannot see that defect
// (the throw is runtime-only), the S256-residual review caught it by reading.
ConnectionPool::init(__DIR__ . '/../config/database.php');
$db = ConnectionPool::getConnection('mysql');
$itemRepo = new ItemRepository($db);

// Build the FFprobe runner from config/ffmpeg.php so the worker uses the same
// binaries the live scanner uses (mirrors backfill-chapters.php). FfmpegRunner's
// first ctor parameter is the ffmpeg BINARY PATH (string) — `new FfmpegRunner($db)`
// was a TypeError at boot; the worker could never have started with this file.
$ffmpegConfig = @include $baseDir . '/config/ffmpeg.php';
if (!is_array($ffmpegConfig)) {
    $ffmpegConfig = [];
}
$ffmpegPath = is_string($ffmpegConfig['ffmpeg_path'] ?? null) ? $ffmpegConfig['ffmpeg_path'] : '/usr/bin/ffmpeg';
$ffprobePath = is_string($ffmpegConfig['ffprobe_path'] ?? null) ? $ffmpegConfig['ffprobe_path'] : '/usr/bin/ffprobe';
$transcodeDir = is_string($ffmpegConfig['transcode_dir'] ?? null)
    ? $ffmpegConfig['transcode_dir']
    : sys_get_temp_dir();

$ffmpeg = new FfmpegRunner($ffmpegPath, $ffprobePath, $transcodeDir);

$jobStore = new MediaAssetJobStore($config['job_queue_dir']);
$jobProcessor = new MediaAssetGenerationJob($ffmpeg, $itemRepo, $db);

$logger = new Logger('media_asset_worker');
$logger->pushHandler(new StreamHandler('php://stdout', Level::Debug->value));

$worker = new MediaAssetWorker(
    $jobStore,
    $jobProcessor,
    $logger,
    $config['max_concurrent'],
);

echo "Media Asset Worker started.\n";
echo "Queue directory: {$config['job_queue_dir']}\n";
echo "Worker interval: {$config['worker_interval']}s\n";
echo "Max concurrent: {$config['max_concurrent']}\n";
echo "Press Ctrl+C to stop.\n\n";

$worker->runLoop($config['worker_interval']);
