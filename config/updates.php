<?php

/**
 * Core (server application) update-check settings — S74 / updates.md #48.
 *
 * The server periodically fetches this repository's root `VERSION` marker and
 * compares it against the compiled {@see \Phlix\Common\Version::STRING}
 * constant, so an operator learns a newer phlix-server exists without polling
 * GitHub by hand.
 *
 * Only `check_enabled` is admin-editable; it is exposed through
 * `PUT /api/v1/admin/updates/settings` and read as an EFFECTIVE value
 * (`server_settings` override → this default) on every poll by
 * {@see \Phlix\Server\Updates\CoreUpdateCheckService::isCheckEnabled()}. The
 * remaining keys are read as DEFAULTS only (config file, no DB round-trip) so
 * that resolving the service at route-bind time cannot issue a query.
 *
 * NOTHING here ever applies an update. The status endpoint surfaces
 * `update_command` as a copy-to-clipboard string the operator runs on the box
 * themselves — the server never shells out to git/composer/systemctl.
 *
 * @return array<string, mixed>
 *
 * @since S74 (core update check)
 */

declare(strict_types=1);

$envStr = static fn (string $k, string $d): string => ($v = getenv($k)) !== false && $v !== '' ? $v : $d;
$envInt = static fn (string $k, int $d): int => is_numeric($v = getenv($k)) ? (int) $v : $d;
$envBool = static function (string $k, bool $d): bool {
    $v = getenv($k);
    if ($v === false || $v === '') {
        return $d;
    }
    return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
};

return [
    // Master switch for the periodic check. Default TRUE (S74). Overridable
    // per-install via PHLIX_UPDATES_CHECK_ENABLED, and at runtime by an admin
    // through PUT /api/v1/admin/updates/settings (a server_settings override,
    // which wins over this default).
    'check_enabled' => $envBool('PHLIX_UPDATES_CHECK_ENABLED', true),

    // Remote version marker: this repository's root VERSION file, kept in
    // lockstep with Phlix\Common\Version::STRING (pinned by
    // tests/Unit/Server/Updates/VersionMarkerFileTest.php).
    'marker_url' => $envStr(
        'PHLIX_UPDATES_MARKER_URL',
        'https://raw.githubusercontent.com/detain/phlix-server/master/VERSION',
    ),

    // Copy-to-clipboard command surfaced by GET /api/v1/admin/updates/status
    // (the admin update banner). Canonical form = the LOCAL-CHECKOUT updater:
    // the installer already on disk in this very checkout (resolved from this
    // file's location, so it names the real install path, not a guessed
    // /var/www/phlix), run with `--update` (real flag, `scripts/install.sh`
    // `--update)` arm). It executes the installer shipped with the release the
    // box is already running, never a fresh `curl … master | sudo bash` pipe — that form runs
    // whatever `master` holds at fetch time, as root, with no pin, checksum or
    // review step (README "Updating an existing install" keeps it only as a
    // caveated alternative). `--update` then fetches `master` as the install
    // dir owner, exactly the ref the `marker_url` above tracks. Do not
    // paraphrase it: an operator pastes this into a root shell. A path outside
    // the shell-safe set is single-quoted. Override per install (e.g. Docker,
    // where updates are image pulls) with PHLIX_UPDATES_COMMAND.
    'update_command' => $envStr(
        'PHLIX_UPDATES_COMMAND',
        (static function (): string {
            $installer = dirname(__DIR__) . '/scripts/install.sh';
            $quoted = preg_match('#^[A-Za-z0-9_./-]+$#', $installer) === 1
                ? $installer
                : escapeshellarg($installer);

            return 'sudo bash ' . $quoted . ' --update -y';
        })(),
    ),

    // Steady-state poll interval, seconds (default: daily). The worker ALSO
    // arms a one-shot catch-up check shortly after boot — a bare
    // Timer::add(86400) never fires on a box that restarts more often than the
    // interval, which is a defect that has already shipped twice in this repo
    // (scheduled backups, plugin auto-update).
    'poll_seconds' => $envInt('PHLIX_UPDATES_POLL_SECONDS', 86400),

    // Socket timeout, seconds, for the marker fetch.
    'timeout_seconds' => $envInt('PHLIX_UPDATES_TIMEOUT_SECONDS', 10),
];
