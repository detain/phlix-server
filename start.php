<?php

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

declare(strict_types=1);

use Phlix\Auth\AuthManager;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\Providers\AuthServicesProvider;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\RateLimit\RateLimiterInterface;
use Phlix\Common\RateLimit\RateLimitProfiles;
use Phlix\Config\EffectiveConfig;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Runtime\PidFile;
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

// Point Workerman's master PID file at config/server.php's `worker.pid_file`.
// MUST run in the master, before Worker::runAll(). Without this, Workerman uses
// its own default (dirname(start.php)/workerman.start.php.pid) while
// AdminRestartController reads the configured path — so the admin restart
// endpoint 500s with "PID file not found" on every real box. Non-fatal: an
// unusable directory leaves Workerman's default in place rather than aborting
// boot. {@see \Phlix\Server\Runtime\PidFile}
if (PidFile::apply($config) === null) {
    fwrite(
        STDERR,
        "[phlix] WARNING: worker.pid_file is unset or its directory is not writable; "
        . "falling back to Workerman's default PID path. POST /api/v1/admin/restart will not work.\n"
    );
}

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
    // Overlay the persisted `server_settings` overrides onto the boot config
    // BEFORE the container is built, so every DI provider that reads boot
    // config observes the EFFECTIVE value. This is what makes the schema's
    // `"restart": true` keys actually apply on a restart/graceful reload —
    // the master's $config is frozen at fork, but this runs per worker start.
    // Never throws: an unreachable settings store leaves $config untouched.
    // {@see \Phlix\Config\EffectiveConfig}
    $config = EffectiveConfig::bootstrapAndOverlay($config);
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
    /** @var ConnectionPool $connectionPool */
    $connectionPool = $container->get(ConnectionPool::class);
    $application = new Application($container, $config, $connectionPool);

    // Warm WebPortalRouter once per worker so its heavy, I/O-yielding factory
    // (it resolves ~20 services and performs the first pooled DB handshake +
    // settings reads) is cached in php-di's resolvedEntries BEFORE this worker
    // accepts concurrent /api/ requests. WebPortalRouter is otherwise resolved
    // LAZILY per request (see HttpHandler step 1b). php-di's cycle guard
    // `entriesBeingResolved` is INSTANCE state on the single per-worker
    // container and is NOT coroutine-safe: if request-coroutine A is mid-resolve
    // when its factory yields on DB I/O, a second concurrent request-coroutine B
    // sees the leaked guard flag and throws a spurious "Circular dependency
    // detected ... WebPortalRouter -> WebPortalRouter" 500. This is a transient
    // cold-start race after every worker restart. Resolving it here — once, in
    // this single onWorkerStart coroutine (Worker::run() dispatches the callback
    // via Coroutine::create), after the pool is ready and BEFORE $w->onMessage
    // is assigned below (so no request can race it) — caches the instance so
    // every later get() short-circuits on resolvedEntries before the guard runs.
    // Non-fatal: the lazy per-request path still applies if this throws.
    if ($container->has(\Phlix\Server\WebPortal\WebPortalRouter::class)) {
        try {
            $container->get(\Phlix\Server\WebPortal\WebPortalRouter::class);
        } catch (\Throwable $e) {
            LoggerFactory::get(LogChannels::HTTP)->debug(
                'WebPortalRouter warm-up skipped (will resolve lazily per request)',
                ['error' => $e->getMessage()],
            );
        }
    }

    // F1 — plugin boot activation. Re-attach every persisted-enabled plugin into
    // THIS HTTP worker's ListenerRegistry + SourceRegistry, so playback events
    // reach scrobbler plugins (trakt/lastfm) and metadata-source plugins are
    // registered immediately after a restart — not only in whichever single
    // worker happened to serve an admin "enable" request. WIRE-ONLY (no
    // persist/audit — the row is already enabled): {@see PluginLoader::bootstrapEnabled()}.
    // Every plugin's onEnable() is de-blocked/non-blocking, so this cannot hang
    // the worker at boot (the item-5c3 landmine that reverted the naive version).
    // Non-fatal: a bootstrap failure must never stop the worker from serving.
    try {
        $container->get(\Phlix\Plugins\PluginLoader::class)->bootstrapEnabled();
    } catch (\Throwable $e) {
        LoggerFactory::get(LogChannels::PLUGINS)->error(
            'plugin boot activation failed (HTTP worker)',
            ['worker_id' => $w->id ?? '?', 'error' => $e->getMessage()],
        );
    }

    // S44 — register enabled external auth providers (OIDC/LDAP) into THIS HTTP
    // worker's in-memory AuthProviderRegistry. The registry is per-worker state,
    // so a provider enabled via the admin API in one worker is otherwise absent
    // from the others until they restart; this boot pass re-attaches every
    // provider whose `auth.<name>.enabled` server-setting is on AND whose plugin
    // settings are complete. It reads settings + instantiates the provider only
    // (OIDC discovery/JWKS are fetched lazily on first authenticate), so it does
    // NO blocking I/O at boot. Scoped to the HTTP worker on purpose: provider
    // login (AuthController / OidcCallbackController) is only reachable on the
    // HTTP dispatch path — the WS/heartbeat/relay/managed-scan workers never run
    // it, so registering there would only hold dead instances (the same
    // rationale the plugin-bootstrap block uses to gate itself to library-scan).
    // Non-fatal: a failure must never stop the worker from serving.
    try {
        $container->get(\Phlix\Auth\AuthProviderBootstrapper::class)->registerEnabledProviders();
    } catch (\Throwable $e) {
        LoggerFactory::get(LogChannels::AUTH)->error(
            'auth provider boot registration failed (HTTP worker)',
            ['worker_id' => $w->id ?? '?', 'error' => $e->getMessage()],
        );
    }

    // SV-0.1: probe hardware acceleration exactly once per worker at start (not
    // per request) and log the chosen accelerator a single time. Resolving the
    // FfmpegRunner runs the DI factory which calls setConfig() + the probe; the
    // probe is idempotent (guarded by $hwaccelProbed) so this explicit call is
    // safe and merely guarantees it happened at boot. It runs OUTSIDE any
    // coroutine here, so the probe's blocking exec cannot stall the event loop.

    // Trace context for the single structured worker-start log emitted below.
    $workerTraceId = substr(md5((string)mt_rand() . microtime(true)), 0, 12);
    $workerPid = getmypid();

    /** @var \Phlix\Media\Transcoding\FfmpegRunner $ffmpegRunner */
    $ffmpegRunner = $container->get(\Phlix\Media\Transcoding\FfmpegRunner::class);

    // SV-0.1: The container's FfmpegRunner factory already calls probeHardwareAcceleration()
    // (guarded by $hwaccelProbed to ensure idempotence), so the explicit probe call is
    // redundant. The runner is ready to use; log the summary for visibility.
    $hwSummary = $ffmpegRunner->getHardwareAccelerationSummary();

    LoggerFactory::get(LogChannels::STREAMING)->info(
        'Hardware acceleration probed at worker start',
        array_merge($hwSummary, [
            'trace_id' => $workerTraceId,
            'worker_id' => $w->id ?? '?',
            'pid' => $workerPid,
        ]),
    );

    // SV-3.1e: DVR boot recovery. resumeActiveRecordings() reconciles
    // `livetv_recordings` after a restart — re-attaches live ffmpeg children,
    // marks orphaned rows failed, and re-arms overdue schedules. It must run
    // exactly ONCE per boot, not once per HTTP worker, so it is gated on the
    // first worker in the group ($w->id === 0). Resolving LiveTvManager links
    // the shared Recorder singleton (so tuner resolution works) and the call
    // runs OUTSIDE any coroutine here — resumeActiveRecordings()'s process
    // checks + detached spawns are ordinary blocking calls, valid at boot just
    // like the hwaccel probe above (no coroutine-only work to guard). It is
    // NOT wired in public/index.php: recovery/timers belong only to the
    // long-running Workerman master, never the single-shot CGI request path.
    // (Scheduler Timers are SV-3.1c; this is only the recovery bootstrap.)
    if ((int) $w->id === 0) {
        try {
            /** @var \Phlix\LiveTv\LiveTvManager $liveTvManager */
            $liveTvManager = $container->get(\Phlix\LiveTv\LiveTvManager::class);
            $recoveryStats = $liveTvManager->bootstrap();
            LoggerFactory::get(LogChannels::LIVETV)->info(
                'DVR boot recovery complete',
                $recoveryStats,
            );
        } catch (\Throwable $e) {
            // A DVR recovery failure must never stop the HTTP worker from
            // serving. Log and continue.
            LoggerFactory::get(LogChannels::LIVETV)->error(
                'DVR boot recovery failed',
                ['error' => $e->getMessage()],
            );
        }
    }

    // SV-3.1c: DVR scheduler + timed-stop. Arm a single periodic Timer (worker 0
    // only, mirroring the boot-recovery gate above so 14 HTTP workers don't each
    // run the scan) that on every tick (a) starts recordings whose start_time
    // (minus pre-padding) has arrived — arming a per-recording one-shot stop
    // timer at end_time + post_padding — and (b) stops any in-progress recording
    // whose effective end (end_time + post_padding) has already passed but whose
    // one-shot timer was lost (the boot-recovery / missed-timer safety net). The
    // Workerman Swoole event adapter wraps every timer callback in
    // Coroutine::create() (safeCall), so the DB work + process kills inside the
    // tick run in a valid coroutine context (hooked PDO can yield); the body is
    // additionally wrapped in try/catch so a scan error can never bubble out and
    // kill the worker. Timers belong only to the resident daemon — this is NOT
    // mirrored in public/index.php (single-shot CGI runs no timers).
    if ((int) $w->id === 0) {
        try {
            /** @var \Phlix\LiveTv\Recording\RecordingScheduler $recordingScheduler */
            $recordingScheduler = $container->get(\Phlix\LiveTv\Recording\RecordingScheduler::class);

            /** @var array<string, mixed> $livetvCfg */
            $livetvCfg = is_array($config['livetv'] ?? null) ? $config['livetv'] : [];
            /** @var array<string, mixed> $dvrCfg */
            $dvrCfg = is_array($livetvCfg['dvr'] ?? null) ? $livetvCfg['dvr'] : [];
            $intervalRaw = $dvrCfg['scheduler_interval_seconds'] ?? 30;
            $schedulerInterval = is_int($intervalRaw) && $intervalRaw > 0
                ? $intervalRaw
                : ((is_numeric($intervalRaw) && (int) $intervalRaw > 0) ? (int) $intervalRaw : 30);

            \Workerman\Timer::add(
                $schedulerInterval,
                static function () use ($recordingScheduler): void {
                    try {
                        $recordingScheduler->tick();
                    } catch (\Throwable $e) {
                        LoggerFactory::get(LogChannels::LIVETV)->error(
                            'DVR scheduler tick failed',
                            ['error' => $e->getMessage()],
                        );
                    }
                },
            );

            LoggerFactory::get(LogChannels::LIVETV)->info(
                'DVR scheduler timer armed',
                ['interval_seconds' => $schedulerInterval],
            );
        } catch (\Throwable $e) {
            // A scheduler-wiring failure must never stop the HTTP worker from
            // serving. Log and continue.
            LoggerFactory::get(LogChannels::LIVETV)->error(
                'DVR scheduler timer wiring failed',
                ['error' => $e->getMessage()],
            );
        }
    }

    // SV-3.6a: Trakt → Phlix watched-history pull-sync. The Trakt scrobbler
    // plugin wires only the PUSH direction inline (PlaybackStopped → Trakt); the
    // PULL direction (TraktHistorySync::syncTraktToPhlix) was fully built but had
    // no scheduler, so a connected Trakt account's watched history never flowed
    // back into local WatchHistory. Plugins expose NO scheduling hook
    // (LifecycleInterface has none), so the periodic pull Timer must live here in
    // the resident HTTP worker — worker-0-gated (mirroring the DVR boot-recovery /
    // scheduler gates above) so 14 HTTP workers don't each run the sync, and it is
    // NOT mirrored in public/index.php (the single-shot CGI path runs no timers).
    //
    // Like the DVR scheduler above, the Workerman Swoole event adapter wraps every
    // Timer callback in Coroutine::create() (safeCall), so the pull's de-blocked
    // HTTP client (SV-3.6b) + its \Co\sleep 429-backoff yield to the event loop
    // inside the tick; the body is additionally wrapped in try/catch so a sync
    // failure can never bubble out and kill the worker or cancel the timer. The
    // https transport still takes the accepted blocking-curl branch (EventLoopTls),
    // so the work is deliberately kept on worker 0 only (the gate) with no extra
    // concurrency.
    //
    // The tick resolves a FRESH entry instance each fire via
    // PluginLoader::getEntryInstance() (which applies the DB-persisted settings so
    // runtime enable/token changes are picked up) — the resident server does not
    // bootstrapEnabled() plugins at boot, so we cannot rely on a live enabled
    // instance existing in this worker process.
    if ((int) $w->id === 0) {
        try {
            /** @var \Phlix\Plugins\PluginLoader $pluginLoader */
            $pluginLoader = $container->get(\Phlix\Plugins\PluginLoader::class);

            // Manifest name of the Trakt scrobbler (phlix-plugin-trakt/plugin.json).
            $traktPluginName = 'phlix-plugin-trakt';
            $installedTrakt = $pluginLoader->getInstalled($traktPluginName);
            $traktSettings = \Phlix\Server\Integrations\Trakt\TraktSettings::fromArray(
                $installedTrakt->settings,
            );
            $traktIntervalMinutes = $traktSettings->syncIntervalMinutes;

            // Manifest "master" on/off switch, persisted in settings_json (default
            // false in plugin.json). It is SEPARATE from the catalog-level
            // plugins.enabled column ($installedTrakt->enabled): each tick's
            // TraktPlugin::isConfigured() also requires this master flag (read from
            // $settings['enabled'] in TraktPlugin::configure()). We mirror that same
            // check here so we only ARM when a tick would actually do work — a
            // catalog-enabled + connected account with the master switch OFF no
            // longer arms a perpetually-no-op timer.
            $traktMasterEnabled = ($installedTrakt->settings['enabled'] ?? false) === true;

            // Respect a disabled config: only arm when the plugin is catalog-enabled,
            // the master switch is on, two-way sync is on, and the interval is a
            // positive number of minutes — the same effective condition each tick
            // requires (per-tick token/username gating is still enforced inside
            // syncHistoryFromTrakt(), so a stale-but-armed timer safely no-ops).
            // Limitation (pre-existing, same as the rest of the plugin system):
            // arming runs once at onWorkerStart, so toggling the master switch ON
            // after boot won't arm until the next server restart.
            if (
                $installedTrakt->enabled
                && $traktMasterEnabled
                && $traktSettings->syncEnabled
                && $traktIntervalMinutes > 0
            ) {
                \Workerman\Timer::add(
                    $traktIntervalMinutes * 60,
                    static function () use ($pluginLoader, $container, $traktPluginName): void {
                        try {
                            $plugin = $pluginLoader->getEntryInstance($traktPluginName);
                            if ($plugin instanceof \Phlix\Plugins\Scrobbler\Trakt\TraktPlugin) {
                                $written = $plugin->syncHistoryFromTrakt($container);
                                LoggerFactory::get(LogChannels::PLUGINS)->info(
                                    'Trakt pull-sync tick complete',
                                    ['items_written' => $written],
                                );
                            }
                        } catch (\Throwable $e) {
                            LoggerFactory::get(LogChannels::PLUGINS)->error(
                                'Trakt pull-sync tick failed',
                                ['error' => $e->getMessage()],
                            );
                        }
                    },
                );

                LoggerFactory::get(LogChannels::PLUGINS)->info(
                    'Trakt pull-sync timer armed',
                    ['interval_minutes' => $traktIntervalMinutes],
                );
            } else {
                LoggerFactory::get(LogChannels::PLUGINS)->debug(
                    'Trakt pull-sync timer not armed (plugin disabled, master off, sync off, or interval <= 0)',
                    [
                        'enabled'          => $installedTrakt->enabled,
                        'master_enabled'   => $traktMasterEnabled,
                        'sync_enabled'     => $traktSettings->syncEnabled,
                        'interval_minutes' => $traktIntervalMinutes,
                    ],
                );
            }
        } catch (\Phlix\Plugins\Exception\PluginNotFoundException) {
            // Trakt plugin not installed — nothing to sync. Not an error.
            LoggerFactory::get(LogChannels::PLUGINS)->debug(
                'Trakt plugin not installed; pull-sync timer not armed',
            );
        } catch (\Throwable $e) {
            // A pull-sync wiring failure must never stop the HTTP worker from
            // serving. Log and continue.
            LoggerFactory::get(LogChannels::PLUGINS)->error(
                'Trakt pull-sync timer wiring failed',
                ['error' => $e->getMessage()],
            );
        }
    }

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

        // Effective-config overlay, mirroring the HTTP worker (§7 dual entry
        // points / every fork). {@see \Phlix\Config\EffectiveConfig}
        $config = EffectiveConfig::bootstrapAndOverlay($config);

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
        // SV-4.7: thread the JWT secret so the WS server enforces handshake auth
        // for privileged SyncPlay/dashboard/playback events. Sourced from the SAME
        // JWT_SECRET the HTTP auth layer uses (config/server.php already resolves
        // it); fall back to the env directly so a bare $wsConfig still gets it.
        // Empty (JWT_SECRET unset — dev) → anonymous connections allowed.
        $wsConfig['jwt_secret'] = $wsConfig['jwt_secret'] ?? (getenv('JWT_SECRET') ?: '');

        /** @var array<string, mixed> $wsConfig */
        // Inject THIS worker ($wsWorker) — the one that actually listens on :8097
        // — so WebSocketServer binds its connection-lifecycle callbacks
        // (onConnect / onMessage / onClose / onError / onWebSocketPong) onto the
        // real accepting worker. Without this the callbacks would bind to a
        // throwaway internal Worker created after Worker::runAll() (never listens),
        // so the pool would stay empty and pongs would never reach recordPong() —
        // breaking S-F28 half-open detection and SyncPlay routing in the resident
        // path.
        $wsServer = new \Phlix\Server\WebSocket\WebSocketServer($wsConfig, $messageHandler, $w);
        $wsServer->setSyncPlayManager($syncPlayManager);

        // SV-4.15(h): inject the per-IP WS-connect limiter so onWebSocketConnect
        // throttles handshake attempts BEFORE the auth gate. The :8097 WS worker
        // is count=1, so the worker-local in-memory RateLimitProfiles::WS_CONNECT
        // limiter is already server-wide — no DB backend needed. Best-effort: a
        // container hiccup must never stop the WS worker from listening, so a
        // failed resolve just leaves the hook unthrottled.
        try {
            /** @var mixed $wsConnectLimiter */
            $wsConnectLimiter = $container->get(RateLimitProfiles::WS_CONNECT);
            if ($wsConnectLimiter instanceof RateLimiterInterface) {
                $wsServer->setWsConnectLimiter($wsConnectLimiter);
            }
        } catch (\Throwable $e) {
            LoggerFactory::get(LogChannels::WEBSOCKET)->warning(
                'Failed to wire WS-connect rate limiter; connect hook unthrottled',
                ['error' => $e->getMessage()],
            );
        }

        // S2 metrics: wire the metrics collector into the WS server.
        /** @var MetricsCollector $wsMetricsCollector */
        $wsMetricsCollector = $container->get(MetricsCollector::class);
        if ($wsMetricsCollector->isEnabled()) {
            $wsServer->setMetricsCollector($wsMetricsCollector);
        }

        // Trigger onStart to log the startup message and arm cleanup + ping
        // timers (Timer::add registers into THIS worker process, so the reaper
        // and ping sweeps run here). The connection-lifecycle callbacks
        // (onConnect / onMessage / onClose / onError / onWebSocketPong) were just
        // bound onto $w (this listening worker) by the WebSocketServer
        // constructor above.
        $wsServer->onStart();

        // S2 metrics: arm the live-connection touch timer + the flush timer in the
        // WS worker.
        if ($wsMetricsCollector->isEnabled()) {
            /** @var MetricsFlushService $wsFlushService */
            $wsFlushService = $container->get(MetricsFlushService::class);
            /** @var array{flush_interval_seconds?: int} $wsMetricsConfig */
            $wsMetricsConfig = $config['metrics'] ?? [];
            $wsFlushInterval = (int) ($wsMetricsConfig['flush_interval_seconds'] ?? 5);

            // The WS worker is its own count=1 group, so $w->id is 0 — the SAME id
            // as HTTP worker 0. That collides in the metrics_rollup PK
            // (bucket_started_at, worker_id) and mislabels metrics_connections.worker_id.
            // Namespace the WS worker above the HTTP id space (worker_id is SMALLINT,
            // signed cap 32767; the HTTP pool is count=14 → ids 0-13).
            $wsWorkerId = 10000 + (int) $w->id;

            // Between flushes, push each live connection's current cumulative bytes
            // into the registry so the live-connection panel shows real throughput
            // for the whole connection lifetime (not a zero row until it closes).
            // Touch at least twice per flush window so the flush always sees fresh
            // totals when it computes per-connection rates.
            $wsTouchInterval = max(1, intdiv($wsFlushInterval, 2));
            \Workerman\Timer::add(
                $wsTouchInterval,
                static function () use ($wsServer): void {
                    $wsServer->touchActiveConnections();
                },
            );

            \Workerman\Timer::add(
                $wsFlushInterval,
                static function () use ($wsFlushService, $wsWorkerId): void {
                    $wsFlushService->flush($wsWorkerId, (int) time());
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
        // Effective-config overlay, mirroring the HTTP worker.
        // {@see \Phlix\Config\EffectiveConfig}
        $config = EffectiveConfig::bootstrapAndOverlay($config);
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
// 4c-bis. Background timer worker.
//
// Registers the periodic timers that were previously reachable ONLY from
// Application::run() — a CGI-era entry point this daemon never calls (see the
// note at the top of section 4 and Application::startBackgroundTimers()). The
// omission meant automatic backups never ran, stats_storage was never written
// (blank dashboard Storage card), wedged transcodes never freed their slot, and
// the newsletter never sent.
//
// count=1 is REQUIRED, not an optimisation: these timers write backups and
// storage snapshots, so running them per-HTTP-worker would multiply both by the
// worker count.
// -----------------------------------------------------------------------------

try {
    $backgroundTimerWorker = new Worker();
    $backgroundTimerWorker->count = 1;
    $backgroundTimerWorker->name = 'phlix-background-timers';
    $backgroundTimerWorker->onWorkerStart = static function (Worker $w) use (
        $config,
        $applyCuratedCoroutineHooks
    ): void {
        // Guard the whole body. The outer try/catch below only covers WORKER
        // SETUP; anything thrown in here runs inside the forked child, where an
        // uncaught throwable takes the process down and systemd restarts it —
        // i.e. a crash-loop that would take the HTTP service with it. These
        // timers are strictly best-effort: losing them degrades backups and
        // dashboard stats, which is bad, but it must never cost availability.
        try {
            $applyCuratedCoroutineHooks();
            // Effective-config overlay, mirroring the HTTP worker.
            // {@see \Phlix\Config\EffectiveConfig}
            $config = EffectiveConfig::bootstrapAndOverlay($config);
            // Built inside the fork so the child owns its own DB state.
            $container = ContainerFactory::create($config);
            /** @var ConnectionPool $pool */
            $pool = $container->get(ConnectionPool::class);

            $timerApp = new Application($container, $config, $pool);
            $timerApp->startBackgroundTimers();
        } catch (\Throwable $e) {
            trigger_error(
                'Background timer worker failed to start: ' . $e->getMessage(),
                E_USER_WARNING
            );
        }
    };
} catch (\Throwable $e) {
    // Best-effort, exactly like the heartbeat worker: a timer-setup failure must
    // never stop the HTTP server from booting.
    trigger_error('Failed to set up background timer worker: ' . $e->getMessage(), E_USER_WARNING);
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
        // Effective-config overlay, mirroring the HTTP worker.
        // {@see \Phlix\Config\EffectiveConfig}
        $config = EffectiveConfig::bootstrapAndOverlay($config);
        // Built inside the fork so the child owns its own DB/HTTP state.
        $container = ContainerFactory::create($config);

        /** @var \Phlix\Hub\RelayConfig $relayConfig */
        $relayConfig = $container->get(\Phlix\Hub\RelayConfig::class);

        /** @var \Phlix\Hub\HubClient $hubClient */
        $hubClient = $container->get(\Phlix\Hub\HubClient::class);

        /** @var \Phlix\Common\Logger\StructuredLogger $logger */
        $logger = $container->get('logger.hub');

        // S38/S39: cross-process state store — this relay fork is the SOLE writer
        // of relay-tunnel.state.json (which the HTTP worker's health/admin
        // surfaces read to reflect the real tunnel state), and the SOLE READER of
        // relay-control.json (the operator kill-switch persisted by the admin
        // relay Enable/Disable controls). Resolved here, before the disabled-gate
        // below, so both the boot decision and the consumer share one instance.
        /** @var \Phlix\Hub\RelayStateStore $relayStateStore */
        $relayStateStore = $container->get(\Phlix\Hub\RelayStateStore::class);

        $enrollment = $hubClient->loadEnrollment();
        $serverId = $enrollment !== null ? $enrollment->serverId : '';

        // Auto-enable the relay tunnel once the server is paired with a hub
        // (P1): the presence of a stored enrollment is enough — no
        // PHLIX_RELAY_ENABLED env var required. An explicit operator kill-switch
        // still wins: either the PHLIX_RELAY_DISABLED env var OR the persisted
        // relay-control.json flag written by the admin "Disable" control (S39).
        // The persisted flag is why the toggle "takes effect on reload" — this
        // fork re-reads it on every graceful reload.
        $relayDisabledEnv = in_array(
            strtolower((string) (getenv('PHLIX_RELAY_DISABLED') ?: '')),
            ['1', 'true', 'yes', 'on'],
            true,
        );
        $relayDisabled = $relayDisabledEnv || $relayStateStore->isRelayDisabled();
        if ($enrollment !== null && !$relayDisabled) {
            $relayConfig = $relayConfig->withAutoEnable($enrollment->hubBaseUrl);
        }

        // Build the in-process HTTP dispatcher so HTTP_REQUEST frames route
        // through the same local app routers the HTTP daemon uses. The
        // Application constructor only registers routes/middleware (no timers),
        // so building one here in the relay fork is safe and side-effect-free.
        /** @var ConnectionPool $relayConnectionPool */
        $relayConnectionPool = $container->get(ConnectionPool::class);
        $relayApplication = new Application($container, $config, $relayConnectionPool);
        $relayDispatcher = new \Phlix\Hub\RelayRequestDispatcher($relayApplication, $container);

        // Warm WebPortalRouter once in this relay fork for the same reason as the
        // HTTP worker above: RelayRequestDispatcher::dispatch() resolves it
        // LAZILY (RelayRequestDispatcher.php) for any /api/ path the Application
        // router 404s, and concurrent HTTP_REQUEST frames relayed over the tunnel
        // can race php-di's non-coroutine-safe `entriesBeingResolved` guard,
        // producing the same spurious "circular dependency" 500. Resolving it
        // here — once, before $consumer->start() begins consuming frames — caches
        // it so later get() calls short-circuit on resolvedEntries. Non-fatal.
        if ($container->has(\Phlix\Server\WebPortal\WebPortalRouter::class)) {
            try {
                $container->get(\Phlix\Server\WebPortal\WebPortalRouter::class);
            } catch (\Throwable $e) {
                LoggerFactory::get(LogChannels::HUB)->debug(
                    'WebPortalRouter warm-up skipped in relay fork (will resolve lazily)',
                    ['error' => $e->getMessage()],
                );
            }
        }

        // The relay fork's RelayStateStore (resolved above, before the
        // disabled-gate) is passed to the consumer so it can persist live tunnel
        // state to relay-tunnel.state.json for the HTTP worker to read.
        $consumer = new \Phlix\Hub\RelayConsumer(
            $relayConfig,
            $hubClient,
            $logger,
            $serverId,
            null,
            null,
            static fn (\Phlix\Server\Http\Request $req): \Phlix\Server\Http\Response
                => $relayDispatcher->dispatch($req),
            $relayStateStore,
        );

        // SV-4.2 ([S-F23], X1): wire the segment-process registry so HTTP_CANCEL
        // frames can kill any tracked on-demand encode. This relay fork's
        // dispatcher ($relayApplication) launches segment encodes in THIS process,
        // so they register into this same registry singleton. (Full request-keyed
        // matching — registering encodes under the relay request id so a cancel
        // finds them directly — is the deferred follow-up; today closeLocalConnection
        // below triggers the HTTP poll-loop wait-timeout kill.)
        /** @var \Phlix\Media\Transcoding\SegmentProcessRegistry $segmentRegistry */
        $segmentRegistry = $container->get(\Phlix\Media\Transcoding\SegmentProcessRegistry::class);
        $consumer->setSegmentProcessRegistry($segmentRegistry);

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
//
// WHERE `process.<key>.enabled` IS HONOURED — and why it is not honoured here.
// This loop runs in the MASTER, before Worker::runAll(), and Workerman cannot
// fork a new Worker group afterwards. It therefore CANNOT consult the
// `server_settings` overrides: doing so would (a) require a blocking DB read in
// the master whose connection would then be inherited across every fork, and
// (b) still not be re-evaluated by the admin "Restart server" button, which
// sends SIGUSR2 — a GRACEFUL RELOAD that re-forks children from this same,
// already-executed master. So the spawn decision stays on the config-file
// default (all five ship `enabled => true`), and the EFFECTIVE value is applied
// inside onWorkerStart below, where it IS re-read on every reload: a worker
// disabled by an admin override starts, logs, and idles without arming its
// poll timer. Net effect for the operator: toggling `process.<key>.enabled` in
// the admin UI takes effect on the next reload/restart, exactly as the schema's
// `"restart": true` flag promises. The one case this cannot cover is ENABLING a
// worker that `config/process.php` itself disables — no Worker was ever spawned
// to re-check — which is an operator editing the file on disk, not a UI action.
// That asymmetry, and the "one idle process per UI-disabled worker" cost, are
// stated in each key's admin-facing `helpText` in phlix-shared v0.26.0+ (locked
// by ServerSettingsSchemaTest), so the admin sees it at the switch rather than
// only here. Do not silently widen or narrow this gate without updating that
// text — the whole point is that the UI must not promise what this cannot do.
// -----------------------------------------------------------------------------

// Managed-worker key → its DI-resolvable class exposing `start(int $pollSeconds)`.
// The map lives in config/managed_workers.php (single source of truth) so a
// config/process.php entry can never be "enabled" without a spawner — the exact
// gap that previously left the media-asset + similarity queues undrained.
try {
    /** @var array<string, class-string> $managedWorkerClasses */
    $managedWorkerClasses = require __DIR__ . '/config/managed_workers.php';
    /** @var array<string, array{enabled?: bool, count?: int, poll_seconds?: int}> $processConfig */
    $processConfig = require __DIR__ . '/config/process.php';
    if (is_array($processConfig) && is_array($managedWorkerClasses)) {
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
                $procKey,
                $pollSeconds
            ): void {
                $applyCuratedCoroutineHooks();
                try {
                    // Effective-config overlay, mirroring the HTTP worker.
                    // {@see \Phlix\Config\EffectiveConfig}
                    $config = EffectiveConfig::bootstrapAndOverlay($config);

                    // Honour an admin `process.<key>.enabled = false` override.
                    // See the block comment above for why this gate lives here
                    // (in the fork, re-evaluated on every graceful reload)
                    // rather than in the master's spawn decision.
                    $procSettings = EffectiveConfig::file('process')[$procKey] ?? null;
                    if (is_array($procSettings) && ($procSettings['enabled'] ?? true) !== true) {
                        LoggerFactory::get(LogChannels::MEDIA)->info(
                            'Managed worker disabled by settings override; idling',
                            ['process' => $procKey, 'worker_class' => $workerClass],
                        );
                        return;
                    }

                    // Built inside the fork so the child owns its own DB/HTTP state.
                    $container = ContainerFactory::create($config);
                    /** @var \Phlix\Media\Library\LibraryScanWorker|\Phlix\Plugins\Catalog\PluginAutoUpdateWorker|\Phlix\Media\Markers\Detection\BackgroundDetectorWorker|\Phlix\Media\MediaAsset\MediaAssetWorker|\Phlix\Media\SimilarityWorker $managed */
                    $managed = $container->get($workerClass);

                    // F1 — plugin boot activation in the scan/job worker. The
                    // library-scan worker dispatches `MediaItemAdded` (→ enrich
                    // plugins musicbrainz/anilist) and runs metadata enrichment
                    // (→ SourceRegistry-registered omdb/anidb/myanimelist), so its
                    // fork must wire the enabled plugins into ITS own
                    // ListenerRegistry + SourceRegistry. Gated to `library-scan`:
                    // the other managed workers (media-asset, similarity,
                    // marker-detection, plugin-auto-update) dispatch none of the
                    // plugin events, so wiring plugins there would only hold dead
                    // instances. WIRE-ONLY + non-blocking; never blocks the fork.
                    if ($procKey === 'library-scan') {
                        try {
                            $container->get(\Phlix\Plugins\PluginLoader::class)->bootstrapEnabled();
                        } catch (\Throwable $pluginBootError) {
                            LoggerFactory::get(LogChannels::PLUGINS)->error(
                                'plugin boot activation failed (managed worker)',
                                ['process' => $procKey, 'error' => $pluginBootError->getMessage()],
                            );
                        }

                        // F2b — the automatic, THROTTLED, BACKGROUND trigger for
                        // plugin metadata SOURCES (omdb/anidb/myanimelist). F2a
                        // wired SourceRegistry dispatch behind an opt-in flag but
                        // left NO production caller. This subscriber lives in the
                        // SAME worker that dispatches MediaItemAdded (this
                        // library-scan managed worker): on the event it only
                        // ENQUEUES (no HTTP — the scan path stays fast); a re-arming
                        // Workerman timer drains one item per tick and gap-fills it
                        // from the DUE sources OVER the scan-resolved metadata. With
                        // no source plugins enabled the registry is empty, so
                        // nothing is ever enqueued and behaviour equals pre-F2b
                        // (RULE 7). Wired AFTER bootstrapEnabled() so the enabled
                        // sources are already registered in THIS worker's
                        // SourceRegistry. Non-blocking; never blocks the fork.
                        try {
                            $container->get(\Phlix\Media\Metadata\Enrichment\BackgroundEnrichmentSubscriber::class)
                                ->register($container->get(\Phlix\Common\Events\ListenerRegistry::class));
                        } catch (\Throwable $enrichBootError) {
                            LoggerFactory::get(LogChannels::MEDIA)->error(
                                'background enrichment subscriber wiring failed (managed worker)',
                                ['process' => $procKey, 'error' => $enrichBootError->getMessage()],
                            );
                        }
                    }

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
            ConnectionPool::armWorkerStopCleanup($managedWorker);
        }
    }
} catch (\Throwable $e) {
    // Best-effort; never block the HTTP server.
    trigger_error('Failed to set up managed worker processes: ' . $e->getMessage(), E_USER_WARNING);
}

// -----------------------------------------------------------------------------
// 4f. DLNA/UPnP SSDP advertiser worker.
//
// The SsdpAdvertiser is a Workerman Worker that sends SSDP NOTIFY messages
// to the multicast address 239.255.255.250:1900 every 30 seconds, allowing
// DLNA/UPnP devices on the network to discover this media server.
//
// This worker must be instantiated BEFORE Worker::runAll() because Workerman
// cannot fork a Worker post-runAll(). count=1 is sufficient since SSDP
// announcements are broadcast network messages that don't need redundancy.
// -----------------------------------------------------------------------------

// WHERE `dlna.enabled` IS HONOURED — and why it is split across two places.
// Exactly the same reasoning as the `process.<key>.enabled` gate above: this
// runs in the MASTER, before Worker::runAll(), so it cannot consult the
// `server_settings` overrides without (a) a blocking DB read whose connection
// every fork would then inherit, and (b) still not being re-evaluated by the
// admin "Restart server" button, which sends SIGUSR2 — a GRACEFUL RELOAD that
// re-forks children from this same, already-executed master. So the spawn
// decision below stays on the config-file default (which ships `true`), and the
// EFFECTIVE value is applied inside SsdpAdvertiser::onWorkerStart, where it IS
// re-read on every reload: an advertiser disabled by an admin override starts
// and idles without opening its multicast socket. The one case this cannot
// cover is ENABLING an advertiser that `config/dlna.php` itself disables — no
// Worker was ever spawned to re-check — which is an operator editing the file
// on disk, not a UI action. That asymmetry, and the "one idle process" cost,
// are stated in the key's admin-facing helpText, so the admin sees it at the
// switch rather than only here.
try {
    $serverConfig = is_array($config['server'] ?? null) ? $config['server'] : [];
    $dlnaPort = is_int($serverConfig['port'] ?? null) ? $serverConfig['port'] : 8096;
    /** @var array{enabled?: bool} $dlnaFileConfig */
    $dlnaFileConfig = require __DIR__ . '/config/dlna.php';
    if (!is_array($dlnaFileConfig) || ($dlnaFileConfig['enabled'] ?? true) !== false) {
        $dbConfigPath = is_string($config['db_config_path'] ?? null) ? $config['db_config_path'] : null;
        $dlnaSsdpWorker = new \Phlix\Dlna\SsdpAdvertiser(null, $dlnaPort, $dbConfigPath);
        $dlnaSsdpWorker->count = 1;
        $dlnaSsdpWorker->name = 'phlix-dlna-ssdp';
    }
} catch (\Throwable $e) {
    // The SSDP advertiser is best-effort; never block the HTTP server.
    trigger_error('Failed to set up DLNA SSDP advertiser worker: ' . $e->getMessage(), E_USER_WARNING);
}

// -----------------------------------------------------------------------------
// 4g. Worker-stop DB cleanup.
//
// Close every DB connection inside onWorkerStop — which still runs in a
// coroutine — so coroutine-hooked PDO sockets aren't torn down at RSHUTDOWN
// outside coroutine context. Leaving them open fatals every worker on SIGTERM
// ("Uncaught Swoole\Error: API must be called in the coroutine" /
// "Couldn't execute method Error::__toString").
// {@see ConnectionPool::armWorkerStopCleanup()}
// -----------------------------------------------------------------------------

foreach (
    [
        $httpWorker,
        $wsWorker ?? null,
        $hubHeartbeatWorker ?? null,
        $relayTunnelWorker ?? null,
        $dlnaSsdpWorker ?? null,
        $backgroundTimerWorker ?? null,
    ] as $stopCleanupWorker
) {
    if ($stopCleanupWorker instanceof Worker) {
        ConnectionPool::armWorkerStopCleanup($stopCleanupWorker);
    }
}

// -----------------------------------------------------------------------------
// 5. Run
// -----------------------------------------------------------------------------

Worker::runAll();
