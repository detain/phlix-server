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
use Phlix\Common\Container\Providers\AuthServicesProvider;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Workerman\HttpHandler;
use Phlix\Stats\Metrics\MetricsCollector;
use Phlix\Stats\Metrics\MetricsFlushService;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';

// -----------------------------------------------------------------------------
// 0. Pre-flight checks
// -----------------------------------------------------------------------------

if (!function_exists('pcntl_fork')) {
    echo "ERROR: pcntl extension is required for Workerman.\n";
    exit(1);
}

// Refuse to boot with a missing or default JWT signing key (S5). The same secret
// also derives the media signed-URL key (see Phlix\Auth\SignedUrl::fromEnv), so a
// default/empty value would make both JWTs and stream URLs forgeable. Skipped in
// the test environment by AuthServicesProvider::assertSecretConfigured(). This runs
// before any Worker is created or Worker::runAll() forks, so a misconfigured server
// fails fast with a clear CRITICAL message instead of serving with a guessable key.
try {
    AuthServicesProvider::assertSecretConfigured();
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
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

    // HttpHandler arg #2 is a RequestAuthenticator (the shared auth
    // collaborator), NOT the raw AuthManager. Wrap the AuthManager exactly
    // like the CGI entry point does (see public/index.php) so the daemon and
    // CGI dispatch paths construct the handler identically and cannot drift.
    $authenticator = new RequestAuthenticator($authManager);

    // Build the full route table + middleware chain once per worker.
    // {@see Application::__construct()} only registers routes/middleware
    // — it does NOT call boot() or run() and therefore does not start
    // hub/relay/discovery/newsletter/backup timers. The hub heartbeat
    // and relay tunnels still need their own one-shot startup; that's
    // wired below outside this closure so it runs once per worker too.
    $application = new Application($container, $config);

    /** @var MetricsCollector $metricsCollector */
    $metricsCollector = $container->get(MetricsCollector::class);

    $w->onMessage = new HttpHandler(
        $container,
        $authenticator,
        $publicRoot,
        $application,
        $metricsCollector,
    );

    // S2 metrics: arm the flush timer in this HTTP worker.
    if ($metricsCollector->isEnabled()) {
        /** @var array{flush_interval_seconds?: int} $metricsConfig */
        $metricsConfig = $config['metrics'] ?? [];
        /** @var MetricsFlushService $flushService */
        $flushService = $container->get(MetricsFlushService::class);
        $flushInterval = (int) ($metricsConfig['flush_interval_seconds'] ?? 5);
        \Workerman\Timer::add(
            $flushInterval,
            static function () use ($flushService, $w): void {
                $flushService->flush((int) $w->id, (int) time());
            },
        );
    }
};

// -----------------------------------------------------------------------------
// 4. (Future) WebSocket worker on port 8097 for sync-play, etc.
//    Wire src/Server/WebSocket/WebSocketServer.php here once it's
//    needed at boot time. For now the HTTP worker alone covers the
//    REST + SSR surface.
// -----------------------------------------------------------------------------

// -----------------------------------------------------------------------------
// 4a. WebSocket worker for SyncPlay realtime communication (SP1).
//
// SyncPlay requires exactly ONE authoritative SyncPlayManager shared across all
// WS connections, so this worker runs as count=1 on port 8097 (separate from the
// HTTP workers on 8096). The manager is constructed once in onWorkerStart and
// its state persists for the lifetime of this worker process.
//
// Architecture:
//   - WebSocketServer accepts an injected MessageHandler so the same handler
//     instance is used for both SyncPlay message routing and general WS events.
//   - SyncPlayManager::initialize() registers the per-type callbacks that route
//     incoming SyncPlay messages to the appropriate handler methods.
//   - ConnectionPool and MessageHandler are singletons shared within this worker.
// -----------------------------------------------------------------------------

