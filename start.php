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
use Phlix\Server\Runtime\SwooleRuntime;
use Phlix\Server\Workerman\HttpHandler;
use Workerman\Worker;

// -----------------------------------------------------------------------------
// 1. Configuration (built first so the coroutine-runtime setup below can read
//    the `coroutine` settings).
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
// 0. Coroutine runtime — make Swoole the eventLoop driver and enable a CURATED
//    set of coroutine hooks in the master process before any Worker is created.
//
//    Hooking *every* native call (SWOOLE_HOOK_ALL) crashed the HTTP worker with
//    recurring general-protection faults inside swoole.so (`exit with status
//    139` = SIGSEGV) on the PHP 8.5 / Swoole 6.2.1 / kernel-7 (io_uring) stack —
//    the faults correlate with hooked file IO (io_uring), process spawns (the
//    on-demand ffmpeg transcode shells out) and native curl. {@see SwooleRuntime}
//    keeps the socket/sleep/stream hooks (so the coroutine MySQL pool + network
//    IO still yield) and drops FILE/PROC/CURL/STDIO, which run as ordinary
//    blocking syscalls instead. Tune or hard-disable via config('coroutine').
//    Degrades gracefully with a warning if ext-swoole is not yet available.
// -----------------------------------------------------------------------------

if (extension_loaded('swoole')) {
    // The canonical Workerman 5 static is `Worker::$eventLoopClass` (not the
    // per-instance `Worker::$eventLoop`); set it before any Worker exists so
    // Swoole drives the eventLoop for every worker in this process.
    Worker::$eventLoopClass = \Workerman\Events\Swoole::class;
    if (SwooleRuntime::coroutineEnabled($config)) {
        \Swoole\Runtime::enableCoroutine(SwooleRuntime::resolveHookFlags($config));
    }
} else {
    trigger_error('Swoole extension not detected — coroutine runtime will not be active. Install ext-swoole to enable.', E_USER_WARNING);
}

