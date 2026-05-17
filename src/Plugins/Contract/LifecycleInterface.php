<?php

declare(strict_types=1);

namespace Phlex\Plugins\Contract;

use Psr\Container\ContainerInterface;

/**
 * Contract every Phlex plugin entry class implements.
 *
 * The entry class is whatever FQCN appears in `plugin.json#/entry`. The
 * loader instantiates it through the host PSR-11 container — so the
 * entry class may type-hint any binding the container can resolve in
 * its constructor and PHP-DI will autowire them.
 *
 * ## Lifecycle
 *
 * - **enable phase** — {@see self::onEnable()} runs after the manifest
 *   is loaded, the plugin's `vendor/autoload.php` is registered, and
 *   the entry class is instantiated. The host container is passed in
 *   so the plugin can resolve services lazily.
 * - **enable phase** — {@see self::subscribedEvents()} runs immediately
 *   after {@see onEnable()} returns, to obtain the list of
 *   `eventClassFqcn => callable` pairs the loader should attach to
 *   the PSR-14 dispatcher.
 * - **disable phase** — {@see self::onDisable()} runs before the
 *   loader unsubscribes the listeners returned earlier from
 *   {@see subscribedEvents()}.
 *
 * Plugins should keep {@see onEnable()} cheap: any heavy initialisation
 * (HTTP client warmup, background workers) belongs in the listeners
 * themselves, which only run when a relevant event fires.
 *
 * ## Subscribed events
 *
 * {@see self::subscribedEvents()} returns a map keyed by **event FQCN**
 * (e.g. `\Phlex\Common\Events\Playback\PlaybackStarted::class`) to
 * either:
 *
 * - A PHP `callable` (closure, `[$plugin, 'methodName']`, invokable
 *   object, …) that accepts the event instance and returns void.
 * - A method name (string), which the loader binds as
 *   `[$pluginInstance, $methodName]` for convenience.
 *
 * The loader translates manifest aliases (`phlex.playback.started`) to
 * FQCNs via {@see \Phlex\Plugins\EventNameMap} before calling this
 * method, so plugin authors deal exclusively with FQCNs at runtime.
 *
 * ## Provenance
 *
 * **Temporary home — moves to `Phlex\Shared\Plugin\LifecycleInterface`
 * in Step B.1.** Plugin authors who target master may pin to this
 * interface; once B.1 lands,
 * `Phlex\Plugins\Contract\LifecycleInterface` becomes a deprecated
 * alias for one minor release.
 *
 * @package Phlex\Plugins\Contract
 * @since 0.10.0
 */
interface LifecycleInterface
{
    /**
     * Called by the loader once when the plugin is enabled. The plugin
     * may resolve services from the container, open clients, prime
     * caches — but must not block. Throw to abort enabling; the loader
     * surfaces the throwable as a
     * {@see \Phlex\Plugins\Exception\PluginEnableException}.
     *
     * @param ContainerInterface $container Host PSR-11 container.
     *
     * @return void
     *
     * @since 0.10.0
     */
    public function onEnable(ContainerInterface $container): void;

    /**
     * Called by the loader once when the plugin is disabled, after the
     * loader has unsubscribed every listener returned from
     * {@see subscribedEvents()}. Use it to flush queued state, close
     * clients, etc. Exceptions are caught and logged but the disable
     * still completes.
     *
     * @return void
     *
     * @since 0.10.0
     */
    public function onDisable(): void;

    /**
     * Return the PSR-14 listener subscriptions this plugin wants.
     *
     * Keys are event class FQCNs; values are either a string method
     * name on this instance, or a PHP callable. The loader wraps each
     * entry into a callable and subscribes it to the dispatcher.
     *
     * @return array<class-string, string|callable> Subscriptions keyed
     *         by event FQCN.
     *
     * @since 0.10.0
     */
    public function subscribedEvents(): array;
}
