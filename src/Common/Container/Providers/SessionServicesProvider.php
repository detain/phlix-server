<?php

declare(strict_types=1);

namespace Phlex\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlex\Common\Container\ServiceProviderInterface;
use Phlex\Session\PlaybackController;
use Phlex\Session\SessionManager;

use function DI\autowire;
use function DI\get;

/**
 * Registers session-related services: device-session management and
 * the playback controller used by continue-watching.
 *
 * @internal Phlex-internal service provider.
 *
 * @package Phlex\Common\Container\Providers
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

            PlaybackController::class => autowire()
                ->constructorParameter('logger', get('logger.session')),
        ]);
    }
}
