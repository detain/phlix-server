<?php

declare(strict_types=1);

namespace Phlix\Common\Events;

use Crell\Tukio\OrderedListenerProvider;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Thin facade over the Tukio {@see OrderedListenerProvider} that plugins
 * and application bootstrap use to register PSR-14 listeners.
 *
 * The registry intentionally does NOT expose Tukio types in its public
 * API — callers depend only on PHP-native `callable`. This insulates
 * the rest of the codebase (and especially future plugins) from changes
 * in the underlying dispatcher.
 *
 * Listeners are subscribed against a concrete event class FQCN. Tukio
 * internally also matches subclasses / interfaces, but we standardise on
 * the FQCN form in the plugin manifest (Phase A.4) so the rules are
 * obvious to plugin authors.
 *
 * ## Removal semantics
 *
 * Tukio's underlying ordered collection does not currently expose a
 * removal API. To support `unsubscribe()` cleanly without forking
 * Tukio, every subscribed callable is wrapped in an inner shim that
 * consults an `unsubscribed` set before forwarding. The wrapper still
 * receives every dispatch, but the cost is negligible (one array
 * lookup) and the API stays simple. Plugin disable + re-enable cycles
 * behave correctly.
 *
 * @package Phlix\Common\Events
 * @since 0.10.0
 */
final class ListenerRegistry
{
    /**
     * The Tukio provider that backs the dispatcher.
     */
    private OrderedListenerProvider $provider;

    /**
     * Map of `"<eventClass>::<callableId>"` to the Tukio listener ID, so
     * duplicate subscriptions can be detected and `unsubscribe()` can
     * tag the listener as inactive.
     *
     * @var array<string, string>
     */
    private array $subscriptions = [];

    /**
     * Set of listener IDs that have been marked unsubscribed. Used by
     * the wrapping shim to short-circuit dispatch.
     *
     * @var array<string, true>
     */
    private array $unsubscribed = [];

    /**
     * Optional logger for non-fatal anomalies (idempotent unsubscribe
     * misses, etc.). Lazy-initialised so unit tests don't need to wire
     * a logger up.
     */
    private ?StructuredLogger $logger;

    /**
     * @param OrderedListenerProvider|null $provider Optional pre-built
     *        Tukio provider; defaults to a fresh empty one. Sharing a
     *        single provider between {@see ListenerRegistry} and the
     *        dispatcher is mandatory — that's how dispatched events
     *        reach subscribed listeners.
     * @param StructuredLogger|null $logger Optional events-channel
     *        logger; if null, looked up lazily via {@see LoggerFactory}
     *        on the first warning. Tests typically inject a stub.
     */
    public function __construct(
        ?OrderedListenerProvider $provider = null,
        ?StructuredLogger $logger = null
    ) {
        $this->provider = $provider ?? new OrderedListenerProvider();
        $this->logger = $logger;
    }

    /**
     * Subscribe a callable to receive events of the given class.
     *
     * @param string        $eventClass FQCN of the event the listener
     *                                  cares about.
     * @param callable      $listener   Listener — any PHP callable with
     *                                  signature `(EventClass): void`.
     * @param int|null      $priority   Higher priority runs first; null
     *                                  defers to Tukio's default
     *                                  ordering (insertion order).
     *
     * @return string Opaque listener ID returned by Tukio. Useful for
     *         debugging / logging; not required for `unsubscribe()`,
     *         which keys on the `(eventClass, callable)` pair.
     *
     * @throws \InvalidArgumentException When the same callable is
     *         subscribed to the same event class twice — explicit so
     *         double-registration bugs surface immediately.
     *
     * @since 0.10.0
     */
    public function subscribe(string $eventClass, callable $listener, ?int $priority = null): string
    {
        $key = $this->subscriptionKey($eventClass, $listener);

        if (isset($this->subscriptions[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Listener for event %s is already registered.',
                $eventClass
            ));
        }

        $registry = $this;
        /** @var string|null $listenerId */
        $listenerId = null;
        $shim = static function (object $event) use ($registry, &$listenerId, $listener): void {
            // The reference $listenerId is set immediately below this
            // closure's creation, before the dispatcher ever invokes
            // the shim — by the time we get here it is always a string.
            if ($listenerId !== null && $registry->isUnsubscribed($listenerId)) {
                return;
            }
            $listener($event);
        };

        $listenerId = $this->provider->listener(
            listener: $shim,
            priority: $priority,
            type: $eventClass,
        );

        $this->subscriptions[$key] = $listenerId;
        return $listenerId;
    }

