<?php

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Admin\BackupManager;
use Phlix\Admin\DashboardService;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Server\Http\Controllers\Admin\BackupController;
use Phlix\Server\Http\Controllers\Admin\DashboardController;
use Phlix\Server\Http\Controllers\Stats\StatsController;
use Phlix\Stats\StatsCollector;

use function DI\autowire;

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
        ]);
    }
}
