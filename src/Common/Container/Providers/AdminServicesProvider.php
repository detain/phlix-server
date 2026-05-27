<?php

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Admin\BackupManager;
use Phlix\Admin\DashboardService;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Server\Http\Controllers\Admin\AdminSettingsController;
use Phlix\Server\Http\Controllers\Admin\BackupController;
use Phlix\Server\Http\Controllers\Admin\DashboardController;
use Phlix\Server\Http\Controllers\Admin\FsBrowseController;
use Phlix\Server\Http\Controllers\Stats\StatsController;
use Phlix\Stats\StatsCollector;

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
        ]);
    }
}
