<?php

/**
 * Phlix media server component: Providers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Admin\BackupManager;
use Phlix\Admin\DashboardService;
use Phlix\Admin\Maintenance\MaintenanceJobRepository;
use Phlix\Admin\Maintenance\MaintenanceQueueWorker;
use Phlix\Admin\Maintenance\MaintenanceTaskRunner;
use Phlix\Admin\SettingsRepository;
use Phlix\Admin\WatchHistoryService;
use Phlix\Auth\AuthManager;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\DuplicateFinder;
use Phlix\Media\Library\PathDeduper;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\SeriesMerger;
use Phlix\Server\Http\Controllers\Admin\AdminMergeController;
use Phlix\Server\Http\Controllers\Admin\AdminMetadataSourceController;
use Phlix\Server\Http\Controllers\Admin\AdminRestartController;
use Phlix\Server\Http\Controllers\Admin\AdminTranscodingController;
use Phlix\Server\Http\Controllers\Admin\AdminSettingsController;
use Phlix\Server\Http\Controllers\Admin\AdminUpdatesController;
use Phlix\Server\Http\Controllers\Admin\AdminUserController;
use Phlix\Server\Http\Controllers\Admin\AdminWebhooksController;
use Phlix\Server\Http\Controllers\Admin\BackupController;
use Phlix\Server\Http\Controllers\Admin\DashboardController;
use Phlix\Server\Http\Controllers\Admin\FsBrowseController;
use Phlix\Server\Http\Controllers\Admin\LogController;
use Phlix\Server\Http\Controllers\Admin\MaintenanceController;
use Phlix\Server\Http\Controllers\Admin\WatchHistoryController;
use Phlix\Server\Http\Controllers\MostWatchedController;
use Phlix\Server\Http\Controllers\Stats\MetricsController;
use Phlix\Server\Http\Controllers\Stats\StatsController;
use Phlix\Server\Updates\AsyncVersionMarkerFetcher;
use Phlix\Server\Updates\CoreUpdateCheckService;
use Phlix\Server\Updates\CoreUpdateCheckWorker;
use Phlix\Server\Updates\VersionMarkerFetcherInterface;
use Phlix\Stats\StatsCollector;
use Phlix\Webhooks\WebhookHttpClient;
use Phlix\Webhooks\WebhookService;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * Wires admin-tier services into the container.
 *
 * {@see \Phlix\Server\Http\Routes\AdminRoutes::register()} eagerly
 * resolves every controller it registers (including the Stats,
 * Dashboard and Backup controllers) at route-bind time, so each of
 * those entries — and their transitive dependencies — must be
 * resolvable through the container. Without these bindings the entire
 * /api/v1/admin/* router fails to bootstrap with `no binding for …`.
 *
 * `StatsCollector` only depends on `Workerman\MySQL\Connection`, which
 * is already registered by {@see CoreServicesProvider}, so plain
 * autowiring is sufficient.
 *
 * {@see FsBrowseController} (Step 0.6) is also eagerly resolved by
 * {@see \Phlix\Server\Http\Routes\AdminRoutes::register()}; its `array
 * $allowedRoots` ctor argument cannot be autowired, so it is bound via a
 * `factory()` that loads the browse roots from `config/filesystem.php`.
 *
 * @internal Phlix-internal service provider.
 *
 * @package Phlix\Common\Container\Providers
 * @since Wave 2 (post-O.7)
 */
