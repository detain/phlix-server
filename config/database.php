<?php

declare(strict_types=1);

// Every connection parameter is overridable via env. Defaults match a
// stock single-host install (the install.sh-managed `phlix` MySQL user
// on localhost). CI test runs override these via phpunit.xml's <env>
// block (root / phlix_test against the GitHub Actions MySQL service).

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'host'      => getenv('DB_HOST')     ?: '127.0.0.1',
            'port'      => (int) (getenv('DB_PORT') ?: 3306),
            'database'  => getenv('DB_DATABASE') ?: (getenv('DB_NAME') ?: 'phlix'),
            'username'  => getenv('DB_USER')     ?: (getenv('DB_USERNAME') ?: 'phlix'),
            'password'  => getenv('DB_PASSWORD') ?: '',
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
            'pool_size' => (int) (getenv('DB_POOL_SIZE') ?: 8),
            'timeout'   => 5,
        ],
    ],
];
