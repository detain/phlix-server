<?php

declare(strict_types=1);

/**
 * Phlix Media Server — Worker startup entry point.
 *
 * Workerman 5.x supports autostart (worker processes start via `php start.php start`
 * and are supervised). The classic pattern is `start.php` + `support\App::run()` (vendor/workerman/webman-framework):
 *   1. Worker global state (loop, TLS context, etc.).
 *   2. Per-worker init (signals, status file, ID).
 *   3. Worker instances + callbacks.
 *   4. `Worker::runAll()` — fork + event loop.
 *
 * This file follows the same structure but uses a hand-rolled Application class
 * instead of Webman's App. The HTTP worker is Workerman's stock HttpServer; all
 * other functionality (hub heartbeat, relay tunnel, etc.) is injected via
 * per-worker `onWorkerStart` callbacks.
 *
 * @package Phlix\Server
 */

use Phlix\Auth\AuthManager;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Core\Application;
use Phlix\Server\Workerman\HttpHandler;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';

// -----------------------------------------------------------------------------
// 0. Pre-flight checks
// -----------------------------------------------------------------------------

if (!function_exists('pcntl_fork')) {
    echo "ERROR: pcntl extension is required for Workerman.\n";
    exit(1);
}

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
//    blocking calls. This is the minimum surface area needed for the media
//    server's async workloads.
// -----------------------------------------------------------------------------

// Swoole must be Workerman's event loop driver, and Worker::$eventLoopClass MUST
// be assigned here in the MASTER process — before any Worker exists and before
// Worker::runAll() — NEVER inside onWorkerStart.
//
// Worker::run() dispatches the per-worker callback with
//   match (Worker::$eventLoopClass) { Swoole::class => Coroutine::create($cb),
//                                     default => (new \Fiber($cb))->start() }
// and Workerman\Coroutine\Context::initDriver() picks its context backend
// (Swoole vs Fiber) from that SAME static. If eventLoopClass is only set later
// (inside onWorkerStart), run() has already taken the plain-Fiber branch while
// the context driver resolves to Swoole — so the finally{} Context::destroy()
// calls Swoole\Coroutine::getContext() OUTSIDE any coroutine, gets null, and
// fatals: "Call to a member function exchangeArray() on null". Every worker then
// dies on startup and Workerman re-forks in a tight loop (100% CPU, no service).
// Setting it in the master keeps dispatch and the context driver consistent: the
// worker callback runs inside a real Swoole coroutine where getContext() is valid.
if (extension_loaded('swoole')) {
    Worker::$eventLoopClass = \Workerman\Events\Swoole::class;
    if (\Phlix\Server\Runtime\SwooleRuntime::coroutineEnabled($config)) {
        // Enable the CURATED coroutine hook mask in the master so children inherit
        // it. resolveHookFlags() handles Swoole 5/6 constant differences (e.g.
        // SWOOLE_HOOK_SOCKET was removed in Swoole 6).
        \Swoole\Runtime::enableCoroutine(\Phlix\Server\Runtime\SwooleRuntime::resolveHookFlags($config));
    }
} else {
    trigger_error(
        'Swoole extension not detected — coroutine runtime will not be active. Install ext-swoole to enable.',
        E_USER_WARNING
    );
}

// Re-assert the curated coroutine hook mask inside every worker. Workerman's
// Swoole event adapter constructor resets hook_flags back to SWOOLE_HOOK_ALL once
// per worker (right before onWorkerStart), which would silently re-enable the
// FILE(io_uring)/PROC/CURL/blocking-function hooks the allowlist exists to avoid
// (those reintroduce the swoole.so SIGSEGV on this PHP 8.5 / Swoole 6.2.1 / kernel-7
// io_uring stack). Re-applying via the same Coroutine::set() API at the top of each
// worker keeps it on the safe hook set. {@see SwooleRuntime}
$applyCuratedCoroutineHooks = static function () use ($config): void {
    if (extension_loaded('swoole') && \Phlix\Server\Runtime\SwooleRuntime::coroutineEnabled($config)) {
        \Swoole\Coroutine::set(['hook_flags' => \Phlix\Server\Runtime\SwooleRuntime::resolveHookFlags($config)]);
    }
};

