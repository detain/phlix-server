<?php

declare(strict_types=1);

namespace Phlix\Common\Events;

use Crell\Tukio\DebugEventDispatcher;
use Crell\Tukio\Dispatcher;
use Crell\Tukio\OrderedListenerProvider;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the application's PSR-14 event dispatcher.
 *
 * The factory composes a {@see OrderedListenerProvider} (also reused as
 * the backing for {@see ListenerRegistry}) with a Tukio
 * {@see Dispatcher}, then optionally wraps the dispatcher in a
 * {@see DebugEventDispatcher} so every dispatched event is logged on the
 * {@see LogChannels::EVENTS} channel.
 *
 * The decorator is gated on the `PHLIX_DEBUG_EVENTS` environment
 * variable (`1`, `true`, `yes`, `on`). Off by default to keep production
 * logs noise-free.
 *
 * The class is invokable so it can be registered with PHP-DI as a
 * factory without using a closure (PHP-DI's compiled container cannot
 * serialise `use`-capturing closures — see A.1's caveat).
 *
 * @package Phlix\Common\Events
 * @since 0.10.0
 */
final class EventDispatcherFactory
{
    /**
     * Environment variable that gates the debug-dispatch decorator.
     */
    public const DEBUG_ENV_VAR = 'PHLIX_DEBUG_EVENTS';

    /**
     * Resolve a fully-wired {@see EventDispatcherInterface} from the
     * container.
     *
     * Expects the container to provide a singleton {@see ListenerRegistry}
     * (registered by
     * {@see \Phlix\Common\Container\Providers\EventServicesProvider}).
     * The debug logger, when enabled, is pulled from the
     * `logger.events` alias if present, otherwise from {@see LoggerFactory}
     * directly so that early bootstraps still get a working logger.
     *
     * @param ContainerInterface $container Fully-built PSR-11 container.
     *
     * @return EventDispatcherInterface PSR-14 dispatcher.
     *
     * @throws \Psr\Container\ContainerExceptionInterface If the listener
     *         registry binding is missing or malformed.
     *
     * @since 0.10.0
     */
    public function __invoke(ContainerInterface $container): EventDispatcherInterface
    {
        /** @var ListenerRegistry $registry */
        $registry = $container->get(ListenerRegistry::class);
        return self::create($registry, self::resolveDebugLogger($container));
    }

    /**
     * Build a dispatcher directly from a {@see ListenerRegistry}.
     *
     * Exposed for tests and for the (rare) caller that builds the
     * dispatcher outside the container.
     *
     * @param ListenerRegistry     $registry    Provides the Tukio listener
     *                                          provider that the dispatcher
     *                                          consults.
     * @param LoggerInterface|null $debugLogger Logger used by the debug
     *                                          decorator when
     *                                          `PHLIX_DEBUG_EVENTS` is
     *                                          truthy. When null and the
     *                                          flag is set, a no-op is
     *                                          used.
     *
     * @return EventDispatcherInterface PSR-14 dispatcher.
     *
     * @since 0.10.0
     */
    public static function create(
        ListenerRegistry $registry,
        ?LoggerInterface $debugLogger = null
    ): EventDispatcherInterface {
        $dispatcher = new Dispatcher($registry->provider());

        if (!self::debugEnabled() || $debugLogger === null) {
            return $dispatcher;
        }

        return new DebugEventDispatcher($dispatcher, $debugLogger);
    }

    /**
     * Build a fresh, empty {@see OrderedListenerProvider}.
     *
     * Exposed so the container provider can share the same provider
     * instance with {@see ListenerRegistry} and the dispatcher.
     *
     * @return OrderedListenerProvider Empty provider, ready to receive
     *         listener registrations.
     *
     * @since 0.10.0
     */
    public static function newProvider(): OrderedListenerProvider
    {
        return new OrderedListenerProvider();
    }

    /**
     * Whether the debug-dispatch decorator is enabled by environment.
     *
     * @return bool True when `PHLIX_DEBUG_EVENTS` is set to a truthy value.
     *
     * @since 0.10.0
     */
    public static function debugEnabled(): bool
    {
        $value = getenv(self::DEBUG_ENV_VAR);
        if ($value === false) {
            return false;
        }
        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Resolve the logger the debug decorator should write to.
     *
     * Prefers the `logger.events` container alias (registered by
     * {@see \Phlix\Common\Container\Providers\CoreServicesProvider}); falls
     * back to {@see LoggerFactory::get()} so the dispatcher still emits
     * useful output during early bootstrap.
     *
     * @param ContainerInterface $container Container that may or may not
     *                                      have the events logger alias.
     *
     * @return LoggerInterface|null Logger to use, or null when nothing
     *         could be resolved (in which case the debug decorator is
     *         skipped entirely).
     */
    private static function resolveDebugLogger(ContainerInterface $container): ?LoggerInterface
    {
        if (!self::debugEnabled()) {
            return null;
        }

        if ($container->has('logger.events')) {
            $candidate = $container->get('logger.events');
            if ($candidate instanceof LoggerInterface) {
                return $candidate;
            }
        }

        try {
            // LoggerFactory::get() returns StructuredLogger, which implements
            // LoggerInterface, so it is returned as-is.
            return LoggerFactory::get(LogChannels::EVENTS);
        } catch (\Throwable) {
            return null;
        }
    }
}
