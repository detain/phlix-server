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
            // Coroutine connection pool. OFF by default: the proven
            // single-connection mutex stays active. Set DB_POOL_ENABLED=1 (and
            // tune `pool_size`) to give each coroutine its own leased
            // connection for true intra-worker DB parallelism — validate on a
            // non-prod restart first (see PooledMySQLConnection). pool_size=1
            // is a safe, fully-serialised fallback.
            'pool_enabled' => filter_var(getenv('DB_POOL_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN),
            'pool_size' => 20,
            'timeout'   => 5,
        ],
    ],
];
