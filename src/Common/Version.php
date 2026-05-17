<?php

declare(strict_types=1);

namespace Phlex\Common;

/**
 * Single source of truth for the running server's semantic version.
 *
 * Lives in `Phlex\Common` rather than `Phlex\Server` because plugin
 * loader code (`Phlex\Plugins\PluginLoader`) consults it without
 * pulling in the Workerman server bootstrap. Compare against
 * {@see Manifest::$phlexMinServerVersion} via `version_compare()` to
 * decide whether a plugin can be safely installed against the running
 * server.
 *
 * Update {@see self::STRING} in the same commit that bumps the
 * project version everywhere else — there is no central source apart
 * from this constant.
 *
 * @package Phlex\Common
 * @since 0.10.0
 */
final class Version
{
    /**
     * Semver string for the running server build. Used by the plugin
     * loader to enforce `phlex_min_server_version` and by the JSON
     * status endpoints that report `version` to clients.
     *
     * @since 0.10.0
     */
    public const STRING = '0.10.0';

    /**
     * Prevent instantiation — this class is a static constant holder only.
     */
    private function __construct()
    {
    }
}
