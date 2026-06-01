<?php

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Phlix\Stats\StatsCollector;
use Psr\EventDispatcher\EventDispatcherInterface;

use function DI\autowire;
use function DI\get;

/**
 * Registers session-related services: device-session management and
 * the playback controller used by continue-watching.
 *
 * @internal Phlix-internal service provider.
 *
 * @package Phlix\Common\Container\Providers
 * @since 0.10.0
 */
final class SessionServicesProvider implements ServiceProviderInterface
{
    /**
     * Register session bindings.
     *
     * @param ContainerBuilder<\DI\Container> $builder
     * @param array<string, mixed>            $appConfig
     *
     * @return void
     *
     * @since 0.10.0
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $builder->addDefinitions([
            SessionManager::class => autowire()
                ->constructorParameter('logger', get('logger.session')),

            // `statsCollector` is wired so playback start/stop events land in
            // stats_playback_events — the source the admin dashboard's Top
            // Users / Top Media / activity widgets read from. Without it the
            // controller silently no-ops its recording (the ctor param is
            // optional) and those widgets stay empty.
            PlaybackController::class => autowire()
                ->constructorParameter('logger', get('logger.session'))
                ->constructorParameter('eventDispatcher', get(EventDispatcherInterface::class))
                ->constructorParameter('statsCollector', get(StatsCollector::class)),
        ]);
    }
}
