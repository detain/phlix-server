<?php

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
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

            PlaybackController::class => autowire()
                ->constructorParameter('logger', get('logger.session'))
                ->constructorParameter('eventDispatcher', get(EventDispatcherInterface::class)),
        ]);
    }
}
