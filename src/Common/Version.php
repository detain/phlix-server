<?php

declare(strict_types=1);

namespace Phlix\Common;

/**
 * Single source of truth for the running server's semantic version.
 *
 * Lives in `Phlix\Common` rather than `Phlix\Server` because plugin
 * loader code (`Phlix\Plugins\PluginLoader`) consults it without
 * pulling in the Workerman server bootstrap. Compare against
 * {@see Manifest::$phlixMinServerVersion} via `version_compare()` to
 * decide whether a plugin can be safely installed against the running
 * server.
 *
 * Update {@see self::STRING} in the same commit that bumps the
 * project version everywhere else — there is no central source apart
 * from this constant.
 *
 * @package Phlix\Common
 * @since 0.10.0
 */
final class Version
{
    /**
     * Semver string for the running server build. Used by the plugin
     * loader to enforce `phlix_min_server_version` and by the JSON
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
