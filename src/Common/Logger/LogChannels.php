<?php

declare(strict_types=1);

namespace Phlex\Common\Logger;

/**
 * Log channel constants for consistent logger naming.
 *
 * Each public constant is a channel name handed to
 * {@see LoggerFactory::get()} so callers reference channels by symbol
 * rather than by string literal. New channels added here should also be
 * registered with the container in
 * {@see \Phlex\Common\Container\Providers\CoreServicesProvider::channels()}
 * so they are resolvable via `logger.<name>` aliases.
 *
 * @package Phlex\Common\Logger
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
     * dispatch activity (when `PHLEX_DEBUG_EVENTS` is enabled), listener
     * registration anomalies, and any in-process notices coming out of
     * `Phlex\Common\Events\*`. Introduced in step A.2.
     *
     * @since 0.10.0
     */
    public const EVENTS = 'events';

    /**
     * Channel used by the plugin subsystem (install / enable / disable /
     * uninstall, manifest validation, composer-runner shell-outs,
     * signature verification). Introduced in step A.4.
     *
     * @since 0.10.0
     */
    public const PLUGINS = 'plugins';

    /**
     * Prevent instantiation — this class is a static constant holder only.
     */
    private function __construct()
    {
    }
}