try {
    $wsWorker = new Worker('websocket://0.0.0.0:8097');
    $wsWorker->count = 1;
    $wsWorker->name = 'phlix-server-ws';
    $wsWorker->onWorkerStart = static function (Worker $w) use ($config, $applyCuratedCoroutineHooks): void {
        $applyCuratedCoroutineHooks();

        // Build the container inside the fork so each worker owns its own state.
        $container = ContainerFactory::create($config);

        // Create the shared MessageHandler and ConnectionPool singletons.
        $connections = \Phlix\Server\WebSocket\ConnectionPool::getInstance();
        $messageHandler = new \Phlix\Server\WebSocket\MessageHandler($connections);

        // Construct ONE authoritative SyncPlayManager and initialize it with the
        // message handler so SyncPlay message types are routed to their handlers.
        /** @var \Phlix\Common\Logger\StructuredLogger $logger */
        $logger = $container->get('logger.websocket');
        $syncPlayManager = new \Phlix\Session\SyncPlay\SyncPlayManager($logger);
        $syncPlayManager->initialize($messageHandler);

        // SP5: Set the snapshot service so mutations are published to the DB
        // snapshot table, allowing HTTP workers to read the authoritative state.
        /** @var \Phlix\Session\SyncPlay\SyncPlaySnapshotService $snapshotService */
        $snapshotService = $container->get(\Phlix\Session\SyncPlay\SyncPlaySnapshotService::class);
        $syncPlayManager->setSnapshotService($snapshotService);

        // Build and configure the WebSocket server with the shared manager.
        $wsConfigRaw = $config['websocket'] ?? null;
        $wsConfig = is_array($wsConfigRaw) ? $wsConfigRaw : [];
        $wsConfig['host'] = $wsConfig['host'] ?? '0.0.0.0';
        $wsConfig['port'] = $wsConfig['port'] ?? 8097;
        $wsConfig['stale_connection_timeout'] = $wsConfig['stale_connection_timeout'] ?? 300;
        $wsConfig['stale_group_timeout'] = $wsConfig['stale_group_timeout'] ?? 3600;

        /** @var array<string, mixed> $wsConfig */
        $wsServer = new \Phlix\Server\WebSocket\WebSocketServer($wsConfig, $messageHandler);
        $wsServer->setSyncPlayManager($syncPlayManager);

        // S2 metrics: wire the metrics collector into the WS server.
        /** @var MetricsCollector $wsMetricsCollector */
        $wsMetricsCollector = $container->get(MetricsCollector::class);
        if ($wsMetricsCollector->isEnabled()) {
            $wsServer->setMetricsCollector($wsMetricsCollector);
        }

        // Trigger onStart to log the startup message and arm cleanup timers.
        // The actual Workerman worker callbacks (onConnect, onMessage, onClose)
        // are already bound in the WebSocketServer constructor.
        $wsServer->onStart();

        // S2 metrics: arm the flush timer in the WS worker.
        if ($wsMetricsCollector->isEnabled()) {
            /** @var MetricsFlushService $wsFlushService */
            $wsFlushService = $container->get(MetricsFlushService::class);
            /** @var array{flush_interval_seconds?: int} $wsMetricsConfig */
            $wsMetricsConfig = $config['metrics'] ?? [];
            $wsFlushInterval = (int) ($wsMetricsConfig['flush_interval_seconds'] ?? 5);
            \Workerman\Timer::add(
                $wsFlushInterval,
                static function () use ($wsFlushService, $w): void {
                    $wsFlushService->flush((int) $w->id, (int) time());
                },
            );
        }

        /** @var \Phlix\Common\Logger\StructuredLogger $wsLogger */
        $wsLogger = $container->get('logger.websocket');
        $wsLogger->info('SyncPlay manager initialized', [
            'message' => 'One SyncPlayManager instance handles all WS connections',
        ]);
    };
} catch (\Throwable $e) {
    // The WS worker is best-effort; never block the HTTP server.
    trigger_error('Failed to set up WebSocket worker: ' . $e->getMessage(), E_USER_WARNING);
}

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
// 4e. Config-driven managed workers (1.1b): library-scan + plugin-auto-update.
//
// config/process.php is the single source of truth for these long-running
// pollers, but this hand-rolled start.php is NOT auto-consumed by Webman, so we
// read it here and spawn each ENABLED entry as a count-sized sibling Worker
// under this same Worker::runAll() group — supervised alongside HTTP, restarted
// as one group, and (critically) running under the service's LimitMEMLOCK +
// the curated coroutine hooks, so the scan loop no longer dies on Swoole's
// io_uring ENOMEM the way the standalone `scripts/run-library-scan-worker.php`
// does under a default RLIMIT_MEMLOCK. The standalone script remains an
// alternative for operators who isolate the worker; running both is safe
// because ScanJobRepository::claimNext() is an atomic single-claimer UPDATE.
//
// Earlier this spawn loop lived here but was dropped during the Swoole
// event-loop refactor, leaving the `library_scan_jobs` queue with nothing to
// drain unless an operator ran the standalone script by hand.
// -----------------------------------------------------------------------------

/** Managed-worker key → its DI-resolvable class exposing `start(int $pollSeconds)`. */
$managedWorkerClasses = [
    'library-scan'       => \Phlix\Media\Library\LibraryScanWorker::class,
    'plugin-auto-update' => \Phlix\Plugins\Catalog\PluginAutoUpdateWorker::class,
];

try {
    /** @var array<string, array{enabled?: bool, count?: int, poll_seconds?: int}> $processConfig */
    $processConfig = require __DIR__ . '/config/process.php';
    if (is_array($processConfig)) {
        foreach ($managedWorkerClasses as $procKey => $workerClass) {
            $settings = $processConfig[$procKey] ?? null;
            if (!is_array($settings) || ($settings['enabled'] ?? false) !== true) {
                continue;
            }
            $count = (int) ($settings['count'] ?? 1);
            $pollSeconds = (int) ($settings['poll_seconds'] ?? 5);

            $managedWorker = new Worker();
            $managedWorker->count = $count > 0 ? $count : 1;
            $managedWorker->name = 'phlix-' . $procKey;
            $managedWorker->onWorkerStart = static function (Worker $w) use (
                $config,
                $applyCuratedCoroutineHooks,
                $workerClass,
                $pollSeconds
            ): void {
                $applyCuratedCoroutineHooks();
                try {
                    // Built inside the fork so the child owns its own DB/HTTP state.
                    $container = ContainerFactory::create($config);
                    /** @var \Phlix\Media\Library\LibraryScanWorker|\Phlix\Plugins\Catalog\PluginAutoUpdateWorker $managed */
                    $managed = $container->get($workerClass);
                    // Arms a Workerman\Timer that polls runOnce() every $pollSeconds.
                    $managed->start($pollSeconds);
                } catch (\Throwable $e) {
                    // Guard the fork: log and idle rather than exit, so a build
                    // failure can't put the worker into a tight re-fork loop.
                    trigger_error(
                        'Managed worker ' . $workerClass . ' failed to start: ' . $e->getMessage(),
                        E_USER_WARNING,
                    );
                }
            };
        }
    }
} catch (\Throwable $e) {
    // Best-effort; never block the HTTP server.
    trigger_error('Failed to set up managed worker processes: ' . $e->getMessage(), E_USER_WARNING);
}

// -----------------------------------------------------------------------------
// 5. Run
// -----------------------------------------------------------------------------

Worker::runAll();