final class AdminServicesProvider implements ServiceProviderInterface
{
    /**
     * Register the admin-tier bindings.
     *
     * @param ContainerBuilder<\DI\Container> $builder
     * @param array<string, mixed>            $appConfig
     *
     * @return void
     *
     * @since Wave 2 (post-O.7)
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $builder->addDefinitions([
            StatsCollector::class    => autowire(),
            StatsController::class   => autowire(),
            MetricsController::class => autowire(),

            // Public "Most Watched" rail (S31). Autowires StatsCollector (above)
            // + ItemRepository + RatingGate (both MediaServicesProvider) — all
            // live in this same container, so plain autowiring resolves them.
            //
            // ⚠ S213: the controller's `$ratingGate` is REQUIRED and
            // non-nullable ON PURPOSE, which is what makes this bare
            // `autowire()` sufficient. PHP-DI SKIPS optional ctor params, so an
            // optional gate here would be null in production forever and the
            // rail would stay ungated — see the same trap already worked around
            // by explicit `constructorParameter()` bindings on `RatingGate`
            // ($users) and `MediaUserDataController` ($ratingGate) in
            // MediaServicesProvider. Pinned by
            // MostWatchedControllerContainerWiringTest.
            MostWatchedController::class => autowire(),

            DashboardService::class    => autowire(),
            DashboardController::class => autowire(),

            WatchHistoryService::class    => autowire(),
            WatchHistoryController::class => autowire(),

            // `logger` + `auditLogger` are named explicitly: PHP-DI skips
            // optional ctor params that carry a default, so both stayed null.
            // `auditLogger` is the one that mattered — every
            // `$this->auditLogger?->logDataExport(...)` call site (backup create,
            // restore, and S3 upload) was skipped, so those privileged
            // data-export events were NEVER written to the audit log. `logger`
            // is bound for the one call site that reads the property directly
            // instead of via getLogger() (the "skipped config file outside the
            // config directory during restore" warning, a path-traversal
            // signal that was being dropped); the APPLICATION channel bound
            // here is the same one getLogger() falls back to, so no channel
            // changes.
            BackupManager::class    => autowire()
                ->constructorParameter('logger', get(StructuredLogger::class))
                ->constructorParameter('auditLogger', get(AuditLogger::class)),
            BackupController::class => autowire(),

            // ── One-off maintenance tasks (S77 / updates.md #49) ─────────────
            //
            // The queue store and the duplicate-path service both take a single
            // REQUIRED `Connection`, which is what makes a bare autowire() safe
            // for them — PHP-DI skips OPTIONAL ctor params, so anything with a
            // default has to be named explicitly below.
            MaintenanceJobRepository::class => autowire(),
            PathDeduper::class             => autowire(),

            // The runner is a factory, not an autowire, for exactly that reason:
            // its `$transcodeManager` is optional (that service needs ffmpeg
            // config and a writable transcode root, so it is the one dependency
            // here that can genuinely fail to build). An autowire() would leave
            // it null in production and the `reap-transcode-jobs` task would
            // report "TranscodeManager is unavailable" forever, while every
            // hand-wired unit test passed. Resolved explicitly, and a real
            // build failure degrades only that ONE task.
            MaintenanceTaskRunner::class => factory(
                static function (ContainerInterface $c): MaintenanceTaskRunner {
                    $transcodeManager = null;
                    try {
                        /** @var TranscodeManager $transcodeManager */
                        $transcodeManager = $c->get(TranscodeManager::class);
                    } catch (\Throwable) {
                        $transcodeManager = null;
                    }

                    /** @var Connection $db */
                    $db = $c->get(Connection::class);
                    /** @var ScanJobRepository $scanJobs */
                    $scanJobs = $c->get(ScanJobRepository::class);
                    /** @var PathDeduper $pathDeduper */
                    $pathDeduper = $c->get(PathDeduper::class);

                    return new MaintenanceTaskRunner($db, $scanJobs, $pathDeduper, $transcodeManager);
                }
            ),

            // `adminGuard` is REQUIRED since S338, so PHP-DI's autowire() supplies
            // it by type and no explicit constructorParameter() binding is needed.
            // The S282/S323 trap this comment used to warn about — an OPTIONAL
            // guard silently left null because autowire() skips optional params —
            // is structurally impossible now: the controller cannot be built
            // without the middleware.
            MaintenanceController::class => autowire(),

            // Drained by a timer inside the `phlix-background-timers` fork; see
            // Application::startMaintenanceQueueTimer().
            MaintenanceQueueWorker::class => autowire(),

            // Server-wide settings store + admin API (Step 0.5).
            SettingsRepository::class      => autowire(),
            AdminSettingsController::class => autowire(),

            // Admin user management (Step 1.2a). `authManager` is named
            // explicitly (SV-2.7): PHP-DI skips optional ctor params with
            // class-typed defaults during autowiring, so without this the
            // controller would always see a null AuthManager and its
            // approve/disable/reject/delete actions could never invalidate
            // AuthManager's in-worker user-status cache — a status change
            // would then only ever take effect after the cache TTL elapses,
            // even for a request landing on the SAME worker that made it.
            AdminUserController::class => autowire()
                ->constructorParameter('authManager', get(AuthManager::class)),

            // Filesystem browse endpoint (Step 0.6) — roots come from config/filesystem.php.
            FsBrowseController::class => factory(static function (): FsBrowseController {
                /** @var array<string, mixed> $cfg */
                $cfg   = include __DIR__ . '/../../../../config/filesystem.php';
                $roots = is_array($cfg['browse_roots'] ?? null) ? $cfg['browse_roots'] : [];
                $list  = [];
                foreach ($roots as $r) {
                    if (is_string($r)) {
                        $list[] = $r;
                    }
                }

                return new FsBrowseController($list);
            }),

            // Admin log viewer (Step 1.7) — tails the rotating log files in
            // the project's .logs/ directory (same dir config/logger.php writes
            // to). The dir is resolved + jailed inside LogController.
            LogController::class => factory(static function (): LogController {
                return new LogController(__DIR__ . '/../../../../.logs');
            }),

            // Duplicate preview + merge controller (Step 1.6, Feature 1).
            // SeriesMerger only needs the transaction API
            // (begin/commit/rollBackTrans), which is declared on the BASE
            // Workerman Connection and honoured by BOTH connection classes Phlix
            // wires: the single-socket PhlixMySQLConnection (reentrant txn
            // coroutine mutex, #333) AND the PooledMySQLConnection handed out
            // when the coroutine pool is enabled (DB_POOL_ENABLED=1), which
            // leases one connection per coroutine so a transaction stays affine.
            // We therefore build the merger for ANY real base Connection so the
            // merge feature works in both pool-off and pool-on modes. The null
            // branch (→ 503 on apply, preview still works) remains only as a
            // defensive degrade for a genuinely-misconfigured/non-DB binding.
            // Both DuplicateFinder and (when available) SeriesMerger are built
            // once here at construction — no growing static/global state.
            AdminMergeController::class => factory(static function (ContainerInterface $c): AdminMergeController {
                /** @var ItemRepository $items */
                $items = $c->get(ItemRepository::class);

                $finder = new DuplicateFinder($items);

                $connection = $c->get(Connection::class);
                $merger = $connection instanceof Connection
                    ? new SeriesMerger($items, $connection)
                    : null;

                return new AdminMergeController($items, $finder, $merger);
            }),

            // Metadata-source name list for the admin priority editor
            // (Step 3.6, Feature 3). Its only dependency is the autowired,
            // container-scoped SourceRegistry (bound in MediaServicesProvider),
            // so a plain autowire is sufficient.
            AdminMetadataSourceController::class => autowire(),

            // HDR tone-mapping settings (P6-S3) — needs full app.config injected.
            AdminTranscodingController::class => factory(
                static function (ContainerInterface $c): AdminTranscodingController {
                    /** @var array<string, mixed> $appConfig */
                    $appConfig = $c->get('app.config');
                    /** @var FfmpegRunner $ffmpegRunner */
                    $ffmpegRunner = $c->get(FfmpegRunner::class);
                    return new AdminTranscodingController($ffmpegRunner, $appConfig);
                }
            ),

            // Webhook event system (P9-S1) — async delivery with retry queue.
            WebhookHttpClient::class => autowire(),

            // `logger` is named explicitly for the same PHP-DI reason as
            // BackupManager above (optional defaulted params are skipped). This
            // one is behaviour-NEUTRAL: the ctor already self-heals with
            // `?? LoggerFactory::get(LogChannels::APPLICATION)`, and the
            // StructuredLogger binding resolves to that very channel. It is bound
            // so the service shares this container's initialised logger instance
            // (the factory guarantees LoggerFactory::init() has run) rather than
            // building its own, and so the wiring no longer *looks* like the
            // silently-dropped dependencies fixed alongside it.
            WebhookService::class => autowire()
                ->constructorParameter('logger', get(StructuredLogger::class)),
            AdminWebhooksController::class => autowire(),

            // Phase 8: graceful server restart via SIGUSR1.
            AdminRestartController::class => factory(
                static function (ContainerInterface $c): AdminRestartController {
                    /** @var array<string, mixed> $appConfig */
                    $appConfig = $c->get('app.config');
                    $worker = $appConfig['worker'] ?? null;

                    if (is_array($worker) && is_string($worker['pid_file'] ?? null)) {
                        $pidFile = $worker['pid_file'];
                    } else {
                        $pidFile = '/var/run/phlix/pid';
                    }

                    return new AdminRestartController($pidFile);
                }
            ),

            // ----------------------------------------------------------------
            // Core (server application) update check — S74 / updates.md #48.
            //
            // The fetcher is bound to the INTERFACE so a test can swap in a
            // double without touching the service's own binding; production
            // always gets the workerman/http-client callback-mode
            // implementation. `config/updates.php` is read through
            // SettingsRepository::getDefault(), which is a cached config-file
            // read with NO database round-trip — important because
            // AdminRoutes::register() resolves every controller it binds at
            // route-bind time, i.e. on every worker boot.
            // ----------------------------------------------------------------
            VersionMarkerFetcherInterface::class => factory(
                static function (ContainerInterface $c): VersionMarkerFetcherInterface {
                    /** @var SettingsRepository $settings */
                    $settings = $c->get(SettingsRepository::class);
                    /** @var mixed $timeout */
                    $timeout = $settings->getDefault('updates.timeout_seconds');

                    return new AsyncVersionMarkerFetcher(
                        is_int($timeout) && $timeout > 0 ? $timeout : 10,
                    );
                }
            ),

            CoreUpdateCheckService::class => factory(
                static function (ContainerInterface $c): CoreUpdateCheckService {
                    /** @var SettingsRepository $settings */
                    $settings = $c->get(SettingsRepository::class);
                    /** @var VersionMarkerFetcherInterface $fetcher */
                    $fetcher = $c->get(VersionMarkerFetcherInterface::class);
                    /** @var StructuredLogger $logger */
                    $logger = $c->get(StructuredLogger::class);

                    /** @var mixed $markerUrl */
                    $markerUrl = $settings->getDefault('updates.marker_url');
                    /** @var mixed $updateCommand */
                    $updateCommand = $settings->getDefault('updates.update_command');

                    return new CoreUpdateCheckService(
                        $settings,
                        $fetcher,
                        $logger,
                        is_string($markerUrl) && $markerUrl !== '' ? $markerUrl : '',
                        is_string($updateCommand) ? $updateCommand : '',
                    );
                }
            ),

            // `logger` is named explicitly for the PHP-DI reason documented on
            // BackupManager above: autowire() skips parameters that carry a
            // default. This one has no default, but the binding is stated so the
            // worker shares this container's initialised logger instance.
            CoreUpdateCheckWorker::class => autowire()
                ->constructorParameter('logger', get(StructuredLogger::class)),

            AdminUpdatesController::class => autowire(),
        ]);
    }
}
