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
use Phlix\Server\Core\Application;
use Phlix\Server\Workerman\HttpHandler;
use Workerman\Worker;

// -----------------------------------------------------------------------------
// 0. Coroutine runtime — set Swoole as the eventLoop driver and enable
//    coroutine hooks in the master process before any Worker is instantiated.
//    Degrades gracefully with a warning if ext-swoole is not yet available.
// -----------------------------------------------------------------------------

if (extension_loaded('swoole')) {
    // NOTE: the canonical Workerman 5 static property is
    // `Worker::$eventLoopClass`, not `Worker::$eventLoop` (which is an
    // *instance* property used to override the eventLoop on a single
    // Worker). Setting the static here, before any Worker is created,
    // makes Swoole the default eventLoop driver for ALL workers in
    // this process. The original 0.2a PR shipped the wrong identifier
    // (`Worker::$eventLoop = ...`), which raised
    // `Access to undeclared static property` on every `php start.php
    // <subcommand>` invocation. Caught by the cumulative-pass review
    // after 0.2c shipped its hub mirror.
    Worker::$eventLoopClass = \Workerman\Events\Swoole::class;
    \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
} else {
    trigger_error('Swoole extension not detected — coroutine runtime will not be active. Install ext-swoole to enable.', E_USER_WARNING);
}

// -----------------------------------------------------------------------------
// 2. Per-process configuration that the Workerman master needs
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
// Workerman's default log file is `workerman.log` in the current
// directory; under `ProtectSystem=strict` the install dir is read-only,
// so writes there fail with EROFS. Point it at the same log tree the
// service unit already opens via ReadWritePaths.
$workerLogFile = is_string($workerCfg['log_file'] ?? null)
    ? $workerCfg['log_file']
    : (is_dir('/var/log/phlix') ? '/var/log/phlix/workerman.log' : __DIR__ . '/.logs/workerman.log');
@mkdir(dirname($workerLogFile), 0775, true);
Worker::$logFile = $workerLogFile;

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

    // Build the full route table + middleware chain once per worker.
    // {@see Application::__construct()} only registers routes/middleware
    // — it does NOT call boot() or run() and therefore does not start
    // hub/relay/discovery/newsletter/backup timers. The hub heartbeat
    // and relay tunnels still need their own one-shot startup; that's
    // wired below outside this closure so it runs once per worker too.
    $application = new Application($container, $config);

    $w->onMessage = new HttpHandler($container, $authManager, $publicRoot, $application);
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
