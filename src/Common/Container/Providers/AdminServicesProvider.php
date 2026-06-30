<?php

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Admin\BackupManager;
use Phlix\Admin\DashboardService;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Media\Library\DuplicateFinder;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\SeriesMerger;
use Phlix\Server\Http\Controllers\Admin\AdminMergeController;
use Phlix\Server\Http\Controllers\Admin\AdminMetadataSourceController;
use Phlix\Server\Http\Controllers\Admin\AdminSettingsController;
use Phlix\Server\Http\Controllers\Admin\BackupController;
use Phlix\Server\Http\Controllers\Admin\DashboardController;
use Phlix\Server\Http\Controllers\Admin\FsBrowseController;
use Phlix\Server\Http\Controllers\Admin\LogController;
use Phlix\Server\Http\Controllers\Stats\StatsController;
use Phlix\Stats\StatsCollector;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;

use function DI\autowire;
use function DI\factory;

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

            DashboardService::class    => autowire(),
            DashboardController::class => autowire(),

            BackupManager::class    => autowire(),
            BackupController::class => autowire(),

            // Server-wide settings store + admin API (Step 0.5).
            SettingsRepository::class      => autowire(),
            AdminSettingsController::class => autowire(),

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
        ]);
    }
}