    /**
     * Unsubscribe a previously-registered listener.
     *
     * Idempotent: calling `unsubscribe()` on a `(eventClass, callable)`
     * pair that was never subscribed (or was already unsubscribed)
     * emits a warning via the events log channel but does not throw —
     * plugin disable cycles are more important than strict bookkeeping.
     *
     * @param string   $eventClass FQCN of the event the listener was
     *                             subscribed to.
     * @param callable $listener   The exact callable that was passed
     *                             to {@see subscribe()}.
     *
     * @return bool True when an active subscription was found and
     *         marked inactive; false when nothing matched.
     *
     * @since 0.10.0
     */
    public function unsubscribe(string $eventClass, callable $listener): bool
    {
        $key = $this->subscriptionKey($eventClass, $listener);

        if (!isset($this->subscriptions[$key])) {
            $this->logger()->warning('unsubscribe: no matching subscription', [
                'event_class' => $eventClass,
            ]);
            return false;
        }

        $listenerId = $this->subscriptions[$key];
        if (isset($this->unsubscribed[$listenerId])) {
            $this->logger()->warning('unsubscribe: listener already inactive', [
                'event_class' => $eventClass,
                'listener_id' => $listenerId,
            ]);
            return false;
        }

        $this->unsubscribed[$listenerId] = true;
        unset($this->subscriptions[$key]);
        return true;
    }

    /**
     * Return the PSR-14 listener provider backing this registry.
     *
     * Exposed so {@see EventDispatcherFactory} can hand the same
     * provider to the dispatcher. Plugins should NOT consume this
     * directly — use {@see subscribe()} / {@see unsubscribe()}.
     *
     * @return ListenerProviderInterface PSR-14 provider.
     *
     * @internal Consumed by EventDispatcherFactory only.
     */
    public function provider(): ListenerProviderInterface
    {
        return $this->provider;
    }

    /**
     * Whether the listener with the given ID has been unsubscribed.
     *
     * @param string $listenerId Tukio listener ID returned by
     *                           {@see subscribe()}.
     *
     * @return bool True when the listener has been deactivated and
     *              the dispatch shim should short-circuit.
     *
     * @internal Consumed by the wrapping shim only.
     */
    public function isUnsubscribed(string $listenerId): bool
    {
        return isset($this->unsubscribed[$listenerId]);
    }

    /**
     * Compute the deterministic key under which a `(event, callable)`
     * pair is tracked in {@see $subscriptions}.
     *
     * The key includes the callable's hash so distinct closures with
     * the same `__invoke` body don't collide.
     *
     * @param string   $eventClass FQCN of the subscribed event.
     * @param callable $listener   The callable being keyed.
     *
     * @return string Opaque registry key.
     */
    private function subscriptionKey(string $eventClass, callable $listener): string
    {
        return $eventClass . '::' . self::callableHash($listener);
    }

    /**
     * Stable hash of a callable for de-duplication.
     *
     * @param callable $listener Callable to fingerprint.
     *
     * @return string Hash that is stable for a given callable within
     *                a single process.
     */
    private static function callableHash(callable $listener): string
    {
        if (is_string($listener)) {
            return 'fn:' . $listener;
        }
        if ($listener instanceof \Closure) {
            return 'cl:' . spl_object_hash($listener);
        }
        if (is_array($listener)) {
            $target = $listener[0];
            $method = (string)$listener[1];
            if (is_object($target)) {
                return 'om:' . spl_object_hash($target) . '::' . $method;
            }
            return 'sm:' . (string)$target . '::' . $method;
        }
        if (is_object($listener)) {
            return 'in:' . spl_object_hash($listener);
        }
        // Should be unreachable: callable that's none of the above.
        return 'un:' . sha1(serialize($listener));
    }

    /**
     * Lazy-load the events-channel logger so unit tests don't need to
     * wire one up.
     *
     * @return StructuredLogger Logger keyed to {@see LogChannels::EVENTS}.
     */
    private function logger(): StructuredLogger
    {
        if ($this->logger === null) {
            $this->logger = LoggerFactory::get(LogChannels::EVENTS);
        }
        return $this->logger;
    }
}
