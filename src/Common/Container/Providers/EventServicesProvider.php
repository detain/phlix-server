<?php

declare(strict_types=1);

namespace Phlex\Common\Container\Providers;

use Crell\Tukio\OrderedListenerProvider;
use DI\ContainerBuilder;
use Phlex\Common\Container\ServiceProviderInterface;
use Phlex\Common\Events\EventDispatcherFactory;
use Phlex\Common\Events\ListenerRegistry;
use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\StructuredLogger;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

use function DI\factory;
use function DI\get;

/**
 * Wires the PSR-14 event-dispatch stack into the container.
 *
 * Registers:
 *
 * - {@see OrderedListenerProvider} as a singleton — the shared Tukio
 *   provider that both the {@see ListenerRegistry} and the dispatcher
 *   consult.
 * - {@see ListenerProviderInterface} aliased to the same provider.
 * - {@see ListenerRegistry} as a singleton.
 * - {@see EventDispatcherInterface} resolved through
 *   {@see EventDispatcherFactory}. The factory is an invokable class
 *   rather than a closure so the binding is safe to use under
 *   `PHLEX_CONTAINER_COMPILE=1` once the compiled container is enabled
 *   in a future phase.
 * - {@see EventDispatcherFactory} as a singleton (autowired, no
 *   constructor parameters).
 * - An events-channel logger alias `logger.events` (Tukio's debug
 *   decorator writes here when `PHLEX_DEBUG_EVENTS=1`).
 *
 * Once registered, application services can simply type-hint
 * `Psr\EventDispatcher\EventDispatcherInterface` in their constructors;
 * PHP-DI will autowire the singleton instance.
 *
 * @internal Phlex-internal service provider.
 *
 * @package Phlex\Common\Container\Providers
 * @since 0.10.0
 */
final class EventServicesProvider implements ServiceProviderInterface
{
    /**
     * Register the event-dispatcher bindings on the given builder.
     *
     * @param ContainerBuilder<\DI\Container> $builder
     * @param array<string, mixed>            $appConfig Application config;
     *                                                   only `logger_config_path`
     *                                                   is consulted (used to
     *                                                   initialise LoggerFactory
     *                                                   when the events logger
     *                                                   is requested).
     *
     * @return void
     *
     * @since 0.10.0
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $loggerConfigPath = $appConfig['logger_config_path'] ?? null;

        $definitions = [
            // Events log channel alias, parallel to the auth/http/etc.
            // aliases registered by CoreServicesProvider.
            'logger.events' => factory(static function () use ($loggerConfigPath): StructuredLogger {
                if (is_string($loggerConfigPath) && $loggerConfigPath !== '') {
                    LoggerFactory::init($loggerConfigPath);
                }
                return LoggerFactory::get(LogChannels::EVENTS);
            }),

            // Singleton Tukio provider — both the registry and the
            // dispatcher resolve to the same instance.
            OrderedListenerProvider::class => factory(
                static fn (): OrderedListenerProvider => EventDispatcherFactory::newProvider()
            ),

            ListenerProviderInterface::class => get(OrderedListenerProvider::class),

            ListenerRegistry::class => factory(
                static function (ContainerInterface $c): ListenerRegistry {
                    /** @var OrderedListenerProvider $provider */
                    $provider = $c->get(OrderedListenerProvider::class);
                    $logger = null;
                    if ($c->has('logger.events')) {
                        $candidate = $c->get('logger.events');
                        if ($candidate instanceof StructuredLogger) {
                            $logger = $candidate;
                        }
                    }
                    return new ListenerRegistry($provider, $logger);
                }
            ),

            EventDispatcherFactory::class => factory(
                static fn (): EventDispatcherFactory => new EventDispatcherFactory()
            ),

            EventDispatcherInterface::class => factory(
                static function (ContainerInterface $c): EventDispatcherInterface {
                    /** @var EventDispatcherFactory $factory */
                    $factory = $c->get(EventDispatcherFactory::class);
                    return $factory($c);
                }
            ),
        ];

        $builder->addDefinitions($definitions);
    }
}
