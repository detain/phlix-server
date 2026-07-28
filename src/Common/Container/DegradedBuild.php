<?php

/**
 * Phlix media server component: Container.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\Container;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Psr\Container\ContainerInterface;

/**
 * Reports a service provider that built something in a DEGRADED state.
 *
 * ## Why this exists
 *
 * Service-provider factories legitimately swallow failures: a settings store
 * that is down must not stop a scan, and an optional collaborator that will not
 * resolve must not stop the whole container. The problem was never the
 * swallowing — it was that the swallow was **silent**, so the degraded build was
 * indistinguishable from a healthy one.
 *
 * Two properties make that especially costly here:
 *
 *   1. PHP-DI caches a `factory()` result in `Container::$resolvedEntries`, and
 *      one container is built **per worker**. Whatever a factory decides during
 *      a momentary outage is therefore frozen for that worker's whole lifetime —
 *      it is not retried on the next request.
 *   2. Several fallbacks are indistinguishable from a legitimate value: an empty
 *      API key looks like "no key configured", a null parental gate looks like
 *      "gating is off by design", and a default suffix list looks like "the
 *      admin never set one".
 *
 * The TMDB API key was the case that made this concrete: a transient database
 * failure at fork time baked an EMPTY key into a worker, every lookup returned
 * `[]` (indistinguishable from "no match"), and nothing was logged. See
 * `MediaServicesProvider`'s `TmdbProvider` factory.
 *
 * ## What a good message says
 *
 * Name the setting or collaborator, and say what the system will do INSTEAD —
 * "using the in-code default", "parental gating is NOT enforced on this path".
 * A reader should be able to judge the blast radius without reading the source.
 *
 * ## Noise
 *
 * These fire from `factory()` bodies, which run at most once per entry per
 * worker, and only on the failure path. A healthy worker logs nothing here.
 *
 * @package Phlix\Common\Container
 * @since 1.4.0
 */
final class DegradedBuild
{
    /**
     * Log that a provider fell back to a degraded value, naming the cause.
     *
     * @param ContainerInterface   $c       Container being built (used to prefer
     *                                      the wired channel logger).
     * @param string               $channel Log channel, e.g. {@see LogChannels::MEDIA}.
     * @param string               $message What degraded, and what happens instead.
     * @param \Throwable           $e       The swallowed failure.
     * @param array<string, mixed> $context Extra structured context.
     *
     * @return void
     */
    public static function warn(
        ContainerInterface $c,
        string $channel,
        string $message,
        \Throwable $e,
        array $context = []
    ): void {
        self::logger($c, $channel)->warning($message, array_merge(
            [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ],
            $context
        ));
    }

    /**
     * Log a degraded build, UNLESS the cause is simply that the entry was never
     * defined in this container.
     *
     * This is the form nearly every call site wants, because the two failures
     * are not the same thing:
     *
     *   - **Not defined at all** — PHP-DI raises `NotFoundExceptionInterface`.
     *     Plenty of containers legitimately register no settings store, no
     *     rating gate, no music library; reporting those would fire on healthy
     *     containers, and a rule that cries wolf gets switched off.
     *   - **Defined but unbuildable** — anything else, including a database that
     *     will not answer. That is a real degradation and is reported.
     *
     * `$c->has()` cannot make this distinction: with autowiring enabled it
     * returns TRUE for any instantiable class, so a container with no settings
     * store at all still answers `has() === true`. The exception type is the
     * only reliable signal.
     *
     * @param ContainerInterface   $c       Container being built.
     * @param string               $channel Log channel, e.g. {@see LogChannels::MEDIA}.
     * @param string               $message What degraded, and what happens instead.
     * @param \Throwable           $e       The swallowed failure.
     * @param array<string, mixed> $context Extra structured context.
     *
     * @return void
     */
    public static function warnUnlessAbsent(
        ContainerInterface $c,
        string $channel,
        string $message,
        \Throwable $e,
        array $context = []
    ): void {
        if ($e instanceof \Psr\Container\NotFoundExceptionInterface) {
            return;
        }

        self::warn($c, $channel, $message, $e, $context);
    }

    /**
     * The channel logger, preferring the container's wired instance.
     *
     * Falls back to {@see LoggerFactory::get()} because the whole point of the
     * call site is that the container is *already* misbehaving; a logger lookup
     * must not be the second thing that fails.
     *
     * @param ContainerInterface $c       Container being built.
     * @param string             $channel Channel name.
     *
     * @return StructuredLogger Resolved channel logger.
     */
    private static function logger(ContainerInterface $c, string $channel): StructuredLogger
    {
        try {
            $logger = $c->get('logger.' . $channel);
            if ($logger instanceof StructuredLogger) {
                return $logger;
            }
        } catch (\Throwable) {
            // Fall through to the factory-built channel logger.
        }

        return LoggerFactory::get($channel);
    }
}
