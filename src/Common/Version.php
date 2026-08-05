<?php

/**
 * Phlix media server component: Common.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
 * This constant is the AUTHORITATIVE version source for the whole
 * repository. `scripts/release.sh` reads it and propagates the bumped
 * value to the root `VERSION` marker and to
 * `k8s/helm/phlix/Chart.yaml` (`version` + `appVersion`) in one commit;
 * never edit those by hand. `composer.json` deliberately carries NO
 * `version` field — `composer validate --strict` (run by the
 * `composer-validate` CI job) fails when one is present.
 *
 * `tests/Unit/Server/Updates/VersionSourcesAgreeTest.php` turns any
 * drift between those sources into a red test.
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
    public const STRING = '1.2.3';

    /**
     * Prevent instantiation — this class is a static constant holder only.
     */
    private function __construct()
    {
    }
}
