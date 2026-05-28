#!/usr/bin/env php
<?php

/**
 * Library Scan Worker — standalone runner (Step 1.1b).
 *
 * The standalone alternative to the managed worker that `start.php` spawns: run
 * this when you want the library-scan worker as its own isolated service (e.g. a
 * dedicated systemd unit on a box where `start.php` serves HTTP only). It reads
 * the SAME `config/process.php` settings as the embedded path.
 *
 * It drains the `library_scan_jobs` queue: {@see \Phlix\Media\Library\LibraryScanWorker}
 * polls with a {@see \Workerman\Timer} (NEVER a blocking `sleep`) and runs the
 * existing {@see \Phlix\Media\Library\LibraryManager} scan per claimed job.
 *
 * Running this AND the `start.php`-embedded worker at the same time is safe —
 * `ScanJobRepository::claimNext()` is atomic and each worker is `count:1`.
 *
 * Usage:
 *   php scripts/run-library-scan-worker.php start          # foreground
 *   php scripts/run-library-scan-worker.php start -d       # daemonize
 *   php scripts/run-library-scan-worker.php stop
 *   php scripts/run-library-scan-worker.php restart
 *   php scripts/run-library-scan-worker.php status
 *
 * @since 1.1b
 */

declare(strict_types=1);

$baseDir = dirname(__DIR__);
chdir($baseDir);
require_once $baseDir . '/vendor/autoload.php';

use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Library\LibraryScanWorker;
use Workerman\Worker;

// -----------------------------------------------------------------------------
// 0. Coroutine runtime — mirror start.php: set Swoole as the eventLoop driver
//    and enable coroutine hooks in the master process before any Worker is
//    instantiated. Degrades gracefully if ext-swoole is not available.
// -----------------------------------------------------------------------------

if (extension_loaded('swoole')) {
    Worker::$eventLoopClass = \Workerman\Events\Swoole::class;
    \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
} else {
    trigger_error('Swoole extension not detected — coroutine runtime will not be active. Install ext-swoole to enable.', E_USER_WARNING);
}

// -----------------------------------------------------------------------------
// 1. Configuration (same assembly as start.php's HTTP worker).
// -----------------------------------------------------------------------------

/** @var array<string, mixed> $config */
$config = include $baseDir . '/config/server.php';
$config['db_config_path']     = $baseDir . '/config/database.php';
$config['logger_config_path'] = $baseDir . '/config/logger.php';

LoggerFactory::init($config['logger_config_path']);

// Worker settings: single source of truth in config/process.php.
$processCfgRaw = include $baseDir . '/config/process.php';
$processCfg = is_array($processCfgRaw) ? $processCfgRaw : [];
$scanCfgRaw = $processCfg['library-scan'] ?? [];
$scanCfg = is_array($scanCfgRaw) ? $scanCfgRaw : [];

$count = isset($scanCfg['count']) && is_int($scanCfg['count']) && $scanCfg['count'] > 0
    ? $scanCfg['count']
    : 1;
$pollSeconds = isset($scanCfg['poll_seconds']) && is_int($scanCfg['poll_seconds']) && $scanCfg['poll_seconds'] > 0
    ? $scanCfg['poll_seconds']
    : 5;

// -----------------------------------------------------------------------------
// 2. Scan worker. The container can't be built before fork (it caches
//    workerman/mysql PDO sockets and the like), so build it inside
//    onWorkerStart so each child has its own copy of long-lived state.
// -----------------------------------------------------------------------------

$worker = new Worker();
$worker->count = $count;
$worker->name = 'phlix-library-scan';

$worker->onWorkerStart = static function (Worker $w) use ($config, $pollSeconds): void {
    $container = ContainerFactory::create($config);
    /** @var LibraryScanWorker $scanWorker */
    $scanWorker = $container->get(LibraryScanWorker::class);
    $scanWorker->start($pollSeconds);
};

Worker::runAll();
