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
use Phlix\AirPlay\AirPlayManager;
use Phlix\Casting\CastingSessionStore;
use Phlix\Chromecast\CastManager;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Dlna\PlayToManager;
use Phlix\Roku\RokuManager;
use Workerman\MySQL\Connection;

use function DI\autowire;
use function DI\get;

/**
 * Wires the DB-backed casting session store into the four device managers
 * (Device-M1).
 *
 * ## Why explicit bindings
 *
 * Every manager takes the store as a TRAILING-OPTIONAL constructor parameter
 * so the ~22 existing `new XManager(...)` test call sites stay legal and a
 * container-less manager behaves exactly like it did before (worker-local map
 * only). PHP-DI skips optional ctor params during autowiring — the documented
 * silent-null hazard this repo hits repeatedly (see the Auth provider's limiter
 * note) — so a store-less autowire would ship the very bug this lane exists to
 * fix. Each manager is therefore bound explicitly with
 * `->constructorParameter('store', ...)`, and the store itself is built from
 * the SHARED pooled `Connection::class` that `CoreServicesProvider` binds —
 * the same connection the auth stores and DB rate limiters ride.
 *
 * `Application::load{DlnaRenderer,Roku,Chromecast,AirPlay}Routes()` resolve the
 * managers via `$this->container->get(...)`, so these definitions are what the
 * live HTTP path actually builds.
 *
 * @internal Phlix-internal service provider.
 *
 * @package Phlix\Common\Container\Providers
 * @since 1.5.0
 */
final class CastingServicesProvider implements ServiceProviderInterface
{
    /**
     * Register the casting store and inject it into the managers.
     *
     * @param array<string, mixed> $appConfig Assembled boot config (unused).
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $builder->addDefinitions([
            CastingSessionStore::class => autowire()
                ->constructorParameter('db', get(Connection::class)),

            PlayToManager::class => autowire()
                ->constructorParameter('store', get(CastingSessionStore::class)),

            RokuManager::class => autowire()
                ->constructorParameter('store', get(CastingSessionStore::class)),

            CastManager::class => autowire()
                ->constructorParameter('store', get(CastingSessionStore::class)),

            AirPlayManager::class => autowire()
                ->constructorParameter('store', get(CastingSessionStore::class)),
        ]);
    }
}