// -----------------------------------------------------------------------------
// 2. Public root (used by HttpHandler for SSR asset lookups).
// -----------------------------------------------------------------------------

$publicRoot = realpath(__DIR__ . '/public') ?: __DIR__ . '/public';

// -----------------------------------------------------------------------------
// 3. HTTP worker.
//
// The container can't be built before fork (it caches workerman/mysql
// PDO sockets and the like). Build it inside onWorkerStart so each
// child has its own copy of long-lived state.
// -----------------------------------------------------------------------------

$httpWorker = new Worker('http://0.0.0.0:8096');
$httpWorker->count = 14;
$httpWorker->name = 'phlix-server-http';
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
// 4d. Relay tunnel worker.
//
// When the server is enrolled with a hub and relay is enabled in config,
// this worker establishes the outbound WebSocket tunnel to the hub's relay
// worker (port 8802). Like the heartbeat, it must be declared before
// runAll() so the forked child owns its own connection state.
// -----------------------------------------------------------------------------

try {
    $relayTunnelWorker = new Worker('text://0.0.0.0:0');
    $relayTunnelWorker->count = 1;
    $relayTunnelWorker->name = 'phlix-relay-tunnel';
    $relayTunnelWorker->onWorkerStart = static function (Worker $w) use ($config, $applyCuratedCoroutineHooks): void {
        $applyCuratedCoroutineHooks();
        // Built inside the fork so the child owns its own DB/HTTP state.
        $container = ContainerFactory::create($config);

        /** @var \Phlix\Hub\RelayConfig $relayConfig */
        $relayConfig = $container->get(\Phlix\Hub\RelayConfig::class);

        /** @var \Phlix\Hub\HubClient $hubClient */
        $hubClient = $container->get(\Phlix\Hub\HubClient::class);

        /** @var \Phlix\Common\Logger\StructuredLogger $logger */
        $logger = $container->get('logger.hub');

        $enrollment = $hubClient->loadEnrollment();
        $serverId = $enrollment !== null ? $enrollment->serverId : '';

        // Auto-enable the relay tunnel once the server is paired with a hub
        // (P1): the presence of a stored enrollment is enough — no
        // PHLIX_RELAY_ENABLED env var required. An explicit
        // PHLIX_RELAY_DISABLED=1 still wins as an operator kill-switch.
        $relayDisabled = in_array(
            strtolower((string) (getenv('PHLIX_RELAY_DISABLED') ?: '')),
            ['1', 'true', 'yes', 'on'],
            true,
        );
        if ($enrollment !== null && !$relayDisabled) {
            $relayConfig = $relayConfig->withAutoEnable($enrollment->hubBaseUrl);
        }

        // Build the in-process HTTP dispatcher so HTTP_REQUEST frames route
        // through the same local app routers the HTTP daemon uses. The
        // Application constructor only registers routes/middleware (no timers),
        // so building one here in the relay fork is safe and side-effect-free.
        $relayApplication = new Application($container, $config);
        $relayDispatcher = new \Phlix\Hub\RelayRequestDispatcher($relayApplication, $container);

        $consumer = new \Phlix\Hub\RelayConsumer(
            $relayConfig,
            $hubClient,
            $logger,
            $serverId,
            null,
            null,
            static fn (\Phlix\Server\Http\Request $req): \Phlix\Server\Http\Response
                => $relayDispatcher->dispatch($req),
        );

        $consumer->start();
    };
} catch (\Throwable $e) {
    // The relay tunnel is best-effort; never block the HTTP server.
    trigger_error('Failed to set up relay tunnel worker: ' . $e->getMessage(), E_USER_WARNING);
}

// -----------------------------------------------------------------------------
// 5. Run
// -----------------------------------------------------------------------------

Worker::runAll();
