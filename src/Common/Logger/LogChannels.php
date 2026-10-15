<?php

/**
 * Phlix media server component: Logger.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\Logger;

/**
 * Log channel constants for consistent logger naming.
 *
 * Each public constant is a channel name handed to
 * {@see LoggerFactory::get()} so callers reference channels by symbol
 * rather than by string literal. New channels added here should also be
 * registered with the container in
 * {@see \Phlix\Common\Container\Providers\CoreServicesProvider::channels()}
 * so they are resolvable via `logger.<name>` aliases.
 *
 * @package Phlix\Common\Logger
 * @since 0.10.0
 */
final class LogChannels
{
    public const APPLICATION = 'application';
    public const HTTP = 'http';
    public const WEBSOCKET = 'websocket';
    public const DATABASE = 'database';
    public const MEDIA = 'media';
    public const STREAMING = 'streaming';
    public const TRANSCODING = 'transcoding';
    public const AUTH = 'auth';
    public const SESSION = 'session';
    public const AUDIT = 'audit';
    public const DLNA = 'dlna';
    public const LIVETV = 'livetv';

    /**
     * Channel used by the PSR-14 event dispatcher subsystem to log
     * dispatch activity (when `PHLIX_DEBUG_EVENTS` is enabled), listener
     * registration anomalies, and any in-process notices coming out of
     * `Phlix\Common\Events\*`. Introduced in step A.2.
     *
     * @since 0.10.0
     */
    public const EVENTS = 'events';

    /**
     * Environment variable that gates the verbose events log (and the
     * PSR-14 debug-dispatch decorator). When truthy (1/true/yes/on) the
     * `events` log handler attaches to the {@see self::EVENTS} channel;
     * otherwise events.log stays empty. Kept here as the symbolic source
     * shared by `config/logger.php` and
     * {@see \Phlix\Common\Logger\StructuredLogger::setupHandlers()}.
     *
     * Mirrors the literal used by
     * {@see \Phlix\Common\Events\EventDispatcherFactory::DEBUG_ENV_VAR}.
     *
     * @since 0.10.0
     */
    public const DEBUG_EVENTS_ENV = 'PHLIX_DEBUG_EVENTS';

    /**
     * Channel used by the plugin subsystem (install / enable / disable /
     * uninstall, manifest validation, composer-runner shell-outs,
     * signature verification). Introduced in step A.4.
     *
     * @since 0.10.0
     */
    public const PLUGINS = 'plugins';

    /**
     * Environment variable that attaches the stdout log handler
     * (`config/logger.php` → `handlers.stdout`). When truthy
     * (`1`/`true`/`yes`/`on`, same semantics as
     * {@see self::DEBUG_EVENTS_ENV}) every logger channel additionally
     * writes its records to `php://stdout` — which `journald`
     * (`StandardOutput=journal`) and Docker log drivers capture — while the
     * `.logs/*.log` file handlers keep working unchanged. Set it for
     * containers and journal-only hosts where repo-relative `.logs/` is
     * invisible to the log pipeline (R14-09).
     *
     * @since 1.4.0
     */
    public const LOG_STDOUT_ENV = 'PHLIX_LOG_STDOUT';

    /**
     * Environment variable that OVERRIDES the minimum level of every
     * level-driven handler in `config/logger.php` (`file`, `error`,
     * `events`, `plugins`, `stdout`) at process start (R3-08 — the knob
     * existed in deploy docs long before anything read it). Accepted names
     * are the PSR-3 set (`debug`, `info`, `notice`, `warning`/`warn`,
     * `error`, `critical`, `alert`, `emergency`), case-insensitive; an
     * unknown or empty value is ignored fail-soft, leaving the shipped
     * per-handler levels in force. Unset ⇒ defaults unchanged.
     *
     * Read once per logger construction ({@see
     * \Phlix\Common\Logger\StructuredLogger::createHandler()}) — changing it
     * is a process-restart operation.
     *
     * @since 1.4.0
     */
    public const LOG_LEVEL_ENV = 'PHLIX_LOG_LEVEL';

    public const HUB = 'hub';

    /**
     * Prevent instantiation — this class is a static constant holder only.
     */
    private function __construct()
    {
    }
}
