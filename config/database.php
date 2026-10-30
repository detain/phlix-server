<?php

declare(strict_types=1);

// Every connection parameter is overridable via env. Defaults match a
// stock single-host install (the install.sh-managed `phlix` MySQL user
// on localhost). CI test runs override these via phpunit.xml's <env>
// block (root / phlix_test against the GitHub Actions MySQL service).

use Phlix\Common\Env\EnvConfig;

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
