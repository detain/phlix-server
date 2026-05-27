#!/usr/bin/env php
<?php

/**
 * Phlix Media Server — Workerman bootstrap.
 *
 * This is the long-running daemon entry point. Modelled on webman's
 * `start.php` + `support\App::run()` pattern (vendor/workerman/webman-framework):
 *
 *   1. Bootstrap config / logger / DI container ONCE per worker process,
 *      not per-request.
 *   2. Create a Workerman HTTP `Worker` that:
 *        - serves files from public/ directly (static-file fast path), and
 *        - hands every other request to {@see HttpHandler} for routing.
 *   3. (Optional) Spin up the WebSocket worker on a sibling port for
 *      sync-play / real-time clients.
 *   4. `Worker::runAll()`.
 *
 * `public/index.php` is the CGI-style fallback entry point (php-fpm,
 * `php -S` for dev). The dispatch logic in {@see HttpHandler} mirrors
 * what index.php does, so both entry points behave identically. Run
 * either; do not run both at the same time on the same port.
 *
 * Usage:
 *   php start.php start          # foreground
 *   php start.php start -d       # daemonize
 *   php start.php stop
 *   php start.php restart
 *   php start.php reload
 *   php start.php status
 *
 * @see https://www.workerman.net/doc/workerman/install.html for the CLI commands.
 */

declare(strict_types=1);

chdir(__DIR__);
require_once __DIR__ . '/vendor/autoload.php';

use Phlix\Auth\AuthManager;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Workerman\HttpHandler;
use Workerman\Worker;

// -----------------------------------------------------------------------------
// 1. Configuration
// -----------------------------------------------------------------------------

/** @var array<string, mixed> $config */
$config = include __DIR__ . '/config/server.php';
$config['db_config_path']     = __DIR__ . '/config/database.php';
$config['logger_config_path'] = __DIR__ . '/config/logger.php';
$config['web_portal']         = array_merge(
    is_array($config['web_portal'] ?? null) ? $config['web_portal'] : [],
    ['template_dir' => __DIR__ . '/public/templates']
);

LoggerFactory::init($config['logger_config_path']);

// -----------------------------------------------------------------------------
// 2. Per-process configuration that the Workerman master needs
// -----------------------------------------------------------------------------

$workerCfg = is_array($config['worker'] ?? null) ? $config['worker'] : [];
if (isset($workerCfg['stdout_file']) && is_string($workerCfg['stdout_file'])) {
    @mkdir(dirname($workerCfg['stdout_file']), 0775, true);
    Worker::$stdoutFile = $workerCfg['stdout_file'];
}
if (isset($workerCfg['pid_file']) && is_string($workerCfg['pid_file'])) {
    @mkdir(dirname($workerCfg['pid_file']), 0775, true);
    Worker::$pidFile = $workerCfg['pid_file'];
}

// -----------------------------------------------------------------------------
// 3. HTTP worker — serves public/ + dispatches dynamic requests via HttpHandler
// -----------------------------------------------------------------------------

$serverCfg = is_array($config['server'] ?? null) ? $config['server'] : [];
$httpHost = is_string($serverCfg['host'] ?? null) ? $serverCfg['host'] : '0.0.0.0';
$httpPort = is_int($serverCfg['port'] ?? null)
    ? $serverCfg['port']
    : (int) (is_numeric($serverCfg['port'] ?? null) ? $serverCfg['port'] : 8096);

$workerCount = $workerCfg['count'] ?? 'auto';
if ($workerCount === 'auto') {
    $workerCount = (int) (shell_exec('nproc 2>/dev/null') ?: 4);
}
$workerCount = is_int($workerCount) ? $workerCount : (int) $workerCount;
if ($workerCount < 1) {
    $workerCount = 1;
}

$httpWorker = new Worker(sprintf('http://%s:%d', $httpHost, $httpPort));
$httpWorker->count = $workerCount;
$httpWorker->name = 'phlix-server-http';
if (!empty($config['process']['reuse_port']) && property_exists($httpWorker, 'reusePort')) {
    $httpWorker->reusePort = true;
}

$publicRoot = __DIR__ . '/public';

// The container can't be built before fork (it caches workerman/mysql
// PDO sockets and the like). Build it inside onWorkerStart so each
// child has its own copy of long-lived state.
$httpWorker->onWorkerStart = static function (Worker $w) use ($config, $publicRoot): void {
    $container = ContainerFactory::create($config);
    /** @var AuthManager $authManager */
    $authManager = $container->get(AuthManager::class);
    $w->onMessage = new HttpHandler($container, $authManager, $publicRoot);
};

// -----------------------------------------------------------------------------
// 4. (Future) WebSocket worker on port 8097 for sync-play, etc.
//    Wire src/Server/WebSocket/WebSocketServer.php here once it's
//    needed at boot time. For now the HTTP worker alone covers the
//    REST + SSR surface.
// -----------------------------------------------------------------------------

// -----------------------------------------------------------------------------
// 5. Run
// -----------------------------------------------------------------------------

Worker::runAll();
