<?php

declare(strict_types=1);

// Every connection parameter is overridable via env. Defaults match a
// stock single-host install (the install.sh-managed `phlix` MySQL user
// on localhost). CI test runs override these via phpunit.xml's <env>
// block (root / phlix_test against the GitHub Actions MySQL service).

use Phlix\Common\Env\EnvConfig;

// Boot-time DB-credential law (minors M-SERVER-38, W-min107). The container
// path fail-closes on placeholder passwords in docker/docker-entrypoint.sh;
// systemd/bare-metal installs previously booted happily with NO DB_PASSWORD
// env at all against an empty-cred MySQL user. Now: in the production tier
// (PHLIX_ENV=production — the shipped unit's default), an UNSET DB_PASSWORD
// raises a loud one-time E_USER_WARNING, and an operator who wants a hard
// boot refusal sets PHLIX_REQUIRE_DB_CREDENTIALS=1 (mirroring
// PHLIX_MIGRATIONS_STRICT's opt-in strictness). Dev tiers and CI (phpunit.xml
// seeds DB_PASSWORD) are untouched — the silent empty default stays legal
// where it is a deliberate local choice, never a production surprise.
//
// The $GLOBALS latch makes both effects fire ONCE per process: config files
// are cached per worker by Phlix\Config\EffectiveConfig, but direct consumers
// (BackupManager, SsdpAdvertiser) `include` this file again at runtime, and
// the warning is a boot-time statement, not a per-operation one. The latch
// key is also the documented test seam (the guard-suite resets it).
if (!isset($GLOBALS['__phlix_db_credentials_guard'])) {
    $GLOBALS['__phlix_db_credentials_guard'] = true;
    if (getenv('PHLIX_ENV') === 'production') {
        $dbPasswordEnv = getenv('DB_PASSWORD');
        $requireCredentials = filter_var(
            getenv('PHLIX_REQUIRE_DB_CREDENTIALS') === false ? '0' : getenv('PHLIX_REQUIRE_DB_CREDENTIALS'),
            FILTER_VALIDATE_BOOLEAN
        );
        if ($dbPasswordEnv === false || $dbPasswordEnv === '') {
            if ($requireCredentials) {
                // Strict mode: missing OR explicitly-empty credentials refuse
                // the boot — the point of the knob is no silent empty-auth.
                throw new RuntimeException(
                    'DB_PASSWORD is unset or empty while PHLIX_REQUIRE_DB_CREDENTIALS is enabled;'
                        . ' provide the MySQL password via the environment (or stop requiring credentials)'
                );
            }
        }
        if ($dbPasswordEnv === false) {
            trigger_error(
                'config/database.php: DB_PASSWORD is unset while PHLIX_ENV=production — the server '
                    . 'connects with an EMPTY password; if MySQL accepts it, this install has no '
                    . 'database credential at all. Set DB_PASSWORD, or set '
                    . 'PHLIX_REQUIRE_DB_CREDENTIALS=1 to refuse such boots.',
                E_USER_WARNING
            );
        }
    }
}

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'host'      => EnvConfig::text('DB_HOST', '127.0.0.1'),
            'port'      => EnvConfig::integer('DB_PORT', 3306),
            'database'  => EnvConfig::text('DB_DATABASE', EnvConfig::text('DB_NAME', 'phlix')),
            'username'  => EnvConfig::text('DB_USER', EnvConfig::text('DB_USERNAME', 'phlix')),
            'password'  => EnvConfig::text('DB_PASSWORD', ''),
            'charset'   => 'utf8mb4',
            // Coroutine connection pool. ON by default (Track S / S9): each
            // coroutine leases its OWN connection from a bounded pool, so
            // independent queries within a worker run truly in parallel instead
            // of serialising on a single shared socket. Validated by this
            // session's concurrency audit (every in-worker cache whose coherence
            // once relied on the shared connection mutex was re-proven or fixed —
            // see TranscodeManager's epoch-stamped job-row cache) plus the S9
            // load test. The proven single-connection mutex path
            // (PhlixMySQLConnection) remains fully intact as an opt-out fallback:
            // set DB_POOL_ENABLED=0 to restore it. pool_size=1 is a safe,
            // fully-serialised middle ground. NB: `=== false` distinguishes an
            // UNSET env var (→ default '1' = on) from an explicit `DB_POOL_ENABLED=0`
            // opt-out; a plain `?: '1'` would treat the string "0" as empty and
            // silently re-enable the pool, defeating the fallback.
            'pool_enabled' => filter_var(
                getenv('DB_POOL_ENABLED') === false ? '1' : getenv('DB_POOL_ENABLED'),
                FILTER_VALIDATE_BOOLEAN
            ),
            // Per-worker pool ceiling. Each worker process keeps its OWN pool,
            // so the server-wide max is roughly (worker count × pool_size) — keep
            // it comfortably under MySQL `max_connections`. Tune via DB_POOL_SIZE.
            //
            // POOL MATH (R19-16), with the real worker counts so the arithmetic is
            // explicit rather than implied: start.php runs the HTTP fleet at
            // count = 14, and the WebSocket (:8097), hub-heartbeat, background-timer
            // and relay-tunnel workers at count = 1 each. At the shipped pool_size = 8
            // that is 14 × 8 = 112 (HTTP) plus ~4 (the count=1 workers, one pool
            // each) before scripts/migrations and any co-tenant on the same MySQL —
            // so `max_connections` must sit comfortably ABOVE ~120 just to leave the
            // default install headroom, and every +1 to DB_POOL_SIZE raises the floor
            // by another 18 connections. Do NOT size max_connections to the pool
            // alone; check `SHOW VARIABLES LIKE 'max_connections'` against the sum of
            // ALL clients of the server before lowering it.
            'pool_size' => EnvConfig::integer('DB_POOL_SIZE', 8),
            'timeout'   => 5,
        ],
    ],
];