// -----------------------------------------------------------------------------
// 0b. Re-assert the curated coroutine hook mask inside every worker.
//
// The curated enableCoroutine() above runs in the MASTER, but Workerman's
// Swoole event adapter resets the mask back to SWOOLE_HOOK_ALL in its
// constructor — `Workerman\Events\Swoole::__construct()` calls
// `Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL])`. That constructor runs
// once per worker when `Worker::$globalEvent` is created (in forkOneWorker*),
// AFTER the master's curated enableCoroutine() and immediately BEFORE the
// worker's onWorkerStart. Left unchecked it silently re-enables the
// FILE(io_uring)/PROC/NATIVE_CURL/blocking-function hooks the allowlist exists
// to avoid — which is exactly what reintroduced the recurring swoole.so
// general-protection faults (`exit with status 139`) on the PHP 8.5 / Swoole
// 6.2.1 / kernel-7 io_uring stack. We re-apply the curated mask via the SAME
// `Coroutine::set(['hook_flags' => ...])` API at the top of every worker's
// onWorkerStart (the first user code after that constructor), so each worker
// actually serves traffic with the safe hook set. {@see SwooleRuntime}
$applyCuratedCoroutineHooks = static function () use ($config): void {
    if (extension_loaded('swoole') && SwooleRuntime::coroutineEnabled($config)) {
        \Swoole\Coroutine::set(['hook_flags' => SwooleRuntime::resolveHookFlags($config)]);
    }
};

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
$httpWorker->onWorkerStart = static function (Worker $w) use ($config, $publicRoot, $applyCuratedCoroutineHooks): void {
    $applyCuratedCoroutineHooks();
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
// 4b. Managed worker processes (1.1b).
//
// This app is hand-rolled (no Webman `support\App::run()`), so config/process.php
// is NOT auto-consumed by the framework — we read it here and spawn each enabled
// entry as a sibling Worker under the same Worker::runAll() process group, so
// `php start.php start` supervises HTTP + workers together (reload-able as one
// group). Additive + guarded: a failure building any worker must NOT take down
// the HTTP workers (they are separate processes), so the spawn loop is wrapped
// in try/catch and the per-worker container is built inside onWorkerStart (it
// cannot be built before fork).
// -----------------------------------------------------------------------------

try {
    /** @var mixed $processCfgRaw */
    $processCfgRaw = @include __DIR__ . '/config/process.php';
    $processCfg = is_array($processCfgRaw) ? $processCfgRaw : [];

    $scanCfgRaw = $processCfg['library-scan'] ?? null;
    $scanCfg = is_array($scanCfgRaw) ? $scanCfgRaw : [];

    if (!empty($scanCfg['enabled'])) {
        $scanCount = isset($scanCfg['count']) && is_int($scanCfg['count']) && $scanCfg['count'] > 0
            ? $scanCfg['count']
            : 1;
        $scanPollSeconds = isset($scanCfg['poll_seconds']) && is_int($scanCfg['poll_seconds']) && $scanCfg['poll_seconds'] > 0
            ? $scanCfg['poll_seconds']
            : 5;

        $scanWorker = new Worker();
        $scanWorker->count = $scanCount;
        $scanWorker->name = 'phlix-library-scan';
        $scanWorker->onWorkerStart = static function (Worker $w) use ($config, $scanPollSeconds, $applyCuratedCoroutineHooks): void {
            $applyCuratedCoroutineHooks();
            // Built inside the fork so each child owns its long-lived state.
            $container = ContainerFactory::create($config);
            /** @var \Phlix\Media\Library\LibraryScanWorker $libraryScanWorker */
            $libraryScanWorker = $container->get(\Phlix\Media\Library\LibraryScanWorker::class);
            $libraryScanWorker->start($scanPollSeconds);
        };
    }

    $autoUpdateCfgRaw = $processCfg['plugin-auto-update'] ?? null;
    $autoUpdateCfg = is_array($autoUpdateCfgRaw) ? $autoUpdateCfgRaw : [];

    if (!empty($autoUpdateCfg['enabled'])) {
        $autoUpdatePoll = isset($autoUpdateCfg['poll_seconds'])
            && is_int($autoUpdateCfg['poll_seconds']) && $autoUpdateCfg['poll_seconds'] > 0
            ? $autoUpdateCfg['poll_seconds']
            : 86400;

        $autoUpdateWorker = new Worker();
        $autoUpdateWorker->count = 1;
        $autoUpdateWorker->name = 'phlix-plugin-auto-update';
        $autoUpdateWorker->onWorkerStart = static function (Worker $w) use ($config, $autoUpdatePoll, $applyCuratedCoroutineHooks): void {
            $applyCuratedCoroutineHooks();
            $container = ContainerFactory::create($config);
            /** @var \Phlix\Plugins\Catalog\PluginAutoUpdateWorker $pluginAutoUpdateWorker */
            $pluginAutoUpdateWorker = $container->get(\Phlix\Plugins\Catalog\PluginAutoUpdateWorker::class);
            $pluginAutoUpdateWorker->start($autoUpdatePoll);
        };
    }
} catch (\Throwable $e) {
    // A misconfigured worker must not stop the HTTP server from booting.
    trigger_error('Failed to set up managed worker processes: ' . $e->getMessage(), E_USER_WARNING);
}

// -----------------------------------------------------------------------------
// 4c. Hub heartbeat worker.
//
// When the server is enrolled with a hub, it must POST periodic heartbeats
// so the hub keeps it marked online. The heartbeat is a Workerman\Timer
// armed by HubClient::startHeartbeatLoop(); a Timer only ticks inside a
// running worker, and a Worker created after runAll() never forks — so it
// MUST be declared here, before runAll(). count=1 so an enrolled server
// emits a single heartbeat stream (not one per HTTP worker). HubApplication
// ->start() is a no-op when there is no enrollment, so this is harmless on
// an unpaired server.
// -----------------------------------------------------------------------------

try {
    $hubHeartbeatWorker = new Worker();
    $hubHeartbeatWorker->count = 1;
    $hubHeartbeatWorker->name = 'phlix-hub-heartbeat';
    $hubHeartbeatWorker->onWorkerStart = static function (Worker $w) use ($config, $applyCuratedCoroutineHooks): void {
        $applyCuratedCoroutineHooks();
        // Built inside the fork so the child owns its own DB/HTTP state.
        $container = ContainerFactory::create($config);
        /** @var \Phlix\Hub\HubApplication $hubApp */
        $hubApp = $container->get(\Phlix\Hub\HubApplication::class);
        $hubApp->start();

        // If the server isn't enrolled yet, poll for an enrollment that appears
        // later — i.e. when the operator pairs this RUNNING server — so the
        // heartbeat loop starts without a process restart. The timer stops once
        // the loop is running (or self-clears if the worker can't be set up).
        if (!$hubApp->isRunning()) {
            $retryTimer = null;
            $retryTimer = \Workerman\Timer::add(15, static function () use ($hubApp, &$retryTimer): void {
                if ($hubApp->isRunning()) {
                    if ($retryTimer !== null) {
                        \Workerman\Timer::del($retryTimer);
                    }
                    return;
                }
                if ($hubApp->isEnrolled()) {
                    $hubApp->start();
                }
            });
        }
    };
} catch (\Throwable $e) {
    // The heartbeat worker is best-effort; never block the HTTP server.
    trigger_error('Failed to set up hub heartbeat worker: ' . $e->getMessage(), E_USER_WARNING);
}

// -----------------------------------------------------------------------------
// 5. Run
// -----------------------------------------------------------------------------

Worker::runAll();
